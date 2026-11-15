<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Docker Inventory #10 : source de preuves facultative, le plugin Inventaire Docker
 * (parime/glpi-docker-inventory, clé « dockerinventory »). Règles pures ; la lecture passe par
 * l'API publique de ce plugin, dans DockerInventoryEvidenceProvider.
 *
 * Même principe que les ponts assetsign et Wazuh : sans le plugin (absent, désactivé, trop ancien
 * pour avoir l'API, ou droit de lecture des services manquant), rien n'est affiché et rien ne
 * casse. Lecture seule : aucun ticket, statut ni droit modifié.
 *
 * L'API filtre déjà sur les entités actives ; chaque ligne est revérifiée ici (entités actives et
 * leurs parentes, pour les objets récursifs) : une ligne d'une autre entité n'est jamais citée.
 */
final class DockerInventoryEvidence
{
    public const PLUGIN_KEY = 'dockerinventory';

    /** API de lecture du plugin (GlpiPlugin\Dockerinventory\Api\Evidence), et sa version minimale. */
    public const API_CLASS = 'GlpiPlugin\\Dockerinventory\\Api\\Evidence';
    public const MIN_API_VERSION = 1;

    public const SECTION_INVENTORY = 'inventory';
    public const SECTION_GAPS = 'gaps';
    public const SECTION_VULNERABILITIES = 'vulnerabilities';
    public const SECTION_CONFIGURATION = 'configuration';
    public const SECTION_CHANGES = 'changes';

    /** Mesures de l'Annexe A et preuves Docker de chacune. */
    public const CONTROLS = [
        'A.5.9'  => [self::SECTION_INVENTORY],
        'A.8.8'  => [self::SECTION_GAPS, self::SECTION_VULNERABILITIES],
        'A.8.9'  => [self::SECTION_CONFIGURATION],
        'A.8.32' => [self::SECTION_CHANGES],
    ];

    /** Période des changements de version (A.8.32), en jours. */
    public const CHANGE_DAYS = 90;

    /** Lignes détaillées au plus par preuve (les compteurs portent sur tout). */
    public const MAX_ROWS = 10;

    /** Au-delà, le dernier import réussi d'une connexion est ancien : l'inventaire n'est plus frais. */
    public const STALE_HOURS = 48;

