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

namespace GlpiPlugin\Grcmanager\Install;

use Migration;

/**
 * Tables d'une fonctionnalité, découvertes par l'installeur : une classe
 * src/Install/<Fonctionnalité>Tables.php qui implémente cette interface est installée, mise à jour
 * et désinstallée sans qu'Installer.php la cite (voir FeatureTablesRegistry). Une nouvelle
 * fonctionnalité ajoute ainsi un fichier au lieu d'allonger les listes d'Installer.php, que toutes
 * les branches modifient en même temps.
 */
interface FeatureTables
{
    /** Crée les tables absentes ; rejouable (installation et chaque mise à jour). */
    public static function install(string $keySign, string $charset, string $collation): void;

    /** Met à jour les tables existantes (colonnes, index ajoutés) ; rejouable, vide si rien à faire. */
    public static function migrate(Migration $migration): void;

    /** Supprime les tables à la désinstallation. */
    public static function uninstall(Migration $migration): void;

    /**
     * Classes du plugin dont GLPI garde des lignes par type d'objet (historique, préférences
     * d'affichage, documents restreints, cibles de notification...), purgées à la désinstallation.
     *
     * @return list<class-string>
     */
    public static function pluginClasses(): array;
}
