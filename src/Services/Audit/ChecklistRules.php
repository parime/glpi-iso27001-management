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

namespace GlpiPlugin\Grcmanager\Services\Audit;

/**
 * Issue #606 : règles de la grille d'audit interne et de la couverture du programme d'audit
 * (PHP pur, testé seul).
 *
 * Une ligne de la grille vise une exigence du SMSI (articles 4 à 10), une mesure de l'Annexe A, une
 * exigence d'un référentiel personnalisé, ou un point libre. Son résultat part de « non audité » et
 * ne change que par la saisie de l'auditeur. Un constat (non-conformité ou observation) se crée
 * depuis une ligne, toujours par un clic de l'utilisateur, jamais automatiquement.
 *
 * La grille se modifie tant que l'audit n'est ni terminé ni annulé.
 */
final class ChecklistRules
{
    public const TYPE_CLAUSE = 'clause';
    public const TYPE_CONTROL = 'control';
    public const TYPE_CUSTOM = 'custom';
    public const TYPE_FREE = 'free';

    public const TYPES = [self::TYPE_CLAUSE, self::TYPE_CONTROL, self::TYPE_CUSTOM, self::TYPE_FREE];

    public const RESULT_NOT_AUDITED = 'not_audited';
    public const RESULT_COMPLIANT = 'compliant';
    public const RESULT_NC_MINOR = 'nc_minor';
    public const RESULT_NC_MAJOR = 'nc_major';
    public const RESULT_OBSERVATION = 'observation';
    public const RESULT_IMPROVEMENT = 'improvement';

    /** Ordre d'affichage des résultats (listes, compteurs). */
    public const RESULTS = [
        self::RESULT_COMPLIANT, self::RESULT_NC_MINOR, self::RESULT_NC_MAJOR, self::RESULT_OBSERVATION,
        self::RESULT_IMPROVEMENT, self::RESULT_NOT_AUDITED,
    ];

    /** Statuts d'audit qui figent la grille. */
    public const CLOSED_AUDIT_STATUSES = ['completed', 'cancelled'];

    /** Période de la vue de couverture : trois ans par défaut (cycle de certification), 1 à 10. */
    public const DEFAULT_COVERAGE_YEARS = 3;
    public const MAX_COVERAGE_YEARS = 10;

    /** Longueurs des colonnes varchar. */
    public const MAX_CODE_LENGTH = 50;
    public const MAX_LABEL_LENGTH = 255;

    /** Nombre maximal de lignes ajoutées en une fois (garde-fou d'un formulaire forgé). */
    public const MAX_LINES_PER_ADD = 300;

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function isResult(string $result): bool
    {
        return in_array($result, self::RESULTS, true);
    }

    public static function normalizeResult(string $result): string
    {
        return self::isResult($result) ? $result : self::RESULT_NOT_AUDITED;
    }

    /** La ligne a été auditée (tout résultat sauf « non audité »). */
    public static function isAudited(string $result): bool
    {
        return self::isResult($result) && $result !== self::RESULT_NOT_AUDITED;
    }

    /** Résultat qui appelle un constat au registre des non-conformités. */
    public static function canRaiseFinding(string $result): bool
    {
        return in_array(
            $result,
            [self::RESULT_NC_MINOR, self::RESULT_NC_MAJOR, self::RESULT_OBSERVATION, self::RESULT_IMPROVEMENT],
            true
        );
    }

    /** Résultat qui signale un écart (non-conformité mineure ou majeure). */
    public static function isNonconformity(string $result): bool
    {
        return in_array($result, [self::RESULT_NC_MINOR, self::RESULT_NC_MAJOR], true);
    }

    /**
     * Type et gravité proposés pour le constat créé depuis une ligne (valeurs de Nonconformity),
     * relus par l'utilisateur avant tout enregistrement ; null si le résultat n'appelle pas de constat.
     *
     * @return array{finding_type: string, severity: string}|null
     */
    public static function findingPreset(string $result): ?array
    {
        return match ($result) {
            self::RESULT_NC_MINOR => ['finding_type' => 'nonconformity', 'severity' => 'minor'],
            self::RESULT_NC_MAJOR => ['finding_type' => 'nonconformity', 'severity' => 'major'],
            self::RESULT_OBSERVATION,
            self::RESULT_IMPROVEMENT => ['finding_type' => 'observation', 'severity' => 'minor'],
            default => null,
        };
    }

    public static function isAuditClosed(string $auditStatus): bool
    {
        return in_array($auditStatus, self::CLOSED_AUDIT_STATUSES, true);
    }

