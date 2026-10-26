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
 * Issue #144, phase 2 : import d'une sauvegarde pfSense dans la matrice des flux.
 */
final class NetworkFlowPfsenseImportTest extends GrcmanagerIntegrationTestCase
{
    private const CONFIG = '<?xml version="1.0"?><pfsense><filter>'
        . '<rule><type>pass</type><interface>wan</interface><protocol>tcp</protocol><source><any/></source>'
        . '<destination><address>203.0.113.20</address><port>3389</port></destination>'
        . '<descr>PHPUnit RDP prestataire</descr></rule>'
        . '</filter></pfsense>';

    public function testSimulateThenImportWithoutDuplicates(): void
    {
        global $DB;

        $before = countElementsInTable(\PluginGrcmanagerNetworkFlow::getTable());
        try {
            $simulation = \PluginGrcmanagerNetworkFlow::importCsv(self::CONFIG, true);
            $this->assertSame(1, $simulation['created']);
            $this->assertSame($before, countElementsInTable(\PluginGrcmanagerNetworkFlow::getTable()));

            $import = \PluginGrcmanagerNetworkFlow::importCsv(self::CONFIG, false);
            $this->assertSame(1, $import['created']);
            $flow = new \PluginGrcmanagerNetworkFlow();
            $this->assertTrue($flow->getFromDBByCrit(['name' => 'PHPUnit RDP prestataire']));
            $this->assertSame('internet_publication', $flow->fields['flow_type']);
            $this->assertContains('admin_exposed', $flow->getChecks(), 'RDP publié sur Internet signalé.');

            $again = \PluginGrcmanagerNetworkFlow::importCsv(self::CONFIG, false);
            $this->assertSame(0, $again['created']);
            $this->assertSame(1, $again['skipped']);
        } finally {
            $DB->delete(\PluginGrcmanagerNetworkFlow::getTable(), ['name' => 'PHPUnit RDP prestataire']);
        }
    }

    public function testXmlThatIsNotPfsenseIsReported(): void
    {
        $result = \PluginGrcmanagerNetworkFlow::importCsv('<config><rule/></config>', true);
        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors']);
    }
}
