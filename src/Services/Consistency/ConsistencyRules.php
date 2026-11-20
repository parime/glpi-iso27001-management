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

namespace GlpiPlugin\Grcmanager\Services\Consistency;

/**
 * Issue #614 : règles de cohérence du SMSI avant un audit (risque sans propriétaire, mesure mise en
 * œuvre sans preuve valide ni test, preuve expirée, politique non revue...). Logique pure, sans
 * base ni session : catalogue des règles (domaine, clause ISO 27001, gravité, seuil), prédicats de
 * chaque règle, réglages (règle active, seuil), score de cohérence et tendance.
 *
 * Rien n'est jamais modifié : chaque écart renvoie à la fiche à corriger.
 */
final class ConsistencyRules
{
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    /** Poids d'une règle dans le score, selon sa gravité. */
    public const SEVERITY_WEIGHTS = [self::SEVERITY_HIGH => 3, self::SEVERITY_MEDIUM => 2, self::SEVERITY_LOW => 1];

    /** Domaines du SMSI, dans l'ordre d'affichage, avec les clauses ISO 27001 qu'ils couvrent. */
    public const AREAS = [
        'policies'          => '5.2, 7.5',
        'risk_assessment'   => '6.1.2, 8.2',
        'risk_treatment'    => '6.1.3, 8.3',
        'evidence'          => '7.5, 9.1',
        'audit'             => '9.2',
        'management_review' => '9.3',
        'improvement'       => '10.2',
        'operations'        => 'Annex A',
    ];

    public const UNIT_DAYS = 'days';
    public const UNIT_MONTHS = 'months';
    public const UNIT_CHARS = 'chars';
    public const UNIT_LEVEL = 'level';

