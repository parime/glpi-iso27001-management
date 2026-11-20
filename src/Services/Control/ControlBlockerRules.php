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

use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;

/**
 * Issue #867 : règles du panneau « ce qui bloque cette mesure », sans dépendance à GLPI (testées
 * unitairement). Aucun contrôle n'est refait ici : les écarts viennent des contrôles de cohérence,
 * des tests d'efficacité, des non-conformités, des politiques et des contrôles techniques ; ces
 * règles ne font que les rattacher à une mesure, leur donner une gravité et les trier.
 */
final class ControlBlockerRules
{
    public const CATEGORY_TECHNICAL = 'technical';
    public const CATEGORY_EVIDENCE = 'evidence';
    public const CATEGORY_POLICY = 'policy';
    public const CATEGORY_TEST = 'test';
    public const CATEGORY_NONCONFORMITY = 'nonconformity';
    public const CATEGORY_WAIVER = 'waiver';
    public const CATEGORY_SOA = 'soa';

    /** Ordre d'affichage des catégories. */
    public const CATEGORIES = [
        self::CATEGORY_TECHNICAL, self::CATEGORY_EVIDENCE, self::CATEGORY_POLICY, self::CATEGORY_TEST,
        self::CATEGORY_NONCONFORMITY, self::CATEGORY_WAIVER, self::CATEGORY_SOA,
    ];

    /** Mesure qui porte les politiques de sécurité (5.2 et A.5.1). */
    public const POLICY_CONTROL = 'A.5.1';

    /**
     * Règles des contrôles de cohérence reprises dans le panneau, et la catégorie de chacune.
     * Les non-conformités en retard sont lues directement (toutes les non-conformités ouvertes
     * bloquent, pas seulement celles en retard).
     */
    public const CONSISTENCY_RULES = [
        'control_exclusion_weak_justification' => self::CATEGORY_SOA,
        'control_applicable_without_risk'      => self::CATEGORY_SOA,
        'control_without_evidence'             => self::CATEGORY_EVIDENCE,
        'evidence_expired'                     => self::CATEGORY_EVIDENCE,
        'waiver_expired'                       => self::CATEGORY_WAIVER,
        'policy_review_overdue'                => self::CATEGORY_POLICY,
        'policy_without_owner'                 => self::CATEGORY_POLICY,
        'nonconformity_closed_without_action'  => self::CATEGORY_NONCONFORMITY,
    ];

    private const SEVERITY_RANKS = [
        ConsistencyRules::SEVERITY_HIGH   => 0,
        ConsistencyRules::SEVERITY_MEDIUM => 1,
        ConsistencyRules::SEVERITY_LOW    => 2,
    ];

    /** Colonnes de tri de la vue globale. */
    public const SORTS = ['severity', 'blockers', 'code'];

    /**
     * Une référence libre (« A.8.16, A.5.7 », « Annexe A.8.16 ») cite-t-elle cette mesure ? Le code
     * doit être entier : A.8.1 n'est pas cité par A.8.16.
     */
    public static function referencesControl(string $reference, string $code): bool
    {
        if ($code === '' || trim($reference) === '') {
            return false;
        }

        return preg_match('/(?<![0-9A-Za-z.])' . preg_quote($code, '/') . '(?![0-9])(?!\.[0-9])/i', $reference) === 1;
    }

    public static function severityRank(string $severity): int
    {
        return self::SEVERITY_RANKS[$severity] ?? count(self::SEVERITY_RANKS);
    }

    /**
     * Blocages d'un test d'efficacité : dernier résultat inefficace ou partiel, échéance dépassée.
     *
     * @return list<array{kind: string, severity: string}>
     */
    public static function testBlockers(?string $latestResult, string $dueStatus): array
    {
        $blockers = [];
        if ($latestResult === ControlTestRules::RESULT_INEFFECTIVE) {
            $blockers[] = ['kind' => 'ineffective', 'severity' => ConsistencyRules::SEVERITY_HIGH];
        } elseif ($latestResult === ControlTestRules::RESULT_PARTIAL) {
            $blockers[] = ['kind' => 'partial', 'severity' => ConsistencyRules::SEVERITY_MEDIUM];
        }
        if ($dueStatus === ControlTestRules::DUE_OVERDUE) {
            $blockers[] = ['kind' => 'overdue', 'severity' => ConsistencyRules::SEVERITY_MEDIUM];
        }

        return $blockers;
    }

