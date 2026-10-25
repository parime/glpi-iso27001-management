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
}
