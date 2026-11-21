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

/**
 * Découverte des tables des fonctionnalités : chaque fichier src/Install/*Tables.php dont la classe
 * implémente FeatureTables, dans l'ordre alphabétique des noms de fichier (ordre stable, le même à
 * l'installation, à la mise à jour et à la désinstallation). Les classes *Tables plus anciennes,
 * appelées directement par Installer.php, n'implémentent pas l'interface et ne sont pas reprises ici.
 */
final class FeatureTablesRegistry
{
    /**
     * @return list<class-string<FeatureTables>>
     */
    public static function classes(): array
    {
        $files = glob(__DIR__ . '/*Tables.php') ?: [];
        sort($files, SORT_STRING);
        $classes = [];
        foreach ($files as $file) {
            $class = __NAMESPACE__ . '\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, FeatureTables::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public static function install(string $keySign, string $charset, string $collation, \Migration $migration): void
    {
        foreach (self::classes() as $class) {
            $class::install($keySign, $charset, $collation);
            $class::migrate($migration);
        }
    }

    public static function uninstall(\Migration $migration): void
    {
        foreach (self::classes() as $class) {
            $class::uninstall($migration);
        }
    }

    /**
     * @return list<class-string>
     */
    public static function pluginClasses(): array
    {
        $classes = [];
        foreach (self::classes() as $class) {
            array_push($classes, ...$class::pluginClasses());
        }

        return $classes;
    }
}
