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

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #582 : langue d'affichage des catalogues de référentiels (NIS2, DORA, AI Act, ANSSI...),
 * dont les textes sont rangés par code de langue à deux lettres (`fr`, `en`, `it`...). La langue
 * de l'utilisateur si le catalogue l'a, sinon l'anglais, sinon le français : un catalogue sans
 * italien s'affiche en anglais pour un utilisateur italien, sans erreur.
 */
final class CatalogLanguage
{
    public const FALLBACKS = ['en', 'fr'];

    /** Code à deux lettres de la langue de la session (`it` pour it_IT), `en` sans session. */
    public static function current(): string
    {
        $language = strtolower(substr((string) ($_SESSION['glpilanguage'] ?? ''), 0, 2));

        return preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : 'en';
    }

    /**
     * Code de langue retenu pour une entrée : la langue demandée si l'entrée a un texte non vide
     * pour elle, sinon la première langue de repli disponible.
     *
     * @param array<array-key, mixed> $entry
     */
    public static function resolve(array $entry, string $prefix = '', ?string $language = null): string
    {
        foreach (array_unique(array_merge([$language ?? self::current()], self::FALLBACKS)) as $code) {
            $value = $entry[$prefix . $code] ?? null;
            if (is_string($value) && $value !== '') {
                return $code;
            }
        }

        return self::FALLBACKS[0];
    }

    /**
     * Texte d'une entrée dans la langue retenue par resolve() ; `$prefix` pour les champs nommés
     * `title_fr`, `title_en`... Chaîne vide si l'entrée n'a aucun texte.
     *
     * @param array<array-key, mixed> $entry
     */
    public static function pick(array $entry, string $prefix = '', ?string $language = null): string
    {
        $value = $entry[$prefix . self::resolve($entry, $prefix, $language)] ?? '';

        return is_string($value) ? $value : '';
    }
}
