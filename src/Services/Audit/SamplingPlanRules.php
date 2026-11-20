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
 * Issue #772, lot 2 : plan d'audit de l'organisme de certification. Règles pures (sans GLPI) :
 *
 * - matrice processus x années du cycle de certification de trois ans ; chaque case cochée est
 *   un processus que l'organisme échantillonne lors de l'audit de certification de cette année ;
 * - les processus sont ceux du programme d'audit interne (activités de la matrice des
 *   responsabilités), jamais une seconde liste ;
 * - rapprochement d'un constat de l'organisme avec un processus : même libellé (casse, accents et
 *   espaces ignorés), ou clause ou mesure du constat parmi les mesures du processus ;
 * - écrans du plugin qui répondent à une activité proposée par défaut.
 */
final class SamplingPlanRules
{
    /**
     * Écrans du plugin qui produisent les preuves d'une activité proposée par défaut, en plus des
     * mesures de l'Annexe A de l'activité (classes GLPI, vérifiées à l'affichage).
     *
     * @var array<string, list<string>>
     */
    public const SCREENS = [
        'risk_assessment'          => ['GlpiPlugin\\Grcmanager\\Risk'],
        'risk_acceptance'          => ['GlpiPlugin\\Grcmanager\\Risk'],
        'policy_approval'          => ['GlpiPlugin\\Grcmanager\\Policy'],
        'asset_inventory'          => [],
        'access_rights'            => [
            'GlpiPlugin\\Grcmanager\\AccessReview',
            'GlpiPlugin\\Grcmanager\\AccountHygiene',
        ],
        'access_review'            => ['GlpiPlugin\\Grcmanager\\AccessReview'],
        'privileged_access'        => ['GlpiPlugin\\Grcmanager\\AccountHygiene'],
        'change_management'        => [],
        'backups'                  => [],
        'log_review'               => [],
        'vulnerability_management' => ['GlpiPlugin\\Grcmanager\\ParkVulnerability'],
        'incident_management'      => ['PluginGrcmanagerSecurityIncident'],
        'supplier_security'        => ['GlpiPlugin\\Grcmanager\\SupplierRisk'],
        'awareness_training'       => ['GlpiPlugin\\Grcmanager\\Training'],
        'business_continuity'      => [
            'GlpiPlugin\\Grcmanager\\BusinessProcess',
            'GlpiPlugin\\Grcmanager\\CrisisExercise',
        ],
        'internal_audit'           => ['GlpiPlugin\\Grcmanager\\Audit', 'GlpiPlugin\\Grcmanager\\AuditProgramme'],
        'management_review'        => ['GlpiPlugin\\Grcmanager\\ManagementReview'],
    ];

    /** @return list<string> */
    public static function screensFor(string $defaultKey): array
    {
        return self::SCREENS[$defaultKey] ?? [];
    }

