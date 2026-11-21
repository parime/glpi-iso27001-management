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

namespace GlpiPlugin\Grcmanager\Services\Monitoring;

/**
 * Issue #836 : logique pure de la supervision lue dans Zabbix (A.8.16, A.8.6, A.5.30), sans base de
 * données : adresse de l'API, gravités, problèmes de saturation, durées et disponibilité mesurée,
 * rapprochement des hôtes avec les actifs GLPI, état d'un actif, comparaison au SLO et au RTO.
 *
 * Le plugin lit seulement : rien n'est jamais modifié dans Zabbix.
 */
final class ZabbixRules
{
    /** Gravités des déclencheurs Zabbix (0 à 5). */
    public const SEVERITY_NOT_CLASSIFIED = 0;
    public const SEVERITY_INFORMATION = 1;
    public const SEVERITY_WARNING = 2;
    public const SEVERITY_AVERAGE = 3;
    public const SEVERITY_HIGH = 4;
    public const SEVERITY_DISASTER = 5;

    /** Gravités lues : à partir d'« Avertissement » (l'information et le non classé sont du bruit). */
    public const READ_SEVERITIES = [2, 3, 4, 5];

    /** État de la supervision d'un actif. */
    public const STATUS_MONITORED = 'monitored';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_MISSING = 'missing';
    public const STATUSES = [self::STATUS_MISSING, self::STATUS_DISABLED, self::STATUS_UNAVAILABLE, self::STATUS_MONITORED];

    /** Actifs concernés par la supervision (mêmes choix que la couverture de la journalisation). */
    public const SCOPE_CRITICAL = 'critical';
    public const SCOPE_CRITICAL_SERVERS = 'critical_servers';
    public const SCOPE_ALL = 'all';
    public const SCOPES = [self::SCOPE_CRITICAL_SERVERS, self::SCOPE_CRITICAL, self::SCOPE_ALL];

    /** Ressources saturées (A.8.6). */
    public const CAPACITY_STORAGE = 'storage';
    public const CAPACITY_MEMORY = 'memory';
    public const CAPACITY_CPU = 'cpu';
    public const CAPACITIES = [self::CAPACITY_STORAGE, self::CAPACITY_MEMORY, self::CAPACITY_CPU];

    /** Disponibilité d'un service comparée à son objectif (SLO). */
    public const SLI_OK = 'ok';
    public const SLI_BELOW = 'below';
    public const SLI_UNKNOWN = 'unknown';

    public const DEFAULT_PERIOD_DAYS = 30;
    public const MAX_PERIOD_DAYS = 90;

    /** Disponibilité « interface disponible » de Zabbix (host.get, selectInterfaces). */
    private const INTERFACE_UNAVAILABLE = 2;

