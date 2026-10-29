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

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowMatrix;
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #144, phase 3 : vue zone × zone, sur des zones fictives.
 */
final class NetworkFlowMatrixTest extends TestCase
{
    /** @return list<array{id: int, source: string, destination: string, checks: list<string>}> */
    private static function flows(): array
    {
        return [
            ['id' => 1, 'source' => 'SITE_A_WS_IT (192.0.2.0/24)', 'destination' => 'SITE_A_SRV_BDD (198.51.100.0/24)',
                'checks' => []],
            ['id' => 2, 'source' => 'SITE_A_WS_IT (192.0.2.0/24)', 'destination' => 'SITE_A_SRV_BDD (198.51.100.0/24)',
                'checks' => [NetworkFlowRules::CHECK_NO_OWNER]],
            ['id' => 3, 'source' => 'SITE_B_WS_COMPTA', 'destination' => 'SITE_A_SRV_BDD',
                'checks' => [NetworkFlowRules::CHECK_ANY_PORT_INTERNET]],
            ['id' => 4, 'source' => 'SITE_B_WS_COMPTA', 'destination' => 'Internet', 'checks' => []],
        ];
    }

    public function testCellsCountFlowsAndTakeTheWorstCheck(): void
    {
        $matrix = NetworkFlowMatrix::build(self::flows());

        $this->assertSame(4, $matrix['total']);
        $this->assertSame(['SITE_A_WS_IT', 'SITE_B_WS_COMPTA'], $matrix['sources']);
        $this->assertSame('SITE_A_SRV_BDD', $matrix['destinations'][0], 'Zone la plus sollicitée en premier.');
        $cell = $matrix['cells']['SITE_A_WS_IT']['SITE_A_SRV_BDD'];
        $this->assertSame(2, $cell['count'], 'Réseau entre parenthèses ignoré : même zone.');
        $this->assertSame([1, 2], $cell['ids']);
        $this->assertSame(
            NetworkFlowRules::isBlocking(NetworkFlowRules::CHECK_NO_OWNER)
                ? NetworkFlowMatrix::LEVEL_BLOCKING
                : NetworkFlowMatrix::LEVEL_WARNING,
            $cell['level']
        );
        $this->assertSame(NetworkFlowMatrix::LEVEL_OK, $matrix['cells']['SITE_B_WS_COMPTA']['Internet']['level']);
        $this->assertFalse($matrix['truncated']);
    }

    public function testGroupingByPrefixGivesTheSiteMatrix(): void
    {
        $matrix = NetworkFlowMatrix::build(self::flows(), NetworkFlowMatrix::GROUP_PREFIX);

        // « SITE_A_… » et « SITE_B_… » partagent le préfixe « SITE » (premier séparateur).
        $this->assertSame(3, $matrix['cells']['SITE']['SITE']['count'] ?? 0);
        $this->assertSame(1, $matrix['cells']['SITE']['Internet']['count'] ?? 0, 'Internet reste à part.');
        $this->assertSame('Internet', NetworkFlowMatrix::zone('Internet', NetworkFlowMatrix::GROUP_PREFIX));
        $this->assertSame('PARIS', NetworkFlowMatrix::zone('PARIS-SRV (10.0.0.0/8)', NetworkFlowMatrix::GROUP_PREFIX));
        $this->assertSame('—', NetworkFlowMatrix::zone(''));
    }

    public function testTooManyZonesAreTruncated(): void
    {
        $flows = [];
        for ($i = 0; $i <= NetworkFlowMatrix::MAX_ZONES; $i++) {
            $flows[] = ['id' => $i + 1, 'source' => 'Z' . $i, 'destination' => 'Internet', 'checks' => []];
        }
        $matrix = NetworkFlowMatrix::build($flows);

        $this->assertTrue($matrix['truncated']);
        $this->assertCount(NetworkFlowMatrix::MAX_ZONES, $matrix['sources']);
    }
}
