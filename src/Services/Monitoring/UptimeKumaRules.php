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

use DateTimeImmutable;
use DateTimeZone;

/**
 * Issue #842 : logique pure de la disponibilité des services exposés lue dans Uptime Kuma (A.8.16,
 * A.5.30, A.8.24), sans base de données : adresse du serveur et pages de statut, lecture de
 * l'endpoint Prometheus « /metrics », clé d'une sonde, nom d'hôte surveillé, disponibilité et plus
 * longue panne calculées sur les relevés, état des certificats TLS, comparaison au RTO.
 *
 * Le plugin lit seulement : rien n'est jamais modifié dans Uptime Kuma, aucun certificat n'est
 * renouvelé.
 */
final class UptimeKumaRules
{
    /** États d'une sonde (valeurs d'Uptime Kuma). */
    public const STATUS_DOWN = 0;
    public const STATUS_UP = 1;
    public const STATUS_PENDING = 2;
    public const STATUS_MAINTENANCE = 3;
    /** Sonde sans état lu (page de statut sans relevé récent). */
    public const STATUS_UNKNOWN = -1;

    /** État d'un certificat TLS. */
    public const CERT_OK = 'ok';
    public const CERT_EXPIRING = 'expiring';
    public const CERT_EXPIRED = 'expired';
    public const CERT_INVALID = 'invalid';
    public const CERT_NONE = 'none';
    public const CERT_STATUSES = [
        self::CERT_EXPIRED, self::CERT_INVALID, self::CERT_EXPIRING, self::CERT_OK, self::CERT_NONE,
    ];

    public const DEFAULT_PERIOD_DAYS = 30;
    public const MAX_PERIOD_DAYS = 90;
    public const DEFAULT_CERT_DAYS = 30;
    public const MAX_CERT_DAYS = 180;
    public const DEFAULT_OUTAGE_MINUTES = 30;
    public const MAX_OUTAGE_MINUTES = 1440;

    /** Pages de statut lues au plus. */
    public const MAX_STATUS_PAGES = 20;

    /**
     * Au-delà de cet écart entre deux relevés, le temps n'est pas compté dans la disponibilité :
     * on ne sait pas ce qui s'est passé (lecture automatique arrêtée, par exemple).
     */
    public const MAX_SAMPLE_GAP = 7200;

