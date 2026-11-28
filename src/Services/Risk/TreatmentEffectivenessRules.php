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

namespace GlpiPlugin\Grcmanager\Services\Risk;

use DateTimeImmutable;

/**
 * Issue #1182 : efficacité du traitement d'un risque (ISO/IEC 27001, clauses 6.1.3, 8.3 et 9.1).
 *
 * - risque cible : niveau que le traitement doit atteindre, choisi sur le risque ;
 * - efficacité : une fois toutes les actions du plan terminées, le risque résiduel atteint-il la
 *   cible ? Sinon, alerte (plan terminé sans effet suffisant, ou risque résiduel pas réévalué) ;
 * - acceptation : jamais signée par le propriétaire du risque (séparation des tâches), valable
 *   jusqu'à une date d'expiration, puis à renouveler.
 *
 * PHP pur, sans GLPI : les appelants (Risk, RiskEffectiveness) lisent la base et la session.
 */
final class TreatmentEffectivenessRules
{
    public const NO_TARGET    = 'no_target';
    public const NO_PLAN      = 'no_plan';
    public const PLAN_OPEN    = 'plan_open';
    public const REACHED      = 'reached';
    public const NOT_REACHED  = 'not_reached';
    public const NOT_ASSESSED = 'not_assessed';

    /** États qui appellent une action : plan terminé sans atteindre la cible, ou sans réévaluation. */
    public const ALERT_STATES = [self::NOT_REACHED, self::NOT_ASSESSED];

    public const ACCEPTANCE_NONE       = 'none';
    public const ACCEPTANCE_VALID      = 'valid';
    public const ACCEPTANCE_EXPIRING   = 'expiring';
    public const ACCEPTANCE_EXPIRED    = 'expired';
    public const ACCEPTANCE_NO_EXPIRY  = 'no_expiry';

    /** Une acceptation est signalée « à renouveler » dans les 30 jours qui précèdent son expiration. */
    public const EXPIRY_WARNING_DAYS = 30;

    /** Durée de validité proposée pour une acceptation, en mois. */
    public const DEFAULT_VALIDITY_MONTHS = 12;

    public static function isTargetLevel(?string $level): bool
    {
        return RiskAppetiteRules::isLevel($level);
    }

    /**
     * Niveau cible enregistré : un niveau connu, sinon vide (« non défini »).
     */
    public static function normalizeTarget(mixed $level): string
    {
        $level = is_string($level) ? $level : '';

        return self::isTargetLevel($level) ? $level : '';
    }

    /**
     * Avancement du plan de traitement à partir des statuts de ses actions.
     *
     * @param list<string> $statuses
     *
     * @return array{total: int, done: int, completed: bool}
     */
    public static function planProgress(array $statuses): array
    {
        $done = count(array_filter(
            $statuses,
            static fn(string $status): bool => $status === TreatmentPlanRules::STATUS_DONE
        ));

        return [
            'total'     => count($statuses),
            'done'      => $done,
            'completed' => $statuses !== [] && $done === count($statuses),
        ];
    }

    /**
     * Efficacité du traitement d'un risque.
     *
     * @param string $target    niveau cible, vide s'il n'est pas défini
     * @param string $residual  niveau résiduel, vide tant qu'il n'est pas évalué
     * @param string $treatment traitement choisi (seuls réduire et transférer ont un plan)
     * @param int    $total     nombre d'actions du plan
     * @param int    $done      nombre d'actions terminées
     */
    public static function state(string $target, string $residual, string $treatment, int $total, int $done): string
    {
        if (!self::isTargetLevel($target)) {
            return self::NO_TARGET;
        }
        if (!TreatmentPlanRules::isTreatmentPlanRelevant($treatment) || $total <= 0) {
            return self::NO_PLAN;
        }
        if ($done < $total) {
            return self::PLAN_OPEN;
        }
        if (!RiskAppetiteRules::isLevel($residual)) {
            return self::NOT_ASSESSED;
        }

        return RiskAppetiteRules::rank($residual) <= RiskAppetiteRules::rank($target)
            ? self::REACHED
            : self::NOT_REACHED;
    }

    public static function isAlert(string $state): bool
    {
        return in_array($state, self::ALERT_STATES, true);
    }

    /**
     * Séparation des tâches : l'acceptation est signée par une personne identifiée qui n'est pas le
     * propriétaire du risque. Un risque sans propriétaire peut être accepté par toute personne
     * habilitée.
     */
    public static function isSegregationRespected(int $signer, int $owner): bool
    {
        return $signer > 0 && $signer !== $owner;
    }

    /**
     * Date d'expiration saisie : une date valide (AAAA-MM-JJ) postérieure à aujourd'hui, sinon null.
     */
    public static function normalizeExpiry(mixed $value, string $today): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $value <= $today) {
            return null;
        }

        return $value;
    }

    /** Expiration proposée : aujourd'hui plus DEFAULT_VALIDITY_MONTHS mois. */
    public static function defaultExpiry(string $today): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $today) ?: new DateTimeImmutable('today');

        return $date->modify('+' . self::DEFAULT_VALIDITY_MONTHS . ' months')->format('Y-m-d');
    }

    /**
     * État d'une acceptation signée : sans signature, sans date d'expiration (signée avant
     * l'issue #1182), valable, à renouveler bientôt ou expirée.
     */
    public static function acceptanceState(bool $signed, ?string $expiry, string $today): string
    {
        if (!$signed) {
            return self::ACCEPTANCE_NONE;
        }
        if ($expiry === null || $expiry === '') {
            return self::ACCEPTANCE_NO_EXPIRY;
        }
        $expiry = substr($expiry, 0, 10);
        if ($expiry < $today) {
            return self::ACCEPTANCE_EXPIRED;
        }
        $warning = (DateTimeImmutable::createFromFormat('!Y-m-d', $today) ?: new DateTimeImmutable('today'))
            ->modify('+' . self::EXPIRY_WARNING_DAYS . ' days')->format('Y-m-d');

        return $expiry <= $warning ? self::ACCEPTANCE_EXPIRING : self::ACCEPTANCE_VALID;
    }

    /** Une acceptation expirée ou proche de l'expiration est à renouveler. */
    public static function needsRenewal(string $acceptanceState): bool
    {
        return in_array($acceptanceState, [self::ACCEPTANCE_EXPIRED, self::ACCEPTANCE_EXPIRING], true);
    }
}
