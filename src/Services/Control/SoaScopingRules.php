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

/**
 * Issue #873 : cadrage guidé de la Déclaration d'Applicabilité (ISO/IEC 27001:2022, 6.1.3 c et d).
 *
 * Quelques questions sur le contexte de l'organisation (télétravail, développement logiciel,
 * sous-traitance, locaux, cloud, données personnelles...) donnent, pour chacune des 93 mesures de
 * l'Annexe A, une applicabilité proposée et le fondement de la justification proposée. Rien n'est
 * appliqué ici : la proposition est relue et validée par une personne (SoaScoping).
 *
 * PHP pur, sans dépendance à GLPI : les libellés traduits sont dans \GlpiPlugin\Grcmanager\SoaScoping.
 */
final class SoaScopingRules
{
    public const ANSWER_YES = 'yes';
    public const ANSWER_NO = 'no';
    public const ANSWERS = [self::ANSWER_YES, self::ANSWER_NO];

    /** Fondement d'une proposition qui ne dépend d'aucune question : mesure de base. */
    public const BASIS_BASELINE = 'baseline';

    public const STATUS_WAITING = 'waiting';
    public const STATUS_MATCHES = 'matches';
    public const STATUS_DIFFERS = 'differs';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const DECISION_ACCEPTED = 'accepted';
    public const DECISION_REJECTED = 'rejected';

    /**
     * Questions du cadrage, dans l'ordre d'affichage => mesures de l'Annexe A qu'elles concernent.
     * Une réponse « oui » rend ces mesures applicables, « non » propose de les exclure (activité
     * absente). Une mesure ne figurant dans aucune liste est une mesure de base, toujours proposée
     * applicable.
     */
    public const QUESTIONS = [
        'remote_work'            => ['A.6.7'],
        'software_development'   => ['A.8.4', 'A.8.25', 'A.8.27', 'A.8.28', 'A.8.29', 'A.8.31', 'A.8.33'],
        'outsourced_development' => ['A.8.30'],
        'suppliers'              => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22'],
        'cloud_services'         => ['A.5.23'],
        'own_premises'           => ['A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6', 'A.7.8'],
        'onsite_servers'         => ['A.7.11', 'A.7.12'],
        'personal_data'          => ['A.5.34'],
    ];

    /** Catégorie d'exclusion proposée (SoaSelectionRules::EXCLUSION_CATEGORIES). */
    public const EXCLUSION_CATEGORY = 'activity_absent';

    /**
     * Réponse normalisée : 'yes', 'no', ou '' (sans réponse).
     */
    public static function normalizeAnswer(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::ANSWERS, true) ? $value : '';
    }

    /**
     * Questions qui concernent une mesure.
     *
     * @return list<string>
     */
    public static function questionsFor(string $code): array
    {
        $questions = [];
        foreach (self::QUESTIONS as $question => $codes) {
            if (in_array($code, $codes, true)) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * Proposition pour une mesure : applicabilité ('yes' ou 'no') et fondement (la question qui la
     * décide, ou 'baseline'). Null tant qu'une question qui la concerne est sans réponse et qu'aucune
     * réponse « oui » ne la rend déjà applicable.
     *
     * @param array<string, string> $answers question => 'yes' | 'no'
     * @return array{applicability: string, basis: string}|null
     */
    public static function proposal(string $code, array $answers): ?array
    {
        $questions = self::questionsFor($code);
        if ($questions === []) {
            return ['applicability' => 'yes', 'basis' => self::BASIS_BASELINE];
        }
        $unanswered = false;
        foreach ($questions as $question) {
            $answer = self::normalizeAnswer($answers[$question] ?? '');
            if ($answer === self::ANSWER_YES) {
                return ['applicability' => 'yes', 'basis' => $question];
            }
            if ($answer === '') {
                $unanswered = true;
            }
        }

        return $unanswered ? null : ['applicability' => 'no', 'basis' => $questions[0]];
    }

    /**
     * État d'une mesure face à sa proposition :
     * - waiting : une question qui la concerne attend sa réponse ;
     * - accepted / rejected : la dernière décision porte sur cette même proposition ;
     * - matches : la SoA dit déjà la même chose, avec une justification si elle est exclue ;
     * - differs : la SoA dit autre chose, ou l'exclusion n'est pas justifiée.
     *
     * @param array{applicability: string, basis: string}|null $proposal
     * @param array{decision: string, proposed_applicability: string}|null $lastDecision
     */
    public static function status(
        ?array $proposal,
        string $currentApplicability,
        string $currentJustification,
        ?array $lastDecision
    ): string {
        if ($proposal === null) {
            return self::STATUS_WAITING;
        }
        if ($lastDecision !== null && $lastDecision['proposed_applicability'] === $proposal['applicability']) {
            if ($lastDecision['decision'] === self::DECISION_REJECTED) {
                return self::STATUS_REJECTED;
            }
            if ($currentApplicability === $proposal['applicability']) {
                return self::STATUS_ACCEPTED;
            }
        }
        if (
            $currentApplicability === $proposal['applicability']
            && ($proposal['applicability'] === 'yes' || trim($currentJustification) !== '')
        ) {
            return self::STATUS_MATCHES;
        }

        return self::STATUS_DIFFERS;
    }

    /**
     * Ce qu'écrit l'acceptation d'une proposition dans la mesure : l'applicabilité proposée ; la
     * justification proposée seulement si la mesure n'en a pas encore (une justification écrite
     * n'est jamais écrasée) ; la catégorie d'exclusion seulement pour une exclusion qui n'en a pas.
     *
     * @param array<string, mixed> $current champs de la mesure (applicability, justification, exclusion_category)
     * @return array<string, string>
     */
    public static function changes(string $proposedApplicability, string $proposedJustification, array $current): array
    {
        $changes = ['applicability' => $proposedApplicability];
        if (trim((string) ($current['justification'] ?? '')) === '') {
            $changes['justification'] = $proposedJustification;
        }
        if (
            SoaSelectionRules::isExclusion($proposedApplicability)
            && trim((string) ($current['exclusion_category'] ?? '')) === ''
        ) {
            $changes['exclusion_category'] = self::EXCLUSION_CATEGORY;
        }

        return $changes;
    }

    /**
     * Comptes par état, pour les cartes de la page.
     *
     * @param list<string> $statuses
     * @return array<string, int>
     */
    public static function countByStatus(array $statuses): array
    {
        $counts = array_fill_keys([
            self::STATUS_WAITING, self::STATUS_DIFFERS, self::STATUS_MATCHES,
            self::STATUS_ACCEPTED, self::STATUS_REJECTED,
        ], 0);
        foreach ($statuses as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }
}
