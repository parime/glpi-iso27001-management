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

namespace GlpiPlugin\Grcmanager\Services\Logging;

use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;

/**
 * Issue #853 : logique pure de la couverture de la journalisation (A.8.15, A.8.16, A.8.17), sans
 * base de données : lecture d'un export CSV d'hôtes émetteurs, rapprochement avec les actifs,
 * état d'un actif (journaux reçus, plus rien depuis N jours, rien), actifs concernés, durée de
 * conservation comparée à la politique et aux obligations.
 *
 * Le plugin lit seulement : il ne lance rien sur les plateformes de journaux et ne modifie rien.
 */
final class LogCoverageRules
{
    /** Plateformes reconnues. */
    public const PLATFORMS = ['wazuh', 'graylog', 'elastic', 'splunk', 'syslog', 'other'];

    /** État d'un actif. */
    public const STATUS_OK = 'ok';
    public const STATUS_STALE = 'stale';
    public const STATUS_SILENT = 'silent';
    public const STATUSES = [self::STATUS_SILENT, self::STATUS_STALE, self::STATUS_OK];

    /** Actifs concernés par la journalisation. */
    public const SCOPE_CRITICAL = 'critical';
    public const SCOPE_CRITICAL_SERVERS = 'critical_servers';
    public const SCOPE_ALL = 'all';
    public const SCOPES = [self::SCOPE_CRITICAL_SERVERS, self::SCOPE_CRITICAL, self::SCOPE_ALL];

    /** État de la conservation d'une plateforme. */
    public const RETENTION_OK = 'ok';
    public const RETENTION_SHORT = 'short';
    public const RETENTION_UNDECLARED = 'undeclared';

