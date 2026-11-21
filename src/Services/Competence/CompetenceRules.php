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

namespace GlpiPlugin\Grcmanager\Services\Competence;

/**
 * Issue #774 : règles des compétences (ISO 27001, article 7.2), en PHP pur.
 *
 * Une fonction du SMSI exige des compétences à un niveau (1 notions, 2 maîtrise, 3 expertise). Une
 * personne acquiert une compétence par une formation réussie ou par une déclaration appuyée d'une
 * preuve ; une acquisition peut expirer (formation à renouveler, certificat à date de fin). L'écart
 * se calcule par personne et par compétence : niveau exigé (le plus haut de ses fonctions) contre
 * niveau acquis encore valable (le plus haut de ses sources). Rien n'est jamais modifié.
 */
final class CompetenceRules
{
    public const LEVEL_BASIC = 1;
    public const LEVEL_PROFICIENT = 2;
    public const LEVEL_EXPERT = 3;
    public const LEVELS = [self::LEVEL_BASIC, self::LEVEL_PROFICIENT, self::LEVEL_EXPERT];

    public const STATUS_MET = 'met';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_MISSING = 'missing';
    public const STATUS_EXPIRED = 'expired';

    /** Façons d'acquérir une compétence exigée. */
    public const MODES = ['training', 'diploma', 'certification', 'experience', 'other'];

    /** Nature de la preuve d'une compétence déclarée. */
    public const EVIDENCE_TYPES = ['certificate', 'attestation', 'diploma', 'annual_review', 'experience', 'other'];

    public const HOLDER_TYPES = ['User', 'Group', 'UserTitle'];

    public static function normalizeLevel(mixed $value): int
    {
        $level = is_numeric($value) ? (int) $value : 0;

        return in_array($level, self::LEVELS, true) ? $level : self::LEVEL_BASIC;
    }

    public static function normalizeMode(mixed $value): string
    {
        $mode = is_string($value) ? $value : '';

        return in_array($mode, self::MODES, true) ? $mode : 'training';
    }

    public static function normalizeEvidenceType(mixed $value): ?string
    {
        $type = is_string($value) ? $value : '';

        return in_array($type, self::EVIDENCE_TYPES, true) ? $type : null;
    }

