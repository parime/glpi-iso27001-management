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
     *     score: float|null, done: int, proven: int, compliant: int,
     *     gaps: list<array{code: string, id: int, implementation_status: string, evidence: string}>}
     */
    public static function assess(array $controls, callable $covers): array
    {
        $byStatus = [];
        $applicable = 0;
        $done = 0;
        $proven = 0;
        $compliant = 0;
        $gaps = [];
        foreach ($controls as $code => $row) {
            $code = (string) $code;
            if ($row['applicability'] === 'no' || !$covers($code)) {
                continue;
            }
            $applicable++;
            $status = $row['implementation_status'];
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $isDone = in_array($status, self::DONE, true);
            $isProven = $row['evidence'] === self::EVIDENCE_PROVEN;
            $done += $isDone ? 1 : 0;
            $proven += $isProven ? 1 : 0;
            if ($isDone && $isProven) {
                $compliant++;
                continue;
            }
            $gaps[] = [
                'code'                  => $code,
                'id'                    => (int) ($row['id'] ?? 0),
                'implementation_status' => $status,
                'evidence'              => $row['evidence'],
            ];
        }

        return [
            'applicable'          => $applicable,
            'implementation_rate' => MaturityScore::implementationRate($byStatus),
            'evidence_rate'       => MaturityScore::rate($proven, $applicable),
            'score'               => MaturityScore::rate($compliant, $applicable),
            'done'                => $done,
            'proven'              => $proven,
            'compliant'           => $compliant,
            'gaps'                => $gaps,
        ];
    }
}
