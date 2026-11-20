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

use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceValidity;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;

/**
 * Issue #379 : évaluation de conformité par référentiel, à partir des mesures de l'Annexe A que le
 * référentiel recouvre (correspondance de chaque catalogue) et des preuves déjà rattachées à ces
 * mesures (documents GLPI, avec leur date de validité éventuelle).
 *
 * - taux de mise en œuvre : le calcul habituel (MaturityScore::implementationRate) ;
 * - taux de preuve : part des mesures applicables qui ont au moins une preuve non expirée ;
 * - score : part des mesures applicables à la fois mises en œuvre (ou vérifiées) et prouvées. C'est
 *   ce qu'un auditeur accepterait sans réserve ; une mesure déclarée faite sans preuve n'y compte pas.
 *
 * Issue #789 : un référentiel qui a ses propres mesures suivies dans le plugin est évalué sur
 * celles-ci (assessMeasures()), et non plus sur l'Annexe A.
 */
final class FrameworkAssessmentRules
{
    public const EVIDENCE_PROVEN  = 'proven';
    public const EVIDENCE_EXPIRED = 'expired';
    public const EVIDENCE_NONE    = 'none';

    private const DONE = ['implemented', 'verified'];

    /**
     * État de preuve d'une mesure : prouvée si au moins un document n'a pas expiré (sans date de
     * validité, un document prouve sans limite), expirée si tous ont expiré, absente sinon.
     *
     * @param list<string|null> $validUntils une entrée par document lié à la mesure
     */
    public static function evidenceStatus(array $validUntils, string $today): string
    {
        if ($validUntils === []) {
            return self::EVIDENCE_NONE;
        }
        foreach ($validUntils as $validUntil) {
            if (EvidenceValidity::status($validUntil, $today) !== EvidenceValidity::STATUS_EXPIRED) {
                return self::EVIDENCE_PROVEN;
            }
        }

        return self::EVIDENCE_EXPIRED;
    }

    /**
     * @param array<string, array{id?: int, applicability: string, implementation_status: string,
     *     evidence: string}> $controls mesures de l'Annexe A, par code
     * @param callable(string): bool $covers la mesure relève-t-elle du référentiel ?
     * @return array{applicable: int, implementation_rate: float|null, evidence_rate: float|null,
     *     score: float|null, done: int, proven: int, compliant: int, gaps: list<array<string, mixed>>}
     *     écarts : code, id, implementation_status, evidence
     */
    public static function assess(array $controls, callable $covers): array
    {
        $measures = [];
        foreach ($controls as $code => $row) {
            $code = (string) $code;
            if (!$covers($code)) {
                continue;
            }
            $measures[] = [
                'code'                  => $code,
                'id'                    => (int) ($row['id'] ?? 0),
                'applicable'            => $row['applicability'] !== 'no',
                'implementation_status' => $row['implementation_status'],
                'evidence'              => $row['evidence'],
            ];
        }

        return self::assessMeasures($measures);
    }

    /**
     * Issue #789 : même calcul sur les mesures propres d'un référentiel (exigences d'un référentiel
     * personnalisé, points du registre NIS2 Italia). Une mesure dont la preuve n'est pas suivie
     * (evidence = null, l'auto-évaluation d'un référentiel personnalisé) compte dans le score dès
     * qu'elle est faite ; le taux de preuve ne porte que sur les mesures dont la preuve est suivie
     * (null s'il n'y en a aucune).
     *
     * @param list<array<string, mixed>> $measures code, id, applicable (bool), implementation_status,
     *     evidence (EVIDENCE_* ou null), et tout champ d'affichage, repris dans les écarts
     * @return array{applicable: int, implementation_rate: float|null, evidence_rate: float|null,
     *     score: float|null, done: int, proven: int, compliant: int, gaps: list<array<string, mixed>>}
     */
    public static function assessMeasures(array $measures): array
    {
        $byStatus = [];
        $applicable = 0;
        $tracked = 0;
        $done = 0;
        $proven = 0;
        $compliant = 0;
        $gaps = [];
        foreach ($measures as $measure) {
            if ($measure['applicable'] !== true) {
                continue;
            }
            $applicable++;
            $status = (string) $measure['implementation_status'];
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $isDone = in_array($status, self::DONE, true);
            $isTracked = $measure['evidence'] !== null;
            $isProven = $measure['evidence'] === self::EVIDENCE_PROVEN;
            $tracked += $isTracked ? 1 : 0;
            $done += $isDone ? 1 : 0;
            $proven += $isProven ? 1 : 0;
            if ($isDone && ($isProven || !$isTracked)) {
                $compliant++;
                continue;
            }
            unset($measure['applicable']);
            $gaps[] = $measure;
        }

        return [
            'applicable'          => $applicable,
            'implementation_rate' => MaturityScore::implementationRate($byStatus),
            'evidence_rate'       => MaturityScore::rate($proven, $tracked),
            'score'               => MaturityScore::rate($compliant, $applicable),
            'done'                => $done,
            'proven'              => $proven,
            'compliant'           => $compliant,
            'gaps'                => $gaps,
        ];
    }

