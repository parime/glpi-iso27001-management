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
        $events = [];
        foreach (['PluginGrcmanagerRisk', 'PluginGrcmanagerRiskCatalog', 'system'] as $type) {
            $DB->insert('glpi_events', [
                'items_id' => 1,
                'type'     => $type,
                'date'     => '2026-10-28 12:00:00',
                'service'  => 'grcmanager',
                'level'    => 4,
                'message'  => 'PHPUnit migration',
            ]);
            $events[$type] = (int) $DB->insertId();
        }
        $risk = $this->savedSearch('PluginGrcmanagerRisk');
        $action = $this->savedSearch('PluginGrcmanagerRiskTreatmentAction');
        // Nom qui commence par un ancien nom sans en être un (classe restée dans inc/ ou d'un autre
        // plugin) : ne doit pas changer.
        $other = $this->savedSearch('PluginGrcmanagerRiskLegacyExport');

        ItemtypeRenames::migrate(new Migration('test'));
        // Relancée (mise à jour suivante) : aucun effet.
        ItemtypeRenames::migrate(new Migration('test'));

        $log = $DB->request(['FROM' => 'glpi_logs', 'WHERE' => ['id' => $logId]])->current();
        $this->assertSame(\GlpiPlugin\Grcmanager\Risk::class, $log['itemtype']);
        $this->assertSame('PHPUnit migration', $log['new_value']);

        // Journal des événements : seul le type renommé change.
        $types = [];
        foreach ($events as $old => $id) {
            $types[$old] = $DB->request(['FROM' => 'glpi_events', 'WHERE' => ['id' => $id]])->current()['type'];
        }
        $this->assertSame([
            'PluginGrcmanagerRisk'        => \GlpiPlugin\Grcmanager\Risk::class,
            'PluginGrcmanagerRiskCatalog' => 'PluginGrcmanagerRiskCatalog',
            'system'                      => 'system',
        ], $types);

        $this->assertSame([
            'itemtype' => \GlpiPlugin\Grcmanager\Risk::class,
            'query'    => 'itemtype=GlpiPlugin%5CGrcmanager%5CRisk&criteria%5B0%5D%5Bfield%5D=1',
        ], $this->savedSearchRow($risk));
        $this->assertSame([
            'itemtype' => \GlpiPlugin\Grcmanager\RiskTreatmentAction::class,
            'query'    => 'itemtype=GlpiPlugin%5CGrcmanager%5CRiskTreatmentAction&criteria%5B0%5D%5Bfield%5D=1',
        ], $this->savedSearchRow($action));
        $this->assertSame([
            'itemtype' => 'PluginGrcmanagerRiskLegacyExport',
            'query'    => 'itemtype=PluginGrcmanagerRiskLegacyExport&criteria%5B0%5D%5Bfield%5D=1',
        ], $this->savedSearchRow($other));
    }

    /**
     * Préférence d'affichage réglée deux fois (ancien favori, puis nouveau nom) : la mise à jour
     * ne doit pas s'arrêter sur la clé unique, et les autres réglages restent.
     */
    public function testOldRowWithATwinUnderTheNewNameDoesNotStopTheUpdate(): void
    {
        global $DB;

        $row = ['users_id' => 2, 'num' => 9001, 'interface' => 'central'];
        $DB->insert('glpi_displaypreferences', $row + ['itemtype' => \GlpiPlugin\Grcmanager\Risk::class, 'rank' => 1]);
        $DB->insert('glpi_displaypreferences', $row + ['itemtype' => 'PluginGrcmanagerRisk', 'rank' => 2]);
        $DB->insert('glpi_displaypreferences', ['num' => 9002, 'rank' => 3] + $row + [
            'itemtype' => 'PluginGrcmanagerRisk',
        ]);

        ItemtypeRenames::migrate(new Migration('test'));

        $ranks = [];
        foreach ($DB->request(['FROM' => 'glpi_displaypreferences', 'WHERE' => ['num' => [9001, 9002]]]) as $pref) {
            $ranks[(int) $pref['num']] = [$pref['itemtype'], (int) $pref['rank']];
        }
        $this->assertSame([
            9001 => [\GlpiPlugin\Grcmanager\Risk::class, 1],
            9002 => [\GlpiPlugin\Grcmanager\Risk::class, 3],
        ], $ranks);
    }

    public function testOldNameStillWorksAsAlias(): void
    {
        $this->assertTrue(class_exists('PluginGrcmanagerRisk'));
        $this->assertInstanceOf(\GlpiPlugin\Grcmanager\Risk::class, new \PluginGrcmanagerRisk());
        $this->assertSame(\GlpiPlugin\Grcmanager\Risk::$rightname, \PluginGrcmanagerRisk::$rightname);
    }
}
