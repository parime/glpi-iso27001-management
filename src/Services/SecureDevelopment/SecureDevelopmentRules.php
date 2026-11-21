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

namespace GlpiPlugin\Grcmanager\Services\SecureDevelopment;

/**
 * Issue #777 : règles du développement sécurisé (ISO/IEC 27001, mesures A.8.25 à A.8.31).
 *
 * - Fiche d'une application : référentiel suivi, revue de code, analyses SAST, DAST et des
 *   dépendances, séparation des environnements (oui ou non) et sa justification.
 * - Validation de mise en production d'un changement : liste de contrôle (tests passés, revue
 *   faite, vulnérabilités traitées), puis décision de la DSI ou du RSSI. Une validation n'est
 *   possible que si toute la liste est cochée ; un refus exige un motif.
 * - État d'une mise en production : validée avant la mise en production, validée après coup,
 *   refusée, en attente ou sans validation.
 * - Lot 2 : détail de la séparation (accès, données, configuration), exigences de sécurité de
 *   l'application (A.8.26), principes d'architecture (A.8.27) et développement externalisé avec
 *   prestataire, clauses de sécurité et suivi (A.8.30).
 *
 * PHP pur (aucune dépendance à GLPI), testé unitairement.
 *
 * @phpstan-type Profile array{framework: string, code_review_required: int, sast: int, dast: int,
 *     dependency_scan: int, environments_separated: string, separation_justification: string}
 */
final class SecureDevelopmentRules
{
    /** Référentiels de développement sécurisé proposés. */
    public const FRAMEWORK_NONE = '';
    public const FRAMEWORK_ASVS = 'owasp_asvs';
    public const FRAMEWORK_TOP10 = 'owasp_top10';
    public const FRAMEWORK_SAMM = 'owasp_samm';
    public const FRAMEWORK_ANSSI = 'anssi';
    public const FRAMEWORK_OTHER = 'other';
    public const FRAMEWORKS = [
        self::FRAMEWORK_ASVS, self::FRAMEWORK_TOP10, self::FRAMEWORK_SAMM, self::FRAMEWORK_ANSSI,
        self::FRAMEWORK_OTHER,
    ];

    /** Séparation des environnements (développement, test, production). */
    public const SEPARATION_UNKNOWN = '';
    public const SEPARATION_YES = 'yes';
    public const SEPARATION_NO = 'no';

    /** Pratiques cochées sur la fiche d'une application. */
    public const PRACTICES = ['code_review_required', 'sast', 'dast', 'dependency_scan'];

    /**
     * Détail d'une séparation affirmée : accès, données et configuration séparés entre les
     * environnements de développement, de test et de production.
     */
    public const SEPARATION_DETAILS = ['separation_access', 'separation_data', 'separation_config'];

    /** Liste de contrôle avant mise en production. */
    public const CHECKLIST = ['tests_passed', 'review_done', 'vulnerabilities_treated'];

    /** Décision de mise en production. */
    public const DECISION_PENDING = '';
    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    /** Qui valide : la DSI ou le RSSI. */
    public const ROLE_CIO = 'cio';
    public const ROLE_CISO = 'ciso';
    public const ROLES = [self::ROLE_CIO, self::ROLE_CISO];

    /** Refus d'une décision. */
    public const REFUSED_CHECKLIST = 'checklist';
    public const REFUSED_ROLE = 'role';
    public const REFUSED_REASON = 'reason';
    public const REFUSED_DECIDED = 'decided';
    public const REFUSED_DECISION = 'decision';

    /** État d'une mise en production. */
    public const RELEASE_VALIDATED = 'validated';
    public const RELEASE_VALIDATED_LATE = 'validated_late';
    public const RELEASE_REJECTED = 'rejected';
    public const RELEASE_PENDING = 'pending';
    public const RELEASE_NONE = 'none';

