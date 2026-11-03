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

    /**
     * Sauvegarde fictive avec redirections NAT et tunnels IPsec : redirection liée à sa règle
     * (source restreinte), redirection « Pass » sans règle, redirection désactivée, phase 2 vers un
     * réseau qui chevauche une interface, phase 2 d'une phase 1 désactivée, mode transport.
     */
    private const NAT_IPSEC_CONFIG = <<<'XML'
<?xml version="1.0"?>
<pfsense>
  <interfaces>
    <wan><descr><![CDATA[INTERNET]]></descr><ipaddr>203.0.113.2</ipaddr><subnet>29</subnet></wan>
    <lan><descr><![CDATA[SITE_A_SRV]]></descr><ipaddr>192.168.10.254</ipaddr><subnet>24</subnet></lan>
  </interfaces>
  <aliases>
    <alias><name>SRV_MAIL</name><type>host</type><address>192.168.10.25</address></alias>
  </aliases>
  <nat>
    <rule><interface>wan</interface><protocol>tcp</protocol><source><address>198.51.100.7</address></source>
      <destination><network>wanip</network><port>25</port></destination><target>SRV_MAIL</target>
      <local-port>25</local-port><descr>Relais du prestataire</descr>
      <associated-rule-id>nat_0001</associated-rule-id></rule>
    <rule><interface>wan</interface><protocol>tcp</protocol><source><any/></source>
      <destination><network>wanip</network><port>8443</port></destination><target>192.168.10.30</target>
      <local-port>443</local-port><associated-rule-id>pass</associated-rule-id></rule>
    <rule><disabled/><interface>wan</interface><protocol>tcp</protocol><source><any/></source>
      <destination><network>wanip</network><port>22</port></destination><target>192.168.10.40</target>
      <local-port>22</local-port><associated-rule-id>pass</associated-rule-id></rule>
  </nat>
  <ipsec>
    <phase1><ikeid>1</ikeid><pre-shared-key>fictitious-ipsec-psk</pre-shared-key><descr>SITE_B</descr></phase1>
    <phase1><ikeid>2</ikeid><disabled/><descr>OLD_SITE</descr></phase1>
    <phase2><ikeid>1</ikeid><mode>tunnel</mode><localid><type>lan</type></localid>
      <remoteid><type>network</type><address>192.168.20.0</address><netbits>24</netbits></remoteid>
      <descr>SITE_B_SRV</descr></phase2>
    <phase2><ikeid>1</ikeid><mode>tunnel</mode>
      <localid><type>network</type><address>192.168.10.0</address><netbits>24</netbits></localid>
      <remoteid><type>network</type><address>192.168.10.0</address><netbits>25</netbits></remoteid></phase2>
    <phase2><ikeid>2</ikeid><mode>tunnel</mode><localid><type>lan</type></localid>
      <remoteid><type>network</type><address>10.9.0.0</address><netbits>16</netbits></remoteid></phase2>
    <phase2><ikeid>1</ikeid><mode>transport</mode><localid><type>lan</type></localid>
      <remoteid><type>address</type><address>192.0.2.9</address></remoteid></phase2>
  </ipsec>
  <filter>
    <rule><type>pass</type><interface>wan</interface><protocol>tcp</protocol>
      <source><address>198.51.100.7</address></source>
      <destination><address>SRV_MAIL</address><port>25</port></destination>
      <descr>NAT Relais du prestataire</descr><associated-rule-id>nat_0001</associated-rule-id></rule>
    <rule><type>pass</type><interface>enc0</interface>
      <source><network>lan</network></source><destination><any/></destination></rule>
  </filter>
</pfsense>
XML;

    public function testPortForwardsAndIpsecTunnelsBecomeFlows(): void
    {
        $result = NetworkFlowPfsense::parse(self::NAT_IPSEC_CONFIG);
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['nat'], 'Redirection désactivée ignorée.');
        $this->assertSame(2, $result['tunnels'], 'Phase 1 désactivée et mode transport ignorés.');

        $rows = array_column($result['rows'], null, 'name');
        $relay = $rows['NAT Relais du prestataire'];
        $this->assertSame('internet_publication', $relay['flow_type'], 'Règle créée par une redirection NAT.');
        $this->assertSame('198.51.100.7', $relay['source_label']);
        $this->assertSame('SRV_MAIL (192.168.10.25)', $relay['destination_label']);

        $this->assertSame('site_tunnel', $rows['pfSense IPsec : SITE_A_SRV (192.168.10.0/24) → Tous']['flow_type']);

        $pass = $rows['pfSense NAT INTERNET : Internet → 192.168.10.30'];
        $this->assertSame('internet_publication', $pass['flow_type'], 'Redirection « Pass » sans règle de filtrage.');
        $this->assertSame('443', $pass['ports'], 'Port interne de la redirection.');
        $this->assertSame(3, $pass['line'], 'Numérotée après les règles de filtrage.');

        $tunnel = $rows['SITE_B_SRV'];
        $this->assertSame('site_tunnel', $tunnel['flow_type']);
        $this->assertSame('SITE_A_SRV (192.168.10.0/24)', $tunnel['source_label']);
        $this->assertSame('192.168.20.0/24', $tunnel['destination_label']);
        $this->assertSame(['any', 'any'], [$tunnel['protocol'], $tunnel['ports']]);

        $this->assertSame([[
            'tunnel'    => 'IPsec : 192.168.10.0/24 → 192.168.10.0/25',
            'remote'    => '192.168.10.0/25',
            'interface' => 'SITE_A_SRV (192.168.10.0/24)',
        ]], $result['overlaps'], 'Seul le réseau distant qui chevauche une interface est signalé.');

        $this->assertCount(5, $result['rows']);
        $dump = (string) json_encode($result);
        $this->assertStringNotContainsString('fictitious-ipsec-psk', $dump, 'Clé IPsec jamais reprise.');
        $this->assertStringNotContainsString('192.168.10.40', $dump, 'Redirection désactivée non importée.');
        $this->assertStringNotContainsString('10.9.0.0', $dump, 'Tunnel d\'une phase 1 désactivée non importé.');
    }

    public function testRejectsAFileThatIsNotAPfsenseBackup(): void
    {
        $this->assertSame('not_pfsense', NetworkFlowPfsense::parse('nom;source')['errors'][0]['message']);
        $this->assertSame('not_pfsense', NetworkFlowPfsense::parse('<opnsense/>')['errors'][0]['message']);
    }
}