    /**
     * Adresse du serveur Uptime Kuma à partir de ce que saisit l'administrateur : adresse de
     * l'interface, éventuellement derrière un chemin (proxy inverse), ou d'une de ses pages
     * (« /dashboard », « /metrics », « /status/<page> »). Schéma https, ou http si les adresses
     * internes sont autorisées ; sans identifiants, requête ni fragment. null si refusée.
     */
    public static function normalizeBaseUrl(string $url, bool $allowHttp = false): ?string
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
        if ($path !== '' && (preg_match('#^[A-Za-z0-9/_.\-~]*$#', $path) !== 1 || str_contains($path, '..'))) {
            return null;
        }
        $path = rtrim($path, '/');
        $pages = '#/(dashboard(/.*)?|metrics|status/[A-Za-z0-9_\-]+|manage-status-page)$#i';
        $path = preg_replace($pages, '', $path) ?? '';

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
    }

    /**
     * Identifiants des pages de statut (« slug ») saisis séparés par des virgules, des espaces ou
     * des retours à la ligne : minuscules, lettres, chiffres, tirets et soulignés ; sans doublon.
     *
     * @return list<string>
     */
    public static function normalizeSlugs(string $raw): array
    {
        $slugs = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower(trim($raw))) ?: [] as $slug) {
            if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $slug) === 1) {
                $slugs[$slug] = $slug;
            }
        }

        return array_slice(array_values($slugs), 0, self::MAX_STATUS_PAGES);
    }

    /**
     * Réglages relus d'une valeur stockée ou soumise : bornes et valeurs par défaut.
     *
     * @return array{period_days: int, cert_days: int, outage_minutes: int}
     */
    public static function normalizeSettings(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $bounded = static function (mixed $value, int $max, int $default): int {
            return is_numeric($value) ? max(1, min($max, (int) $value)) : $default;
        };

        return [
            'period_days'    => $bounded($raw['period_days'] ?? null, self::MAX_PERIOD_DAYS, self::DEFAULT_PERIOD_DAYS),
            'cert_days'      => $bounded($raw['cert_days'] ?? null, self::MAX_CERT_DAYS, self::DEFAULT_CERT_DAYS),
            'outage_minutes' => $bounded(
                $raw['outage_minutes'] ?? null,
                self::MAX_OUTAGE_MINUTES,
                self::DEFAULT_OUTAGE_MINUTES
            ),
        ];
    }

    public static function normalizeStatus(mixed $status): int
    {
        if (!is_numeric($status)) {
            return self::STATUS_UNKNOWN;
        }
        $status = (int) $status;

        $known = [self::STATUS_DOWN, self::STATUS_UP, self::STATUS_PENDING, self::STATUS_MAINTENANCE];

        return in_array($status, $known, true) ? $status : self::STATUS_UNKNOWN;
    }

    /**
     * Lignes « monitor_* » de l'endpoint Prometheus d'Uptime Kuma (format texte d'exposition) :
     * nom de la métrique, étiquettes et valeur. Les autres métriques et les commentaires sont ignorés.
     *
     * @return list<array{metric: string, labels: array<string, string>, value: float}>
     */
    public static function parseMetrics(string $body): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(monitor_[a-z_]+)(\{(.*)\})?\s+(\S+)(\s+\d+)?$/', $line, $m) !== 1) {
                continue;
            }
            if (!is_numeric($m[4])) {
                continue;
            }
            $labels = [];
            preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $m[3], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $labels[$pair[1]] = strtr($pair[2], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"]);
            }
            $rows[] = ['metric' => $m[1], 'labels' => $labels, 'value' => (float) $m[4]];
        }

        return $rows;
    }

    /**
     * Valeur d'étiquette Prometheus d'Uptime Kuma : « null » (écrit tel quel pour un champ vide)
     * devient une chaîne vide.
     */
    public static function labelValue(?string $value): string
    {
        $value = trim((string) $value);

        return in_array(mb_strtolower($value), ['null', 'undefined'], true) ? '' : $value;
    }

    /**
     * Clé stable d'une sonde : son identifiant Uptime Kuma s'il est connu, sinon son nom.
     */
    public static function monitorKey(string $id, string $name): string
    {
        if (preg_match('/^\d{1,20}$/', $id) === 1) {
            return 'id:' . $id;
        }

        return 'name:' . substr(hash('sha256', mb_strtolower(trim($name))), 0, 32);
    }

    /**
     * Nom d'hôte surveillé : celui de l'adresse (sondes HTTP), sinon le nom d'hôte de la sonde (ping,
     * port, DNS). Minuscules, vide si aucun.
     */
    public static function hostOf(string $url, string $hostname): string
    {
        $host = '';
        if ($url !== '') {
            $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        }
        if ($host === '') {
            $host = $hostname;
        }
        $host = mb_strtolower(trim($host, " []\t\n\r"));

        return preg_match('/^[a-z0-9.\-_:]{1,253}$/', $host) === 1 ? $host : '';
    }

    /**
     * Heure d'un relevé de page de statut (« 2026-01-15 10:00:00.000 », en UTC chez Uptime Kuma),
     * ou null si illisible.
     */
    public static function parseTime(string $time): ?int
    {
        $time = trim($time);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $time) !== 1) {
            return null;
        }
        $hasZone = preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $time) === 1;
        try {
            $date = new DateTimeImmutable($time, $hasZone ? null : new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->getTimestamp();
    }

    /**
     * Analyse des relevés d'une sonde sur la période [from, now] :
     * - disponibilité (%, deux décimales) : temps « en service » (UP, ou maintenance prévue) sur le
     *   temps mesuré (UP, maintenance, DOWN). Chaque relevé vaut jusqu'au suivant, au plus
     *   MAX_SAMPLE_GAP secondes ; « en attente » n'est compté ni pour ni contre. null sans mesure ;
     * - plus longue panne de la période (secondes) : relevés DOWN successifs, du premier au premier
     *   relevé revenu en service, ou jusqu'à maintenant si la panne dure encore ;
     * - début de la panne en cours, null si la sonde n'est pas en panne au dernier relevé.
     *
     * @param list<array{0: int, 1: int}> $samples [heure, état], dans n'importe quel ordre
     * @return array{availability: ?float, up: int, down: int, longest: int, down_since: ?int}
     */
    public static function analyse(array $samples, int $from, int $now, int $maxGap = self::MAX_SAMPLE_GAP): array
    {
        usort($samples, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $samples = array_values(array_filter($samples, static fn (array $s): bool => $s[0] <= $now));
        $up = 0;
        $down = 0;
        $longest = 0;
        $streak = null;
        $count = count($samples);
        foreach ($samples as $index => [$time, $status]) {
            $next = $index + 1 < $count ? $samples[$index + 1][0] : $now;
            $end = min($next, $time + $maxGap, $now);
            $start = max($time, $from);
            $span = max(0, $end - $start);
            if ($status === self::STATUS_UP || $status === self::STATUS_MAINTENANCE) {
                $up += $span;
            } elseif ($status === self::STATUS_DOWN) {
                $down += $span;
            }
            if ($status === self::STATUS_DOWN) {
                $streak ??= $time;
            } elseif ($status === self::STATUS_UP || $status === self::STATUS_MAINTENANCE) {
                if ($streak !== null && $time >= $from) {
                    $longest = max($longest, $time - max($streak, $from));
                }
                $streak = null;
            }
        }
        $downSince = null;
        if ($streak !== null) {
            $downSince = $streak;
            $longest = max($longest, $now - max($streak, $from));
        }

        return [
            'availability' => $up + $down > 0 ? round(100 * $up / ($up + $down), 2) : null,
            'up'           => $up,
            'down'         => $down,
            'longest'      => $longest,
            'down_since'   => $downSince,
        ];
    }

    /**
     * État d'un certificat : expiré, refusé par Uptime Kuma (chaîne ou nom non valides), proche de
     * l'échéance (moins de $thresholdDays jours), valide, ou aucun certificat relevé.
     */
    public static function certStatus(?int $daysRemaining, ?bool $valid, int $thresholdDays): string
    {
        if ($daysRemaining === null && $valid === null) {
            return self::CERT_NONE;
        }
        if ($daysRemaining !== null && $daysRemaining < 0) {
            return self::CERT_EXPIRED;
        }
        if ($valid === false) {
            return self::CERT_INVALID;
        }
        if ($daysRemaining !== null && $daysRemaining <= $thresholdDays) {
            return self::CERT_EXPIRING;
        }

        return self::CERT_OK;
    }

    /** Certificat à traiter (expiré, non valide ou proche de l'échéance). */
    public static function certNeedsAction(string $status): bool
    {
        return in_array($status, [self::CERT_EXPIRED, self::CERT_INVALID, self::CERT_EXPIRING], true);
    }

    /**
     * Une panne plus longue que le RTO du processus métier (heures ; 0 = non défini, jamais en écart).
     */
    public static function exceedsRto(int $outageSeconds, int $rtoHours): bool
    {
        return $rtoHours > 0 && $outageSeconds > $rtoHours * 3600;
    }

    /** Panne assez longue pour être proposée comme incident (réglage en minutes). */
    public static function isLongOutage(int $outageSeconds, int $thresholdMinutes): bool
    {
        return $outageSeconds > 0 && $outageSeconds >= $thresholdMinutes * 60;
    }

    /**
     * Gravité de l'incident proposé : critique si la panne dépasse le RTO, majeure pour un service
     * critique, mineure sinon.
     */
    public static function incidentSeverity(bool $exceedsRto, bool $critical): string
    {
        return match (true) {
            $exceedsRto => 'critical',
            $critical   => 'major',
            default     => 'minor',
        };
    }
}