    /** Niveaux de risque proposés comme seuil (du plus faible au plus élevé). */
    public const LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * Règles, dans l'ordre d'affichage : domaine, clause, gravité et seuil réglable (unité, défaut,
     * bornes). Une règle sans seuil n'a rien à régler, hormis son activation.
     *
     * @var array<string, array{area: string, clause: string, severity: string,
     *     threshold: ?array{unit: string, default: int|string, min?: int, max?: int}}>
     */
    public const RULES = [
        'policy_review_overdue'                => [
            'area' => 'policies', 'clause' => '5.2', 'severity' => self::SEVERITY_MEDIUM,
            'threshold' => ['unit' => self::UNIT_DAYS, 'default' => 0, 'min' => 0, 'max' => 365],
        ],
        'policy_without_owner'                 => [
            'area' => 'policies', 'clause' => '5.3', 'severity' => self::SEVERITY_LOW, 'threshold' => null,
        ],
        'risk_without_owner'                   => [
            'area' => 'risk_assessment', 'clause' => '6.1.2 c)', 'severity' => self::SEVERITY_HIGH, 'threshold' => null,
        ],
        'risk_review_overdue'                  => [
            'area' => 'risk_assessment', 'clause' => '8.2', 'severity' => self::SEVERITY_MEDIUM,
            'threshold' => ['unit' => self::UNIT_DAYS, 'default' => 0, 'min' => 0, 'max' => 365],
        ],
        'risk_without_decision'                => [
            'area' => 'risk_assessment', 'clause' => '6.1.2 e)', 'severity' => self::SEVERITY_HIGH,
            'threshold' => ['unit' => self::UNIT_LEVEL, 'default' => 'high'],
        ],
        'risk_accepted_without_approval'       => [
            'area' => 'risk_treatment', 'clause' => '6.1.3 f)', 'severity' => self::SEVERITY_HIGH,
            'threshold' => ['unit' => self::UNIT_LEVEL, 'default' => 'high'],
        ],
        'control_exclusion_weak_justification' => [
            'area' => 'risk_treatment', 'clause' => '6.1.3 d)', 'severity' => self::SEVERITY_HIGH,
            'threshold' => ['unit' => self::UNIT_CHARS, 'default' => 20, 'min' => 1, 'max' => 500],
        ],
        'control_applicable_without_risk'      => [
            'area' => 'risk_treatment', 'clause' => '6.1.3 b)', 'severity' => self::SEVERITY_LOW, 'threshold' => null,
        ],
        'soa_not_approved'                     => [
            'area' => 'risk_treatment', 'clause' => '6.1.3 f)', 'severity' => self::SEVERITY_MEDIUM,
            'threshold' => null,
        ],
        'treatment_action_overdue'             => [
            'area' => 'risk_treatment', 'clause' => '8.3', 'severity' => self::SEVERITY_MEDIUM,
            'threshold' => ['unit' => self::UNIT_DAYS, 'default' => 0, 'min' => 0, 'max' => 365],
        ],
        'waiver_expired'                       => [
            'area' => 'risk_treatment', 'clause' => '6.1.3', 'severity' => self::SEVERITY_MEDIUM, 'threshold' => null,
        ],
        'control_without_evidence'             => [
            'area' => 'evidence', 'clause' => '9.1', 'severity' => self::SEVERITY_HIGH,
            'threshold' => ['unit' => self::UNIT_MONTHS, 'default' => 12, 'min' => 1, 'max' => 60],
        ],
        'evidence_expired'                     => [
            'area' => 'evidence', 'clause' => '7.5.3', 'severity' => self::SEVERITY_MEDIUM, 'threshold' => null,
        ],
        'no_recent_internal_audit'             => [
            'area' => 'audit', 'clause' => '9.2', 'severity' => self::SEVERITY_HIGH,
            'threshold' => ['unit' => self::UNIT_MONTHS, 'default' => 12, 'min' => 1, 'max' => 60],
        ],
        'no_recent_management_review'          => [
            'area' => 'management_review', 'clause' => '9.3', 'severity' => self::SEVERITY_HIGH,
            'threshold' => ['unit' => self::UNIT_MONTHS, 'default' => 12, 'min' => 1, 'max' => 60],
        ],
        'nonconformity_closed_without_action'  => [
            'area' => 'improvement', 'clause' => '10.2', 'severity' => self::SEVERITY_HIGH, 'threshold' => null,
        ],
        'nonconformity_overdue'                => [
            'area' => 'improvement', 'clause' => '10.2', 'severity' => self::SEVERITY_MEDIUM,
            'threshold' => ['unit' => self::UNIT_DAYS, 'default' => 0, 'min' => 0, 'max' => 365],
        ],
        'supplier_risk_review_overdue'         => [
            'area' => 'operations', 'clause' => 'A.5.22', 'severity' => self::SEVERITY_MEDIUM,
            'threshold' => ['unit' => self::UNIT_DAYS, 'default' => 0, 'min' => 0, 'max' => 365],
        ],
        'business_process_without_rto'         => [
            'area' => 'operations', 'clause' => 'A.5.30', 'severity' => self::SEVERITY_MEDIUM, 'threshold' => null,
        ],
        'processing_dpia_pending'              => [
            'area' => 'operations', 'clause' => 'A.5.34', 'severity' => self::SEVERITY_HIGH, 'threshold' => null,
        ],
        'obligation_review_overdue'            => [
            'area' => 'operations', 'clause' => 'A.5.31', 'severity' => self::SEVERITY_LOW,
            'threshold' => ['unit' => self::UNIT_DAYS, 'default' => 0, 'min' => 0, 'max' => 365],
        ],
        'access_review_overdue'                => [
            'area' => 'operations', 'clause' => 'A.5.18', 'severity' => self::SEVERITY_MEDIUM,
            'threshold' => ['unit' => self::UNIT_DAYS, 'default' => 0, 'min' => 0, 'max' => 365],
        ],
        // Issue #704 : élément du parc plus supporté (endoflife.date), non évaluable sans la source.
        'asset_end_of_life'                    => [
            'area' => 'operations', 'clause' => 'A.8.8', 'severity' => self::SEVERITY_HIGH, 'threshold' => null,
        ],
        // Issue #779 : logiciel sans licence, surdéploiement ou licence expirée encore utilisée.
        'software_license_gap'                 => [
            'area' => 'operations', 'clause' => 'A.5.32', 'severity' => self::SEVERITY_MEDIUM, 'threshold' => null,
        ],
    ];

    /** Statuts de non-conformité close (voir Nonconformity::CLOSED_STATUSES). */
    private const NC_CLOSED = ['closed', 'verified'];

    // ------------------------------------------------------------------ Réglages.

    /**
     * Réglages complets et bornés : toutes les règles, actives par défaut, seuil par défaut quand la
     * valeur enregistrée est absente ou invalide.
     *
     * @param array<mixed> $stored réglages enregistrés (règle => [enabled, threshold])
     * @return array<string, array{enabled: bool, threshold: int|string|null}>
     */
    public static function settings(array $stored): array
    {
        $settings = [];
        foreach (self::RULES as $key => $rule) {
            $entry = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $settings[$key] = [
                'enabled'   => !array_key_exists('enabled', $entry) || (bool) $entry['enabled'],
                'threshold' => self::threshold($key, $entry['threshold'] ?? null),
            ];
        }

        return $settings;
    }