    /**
     * Gravité d'un résultat de contrôle technique (ControlTestRules::resultFromTechnicalCheck()),
     * null s'il ne bloque pas.
     */
    public static function technicalSeverity(?string $result): ?string
    {
        return match ($result) {
            ControlTestRules::RESULT_INEFFECTIVE => ConsistencyRules::SEVERITY_HIGH,
            ControlTestRules::RESULT_PARTIAL     => ConsistencyRules::SEVERITY_MEDIUM,
            default                              => null,
        };
    }

    /**
     * Gravité d'une non-conformité ouverte : élevée si majeure, critique ou en retard, moyenne sinon.
     */
    public static function nonconformitySeverity(string $severity, bool $overdue): string
    {
        return $overdue || in_array($severity, ['major', 'critical'], true)
            ? ConsistencyRules::SEVERITY_HIGH
            : ConsistencyRules::SEVERITY_MEDIUM;
    }

    /**
     * Blocages d'une mesure, du plus grave au moins grave, puis par catégorie et par nom.
     *
     * @param list<array<string, mixed>> $blockers
     * @return list<array<string, mixed>>
     */
    public static function sortBlockers(array $blockers): array
    {
        $order = array_flip(self::CATEGORIES);
        usort($blockers, static fn (array $a, array $b): int => [
            self::severityRank((string) $a['severity']),
            $order[$a['category']] ?? 99,
            (string) $a['name'],
        ] <=> [
            self::severityRank((string) $b['severity']),
            $order[$b['category']] ?? 99,
            (string) $b['name'],
        ]);

        return $blockers;
    }

    /**
     * Synthèse d'une mesure : nombre de blocages par gravité et gravité la plus haute.
     *
     * @param list<array<string, mixed>> $blockers
     * @return array{total: int, worst: ?string, by_severity: array<string, int>}
     */
    public static function summarize(array $blockers): array
    {
        $counts = array_fill_keys(array_keys(self::SEVERITY_RANKS), 0);
        $worst = null;
        foreach ($blockers as $blocker) {
            $severity = (string) $blocker['severity'];
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
            if ($worst === null || self::severityRank($severity) < self::severityRank($worst)) {
                $worst = $severity;
            }
        }

        return ['total' => count($blockers), 'worst' => $worst, 'by_severity' => $counts];
    }

    public static function normalizeSort(mixed $sort): string
    {
        return in_array($sort, self::SORTS, true) ? (string) $sort : self::SORTS[0];
    }

    public static function normalizeOrder(mixed $order): string
    {
        return strtoupper((string) $order) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * Tri de la vue globale. « severity » ASC : la plus grave d'abord, puis le plus de blocages ;
     * « blockers » ASC : le plus de blocages d'abord ; « code » ASC : A.5.1, A.5.2... A.5.10.
     *
     * @param list<array<string, mixed>> $rows lignes avec code et summary (voir summarize())
     * @return list<array<string, mixed>>
     */
    public static function sortRows(array $rows, string $sort, string $order): array
    {
        $key = static fn (array $row): array => match ($sort) {
            'code'     => [0, 0, 0],
            'blockers' => [-$row['summary']['total'], self::severityRank((string) $row['summary']['worst']), 0],
            default    => [
                self::severityRank((string) $row['summary']['worst']),
                -($row['summary']['by_severity'][ConsistencyRules::SEVERITY_HIGH] ?? 0),
                -$row['summary']['total'],
            ],
        };
        usort($rows, static function (array $a, array $b) use ($key, $order): int {
            $cmp = $key($a) <=> $key($b);
            if ($cmp === 0) {
                $cmp = strnatcmp((string) $a['code'], (string) $b['code']);
            }

            return $order === 'DESC' ? -$cmp : $cmp;
        });

        return $rows;
    }
}
