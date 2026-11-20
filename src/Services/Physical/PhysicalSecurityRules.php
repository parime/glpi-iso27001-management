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

namespace GlpiPlugin\Grcmanager\Services\Physical;

/**
 * Issue #778 : règles de l'évaluation de la sécurité physique d'un site (ISO 27001, mesures A.7.1 à
 * A.7.14), sans GLPI pour être testées seules.
 *
 * Une évaluation porte sur un lieu de GLPI (le site) : une ligne par mesure A.7.x avec son état,
 * le constat et les preuves, et les zones du site avec leurs contrôles d'accès. Le site est
 * réévalué au moins une fois par an.
 */
final class PhysicalSecurityRules
{
    /** Mesures de l'Annexe A évaluées sur un site (contrôles physiques). */
    public const CONTROL_CODES = [
        'A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6', 'A.7.7',
        'A.7.8', 'A.7.9', 'A.7.10', 'A.7.11', 'A.7.12', 'A.7.13', 'A.7.14',
    ];

    public const STATE_NOT_ASSESSED = 'not_assessed';
    public const STATE_COMPLIANT = 'compliant';
    public const STATE_PARTIAL = 'partial';
    public const STATE_NONCOMPLIANT = 'noncompliant';
    public const STATE_NOT_APPLICABLE = 'not_applicable';

    /** @var list<string> */
    public const STATES = [
        self::STATE_NOT_ASSESSED, self::STATE_COMPLIANT, self::STATE_PARTIAL, self::STATE_NONCOMPLIANT,
        self::STATE_NOT_APPLICABLE,
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED];

    public const ZONE_PUBLIC = 'public';
    public const ZONE_CONTROLLED = 'controlled';
    public const ZONE_SENSITIVE = 'sensitive';
    public const ZONE_TECHNICAL = 'technical';

    /** @var list<string> */
    public const ZONE_TYPES = [self::ZONE_PUBLIC, self::ZONE_CONTROLLED, self::ZONE_SENSITIVE, self::ZONE_TECHNICAL];

    /** Zones dont l'accès doit être restreint et les consignes affichées. */
    public const RESTRICTED_ZONES = [self::ZONE_SENSITIVE, self::ZONE_TECHNICAL];

    /** @var list<string> moyens de contrôle d'accès d'une zone */
    public const ACCESS_MEANS = [
        'badge', 'key', 'code', 'biometric', 'reception', 'visitor_log', 'escort', 'cctv', 'alarm',
    ];

    /** Délai de réévaluation d'un site. */
    public const REASSESSMENT_MONTHS = 12;

    /** Une réévaluation est signalée « à prévoir » ce nombre de jours avant son échéance. */
    public const DUE_SOON_DAYS = 30;

    /** Intervalle minimal entre deux rappels pour un même site. */
    public const REMINDER_INTERVAL_DAYS = 30;

    public const DUE_NONE = 'none';
    public const DUE_OK = 'ok';
    public const DUE_SOON = 'due_soon';
    public const DUE_OVERDUE = 'overdue';

    public const ZONE_WARNING_NO_ACCESS_CONTROL = 'no_access_control';
    public const ZONE_WARNING_NO_INSTRUCTIONS = 'no_instructions';

    public const MAX_TEXT_LENGTH = 65000;
    public const MAX_NAME_LENGTH = 255;

    public static function isControlCode(string $code): bool
    {
        return in_array($code, self::CONTROL_CODES, true);
    }

