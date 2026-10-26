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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Network;

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowPfsense;
use PHPUnit\Framework\TestCase;

final class NetworkFlowPfsenseTest extends TestCase
{
    /** Extrait fictif d'une sauvegarde pfSense (config.xml). */
    private const CONFIG = <<<'XML'
<?xml version="1.0"?>
<pfsense>
  <aliases>
    <alias><name>WEB_PORTS</name><type>port</type><address>80 443</address></alias>
    <alias><name>SRV_WEB</name><type>host</type><address>192.0.2.10</address></alias>
  </aliases>
  <filter>
    <rule><type>pass</type><interface>wan</interface><protocol>tcp</protocol>
      <source><any/></source><destination><address>SRV_WEB</address><port>WEB_PORTS</port></destination>
      <descr>Site vitrine</descr></rule>
    <rule><type>pass</type><interface>lan</interface><protocol>tcp/udp</protocol>
      <source><network>lan</network></source><destination><any/></destination></rule>
    <rule><type>block</type><interface>wan</interface><source><any/></source><destination><any/></destination></rule>
    <rule><type>pass</type><disabled/><interface>lan</interface>
      <source><any/></source><destination><any/></destination></rule>
    <rule><type>pass</type><interface>lan</interface><protocol>tcp</protocol>
      <source><network>lan</network></source>
      <destination><network>opt1ip</network><port>8000:8080</port></destination></rule>
    <rule><type>pass</type><interface>lan</interface><protocol>tcp</protocol>
      <source><any/></source><destination><address>10.0.0.5</address><port>MISSING_ALIAS</port></destination></rule>
  </filter>
</pfsense>
XML;

    public function testPassRulesBecomeFlowsWithResolvedAliases(): void
    {
        $result = NetworkFlowPfsense::parse(self::CONFIG);
        $this->assertCount(3, $result['rows'], 'Règles bloquantes et désactivées ignorées.');

        [$web, $outbound, $internal] = $result['rows'];
        $this->assertSame('Site vitrine', $web['name']);
        $this->assertSame('internet_publication', $web['flow_type']);
        $this->assertSame('Internet', $web['source_label']);
        $this->assertSame('SRV_WEB (192.0.2.10)', $web['destination_label']);
        $this->assertSame('80,443', $web['ports']);
        $this->assertSame('', $web['justification'], 'La justification reste à renseigner.');

        $this->assertSame('internet_outbound', $outbound['flow_type']);
        $this->assertSame('Réseau LAN', $outbound['source_label']);
        $this->assertSame('Internet', $outbound['destination_label']);
        $this->assertSame('tcp_udp', $outbound['protocol']);
        $this->assertSame('any', $outbound['ports']);

        $this->assertSame('internal', $internal['flow_type']);
        $this->assertSame('IP OPT1', $internal['destination_label']);
        $this->assertSame('8000-8080', $internal['ports']);

        $this->assertSame([['line' => 6, 'message' => 'invalid_ports']], $result['errors']);
    }

    public function testRejectsAFileThatIsNotAPfsenseBackup(): void
    {
        $this->assertSame('not_pfsense', NetworkFlowPfsense::parse('nom;source')['errors'][0]['message']);
        $this->assertSame('not_pfsense', NetworkFlowPfsense::parse('<opnsense/>')['errors'][0]['message']);
    }
}
