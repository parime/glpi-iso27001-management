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

namespace GlpiPlugin\Grcmanager\Services\Workspace;

/**
 * Issue #539 : règles pures (sans GLPI) de « Mon espace GRC ». Les éléments lus par
 * MySpaceProvider sont filtrés (ce qui attend vraiment la personne), classés par échéance et
 * qualifiés (en retard, à faire sous peu, plus tard, sans échéance).
 *
 * Deux sortes d'éléments :
 * - une tâche ouverte (action de traitement, non-conformité, lignes de revue des accès, formation
 *   à suivre…) attend toujours la personne, avec ou sans échéance ;
 * - une échéance périodique (revue d'un risque ou d'une politique, test d'une mesure, preuve à
 *   renouveler…) ne l'attend que dans les HORIZON_DAYS jours qui viennent, ou une fois dépassée :
 *   une revue prévue dans deux ans n'est pas « à faire ».
 */
final class MySpaceRules
{
    /** Délai avant l'échéance à partir duquel une échéance périodique est à faire. */
    public const HORIZON_DAYS = 30;

    public const URGENCY_OVERDUE = 'overdue';
    public const URGENCY_SOON    = 'soon';
    public const URGENCY_LATER   = 'later';
    public const URGENCY_NONE    = 'none';

    /** Date (Y-m-d) d'une valeur de date ou de date et heure, ou null. */
    public static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) !== 1) {
            return null;
        }

        return $match[1] === '0000-00-00' ? null : $match[1];
    }

    public static function horizon(string $today): string
    {
        return (new \DateTimeImmutable($today))->modify('+' . self::HORIZON_DAYS . ' days')->format('Y-m-d');
    }

    /** L'échéance est incluse : un élément dû le 10 est encore à l'heure le 10. */
    public static function urgency(?string $due, string $today): string
    {
        $due = self::normalizeDate($due);
        if ($due === null) {
            return self::URGENCY_NONE;
        }
        if ($due < $today) {
            return self::URGENCY_OVERDUE;
        }

        return $due <= self::horizon($today) ? self::URGENCY_SOON : self::URGENCY_LATER;
    }

    /** Une tâche ouverte attend toujours ; une échéance périodique, sous HORIZON_DAYS jours. */
    public static function isWaiting(?string $due, bool $openTask, string $today): bool
    {
        if ($openTask) {
            return true;
        }
        $due = self::normalizeDate($due);

        return $due !== null && $due <= self::horizon($today);
    }

    /** Jours de retard à la date $today (0 si l'élément n'est pas en retard). */
    public static function daysLate(?string $due, string $today): int
    {
        $due = self::normalizeDate($due);
        if ($due === null || $due >= $today) {
            return 0;
        }

        return (int) (new \DateTimeImmutable($due))->diff(new \DateTimeImmutable($today))->days;
    }

    /**
     * Éléments qui attendent la personne, du plus urgent au moins urgent : échéance croissante
     * (les retards d'abord), les éléments sans échéance à la fin, puis par nom.
     *
     * @param list<array{source: string, id: int, name: string, due: ?string, url: string,
     *     detail?: string, open_task?: bool}> $candidates
     * @return list<array{source: string, id: int, name: string, due: ?string, url: string,
     *     detail: string, urgency: string, days_late: int}>
     */
    public static function build(array $candidates, string $today): array
    {
        $items = [];
        foreach ($candidates as $candidate) {
            $due = self::normalizeDate($candidate['due']);
            if (!self::isWaiting($due, (bool) ($candidate['open_task'] ?? false), $today)) {
                continue;
            }
            $items[] = [
                'source'    => $candidate['source'],
                'id'        => $candidate['id'],
                'name'      => $candidate['name'],
                'due'       => $due,
                'url'       => $candidate['url'],
                'detail'    => (string) ($candidate['detail'] ?? ''),
                'urgency'   => self::urgency($due, $today),
                'days_late' => self::daysLate($due, $today),
            ];
        }
        usort($items, [self::class, 'compare']);

        return $items;
    }

    /**
     * @param array{due: ?string, name: string, source: string, id: int} $a
     * @param array{due: ?string, name: string, source: string, id: int} $b
     */
    public static function compare(array $a, array $b): int
    {
        if ($a['due'] !== $b['due']) {
            if ($a['due'] === null) {
                return 1;
            }
            if ($b['due'] === null) {
                return -1;
            }

            return strcmp($a['due'], $b['due']);
        }

        $byName = strcasecmp($a['name'], $b['name']);
        if ($byName !== 0) {
            return $byName;
        }

        return [$a['source'], $a['id']] <=> [$b['source'], $b['id']];
    }

    /**
     * @param list<array{urgency: string}> $items
     * @return array{total: int, overdue: int, soon: int}
     */
    public static function summary(array $items): array
    {
        $count = static fn (string $urgency): int => count(array_filter(
            $items,
            static fn (array $item): bool => $item['urgency'] === $urgency
        ));

        return [
            'total'   => count($items),
            'overdue' => $count(self::URGENCY_OVERDUE),
            'soon'    => $count(self::URGENCY_SOON),
        ];
    }

    /**
     * Lignes de revue des accès confiées à la personne, regroupées par campagne : seules les
     * campagnes où il reste au moins une décision à prendre.
     *
     * @param list<array<string, mixed>> $lines lignes de AccessReview::getLinesForReviewer()
     * @return list<array{id: int, name: string, due: ?string, pending: int, total: int}>
     */
    public static function accessReviewCampaigns(array $lines): array
    {
        $campaigns = [];
        foreach ($lines as $line) {
            $id = (int) ($line['plugin_grcmanager_accessreviews_id'] ?? 0);
            $campaigns[$id] ??= [
                'id'      => $id,
                'name'    => (string) ($line['campaign_name'] ?? ''),
                'due'     => self::normalizeDate($line['campaign_due_date'] ?? null),
                'pending' => 0,
                'total'   => 0,
            ];
            $campaigns[$id]['total']++;
            if (($line['decision'] ?? '') === 'pending') {
                $campaigns[$id]['pending']++;
            }
        }

        return array_values(array_filter($campaigns, static fn (array $c): bool => $c['pending'] > 0));
    }

    /**
     * Formations à renouveler : pour chaque programme (une formation et ses sessions), la dernière
     * participation terminée de la personne, si la formation exige un renouvellement. Une
     * participation plus récente au même programme remplace la précédente.
     *
     * @param list<array{id: int, program: int, title: string, completion_date: ?string, months: int}> $completed
     * @return list<array{id: int, title: string, due: string}>
     */
    public static function trainingRenewals(array $completed): array
    {
        $latest = [];
        foreach ($completed as $row) {
            $date = self::normalizeDate($row['completion_date']);
            if ($date === null) {
                continue;
            }
            $current = $latest[$row['program']] ?? null;
            $newer = $current === null || [$date, $row['id']] > [$current['date'], $current['id']];
            if ($newer) {
                $latest[$row['program']] = ['date' => $date] + $row;
            }
        }
        $renewals = [];
        foreach ($latest as $row) {
            if ($row['months'] <= 0) {
                continue;
            }
            $renewals[] = [
                'id'    => $row['id'],
                'title' => $row['title'],
                'due'   => (new \DateTimeImmutable($row['date']))
                    ->modify('+' . $row['months'] . ' months')->format('Y-m-d'),
            ];
        }

        return $renewals;
    }
}
