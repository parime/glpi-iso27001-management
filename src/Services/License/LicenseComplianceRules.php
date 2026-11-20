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

namespace GlpiPlugin\Grcmanager\Services\License;

/**
 * Issue #779 : conformité des licences logicielles (ISO 27001, mesure A.5.32). PHP pur, sans GLPI :
 * l'inventaire (LicenseInventory) fournit les installations, les licences et les entités.
 *
 * Trois constats, calculés à la demande, jamais enregistrés :
 * - logiciel commercial sans licence : logiciel installé d'un éditeur surveillé, sans aucune licence
 *   GLPI qui compte pour l'entité de l'installation ;
 * - surdéploiement : plus d'actifs équipés que de postes licenciés (licences non expirées). Une
 *   licence illimitée (nombre inférieur ou égal à 0) couvre tout ;
 * - licence expirée encore utilisée : des actifs lui sont encore affectés et ont le logiciel, ou le
 *   logiciel est encore installé dans son périmètre sans aucune licence valable ;
 * - lot 2 : licence utilisée qui arrive à échéance dans l'horizon choisi (60 jours par défaut), sans
 *   autre licence valable au-delà ; c'est une alerte d'anticipation, pas un écart. Un surdéploiement
 *   couvert par une licence qui autorise le dépassement (allow_overquota) reste signalé, avec cette
 *   précision. Taux de couverture des logiciels commerciaux surveillés (coverage()).
 *
 * Entités (#536) : une licence compte pour une entité si elle lui appartient, ou si elle est
 * récursive et appartient à une entité parente. Une licence récursive est partagée par les
 * sous-entités : son entité est aussi contrôlée avec ses sous-entités (portée « sous-entités »), sans
 * jamais signaler un surdéploiement qui n'existe pas (seules les licences qui peuvent couvrir le
 * périmètre sont comptées).
 *
 * @phpstan-type Software array{id: int, name: string, manufacturer: string}
 * @phpstan-type Install array{software_id: int, entity_id: int, itemtype: string, items_id: int,
 *     item_name: string}
 * @phpstan-type Licence array{id: int, name: string, software_id: int, entity_id: int,
 *     is_recursive: bool, number: int, expire: string, allow_overquota?: bool}
 * @phpstan-type Assignment array{licence_id: int, itemtype: string, items_id: int}
 * @phpstan-type Asset array{itemtype: string, items_id: int, item_name: string}
 * @phpstan-type Finding array{type: string, scope: string, entity_id: int, software_id: int,
 *     software_name: string, manufacturer: string, installs: int, seats: int, licence_id: int,
 *     licence_name: string, expire: string, assigned: int, assets: list<Asset>, overquota_allowed: bool}
 */
final class LicenseComplianceRules
{
    public const FINDING_UNLICENSED = 'unlicensed';
    public const FINDING_OVERDEPLOYED = 'overdeployed';
    public const FINDING_EXPIRED = 'expired';

    /** Lot 2 : licence utilisée qui arrive à échéance (alerte d'anticipation, pas un écart). */
    public const FINDING_EXPIRING = 'expiring';

    /** Du plus grave au moins grave (ordre d'affichage). */
    public const FINDINGS = [
        self::FINDING_UNLICENSED, self::FINDING_OVERDEPLOYED, self::FINDING_EXPIRED, self::FINDING_EXPIRING,
    ];

    /** Constats qui sont des écarts (contrôle de cohérence) ; l'échéance proche n'en est pas un. */
    public const GAP_FINDINGS = [self::FINDING_UNLICENSED, self::FINDING_OVERDEPLOYED, self::FINDING_EXPIRED];

    /** Horizon d'alerte avant l'échéance d'une licence, en jours (0 : pas d'alerte). */
    public const DEFAULT_EXPIRING_DAYS = 60;

    public const MAX_EXPIRING_DAYS = 365;

    /** L'entité seule. */
    public const SCOPE_ENTITY = 'entity';

    /** L'entité et ses sous-entités (licences récursives partagées). */
    public const SCOPE_SUBTREE = 'subtree';

    /** Éditeurs de logiciels commerciaux surveillés par défaut (liste modifiable). */
    public const DEFAULT_PUBLISHERS = [
        'Microsoft', 'Adobe', 'Oracle', 'Autodesk', 'VMware', 'Broadcom', 'SAP', 'IBM', 'Citrix', 'Corel',
        'JetBrains', 'TeamViewer', 'Parallels', 'Dassault Systemes', 'Bentley', 'SolarWinds',
    ];

