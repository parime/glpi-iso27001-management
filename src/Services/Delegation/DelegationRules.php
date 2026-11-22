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

namespace GlpiPlugin\Grcmanager\Services\Delegation;

/**
 * Issue #618 : délégation des décisions du SMSI pendant une absence (PHP pur, testé seul).
 *
 * Les suppléants sont ceux que chaque personne déclare dans GLPI (préférences, onglet des
 * suppléants, avec une période d'absence). Le plugin est plus strict que la validation des tickets :
 * - une période sans date de début ou de fin ne délègue rien (GLPI la tient pour illimitée) ;
 * - seules les décisions confiées nommément au titulaire se délèguent (lignes d'une revue des
 *   accès, approbation d'une version de la SoA ou d'une politique), jamais une décision qui tient
 *   à un droit (dérogation, acceptation d'un risque) : le suppléant n'obtient aucun droit ;
 * - aucun type n'est délégable par défaut, l'administrateur choisit lesquels le sont ;
 * - un suppléant ne décide pas de son propre compte dans une revue des accès, et ne répond pas
 *   pour le compte d'un titulaire à une version qu'il approuve lui-même (une seule voix).
 */
final class DelegationRules
{
    public const TYPE_ACCESS_REVIEW = 'access_review';
    public const TYPE_SOA_APPROVAL = 'soa_approval';
    public const TYPE_POLICY_APPROVAL = 'policy_approval';

    /** Types de décisions délégables, dans l'ordre d'affichage. */
    public const TYPES = [self::TYPE_ACCESS_REVIEW, self::TYPE_SOA_APPROVAL, self::TYPE_POLICY_APPROVAL];

    /**
     * Types choisis dans la configuration : seules les valeurs connues, sans doublon, dans l'ordre
     * de TYPES. Valeur absente ou illisible : aucun type (rien n'est délégué par défaut).
     *
     * @param mixed $types
     * @return list<string>
     */
    public static function sanitizeTypes(mixed $types): array
    {
        if (is_string($types)) {
            $decoded = json_decode($types, true);
            $types = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($types)) {
            return [];
        }
        $wanted = array_map(static fn (mixed $type): string => is_scalar($type) ? trim((string) $type) : '', $types);

        return array_values(array_filter(self::TYPES, static fn (string $type): bool => in_array($type, $wanted, true)));
    }

    /**
     * La période d'absence couvre-t-elle $now ? Les deux bornes sont exigées : une délégation sans
     * fin n'existe pas, elle cesse d'elle-même à la date de fin.
     */
    public static function isWithinPeriod(mixed $start, mixed $end, string $now): bool
    {
        $start = self::normalizeDateTime($start);
        $end = self::normalizeDateTime($end, true);
        $now = self::normalizeDateTime($now);
        if ($start === null || $end === null || $now === null || $end < $start) {
            return false;
        }

        return $start <= $now && $now <= $end;
    }

    /**
     * Date (AAAA-MM-JJ) ou date et heure vers « AAAA-MM-JJ HH:MM:SS » ; une date seule de fin
     * couvre toute la journée. Toute autre valeur : null.
     */
    public static function normalizeDateTime(mixed $value, bool $endOfDay = false): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
            $value = str_replace('T', ' ', $value);

            return strlen($value) === 16 ? $value . ':00' : $value;
        }

        return null;
    }

    /**
     * Le suppléant peut-il décider de cette ligne de revue des accès pour le compte du titulaire ?
     * La ligne doit être confiée au titulaire et ne pas porter sur le compte du suppléant.
     */
    public static function canDecideAccessLine(
        int $substituteId,
        int $holderId,
        int $lineReviewerId,
        int $lineAccountId
    ): bool {
        return $substituteId > 0
            && $holderId > 0
            && $substituteId !== $holderId
            && $lineReviewerId === $holderId
            && $lineAccountId !== $substituteId;
    }

    /**
     * Le suppléant peut-il répondre pour le compte du titulaire à une version à approuver ?
     * Le titulaire doit être approbateur désigné en attente ; le suppléant ne doit pas être lui-même
     * approbateur de la version (il voterait deux fois).
     *
     * @param list<int> $approverIds tous les approbateurs désignés de la version
     * @param list<int> $pendingIds approbateurs qui n'ont pas encore répondu
     */
    public static function canAnswerApproval(
        int $substituteId,
        int $holderId,
        array $approverIds,
        array $pendingIds
    ): bool {
        return $substituteId > 0
            && $holderId > 0
            && $substituteId !== $holderId
            && in_array($holderId, $pendingIds, true)
            && !in_array($substituteId, $approverIds, true);
    }
}
