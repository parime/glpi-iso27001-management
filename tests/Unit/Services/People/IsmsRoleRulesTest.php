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

namespace GlpiPlugin\Grcmanager\Tests\Services\People;

use GlpiPlugin\Grcmanager\Services\People\IsmsRoleRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1054 : rôles du SMSI, règles sans base (adresse générique, reprise de l'ancien champ DPO).
 */
final class IsmsRoleRulesTest extends TestCase
{
    public function testEmailIsLowercasedEmptyOrRefused(): void
    {
        $this->assertSame('dpo@example.org', Rules::normalizeEmail('  DPO@Example.org '));
        $this->assertSame('', Rules::normalizeEmail('   '));
        $this->assertSame('', Rules::normalizeEmail(null));
        $this->assertNull(Rules::normalizeEmail('not an address'));
        $this->assertNull(Rules::normalizeEmail('dpo@'));
        $this->assertNull(Rules::normalizeEmail(['dpo@example.org']));
        $this->assertNull(Rules::normalizeEmail(str_repeat('a', 250) . '@example.org'));
    }

    public function testLegacyAddressOnlyBecomesTheGenericAddress(): void
    {
        $this->assertSame(['email' => 'dpo@example.org', 'note' => ''], Rules::splitLegacyContact(' dpo@example.org '));
        $this->assertSame(
            ['email' => 'dpo@example.org', 'note' => ''],
            Rules::splitLegacyContact('mailto:DPO@example.org')
        );
    }

    public function testLegacyNameAndAddressAreSplit(): void
    {
        $this->assertSame(
            ['email' => 'dpo@example.org', 'note' => 'Alice Martin'],
            Rules::splitLegacyContact('Alice Martin, dpo@example.org')
        );
        $this->assertSame(
            ['email' => 'dpo@example.org', 'note' => 'Alice Martin'],
            Rules::splitLegacyContact('Alice Martin <dpo@example.org>')
        );
        $this->assertSame(
            ['email' => 'dpo@example.org', 'note' => 'Alice Martin - 01 23 45 67 89'],
            Rules::splitLegacyContact('Alice Martin - 01 23 45 67 89 (dpo@example.org)')
        );
    }

    public function testLegacyBracketsThatAreNotAroundTheAddressAreKept(): void
    {
        $this->assertSame(
            ['email' => 'dpo@example.org', 'note' => 'Alice Martin (cabinet externe)'],
            Rules::splitLegacyContact('Alice Martin (cabinet externe), dpo@example.org')
        );
    }

    public function testLegacyTextWithoutAddressIsKeptAsANote(): void
    {
        $this->assertSame(
            ['email' => '', 'note' => 'Cabinet externe, 01 23 45 67 89'],
            Rules::splitLegacyContact("  Cabinet externe,\n 01 23 45 67 89 ")
        );
        $this->assertSame(['email' => '', 'note' => 'x'], Rules::splitLegacyContact('<b>x</b>'));
        $this->assertSame(['email' => '', 'note' => ''], Rules::splitLegacyContact(''));
        $this->assertSame(255, mb_strlen(Rules::splitLegacyContact(str_repeat('a', 300))['note']));
    }

    public function testContactTextShowsGroupMembersThenAddressOrTheNote(): void
    {
        $this->assertSame(
            'DPO (Alice Martin, Bob Durand), dpo@example.org',
            Rules::contactText('DPO', ['Alice Martin', 'Bob Durand'], 'dpo@example.org', 'ancien texte')
        );
        $this->assertSame('DPO', Rules::contactText('DPO', [], '', 'ancien texte'));
        $this->assertSame('dpo@example.org', Rules::contactText('', [], 'dpo@example.org', 'ancien texte'));
        $this->assertSame('ancien texte', Rules::contactText('', [], '', 'ancien texte'));
    }
}
