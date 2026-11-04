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

use GlpiPlugin\Grcmanager\BusinessImpact;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessItem;
use GlpiPlugin\Grcmanager\Install\Installer;

/**
 * Issue #377 : registre des processus métier fictifs, BIA, dépendances GLPI, cloisonnement.
 */
final class BusinessProcessTest extends GrcmanagerIntegrationTestCase
{
    private function process(array $input = []): BusinessProcess
    {
        $process = new BusinessProcess();
        $id = (int) $process->add($input + ['name' => 'Paie DEMO', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $id);
        $process->getFromDB($id);

        return $process;
    }

    public function testBiaObjectivesAndDependencies(): void
    {
        global $DB;

        // Processus « Paie » : RTO 24 h, RPO 4 h, DMIA 72 h ; il dépend de l'application de paie,
        // hébergée sur une VM, elle-même sur un hyperviseur (analyse d'impact de GLPI).
        $process = $this->process(['mtpd_hours' => 72, 'rto_hours' => 24, 'rpo_hours' => 4]);
        $appliance = (int) (new \Appliance())->add(['name' => 'APP-DEMO-PAIE', 'entities_id' => 0]);
        $vm = (int) (new \Computer())->add(['name' => 'VM-DEMO-PAIE', 'entities_id' => 0]);
        $hypervisor = (int) (new \Computer())->add(['name' => 'HV-DEMO-01', 'entities_id' => 0]);
        $relations = [[$hypervisor, 'Computer', $vm, 'Computer'], [$vm, 'Computer', $appliance, 'Appliance']];
        foreach ($relations as [$s, $st, $i, $it]) {
            $DB->insert('glpi_impactrelations', [
                'itemtype_source'   => $st,
                'items_id_source'   => $s,
                'itemtype_impacted' => $it,
                'items_id_impacted' => $i,
            ]);
        }
        $DB->insert(\GlpiPlugin\Grcmanager\AssetClassification::getTable(), [
            'itemtype' => 'Computer', 'items_id' => $vm, 'availability' => 'high', 'rto_hours' => 8, 'rpo_hours' => 1,
        ]);
        $DB->insert(\GlpiPlugin\Grcmanager\AssetClassification::getTable(), [
            'itemtype' => 'Computer', 'items_id' => $hypervisor, 'availability' => 'high',
            'rto_hours' => 48, 'rpo_hours' => 24,
        ]);

        $link = new BusinessProcessItem();
        $linkId = (int) $link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'itemtype' => 'Appliance', 'items_id' => $appliance, 'comment' => 'Application de paie',
        ]);
        $this->assertGreaterThan(0, $linkId);
        // Doublon et type non liable refusés.
        $this->assertFalse($link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'itemtype' => 'Appliance', 'items_id' => $appliance,
        ]));
        $this->assertFalse($link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(), 'itemtype' => 'User', 'items_id' => 2,
        ]));

        BusinessImpact::saveGrid($process, [
            4   => ['financial' => 1, 'legal' => 1, 'reputation' => 1, 'operational' => 2],
            24  => ['financial' => 2, 'legal' => 2, 'reputation' => 2, 'operational' => 3],
            72  => ['financial' => 3, 'legal' => 4, 'reputation' => 3, 'operational' => 4],
            168 => ['financial' => 9, 'legal' => 'x'],
        ]);
        $process->getFromDB($process->getID());
        $this->assertNotEmpty($process->fields['bia_date'], 'BIA datée.');
        $this->assertSame(5, countElementsInTable(BusinessImpact::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
        ]));

        $analysis = BusinessProcess::analyze($process);
        $this->assertSame(24, $analysis['first_unacceptable']);
        // DMIA de 72 h alors que l'impact est grave dès 24 h.
        $this->assertSame(['mtpd_above_impact'], $analysis['issues']);
        $this->assertSame(0, $analysis['grid'][168]['financial'], 'Niveau hors échelle ignoré.');

        $byLabel = array_column($analysis['dependencies'], null, 'label');
        $this->assertSame(0, $byLabel[\Appliance::getTypeName(1) . ' APP-DEMO-PAIE']['depth']);
        $this->assertSame([], $byLabel[\Appliance::getTypeName(1) . ' APP-DEMO-PAIE']['issues']);
        $this->assertSame([], $byLabel[\Computer::getTypeName(1) . ' VM-DEMO-PAIE']['issues']);
        $this->assertSame(2, $byLabel[\Computer::getTypeName(1) . ' HV-DEMO-01']['depth']);
        $this->assertSame(
            ['rto_slower', 'rpo_larger'],
            $byLabel[\Computer::getTypeName(1) . ' HV-DEMO-01']['issues'],
            'Hyperviseur plus lent que le processus qui en dépend.'
        );

        // Onglet sur l'application : le processus qui en dépend.
        $processes = BusinessProcessItem::getProcessesForItem('Appliance', $appliance);
        $this->assertSame(['Paie DEMO'], array_column($processes, 'name'));

        // Export pour le PCA : en-tête, puis le processus avec l'hyperviseur en écart.
        $rows = BusinessProcess::continuityRows();
        $line = array_values(array_filter($rows, static fn (array $row): bool => $row[0] === 'Paie DEMO'))[0];
        $this->assertStringContainsString('APP-DEMO-PAIE', implode(';', $line));
        $this->assertStringContainsString('HV-DEMO-01', implode(';', $line));

        // Purge de l'application : sa dépendance disparaît (hook ITEM_PURGE).
        (new \Appliance())->delete(['id' => $appliance], true);
        $this->assertSame(0, countElementsInTable(BusinessProcessItem::getTable(), ['id' => $linkId]));

        // Purge du processus : sa BIA disparaît.
        $process->delete(['id' => $process->getID()], true);
        $this->assertSame(0, countElementsInTable(BusinessImpact::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
        ]));
    }

    public function testDurationsAreSanitizedAndNameRequired(): void
    {
        $process = $this->process(['rto_hours' => 5, 'rpo_hours' => '336']);
        $this->assertSame(0, (int) $process->fields['rto_hours']);
        $this->assertSame(336, (int) $process->fields['rpo_hours']);
        $this->assertFalse((new BusinessProcess())->add(['name' => '  ', 'entities_id' => 0]));
    }

    public function testUpdateCannotMoveTheProcessToAnotherEntity(): void
    {
        $other = $this->createTestEntity(0, 'BIA DEMO C ' . random_int(1000, 9999));
        $process = $this->process();

        $process->update(['id' => $process->getID(), 'name' => 'Paie DEMO 2', 'entities_id' => $other]);
        $process->getFromDB($process->getID());

        $this->assertSame('Paie DEMO 2', $process->fields['name']);
        $this->assertSame(0, (int) $process->fields['entities_id']);
    }

    public function testOtherEntityIsNeitherShownNorLinkable(): void
    {
        $other = $this->createTestEntity(0, 'BIA DEMO B ' . random_int(1000, 9999));
        $hidden = (int) (new \Computer())->add(['name' => 'SRV-DEMO-B', 'entities_id' => $other]);
        $processB = $this->process(['name' => 'Processus DEMO B', 'entities_id' => $other]);
        $processA = $this->process(['name' => 'Processus DEMO A']);
        foreach ([$processA, $processB] as $process) {
            $this->assertGreaterThan(0, (int) (new BusinessProcessItem())->add([
                'plugin_grcmanager_businessprocesses_id' => $process->getID(),
                'itemtype' => 'Computer', 'items_id' => $hidden,
            ]));
        }

        // La personne perd l'accès à l'entité B.
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        $analysis = BusinessProcess::analyze($processA);
        $this->assertSame([], $analysis['dependencies'], 'Élément de B jamais nommé.');
        $this->assertSame(1, $analysis['hidden']);
        $this->assertSame(
            ['Processus DEMO A'],
            array_column(BusinessProcessItem::getProcessesForItem('Computer', $hidden), 'name'),
            'Processus de B non listé.'
        );
        $this->assertFalse($processB->can($processB->getID(), READ));
        $this->assertFalse((new BusinessProcessItem())->add([
            'plugin_grcmanager_businessprocesses_id' => $processA->getID(),
            'itemtype' => 'Computer', 'items_id' => $hidden,

        ]), 'Élément illisible : lien refusé.');
        $names = array_column(BusinessProcess::continuityRows(), 0);
        $this->assertContains('Processus DEMO A', $names);
        $this->assertNotContains('Processus DEMO B', $names);
    }

    public function testTabsRequireTheContinuityRight(): void
    {
        $process = $this->process();
        $computer = new \Computer();
        $computer->getFromDB((int) $computer->add(['name' => 'PC-DEMO-BIA', 'entities_id' => 0]));
        $saved = $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = 0;
        try {
            $this->assertSame('', (new BusinessProcessItem())->getTabNameForItem($computer));
            // ajax/common.tabs.php appelle directement le contenu : il doit refuser lui aussi.
            ob_start();
            $shown = BusinessProcessItem::displayTabContentForItem($computer);
            $shownImpact = BusinessImpact::displayTabContentForItem($process);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
            $this->assertFalse($shownImpact);
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = $saved;
        }

        ob_start();
        $this->assertTrue(BusinessImpact::displayTabContentForItem($process));
        $this->assertTrue(BusinessProcessItem::displayTabContentForItem($process));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('levels[4][financial]', $html);
        $this->assertStringContainsString('_glpi_csrf_token', $html);
    }
}
