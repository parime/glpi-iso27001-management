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

use GlpiPlugin\Grcmanager\Install\ItemtypeRenames;
use Migration;

/**
 * Issue #293 : mise à jour d'une installation existante, données à l'ancien nom de classe.
 */
final class ItemtypeRenamesTest extends GrcmanagerIntegrationTestCase
{
    private function savedSearch(string $itemtype): int
    {
        global $DB;

        $DB->insert('glpi_savedsearches', [
            'name'     => 'PHPUnit ' . $itemtype,
            'type'     => 1,
            'itemtype' => $itemtype,
            'users_id' => 2,
            'query'    => 'itemtype=' . $itemtype . '&criteria%5B0%5D%5Bfield%5D=1',
        ]);

        return (int) $DB->insertId();
    }

    /** @return array{itemtype: string, query: string} */
    private function savedSearchRow(int $id): array
    {
        global $DB;

        $row = $DB->request(['FROM' => 'glpi_savedsearches', 'WHERE' => ['id' => $id]])->current();

        return ['itemtype' => (string) $row['itemtype'], 'query' => (string) $row['query']];
    }

    public function testStoredNamesAreRenamedWithoutTouchingOtherClasses(): void
    {
        global $DB;

        $DB->insert('glpi_logs', [
            'itemtype'         => 'PluginGrcmanagerRisk',
            'items_id'         => 1,
            'linked_action'    => 0,
            'user_name'        => 'PHPUnit',
            'date_mod'         => '2026-10-28 12:00:00',
            'id_search_option' => 0,
            'old_value'        => '',
            'new_value'        => 'PHPUnit migration',
        ]);
        $logId = (int) $DB->insertId();
        $risk = $this->savedSearch('PluginGrcmanagerRisk');
        $action = $this->savedSearch('PluginGrcmanagerRiskTreatmentAction');
        // Classe restée dans inc/ dont le nom commence par un ancien nom : ne doit pas changer.
        $catalog = $this->savedSearch('PluginGrcmanagerRiskCatalog');

        ItemtypeRenames::migrate(new Migration('test'));
        // Relancée (mise à jour suivante) : aucun effet.
        ItemtypeRenames::migrate(new Migration('test'));

        $log = $DB->request(['FROM' => 'glpi_logs', 'WHERE' => ['id' => $logId]])->current();
        $this->assertSame(\GlpiPlugin\Grcmanager\Risk::class, $log['itemtype']);
        $this->assertSame('PHPUnit migration', $log['new_value']);

        $this->assertSame([
            'itemtype' => \GlpiPlugin\Grcmanager\Risk::class,
            'query'    => 'itemtype=GlpiPlugin%5CGrcmanager%5CRisk&criteria%5B0%5D%5Bfield%5D=1',
        ], $this->savedSearchRow($risk));
        $this->assertSame([
            'itemtype' => \GlpiPlugin\Grcmanager\RiskTreatmentAction::class,
            'query'    => 'itemtype=GlpiPlugin%5CGrcmanager%5CRiskTreatmentAction&criteria%5B0%5D%5Bfield%5D=1',
        ], $this->savedSearchRow($action));
        $this->assertSame([
            'itemtype' => 'PluginGrcmanagerRiskCatalog',
            'query'    => 'itemtype=PluginGrcmanagerRiskCatalog&criteria%5B0%5D%5Bfield%5D=1',
        ], $this->savedSearchRow($catalog));
    }

    public function testOldNameStillWorksAsAlias(): void
    {
        $this->assertTrue(class_exists('PluginGrcmanagerRisk'));
        $this->assertInstanceOf(\GlpiPlugin\Grcmanager\Risk::class, new \PluginGrcmanagerRisk());
        $this->assertSame(\GlpiPlugin\Grcmanager\Risk::$rightname, \PluginGrcmanagerRisk::$rightname);
    }
}
