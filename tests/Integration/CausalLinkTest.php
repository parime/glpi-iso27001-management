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

use GlpiPlugin\Grcmanager\CausalLink as Link;

/**
 * Issue #171 : effet domino entre un incident fictif et des risques fictifs.
 */
final class CausalLinkTest extends GrcmanagerIntegrationTestCase
{
    private function risk(string $title): string
    {
        $id = (int) (new \GlpiPlugin\Grcmanager\Risk())->add([
            'title'       => $title,
            'probability' => 'possible',
            'impact'      => 'high',
            'entities_id' => 0,
        ]);

        return Link::key(\GlpiPlugin\Grcmanager\Risk::class, $id);
    }

    public function testDominoChainCycleAndTab(): void
    {
        $incidentId = (int) (new \PluginGrcmanagerSecurityIncident())->add([
            'name'        => 'PHPUnit hameçonnage réussi',
            'entities_id' => 0,
            'content'     => 'x',
        ]);
        $incident = Link::key(\PluginGrcmanagerSecurityIncident::class, $incidentId);
        $account = $this->risk('PHPUnit compromission de compte');
        $leak = $this->risk('PHPUnit fuite de données');

        $this->assertGreaterThan(0, (int) (new Link())->add(['cause' => $incident, 'effect' => $account]));
        $this->assertGreaterThan(0, (int) (new Link())->add(['cause' => $account, 'effect' => $leak]));
        $this->assertFalse((new Link())->add(['cause' => $account, 'effect' => $leak]), 'Doublon refusé.');
        $this->assertFalse((new Link())->add(['cause' => $leak, 'effect' => $incident]), 'Boucle refusée.');
        $this->assertFalse((new Link())->add(['cause' => $leak, 'effect' => 'Computer:1']), 'Type non reliable.');

        // Onglet de la compromission : une cause, une conséquence, et le graphe autour.
        $risk = new \GlpiPlugin\Grcmanager\Risk();
        $risk->getFromDB((int) explode(':', $account)[1]);
        $this->assertStringContainsString('2', (string) (new Link())->getTabNameForItem($risk));
        ob_start();
        Link::displayTabContentForItem($risk);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('PHPUnit hameçonnage réussi', $html);
        $this->assertStringContainsString('PHPUnit fuite de données', $html);
        $this->assertStringContainsString('cytoscape', $html);

        $elements = Link::cytoscapeElements(
            ['nodes' => [$incident, $account], 'edges' => [[$incident, $account]]],
            $account
        );
        $this->assertStringContainsString('incident', $elements[0]['classes']);
        $this->assertStringContainsString('center', $elements[1]['classes']);
        $this->assertSame($account, $elements[2]['data']['target']);
    }

    public function testHiddenNodeIsCountedWithoutBeingNamed(): void
    {
        $node = Link::describe(\GlpiPlugin\Grcmanager\Risk::class . ':999999999');
        $this->assertFalse($node['visible']);
        $this->assertSame(__('Item not visible', 'grcmanager'), $node['label']);
    }
}
