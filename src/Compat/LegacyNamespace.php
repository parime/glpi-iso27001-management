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

namespace GlpiPlugin\Grcmanager\Compat;

/**
 * Issue #658 : la couche de compatibilité s'appelait GlpiPlugin\Grcmanager\Compatibility (dossier
 * src/Compatibility) jusqu'à la 2.50 ; elle suit désormais la charte commune (src/Compat). L'ancien
 * nom reste utilisable le temps d'une version (code d'un autre plugin qui appellerait
 * Compatibility\Http, par exemple) : alias créé seulement à la première utilisation.
 *
 * À retirer à la version suivante (voir TECH_DEBT.md, issue #658).
 */
final class LegacyNamespace
{
    public const OLD_PREFIX = 'GlpiPlugin\\Grcmanager\\Compatibility\\';
    public const NEW_PREFIX = 'GlpiPlugin\\Grcmanager\\Compat\\';

    /**
     * Nouveau nom d'une classe de l'ancien espace de noms, null pour toute autre classe.
     */
    public static function newName(string $class): ?string
    {
        if (!str_starts_with($class, self::OLD_PREFIX)) {
            return null;
        }

        return self::NEW_PREFIX . substr($class, strlen(self::OLD_PREFIX));
    }

    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $new = self::newName($class);
            if ($new !== null && (class_exists($new) || interface_exists($new) || trait_exists($new))) {
                class_alias($new, $class);
            }
        });
    }
}
