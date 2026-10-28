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

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;

/**
 * Issue #144 : tunnel partenaire dont le réseau chevauche un réseau IP déclaré dans GLPI.
 */
final class NetworkFlowPartnerOverlapTest extends GrcmanagerIntegrationTestCase
{
    public function testGlpiIpNetworksAreUsedForTheOverlapCheck(): void
    {
        $network = new \IPNetwork();
        $networkId = (int) $network->add([
            'name'        => 'PHPUnit réseau du siège',
            'entities_id' => 0,
            'network'     => '10.231.0.0/16',
            'gateway'     => '10.231.0.1',
        ]);
        $flow = new \GlpiPlugin\Grcmanager\NetworkFlow();
        $flowId = 0;
        try {
            $this->assertGreaterThan(0, $networkId);
            $this->assertContains('10.231.0.0/16', \GlpiPlugin\Grcmanager\NetworkFlow::internalNetworks());

            $flowId = (int) $flow->add([
                'name'              => 'PHPUnit tunnel partenaire',
                'flow_type'         => 'partner_tunnel',
                'source_label'      => 'Siège',
                'destination_label' => 'Partenaire 10.231.40.0/24',
                'justification'     => 'Échanges de commandes',
                'users_id'          => (int) \Session::getLoginUserID(),
                'is_active'         => 1,
            ]);
            $flow->getFromDB($flowId);
            $this->assertContains(NetworkFlowRules::CHECK_PARTNER_OVERLAP, $flow->getChecks());
        } finally {
            if ($flowId > 0) {
                $flow->delete(['id' => $flowId], true);
            }
            $network->delete(['id' => $networkId], true);
        }
    }
}
