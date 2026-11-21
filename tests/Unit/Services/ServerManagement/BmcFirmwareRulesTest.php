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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\ServerManagement;

use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcAccountReviewRules as Review;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcFirmwareRules as Firmware;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #843, lot 2 : firmwares des contrôleurs (désignation, version, écarts) et suggestion de la
 * revue des comptes (sans GLPI ni réseau).
 */
final class BmcFirmwareRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function versions(): iterable
    {
        yield 'HPE iLO' => ['iLO 5 v2.72', '2.72'];
        yield 'BIOS HPE daté' => ['U30 v2.72 (03/22/2023)', '2.72'];
        yield 'date après la version' => ['2.72 Feb 09 2022', '2.72'];
        yield 'Dell iDRAC' => ['6.10.30.00', '6.10.30.00'];
        yield 'préfixe v' => ['v1.4', '1.4'];
        yield 'sans numéro pointé' => ['A12', 'A12'];
        yield 'vide' => ['', ''];
    }

    #[DataProvider('versions')]
    public function testVersion(string $raw, string $expected): void
    {
        $this->assertSame($expected, Firmware::version($raw));
    }

    public function testItemsNameTheFirmwareLikeGlpi(): void
    {
        $this->assertSame([
            ['part' => Firmware::PART_CONTROLLER, 'name' => 'HPE iLO 5', 'version' => '2.72'],
            ['part' => Firmware::PART_BIOS, 'name' => 'HPE ProLiant DL380 Gen10 BIOS', 'version' => '2.72'],
        ], Firmware::items([
            'vendor' => 'HPE', 'manager_model' => 'iLO 5', 'firmware_version' => 'iLO 5 v2.72',
            'system_model' => 'ProLiant DL380 Gen10', 'bios_version' => 'U30 v2.72 (03/22/2023)',
        ]));
        $this->assertSame(
            [['part' => Firmware::PART_CONTROLLER, 'name' => 'Dell iDRAC9', 'version' => '6.10.30.00']],
            Firmware::items([
                'vendor' => 'Dell', 'manager_model' => 'Dell iDRAC9', 'firmware_version' => '6.10.30.00',
                'system_model' => '', 'bios_version' => '2.19.1',
            ]),
            'Vendor not repeated; BIOS without model ignored.'
        );
        $this->assertSame([], Firmware::items(['manager_model' => 'iLO 5', 'firmware_version' => '']));
    }

    public function testGaps(): void
    {
        $this->assertSame([], Firmware::gaps([['cves' => 0, 'priority' => 0, 'eol_status' => 'supported']]));
        $this->assertSame([Rules::GAP_FIRMWARE_PRIORITY_CVE, Rules::GAP_FIRMWARE_EOL], Firmware::gaps([
            ['cves' => 3, 'priority' => 0, 'eol_status' => 'soon'],
            ['cves' => 1, 'priority' => 1, 'eol_status' => 'eol'],
        ]));
        $this->assertSame(
            [Rules::GAP_FIRMWARE_CVE, Rules::GAP_FIRMWARE_EOL_SOON],
            Firmware::gaps([['cves' => 2, 'priority' => 0, 'eol_status' => 'soon']])
        );
        $gaps = Rules::gaps(['date_read' => '2026-01-01 00:00:00'], [], true, true, '2026-01-02', [
            Rules::GAP_FIRMWARE_EOL, 'unknown_gap',
        ]);
        $this->assertSame([Rules::GAP_FIRMWARE_EOL], $gaps, 'Extra gaps kept in the order of GAPS.');
        $this->assertTrue(Rules::hasHighGap([Rules::GAP_FIRMWARE_PRIORITY_CVE]));
    }

    public function testAccountSuggestion(): void
    {
        $this->assertSame(
            ['suggestion' => 'remove', 'reasons' => [Review::R_DEFAULT, Review::R_ADMIN]],
            Review::suggest('ADMIN', 'Administrator', 1, 0)
        );
        $this->assertSame(
            ['suggestion' => 'remove', 'reasons' => [Review::R_DISABLED]],
            Review::suggest('ancien', 'ReadOnly', 0, 0)
        );
        $this->assertSame(
            ['suggestion' => 'examine', 'reasons' => [Review::R_LOCKED, Review::R_ADMIN]],
            Review::suggest('alice', 'Administrator', 1, 1)
        );
        $this->assertSame(
            ['suggestion' => 'keep', 'reasons' => [Review::R_NO_SIGNAL]],
            Review::suggest('operateur', 'Operator', 1, 0)
        );
        $this->assertTrue(Review::isDecision('remove'));
        $this->assertFalse(Review::isDecision('modify'));
    }
}