    /**
     * Clé d'une exigence, commune aux lignes de grille, aux modèles et à la couverture : le numéro
     * pour un article ou une mesure (stable d'une entité à l'autre), l'identifiant pour une exigence
     * de référentiel personnalisé, rien pour un point libre.
     */
    public static function refKey(string $type, string $code, int $itemsId): ?string
    {
        return match ($type) {
            self::TYPE_CLAUSE, self::TYPE_CONTROL => trim($code) === '' ? null : $type . ':' . trim($code),
            self::TYPE_CUSTOM => $itemsId > 0 ? $type . ':' . $itemsId : null,
            default => null,
        };
    }

    public static function cleanText(mixed $value, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '') ?? '');

        return mb_substr($text, 0, $maxLength);
    }

    public static function cleanLongText(mixed $value): string
    {
        return trim(str_replace("\r\n", "\n", is_scalar($value) ? (string) $value : ''));
    }

    public static function coverageYears(mixed $value): int
    {
        $years = is_numeric($value) ? (int) $value : self::DEFAULT_COVERAGE_YEARS;

        return max(1, min(self::MAX_COVERAGE_YEARS, $years));
    }

    /** Premier jour pris en compte par la vue de couverture (même jour, $years ans plus tôt). */
    public static function coverageStart(string $today, int $years): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $today) ?: new \DateTimeImmutable('today');

        return $date->modify('-' . self::coverageYears($years) . ' years')->format('Y-m-d');
    }

    /**
     * Compteurs d'une grille par résultat et part des lignes auditées.
     *
     * @param list<string> $results
     * @return array{total: int, audited: int, percent: int, counts: array<string, int>}
     */
    public static function summary(array $results): array
    {
        $counts = array_fill_keys(self::RESULTS, 0);
        foreach ($results as $result) {
            $counts[self::normalizeResult($result)]++;
        }
        $total = count($results);
        $audited = $total - $counts[self::RESULT_NOT_AUDITED];

        return [
            'total'   => $total,
            'audited' => $audited,
            'percent' => $total > 0 ? (int) floor($audited * 100 / $total) : 0,
            'counts'  => $counts,
        ];
    }

    /**
     * Couverture du programme d'audit : pour chaque exigence du catalogue, le dernier audit (date
     * et résultat) parmi les lignes auditées depuis $since ; celles jamais auditées sur la période
     * en tête, puis les plus anciennement auditées. À date égale, le résultat le plus défavorable
     * l'emporte (une non-conformité n'est jamais masquée par une ligne conforme du même jour).
     *
     * @param list<array{key: string, type: string, code: string, label: string}> $catalog
     * @param list<array{key: ?string, date: ?string, result: string, audit_id: int, audit_title: string}> $lines
     * @return array{rows: list<array<string, mixed>>, total: int, covered: int, never: int, percent: int}
     */
    public static function coverage(array $catalog, array $lines, string $since): array
    {
        $last = [];
        foreach ($lines as $line) {
            $key = $line['key'];
            $date = (string) ($line['date'] ?? '');
            if ($key === null || $date === '' || $date < $since || !self::isAudited($line['result'])) {
                continue;
            }
            $current = $last[$key] ?? null;
            $worse = $current !== null && $date === $current['date']
                && self::severityRank($line['result']) > self::severityRank($current['result']);
            if ($current === null || $date > $current['date'] || $worse) {
                $last[$key] = $line;
            }
        }

        $rows = [];
        $rank = 0;
        foreach ($catalog as $entry) {
            $line = $last[$entry['key']] ?? null;
            $rows[] = $entry + [
                'rank'        => $rank++,
                'audited'     => $line !== null,
                'last_date'   => $line['date'] ?? null,
                'last_result' => $line['result'] ?? null,
                'audit_id'    => $line['audit_id'] ?? 0,
                'audit_title' => $line['audit_title'] ?? '',
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['audited'] !== $b['audited']) {
                return $a['audited'] ? 1 : -1;
            }
            if ($a['audited'] && $a['last_date'] !== $b['last_date']) {
                return strcmp((string) $a['last_date'], (string) $b['last_date']);
            }

            return $a['rank'] <=> $b['rank'];
        });

        $total = count($rows);
        $covered = count(array_filter($rows, static fn (array $row): bool => $row['audited']));

        return [
            'rows'    => $rows,
            'total'   => $total,
            'covered' => $covered,
            'never'   => $total - $covered,
            'percent' => $total > 0 ? (int) floor($covered * 100 / $total) : 0,
        ];
    }

    /** Plus le résultat est défavorable, plus le rang est élevé. */
    public static function severityRank(string $result): int
    {
        return match ($result) {
            self::RESULT_NC_MAJOR => 5,
            self::RESULT_NC_MINOR => 4,
            self::RESULT_OBSERVATION => 3,
            self::RESULT_IMPROVEMENT => 2,
            self::RESULT_COMPLIANT => 1,
            default => 0,
        };
    }
}
