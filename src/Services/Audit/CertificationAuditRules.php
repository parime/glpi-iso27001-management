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
 * Issue #772 : audits de certification. Règles pures (sans GLPI) :
 *
 * - type d'audit : interne (clause 9.2, valeur de tout audit existant) ou externe, mené par
 *   l'organisme de certification (initial, surveillance 1 et 2, renouvellement) ;
 * - vocabulaire des constats de l'organisme (NC majeure, NC mineure, point sensible, piste de
 *   progrès), traduit vers les deux axes existants des constats (`finding_type` et `severity`) :
 *   aucune seconde colonne, le workflow CAPA reste celui des non-conformités ;
 * - état de levée d'un constat, calculé à partir de son statut.
 */
final class CertificationAuditRules
{
    public const TYPE_INTERNAL = 'internal';

    /** Types d'audit, dans l'ordre du cycle de certification de trois ans. */
    public const AUDIT_TYPES = [self::TYPE_INTERNAL, 'initial', 'surveillance_1', 'surveillance_2', 'renewal'];

    public const CATEGORY_MAJOR_NC = 'major_nc';
    public const CATEGORY_MINOR_NC = 'minor_nc';
    public const CATEGORY_SENSITIVE_POINT = 'sensitive_point';
    public const CATEGORY_IMPROVEMENT = 'improvement';

    /** Constats de l'organisme, du plus grave au plus léger. */
    public const CATEGORIES = [
        self::CATEGORY_MAJOR_NC,
        self::CATEGORY_MINOR_NC,
        self::CATEGORY_SENSITIVE_POINT,
        self::CATEGORY_IMPROVEMENT,
    ];

    /** Valeurs de `finding_type` ajoutées par l'issue #772 (CAPA facultatif, comme une observation). */
    public const FINDING_TYPE_SENSITIVE_POINT = 'sensitive_point';
    public const FINDING_TYPE_IMPROVEMENT = 'improvement';

    public const LIFT_NOT_LIFTED = 'not_lifted';
    public const LIFT_IN_PROGRESS = 'in_progress';
    public const LIFT_AWAITING_VERIFICATION = 'awaiting_verification';
    public const LIFT_LIFTED = 'lifted';

    public const LIFT_STATES = [
        self::LIFT_NOT_LIFTED,
        self::LIFT_IN_PROGRESS,
        self::LIFT_AWAITING_VERIFICATION,
        self::LIFT_LIFTED,
    ];

    /** Type connu, sinon « interne » : jamais un audit externe par erreur de saisie. */
    public static function normalizeAuditType(string $type): string
    {
        return in_array($type, self::AUDIT_TYPES, true) ? $type : self::TYPE_INTERNAL;
    }

    public static function isExternal(string $type): bool
    {
        return in_array($type, self::AUDIT_TYPES, true) && $type !== self::TYPE_INTERNAL;
    }

    /**
     * @return list<string> types menés par l'organisme de certification
     */
    public static function externalTypes(): array
    {
        return array_values(array_filter(self::AUDIT_TYPES, [self::class, 'isExternal']));
    }

    /**
     * Constat de l'organisme correspondant aux deux axes d'un constat ; null pour une observation
     * d'audit interne, qui n'a pas d'équivalent dans le vocabulaire de l'organisme.
     */
    public static function categoryOf(string $findingType, string $severity): ?string
    {
        return match ($findingType) {
            self::FINDING_TYPE_SENSITIVE_POINT => self::CATEGORY_SENSITIVE_POINT,
            self::FINDING_TYPE_IMPROVEMENT     => self::CATEGORY_IMPROVEMENT,
            'observation'                      => null,
            // Toute autre valeur est traitée en non-conformité (même règle prudente que
            // CapaRequirementService) ; « critique » n'existe pas chez l'organisme : NC majeure.
            default                            => $severity === 'minor'
                ? self::CATEGORY_MINOR_NC
                : self::CATEGORY_MAJOR_NC,
        };
    }

    /**
     * Axes à enregistrer pour un constat de l'organisme ; null si la valeur est inconnue.
     *
     * @return array{finding_type: string, severity: string}|null
     */
    public static function fieldsFor(string $category): ?array
    {
        return match ($category) {
            self::CATEGORY_MAJOR_NC        => ['finding_type' => 'nonconformity', 'severity' => 'major'],
            self::CATEGORY_MINOR_NC        => ['finding_type' => 'nonconformity', 'severity' => 'minor'],
            self::CATEGORY_SENSITIVE_POINT => [
                'finding_type' => self::FINDING_TYPE_SENSITIVE_POINT, 'severity' => 'minor',
            ],
            self::CATEGORY_IMPROVEMENT     => ['finding_type' => self::FINDING_TYPE_IMPROVEMENT, 'severity' => 'minor'],
            default                        => null,
        };
    }

    /**
     * État de levée d'un constat d'après son statut : ouvert, en cours de traitement, action
     * réalisée (close) en attente de vérification de son efficacité, ou levé (vérifié).
     */
    public static function liftState(string $status): string
    {
        return match ($status) {
            'in_progress' => self::LIFT_IN_PROGRESS,
            'closed'      => self::LIFT_AWAITING_VERIFICATION,
            'verified'    => self::LIFT_LIFTED,
            default       => self::LIFT_NOT_LIFTED,
        };
    }

    /** Un constat compte comme non levé tant que son action n'est pas réalisée (ni close ni vérifiée). */
    public static function isOpen(string $status): bool
    {
        return self::isOpenState(self::liftState($status));
    }

    public static function isOpenState(string $liftState): bool
    {
        return !in_array($liftState, [self::LIFT_AWAITING_VERIFICATION, self::LIFT_LIFTED], true);
    }

    /**
     * Comptages par état de levée et par constat de l'organisme.
     *
     * @param list<array{lift_state: string, category: ?string}> $findings
     * @return array{total: int, open: int, open_nc: int, lift: array<string, int>, categories: array<string, int>}
     */
    public static function summary(array $findings): array
    {
        $lift = array_fill_keys(self::LIFT_STATES, 0);
        $categories = array_fill_keys(self::CATEGORIES, 0);
        $open = 0;
        $openNc = 0;
        foreach ($findings as $finding) {
            $state = in_array($finding['lift_state'], self::LIFT_STATES, true)
                ? $finding['lift_state']
                : self::LIFT_NOT_LIFTED;
            $lift[$state]++;
            if ($finding['category'] !== null && isset($categories[$finding['category']])) {
                $categories[$finding['category']]++;
            }
            if (self::isOpenState($state)) {
                $open++;
                if (in_array($finding['category'], [self::CATEGORY_MAJOR_NC, self::CATEGORY_MINOR_NC], true)) {
                    $openNc++;
                }
            }
        }

        return [
            'total'      => count($findings),
            'open'       => $open,
            'open_nc'    => $openNc,
            'lift'       => $lift,
            'categories' => $categories,
        ];
    }
}
