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

use GlpiPlugin\Grcmanager\Services\Risk\InfrastructureRiskCatalog;

/**
 * Issue #178 : import de risques types dans le registre, sans doublon.
 */
final class RiskCatalogTest extends GrcmanagerIntegrationTestCase
{
    public function testEveryKeyHasTexts(): void
    {
        $entries = \PluginGrcmanagerRiskCatalog::getEntries();
        $this->assertSame(InfrastructureRiskCatalog::keys(), array_keys($entries));
        foreach ($entries as $entry) {
            $this->assertNotSame('', $entry['title']);
            $this->assertNotEmpty($entry['questions']);
        }
    }

    public function testImportCreatesRiskWithControlsOnce(): void
    {
        global $DB;

        $DB->delete(
            \PluginGrcmanagerRiskCatalog::IMPORTS_TABLE,
            ['catalog_key' => ['spof_power', 'backups_same_site']]
        );

        $created = \PluginGrcmanagerRiskCatalog::import(['spof_power', 'backups_same_site', 'inconnu']);
        $this->assertCount(2, $created);

        $risk = new \GlpiPlugin\Grcmanager\Risk();
        $this->assertTrue($risk->getFromDB($created[0]));
        $this->assertSame('physical', $risk->fields['category']);
        $this->assertSame('identified', $risk->fields['status']);
        $this->assertGreaterThan(0, (float) $risk->fields['computed_score'], 'Score calculé comme au formulaire.');
        $this->assertStringContainsString('- ', (string) $risk->fields['description'], 'Questions d\'aide reprises.');

        $codes = [];
        foreach (
            $DB->request([
                'SELECT'     => ['c.code'],
                'FROM'       => 'glpi_plugin_grcmanager_controls_risks AS l',
                'INNER JOIN' => [
                    'glpi_plugin_grcmanager_controls AS c' => [
                        'FKEY' => ['l' => 'plugin_grcmanager_controls_id', 'c' => 'id'],
                    ],
                ],
                'WHERE'      => ['l.plugin_grcmanager_risks_id' => $created[0]],
            ]) as $row
        ) {
            $codes[] = $row['code'];
        }
        sort($codes);
        $expected = InfrastructureRiskCatalog::controls('spof_power');
        sort($expected);
        $this->assertSame($expected, $codes);

        $this->assertSame([], \PluginGrcmanagerRiskCatalog::import(['spof_power']), 'Déjà dans le registre.');
        $this->assertSame($created[0], \PluginGrcmanagerRiskCatalog::importedRisks()['spof_power']);

        // Le risque supprimé du registre peut être réimporté.
        $risk->delete(['id' => $created[0]], true);
        $this->assertCount(1, \PluginGrcmanagerRiskCatalog::import(['spof_power']));
    }
}
