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
 * Issue #199 : badges de la matrice des flux rendus en Twig, texte échappé.
 */
final class NetworkFlowBadgesTest extends GrcmanagerIntegrationTestCase
{
    public function testBadgesAreRenderedAndEscaped(): void
    {
        $type = \PluginGrcmanagerNetworkFlow::typeBadge('admin_access');
        $this->assertStringContainsString('bg-red-lt', $type);
        $this->assertStringContainsString('ti-shield-lock', $type);

        $this->assertStringContainsString('bg-green-lt', \PluginGrcmanagerNetworkFlow::checksHtml([]));
        $checks = \PluginGrcmanagerNetworkFlow::checksHtml([NetworkFlowRules::CHECK_NO_OWNER]);
        $this->assertStringContainsString('bg-yellow-lt', $checks);

        $endpoint = \PluginGrcmanagerNetworkFlow::endpointHtml('', 0, '<b>Site</b> & co');
        $this->assertSame('&lt;b&gt;Site&lt;/b&gt; &amp; co', $endpoint);

        $computer = new \Computer();
        $id = (int) $computer->add(['name' => 'PHPUnit serveur web', 'entities_id' => 0]);
        try {
            $linked = \PluginGrcmanagerNetworkFlow::endpointHtml('Computer', $id, 'DMZ');
            $this->assertStringContainsString(\Computer::getFormURLWithID($id), $linked);
            $this->assertStringContainsString('PHPUnit serveur web</a> — DMZ', $linked);
        } finally {
            $computer->delete(['id' => $id], true);
        }
    }
}
