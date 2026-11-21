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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Install\FeatureTablesRegistry;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\ItemtypeRenames;
use NotificationTarget;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Issue #808 : la désinstallation retire les journaux (glpi_logs) et les autres lignes du cœur par
 * type d'objet, d'après la liste des classes du plugin (anciens noms et classes créées dans src/).
 * Une classe historisée absente de cette liste laisse ses journaux en base.
 */
final class UninstallCleanupListTest extends GrcmanagerIntegrationTestCase
{
    public function testEveryHistorizedClassIsPurgedAtUninstall(): void
    {
        $listed = array_merge(
            array_values(ItemtypeRenames::MAP),
            (new ReflectionClassConstant(Installer::class, 'SRC_ONLY_CLASSES'))->getValue(),
            FeatureTablesRegistry::pluginClasses()
        );
        $missing = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/*.php') ?: [] as $file) {
            $class = 'GlpiPlugin\\Grcmanager\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $historized = $reflection->isSubclassOf(CommonDBTM::class)
                && ($reflection->getDefaultProperties()['dohistory'] ?? false) === true;
            $target = $reflection->isSubclassOf(NotificationTarget::class);
            if (($historized || $target) && !in_array($class, $listed, true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Classes à ajouter à Installer::SRC_ONLY_CLASSES (ou à pluginClasses() de la classe *Tables de la fonctionnalité)');
    }
}
