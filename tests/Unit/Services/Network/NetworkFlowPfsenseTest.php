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

    /**
     * Sauvegarde complète fictive : lien Internet sur « lan » (derrière un routeur), zones nommées,
     * règle « tout sauf les réseaux privés », règle saisie deux fois et secrets dans le fichier.
     */
    private const FULL_CONFIG = <<<'XML'
<?xml version="1.0"?>
<pfsense>
  <system><user><name>admin</name><bcrypt-hash>$2y$12$fictitiousfictitiousfictitious</bcrypt-hash></user></system>
  <interfaces>
    <wan><if>ix1</if><descr><![CDATA[UNUSED]]></descr></wan>
    <lan><descr><![CDATA[UPLINK]]></descr><ipaddr>198.51.100.2</ipaddr><subnet>24</subnet><gateway>GW_UP</gateway></lan>
    <opt1><descr><![CDATA[SITE_A_WS_IT]]></descr><ipaddr>192.168.27.254</ipaddr><subnet>24</subnet></opt1>
    <opt2><descr><![CDATA[SITE_A_SRV_BDD]]></descr><ipaddr>192.168.127.254</ipaddr><subnet>24</subnet></opt2>
  </interfaces>
  <gateways>
    <gateway_item><interface>lan</interface><gateway>198.51.100.1</gateway><name>GW_UP</name></gateway_item>
    <defaultgw4>GW_UP</defaultgw4>
  </gateways>
  <aliases>
    <alias><name>PRIVATE_NETS</name><type>network</type><address>10.0.0.0/8 172.16.0.0/16 192.168.0.0/16</address>
      <detail><![CDATA[Jane Doe||John Doe]]></detail></alias>
  </aliases>
  <ipsec><phase1><pre-shared-key>fictitious-psk-value</pre-shared-key></phase1></ipsec>
  <filter>
    <rule><type>pass</type><interface>opt1</interface>
      <source><network>opt1</network></source><destination><network>opt2</network></destination></rule>
    <rule><type>pass</type><interface>opt1</interface>
      <source><network>opt1</network></source><destination><network>opt2</network></destination></rule>
    <rule><type>pass</type><interface>opt2</interface>
      <source><network>opt2</network></source><destination><address>PRIVATE_NETS</address><not/></destination></rule>
    <rule><type>pass</type><interface>lan</interface><protocol>tcp</protocol>
      <source><any/></source><destination><network>opt2ip</network><port>1433</port></destination></rule>
  </filter>
</pfsense>
XML;

    public function testRealWorldBackupGivesNamedZonesAndTheRightInternetSide(): void
    {
        $result = NetworkFlowPfsense::parse(self::FULL_CONFIG);

        $this->assertSame('lan', $result['outside'], 'Interface de la passerelle par défaut.');
        $this->assertSame('SITE_A_WS_IT', $result['interfaces']['opt1']);
        $this->assertSame(1, $result['merged'], 'Règle saisie deux fois : un seul flux.');
        $this->assertCount(3, $result['rows']);
        [$internal, $outbound, $published] = $result['rows'];

        $this->assertSame('internal', $internal['flow_type']);
        $this->assertSame('SITE_A_WS_IT (192.168.27.0/24)', $internal['source_label']);
        $this->assertSame('SITE_A_SRV_BDD (192.168.127.0/24)', $internal['destination_label']);
        $this->assertStringContainsString('SITE_A_WS_IT', $internal['name']);

        $this->assertSame('internet_outbound', $outbound['flow_type'], '« non réseaux privés » = Internet.');
        $this->assertSame('Internet', $outbound['destination_label']);

        $this->assertSame('internet_publication', $published['flow_type'], 'Règle sur le lien Internet réel.');
        $this->assertSame('Internet', $published['source_label']);
        $this->assertSame('IP SITE_A_SRV_BDD (192.168.127.254)', $published['destination_label']);

        $chosen = NetworkFlowPfsense::parse(self::FULL_CONFIG, 'wan');
        $this->assertSame('internal', $chosen['rows'][2]['flow_type'], 'Interface extérieure choisie à l\'import.');
    }

    public function testSecretsAreReportedButNeverCopied(): void
    {
        $result = NetworkFlowPfsense::parse(self::FULL_CONFIG);
        $this->assertTrue($result['secrets']);
        $dump = json_encode($result);
        foreach (['fictitious-psk-value', 'fictitiousfictitious', 'Jane Doe'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dump, 'Ni secret ni détail d\'alias repris.');
        }
        $this->assertFalse(NetworkFlowPfsense::parse(self::CONFIG)['secrets'], 'Export par zone : rien à signaler.');
        $this->assertFalse(NetworkFlowPfsense::containsSecrets('<pfsense><prv></prv><password/></pfsense>'));
    }

    public function testRejectsAFileThatIsNotAPfsenseBackup(): void
    {
        $this->assertSame('not_pfsense', NetworkFlowPfsense::parse('nom;source')['errors'][0]['message']);
        $this->assertSame('not_pfsense', NetworkFlowPfsense::parse('<opnsense/>')['errors'][0]['message']);
    }
}
