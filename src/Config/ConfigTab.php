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

namespace GlpiPlugin\Grcmanager\Config;

/**
 * Onglet de la page de configuration du plugin (issue #1036), un fichier
 * src/Config/Tab/<Onglet>Tab.php par onglet. Il est montré si au moins un de ses blocs l'est.
 */
abstract class ConfigTab
{
    /** Clé de l'onglet, dans l'adresse : front/config.php?tab=<clé>. */
    abstract public static function key(): string;

    /** Libellé traduit. */
    abstract public static function label(): string;

    /** Icône Tabler, par exemple « ti-settings ». */
    abstract public static function icon(): string;

    /** Place dans la barre d'onglets : du plus petit au plus grand (de 10 en 10). */
    abstract public static function order(): int;
}
