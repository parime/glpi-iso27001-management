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

namespace GlpiPlugin\Grcmanager\Services\Context;

/**
 * Issue #771 : règles du registre des contacts officiels (ISO 27001, mesures A.5.5 et A.5.6), sans
 * GLPI pour être testées seules.
 *
 * Un contact officiel est une partie intéressée du contexte de l'organisation (ContextItem) marquée
 * « contact officiel » : autorité de contrôle, CSIRT, forces de l'ordre, groupe d'intérêt... Ses
 * coordonnées doivent être vérifiées au moins une fois par an.
 */
final class OfficialContactRules
{
    /** Délai au-delà duquel un contact non vérifié est signalé. */
    public const VERIFICATION_MONTHS = 12;

    /** Intervalle minimal entre deux relances d'un même contact. */
    public const REMINDER_INTERVAL_DAYS = 30;

    public const STATUS_OK = 'ok';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_NEVER = 'never';

    public const CONTEXT_INCIDENT = 'incident';
    public const CONTEXT_BREACH = 'breach';

    /** Catégories des contacts relevant de la mesure A.5.5 (relations avec les autorités). */
    public const AUTHORITY_CATEGORIES = [
        'supervisory_authority', 'csirt', 'law_enforcement', 'local_authority', 'sector_regulator',
    ];

    /** Catégories relevant de la mesure A.5.6 (relations avec des groupes d'intérêt). */
    public const INTEREST_GROUP_CATEGORIES = ['interest_group'];

    /** @var list<string> */
    public const CATEGORIES = [
        'supervisory_authority', 'csirt', 'law_enforcement', 'local_authority', 'sector_regulator',
        'interest_group', 'insurer', 'hosting_provider', 'other',
    ];

    /** Mesure de l'Annexe A => catégories de contacts qui en constituent la preuve. */
    public const CONTROL_CATEGORIES = [
        'A.5.5' => self::AUTHORITY_CATEGORIES,
        'A.5.6' => self::INTEREST_GROUP_CATEGORIES,
    ];

    public static function normalizeCategory(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::CATEGORIES, true) ? $value : 'other';
    }

    /**
     * @return list<string>|null catégories prouvant la mesure, null si la mesure n'est pas concernée
     */
    public static function categoriesForControl(string $code): ?array
    {
        return self::CONTROL_CATEGORIES[$code] ?? null;
    }

    /**
     * État de la vérification au jour $today : jamais vérifié, vérifié il y a plus de 12 mois, ou à jour.
     */
    public static function verificationStatus(?string $verifiedDate, string $today): string
    {
        if (!self::isDate($verifiedDate)) {
            return self::STATUS_NEVER;
        }

        return substr((string) $verifiedDate, 0, 10) < self::limitDate($today)
            ? self::STATUS_OVERDUE
            : self::STATUS_OK;
    }

    /**
     * Date à laquelle la prochaine vérification est due (12 mois après la dernière), null sans vérification.
     */
    public static function nextVerificationDate(?string $verifiedDate): ?string
    {
        if (!self::isDate($verifiedDate)) {
            return null;
        }

        return (new \DateTimeImmutable(substr((string) $verifiedDate, 0, 10)))
            ->modify('+' . self::VERIFICATION_MONTHS . ' months')
            ->format('Y-m-d');
    }

    /**
     * Relancer le propriétaire : contact à vérifier, et pas de relance depuis 30 jours.
     */
    public static function shouldRemind(?string $verifiedDate, ?string $lastReminder, string $today): bool
    {
        if (self::verificationStatus($verifiedDate, $today) === self::STATUS_OK) {
            return false;
        }
        if (!self::isDate($lastReminder)) {
            return true;
        }
        $limit = (new \DateTimeImmutable(substr($today, 0, 10)))
            ->modify('-' . self::REMINDER_INTERVAL_DAYS . ' days')
            ->format('Y-m-d');

        return substr((string) $lastReminder, 0, 10) <= $limit;
    }

    /**
     * Le contact est-il à prévenir pour ce type d'événement (incident de sécurité ou violation de données) ?
     *
     * @param array<string, mixed> $row
     */
    public static function concerns(array $row, string $context): bool
    {
        if (empty($row['is_official_contact'])) {
            return false;
        }

        return match ($context) {
            self::CONTEXT_INCIDENT => !empty($row['on_security_incident']),
            self::CONTEXT_BREACH   => !empty($row['on_data_breach']),
            default                => false,
        };
    }

    /**
     * Synthèse de vérification d'une liste de contacts.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return array{total: int, verified: int, to_verify: int}
     */
    public static function summary(iterable $rows, string $today): array
    {
        $summary = ['total' => 0, 'verified' => 0, 'to_verify' => 0];
        foreach ($rows as $row) {
            $summary['total']++;
            $status = self::verificationStatus(
                isset($row['contact_verified_date']) ? (string) $row['contact_verified_date'] : null,
                $today
            );
            $summary[$status === self::STATUS_OK ? 'verified' : 'to_verify']++;
        }

        return $summary;
    }

    private static function limitDate(string $today): string
    {
        return (new \DateTimeImmutable(substr($today, 0, 10)))
            ->modify('-' . self::VERIFICATION_MONTHS . ' months')
            ->format('Y-m-d');
    }

    private static function isDate(?string $value): bool
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 && !str_starts_with($value, '0000');
    }
}