    /**
     * Date au format AAAA-MM-JJ, ou null si vide ou invalide.
     */
    public static function normalizeDate(mixed $value): ?string
    {
        $date = is_string($value) ? trim(substr($value, 0, 10)) : '';
        if ($date === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    /**
     * Fin de validité d'une compétence acquise par une formation : date de réalisation plus la
     * période de renouvellement de la formation ; null si pas de renouvellement ou date inconnue.
     */
    public static function trainingExpiry(?string $completionDate, int $renewalMonths): ?string
    {
        $date = self::normalizeDate($completionDate);
        if ($date === null || $renewalMonths <= 0) {
            return null;
        }

        return (new \DateTimeImmutable($date))->modify('+' . $renewalMonths . ' months')->format('Y-m-d');
    }

    /**
     * Une participation compte comme formation réussie : réalisée, et non échouée si elle est évaluée.
     */
    public static function trainingPassed(string $completionStatus, string $assessmentResult): bool
    {
        return $completionStatus === 'completed' && $assessmentResult !== 'failed';
    }

    public static function isValid(?string $expiry, string $today): bool
    {
        return $expiry === null || $expiry === '' || $expiry >= $today;
    }

    /**
     * Niveau acquis encore valable (0 si aucun) et présence d'une acquisition expirée.
     *
     * @param list<array{level: int, expires: ?string}> $sources
     * @return array{level: int, expired: bool}
     */
    public static function acquired(array $sources, string $today): array
    {
        $level = 0;
        $expired = false;
        foreach ($sources as $source) {
            if (self::isValid($source['expires'], $today)) {
                $level = max($level, self::normalizeLevel($source['level']));
            } else {
                $expired = true;
            }
        }

        return ['level' => $level, 'expired' => $expired];
    }

    /**
     * État d'une compétence exigée pour une personne.
     *
     * @param list<array{level: int, expires: ?string}> $sources
     */
    public static function status(int $required, array $sources, string $today): string
    {
        $acquired = self::acquired($sources, $today);
        if ($acquired['level'] >= $required) {
            return self::STATUS_MET;
        }
        if ($acquired['level'] > 0) {
            return self::STATUS_PARTIAL;
        }

        return $acquired['expired'] ? self::STATUS_EXPIRED : self::STATUS_MISSING;
    }

    public static function isGap(string $status): bool
    {
        return $status !== self::STATUS_MET;
    }

    /**
     * Compétences exigées de chaque personne : niveau le plus haut parmi ses fonctions, et les
     * fonctions qui l'exigent.
     *
     * @param array<int, list<int>> $functionsByPerson personne => fonctions tenues
     * @param array<int, array<int, int>> $requirements fonction => compétence => niveau exigé
     * @return array<int, array<int, array{level: int, functions: list<int>}>>
     */
    public static function requiredByPerson(array $functionsByPerson, array $requirements): array
    {
        $required = [];
        foreach ($functionsByPerson as $person => $functions) {
            foreach (array_values(array_unique($functions)) as $function) {
                foreach ($requirements[$function] ?? [] as $competence => $level) {
                    $current = $required[$person][$competence] ?? ['level' => 0, 'functions' => []];
                    $current['level'] = max($current['level'], self::normalizeLevel($level));
                    $current['functions'][] = $function;
                    $required[$person][$competence] = $current;
                }
            }
        }

        return $required;
    }

    /**
     * Formations qui donnent la compétence au niveau exigé, à proposer pour combler l'écart.
     *
     * @param array<int, int> $grants formation => niveau donné
     * @return list<int>
     */
    public static function proposedTrainings(int $required, array $grants): array
    {
        $ids = [];
        foreach ($grants as $training => $level) {
            if (self::normalizeLevel($level) >= $required) {
                $ids[] = (int) $training;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Lot 2 : inscriptions encore en attente (formation ou session pas encore suivie) qui comblent
     * l'écart, c'est-à-dire qui donnent la compétence au moins au niveau exigé. Un écart qui en a
     * au moins une a une action planifiée.
     *
     * @template T of array{level: int}
     * @param list<T> $planned
     * @return list<T>
     */
    public static function plannedFor(int $required, array $planned): array
    {
        return array_values(array_filter(
            $planned,
            static fn (array $entry): bool => self::normalizeLevel($entry['level']) >= $required
        ));
    }

    /**
     * Synthèse : personnes suivies, personnes avec au moins un écart, écarts et taux de couverture
     * (compétences exigées acquises au niveau attendu), null sans aucune exigence.
     *
     * @param array<int, array<int, string>> $statuses personne => compétence => état
     * @return array{people: int, people_with_gaps: int, required: int, gaps: int, rate: ?int}
     */
    public static function summary(array $statuses): array
    {
        $required = $gaps = $peopleWithGaps = 0;
        foreach ($statuses as $cells) {
            $own = 0;
            foreach ($cells as $status) {
                $required++;
                if (self::isGap($status)) {
                    $own++;
                }
            }
            $gaps += $own;
            if ($own > 0) {
                $peopleWithGaps++;
            }
        }

        return [
            'people'           => count($statuses),
            'people_with_gaps' => $peopleWithGaps,
            'required'         => $required,
            'gaps'             => $gaps,
            'rate'             => $required > 0 ? (int) floor(100 * ($required - $gaps) / $required) : null,
        ];
    }

    /**
     * Lot 3 : clé d'un écart (personne, compétence, niveau exigé), pour ne le signaler qu'une fois.
     */
    public static function gapKey(int $person, int $competence, int $level): string
    {
        return $person . '|' . $competence . '|' . self::normalizeLevel($level);
    }

    /**
     * Lot 3 : écarts sans formation planifiée pas encore signalés au responsable.
     *
     * @template T of array{person: int, competence_id: int, level: int}
     * @param list<T> $gaps
     * @param array<string, true> $known clés déjà signalées
     * @return list<T>
     */
    public static function newGaps(array $gaps, array $known): array
    {
        return array_values(array_filter(
            $gaps,
            static fn (array $gap): bool
                => !isset($known[self::gapKey($gap['person'], $gap['competence_id'], $gap['level'])])
        ));
    }

    /**
     * Une déclaration doit citer sa preuve : une référence écrite ou un document joint.
     */
    public static function hasEvidence(string $reference, int $documentsId): bool
    {
        return trim($reference) !== '' || $documentsId > 0;
    }
}
