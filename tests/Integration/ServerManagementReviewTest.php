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

use Computer;
use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\AccessReviewBmcAccount;
use GlpiPlugin\Grcmanager\Install\AccessReviewBmcTables;
use GlpiPlugin\Grcmanager\Install\ServerManagementTables as Tables;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules as Rules;

/**
 * Issue #843, lot 2 : firmware des contrôleurs comparé aux vulnérabilités du parc et aux fins de
 * support (données fictives, aucun appel réseau) ; comptes des contrôleurs dans la revue des accès.
 */
final class ServerManagementReviewTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private string $suffix;

    private int $computer;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        ServerManagement::reset();
        EndOfLifeStore::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo BMC lot 2 ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;
        $this->computer = (int) (new Computer())->add([
            'name' => 'bmc-lot2-' . $this->suffix, 'entities_id' => $this->entity, 'serial' => 'LOT2' . $this->suffix,
        ]);
        $this->assertGreaterThan(0, $this->computer);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        ServerManagement::reset();
        parent::tearDown();
        EndOfLifeStore::reset();
    }

    private function controller(): int
    {
        global $DB;

        $DB->insert(Tables::CONTROLLERS, [
            'entities_id' => $this->entity, 'name' => 'iLO lot 2 ' . $this->suffix, 'source' => Rules::SOURCE_CSV,
            'vendor' => 'PhpUnitDemo', 'manager_model' => 'iLO ' . $this->suffix,
            'firmware_version' => 'iLO 5 v2.72', 'system_model' => 'Server ' . $this->suffix,
            'bios_version' => 'U30 v1.10 (03/22/2023)', 'serial' => 'LOT2' . $this->suffix,
            'date_read' => date('Y-m-d H:i:s'), 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $DB->insertId();
        foreach ([['root', 'Administrator', 1], ['operateur', 'Operator', 1], ['alice', 'Administrator', 1]] as $a) {
            $DB->insert(Tables::ACCOUNTS, [
                'plugin_grcmanager_bmccontrollers_id' => $id, 'username' => $a[0], 'role' => $a[1],
                'is_enabled' => $a[2], 'date_import' => date('Y-m-d H:i:s'),
            ]);
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        ServerManagement::reset();
        foreach (ServerManagement::pageData()['rows'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        $this->fail('Controller not listed.');
    }

    public function testFirmwareIsComparedWithFleetVulnerabilitiesAndEndOfSupport(): void
    {
        global $DB;

        $id = $this->controller();
        $name = 'PhpUnitDemo iLO ' . $this->suffix;
        $bios = 'PhpUnitDemo Server ' . $this->suffix . ' BIOS';

        // Avant tout alias : firmwares listés comme à relier, aucun écart de vulnérabilité.
        $row = $this->row($id);
        $this->assertSame([$name, $bios], array_column($row['firmwares'], 'name'));
        $this->assertSame(['2.72', '1.10'], array_column($row['firmwares'], 'version'));
        $this->assertFalse($row['firmwares'][0]['watched']);
        $this->assertNotContains(Rules::GAP_FIRMWARE_CVE, $row['gaps']);

        // Les firmwares des contrôleurs rejoignent les sources de la veille (aucun nouveau service).
        $counts = array_values(array_filter(
            ParkVulnerabilitySync::installCounts([$name]),
            static fn (array $c): bool => $c['software'] === $name
        ));
        $this->assertSame(
            [['software' => $name, 'version' => '2.72', 'installs' => 1, 'source' => 'firmware']],
            $counts
        );

        // Alias déclaré et résultat de la veille : CVE connue sur ce firmware.
        $canonical = ParkVulnerability::confirm($name, 'cpe:2.3:o:phpunitdemo:ilo' . $this->suffix . '_firmware');
        $this->assertGreaterThan(0, (int) $canonical);
        $cve = 'CVE-2099-7' . random_int(10000, 99999);
        ParkVulnerabilitySync::replaceParkCves((int) $canonical, '2.72', [$cve]);
        $this->assertContains(
            ['itemtype' => Computer::class, 'items_id' => $this->computer, 'entities_id' => $this->entity],
            ParkVulnerabilitySync::machinesFor((int) $canonical, ['2.72']),
            'The server of the controller is the asset of the vulnerability.'
        );

        // Fin de support : produit relié à la main, cycle 2 terminé.
        EndOfLifeStore::save(true, 90);
        $product = 'phpunitdemo-ilo-' . $this->suffix;
        $this->assertTrue(EndOfLifeStore::saveMapping('firmware', $name, $product));
        EndOfLifeStore::applyWrites([[
            'product' => $product,
            'cycles'  => [['cycle' => '2', 'codename' => '', 'eol' => '2020-01-01', 'latest' => '2.99']],
            'error'   => '',
            'success' => true,
        ]], date('Y-m-d H:i:s'));
        EndOfLifeStore::reset();

        $row = $this->row($id);
        $firmware = $row['firmwares'][0];
        $DB->delete(ParkVulnerabilitySync::PARK_CVES_TABLE, ['cve_id' => $cve]);
        $this->assertTrue($firmware['watched']);
        $this->assertSame(1, $firmware['cves']);
        $this->assertSame([$cve], $firmware['top_cves']);
        $this->assertSame('eol', $firmware['eol_status']);
        $this->assertContains(Rules::GAP_FIRMWARE_CVE, $row['gaps']);
        $this->assertContains(Rules::GAP_FIRMWARE_EOL, $row['gaps']);

        // Même état sur la page des fins de support, rattaché au serveur du contrôleur.
        $items = array_values(array_filter(
            EndOfLifeInventory::evaluateVisible(),
            static fn (array $item): bool => $item['name'] === $name
        ));
        $this->assertCount(1, $items);
        $this->assertSame([Computer::class, $this->computer], [$items[0]['itemtype'], $items[0]['items_id']]);
        $this->assertSame('eol', $items[0]['evaluation']['status']);
    }

    public function testControllerAccountsAreReviewedInTheCampaign(): void
    {
        global $DB;

        $controller = $this->controller();
        $campaign = new AccessReview();
        $campaignId = (int) $campaign->add([
            'name' => 'PHPUnit revue BMC ' . $this->suffix, 'entities_id' => $this->entity, 'is_recursive' => 0,
            'frequency' => 'quarterly', 'due_date' => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->assertGreaterThan(0, $campaignId);
        $campaign->getFromDB($campaignId);
        $this->assertSame(0, AccessReviewBmcAccount::snapshot($campaign), 'Draft: nothing copied.');

        $DB->update(AccessReview::getTable(), ['status' => AccessReviewLogic::STATUS_OPEN], ['id' => $campaignId]);
        $campaign->getFromDB($campaignId);
        $this->assertSame(3, AccessReviewBmcAccount::snapshot($campaign));
        $this->assertSame(0, AccessReviewBmcAccount::snapshot($campaign), 'Copied once only.');

        $lines = [];
        foreach (AccessReviewBmcAccount::tabData($campaign)['lines'] as $line) {
            $lines[$line['username']] = $line;
        }
        $this->assertSame('remove', $lines['root']['suggestion_code']);
        $this->assertSame('examine', $lines['alice']['suggestion_code']);
        $this->assertSame('keep', $lines['operateur']['suggestion_code']);
        $this->assertSame($this->computer, (int) $DB->request([
            'FROM' => AccessReviewBmcTables::LINES, 'WHERE' => ['id' => $lines['root']['id']],
        ])->current()['computers_id']);

        $actor = (int) \Session::getLoginUserID();
        $this->assertNotSame('', AccessReviewBmcAccount::decide($lines['root']['id'], 'keep', '', $actor));
        $this->assertNotSame('', AccessReviewBmcAccount::decide($lines['root']['id'], 'modify', 'x', $actor));
        $this->assertSame('', AccessReviewBmcAccount::decide($lines['root']['id'], 'remove', '', $actor));
        $this->assertSame('', AccessReviewBmcAccount::decide($lines['alice']['id'], 'keep', 'Named admin', $actor));
        $data = AccessReviewBmcAccount::tabData($campaign);
        $this->assertSame([1, 2], [$data['pending'], $data['decided']]);

        // Le contrôleur supprimé ensuite ne change pas la revue ; la campagne purgée l'emporte.
        $DB->delete(Tables::CONTROLLERS, ['id' => $controller]);
        $this->assertCount(3, AccessReviewBmcAccount::tabData($campaign)['lines']);
        $campaign->delete(['id' => $campaignId], true);
        $this->assertSame(0, countElementsInTable(AccessReviewBmcTables::LINES, [
            'plugin_grcmanager_accessreviews_id' => $campaignId,
        ]));
    }
}
