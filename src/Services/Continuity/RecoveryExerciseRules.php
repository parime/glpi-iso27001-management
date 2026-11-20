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

namespace GlpiPlugin\Grcmanager\Services\Continuity;

/**
 * Issue #733 : exercices de reprise (ISO 27001 A.5.30, préparation des TIC à la continuité).
 * Logique pure, sans GLPI :
 * - type d'exercice (sur table, fonctionnel, test technique de restauration, grandeur nature), liste
 *   réglable : types par défaut cochés, puis types propres à l'organisation ;
 * - perte de données mesurée (RPO mesuré), comparée à la perte admissible du processus ;
 * - programme de tests : fréquence propre à chaque processus, sinon celle de la revue périodique ;
 * - couverture pluriannuelle : résultat du dernier test de chaque année.
 */
final class RecoveryExerciseRules
{
    public const TYPE_TABLETOP = 'tabletop';
    public const TYPE_FUNCTIONAL = 'functional';
    public const TYPE_TECHNICAL_RESTORE = 'technical_restore';
    public const TYPE_FULL_SCALE = 'full_scale';

    /** Types proposés par défaut, dans l'ordre d'affichage. */
    public const DEFAULT_TYPES = [
        self::TYPE_TABLETOP, self::TYPE_FUNCTIONAL, self::TYPE_TECHNICAL_RESTORE, self::TYPE_FULL_SCALE,
    ];

    /** Longueur de la colonne `exercise_type`. */
    public const MAX_TYPE_LENGTH = 64;
    public const MAX_TYPES = 30;

    /** Plus longue fréquence de test réglable sur un processus, en mois. */
    public const MAX_FREQUENCY_MONTHS = 60;

    /** Années affichées dans la couverture pluriannuelle, année en cours comprise. */
    public const COVERAGE_YEARS = 4;

    /** Résultat d'un test, puis d'une année de la couverture. */
    public const RESULT_PASSED = 'passed';
    public const RESULT_FAILED = 'failed';
    public const RESULT_UNTESTED = 'untested';

    /**
     * Type saisi ou choisi : texte court sans balise ni retour à la ligne.
     */
    public static function sanitizeType(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return mb_substr($clean, 0, self::MAX_TYPE_LENGTH);
    }

    /**
     * Liste des types : sans doublon (casse ignorée), sans entrée vide, au plus MAX_TYPES.
     *
     * @param array<mixed> $types
     * @return list<string>
     */
    public static function sanitizeTypes(array $types): array
    {
        $clean = [];
        foreach ($types as $type) {
            $value = self::sanitizeType($type);
            if ($value === '') {
                continue;
            }
            $clean[mb_strtolower($value)] ??= $value;
            if (count($clean) >= self::MAX_TYPES) {
                break;
            }
        }

        return array_values($clean);
    }

    /**
     * Types d'un formulaire de réglage : types par défaut cochés (dans leur ordre), puis types
     * supplémentaires, un par ligne.
     *
     * @param array<mixed> $checked
     * @return list<string>
     */
    public static function fromForm(array $checked, string $others): array
    {
        $defaults = array_values(array_filter(
            self::DEFAULT_TYPES,
            static fn (string $key): bool => in_array($key, $checked, true)
        ));

        return self::sanitizeTypes(array_merge($defaults, preg_split('/\R/u', $others) ?: []));
    }

    /**
     * Types supplémentaires (hors liste par défaut) d'une liste réglée.
     *
     * @param list<string> $types
     * @return list<string>
     */
    public static function customTypes(array $types): array
    {
        return array_values(array_filter(
            $types,
            static fn (string $type): bool => !in_array($type, self::DEFAULT_TYPES, true)
        ));
    }

    /**
     * Type retenu pour un enregistrement : un type de la liste réglée, ou le type actuel s'il n'y est
     * plus (il n'est jamais perdu en silence), sinon vide.
     *
     * @param list<string> $allowed
     */
    public static function chooseType(mixed $value, array $allowed, string $current = ''): string
    {
        $type = self::sanitizeType($value);
        if ($type === '') {
            return '';
        }

        return in_array($type, $allowed, true) || $type === $current ? $type : '';
    }

    /**
     * Perte de données mesurée, en minutes : null si elle n'est pas saisie (champ vide ou invalide),
     * 0 si aucune donnée n'a été perdue.
     */
    public static function sanitizeRpoMinutes(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $minutes = (int) $value;

        return $minutes >= 0 && $minutes <= RecoveryStrategyRules::MAX_MINUTES ? $minutes : null;
    }

    /**
     * La perte de données mesurée dépasse le RPO (en heures ; 0 : RPO non défini, rien à comparer).
     */
    public static function exceedsRpo(int $rpoHours, ?int $measuredMinutes): bool
    {
        return $rpoHours > 0 && $measuredMinutes !== null && $measuredMinutes > $rpoHours * 60;
    }

    /**
     * Résultat d'un test : réussi s'il tient le RTO et le RPO du processus.
     */
    public static function testResult(bool $exceedsRto, bool $exceedsRpo): string
    {
        return $exceedsRto || $exceedsRpo ? self::RESULT_FAILED : self::RESULT_PASSED;
    }

    /**
     * Fréquence de test propre à un processus, en mois : 0 (fréquence de la revue périodique) à
     * MAX_FREQUENCY_MONTHS.
     */
    public static function sanitizeFrequencyMonths(mixed $value): int
    {
        $months = is_numeric($value) ? (int) $value : 0;

        return $months > 0 && $months <= self::MAX_FREQUENCY_MONTHS ? $months : 0;
    }

    /**
     * Fréquence applicable : celle du processus, sinon celle par défaut.
     */
    public static function effectiveMonths(int $processMonths, int $defaultMonths): int
    {
        return $processMonths > 0 ? $processMonths : $defaultMonths;
    }

    /**
     * Date de départ de l'échéance du test : le dernier test, sinon (processus jamais testé) la
     * création du processus, pour lui laisser une période complète avant le premier signalement.
     */
    public static function dueBaseDate(?string $lastTestDate, ?string $creationDate): ?string
    {
        foreach ([$lastTestDate, $creationDate] as $date) {
            $date = substr(trim((string) $date), 0, 10);
            if (RecoveryStrategyRules::sanitizeDate($date) !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Années de la couverture, de la plus ancienne à l'année en cours.
     *
     * @return list<int>
     */
    public static function coverageYears(int $currentYear, int $count = self::COVERAGE_YEARS): array
    {
        $count = max(1, $count);

        return range($currentYear - $count + 1, $currentYear);
    }

    /**
     * Couverture pluriannuelle d'un processus : pour chaque année, le résultat de son dernier test
     * (date, puis ordre de saisie), ou « non testé ».
     *
     * @param iterable<array{id?: int, test_date?: string, result?: string}> $tests
     * @param list<int> $years
     * @return array<int, string> année => résultat
     */
    public static function yearlyCoverage(iterable $tests, array $years): array
    {
        $last = [];
        foreach ($tests as $test) {
            $date = (string) ($test['test_date'] ?? '');
            $year = (int) substr($date, 0, 4);
            if (!in_array($year, $years, true)) {
                continue;
            }
            $key = [$date, (int) ($test['id'] ?? 0)];
            if (!isset($last[$year]) || $key > $last[$year]['key']) {
                $last[$year] = ['key' => $key, 'result' => (string) ($test['result'] ?? self::RESULT_PASSED)];
            }
        }
        $coverage = [];
        foreach ($years as $year) {
            $coverage[$year] = $last[$year]['result'] ?? self::RESULT_UNTESTED;
        }

        return $coverage;
    }
}