    public static function normalizeState(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::STATES, true) ? $value : self::STATE_NOT_ASSESSED;
    }

    public static function normalizeStatus(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::STATUSES, true) ? $value : self::STATUS_IN_PROGRESS;
    }

    public static function normalizeZoneType(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::ZONE_TYPES, true) ? $value : self::ZONE_CONTROLLED;
    }

    /**
     * Moyens d'accès connus, sans doublon, dans l'ordre de la liste.
     *
     * @return list<string>
     */
    public static function normalizeAccessMeans(mixed $values): array
    {
        $values = is_array($values) ? array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $values
        ) : [];

        return array_values(array_intersect(self::ACCESS_MEANS, $values));
    }

    /**
     * @return list<string>
     */
    public static function decodeAccessMeans(?string $json): array
    {
        $decoded = json_decode((string) $json, true);

        return self::normalizeAccessMeans(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param list<string> $means
     */
    public static function encodeAccessMeans(array $means): string
    {
        return (string) json_encode(self::normalizeAccessMeans($means));
    }

    public static function cleanText(mixed $value, int $max = self::MAX_TEXT_LENGTH): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return mb_substr($value, 0, $max);
    }

    /** Date au format AAAA-MM-JJ, ou null si elle n'est pas valide. */
    public static function normalizeDate(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date === false ? null : $date->format('Y-m-d');
    }

    /** Échéance de réévaluation par défaut : 12 mois après l'évaluation. */
    public static function nextAssessmentDate(?string $assessmentDate): ?string
    {
        $date = self::normalizeDate($assessmentDate);
        if ($date === null) {
            return null;
        }

        return (new \DateTimeImmutable($date))
            ->modify('+' . self::REASSESSMENT_MONTHS . ' months')
            ->format('Y-m-d');
    }

    /** État de l'échéance de réévaluation d'un site. */
    public static function dueStatus(?string $nextDate, string $today): string
    {
        $next = self::normalizeDate($nextDate);
        if ($next === null) {
            return self::DUE_NONE;
        }
        if ($next < $today) {
            return self::DUE_OVERDUE;
        }
        $soon = (new \DateTimeImmutable($today))->modify('+' . self::DUE_SOON_DAYS . ' days')->format('Y-m-d');

        return $next <= $soon ? self::DUE_SOON : self::DUE_OK;
    }

    /**
     * Rappel à envoyer : réévaluation à prévoir ou dépassée, et aucun rappel depuis 30 jours.
     */
    public static function shouldRemind(?string $nextDate, ?string $lastReminder, string $today): bool
    {
        if (!in_array(self::dueStatus($nextDate, $today), [self::DUE_SOON, self::DUE_OVERDUE], true)) {
            return false;
        }
        $last = self::normalizeDate($lastReminder);
        if ($last === null) {
            return true;
        }
        $limit = (new \DateTimeImmutable($today))
            ->modify('-' . self::REMINDER_INTERVAL_DAYS . ' days')
            ->format('Y-m-d');

        return $last <= $limit;
    }

    /**
     * Synthèse des états d'une évaluation. Le score compte 1 pour une mesure conforme et 0,5 pour une
     * mesure partiellement conforme, sur les mesures applicables (une mesure non évaluée compte 0).
     *
     * @param list<string> $states
     * @return array{total: int, counts: array<string, int>, applicable: int, assessed: int,
     *     score: int|null, findings: int}
     */
    public static function summary(array $states): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($states as $state) {
            $counts[self::normalizeState($state)]++;
        }
        $applicable = count($states) - $counts[self::STATE_NOT_APPLICABLE];
        $points = $counts[self::STATE_COMPLIANT] + 0.5 * $counts[self::STATE_PARTIAL];
        // Aucune mesure applicable encore évaluée : pas de score (un 0 % laisserait croire à un échec).
        $scored = $applicable > 0 && $applicable > $counts[self::STATE_NOT_ASSESSED];

        return [
            'total'      => count($states),
            'counts'     => $counts,
            'applicable' => $applicable,
            'assessed'   => count($states) - $counts[self::STATE_NOT_ASSESSED],
            'score'      => $scored ? (int) round(100 * $points / $applicable) : null,
            'findings'   => $counts[self::STATE_PARTIAL] + $counts[self::STATE_NONCOMPLIANT],
        ];
    }

    /** Un constat partiel ou non conforme peut devenir une action corrective (CAPA). */
    public static function needsAction(string $state): bool
    {
        return in_array($state, [self::STATE_PARTIAL, self::STATE_NONCOMPLIANT], true);
    }

    /**
     * Valeurs proposées pour la non-conformité ouverte depuis une ligne, null si l'état n'en appelle
     * pas : écart total (majeure) ou partiel (mineure).
     *
     * @return array{finding_type: string, severity: string}|null
     */
    public static function findingPreset(string $state): ?array
    {
        return match ($state) {
            self::STATE_NONCOMPLIANT => ['finding_type' => 'nonconformity', 'severity' => 'major'],
            self::STATE_PARTIAL      => ['finding_type' => 'nonconformity', 'severity' => 'minor'],
            default                  => null,
        };
    }

    /**
     * Points d'attention d'une zone : une zone sensible ou un local technique sans aucun contrôle
     * d'accès, ou sans consignes d'accès affichées.
     *
     * @param list<string> $means
     * @return list<string>
     */
    public static function zoneWarnings(string $type, array $means, bool $instructionsPosted): array
    {
        if (!in_array($type, self::RESTRICTED_ZONES, true)) {
            return [];
        }
        $warnings = [];
        if (self::normalizeAccessMeans($means) === []) {
            $warnings[] = self::ZONE_WARNING_NO_ACCESS_CONTROL;
        }
        if (!$instructionsPosted) {
            $warnings[] = self::ZONE_WARNING_NO_INSTRUCTIONS;
        }

        return $warnings;
    }

    /**
     * Tri des mesures A.7.x dans l'ordre naturel (A.7.2 avant A.7.10).
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public static function sortCodes(array $codes): array
    {
        usort($codes, 'strnatcmp');

        return $codes;
    }
}
