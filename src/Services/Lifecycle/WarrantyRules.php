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

namespace GlpiPlugin\Grcmanager\Services\Lifecycle;

/**
 * Issue #782 : état de la garantie d'un matériel, d'après ses informations financières natives
 * (glpi_infocoms.warranty_date et warranty_duration, en mois).
 *
 * - Fin de garantie : date de début + durée en mois, comme Infocom::getWarrantyExpir() de GLPI
 *   (DateInterval « P{n}M ») ; une durée de -1 est la garantie à vie de GLPI.
 * - Garantie expirée le jour de sa date de fin, comme l'alerte de fin de garantie de GLPI et la fin
 *   de support (CycleMatcher) ; « fin proche » avec le seuil commun de la fin de support.
 * - Sans date de début ou sans durée : état inconnu, jamais compté comme sous garantie.
 *
 * - Lot 2 : fin de vie déclarée par le constructeur, saisie à la main par modèle ; atteinte le jour
 *   de sa date, « proche » avec le même seuil commun. Indépendante de la garantie.
 *
 * PHP pur (aucune dépendance à GLPI), testé unitairement.
 *
 * @phpstan-type Warranty array{status: string, reason: string, end: string, days: int|null,
 *     lifetime: bool}
 * @phpstan-type EndOfLife array{status: string, date: string, days: int|null}
 */
final class WarrantyRules
{
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SOON = 'soon';
    public const STATUS_COVERED = 'covered';
    public const STATUS_UNKNOWN = 'unknown';

    /** Du plus grave au moins grave. */
    public const STATUSES = [self::STATUS_EXPIRED, self::STATUS_SOON, self::STATUS_COVERED, self::STATUS_UNKNOWN];

    /** Raisons d'un état inconnu. */
    public const REASON_NO_INFOCOM = 'no_infocom';
    public const REASON_NO_DATE = 'no_date';
    public const REASON_NO_DURATION = 'no_duration';

    /** Durée de garantie « à vie » de GLPI. */
    public const LIFETIME = -1;

    /** États de la fin de vie constructeur (lot 2), du plus grave au moins grave. */
    public const EOL_REACHED = 'eol_reached';
    public const EOL_SOON = 'eol_soon';
    public const EOL_FUTURE = 'eol_future';
    public const EOL_NONE = 'eol_none';
    public const EOL_STATUSES = [self::EOL_REACHED, self::EOL_SOON, self::EOL_FUTURE, self::EOL_NONE];

    /**
     * État de la fin de vie déclarée par le constructeur pour le modèle du matériel.
     *
     * @param string|null $date « 2027-06-30 », null ou '' si aucune date n'est saisie.
     * @return EndOfLife
     */
    public static function endOfLife(?string $date, string $today, int $warningDays): array
    {
        $date = self::date($date);
        if ($date === null) {
            return ['status' => self::EOL_NONE, 'date' => '', 'days' => null];
        }
        $days = CycleMatcher::daysBetween($today, $date);
        $status = match (true) {
            $days <= 0            => self::EOL_REACHED,
            $days <= $warningDays => self::EOL_SOON,
            default               => self::EOL_FUTURE,
        };

        return ['status' => $status, 'date' => $date, 'days' => $days];
    }

    /**
     * Nombre de matériels de chaque état de fin de vie constructeur.
     *
     * @param iterable<array{eol: array{status: string}}> $assets
     * @return array<string, int>
     */
    public static function endOfLifeCounts(iterable $assets): array
    {
        $counts = array_fill_keys(self::EOL_STATUSES, 0);
        foreach ($assets as $asset) {
            $status = in_array($asset['eol']['status'], self::EOL_STATUSES, true)
                ? $asset['eol']['status'] : self::EOL_NONE;
            $counts[$status]++;
        }

        return $counts;
    }

    /**
     * État de la garantie à la date du jour.
     *
     * @param bool        $hasInfocom Le matériel a des informations financières.
     * @param string|null $start      Début de garantie (« 2026-01-31 »), null ou '' si absent.
     * @param int         $months     Durée en mois (-1 : à vie, 0 : non renseignée).
     * @param string      $today      « 2026-11-20 ».
     * @return Warranty
     */
    public static function evaluate(
        bool $hasInfocom,
        ?string $start,
        int $months,
        string $today,
        int $warningDays
    ): array {
        $unknown = ['status' => self::STATUS_UNKNOWN, 'reason' => '', 'end' => '', 'days' => null, 'lifetime' => false];
        if (!$hasInfocom) {
            return ['reason' => self::REASON_NO_INFOCOM] + $unknown;
        }
        if ($months === self::LIFETIME) {
            return ['status' => self::STATUS_COVERED, 'lifetime' => true] + $unknown;
        }
        $start = self::date($start);
        if ($start === null) {
            return ['reason' => self::REASON_NO_DATE] + $unknown;
        }
        if ($months <= 0) {
            return ['reason' => self::REASON_NO_DURATION] + $unknown;
        }
        $end = self::endDate($start, $months);
        $days = CycleMatcher::daysBetween($today, $end);
        $status = match (true) {
            $days <= 0            => self::STATUS_EXPIRED,
            $days <= $warningDays => self::STATUS_SOON,
            default               => self::STATUS_COVERED,
        };

        return ['status' => $status, 'reason' => '', 'end' => $end, 'days' => $days, 'lifetime' => false];
    }

    /**
     * Date de fin : début + durée en mois, comme GLPI (le 31 janvier + 1 mois donne le 3 mars, pas
     * le 28 février : même date que la fiche du matériel).
     */
    public static function endDate(string $start, int $months): string
    {
        $date = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));

        return $date->add(new \DateInterval('P' . max(0, $months) . 'M'))->format('Y-m-d');
    }

    /**
     * Date « AAAA-MM-JJ » valide, null sinon (champ vide, « 0000-00-00 », texte libre).
     */
    public static function date(?string $value): ?string
    {
        $value = substr(trim((string) $value), 0, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }

    /**
     * Rang de gravité (0 = le plus grave).
     */
    public static function severity(string $status): int
    {
        $rank = array_search($status, self::STATUSES, true);

        return $rank === false ? count(self::STATUSES) : (int) $rank;
    }

    /**
     * Tableau croisé : par type de matériel et par entité, le nombre de matériels de chaque état.
     * Groupes triés par type puis par entité.
     *
     * @param iterable<array{itemtype: string, entities_id: int, status: string}> $assets
     * @return list<array{itemtype: string, entities_id: int, counts: array<string, int>, total: int}>
     */
    public static function crossTab(iterable $assets): array
    {
        $groups = [];
        foreach ($assets as $asset) {
            $key = $asset['itemtype'] . "\0" . $asset['entities_id'];
            $groups[$key] ??= [
                'itemtype'    => $asset['itemtype'],
                'entities_id' => $asset['entities_id'],
                'counts'      => array_fill_keys(self::STATUSES, 0),
                'total'       => 0,
            ];
            $status = in_array($asset['status'], self::STATUSES, true) ? $asset['status'] : self::STATUS_UNKNOWN;
            $groups[$key]['counts'][$status]++;
            $groups[$key]['total']++;
        }
        ksort($groups, SORT_STRING);

        return array_values($groups);
    }

    /**
     * Nombre de matériels de chaque état.
     *
     * @param iterable<array{status: string}> $assets
     * @return array<string, int>
     */
    public static function counts(iterable $assets): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($assets as $asset) {
            $status = in_array($asset['status'], self::STATUSES, true) ? $asset['status'] : self::STATUS_UNKNOWN;
            $counts[$status]++;
        }

        return $counts;
    }
}
