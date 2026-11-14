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

namespace GlpiPlugin\Grcmanager\Services\Policy;

use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;

/**
 * Issue #535 : règles pures (sans GLPI) de la prise de connaissance des politiques de sécurité par
 * leur public (ISO 27001 A.5.1, A.6.3).
 *
 * - Public cible : groupes, profils et entités, réunis (une personne en fait partie dès qu'elle
 *   correspond à l'un d'eux). Seuls les comptes actifs sont retenus, au lancement de la campagne.
 * - Une campagne porte sur une version approuvée de la politique ; chaque personne confirme avoir
 *   pris connaissance de cette version. Une nouvelle version relance une campagne, la précédente
 *   reste en historique (« remplacée »), sans changement automatique de statut.
 * - Retardataire : personne qui n'a pas confirmé une fois l'échéance passée (l'échéance est
 *   incluse). Une relance est une notification, jamais une sanction ; au plus une par semaine.
 */
final class AcknowledgementRules
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const AUDIENCE_GROUP = 'Group';
    public const AUDIENCE_PROFILE = 'Profile';
    public const AUDIENCE_ENTITY = 'Entity';

    public const AUDIENCE_TYPES = [self::AUDIENCE_GROUP, self::AUDIENCE_PROFILE, self::AUDIENCE_ENTITY];

    /** Délai de réponse proposé par défaut, en jours. */
    public const DEFAULT_DAYS = 30;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 365;

    /** Seuils de couleur du taux : vert à partir de GOOD_RATE, orange à partir de FAIR_RATE. */
    public const GOOD_RATE = 90;
    public const FAIR_RATE = 50;

    public static function isAudienceType(string $type): bool
    {
        return in_array($type, self::AUDIENCE_TYPES, true);
    }

    /** Délai de réponse ramené entre MIN_DAYS et MAX_DAYS (DEFAULT_DAYS si absent). */
    public static function sanitizeDays(mixed $days): int
    {
        if (!is_numeric($days) || (int) $days <= 0) {
            return self::DEFAULT_DAYS;
        }

        return max(self::MIN_DAYS, min(self::MAX_DAYS, (int) $days));
    }

    public static function dueDate(string $launchDate, int $days): string
    {
        return (new \DateTimeImmutable(substr($launchDate, 0, 10)))
            ->modify('+' . self::sanitizeDays($days) . ' days')
            ->format('Y-m-d');
    }

    /**
     * Une campagne ne se lance que sur une politique approuvée dont le public est défini, et pas
     * deux fois pour la même version.
     */
    public static function canLaunch(
        string $policyStatus,
        bool $hasAudience,
        ?string $lastCampaignVersion,
        string $version
    ): bool {
        return $policyStatus === PolicyLifecycle::STATUS_APPROVED
            && $hasAudience
            && ($lastCampaignVersion === null || $lastCampaignVersion !== $version);
    }

    /**
     * Nouvelle version : relance automatique d'une campagne, seulement si une campagne a déjà été
     * lancée pour une autre version (la première campagne est toujours lancée à la main).
     */
    public static function shouldRelaunch(
        string $policyStatus,
        bool $hasAudience,
        ?string $lastCampaignVersion,
        string $version
    ): bool {
        return $lastCampaignVersion !== null
            && self::canLaunch($policyStatus, $hasAudience, $lastCampaignVersion, $version);
    }

    /**
     * Membres du public cible, avec l'entité où leur prise de connaissance est comptée.
     *
     * @param array{groups?: list<int>, profiles?: list<int>, entities?: list<int>} $audience
     *        entités déjà étendues à leurs sous-entités quand elles sont récursives
     * @param array<int, array{default_entity?: ?int, groups?: list<int>,
     *     authorizations?: list<array{profiles_id: int, entities_id: int}>}> $users comptes actifs
     * @return array<int, int> users_id => entities_id
     */
    public static function members(array $audience, array $users): array
    {
        $groups = array_map('intval', $audience['groups'] ?? []);
        $profiles = array_map('intval', $audience['profiles'] ?? []);
        $entities = array_map('intval', $audience['entities'] ?? []);
        $members = [];
        foreach ($users as $usersId => $user) {
            $authorizations = $user['authorizations'] ?? [];
            $inGroup = array_intersect(array_map('intval', $user['groups'] ?? []), $groups) !== [];
            $inProfile = false;
            $entityMatches = [];
            foreach ($authorizations as $authorization) {
                if (in_array((int) $authorization['profiles_id'], $profiles, true)) {
                    $inProfile = true;
                }
                if (in_array((int) $authorization['entities_id'], $entities, true)) {
                    $entityMatches[] = (int) $authorization['entities_id'];
                }
            }
            if (!$inGroup && !$inProfile && $entityMatches === []) {
                continue;
            }
            $members[(int) $usersId] = self::countingEntity(
                $user['default_entity'] ?? null,
                array_map(static fn (array $a): int => (int) $a['entities_id'], $authorizations),
                $entityMatches
            );
        }
        ksort($members);

        return $members;
    }

    /**
     * Entité où compter une personne : parmi les entités du public qui l'ont retenue, sinon parmi
     * ses habilitations ; son entité par défaut si elle en fait partie, la première sinon.
     *
     * @param list<int> $authorizationEntities
     * @param list<int> $matchedEntities
     */
    public static function countingEntity(
        ?int $defaultEntity,
        array $authorizationEntities,
        array $matchedEntities
    ): int {
        $candidates = $matchedEntities !== [] ? $matchedEntities : $authorizationEntities;
        if ($defaultEntity !== null && in_array($defaultEntity, $candidates, true)) {
            return $defaultEntity;
        }
        if ($candidates !== []) {
            return (int) $candidates[0];
        }

        return max(0, (int) $defaultEntity);
    }

    public static function rate(int $done, int $total): int
    {
        return $total > 0 ? (int) floor($done * 100 / $total) : 0;
    }

    /** Couleur Tabler du taux : vert, orange ou rouge. */
    public static function rateColor(int $rate): string
    {
        if ($rate >= self::GOOD_RATE) {
            return 'green';
        }

        return $rate >= self::FAIR_RATE ? 'orange' : 'red';
    }

    /** L'échéance est incluse : une confirmation attendue le 10 n'est pas en retard le 10. */
    public static function isLate(?string $acknowledgedAt, string $dueDate, string $today): bool
    {
        return ($acknowledgedAt === null || $acknowledgedAt === '') && $today > substr($dueDate, 0, 10);
    }

    /**
     * @param list<array{date_acknowledged?: ?string}> $lines
     * @return array{total: int, done: int, pending: int, late: int, rate: int, color: string}
     */
    public static function progress(array $lines, string $dueDate, string $today): array
    {
        $total = count($lines);
        $done = 0;
        $late = 0;
        foreach ($lines as $line) {
            $acknowledged = $line['date_acknowledged'] ?? null;
            if ($acknowledged !== null && $acknowledged !== '') {
                $done++;
            } elseif (self::isLate(null, $dueDate, $today)) {
                $late++;
            }
        }
        $rate = self::rate($done, $total);

        return [
            'total'   => $total,
            'done'    => $done,
            'pending' => $total - $done,
            'late'    => $late,
            'rate'    => $rate,
            'color'   => self::rateColor($rate),
        ];
    }

    /**
     * Avancement par entité, dans l'ordre des entités rencontrées.
     *
     * @param list<array{entities_id: int, date_acknowledged?: ?string}> $lines
     * @return array<int, array{total: int, done: int, pending: int, late: int, rate: int, color: string}>
     */
    public static function progressByEntity(array $lines, string $dueDate, string $today): array
    {
        $byEntity = [];
        foreach ($lines as $line) {
            $byEntity[(int) $line['entities_id']][] = $line;
        }
        $result = [];
        foreach ($byEntity as $entityId => $entityLines) {
            $result[$entityId] = self::progress($entityLines, $dueDate, $today);
        }

        return $result;
    }

    /**
     * Retardataires : pas de confirmation, échéance passée.
     *
     * @template T of array{date_acknowledged?: ?string}
     * @param list<T> $lines
     * @return list<T>
     */
    public static function latecomers(array $lines, string $dueDate, string $today): array
    {
        return array_values(array_filter(
            $lines,
            static fn (array $line): bool => self::isLate($line['date_acknowledged'] ?? null, $dueDate, $today)
        ));
    }

    /**
     * Relance due : en retard, et pas de relance depuis au moins une semaine (même rythme que les
     * revues des accès).
     */
    public static function isReminderDue(
        ?string $acknowledgedAt,
        string $dueDate,
        ?string $lastReminder,
        string $today
    ): bool {
        return ($acknowledgedAt === null || $acknowledgedAt === '')
            && AccessReviewLogic::isReminderDue($dueDate, $lastReminder, $today);
    }

    /** Cellule CSV neutralisée contre l'injection de formules (= + - @). */
    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }
}
