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

namespace GlpiPlugin\Grcmanager\Services\Readiness;

use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as Base;

/**
 * Issue #1158 : parcours de mise en conformité NIS2, sur le même moteur que la préparation à la
 * certification ISO 27001 (#619) : mêmes états (fait, partiel, à faire, hors de vos droits), même
 * score, même « prochaine étape » (CertificationReadinessRules).
 *
 * Chaque critère cite l'article de la directive (UE) 2022/2555 auquel il répond (renvoi établi par
 * ce projet, avec ses propres mots ; seul le texte publié au Journal officiel fait foi :
 * https://eur-lex.europa.eu/eli/dir/2022/2555/oj). Les critères ReCyF suivent le référentiel de
 * l'ANSSI déjà relevé dans RecyfCatalogDefaults.
 *
 * Logique pure, sans GLPI.
 */
final class Nis2ReadinessRules
{
    /** Groupes du parcours, dans l'ordre. */
    public const GROUPS = ['scope', 'governance', 'measures', 'reporting', 'follow_up'];

    /**
     * Critères, dans l'ordre du parcours. `articles` : articles de la directive (UE) 2022/2555 ;
     * `iso` : étapes de la préparation ISO 27001 dont l'état est repris ; `recyf` : critère qui
     * n'a de sens qu'avec le ReCyF (NIS2 France) activé.
     *
     * @var array<string, array{group: string, articles: string, iso: list<string>, recyf: bool}>
     */
    public const CRITERIA = [
        'category'       => ['group' => 'scope', 'articles' => '3', 'iso' => [], 'recyf' => true],
        'registration'   => ['group' => 'scope', 'articles' => '3(4)', 'iso' => [], 'recyf' => false],
        'governance'     => [
            'group' => 'governance', 'articles' => '20(1)', 'iso' => ['policy', 'roles'], 'recyf' => false,
        ],
        'training'       => [
            'group' => 'governance', 'articles' => '20(2), 21(2)(g)', 'iso' => ['awareness'], 'recyf' => false,
        ],
        'risk'           => [
            'group' => 'measures', 'articles' => '21(1), 21(2)(a)', 'iso' => ['risk_assessment', 'risk_treatment'],
            'recyf' => false,
        ],
        'continuity'     => ['group' => 'measures', 'articles' => '21(2)(c)', 'iso' => ['exercises'], 'recyf' => false],
        'supply_chain'   => ['group' => 'measures', 'articles' => '21(2)(d)', 'iso' => [], 'recyf' => false],
        'effectiveness'  => [
            'group' => 'measures', 'articles' => '21(2)(f)', 'iso' => ['internal_audit', 'nonconformities'],
            'recyf' => false,
        ],
        'access'         => ['group' => 'measures', 'articles' => '21(2)(i)', 'iso' => [], 'recyf' => false],
        'mfa'            => ['group' => 'measures', 'articles' => '21(2)(j)', 'iso' => [], 'recyf' => false],
        'incidents'      => ['group' => 'reporting', 'articles' => '21(2)(b), 23', 'iso' => [], 'recyf' => false],
        'recyf_coverage' => ['group' => 'follow_up', 'articles' => '21(1)', 'iso' => [], 'recyf' => true],
        'recyf_evidence' => ['group' => 'follow_up', 'articles' => '21(1), 32', 'iso' => [], 'recyf' => true],
        'recurring'      => ['group' => 'follow_up', 'articles' => '21(2)(f), 21(2)(i)', 'iso' => [], 'recyf' => false],
    ];

    /**
     * Revues périodiques (PeriodicReviewCatalog) attendues pour NIS2 : revue des droits et des
     * comptes à privilèges, test de restauration des sauvegardes, test du plan de continuité.
     */
    public const RECURRING_REVIEWS = ['access_rights', 'privileged_accounts', 'backup_restore_test', 'continuity_test'];

    /**
     * Critères retenus : ceux du ReCyF seulement s'il est activé.
     *
     * @return list<string>
     */
    public static function criteria(bool $recyfEnabled): array
    {
        return array_keys(array_filter(
            self::CRITERIA,
            static fn (array $c): bool => $recyfEnabled || !$c['recyf']
        ));
    }

