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

namespace GlpiPlugin\Grcmanager\Services\Privacy;

/**
 * Issue #375 : règles d'une analyse d'impact relative à la protection des données (AIPD, RGPD article
 * 35). Critères de déclenchement des lignes directrices européennes WP248, trois risques de la méthode
 * de la CNIL cotés avant et après mesures. Les résultats restent indicatifs : la décision appartient
 * au responsable de traitement, après l'avis du DPO. Sans base : testable seul.
 */
final class DpiaRules
{
    /** Les neuf critères des lignes directrices WP248. */
    public const CRITERIA = [
        'evaluation_scoring', 'automated_decision', 'systematic_monitoring', 'sensitive_data', 'large_scale',
        'matching_datasets', 'vulnerable_subjects', 'innovative_use', 'blocks_rights',
    ];

    /** À partir de ce nombre de critères, une AIPD est requise en principe. */
    public const CRITERIA_THRESHOLD = 2;

    public const RISKS = ['illegitimate_access', 'unwanted_change', 'disappearance'];

    /** Champs de cotation d'un risque : avant et après les mesures. */
    public const RISK_SCALES = ['severity', 'likelihood', 'residual_severity', 'residual_likelihood'];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';

    /** Échelle 1 à 4 => impact du registre des risques. */
    private const SEVERITY_TO_IMPACT = [1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'critical'];

    /** Échelle 1 à 4 => probabilité du registre des risques. */
    private const LIKELIHOOD_TO_PROBABILITY = [1 => 'rare', 2 => 'possible', 3 => 'probable', 4 => 'certain'];

    /**
     * @param mixed $value Tableau du formulaire ou JSON enregistré.
     * @return list<string> Critères connus, sans doublon, dans l'ordre du catalogue.
     */
    public static function normalizeCriteria(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $selected = is_array($decoded) ? array_map('strval', array_values($decoded)) : [];

        return array_values(array_intersect(self::CRITERIA, $selected));
    }

    public static function isRequired(mixed $criteria): bool
    {
        return count(self::normalizeCriteria($criteria)) >= self::CRITERIA_THRESHOLD;
    }

    /**
     * @param mixed $value Tableau du formulaire ou JSON enregistré.
     * @return array<string, array<string, int>> Risque => échelle => 0 (non coté) à 4.
     */
    public static function normalizeRisks(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $decoded = is_array($decoded) ? $decoded : [];
        $risks = [];
        foreach (self::RISKS as $risk) {
            foreach (self::RISK_SCALES as $scale) {
                $level = $decoded[$risk][$scale] ?? 0;
                $risks[$risk][$scale] = is_numeric($level) ? max(0, min(4, (int) $level)) : 0;
            }
        }

        return $risks;
    }

    /**
     * Niveau d'un risque : high quand gravité et vraisemblance sont toutes deux au moins importantes,
     * medium quand l'une l'est, low sinon, none tant qu'il n'est pas coté.
     */
    public static function level(int $severity, int $likelihood): string
    {
        if ($severity <= 0 || $likelihood <= 0) {
            return 'none';
        }
        if ($severity >= 3 && $likelihood >= 3) {
            return 'high';
        }

        return $severity >= 3 || $likelihood >= 3 ? 'medium' : 'low';
    }

    /**
     * Article 36 : un risque résiduel élevé impose de consulter l'autorité de contrôle avant le traitement.
     */
    public static function needsPriorConsultation(mixed $risks): bool
    {
        foreach (self::normalizeRisks($risks) as $risk) {
            if (self::level($risk['residual_severity'], $risk['residual_likelihood']) === 'high') {
                return true;
            }
        }

        return false;
    }

    /**
     * Risque résiduel le plus fort, pour le registre des risques ; null si aucun n'est coté.
     *
     * @return array{risk: string, impact: string, probability: string}|null
     */
    public static function worstResidualRisk(mixed $risks): ?array
    {
        $worst = null;
        $worstScore = 0;
        foreach (self::normalizeRisks($risks) as $key => $risk) {
            $score = $risk['residual_severity'] * $risk['residual_likelihood'];
            if ($score > $worstScore) {
                $worstScore = $score;
                $worst = [
                    'risk'        => $key,
                    'impact'      => self::SEVERITY_TO_IMPACT[$risk['residual_severity']],
                    'probability' => self::LIKELIHOOD_TO_PROBABILITY[$risk['residual_likelihood']],
                ];
            }
        }

        return $worst;
    }

    /**
     * Ce qui manque pour approuver l'AIPD.
     *
     * @param array<string, mixed> $fields
     * @return list<string> necessity, risks, measures, dpo_opinion
     */
    public static function missingForApproval(array $fields): array
    {
        $missing = [];
        if (trim((string) ($fields['necessity'] ?? '')) === '') {
            $missing[] = 'necessity';
        }
        foreach (self::normalizeRisks($fields['risk_assessment'] ?? '') as $risk) {
            if (in_array(0, $risk, true)) {
                $missing[] = 'risks';
                break;
            }
        }
        foreach (['measures', 'dpo_opinion'] as $field) {
            if (trim((string) ($fields[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * État à proposer pour la fiche du registre, null si elle est déjà cohérente. Jamais appliqué sans
     * l'action de l'utilisateur.
     */
    public static function suggestedRecordStatus(string $recordStatus, mixed $criteria, string $status): ?string
    {
        if ($status === self::STATUS_APPROVED) {
            return $recordStatus === 'done' ? null : 'done';
        }
        if ($recordStatus === 'done') {
            return null;
        }
        if (self::isRequired($criteria)) {
            return $recordStatus === 'required' ? null : 'required';
        }

        return $recordStatus === 'not_assessed' && self::normalizeCriteria($criteria) !== [] ? 'not_required' : null;
    }
}
