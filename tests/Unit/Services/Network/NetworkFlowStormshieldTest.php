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

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowStormshield;
use PHPUnit\Framework\TestCase;

/**
 * Issue #144, phase 3 : export CSV d'une politique Stormshield, structure réelle, noms fictifs.
 */
final class NetworkFlowStormshieldTest extends TestCase
{
    private const HEADER = '#type_slot,#separator_color,#separator_nb_elements,#rule_name,#comment,#state,#action,'
        . '#count,#rate,#set_tos,#inspection,#service,#log_level,#schedule,#route,#no_conn_log,#tos,#ether_proto,'
        . '#ip_proto,#proto,#from_user_type,#from_user_domain,#from_user_method,#from_src,#from_geo,#from_ip_rep,'
        . '#from_host_rep,#from_port,#from_if,#via,#to_dest,#to_geo,#to_ip_rep,#to_host_rep,#to_port,#to_if,'
        . '#nat_before_vpn,#nat_from_target,#nat_from_arp,#nat_from_load_balancing,#nat_from_port,'
        . '#nat_from_port_load_balancing,#nat_to_target,#nat_to_arp,#nat_to_load_balancing,#nat_to_port,'
        . '#nat_to_port_load_balancing,#asq_nohasync,#asq_enforceipsecforward,#asq_enforceipsecreverse,#inbound,'
        . '#ipstate,#qid,#ackqid,#synproxy';

    /**
     * Règle de filtrage : seules les colonnes utiles sont renseignées, aux mêmes positions que
     * l'export (54 colonnes).
     *
     * @param array<string, string> $values
     */
    private static function rule(array $values, string $slot = 'local_filter_slot'): string
    {
        $columns = array_map(static fn ($h) => ltrim($h, '#'), explode(',', self::HEADER));
        $cells = array_fill(0, count($columns), '');
        $values += ['type_slot' => $slot, 'state' => 'on', 'action' => 'pass', 'inspection' => 'firewall',
            'from_port' => 'any', 'via' => 'any'];
        foreach ($values as $name => $value) {
            $cells[array_search($name, $columns, true)] = '"' . str_replace('"', '""', $value) . '"';
        }

        return implode(',', $cells);
    }

    private static function export(): string
    {
        return implode("\n", [
            self::HEADER,
            '"local_filter_slot",c0c0c0,3,"","TRAFIC ENTRANT",' . str_repeat(',', 48),
            self::rule(['rule_name' => 'r1', 'comment' => 'Application web publiée depuis VPS_PROXY',
                'from_src' => 'VPS_PROXY', 'from_if' => 'WAN', 'to_dest' => 'Firewall_WAN', 'to_port' => '8020',
                'nat_to_target' => 'SITE_A_SRV_WEB']),
            self::rule(['rule_name' => 'r2', 'comment' => 'Accès VPN', 'from_src' => 'any', 'from_if' => 'WAN',
                'to_dest' => 'Firewall_WAN', 'to_port' => 'OpenVPN_site', 'nat_to_target' => 'SITE_A_FW_VIP']),
            self::rule(['rule_name' => 'r3', 'comment' => 'Phase 2 depuis le site B', 'from_src' => 'any',
                'via' => 'ipsec', 'to_dest' => 'any', 'to_port' => 'any']),
            '"local_filter_slot",c0c0c0,4,"","TRAFIC SORTANT",' . str_repeat(',', 48),
            self::rule(['rule_name' => 'r4', 'comment' => 'Internet (HTTP, HTTPS, DNS)', 'from_src' => 'SITE_A_FW_VIP',
                'to_dest' => 'any', 'to_port' => 'http,https,dns']),
            self::rule(['rule_name' => 'r5', 'comment' => 'Serveurs de temps', 'ip_proto' => 'udp',
                'from_src' => 'SITE_A_FW_VIP', 'to_dest' => 'any', 'to_port' => 'ntp']),
            self::rule(['rule_name' => 'r6', 'comment' => 'Internet (HTTP, HTTPS, DNS)', 'from_src' => 'SITE_A_FW_VIP',
                'to_dest' => 'any', 'to_port' => 'http,https,dns']),
            self::rule(['rule_name' => 'r7', 'comment' => 'Created on 2026-01-02 10:00:00 by admin (192.0.2.1)',
                'from_src' => 'SITE_A_WS_IT', 'to_dest' => 'SITE_B_SRV,GRP_SITE_B_LANS', 'to_port' => '2222,ssh']),
            self::rule(['rule_name' => 'r8', 'comment' => 'Règle coupée', 'state' => 'off', 'from_src' => 'any',
                'to_dest' => 'any', 'to_port' => 'any']),
            self::rule(['rule_name' => 'r9', 'comment' => 'Bloquer', 'action' => 'block', 'from_src' => 'any',
                'to_dest' => 'any', 'to_port' => 'any']),
            self::rule(['rule_name' => 'n1', 'comment' => 'NAT vers Internet', 'action' => '', 'from_src' => 'LAN',
                'to_dest' => 'any', 'to_port' => 'any'], 'local_nat_slot'),
        ]);
    }

    public function testFilterRulesBecomeFlows(): void
    {
        $result = NetworkFlowStormshield::parse(self::export());

        $this->assertSame(1, $result['merged'], 'Règle identique fusionnée.');
        $names = array_column($result['rows'], 'name');
        $this->assertSame([
            'Application web publiée depuis VPS_PROXY',
            'Phase 2 depuis le site B',
            'Internet (HTTP, HTTPS, DNS)',
            'Serveurs de temps',
            'Stormshield : SITE_A_WS_IT → SITE_B_SRV, GRP_SITE_B_LANS',
        ], $names, 'Séparateurs, règle coupée, blocage et NAT ignorés ; commentaire automatique non repris.');

        [$published, $tunnel, $web, $ntp, $internal] = $result['rows'];
        $this->assertSame('internet_publication', $published['flow_type']);
        $this->assertSame('VPS_PROXY', $published['source_label']);
        $this->assertSame('SITE_A_SRV_WEB', $published['destination_label'], 'Cible de la redirection.');
        $this->assertSame('8020', $published['ports']);

        $this->assertSame('site_tunnel', $tunnel['flow_type']);

        $this->assertSame('internet_outbound', $web['flow_type']);
        $this->assertSame('Internet', $web['destination_label']);
        $this->assertSame('80,443,53', $web['ports'], 'Services prédéfinis traduits en ports.');

        $this->assertSame('udp', $ntp['protocol']);
        $this->assertSame('123', $ntp['ports']);

        $this->assertSame('internal', $internal['flow_type']);
        $this->assertSame('2222,22', $internal['ports']);
    }

    public function testCustomServiceIsReportedInsteadOfGuessed(): void
    {
        $errors = NetworkFlowStormshield::parse(self::export())['errors'];

        $this->assertCount(1, $errors);
        $this->assertSame('unknown_service:openvpn_site', $errors[0]['message']);
        $this->assertSame(4, $errors[0]['line'], 'Numéro de ligne du fichier.');
    }

    public function testRecognisesTheExportAndRejectsAnIncompleteHeader(): void
    {
        $this->assertTrue(NetworkFlowStormshield::isStormshieldExport("\xEF\xBB\xBF" . self::HEADER));
        $this->assertFalse(NetworkFlowStormshield::isStormshieldExport("nom;source;destination\n"));
        $this->assertSame(
            'missing_columns',
            NetworkFlowStormshield::parse("#type_slot,#rule_name\n")['errors'][0]['message']
        );
    }
}