    /**
     * Adresse de l'API JSON-RPC à partir de ce que saisit l'administrateur : adresse de l'interface
     * (« https://zabbix.exemple.test/zabbix ») ou de l'API elle-même. Schéma https, ou http si les
     * adresses internes sont autorisées ; sans identifiants, requête ni fragment. null si refusée.
     */
    public static function normalizeApiUrl(string $url, bool $allowHttp = false): ?string
    {
        $parts = parse_url(trim($url));
        if (
            !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !($scheme === 'https' || ($scheme === 'http' && $allowHttp))) {
            return null;
        }
        if (preg_match('/^[a-z0-9.\-]+$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && preg_match('#^[A-Za-z0-9/_.\-~]*$#', $path) !== 1) {
            return null;
        }
        if (str_contains($path, '..')) {
            return null;
        }
        $path = rtrim($path, '/');
        $path = preg_replace('#/(api_jsonrpc\.php|index\.php|zabbix\.php)$#i', '', $path) ?? '';

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . $path . '/api_jsonrpc.php';
    }

    /**
     * Réglages relus d'une valeur stockée ou soumise : bornes et valeurs par défaut.
     *
     * @return array{scope: string, period_days: int}
     */
    public static function normalizeSettings(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $scope = (string) ($raw['scope'] ?? '');
        $period = $raw['period_days'] ?? null;

        return [
            'scope'       => in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_CRITICAL_SERVERS,
            'period_days' => is_numeric($period)
                ? max(1, min(self::MAX_PERIOD_DAYS, (int) $period))
                : self::DEFAULT_PERIOD_DAYS,
        ];
    }

    public static function normalizeSeverity(mixed $severity): int
    {
        $severity = is_numeric($severity) ? (int) $severity : 0;

        return max(self::SEVERITY_NOT_CLASSIFIED, min(self::SEVERITY_DISASTER, $severity));
    }

    /** Gravité haute ou désastre : à proposer comme incident (sur clic) et compter dans le KRI. */
    public static function isHigh(int $severity): bool
    {
        return $severity >= self::SEVERITY_HIGH;
    }

    /**
     * Gravité d'un incident de sécurité proposé depuis un problème.
     */
    public static function incidentSeverity(int $severity): string
    {
        return match (true) {
            $severity >= self::SEVERITY_DISASTER => 'critical',
            $severity >= self::SEVERITY_HIGH     => 'major',
            default                              => 'minor',
        };
    }

    /**
     * Ressource saturée par un problème : étiquette « component » des modèles officiels de Zabbix
     * (storage, memory, cpu), sinon mots du nom du déclencheur. null si ce n'est pas de la capacité.
     *
     * @param list<array{tag: string, value: string}> $tags
     */
    public static function capacityKind(string $name, array $tags = []): ?string
    {
        foreach ($tags as $tag) {
            if (mb_strtolower(trim($tag['tag'])) !== 'component') {
                continue;
            }
            $value = mb_strtolower(trim($tag['value']));
            if (in_array($value, ['storage', 'filesystem', 'disk'], true)) {
                return self::CAPACITY_STORAGE;
            }
            if (in_array($value, ['memory', 'swap'], true)) {
                return self::CAPACITY_MEMORY;
            }
            if (in_array($value, ['cpu', 'processor'], true)) {
                return self::CAPACITY_CPU;
            }
        }
        $name = mb_strtolower($name);
        if (preg_match('/disk space|free space|filesystem|file system|inode|disque|espace libre|storage/u', $name) === 1) {
            return self::CAPACITY_STORAGE;
        }
        if (preg_match('/memory|swap|m[ée]moire/u', $name) === 1) {
            return self::CAPACITY_MEMORY;
        }
        if (preg_match('/\bcpu\b|processor|processeur|load average|charge/u', $name) === 1) {
            return self::CAPACITY_CPU;
        }

        return null;
    }

    /**
     * Durée d'un problème en secondes : jusqu'au retour à la normale, ou jusqu'à maintenant s'il est
     * encore ouvert.
     */
    public static function duration(int $clock, ?int $recoveredAt, int $now): int
    {
        $end = $recoveredAt !== null && $recoveredAt > 0 ? $recoveredAt : $now;

        return max(0, $end - $clock);
    }

    /**
     * Disponibilité mesurée sur la période (%, deux décimales) : part du temps sans problème de
     * gravité haute. Les intervalles qui se chevauchent ne comptent qu'une fois ; seule la partie
     * dans la période compte. null si la période est vide.
     *
     * @param list<array{0: int, 1: int}> $intervals [début, fin] en secondes
     */
    public static function availability(array $intervals, int $from, int $to): ?float
    {
        if ($to <= $from) {
            return null;
        }
        $down = self::downtime($intervals, $from, $to);

        return round(100 * ($to - $from - $down) / ($to - $from), 2);
    }

    /**
     * Temps d'arrêt cumulé (secondes) dans [from, to], chevauchements fusionnés.
     *
     * @param list<array{0: int, 1: int}> $intervals
     */
    public static function downtime(array $intervals, int $from, int $to): int
    {
        $clipped = [];
        foreach ($intervals as [$start, $end]) {
            $start = max($from, $start);
            $end = min($to, $end);
            if ($end > $start) {
                $clipped[] = [$start, $end];
            }
        }
        usort($clipped, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $total = 0;
        $current = null;
        foreach ($clipped as $interval) {
            if ($current === null) {
                $current = $interval;
                continue;
            }
            if ($interval[0] <= $current[1]) {
                $current[1] = max($current[1], $interval[1]);
                continue;
            }
            $total += $current[1] - $current[0];
            $current = $interval;
        }
        if ($current !== null) {
            $total += $current[1] - $current[0];
        }

        return $total;
    }

    /**
     * Un arrêt plus long que le RTO du processus métier (heures ; 0 = non défini, jamais en écart).
     */
    public static function exceedsRto(int $longestSeconds, int $rtoHours): bool
    {
        return $rtoHours > 0 && $longestSeconds > $rtoHours * 3600;
    }

    public static function sliStatus(?float $sli, ?float $slo): string
    {
        if ($sli === null || $slo === null) {
            return self::SLI_UNKNOWN;
        }

        return $sli + 1e-9 < $slo ? self::SLI_BELOW : self::SLI_OK;
    }

    /**
     * Actif critique : classification C/I/D élevée sur au moins un axe, ou publié sur Internet
     * (même définition que les correctifs de sécurité et la journalisation).
     */
    public static function isCritical(
        string $confidentiality,
        string $integrity,
        string $availability,
        bool $exposed
    ): bool {
        return $exposed || in_array('high', [$confidentiality, $integrity, $availability], true);
    }

    public static function isConcerned(string $scope, bool $critical, bool $isServer, bool $isNetwork): bool
    {
        return match ($scope) {
            self::SCOPE_ALL      => true,
            self::SCOPE_CRITICAL => $critical,
            default              => $critical || $isServer || $isNetwork,
        };
    }

    /**
     * État d'un actif : aucun hôte Zabbix reconnu, hôte présent mais non supervisé (désactivé),
     * hôte supervisé dont une interface est injoignable, hôte supervisé.
     *
     * @param list<int> $interfaceStates disponibilité de chaque interface (0 inconnue, 1 oui, 2 non)
     */
    public static function status(bool $found, bool $monitored, array $interfaceStates = []): string
    {
        if (!$found) {
            return self::STATUS_MISSING;
        }
        if (!$monitored) {
            return self::STATUS_DISABLED;
        }

        return in_array(self::INTERFACE_UNAVAILABLE, $interfaceStates, true)
            ? self::STATUS_UNAVAILABLE
            : self::STATUS_MONITORED;
    }

    public static function isCovered(string $status): bool
    {
        return in_array($status, [self::STATUS_MONITORED, self::STATUS_UNAVAILABLE], true);
    }

    /**
     * Synthèse d'une liste d'actifs : nombre par état, taux de couverture (supervisés).
     *
     * @param list<array{status: string}> $rows
     * @return array{total: int, monitored: int, unavailable: int, disabled: int, missing: int, rate: ?int,
     *     uncovered: int}
     */
    public static function summary(array $rows): array
    {
        $summary = [
            'total' => count($rows), 'monitored' => 0, 'unavailable' => 0, 'disabled' => 0, 'missing' => 0,
            'rate' => null, 'uncovered' => 0,
        ];
        foreach ($rows as $row) {
            $status = in_array($row['status'], self::STATUSES, true) ? $row['status'] : self::STATUS_MISSING;
            $summary[$status]++;
        }
        $summary['uncovered'] = $summary['disabled'] + $summary['missing'];
        if ($summary['total'] > 0) {
            $summary['rate'] = (int) floor(($summary['monitored'] + $summary['unavailable']) * 100 / $summary['total']);
        }

        return $summary;
    }

    /**
     * Nom d'hôte comparable : minuscules, sans le domaine. Une adresse IP est gardée entière.
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
     * Rapproche un hôte Zabbix d'un actif : numéro de série (inventaire Zabbix), puis nom
     * technique et nom visible (complets puis courts), puis adresses IP et noms DNS des interfaces.
     * Insensible à la casse. Clé « type:id » de l'actif, ou null.
     *
     * @param list<string> $names   nom technique et nom visible de l'hôte
     * @param list<string> $ips     adresses IP des interfaces
     * @param list<string> $dns     noms DNS des interfaces
     * @param array<string, string> $bySerial numéro de série en minuscules => clé
     * @param array<string, string> $byName   nom en minuscules (complet et court) => clé
     * @param array<string, string> $byIp     adresse => clé
     */
    public static function match(
        string $serial,
        array $names,
        array $ips,
        array $dns,
        array $bySerial,
        array $byName,
        array $byIp
    ): ?string {
        $serial = mb_strtolower(trim($serial));
        if ($serial !== '' && isset($bySerial[$serial])) {
            return $bySerial[$serial];
        }
        $candidates = array_merge($names, $dns);
        foreach ($candidates as $name) {
            $full = mb_strtolower(trim($name));
            if ($full !== '' && isset($byName[$full])) {
                return $byName[$full];
            }
        }
        foreach ($candidates as $name) {
            $short = self::normalizeHost($name);
            if ($short !== '' && isset($byName[$short])) {
                return $byName[$short];
            }
        }
        foreach ($ips as $ip) {
            $ip = mb_strtolower(trim($ip));
            if ($ip !== '' && isset($byIp[$ip])) {
                return $byIp[$ip];
            }
        }

        return null;
    }
}
