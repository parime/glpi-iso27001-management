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
 * Issue #608 : règles du registre du contexte de l'organisation (ISO 27001, articles 4.1 et 4.2),
 * sans GLPI (ni $DB, ni __()) pour être testées seules.
 *
 * Une fiche est soit un enjeu (interne ou externe), soit une partie intéressée. L'enjeu
 * « changement climatique » est une catégorie à part : l'amendement de 2024 demande de dire s'il est
 * pertinent, et de le justifier dans les deux cas.
 */
final class ContextItemRules
{
    public const KIND_ISSUE = 'issue';
    public const KIND_PARTY = 'party';

    /** @var list<string> */
    public const KINDS = [self::KIND_ISSUE, self::KIND_PARTY];

    /** @var list<string> */
    public const NATURES = ['internal', 'external'];

    public const CATEGORY_CLIMATE = 'climate_change';

    /** @var list<string> */
    public const CATEGORIES = [
        'regulatory', 'technological', 'economic', 'human', 'organizational', 'political',
        'environmental', self::CATEGORY_CLIMATE, 'other',
    ];

    /** @var list<string> */
    public const PARTY_TYPES = [
        'customer', 'authority', 'employee', 'supplier', 'shareholder', 'partner', 'insurer',
        'community', 'other',
    ];

    public const RELEVANCE_TO_ASSESS = 'to_assess';
    public const RELEVANCE_RETAINED = 'retained';
    public const RELEVANCE_NOT_RETAINED = 'not_retained';

    /** @var list<string> */
    public const RELEVANCES = [self::RELEVANCE_TO_ASSESS, self::RELEVANCE_RETAINED, self::RELEVANCE_NOT_RETAINED];

    public const ERROR_NAME = 'name';
    public const ERROR_JUSTIFICATION = 'justification';

    public static function normalizeKind(mixed $value): string
    {
        return self::pick($value, self::KINDS, self::KIND_ISSUE);
    }

    public static function normalizeNature(mixed $value): string
    {
        return self::pick($value, self::NATURES, 'internal');
    }

    public static function normalizeCategory(mixed $value): string
    {
        return self::pick($value, self::CATEGORIES, 'other');
    }

    public static function normalizePartyType(mixed $value): string
    {
        return self::pick($value, self::PARTY_TYPES, 'other');
    }

    public static function normalizeRelevance(mixed $value): string
    {
        return self::pick($value, self::RELEVANCES, self::RELEVANCE_TO_ASSESS);
    }

    /**
     * Une justification est demandée pour l'enjeu climatique dès qu'il est tranché (pertinent ou
     * non), et pour toute fiche écartée du SMSI.
     *
     * @param array<string, mixed> $fields
     */
    public static function needsJustification(array $fields): bool
    {
        $relevance = (string) ($fields['relevance'] ?? self::RELEVANCE_TO_ASSESS);
        if ($relevance === self::RELEVANCE_NOT_RETAINED) {
            return true;
        }

        return self::isClimate($fields) && $relevance === self::RELEVANCE_RETAINED;
    }

    /**
     * Erreurs de saisie (codes ERROR_*), une liste vide si la fiche peut être enregistrée.
     *
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    public static function errors(array $fields): array
    {
        $errors = [];
        if (trim((string) ($fields['name'] ?? '')) === '') {
            $errors[] = self::ERROR_NAME;
        }
        if (self::needsJustification($fields) && trim((string) ($fields['justification'] ?? '')) === '') {
            $errors[] = self::ERROR_JUSTIFICATION;
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function isClimate(array $fields): bool
    {
        return ($fields['kind'] ?? '') === self::KIND_ISSUE && ($fields['category'] ?? '') === self::CATEGORY_CLIMATE;
    }

    /**
     * La question du changement climatique a-t-elle une réponse : un enjeu climatique tranché et
     * justifié, hors corbeille ?
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public static function climateAnswered(iterable $rows): bool
    {
        foreach ($rows as $row) {
            if (
                self::isClimate($row)
                && empty($row['is_deleted'])
                && ($row['relevance'] ?? '') !== self::RELEVANCE_TO_ASSESS
                && in_array($row['relevance'] ?? '', self::RELEVANCES, true)
                && trim((string) ($row['justification'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Date de revue passée (au jour près). Sans date, la fiche n'est pas en retard.
     */
    public static function isOverdue(?string $reviewDate, string $today): bool
    {
        if ($reviewDate === null || $reviewDate === '' || str_starts_with($reviewDate, '0000')) {
            return false;
        }

        return substr($reviewDate, 0, 10) < substr($today, 0, 10);
    }

    /**
     * Sous-article de la norme que la fiche documente : 4.1 pour un enjeu, 4.2 pour une partie.
     */
    public static function clauseFor(string $kind): string
    {
        return $kind === self::KIND_PARTY ? '4.2' : '4.1';
    }

    /**
     * Lot 2 : type d'élément lié passé dans l'adresse (« Ajouter un enjeu » depuis l'onglet d'un
     * risque, d'une obligation ou d'une mesure) => champ du formulaire à présélectionner.
     */
    public const LINK_TYPES = [
        'risk'       => '_linked_risks',
        'obligation' => '_linked_obligations',
        'control'    => '_linked_controls',
    ];

    public static function linkFieldFor(mixed $type): ?string
    {
        return is_string($type) ? (self::LINK_TYPES[$type] ?? null) : null;
    }

    /**
     * Cellule de l'export CSV neutralisée contre l'injection de formules (= + - @ en tête).
     */
    public static function csvCell(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function pick(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