    /** Logiciels gratuits de ces éditeurs, ignorés par défaut (partie du nom, liste modifiable). */
    public const DEFAULT_IGNORED = [
        'Redistributable', 'Runtime', 'Acrobat Reader', 'Edge', 'OneDrive', 'Teams', 'WebView', 'Update',
        'Language Pack', 'Visual Studio Code',
    ];

    public const MAX_LIST_ENTRIES = 200;

    public const MAX_ENTRY_LENGTH = 100;

    /** Actifs gardés au plus par constat (affichage). */
    public const MAX_ASSETS = 5;

    // ------------------------------------------------------------------ Listes.

    /**
     * Liste saisie (une entrée par ligne, ou séparée par des points-virgules) : entrées nettoyées,
     * sans doublon (casse ignorée), bornées en nombre et en longueur.
     *
     * @return list<string>
     */
    public static function parseList(string $text): array
    {
        $entries = [];
        foreach (preg_split('/[\r\n;]+/', $text) ?: [] as $raw) {
            $entry = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
            if ($entry === '' || mb_strlen($entry) > self::MAX_ENTRY_LENGTH) {
                continue;
            }
            $key = self::normalize($entry);
            if ($key === '' || isset($entries[$key])) {
                continue;
            }
            $entries[$key] = $entry;
            if (count($entries) >= self::MAX_LIST_ENTRIES) {
                break;
            }
        }

        return array_values($entries);
    }

