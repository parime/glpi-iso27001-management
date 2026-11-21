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

namespace GlpiPlugin\Grcmanager\Services\Compliance;

/**
 * Issue #781 : règles pures de la conformité de configuration (résultats OpenSCAP), sans GLPI.
 *
 * Taux de conformité d'un rapport : règles conformes (pass, fixed) sur règles évaluées (conformes,
 * en échec, en erreur). Les règles non applicables, non vérifiées ou non sélectionnées ne comptent pas.
 */
final class ConfigScanRules
{
    /** Taux en dessous duquel un ordinateur est signalé (%). */
    public const TARGET_RATE = 80;

    /** Ordre d'affichage des gravités, la plus forte d'abord. */
    public const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3, 'unknown' => 4];

    /**
     * Taux de conformité (%) d'après les compteurs, null si aucune règle n'est évaluée.
     */
    public static function rate(int $pass, int $fail, int $error, int $fixed = 0): ?int
    {
        $ok = max(0, $pass) + max(0, $fixed);
        $evaluated = $ok + max(0, $fail) + max(0, $error);
        if ($evaluated === 0) {
            return null;
        }

        return (int) floor($ok * 100 / $evaluated);
    }

    /**
     * Couleur du badge d'un taux.
     */
    public static function rateColor(?int $rate): string
    {
        if ($rate === null) {
            return 'secondary';
        }
        if ($rate >= 95) {
            return 'success';
        }

        return $rate >= self::TARGET_RATE ? 'warning' : 'danger';
    }

    /**
     * Couleur du badge d'une gravité XCCDF.
     */
    public static function severityColor(string $severity): string
    {
        return match ($severity) {
            'high'   => 'danger',
            'medium' => 'warning',
            'low'    => 'info',
            default  => 'secondary',
        };
    }

    /**
     * Évolution du taux par rapport au rapport précédent du même profil : écart en points, null sans
     * rapport précédent comparable.
     */
    public static function trend(?int $previous, ?int $current): ?int
    {
        if ($previous === null || $current === null) {
            return null;
        }

        return $current - $previous;
    }

    /**
     * Nom d'hôte normalisé pour le rapprochement et la détection des doublons (minuscules, sans point
     * final).
     */
    public static function normalizeHost(string $host): string
    {
        return rtrim(mb_strtolower(trim($host)), '.');
    }

    /**
     * Noms à essayer pour rapprocher un rapport d'un ordinateur, du plus précis au moins précis :
     * nom d'hôte déclaré, cible, nom complet. Un nom complet donne aussi son nom court.
     *
     * @param array{hostname?: string, target?: string, fqdn?: string} $report
     * @return list<string>
     */
    public static function candidateNames(array $report): array
    {
        $names = [];
        foreach (['hostname', 'target', 'fqdn'] as $key) {
            $name = self::normalizeHost((string) ($report[$key] ?? ''));
            // Une adresse IP n'est pas un nom d'ordinateur.
            if ($name === '' || filter_var($name, FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            $names[] = $name;
            $short = (string) strtok($name, '.');
            if ($short !== $name) {
                $names[] = $short;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Garde le dernier rapport de chaque cible (ordinateur rapproché, sinon nom d'hôte) et de chaque
     * profil. Les rapports doivent porter computers_id, hostname, profile_id et scan_date.
     *
     * @param list<array<string, mixed>> $reports
     * @return list<array<string, mixed>>
     */
    public static function latestPerTarget(array $reports): array
    {
        $latest = [];
        foreach ($reports as $report) {
            $key = self::targetKey($report) . '|' . (string) ($report['profile_id'] ?? '');
            if (
                !isset($latest[$key])
                || [(string) $report['scan_date'], (int) ($report['id'] ?? 0)]
                    > [(string) $latest[$key]['scan_date'], (int) ($latest[$key]['id'] ?? 0)]
            ) {
                $latest[$key] = $report;
            }
        }

        return array_values($latest);
    }

    /**
     * Rapport précédent de la même cible et du même profil, pour chaque rapport (identifiant du
     * rapport => taux précédent).
     *
     * @param list<array<string, mixed>> $reports rapports avec 'rate'
     * @return array<int, ?int>
     */
    public static function previousRates(array $reports): array
    {
        usort(
            $reports,
            static fn (array $a, array $b): int => [(string) $a['scan_date'], (int) $a['id']]
                <=> [(string) $b['scan_date'], (int) $b['id']]
        );
        $last = [];
        $previous = [];
        foreach ($reports as $report) {
            $key = self::targetKey($report) . '|' . (string) ($report['profile_id'] ?? '');
            $previous[(int) $report['id']] = array_key_exists($key, $last) ? $last[$key] : null;
            $last[$key] = $report['rate'];
        }

        return $previous;
    }

    /**
     * Règles en échec les plus fréquentes : par règle, nombre de cibles concernées, gravité et titre.
     *
     * @param list<array{rule_id: string, title: string, severity: string, target: string}> $failures
     * @return list<array{rule_id: string, title: string, severity: string, count: int}>
     */
    public static function topFailing(array $failures, int $limit = 10): array
    {
        $rules = [];
        foreach ($failures as $failure) {
            $id = $failure['rule_id'];
            $rules[$id] ??= [
                'rule_id' => $id, 'title' => $failure['title'], 'severity' => $failure['severity'], 'targets' => [],
            ];
            $rules[$id]['targets'][$failure['target']] = true;
            if ($rules[$id]['title'] === '' && $failure['title'] !== '') {
                $rules[$id]['title'] = $failure['title'];
            }
        }
        $list = array_map(static fn (array $rule): array => [
            'rule_id'  => $rule['rule_id'],
            'title'    => $rule['title'],
            'severity' => $rule['severity'],
            'count'    => count($rule['targets']),
        ], array_values($rules));
        usort($list, static fn (array $a, array $b): int => [
            -$a['count'], self::SEVERITY_ORDER[$a['severity']] ?? 9, $a['rule_id'],
        ] <=> [
            -$b['count'], self::SEVERITY_ORDER[$b['severity']] ?? 9, $b['rule_id'],
        ]);

        return array_slice($list, 0, max(1, $limit));
    }

    /**
     * Synthèse des derniers rapports : cibles analysées, taux moyen, cibles sous l'objectif, cibles avec
     * une règle de gravité haute en échec, rapports non rapprochés.
     *
     * @param list<array<string, mixed>> $latest derniers rapports, avec 'rate', 'high_fail_count', 'computers_id'
     * @return array{targets: int, average: ?int, below_target: int, with_high: int, unmatched: int,
     *     high_rate: ?int}
     */
    public static function summary(array $latest): array
    {
        $targets = [];
        $rates = [];
        $below = [];
        $high = [];
        $unmatched = [];
        foreach ($latest as $report) {
            $key = self::targetKey($report);
            $targets[$key] = true;
            if ($report['rate'] !== null) {
                $rates[] = (int) $report['rate'];
                if ((int) $report['rate'] < self::TARGET_RATE) {
                    $below[$key] = true;
                }
            }
            if ((int) ($report['high_fail_count'] ?? 0) > 0) {
                $high[$key] = true;
            }
            if ((int) ($report['computers_id'] ?? 0) === 0) {
                $unmatched[$key] = true;
            }
        }
        $count = count($targets);

        return [
            'targets'      => $count,
            'average'      => $rates === [] ? null : (int) floor(array_sum($rates) / count($rates)),
            'below_target' => count($below),
            'with_high'    => count($high),
            'unmatched'    => count($unmatched),
            // Part des cibles analysées avec au moins une règle de gravité haute en échec (KRI).
            'high_rate'    => $count === 0 ? null : (int) round(count($high) * 100 / $count),
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function targetKey(array $report): string
    {
        $computer = (int) ($report['computers_id'] ?? 0);

        return $computer > 0 ? 'c' . $computer : 'h' . self::normalizeHost((string) ($report['hostname'] ?? ''));
    }
}