    public static function sanitizeFramework(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, self::FRAMEWORKS, true) ? $value : self::FRAMEWORK_NONE;
    }

    public static function sanitizeSeparation(mixed $value): string
    {
        return in_array($value, [self::SEPARATION_YES, self::SEPARATION_NO], true)
            ? (string) $value
            : self::SEPARATION_UNKNOWN;
    }

    public static function sanitizeRole(mixed $value): string
    {
        return in_array($value, self::ROLES, true) ? (string) $value : '';
    }

    /** Case cochée d'un formulaire : 1 ou 0, jamais autre chose. */
    public static function flag(mixed $value): int
    {
        return in_array($value, [1, '1', true, 'on'], true) ? 1 : 0;
    }

    /**
     * Adresse du dépôt de code : http(s) ou ssh uniquement (lien cliquable sans risque), '' sinon.
     */
    public static function sanitizeRepository(mixed $value): string
    {
        $value = trim(is_string($value) ? $value : '');
        if ($value === '' || strlen($value) > 255 || preg_match('/[\s<>"\']/', $value) === 1) {
            return '';
        }
        if (preg_match('#^(https?|ssh)://[^/\s]+#i', $value) === 1 || preg_match('#^git@[^:\s]+:\S+$#', $value) === 1) {
            return $value;
        }

        return '';
    }

    /** Lien cliquable : seulement une adresse http(s). */
    public static function isWebLink(string $repository): bool
    {
        return preg_match('#^https?://#i', $repository) === 1;
    }

    /**
     * Une application sans séparation des environnements doit dire pourquoi (risque accepté,
     * mesure compensatoire) ; une séparation affirmée se décrit aussi (accès, données, configuration),
     * mais sans obligation.
     */
    public static function justificationMissing(string $separation, string $justification): bool
    {
        return $separation === self::SEPARATION_NO && trim($justification) === '';
    }

    /**
     * L'application compte parmi « sans séparation des environnements » : non séparée, ou pas encore
     * renseignée.
     */
    public static function withoutSeparation(string $separation): bool
    {
        return $separation !== self::SEPARATION_YES;
    }

    /**
     * Séparation affirmée mais incomplète : un des aspects (accès, données, configuration) n'est
     * pas séparé.
     *
     * @param array<string, mixed> $profile
     * @return list<string> aspects non séparés, vide si la séparation n'est pas affirmée
     */
    public static function separationGaps(array $profile): array
    {
        if ((string) ($profile['environments_separated'] ?? '') !== self::SEPARATION_YES) {
            return [];
        }

        return array_values(array_filter(
            self::SEPARATION_DETAILS,
            static fn (string $detail): bool => (int) ($profile[$detail] ?? 0) !== 1
        ));
    }

    /**
     * Exigences de sécurité de l'application non décrites (A.8.26).
     *
     * @param array<string, mixed> $profile
     */
    public static function withoutRequirements(array $profile): bool
    {
        return trim((string) ($profile['security_requirements'] ?? '')) === '';
    }

    /**
     * Principes d'architecture sécurisée non décrits (A.8.27).
     *
     * @param array<string, mixed> $profile
     */
    public static function withoutArchitecture(array $profile): bool
    {
        return trim((string) ($profile['architecture_principles'] ?? '')) === '';
    }

    /**
     * Développement confié à un prestataire (A.8.30).
     *
     * @param array<string, mixed> $profile
     */
    public static function isOutsourced(array $profile): bool
    {
        return (int) ($profile['outsourced'] ?? 0) === 1;
    }

    /**
     * Développement externalisé sans encadrement : prestataire non désigné ou clauses de sécurité
     * absentes du contrat.
     *
     * @param array<string, mixed> $profile
     */
    public static function outsourcingGap(array $profile): bool
    {
        return self::isOutsourced($profile)
            && ((int) ($profile['suppliers_id'] ?? 0) <= 0 || (int) ($profile['outsourcing_clauses'] ?? 0) !== 1);
    }

    /**
     * Pratiques manquantes d'une fiche (référentiel et analyses), dans l'ordre d'affichage.
     *
     * @param array<string, mixed> $profile
     * @return list<string> 'framework', 'code_review_required', 'sast', 'dast', 'dependency_scan'
     */
    public static function missingPractices(array $profile): array
    {
        $missing = [];
        if (self::sanitizeFramework($profile['framework'] ?? '') === self::FRAMEWORK_NONE) {
            $missing[] = 'framework';
        }
        foreach (self::PRACTICES as $practice) {
            if ((int) ($profile[$practice] ?? 0) !== 1) {
                $missing[] = $practice;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $validation
     * @return list<string> éléments non cochés de la liste de contrôle
     */
    public static function uncheckedItems(array $validation): array
    {
        return array_values(array_filter(
            self::CHECKLIST,
            static fn (string $item): bool => (int) ($validation[$item] ?? 0) !== 1
        ));
    }

    /**
     * Refus d'une décision, ou null si elle est acceptée.
     *
     * @param array<string, mixed> $validation état enregistré (liste de contrôle et décision en cours)
     */
    public static function decisionRefusal(array $validation, string $decision, string $role, string $reason): ?string
    {
        if (!in_array($decision, [self::DECISION_APPROVED, self::DECISION_REJECTED], true)) {
            return self::REFUSED_DECISION;
        }
        if (($validation['decision'] ?? '') !== self::DECISION_PENDING) {
            return self::REFUSED_DECIDED;
        }
        if (self::sanitizeRole($role) === '') {
            return self::REFUSED_ROLE;
        }
        if ($decision === self::DECISION_APPROVED && self::uncheckedItems($validation) !== []) {
            return self::REFUSED_CHECKLIST;
        }
        if ($decision === self::DECISION_REJECTED && trim($reason) === '') {
            return self::REFUSED_REASON;
        }

        return null;
    }

    /**
     * État de la mise en production d'un changement.
     *
     * @param array<string, mixed>|null $validation null : aucune liste de contrôle
     * @param string                    $releasedAt date de mise en production (résolution), '' si inconnue
     */
    public static function releaseState(?array $validation, string $releasedAt): string
    {
        if ($validation === null) {
            return self::RELEASE_NONE;
        }
        $decision = (string) ($validation['decision'] ?? '');
        if ($decision === self::DECISION_REJECTED) {
            return self::RELEASE_REJECTED;
        }
        if ($decision !== self::DECISION_APPROVED) {
            return self::RELEASE_PENDING;
        }
        $decidedAt = (string) ($validation['date_decision'] ?? '');
        if ($releasedAt !== '' && $decidedAt !== '' && $decidedAt > $releasedAt) {
            return self::RELEASE_VALIDATED_LATE;
        }

        return self::RELEASE_VALIDATED;
    }

    /** Mise en production comptée comme « sans validation sécurité » (tout sauf validée à temps). */
    public static function withoutValidation(string $releaseState): bool
    {
        return $releaseState !== self::RELEASE_VALIDATED;
    }

    /**
     * Indicateurs du registre.
     *
     * @param list<array<string, mixed>> $profiles fiches des applications
     * @param list<string>               $releaseStates état de chaque mise en production
     * @return array{applications: int, without_separation: int, separation_unjustified: int,
     *     without_framework: int, without_review: int, without_sast: int, without_dast: int,
     *     without_dependency_scan: int, separation_incomplete: int, without_requirements: int,
     *     without_architecture: int, outsourced: int, outsourced_without_clauses: int, releases: int,
     *     releases_without_validation: int}
     */
    public static function indicators(array $profiles, array $releaseStates): array
    {
        $count = static fn (callable $test): int => count(array_filter($profiles, $test));

        return [
            'applications'            => count($profiles),
            'without_separation'      => $count(static fn (array $p): bool => self::withoutSeparation(
                (string) ($p['environments_separated'] ?? '')
            )),
            'separation_unjustified'  => $count(static fn (array $p): bool => self::justificationMissing(
                (string) ($p['environments_separated'] ?? ''),
                (string) ($p['separation_justification'] ?? '')
            )),
            'without_framework'       => $count(
                static fn (array $p): bool => in_array('framework', self::missingPractices($p), true)
            ),
            'without_review'          => $count(
                static fn (array $p): bool => (int) ($p['code_review_required'] ?? 0) !== 1
            ),
            'without_sast'            => $count(static fn (array $p): bool => (int) ($p['sast'] ?? 0) !== 1),
            'without_dast'            => $count(static fn (array $p): bool => (int) ($p['dast'] ?? 0) !== 1),
            'without_dependency_scan' => $count(static fn (array $p): bool => (int) ($p['dependency_scan'] ?? 0) !== 1),
            'separation_incomplete'   => $count(static fn (array $p): bool => self::separationGaps($p) !== []),
            'without_requirements'    => $count(static fn (array $p): bool => self::withoutRequirements($p)),
            'without_architecture'    => $count(static fn (array $p): bool => self::withoutArchitecture($p)),
            'outsourced'              => $count(static fn (array $p): bool => self::isOutsourced($p)),
            'outsourced_without_clauses' => $count(static fn (array $p): bool => self::outsourcingGap($p)),
            'releases'                => count($releaseStates),
            'releases_without_validation' => count(array_filter(
                $releaseStates,
                static fn (string $state): bool => self::withoutValidation($state)
            )),
        ];
    }
}