    /**
     * Réglages soumis par le formulaire de configuration : une case décochée désactive la règle.
     *
     * @param array<mixed> $posted règle => [enabled => '1', threshold => valeur]
     * @return array<string, array{enabled: bool, threshold: int|string|null}>
     */
    public static function fromForm(array $posted): array
    {
        $stored = [];
        foreach (array_keys(self::RULES) as $key) {
            $entry = is_array($posted[$key] ?? null) ? $posted[$key] : [];
            $stored[$key] = ['enabled' => !empty($entry['enabled']), 'threshold' => $entry['threshold'] ?? null];
        }

        return self::settings($stored);
    }

    /**
     * Seuil d'une règle : niveau connu, ou entier dans ses bornes ; sinon la valeur par défaut.
     */
    public static function threshold(string $key, mixed $value): int|string|null
    {
        $spec = self::RULES[$key]['threshold'] ?? null;
        if ($spec === null) {
            return null;
        }
        if ($spec['unit'] === self::UNIT_LEVEL) {
            return is_string($value) && in_array($value, ['medium', 'high', 'critical'], true)
                ? $value
                : $spec['default'];
        }
        if (!is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            return $spec['default'];
        }

        return max((int) $spec['min'], min((int) $spec['max'], (int) $value));
    }

    // ------------------------------------------------------------------ Outils de date et de texte.

