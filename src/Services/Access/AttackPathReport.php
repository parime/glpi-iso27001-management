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

namespace GlpiPlugin\Grcmanager\Services\Access;

/**
 * Issue #835, lot 3 : lecture d'un export JSON de chemins d'attaque de l'Active Directory.
 *
 * Formats reconnus :
 * - BloodHound CE : graphe exporté depuis l'explorateur (« nodes » indexés par identifiant ou en
 *   liste, « edges » avec source et target), éventuellement sous une clé « data » (réponse de l'API) ;
 * - Adalanche : graphe au format Cytoscape (« elements » en liste de { group, data } ou en
 *   { nodes, edges }, chaque élément portant ses champs sous « data »).
 *
 * Lecture sûre : taille limitée (MAX_BYTES), profondeur du JSON bornée (MAX_DEPTH), nombre de nœuds et
 * de liens limité (au-delà, le graphe est lu en partie et le résultat le signale), noms nettoyés des
 * balises et des caractères de contrôle. Seuls le nom et le type des comptes exposés sont gardés :
 * aucune autre propriété (SID, attributs, mots de passe éventuels) n'est lue.
 *
 * Calcul : les cibles sont les groupes Domain Admins (SID terminé par -512 ou nom du groupe, aussi en
 * français) et, pour Adalanche, les nœuds marqués comme cible de la requête. Un parcours en largeur sur
 * les liens inversés donne, pour chaque compte (utilisateur ou ordinateur), le nombre de sauts du plus
 * court chemin vers une cible. Un compte déjà de niveau zéro (isTierZero) n'est pas compté.
 */
final class AttackPathReport
{
    public const TOOL_BLOODHOUND = 'bloodhound';

    public const TOOL_ADALANCHE = 'adalanche';

    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_DEPTH = 64;

    public const MAX_NODES = 20000;

    public const MAX_EDGES = 100000;

    /** Comptes les plus exposés gardés par import. */
    public const MAX_ACCOUNTS = 20;

    public const ERROR_EMPTY = 'empty';

    public const ERROR_TOO_LARGE = 'too_large';

    public const ERROR_INVALID = 'invalid';

    public const ERROR_NOT_GRAPH = 'not_graph';

    public const ERROR_NO_TARGET = 'no_target';

    private const ADMIN_GROUP_NAMES = ['DOMAIN ADMINS', 'ADMINS DU DOMAINE', 'ADMINISTRATEURS DU DOMAINE'];

    /**
     * @return array{error: string, tool: string, domain: string, nodes: int, edges: int, targets: int,
     *     paths: int, users: int, computers: int, shortest: ?int,
     *     accounts: list<array{name: string, kind: string, hops: int, routes: int}>, truncated: bool}
     */
    public static function emptyResult(): array
    {
        return [
            'error' => '', 'tool' => '', 'domain' => '', 'nodes' => 0, 'edges' => 0, 'targets' => 0,
            'paths' => 0, 'users' => 0, 'computers' => 0, 'shortest' => null, 'accounts' => [],
            'truncated' => false,
        ];
    }

    /**
     * Le contenu ressemble-t-il à un graphe de chemins d'attaque (sans le lire en entier) ?
     */
    public static function looksLikeGraph(string $content): bool
    {
        $head = substr($content, 0, 4096);

        return str_contains($head, '"elements"')
            || (str_contains($content, '"nodes"') && str_contains($content, '"edges"'));
    }

