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

namespace GlpiPlugin\Grcmanager\Services\Review;

use DateTimeImmutable;

/**
 * Issue #542 : règles pures du dossier préparatoire de la revue de direction (ISO 27001, 9.3.2) :
 * période couverte, sections, décisions à transformer en actions, avancement des actions des
 * revues précédentes et tendance d'un indicateur. Aucune dépendance à GLPI (testé unitairement).
 */
final class ManagementReviewDossierRules
{
    /**
     * Sections du dossier, dans l'ordre des entrées de la clause 9.3.2 : clé => [repère de clause,
     * icône Tabler]. Les libellés et résumés sont traduits par ManagementReviewDossier.
     */
    public const SECTIONS = [
        'previous_actions' => ['9.3.2 a', 'ti-list-check'],
        'context'          => ['9.3.2 b, c, e', 'ti-world'],
        'nonconformities'  => ['9.3.2 d 1', 'ti-alert-hexagon'],
        'measurement'      => ['9.3.2 d 2', 'ti-activity-heartbeat'],
        'audits'           => ['9.3.2 d 3', 'ti-clipboard-check'],
        'objectives'       => ['9.3.2 d 4', 'ti-target-arrow'],
        'risks'            => ['9.3.2 f', 'ti-shield-exclamation'],
        'soa'              => ['9.3.2 f', 'ti-file-certificate'],
        'improvement'      => ['9.3.2 g', 'ti-trending-up'],
    ];

    /** Période par défaut sans revue précédente : douze mois avant la fin. */
    public const DEFAULT_MONTHS = 12;

    /** Longueur maximale d'une action créée à partir d'une ligne de décision. */
    public const MAX_ACTION_LENGTH = 1000;

    /**
     * Période proposée : de la revue précédente (exclue la veille, incluse le jour même) jusqu'à la
     * date de la revue, ou aujourd'hui pour une revue sans date. Sans revue précédente : les douze
     * mois qui précèdent.
     *
     * @return array{start: string, end: string}
     */
    public static function defaultPeriod(?string $previousReviewDate, ?string $reviewDate, string $today): array
    {
        $end = self::validDate($reviewDate) ?? self::validDate($today) ?? date('Y-m-d');
        $previous = self::validDate($previousReviewDate);
        if ($previous !== null && $previous < $end) {
            return ['start' => $previous, 'end' => $end];
        }

        return [
            'start' => (new DateTimeImmutable($end))->modify('-' . self::DEFAULT_MONTHS . ' months')->format('Y-m-d'),
            'end'   => $end,
        ];
    }

    /**
     * Période saisie, contrôlée : deux dates valides, début avant (ou égal à) la fin, pas plus de dix
     * ans. Null si la saisie ne convient pas.
     *
     * @return array{start: string, end: string}|null
     */
    public static function normalizePeriod(mixed $start, mixed $end): ?array
    {
        $start = self::validDate(is_string($start) ? $start : null);
        $end = self::validDate(is_string($end) ? $end : null);
        if ($start === null || $end === null || $start > $end) {
            return null;
        }
        $limit = (new DateTimeImmutable($end))->modify('-10 years')->format('Y-m-d');
        if ($start < $limit) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Date AAAA-MM-JJ réelle (pas de 2026-02-31), sinon null.
     */
    public static function validDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1970) {
            return null;
        }

        return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
    }

    /**
     * Le jour donné (AAAA-MM-JJ ou date et heure) tombe-t-il dans la période, bornes comprises ?
     */
    public static function inPeriod(?string $value, string $start, string $end): bool
    {
        $day = self::validDate($value);

        return $day !== null && $day >= $start && $day <= $end;
    }

    /**
     * Les décisions de la revue (texte libre), une par ligne : puces et numéros retirés, lignes
     * vides et doublons écartés.
     *
     * @return list<string>
     */
    public static function decisionLines(string $decisions): array
    {
        // Fins de ligne HTML (retour à la ligne, fin de paragraphe ou d'élément de liste) : une ligne.
        $text = (string) preg_replace('~<\s*(?:br\s*/?|/\s*(?:p|li))\s*>~i', "\n", $decisions);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        $seen = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*\x{2022}\x{2013}>]+|\d+[.)]|[a-z][.)])\s*/u', '', $line));
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            $line = mb_substr($line, 0, self::MAX_ACTION_LENGTH);
            $key = mb_strtolower($line);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Lignes de décision qui ne sont pas encore une action de la revue (comparaison sans tenir
     * compte de la casse ni des espaces), pour ne jamais créer deux fois la même action.
     *
     * @param list<string> $lines
     * @param list<string> $existing descriptions des actions déjà créées
     * @return list<string>
     */
    public static function newDecisionLines(array $lines, array $existing): array
    {
        $known = [];
        foreach ($existing as $description) {
            $known[mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $description)))] = true;
        }

        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => !isset($known[mb_strtolower($line)])
        ));
    }

    /**
     * Avancement d'un ensemble d'actions : total, terminées, en cours (non terminées), en retard
     * et pourcentage réalisé (null sans action).
     *
     * @param list<array{status: string, due_date: ?string}> $actions
     * @return array{total: int, done: int, open: int, overdue: int, percent: ?int}
     */
    public static function actionSummary(array $actions, string $today): array
    {
        $summary = ['total' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0, 'percent' => null];
        foreach ($actions as $action) {
            $summary['total']++;
            if ($action['status'] === 'done') {
                $summary['done']++;
                continue;
            }
            $summary['open']++;
            if (self::isOverdue($action['due_date'], $action['status'], $today)) {
                $summary['overdue']++;
            }
        }
        if ($summary['total'] > 0) {
            $summary['percent'] = (int) round(100 * $summary['done'] / $summary['total']);
        }

        return $summary;
    }

    /**
     * Action non terminée dont l'échéance est passée (même définition que le plan de traitement
     * des risques, TreatmentPlanRules::isOverdue()).
     */
    public static function isOverdue(?string $dueDate, string $status, string $today): bool
    {
        $due = self::validDate($dueDate);

        return $status !== 'done' && $due !== null && $due < $today;
    }

    /**
     * Tendance d'un indicateur entre deux relevés : 'up', 'down', 'stable', ou null s'il manque un
     * relevé.
     *
     * @return array{direction: string, delta: int}|null
     */
    public static function trend(?int $first, ?int $last): ?array
    {
        if ($first === null || $last === null) {
            return null;
        }
        $delta = $last - $first;

        return ['direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'stable'), 'delta' => $delta];
    }

    /**
     * Relevés mensuels (AAAA-MM => valeur) compris dans la période, du plus ancien au plus récent.
     *
     * @param array<string, int> $byMonth
     * @return array<string, int>
     */
    public static function monthsInPeriod(array $byMonth, string $start, string $end): array
    {
        $from = substr($start, 0, 7);
        $to = substr($end, 0, 7);
        $kept = array_filter(
            $byMonth,
            static fn (string $month): bool => $month >= $from && $month <= $to,
            ARRAY_FILTER_USE_KEY
        );
        ksort($kept);

        return $kept;
    }

    /**
     * Nom du fichier PDF archivé (préfixe reconnu par le registre des documents restreints).
     */
    public static function fileName(int $reviewId, string $timestamp): string
    {
        return sprintf('dossier-revue-direction-%d-%s.pdf', $reviewId, preg_replace('/\D/', '', $timestamp));
    }
}