    /** Date décalée de $days jours (négatif : dans le passé), au format AAAA-MM-JJ. */
    public static function shiftDays(string $today, int $days): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%s %+d days', $today, $days)));
    }

    /** Date décalée de $months mois dans le passé, au format AAAA-MM-JJ. */
    public static function monthsAgo(string $today, int $months): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%s -%d months', $today, $months)));
    }

    /**
     * Une échéance dépassée de plus de $graceDays jours (date incluse : une échéance « aujourd'hui »
     * n'est pas dépassée). Sans date : jamais en retard.
     */
    public static function isPastDue(mixed $date, string $today, int $graceDays = 0): bool
    {
        $date = self::date($date);

        return $date !== null && $date < self::shiftDays($today, -$graceDays);
    }

    /**
     * Revue à faire : 'missing' sans date de revue (si cela compte), 'overdue' si elle est dépassée,
     * null sinon.
     */
    public static function reviewState(mixed $date, string $today, int $graceDays, bool $missingIsGap): ?string
    {
        if (self::date($date) === null) {
            return $missingIsGap ? 'missing' : null;
        }

        return self::isPastDue($date, $today, $graceDays) ? 'overdue' : null;
    }

    /** Texte saisi (HTML retiré, espaces compris) plus court que $minChars caractères. */
    public static function isWeakText(mixed $text, int $minChars): bool
    {
        $plain = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $plain));

        return mb_strlen($plain) < $minChars;
    }

    /** Rang d'un niveau de risque (-1 si inconnu). */
    public static function levelRank(mixed $level): int
    {
        $rank = array_search($level, self::LEVELS, true);

        return is_int($rank) ? $rank : -1;
    }

    public static function isLevelAtLeast(mixed $level, string $minimum): bool
    {
        $rank = self::levelRank($level);

        return $rank >= 0 && $rank >= self::levelRank($minimum);
    }

    // ------------------------------------------------------------------ Prédicats des règles.

    /**
     * Risque encore ouvert (identifié, en traitement ou accepté).
     *
     * @param array<string, mixed> $risk
     */
    public static function isOpenRisk(array $risk): bool
    {
        return ($risk['status'] ?? '') !== 'closed';
    }

    /**
     * @param array<string, mixed> $risk
     */
    public static function riskWithoutOwner(array $risk): bool
    {
        return self::isOpenRisk($risk) && (int) ($risk['users_id'] ?? 0) <= 0;
    }

    /**
     * Risque ouvert d'un niveau au moins égal au seuil, sans décision de traitement.
     * @param array<string, mixed> $risk
     */
    public static function riskWithoutDecision(array $risk, string $minimum): bool
    {
        return self::isOpenRisk($risk)
            && trim((string) ($risk['treatment'] ?? '')) === ''
            && self::isLevelAtLeast($risk['risk_level'] ?? '', $minimum);
    }

    /**
     * Niveau retenu pour l'acceptation : le risque résiduel s'il est évalué, sinon le risque initial
     * (ISO 27005 : c'est le risque résiduel qui est accepté).
     * @param array<string, mixed> $risk
     */
    public static function retainedLevel(array $risk): string
    {
        $residual = (string) ($risk['residual_level'] ?? '');

        return self::levelRank($residual) >= 0 ? $residual : (string) ($risk['risk_level'] ?? '');
    }

    /**
     * Risque accepté, d'un niveau retenu au moins égal au seuil, sans acceptation formelle signée.
     * @param array<string, mixed> $risk
     */
    public static function riskAcceptedWithoutApproval(array $risk, string $minimum): bool
    {
        return self::isOpenRisk($risk)
            && ($risk['treatment'] ?? '') === 'accept'
            && (int) ($risk['users_id_acceptance'] ?? 0) <= 0
            && self::isLevelAtLeast(self::retainedLevel($risk), $minimum);
    }

    /**
     * Mesure exclue ou partiellement applicable dont la justification est absente ou trop courte.
     *
     * @param array<string, mixed> $control
     */
    public static function exclusionWeaklyJustified(array $control, int $minChars): bool
    {
        return in_array($control['applicability'] ?? '', ['no', 'partial'], true)
            && self::isWeakText($control['justification'] ?? '', $minChars);
    }

    /**
     * Mesure applicable (en tout ou partie).
     *
     * @param array<string, mixed> $control
     */
    public static function isApplicable(array $control): bool
    {
        return in_array($control['applicability'] ?? '', ['yes', 'partial'], true);
    }

    /**
     * Mesure applicable, déjà revue dans la SoA, qu'aucun risque ne justifie (lien mesure-risque).
     * @param array<string, mixed> $control
     */
    public static function applicableWithoutRisk(array $control, int $linkedRisks): bool
    {
        return self::isApplicable($control) && (int) ($control['is_reviewed'] ?? 0) === 1 && $linkedRisks === 0;
    }

    /**
     * Mesure déclarée mise en œuvre ou vérifiée.
     *
     * @param array<string, mixed> $control
     */
    public static function isImplemented(array $control): bool
    {
        return self::isApplicable($control)
            && in_array($control['implementation_status'] ?? '', ['implemented', 'verified'], true);
    }

    /**
     * Mesure mise en œuvre sans preuve valide (document non expiré) ni test depuis $months mois.
     *
     * @param array<string, mixed> $control
     */
    public static function implementedWithoutEvidence(
        array $control,
        int $validDocuments,
        ?string $lastTestDate,
        string $today,
        int $months
    ): bool {
        if (!self::isImplemented($control) || $validDocuments > 0) {
            return false;
        }
        $last = self::date($lastTestDate);

        return $last === null || $last < self::monthsAgo($today, $months);
    }

    /** Date de validité passée (le jour même est encore valide). */
    public static function isExpired(mixed $validUntil, string $today): bool
    {
        return self::isPastDue($validUntil, $today);
    }

    /**
     * Aucun événement (audit interne terminé, revue de direction tenue) depuis $months mois.
     */
    public static function noRecentEvent(?string $lastDate, string $today, int $months): bool
    {
        $last = self::date($lastDate);

        return $last === null || $last < self::monthsAgo($today, $months);
    }

    /**
     * Non-conformité close ou vérifiée sans action corrective décrite.
     *
     * @param array<string, mixed> $finding
     */
    public static function closedWithoutAction(array $finding): bool
    {
        return ($finding['finding_type'] ?? 'nonconformity') === 'nonconformity'
            && in_array($finding['status'] ?? '', self::NC_CLOSED, true)
            && self::isWeakText($finding['corrective_action'] ?? '', 1);
    }

    /**
     * Non-conformité encore ouverte dont l'échéance est dépassée.
     *
     * @param array<string, mixed> $finding
     */
    public static function nonconformityOverdue(array $finding, string $today, int $graceDays): bool
    {
        return !in_array($finding['status'] ?? '', self::NC_CLOSED, true)
            && self::isPastDue($finding['due_date'] ?? null, $today, $graceDays);
    }

    /**
     * Action de traitement non terminée dont l'échéance est dépassée.
     *
     * @param array<string, mixed> $action
     */
    public static function treatmentActionOverdue(array $action, string $today, int $graceDays): bool
    {
        return ($action['status'] ?? '') !== 'done'
            && self::isPastDue($action['due_date'] ?? null, $today, $graceDays);
    }

    /**
     * Dérogation encore « validée » alors que sa date de fin est passée.
     *
     * @param array<string, mixed> $waiver
     */
    public static function waiverExpired(array $waiver, string $today): bool
    {
        return ($waiver['status'] ?? '') === 'approved' && self::isPastDue($waiver['date_end'] ?? null, $today);
    }

    /**
     * Processus métier sans RTO (0 : non défini).
     *
     * @param array<string, mixed> $process
     */
    public static function processWithoutRto(array $process): bool
    {
        return (int) ($process['rto_hours'] ?? 0) <= 0;
    }

    /**
     * Traitement en cours (pas terminé) dont l'analyse d'impact est requise mais pas faite.
     *
     * @param array<string, mixed> $activity
     */
    public static function dpiaPending(array $activity): bool
    {
        return ($activity['status'] ?? '') !== 'archived' && ($activity['dpia_status'] ?? '') === 'required';
    }

    /**
     * Campagne de revue des accès non close dont l'échéance est dépassée.
     *
     * @param array<string, mixed> $campaign
     */
    public static function accessReviewOverdue(array $campaign, string $today, int $graceDays): bool
    {
        return ($campaign['status'] ?? '') !== 'closed'
            && self::isPastDue($campaign['due_date'] ?? null, $today, $graceDays);
    }

    // ------------------------------------------------------------------ Score et synthèse.

    /**
     * Score de cohérence sur 100 : part pondérée (par gravité) des éléments examinés sans écart.
     * Null si aucune règle n'a rien examiné.
     *
     * @param array<string, array{severity: string, total: int, gaps: int}> $results
     */
    public static function score(array $results): ?int
    {
        $weighted = 0.0;
        $weightedGaps = 0.0;
        foreach ($results as $result) {
            $weight = self::SEVERITY_WEIGHTS[$result['severity']] ?? 1;
            $total = max($result['total'], $result['gaps']);
            $weighted += $weight * $total;
            $weightedGaps += $weight * min($result['gaps'], $total);
        }
        if ($weighted <= 0) {
            return null;
        }

        return (int) round(100 * (1 - $weightedGaps / $weighted));
    }

    /**
     * Couleur (Tabler) d'un score : rouge sous 60, orange sous 85, vert au-delà.
     */
    public static function scoreColor(?int $score): string
    {
        return match (true) {
            $score === null => 'secondary',
            $score < 60     => 'red',
            $score < 85     => 'orange',
            default         => 'green',
        };
    }

    /**
     * Écarts par gravité.
     *
     * @param array<string, array{severity: string, gaps: int}> $results
     * @return array<string, int>
     */
    public static function countBySeverity(array $results): array
    {
        $counts = array_fill_keys(array_keys(self::SEVERITY_WEIGHTS), 0);
        foreach ($results as $result) {
            $counts[$result['severity']] = ($counts[$result['severity']] ?? 0) + $result['gaps'];
        }

        return $counts;
    }

    /**
     * Évolution par règle depuis l'enregistrement précédent (null : pas d'enregistrement antérieur
     * pour cette règle).
     *
     * @param array<string, int> $current  règle => écarts
     * @param array<string, mixed> $previous règle => écarts
     * @return array<string, ?int>
     */
    public static function deltas(array $current, array $previous): array
    {
        $deltas = [];
        foreach ($current as $key => $count) {
            $deltas[$key] = array_key_exists($key, $previous) && is_numeric($previous[$key])
                ? $count - (int) $previous[$key]
                : null;
        }

        return $deltas;
    }

    /**
     * Filtre d'affichage ou d'export : domaine, gravité et règle (chaîne vide : tout).
     *
     * @param array{area: string, severity: string, rule: string} $filters
     */
    public static function matches(string $key, array $filters): bool
    {
        $rule = self::RULES[$key] ?? null;
        if ($rule === null) {
            return false;
        }

        return ($filters['area'] === '' || $filters['area'] === $rule['area'])
            && ($filters['severity'] === '' || $filters['severity'] === $rule['severity'])
            && ($filters['rule'] === '' || $filters['rule'] === $key);
    }

    /**
     * Filtres lus dans la requête : seules les valeurs connues sont gardées.
     *
     * @param array<mixed> $query
     * @return array{area: string, severity: string, rule: string}
     */
    public static function filters(array $query): array
    {
        $pick = static fn (string $name, array $allowed): string
            => is_string($query[$name] ?? null) && in_array($query[$name], $allowed, true) ? $query[$name] : '';

        return [
            'area'     => $pick('area', array_keys(self::AREAS)),
            'severity' => $pick('severity', array_keys(self::SEVERITY_WEIGHTS)),
            'rule'     => $pick('rule', array_keys(self::RULES)),
        ];
    }

    /**
     * Cellule d'export CSV : un texte commençant par = + - @ (ou tabulation, retour) serait exécuté
     * comme formule par le tableur ; il est préfixé d'une apostrophe.
     */
    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) < 10) {
            return null;
        }
        $date = substr($value, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date !== '0000-00-00' ? $date : null;
    }
}