    /** Écarts de version et gravités affichés en compteurs, du plus grave au moins grave. */
    public const GAPS = ['major', 'minor', 'patch'];
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'none', ''];

    public const PROVENANCE_MANAGED = 'managed';
    public const PROVENANCE_UNMANAGED = 'unmanaged';

    public const FRESHNESS_FRESH = 'fresh';
    public const FRESHNESS_STALE = 'stale';
    public const FRESHNESS_NEVER = 'never';

    public static function isRelevantControl(string $code): bool
    {
        return isset(self::CONTROLS[$code]);
    }

    /**
     * @return list<string>
     */
    public static function sectionsFor(string $code): array
    {
        return self::CONTROLS[$code] ?? [];
    }

    /**
     * L'API du plugin est-elle utilisable ? Version entière, au moins MIN_API_VERSION.
     */
    public static function isSupportedApiVersion(mixed $version): bool
    {
        return is_int($version) && $version >= self::MIN_API_VERSION;
    }

    /**
     * Début de la période des changements, pour une date de référence (« AAAA-MM-JJ HH:MM:SS »).
     */
    public static function changesSince(string $now): string
    {
        $time = strtotime($now);

        return date('Y-m-d H:i:s', ($time === false ? time() : $time) - self::CHANGE_DAYS * 86400);
    }

    /**
     * Fraîcheur du dernier import réussi d'une connexion.
     */
    public static function freshness(string $lastSuccess, string $now): string
    {
        $last = $lastSuccess === '' ? false : strtotime($lastSuccess);
        if ($last === false) {
            return self::FRESHNESS_NEVER;
        }
        $reference = strtotime($now);
        $reference = $reference === false ? time() : $reference;

        return $reference - $last > self::STALE_HOURS * 3600 ? self::FRESHNESS_STALE : self::FRESHNESS_FRESH;
    }

    /**
     * Part des images venant d'un registre maîtrisé, en %, parmi celles dont la provenance est
     * évaluée ; null si aucune ne l'est (aucun registre maîtrisé déclaré).
     *
     * @param array<string, int> $counts
     */
    public static function managedShare(array $counts): ?float
    {
        $managed = max(0, (int) ($counts[self::PROVENANCE_MANAGED] ?? 0));
        $unmanaged = max(0, (int) ($counts[self::PROVENANCE_UNMANAGED] ?? 0));
        $evaluated = $managed + $unmanaged;

        return $evaluated > 0 ? round(100 * $managed / $evaluated, 1) : null;
    }

    /**
     * Ne garde que les lignes des entités autorisées (`entities_id` entier parmi `$allowed`). Une
     * ligne sans entité lisible est écartée : on ne cite que ce qu'on sait visible.
     *
     * @param mixed $rows
     * @param array<int, true> $allowed
     * @return list<array<string, mixed>>
     */
    public static function visibleRows(mixed $rows, array $allowed): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $visible = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['entities_id']) || !is_numeric($row['entities_id'])) {
                continue;
            }
            if (isset($allowed[(int) $row['entities_id']])) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * Preuves d'une mesure à partir des réponses brutes de l'API (une par section, null si la
     * section n'a rien donné). Null si aucune section n'est exploitable : la ligne ne s'affiche pas.
     *
     * @param array<string, array<string, mixed>|null> $raw
     * @param array<int, true> $allowed Entités autorisées (actives et leurs parentes).
     * @return array<string, mixed>|null
     */
    public static function build(string $code, array $raw, array $allowed, string $now): ?array
    {
        $evidence = [];
        foreach (self::sectionsFor($code) as $section) {
            $data = $raw[$section] ?? null;
            if (!is_array($data)) {
                continue;
            }
            $built = match ($section) {
                self::SECTION_INVENTORY       => self::inventory($data, $allowed, $now),
                self::SECTION_GAPS            => self::gaps($data, $allowed),
                self::SECTION_VULNERABILITIES => self::vulnerabilities($data, $allowed),
                self::SECTION_CONFIGURATION   => self::configuration($data, $allowed),
                self::SECTION_CHANGES         => self::changes($data, $allowed),
                default                       => null,
            };
            if ($built !== null) {
                $evidence[$section] = $built;
            }
        }

        return $evidence === [] ? null : $evidence;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, true> $allowed
     * @return array<string, mixed>
     */
    private static function inventory(array $data, array $allowed, string $now): array
    {
        $connections = [];
        foreach (self::visibleRows($data['connections'] ?? [], $allowed) as $row) {
            $last = self::text($row['last_success_date'] ?? '');
            $connections[] = [
                'name'              => self::text($row['name'] ?? ''),
                'type_label'        => self::text($row['type_label'] ?? ''),
                'last_success_date' => $last,
                'freshness'         => self::freshness($last, $now),
                'url'               => self::url($row['url'] ?? ''),
            ];
        }

        return [
            'services'             => self::count($data['services'] ?? 0),
            'retired_services'     => self::count($data['retired_services'] ?? 0),
            'stacks'               => self::count($data['stacks'] ?? 0),
            'nodes'                => self::count($data['nodes'] ?? 0),
            'services_url'         => self::url($data['services_url'] ?? ''),
            'can_view_connections' => (bool) ($data['can_view_connections'] ?? false),
            'connections'          => $connections,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, true> $allowed
     * @return array<string, mixed>
     */
    private static function gaps(array $data, array $allowed): array
    {
        $infos = is_array($data['gaps'] ?? null) ? $data['gaps'] : [];
        $counts = [];
        foreach (self::GAPS as $gap) {
            $counts[] = self::countBadge($gap, $data['counts'][$gap] ?? 0, $infos[$gap] ?? null);
        }
        $rows = [];
        foreach (self::visibleRows($data['outdated'] ?? [], $allowed) as $row) {
            $gap = self::text($row['gap'] ?? '');
            $rows[] = [
                'service'      => self::text($row['service'] ?? ''),
                'url'          => self::url($row['url'] ?? ''),
                'stack'        => self::text($row['stack'] ?? ''),
                'image'        => self::text($row['image'] ?? ''),
                'deployed_tag' => self::text($row['deployed_tag'] ?? ''),
                'latest_tag'   => self::text($row['latest_tag'] ?? ''),
                'gap'          => self::info($gap, $infos[$gap] ?? null),
            ];
        }

        return [
            'counts'   => $counts,
            'rows'     => array_slice($rows, 0, self::MAX_ROWS),
            'total'    => self::count($data['outdated_total'] ?? count($rows)),
            'page_url' => self::url($data['page_url'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, true> $allowed
     * @return array<string, mixed>
     */
    private static function vulnerabilities(array $data, array $allowed): array
    {
        $infos = is_array($data['severities'] ?? null) ? $data['severities'] : [];
        $counts = [];
        foreach (self::SEVERITIES as $severity) {
            $counts[] = self::countBadge($severity, $data['counts'][$severity] ?? 0, $infos[$severity] ?? null);
        }
        $rows = self::vulnerableServices($data, $allowed);

        return [
            'has_harbor' => (bool) ($data['has_harbor'] ?? false),
            'counts'     => $counts,
            'rows'       => array_slice($rows, 0, self::MAX_ROWS),
            'total'      => count($rows),
            'page_url'   => self::url($data['page_url'] ?? ''),
        ];
    }

    /**
     * Services visibles dont l'image a des vulnérabilités critiques ou hautes, les critiques d'abord.
     *
     * @param array<string, mixed> $data Réponse de l'API (vulnerabilities).
     * @param array<int, true> $allowed
     * @return list<array{service: string, url: string, stack: string, image: string, tag: string,
     *     critical: int, high: int, fixable: int, severity: array{label: string, color: string,
     *     icon: string}}>
     */
    public static function vulnerableServices(array $data, array $allowed): array
    {
        $infos = is_array($data['severities'] ?? null) ? $data['severities'] : [];
        $rows = [];
        foreach (self::visibleRows($data['services'] ?? [], $allowed) as $row) {
            $severity = self::text($row['severity'] ?? '');
            $rows[] = [
                'service'  => self::text($row['service'] ?? ''),
                'url'      => self::url($row['url'] ?? ''),
                'stack'    => self::text($row['stack'] ?? ''),
                'image'    => self::text($row['image'] ?? ''),
                'tag'      => self::text($row['tag'] ?? ''),
                'critical' => self::count($row['critical'] ?? 0),
                'high'     => self::count($row['high'] ?? 0),
                'fixable'  => self::count($row['fixable'] ?? 0),
                'severity' => self::info($severity, $infos[$severity] ?? null),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['critical'], $b['high'], $a['service']]
            <=> [$a['critical'], $a['high'], $b['service']]);

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, true> $allowed
     * @return array<string, mixed>
     */
    private static function configuration(array $data, array $allowed): array
    {
        $infos = is_array($data['provenances'] ?? null) ? $data['provenances'] : [];
        $rows = [];
        foreach (self::visibleRows($data['services'] ?? [], $allowed) as $row) {
            $provenance = self::text($row['provenance'] ?? '');
            $rows[] = [
                'service'     => self::text($row['service'] ?? ''),
                'url'         => self::url($row['url'] ?? ''),
                'stack'       => self::text($row['stack'] ?? ''),
                'image'       => self::text($row['image'] ?? ''),
                'tag'         => self::text($row['tag'] ?? ''),
                'digest'      => self::text($row['digest'] ?? ''),
                'replicas'    => self::text($row['replicas'] ?? ''),
                'replicas_ok' => (bool) ($row['replicas_ok'] ?? false),
                'ports'       => array_values(array_map(
                    static fn (mixed $port): string => self::text($port),
                    array_filter(is_array($row['ports'] ?? null) ? $row['ports'] : [], 'is_scalar')
                )),
                'provenance'  => self::info($provenance, $infos[$provenance] ?? null),
            ];
        }
        $counts = is_array($data['provenance_counts'] ?? null) ? $data['provenance_counts'] : [];
        $managed = self::count($counts[self::PROVENANCE_MANAGED] ?? 0);
        $unmanaged = self::count($counts[self::PROVENANCE_UNMANAGED] ?? 0);

        return [
            'rows'          => array_slice($rows, 0, self::MAX_ROWS),
            'total'         => self::count($data['services_total'] ?? count($rows)),
            'managed'       => $managed,
            'evaluated'     => $managed + $unmanaged,
            'managed_share' => self::managedShare([
                self::PROVENANCE_MANAGED   => $managed,
                self::PROVENANCE_UNMANAGED => $unmanaged,
            ]),
            'services_url'  => self::url($data['services_url'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, true> $allowed
     * @return array<string, mixed>
     */
    private static function changes(array $data, array $allowed): array
    {
        $rows = [];
        foreach (self::visibleRows($data['changes'] ?? [], $allowed) as $row) {
            $rows[] = [
                'date'       => self::text($row['date'] ?? ''),
                'service'    => self::text($row['service'] ?? ''),
                'url'        => self::url($row['url'] ?? ''),
                'stack'      => self::text($row['stack'] ?? ''),
                'kind'       => self::text($row['kind'] ?? ''),
                'kind_label' => self::text($row['kind_label'] ?? ''),
                'old'        => self::version($row, 'old'),
                'new'        => self::version($row, 'new'),
            ];
        }

        return [
            'rows'  => array_slice($rows, 0, self::MAX_ROWS),
            'total' => self::count($data['total'] ?? count($rows)),
            'days'  => self::CHANGE_DAYS,
        ];
    }

    /**
     * Version lisible d'un côté d'un changement : « image:tag », suivie de l'empreinte courte quand
     * seul le contenu a changé.
     *
     * @param array<string, mixed> $row
     */
    private static function version(array $row, string $side): string
    {
        $image = self::text($row[$side . '_image'] ?? '');
        $tag = self::text($row[$side . '_tag'] ?? '');
        $digest = self::text($row[$side . '_digest'] ?? '');
        $text = $tag === '' ? $image : $image . ':' . $tag;
        if (($row['kind'] ?? '') === 'digest' && $digest !== '') {
            $text .= ' (' . $digest . ')';
        }

        return $text;
    }

    /**
     * @param mixed $info Libellé, couleur et icône fournis par le plugin.
     * @return array{code: string, label: string, color: string, icon: string, count: int}
     */
    private static function countBadge(string $code, mixed $count, mixed $info): array
    {
        return self::info($code, $info) + ['count' => self::count($count)];
    }

    /**
     * @param mixed $info
     * @return array{code: string, label: string, color: string, icon: string}
     */
    private static function info(string $code, mixed $info): array
    {
        $info = is_array($info) ? $info : [];
        $color = self::text($info['color'] ?? '');
        $icon = self::text($info['icon'] ?? '');

        return [
            'code'  => $code,
            'label' => self::text($info['label'] ?? $code),
            // Classes CSS : seulement des noms de couleur et d'icône Tabler bien formés.
            'color' => preg_match('/^[a-z]+$/', $color) === 1 ? $color : 'secondary',
            'icon'  => preg_match('/^ti ti-[a-z0-9-]+$/', $icon) === 1 ? $icon : 'ti ti-point',
        ];
    }

    /**
     * Lien vers une page du plugin : chemin relatif ou adresse http(s), sinon aucun lien.
     */
    private static function url(mixed $value): string
    {
        $url = self::text($value);

        return preg_match('#^(/|https?://)#i', $url) === 1 ? $url : '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