    /**
     * @return array{error: string, tool: string, domain: string, nodes: int, edges: int, targets: int,
     *     paths: int, users: int, computers: int, shortest: ?int,
     *     accounts: list<array{name: string, kind: string, hops: int, routes: int}>, truncated: bool}
     */
    public static function parse(string $content): array
    {
        $result = self::emptyResult();
        if (strlen($content) > self::MAX_BYTES) {
            return ['error' => self::ERROR_TOO_LARGE] + $result;
        }
        $text = AdAuditReports::toUtf8($content);
        if ($text === null) {
            return ['error' => self::ERROR_INVALID] + $result;
        }
        if (trim($text) === '') {
            return ['error' => self::ERROR_EMPTY] + $result;
        }
        try {
            $data = json_decode($text, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return ['error' => self::ERROR_INVALID] + $result;
        }
        if (!is_array($data)) {
            return ['error' => self::ERROR_NOT_GRAPH] + $result;
        }
        if (isset($data['data']) && is_array($data['data']) && !isset($data['nodes']) && !isset($data['elements'])) {
            $data = $data['data'];
        }

        $graph = self::graph($data);
        if ($graph === null) {
            return ['error' => self::ERROR_NOT_GRAPH] + $result;
        }
        [$tool, $nodes, $edges, $truncated] = $graph;

        $targets = [];
        foreach ($nodes as $id => $node) {
            if ($node['target']) {
                $targets[] = $id;
            }
        }
        if ($targets === []) {
            return ['error' => self::ERROR_NO_TARGET, 'tool' => $tool] + $result;
        }

        // Liens inversés : de chaque nœud vers ceux qui l'atteignent.
        $reverse = [];
        foreach ($edges as [$source, $target]) {
            $reverse[$target][] = $source;
        }
        $distance = array_fill_keys($targets, 0);
        $queue = $targets;
        for ($i = 0; $i < count($queue); $i++) {
            $current = $queue[$i];
            foreach ($reverse[$current] ?? [] as $previous) {
                if (!isset($distance[$previous])) {
                    $distance[$previous] = $distance[$current] + 1;
                    $queue[] = $previous;
                }
            }
        }
        // Routes : liens d'un nœud vers un nœud qui mène à une cible (plusieurs chemins possibles).
        $routes = [];
        foreach ($edges as [$source, $target]) {
            if (isset($distance[$target]) && $source !== $target) {
                $routes[$source] = ($routes[$source] ?? 0) + 1;
            }
        }

        $accounts = [];
        foreach ($distance as $id => $hops) {
            $node = $nodes[$id];
            if ($hops === 0 || $node['tier_zero'] || !in_array($node['kind'], ['user', 'computer'], true)) {
                continue;
            }
            $result[$node['kind'] === 'user' ? 'users' : 'computers']++;
            $accounts[] = [
                'name' => $node['name'], 'kind' => $node['kind'], 'hops' => $hops, 'routes' => $routes[$id] ?? 0,
            ];
        }
        usort($accounts, static fn (array $a, array $b): int => [$a['hops'], -$a['routes'], $a['name']]
            <=> [$b['hops'], -$b['routes'], $b['name']]);

        $domain = '';
        foreach ($targets as $id) {
            $domain = $domain !== '' ? $domain : $nodes[$id]['domain'];
        }
        foreach ($nodes as $node) {
            $domain = $domain !== '' ? $domain : $node['domain'];
        }

        return [
            'error'     => '',
            'tool'      => $tool,
            'domain'    => $domain,
            'nodes'     => count($nodes),
            'edges'     => count($edges),
            'targets'   => count($targets),
            'paths'     => $result['users'] + $result['computers'],
            'users'     => $result['users'],
            'computers' => $result['computers'],
            'shortest'  => $accounts === [] ? null : $accounts[0]['hops'],
            'accounts'  => array_slice($accounts, 0, self::MAX_ACCOUNTS),
            'truncated' => $truncated,
        ];
    }

    /**
     * Nœuds et liens du graphe, ou null si ce n'est pas un graphe reconnu.
     *
     * @param array<mixed> $data
     * @return array{0: string, 1: array<string, array{name: string, kind: string, target: bool,
     *     tier_zero: bool, domain: string}>, 2: list<array{0: string, 1: string}>, 3: bool}|null
     */
    private static function graph(array $data): ?array
    {
        $tool = self::TOOL_BLOODHOUND;
        $rawNodes = $data['nodes'] ?? null;
        $rawEdges = $data['edges'] ?? null;
        if (isset($data['elements']) && is_array($data['elements'])) {
            $tool = self::TOOL_ADALANCHE;
            $elements = $data['elements'];
            if (array_is_list($elements)) {
                $rawNodes = [];
                $rawEdges = [];
                foreach ($elements as $element) {
                    if (is_array($element) && ($element['group'] ?? '') === 'edges') {
                        $rawEdges[] = $element;
                    } elseif (is_array($element)) {
                        $rawNodes[] = $element;
                    }
                }
            } else {
                $rawNodes = $elements['nodes'] ?? null;
                $rawEdges = $elements['edges'] ?? null;
            }
        }
        if (!is_array($rawNodes) || !is_array($rawEdges) || $rawNodes === []) {
            return null;
        }
        $first = reset($rawNodes);
        if (is_array($first) && isset($first['data']) && is_array($first['data'])) {
            $tool = self::TOOL_ADALANCHE;
        }

        $truncated = false;
        $nodes = [];
        foreach ($rawNodes as $key => $raw) {
            if (count($nodes) >= self::MAX_NODES) {
                $truncated = true;
                break;
            }
            if (!is_array($raw)) {
                continue;
            }
            $fields = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
            // Identifiant du nœud, sinon sa clé (BloodHound CE indexe les nœuds par identifiant).
            $id = self::scalar($fields['id'] ?? $key);
            if ($id === '') {
                continue;
            }
            $nodes[$id] = self::node($fields);
        }

        $edges = [];
        foreach ($rawEdges as $raw) {
            if (count($edges) >= self::MAX_EDGES) {
                $truncated = true;
                break;
            }
            if (!is_array($raw)) {
                continue;
            }
            $fields = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
            $source = self::scalar($fields['source'] ?? '');
            $target = self::scalar($fields['target'] ?? '');
            if ($source !== '' && $target !== '' && isset($nodes[$source], $nodes[$target])) {
                $edges[] = [$source, $target];
            }
        }

        return $nodes === [] ? null : [$tool, $nodes, $edges, $truncated];
    }

    /**
     * @param array<mixed> $fields
     * @return array{name: string, kind: string, target: bool, tier_zero: bool, domain: string}
     */
    private static function node(array $fields): array
    {
        $properties = isset($fields['properties']) && is_array($fields['properties']) ? $fields['properties'] : [];
        $name = self::clean(self::scalar(
            $fields['label'] ?? $properties['name'] ?? $fields['name'] ?? $fields['samAccountName'] ?? ''
        ));
        $kinds = [];
        foreach (['kind', 'type', '_type', 'objectClass'] as $key) {
            $value = $fields[$key] ?? null;
            foreach (is_array($value) ? $value : [$value] as $kind) {
                if (is_string($kind)) {
                    $kinds[] = strtolower($kind);
                }
            }
        }
        foreach ($fields['kinds'] ?? [] as $kind) {
            if (is_string($kind)) {
                $kinds[] = strtolower($kind);
            }
        }
        $kind = match (true) {
            array_intersect($kinds, ['user', 'person']) !== [] => 'user',
            in_array('computer', $kinds, true)                 => 'computer',
            in_array('group', $kinds, true)                    => 'group',
            default                                            => 'other',
        };
        $sid = strtoupper(self::scalar(
            $fields['objectId'] ?? $fields['objectid'] ?? $properties['objectid'] ?? $fields['objectSid'] ?? ''
        ));
        $upper = mb_strtoupper($name);
        $isAdminGroup = str_ends_with($sid, '-512');
        foreach (self::ADMIN_GROUP_NAMES as $group) {
            $isAdminGroup = $isAdminGroup || $upper === $group || str_starts_with($upper, $group . '@');
        }

        $domain = '';
        $at = strrpos($name, '@');
        if ($at !== false) {
            $domain = substr($name, $at + 1);
        } elseif (is_string($properties['domain'] ?? null)) {
            $domain = $properties['domain'];
        } elseif (preg_match_all('/DC=([^,]+)/i', self::scalar($fields['distinguishedName'] ?? ''), $dc) > 0) {
            $domain = implode('.', $dc[1]);
        }

        return [
            'name'      => $name !== '' ? $name : '?',
            'kind'      => $kind,
            'target'    => $isAdminGroup || ($fields['target'] ?? false) === true,
            'tier_zero' => ($fields['isTierZero'] ?? false) === true || in_array('tag_tier_zero', $kinds, true),
            'domain'    => AdAuditRules::normalizeDomain(self::clean($domain)),
        ];
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) ? mb_substr((string) $value, 0, 512) : '';
    }

    /**
     * Texte sans balise ni caractère de contrôle, 255 caractères au plus.
     */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($text));

        return mb_substr(trim($text), 0, 255);
    }
}