    /** Réglages par défaut. */
    public const DEFAULT_POLICY_DAYS = 180;
    public const DEFAULT_STALE_DAYS = 7;
    public const MAX_DAYS = 3650;
    public const MAX_STALE_DAYS = 365;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Colonnes reconnues => en-têtes usuels normalisés (minuscules, sans séparateurs) : Splunk
     * (« | metadata type=hosts » : host, lastTime, recentTime), Graylog (source), Elastic et
     * OpenSearch (host.name, agent.name, max de @timestamp), Wazuh (name, ip, lastKeepAlive).
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'host'       => [
            'host', 'hostname', 'hostnamekeyword', 'source', 'sourcehost', 'agent', 'agentname',
            'name', 'computer', 'computername', 'device', 'devicename', 'hote', 'nom', 'machine', 'asset',
        ],
        'ip'         => [
            'ip', 'ipaddress', 'hostip', 'agentip', 'sourceip', 'srcip', 'address', 'adresse', 'adresseip',
        ],
        'last_event' => [
            'lastevent', 'lasttime', 'recenttime', 'lastseen', 'lastmessage', 'lastreceived', 'lastkeepalive',
            'maxtimestamp', 'latest', 'latesttime', 'timestamp', 'lastlog', 'dernierevenement', 'derniereactivite',
            'date', 'time',
        ],
    ];

    public static function normalizePlatform(mixed $platform): string
    {
        $platform = is_string($platform) ? mb_strtolower(trim($platform)) : '';

        return in_array($platform, self::PLATFORMS, true) ? $platform : 'other';
    }

    /**
     * Réglages relus d'une valeur stockée ou soumise : bornes et valeurs par défaut.
     *
     * @return array{scope: string, policy_days: int, stale_days: int}
     */
    public static function normalizeSettings(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $scope = (string) ($raw['scope'] ?? '');
        $policy = $raw['policy_days'] ?? null;
        $stale = $raw['stale_days'] ?? null;

        return [
            'scope'       => in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_CRITICAL_SERVERS,
            'policy_days' => is_numeric($policy)
                ? max(1, min(self::MAX_DAYS, (int) $policy))
                : self::DEFAULT_POLICY_DAYS,
            'stale_days'  => is_numeric($stale)
                ? max(1, min(self::MAX_STALE_DAYS, (int) $stale))
                : self::DEFAULT_STALE_DAYS,
        ];
    }

    /**
     * Nom d'hôte comparable : minuscules, sans le domaine (« srv-web01.exemple.test » donne
     * « srv-web01 »). Une adresse IP est gardée entière.
     */
    public static function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        $short = strstr($host, '.', true);

        return $short !== false && $short !== '' ? $short : $host;
    }

    /**
     * Actif critique : classification C/I/D élevée sur au moins un axe, ou publié sur Internet
     * (même définition que les correctifs de sécurité, issue #854).
     */
    public static function isCritical(
        string $confidentiality,
        string $integrity,
        string $availability,
        bool $exposed
    ): bool {
        return $exposed || in_array('high', [$confidentiality, $integrity, $availability], true);
    }

    /**
     * Actif concerné par la journalisation selon le réglage.
     */
    public static function isConcerned(string $scope, bool $critical, bool $isServer, bool $isNetwork): bool
    {
        return match ($scope) {
            self::SCOPE_ALL      => true,
            self::SCOPE_CRITICAL => $critical,
            default              => $critical || $isServer || $isNetwork,
        };
    }

    /**
     * État d'un actif : aucun hôte émetteur reconnu (rien), dernier événement plus ancien que le
     * seuil (plus rien depuis N jours), sinon journaux reçus. Un hôte connu sans date est compté
     * comme émetteur (la plateforme le connaît, sans donner la date).
     */
    public static function status(bool $found, ?string $lastEvent, string $now, int $staleDays): string
    {
        if (!$found) {
            return self::STATUS_SILENT;
        }
        $age = self::ageDays($lastEvent, $now);

        return $age !== null && $age > $staleDays ? self::STATUS_STALE : self::STATUS_OK;
    }

    /**
     * Jours entiers écoulés depuis une date, null si la date est absente ou illisible.
     */
    public static function ageDays(?string $date, string $now): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $from = strtotime($date);
        $to = strtotime($now);
        if ($from === false || $to === false) {
            return null;
        }

        return max(0, intdiv($to - $from, 86400));
    }

    /**
     * Conservation exigée : la plus longue entre la politique et l'obligation.
     */
    public static function requiredDays(int $policyDays, int $obligationDays): int
    {
        return max($policyDays, $obligationDays);
    }

    public static function retentionStatus(int $declaredDays, int $requiredDays): string
    {
        if ($declaredDays <= 0) {
            return self::RETENTION_UNDECLARED;
        }

        return $declaredDays < $requiredDays ? self::RETENTION_SHORT : self::RETENTION_OK;
    }

    /**
     * Synthèse d'une liste d'actifs : nombre par état et taux de couverture (journaux reçus).
     *
     * @param list<array{status: string}> $rows
     * @return array{total: int, ok: int, stale: int, silent: int, rate: ?int, uncovered: int}
     */
    public static function summary(array $rows): array
    {
        $summary = ['total' => count($rows), 'ok' => 0, 'stale' => 0, 'silent' => 0, 'rate' => null, 'uncovered' => 0];
        foreach ($rows as $row) {
            $status = in_array($row['status'], self::STATUSES, true) ? $row['status'] : self::STATUS_SILENT;
            $summary[$status]++;
        }
        $summary['uncovered'] = $summary['stale'] + $summary['silent'];
        if ($summary['total'] > 0) {
            $summary['rate'] = (int) floor($summary['ok'] * 100 / $summary['total']);
        }

        return $summary;
    }

    /**
     * Lit un export CSV (en-tête obligatoire, séparateur et encodage détectés) en hôtes émetteurs.
     * Plusieurs lignes du même hôte sont fusionnées en gardant le dernier événement.
     *
     * @return array{rows: list<array{host: string, ip: string, last_event: ?string}>, errors: list<string>,
     *     truncated: bool}
     */
    public static function parseCsv(string $raw, string $timezone = 'UTC'): array
    {
        $parsed = EdrCsvImport::parse($raw);
        $mapping = [];
        foreach (self::FIELDS as $field => $aliases) {
            foreach ($aliases as $alias) {
                foreach ($parsed['headers'] as $index => $header) {
                    if (!in_array($index, $mapping, true) && EdrCsvImport::normalizeHeader($header) === $alias) {
                        $mapping[$field] = $index;
                        break 2;
                    }
                }
            }
        }
        if (!isset($mapping['host']) && !isset($mapping['ip'])) {
            return ['rows' => [], 'errors' => ['missing_host'], 'truncated' => false];
        }
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $hosts = [];
        foreach ($parsed['rows'] as $row) {
            $host = mb_substr($get($row, 'host'), 0, 255);
            $ip = mb_substr($get($row, 'ip'), 0, 64);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $ip = '';
            }
            if ($host === '' && $ip === '') {
                continue;
            }
            $date = $get($row, 'last_event');
            $last = $date !== '' ? EdrCsvImport::parseDate($date, $timezone) : null;
            $key = mb_strtolower($host) . '|' . $ip;
            if (isset($hosts[$key])) {
                $hosts[$key]['last_event'] = self::latest($hosts[$key]['last_event'], $last);
                continue;
            }
            $hosts[$key] = ['host' => $host, 'ip' => $ip, 'last_event' => $last];
        }

        return ['rows' => array_values($hosts), 'errors' => [], 'truncated' => $parsed['truncated']];
    }

    public static function latest(?string $a, ?string $b): ?string
    {
        if ($a === null || $a === '') {
            return $b;
        }
        if ($b === null || $b === '') {
            return $a;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    /**
     * Rapproche un hôte émetteur d'un actif : nom exact, puis nom court (sans domaine), puis
     * adresse IP. Insensible à la casse. Clé « type:id » de l'actif, ou null.
     *
     * @param array<string, string> $byName nom en minuscules (complet et court) => clé
     * @param array<string, string> $byIp   adresse => clé
     */
    public static function match(string $host, string $ip, array $byName, array $byIp): ?string
    {
        $full = mb_strtolower(trim($host));
        if ($full !== '' && isset($byName[$full])) {
            return $byName[$full];
        }
        $short = self::normalizeHost($host);
        if ($short !== '' && isset($byName[$short])) {
            return $byName[$short];
        }
        foreach ([trim($ip), $full] as $address) {
            if ($address !== '' && isset($byIp[$address])) {
                return $byIp[$address];
            }
        }

        return null;
    }
}