    /** Libellé comparable : minuscules, sans accents, espaces et ponctuation réduits. */
    public static function normalizeLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($label, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $label = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $label));
    }

    /**
     * Codes d'une clause ou d'une mesure saisie librement (« A.5.15, A.5.18 », « 9.2 ») : en
     * majuscules, sans espaces.
     *
     * @return list<string>
     */
    public static function splitCodes(string $codes): array
    {
        $parts = preg_split('/[\s,;\/]+/', strtoupper(trim($codes))) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    /**
     * Le constat porte-t-il sur le processus ? Même libellé de processus, ou une de ses clauses ou
     * mesures est une mesure de l'Annexe A du processus.
     *
     * @param list<string> $processCodes mesures de l'Annexe A du processus
     */
    public static function findingConcernsProcess(
        string $findingProcess,
        string $findingRequirement,
        string $processLabel,
        array $processCodes
    ): bool {
        $label = self::normalizeLabel($processLabel);
        if ($label !== '' && self::normalizeLabel($findingProcess) === $label) {
            return true;
        }
        $codes = self::splitCodes(implode(',', $processCodes));

        return $codes !== [] && array_intersect(self::splitCodes($findingRequirement), $codes) !== [];
    }

    /**
     * Audits de certification du cycle, par année (date réelle, sinon prévue). Les audits annulés
     * ou sans date, et ceux hors du cycle, sont écartés ; chaque année du cycle est présente.
     *
     * @param list<array{id: int, status: string, date: string}> $audits
     * @param list<int> $years années du cycle
     * @return array<int, list<array{id: int, status: string, date: string}>>
     */
    public static function auditsByYear(array $audits, array $years): array
    {
        $byYear = array_fill_keys($years, []);
        foreach ($audits as $audit) {
            if ($audit['status'] === 'cancelled' || $audit['date'] === '') {
                continue;
            }
            $year = (int) substr($audit['date'], 0, 4);
            if (array_key_exists($year, $byYear)) {
                $byYear[$year][] = $audit;
            }
        }
        foreach ($byYear as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        }
        unset($list);

        return $byYear;
    }

    /**
     * Matrice processus x audits de certification du cycle.
     *
     * @param array<int, string> $processes id => libellé, dans l'ordre d'affichage
     * @param array<int, list<int>> $sampled audit => processus échantillonnés
     * @param list<int> $auditIds audits du cycle, dans l'ordre des colonnes
     * @return array{rows: list<array{id: int, label: string, cells: array<int, bool>, count: int}>,
     *               sampled: int, never: int, total: int}
     */
    public static function matrix(array $processes, array $sampled, array $auditIds): array
    {
        $rows = [];
        $never = 0;
        $cellsCount = 0;
        foreach ($processes as $id => $label) {
            $cells = [];
            $count = 0;
            foreach ($auditIds as $auditId) {
                $cells[$auditId] = in_array($id, $sampled[$auditId] ?? [], true);
                $count += (int) $cells[$auditId];
            }
            if ($count === 0) {
                $never++;
            }
            $cellsCount += $count;
            $rows[] = ['id' => $id, 'label' => $label, 'cells' => $cells, 'count' => $count];
        }

        return ['rows' => $rows, 'sampled' => $cellsCount, 'never' => $never, 'total' => count($rows)];
    }

    /**
     * Audit de l'année : celui de l'année en cours encore à venir ou en cours, sinon le dernier
     * de l'année, sinon le prochain audit, sinon le dernier réalisé. 0 s'il n'y en a aucun.
     *
     * @param list<array{id: int, status: string, date: string}> $audits
     */
    public static function auditOfTheYear(array $audits, string $today): int
    {
        $audits = array_values(array_filter(
            $audits,
            static fn (array $a): bool => $a['status'] !== 'cancelled' && $a['date'] !== ''
        ));
        usort($audits, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $year = substr($today, 0, 4);
        $thisYear = array_values(array_filter(
            $audits,
            static fn (array $a): bool => substr($a['date'], 0, 4) === $year
        ));
        foreach ($thisYear as $audit) {
            if ($audit['date'] >= $today || $audit['status'] === 'in_progress') {
                return $audit['id'];
            }
        }
        if ($thisYear !== []) {
            return $thisYear[count($thisYear) - 1]['id'];
        }
        foreach ($audits as $audit) {
            if ($audit['date'] >= $today) {
                return $audit['id'];
            }
        }

        return $audits === [] ? 0 : $audits[count($audits) - 1]['id'];
    }

    /**
     * Audits antérieurs à un audit (date strictement plus ancienne ; sans date, tous les autres).
     *
     * @param list<array{id: int, status: string, date: string}> $audits
     * @return list<int>
     */
    public static function previousAudits(array $audits, int $auditId, string $auditDate): array
    {
        $ids = [];
        foreach ($audits as $audit) {
            if ($audit['id'] === $auditId) {
                continue;
            }
            if ($auditDate === '' || ($audit['date'] !== '' && $audit['date'] < $auditDate)) {
                $ids[] = $audit['id'];
            }
        }

        return $ids;
    }
}