    /**
     * Issue #789 : état d'une mesure propre d'un référentiel intégré, déduit des mesures de l'Annexe A
     * que son catalogue lui relie (correspondance). Applicable si au moins une mesure reliée l'est ;
     * mise en œuvre si toutes les mesures reliées applicables le sont, en cours si une partie l'est
     * ou est en cours ; prouvée si toutes sont prouvées, expirée si l'une a une preuve expirée.
     * Sans mesure reliée, rien ne permet de l'évaluer : elle reste un écart (mapped = false).
     *
     * @param list<array{applicability: string, implementation_status: string, evidence: string}> $controls
     * @return array{applicable: bool, implementation_status: string, evidence: string, mapped: bool}
     */
    public static function fromMappedControls(array $controls): array
    {
        $applicable = array_values(array_filter(
            $controls,
            static fn (array $control): bool => $control['applicability'] !== 'no'
        ));
        if ($controls !== [] && $applicable === []) {
            return [
                'applicable' => false, 'implementation_status' => 'not_started',
                'evidence' => self::EVIDENCE_NONE, 'mapped' => true,
            ];
        }
        if ($applicable === []) {
            return [
                'applicable' => true, 'implementation_status' => 'not_started',
                'evidence' => self::EVIDENCE_NONE, 'mapped' => false,
            ];
        }
        $done = 0;
        $started = 0;
        $proven = 0;
        $expired = false;
        foreach ($applicable as $control) {
            $isDone = in_array($control['implementation_status'], self::DONE, true);
            $done += $isDone ? 1 : 0;
            $started += $isDone || $control['implementation_status'] === 'in_progress' ? 1 : 0;
            $proven += $control['evidence'] === self::EVIDENCE_PROVEN ? 1 : 0;
            $expired = $expired || $control['evidence'] === self::EVIDENCE_EXPIRED;
        }
        $count = count($applicable);

        return [
            'applicable'            => true,
            'implementation_status' => $done === $count
                ? 'implemented' : ($started > 0 ? 'in_progress' : 'not_started'),
            'evidence'              => $proven === $count
                ? self::EVIDENCE_PROVEN : ($expired ? self::EVIDENCE_EXPIRED : self::EVIDENCE_NONE),
            'mapped'                => true,
        ];
    }

    /**
     * Issue #789 : statut d'auto-évaluation d'une exigence de référentiel personnalisé
     * (CustomRequirement::STATUS_*) ramené au vocabulaire des mesures : conforme = mise en œuvre,
     * partiellement conforme = en cours (compte pour moitié), non évaluée ou non conforme = non
     * commencée ; non applicable sort du calcul.
     *
     * @return array{applicable: bool, implementation_status: string}
     */
    public static function fromCustomStatus(string $status): array
    {
        return match ($status) {
            'compliant'      => ['applicable' => true, 'implementation_status' => 'implemented'],
            'partial'        => ['applicable' => true, 'implementation_status' => 'in_progress'],
            'not_applicable' => ['applicable' => false, 'implementation_status' => 'not_started'],
            default          => ['applicable' => true, 'implementation_status' => 'not_started'],
        };
    }
}