    /**
     * Forme de comparaison : minuscules, accents retirés, ponctuation remplacée par des espaces.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e',
            'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ł' => 'l', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ń' => 'n',
            'ć' => 'c', 'ę' => 'e', 'ą' => 'a',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * L'éditeur est surveillé si l'un des noms de la liste y figure en mots entiers
     * (« Microsoft » reconnaît « Microsoft Corporation », pas « Microsoftware »).
     *
     * @param list<string> $publishers
     */
    public static function isWatchedPublisher(string $manufacturer, array $publishers): bool
    {
        $haystack = ' ' . self::normalize($manufacturer) . ' ';
        if (trim($haystack) === '') {
            return false;
        }
        foreach ($publishers as $publisher) {
            $needle = self::normalize($publisher);
            if ($needle !== '' && str_contains($haystack, ' ' . $needle . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Logiciel gratuit à ignorer : l'une des entrées figure dans son nom (casse et accents ignorés).
     *
     * @param list<string> $ignored
     */
    public static function isIgnoredSoftware(string $name, array $ignored): bool
    {
        $haystack = ' ' . self::normalize($name) . ' ';
        foreach ($ignored as $entry) {
            $needle = self::normalize($entry);
            if ($needle !== '' && str_contains($haystack, ' ' . $needle . ' ')) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ Licences.

    /**
     * Expirée : date d'expiration renseignée et passée (la licence vaut encore le jour même).
     */
    public static function isExpired(string $expire, string $today): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire) === 1 && $expire !== '0000-00-00' && $expire < $today;
    }

    public static function isUnlimited(int $number): bool
    {
        return $number <= 0;
    }

    /**
     * Horizon d'alerte saisi, borné entre 0 (pas d'alerte) et MAX_EXPIRING_DAYS.
     */
    public static function boundExpiringDays(int $days): int
    {
        return max(0, min(self::MAX_EXPIRING_DAYS, $days));
    }

    /**
     * Arrive à échéance : date renseignée, pas encore passée, au plus tard dans $days jours.
     */
    public static function isExpiring(string $expire, string $today, int $days): bool
    {
        if ($days <= 0 || !self::hasDate($expire) || self::isExpired($expire, $today)) {
            return false;
        }

        return $expire <= self::addDays($today, $days);
    }

    private static function hasDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date !== '0000-00-00';
    }

    private static function addDays(string $date, int $days): string
    {
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($base === false) {
            return $date;
        }

        return $base->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /**
     * La licence compte pour l'entité : elle lui appartient, ou elle est récursive et appartient à
     * une entité parente.
     *
     * @param Licence $licence
     * @param list<int> $ancestors Entités parentes de $entityId.
     */
    public static function countsFor(array $licence, int $entityId, array $ancestors): bool
    {
        return $licence['entity_id'] === $entityId
            || ($licence['is_recursive'] && in_array($licence['entity_id'], $ancestors, true));
    }

    /**
     * Postes licenciés par ces licences non expirées : null si l'une est illimitée.
     *
     * @param list<Licence> $licences
     */
    public static function seats(array $licences, string $today): ?int
    {
        $seats = 0;
        foreach ($licences as $licence) {
            if (self::isExpired($licence['expire'], $today)) {
                continue;
            }
            if (self::isUnlimited($licence['number'])) {
                return null;
            }
            $seats += $licence['number'];
        }

        return $seats;
    }

    /**
     * @param list<Licence> $licences
     * @return list<Licence>
     */
    private static function valid(array $licences, string $today): array
    {
        return array_values(array_filter(
            $licences,
            static fn (array $licence): bool => !self::isExpired($licence['expire'], $today)
        ));
    }

    // ------------------------------------------------------------------ Évaluation.

    /**
     * Constats, du plus grave au moins grave, puis par entité et par logiciel.
     *
     * @param array<int, Software> $software Logiciels par identifiant.
     * @param list<Install> $installs Installations visibles (un actif peut avoir plusieurs versions).
     * @param list<Licence> $licences Licences visibles (non supprimées, hors gabarits).
     * @param list<Assignment> $assignments Actifs affectés aux licences.
     * @param array<int, list<int>> $ancestors Entités parentes de chaque entité citée.
     * @param list<string> $publishers Éditeurs surveillés.
     * @param list<string> $ignored Logiciels ignorés (partie du nom).
     * @param int $expiringDays Horizon d'alerte avant l'échéance (0 : pas d'alerte).
     * @return list<Finding>
     */
    public static function evaluate(
        array $software,
        array $installs,
        array $licences,
        array $assignments,
        array $ancestors,
        array $publishers,
        array $ignored,
        string $today,
        int $expiringDays = 0
    ): array {
        $isUnder = static fn (int $entity, int $root): bool
            => $entity === $root || in_array($root, $ancestors[$entity] ?? [], true);

        // Actifs équipés, par logiciel puis par entité (un actif compte une fois par logiciel).
        $bySoftware = [];
        foreach ($installs as $install) {
            $key = $install['itemtype'] . ':' . $install['items_id'];
            $bySoftware[$install['software_id']][$install['entity_id']][$key] = [
                'itemtype'  => $install['itemtype'],
                'items_id'  => $install['items_id'],
                'item_name' => $install['item_name'],
            ];
        }
        $licencesBySoftware = [];
        foreach ($licences as $licence) {
            $licencesBySoftware[$licence['software_id']][] = $licence;
        }

        $findings = [];
        foreach ($bySoftware as $softwareId => $byEntity) {
            $info = $software[$softwareId] ?? null;
            if ($info === null) {
                continue;
            }
            $softwareLicences = $licencesBySoftware[$softwareId] ?? [];
            ksort($byEntity);
            // Entités déjà en surdéploiement à elles seules.
            $flagged = [];
            foreach ($byEntity as $entityId => $assets) {
                $applicable = array_values(array_filter(
                    $softwareLicences,
                    static fn (array $l): bool => self::countsFor($l, $entityId, $ancestors[$entityId] ?? [])
                ));
                if ($applicable === []) {
                    if (
                        self::isWatchedPublisher($info['manufacturer'], $publishers)
                        && !self::isIgnoredSoftware($info['name'], $ignored)
                    ) {
                        $findings[] = self::finding(
                            self::FINDING_UNLICENSED,
                            self::SCOPE_ENTITY,
                            $entityId,
                            $info,
                            $assets,
                            0
                        );
                    }
                    continue;
                }
                // Seules des licences expirées : le constat « licence expirée » le dit déjà.
                $valid = self::valid($applicable, $today);
                $seats = self::seats($valid, $today);
                if ($valid !== [] && $seats !== null && count($assets) > $seats) {
                    $flagged[$entityId] = true;
                    $finding = self::finding(
                        self::FINDING_OVERDEPLOYED,
                        self::SCOPE_ENTITY,
                        $entityId,
                        $info,
                        $assets,
                        $seats
                    );
                    $finding['overquota_allowed'] = self::allowsOverquota($valid);
                    $findings[] = $finding;
                }
            }

            // Licences récursives partagées : leur entité avec ses sous-entités.
            $owners = [];
            foreach ($softwareLicences as $licence) {
                if ($licence['is_recursive']) {
                    $owners[$licence['entity_id']] = true;
                }
            }
            ksort($owners);
            foreach (array_keys($owners) as $owner) {
                $demand = [];
                $alreadyFlagged = true;
                foreach ($byEntity as $entityId => $assets) {
                    if ($isUnder($entityId, $owner)) {
                        $demand += $assets;
                        $alreadyFlagged = $alreadyFlagged && isset($flagged[$entityId]);
                    }
                }
                // Chaque entité équipée est déjà signalée à elle seule (en particulier quand une seule
                // entité est équipée) : le constat du périmètre n'apprendrait rien de plus.
                if ($demand === [] || $alreadyFlagged) {
                    continue;
                }
                $supply = array_values(array_filter(
                    $softwareLicences,
                    static fn (array $l): bool => $isUnder($l['entity_id'], $owner)
                        || ($l['is_recursive'] && in_array($l['entity_id'], $ancestors[$owner] ?? [], true))
                ));
                $seats = self::seats($supply, $today);
                if (self::valid($supply, $today) !== [] && $seats !== null && count($demand) > $seats) {
                    $finding = self::finding(
                        self::FINDING_OVERDEPLOYED,
                        self::SCOPE_SUBTREE,
                        $owner,
                        $info,
                        $demand,
                        $seats
                    );
                    $finding['overquota_allowed'] = self::allowsOverquota(self::valid($supply, $today));
                    $findings[] = $finding;
                }
            }
        }

        // Licences expirées encore utilisées.
        $assignedByLicence = [];
        foreach ($assignments as $assignment) {
            $assetKey = $assignment['itemtype'] . ':' . $assignment['items_id'];
            $assignedByLicence[$assignment['licence_id']][$assetKey] = true;
        }
        foreach ($licences as $licence) {
            if (!self::isExpired($licence['expire'], $today)) {
                continue;
            }
            $info = $software[$licence['software_id']] ?? null;
            if ($info === null) {
                continue;
            }
            $inScope = [];
            $installed = [];
            foreach ($bySoftware[$licence['software_id']] ?? [] as $entityId => $assets) {
                $installed += $assets;
                if (
                    $entityId === $licence['entity_id']
                    || ($licence['is_recursive'] && $isUnder($entityId, $licence['entity_id']))
                ) {
                    $inScope += $assets;
                }
            }
            $stillAssigned = array_intersect_key($installed, $assignedByLicence[$licence['id']] ?? []);
            $otherValid = array_filter(
                $licencesBySoftware[$licence['software_id']] ?? [],
                static fn (array $l): bool => $l['id'] !== $licence['id']
                    && !self::isExpired($l['expire'], $today)
                    && self::countsFor($l, $licence['entity_id'], $ancestors[$licence['entity_id']] ?? [])
            );
            if ($stillAssigned === [] && ($inScope === [] || $otherValid !== [])) {
                continue;
            }
            $assets = $stillAssigned !== [] ? $stillAssigned + $inScope : $inScope;
            $finding = self::finding(
                self::FINDING_EXPIRED,
                $licence['is_recursive'] ? self::SCOPE_SUBTREE : self::SCOPE_ENTITY,
                $licence['entity_id'],
                $info,
                $inScope,
                max(0, $licence['number'])
            );
            $finding['licence_id'] = $licence['id'];
            $finding['licence_name'] = $licence['name'];
            $finding['expire'] = $licence['expire'];
            $finding['assigned'] = count($stillAssigned);
            $finding['assets'] = array_slice(array_values($assets), 0, self::MAX_ASSETS);
            $findings[] = $finding;
        }

        // Lot 2 : licences utilisées qui arrivent à échéance, sans licence valable au-delà.
        if ($expiringDays > 0) {
            $horizon = self::addDays($today, $expiringDays);
            foreach ($licences as $licence) {
                if (!self::isExpiring($licence['expire'], $today, $expiringDays)) {
                    continue;
                }
                $info = $software[$licence['software_id']] ?? null;
                if ($info === null) {
                    continue;
                }
                $inScope = [];
                $installed = [];
                foreach ($bySoftware[$licence['software_id']] ?? [] as $entityId => $assets) {
                    $installed += $assets;
                    if (
                        $entityId === $licence['entity_id']
                        || ($licence['is_recursive'] && $isUnder($entityId, $licence['entity_id']))
                    ) {
                        $inScope += $assets;
                    }
                }
                $stillAssigned = array_intersect_key($installed, $assignedByLicence[$licence['id']] ?? []);
                if ($stillAssigned === [] && $inScope === []) {
                    continue;
                }
                // Renouvellement déjà enregistré : une autre licence valable au-delà de l'horizon.
                $renewed = array_filter(
                    $licencesBySoftware[$licence['software_id']] ?? [],
                    static fn (array $l): bool => $l['id'] !== $licence['id']
                        && !self::isExpired($l['expire'], $today)
                        && (!self::hasDate($l['expire']) || $l['expire'] > $horizon)
                        && self::countsFor($l, $licence['entity_id'], $ancestors[$licence['entity_id']] ?? [])
                );
                if ($renewed !== []) {
                    continue;
                }
                $finding = self::finding(
                    self::FINDING_EXPIRING,
                    $licence['is_recursive'] ? self::SCOPE_SUBTREE : self::SCOPE_ENTITY,
                    $licence['entity_id'],
                    $info,
                    $inScope,
                    max(0, $licence['number'])
                );
                $finding['licence_id'] = $licence['id'];
                $finding['licence_name'] = $licence['name'];
                $finding['expire'] = $licence['expire'];
                $finding['assigned'] = count($stillAssigned);
                $finding['assets'] = array_slice(array_values($stillAssigned + $inScope), 0, self::MAX_ASSETS);
                $findings[] = $finding;
            }
        }

        $order = array_flip(self::FINDINGS);
        usort($findings, static fn (array $a, array $b): int => [
            $order[$a['type']], $a['entity_id'], mb_strtolower($a['software_name']), $a['licence_id'],
        ] <=> [
            $order[$b['type']], $b['entity_id'], mb_strtolower($b['software_name']), $b['licence_id'],
        ]);

        return $findings;
    }

    /**
     * Taux de couverture : couples (logiciel commercial surveillé, entité) installés, et ceux qui ont
     * au moins une licence valable qui compte pour l'entité.
     *
     * @param array<int, Software> $software
     * @param list<Install> $installs
     * @param list<Licence> $licences
     * @param array<int, list<int>> $ancestors
     * @param list<string> $publishers
     * @param list<string> $ignored
     * @return array{watched: int, licensed: int}
     */
    public static function coverage(
        array $software,
        array $installs,
        array $licences,
        array $ancestors,
        array $publishers,
        array $ignored,
        string $today
    ): array {
        $pairs = [];
        foreach ($installs as $install) {
            $info = $software[$install['software_id']] ?? null;
            if (
                $info === null || !self::isWatchedPublisher($info['manufacturer'], $publishers)
                || self::isIgnoredSoftware($info['name'], $ignored)
            ) {
                continue;
            }
            $pairs[$install['software_id'] . ':' . $install['entity_id']] = [
                $install['software_id'], $install['entity_id'],
            ];
        }
        $licensed = 0;
        foreach ($pairs as [$softwareId, $entityId]) {
            foreach ($licences as $licence) {
                if (
                    $licence['software_id'] === $softwareId
                    && !self::isExpired($licence['expire'], $today)
                    && self::countsFor($licence, $entityId, $ancestors[$entityId] ?? [])
                ) {
                    $licensed++;
                    break;
                }
            }
        }

        return ['watched' => count($pairs), 'licensed' => $licensed];
    }

    /**
     * Nombre de constats de chaque type.
     *
     * @param list<Finding> $findings
     * @return array<string, int>
     */
    public static function counts(array $findings): array
    {
        $counts = array_fill_keys(self::FINDINGS, 0);
        foreach ($findings as $finding) {
            $counts[$finding['type']]++;
        }

        return $counts;
    }

    /**
     * @param Software $info
     * @param array<string, Asset> $assets
     * @return Finding
     */
    private static function finding(
        string $type,
        string $scope,
        int $entityId,
        array $info,
        array $assets,
        int $seats
    ): array {
        uasort($assets, static fn (array $a, array $b): int => [$a['item_name'], $a['items_id']]
            <=> [$b['item_name'], $b['items_id']]);

        return [
            'type'          => $type,
            'scope'         => $scope,
            'entity_id'     => $entityId,
            'software_id'   => $info['id'],
            'software_name' => $info['name'],
            'manufacturer'  => $info['manufacturer'],
            'installs'      => count($assets),
            'seats'         => $seats,
            'licence_id'    => 0,
            'licence_name'  => '',
            'expire'        => '',
            'assigned'      => 0,
            'assets'        => array_slice(array_values($assets), 0, self::MAX_ASSETS),
            'overquota_allowed' => false,
        ];
    }

    /**
     * L'une de ces licences autorise le dépassement du nombre de postes (allow_overquota de GLPI).
     *
     * @param list<Licence> $licences
     */
    private static function allowsOverquota(array $licences): bool
    {
        foreach ($licences as $licence) {
            if ($licence['allow_overquota'] ?? false) {
                return true;
            }
        }

        return false;
    }
}
