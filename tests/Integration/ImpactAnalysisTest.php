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

/**
 * Issue #177 : impact d'une panne d'un hyperviseur fictif et point de défaillance unique.
 */
final class ImpactAnalysisTest extends GrcmanagerIntegrationTestCase
{
    public function testHypervisorImpactAndSinglePointRisk(): void
    {
        global $DB;

        $make = static fn (string $name): int => (int) (new \Computer())->add(['name' => $name, 'entities_id' => 0]);
        $hypervisor = $make('HV-DEMO-01');
        $vm = $make('VM-DEMO-ERP');
        $app = $make('APP-DEMO-PAIE');
        foreach ([[$hypervisor, $vm], [$vm, $app]] as [$source, $impacted]) {
            $DB->insert('glpi_impactrelations', [
                'itemtype_source' => 'Computer', 'items_id_source' => $source,
                'itemtype_impacted' => 'Computer', 'items_id_impacted' => $impacted,
            ]);
        }
        $DB->insert(\PluginGrcmanagerAssetClassification::getTable(), [
            'itemtype' => 'Computer', 'items_id' => $vm, 'availability' => 'high',
        ]);

        $computer = new \Computer();
        $computer->getFromDB($hypervisor);
        $analysis = \PluginGrcmanagerImpactAnalysis::analyze($computer);
        $this->assertTrue($analysis['has_relations']);
        $this->assertSame([1, 2], array_column($analysis['downstream'], 'depth'));
        $this->assertSame('high', $analysis['downstream'][0]['availability']);
        $this->assertCount(1, $analysis['spof_for'], 'Seul hyperviseur de la VM critique.');

        $riskId = \PluginGrcmanagerImpactAnalysis::createSinglePointRisk($computer, $analysis['spof_for']);
        $this->assertGreaterThan(0, $riskId);
        $again = \PluginGrcmanagerImpactAnalysis::createSinglePointRisk($computer, $analysis['spof_for']);
        $this->assertSame(0, $again, 'Pas de doublon.');
        $this->assertCount(1, \PluginGrcmanagerRisk::getRisksLinkedToItem('Computer', $hypervisor));

        // Le risque apparaît aussi dans l'analyse de l'hyperviseur.
        $this->assertSame([$riskId], array_column(\PluginGrcmanagerImpactAnalysis::analyze($computer)['risks'], 'id'));

        $sources = array_column(array_column(\PluginGrcmanagerImpactAnalysis::allSinglePoints(), 'source'), 'label');
        // Libellé traduit : la CI tourne en anglais.
        $this->assertContains(\Computer::getTypeName(1) . ' HV-DEMO-01', $sources);
    }

    /**
     * Issue #177, phase 2 : objectifs de reprise, graphe et export pour le plan de continuité.
     */
    public function testRecoveryObjectivesGraphAndContinuityExport(): void
    {
        global $DB;

        $make = static fn (string $name): int => (int) (new \Computer())->add(['name' => $name, 'entities_id' => 0]);
        $storage = $make('SAN-DEMO-RTO');
        $app = $make('APP-DEMO-RTO');
        $DB->insert('glpi_impactrelations', [
            'itemtype_source' => 'Computer', 'items_id_source' => $storage,
            'itemtype_impacted' => 'Computer', 'items_id_impacted' => $app,
        ]);
        $classification = new \PluginGrcmanagerAssetClassification();
        $classification->add(['itemtype' => 'Computer', 'items_id' => $storage, 'rto_hours' => 24]);
        $classification->add([
            'itemtype'     => 'Computer',
            'items_id'     => $app,
            'availability' => 'high',
            'rto_hours'    => 4,
            'rpo_hours'    => '7',
        ]);
        $row = \PluginGrcmanagerAssetClassification::getByItem('Computer', $app);
        $this->assertSame(4, (int) $row['rto_hours']);
        $this->assertSame(0, (int) $row['rpo_hours'], 'Durée hors liste refusée.');

        $computer = new \Computer();
        $computer->getFromDB($storage);
        $analysis = \PluginGrcmanagerImpactAnalysis::analyze($computer);
        $this->assertCount(1, $analysis['recovery'], 'L\'application exige 4 h, le stockage 24 h.');
        $this->assertSame(4, $analysis['recovery'][0]['required']);
        $this->assertSame(24, $analysis['recovery'][0]['actual']);
        $this->assertCount(3, $analysis['graph'], 'Deux nœuds et un lien.');
        $this->assertStringContainsString('center', $analysis['graph'][0]['classes']);

        ob_start();
        \PluginGrcmanagerImpactAnalysis::displayTabContentForItem($computer);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('data-elements=', $html);
        $this->assertStringContainsString('APP-DEMO-RTO', $html);

        $rows = \PluginGrcmanagerImpactAnalysis::continuityRows();
        $label = \Computer::getTypeName(1) . ' APP-DEMO-RTO';
        $this->assertContains($label, array_column($rows, 0));
        $line = $rows[(int) array_search($label, array_column($rows, 0), true)];
        $this->assertSame(\Computer::getTypeName(1) . ' SAN-DEMO-RTO', $line[4], 'Dépend du stockage.');

        // Onglet de classification en Twig, avec les objectifs.
        $appItem = new \Computer();
        $appItem->getFromDB($app);
        ob_start();
        \PluginGrcmanagerAssetClassification::displayTabContentForItem($appItem);
        $tab = (string) ob_get_clean();
        $this->assertStringContainsString('name="rto_hours"', $tab);
        $this->assertStringContainsString('value="4" selected', $tab);
    }
}