    /**
     * État de plusieurs étapes réunies : fait si toutes le sont, à faire si aucune n'est commencée,
     * partiel sinon ; les étapes hors des droits sont ignorées (toutes hors des droits : hors des
     * droits).
     *
     * @param list<string> $statuses
     */
    public static function combine(array $statuses): string
    {
        $known = array_values(array_filter($statuses, static fn (string $s): bool => $s !== Base::STATUS_LOCKED));
        if ($known === []) {
            return Base::STATUS_LOCKED;
        }
        $unique = array_values(array_unique($known));

        return count($unique) === 1 ? $unique[0] : Base::STATUS_PARTIAL;
    }

    /** Statut d'entité (essentielle ou importante) réglé sur au moins une entité. */
    public static function category(int $entitiesWithCategory): string
    {
        return $entitiesWithCategory > 0 ? Base::STATUS_DONE : Base::STATUS_TODO;
    }

    /**
     * Autorité ou CSIRT inscrit comme contact officiel ; vérifié depuis moins de 12 mois pour être
     * « fait ».
     */
    public static function registration(int $authorityContacts, int $verifiedContacts): string
    {
        return match (true) {
            $verifiedContacts > 0  => Base::STATUS_DONE,
            $authorityContacts > 0 => Base::STATUS_PARTIAL,
            default                => Base::STATUS_TODO,
        };
    }

    /** Fournisseurs suivis et évalués dans l'année. */
    public static function supplyChain(int $supplierRisks, int $recentEvaluations): string
    {
        return match (true) {
            $supplierRisks === 0                 => Base::STATUS_TODO,
            $recentEvaluations >= $supplierRisks => Base::STATUS_DONE,
            default                              => Base::STATUS_PARTIAL,
        };
    }

    /**
     * Revue des accès : une campagne close depuis moins de 12 mois ($recentClosed), ou au moins une
     * campagne lancée ($campaigns).
     */
    public static function access(int $recentClosed, int $campaigns): string
    {
        return match (true) {
            $recentClosed > 0 => Base::STATUS_DONE,
            $campaigns > 0    => Base::STATUS_PARTIAL,
            default           => Base::STATUS_TODO,
        };
    }

    /** Couverture MFA (taux en %, null sans compte importé) et administrateurs sans MFA. */
    public static function mfa(?int $rate, int $adminsWithoutMfa): string
    {
        return match (true) {
            $rate === null                          => Base::STATUS_TODO,
            $rate >= 100 && $adminsWithoutMfa === 0 => Base::STATUS_DONE,
            default                                 => Base::STATUS_PARTIAL,
        };
    }

    /**
     * Notification des incidents (24 h, 72 h, un mois) : aucune échéance NIS2 dépassée, et un CSIRT
     * ou une autorité à prévenir inscrit.
     */
    public static function incidents(int $overdue, int $dueSoon, bool $authorityKnown): string
    {
        return match (true) {
            $overdue > 0                       => Base::STATUS_TODO,
            $dueSoon > 0 || !$authorityKnown   => Base::STATUS_PARTIAL,
            default                            => Base::STATUS_DONE,
        };
    }

    /** Mesures ReCyF applicables mises en œuvre. */
    public static function recyfCoverage(int $applicable, int $done): string
    {
        return match (true) {
            $applicable > 0 && $done >= $applicable => Base::STATUS_DONE,
            $done > 0                               => Base::STATUS_PARTIAL,
            default                                 => Base::STATUS_TODO,
        };
    }

    /** Mesures ReCyF mises en œuvre qui ont au moins un document joint (preuve). */
    public static function recyfEvidence(int $done, int $withoutEvidence): string
    {
        return match (true) {
            $done === 0                => Base::STATUS_TODO,
            $withoutEvidence === 0     => Base::STATUS_DONE,
            $withoutEvidence < $done   => Base::STATUS_PARTIAL,
            default                    => Base::STATUS_TODO,
        };
    }

    /** Revues récurrentes attendues : activées et à jour. */
    public static function recurring(int $expected, int $active, int $overdue): string
    {
        return match (true) {
            $active === 0                          => Base::STATUS_TODO,
            $active >= $expected && $overdue === 0 => Base::STATUS_DONE,
            default                                => Base::STATUS_PARTIAL,
        };
    }
}
