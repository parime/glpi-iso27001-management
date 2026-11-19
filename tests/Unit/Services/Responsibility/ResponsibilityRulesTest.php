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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Responsibility;

use GlpiPlugin\Grcmanager\Services\Responsibility\DefaultActivities;
use GlpiPlugin\Grcmanager\Services\Responsibility\ResponsibilityRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #612 : matrice des responsabilités du SMSI, sur une matrice fictive.
 */
final class ResponsibilityRulesTest extends TestCase
{
    /**
     * @return array{itemtype: string, items_id: int, role: string}
     */
    private function row(string $itemtype, int $id, string $role): array
    {
        return ['itemtype' => $itemtype, 'items_id' => $id, 'role' => $role];
    }

    public function testRolesPrincipalsAndCodesAreNormalized(): void
    {
        $this->assertSame(Rules::ROLE_ACCOUNTABLE, Rules::normalizeRole('accountable'));
        $this->assertNull(Rules::normalizeRole('owner'));
        $this->assertNull(Rules::normalizeRole(['accountable']));
        $this->assertSame('Group', Rules::normalizePrincipal('Group'));
        $this->assertNull(Rules::normalizePrincipal('Supplier'));
        $this->assertSame(
            'A.5.3,A.5.15,A.8.13',
            Rules::normalizeCodes(['A.8.13', 'A.5.15', 'A.5.3', 'A.5.3', '<b>', 'B.1'])
        );
        $this->assertSame('A.5.18', Rules::normalizeCodes(' A.5.18 ,x'));
        $this->assertSame([], Rules::splitCodes(''));
        $this->assertSame(['A.5.1', 'A.5.9'], Rules::splitCodes('A.5.9,A.5.1'));
    }

    public function testOnlyOneAccountablePerActivity(): void
    {
        $rows = [$this->row('User', 7, Rules::ROLE_ACCOUNTABLE)];
        $this->assertSame('accountable_taken', Rules::checkNew($rows, $this->row('Group', 3, Rules::ROLE_ACCOUNTABLE)));
        $this->assertSame('duplicate', Rules::checkNew($rows, $this->row('User', 7, Rules::ROLE_ACCOUNTABLE)));
        $this->assertSame('ok', Rules::checkNew($rows, $this->row('User', 7, Rules::ROLE_INFORMED)));
    }

    public function testMissingAccountableAndResponsibleAreReported(): void
    {
        $types = array_column(Rules::alerts(['is_sensitive' => 0], [], [], []), 'type');
        $this->assertSame([Rules::ALERT_NO_ACCOUNTABLE, Rules::ALERT_NO_RESPONSIBLE], $types);

        $alerts = Rules::alerts([], [
            $this->row('User', 1, Rules::ROLE_ACCOUNTABLE),
            $this->row('User', 2, Rules::ROLE_ACCOUNTABLE),
            $this->row('User', 3, Rules::ROLE_RESPONSIBLE),
        ], [], [1 => true, 2 => true, 3 => true]);
        $this->assertSame([Rules::ALERT_SEVERAL_ACCOUNTABLE], array_column($alerts, 'type'));
        $this->assertSame('high', $alerts[0]['severity']);
    }

    public function testSegregationOfDutiesOnSensitiveActivitiesOnly(): void
    {
        // Alice (5) administre les sauvegardes avec son groupe (10) et approuve aussi l'activité.
        $rows = [
            $this->row('Group', 10, Rules::ROLE_RESPONSIBLE),
            $this->row('User', 5, Rules::ROLE_ACCOUNTABLE),
        ];
        $members = [10 => [5, 6]];
        $active = [5 => true, 6 => true];
        $this->assertSame([], Rules::alerts(['is_sensitive' => 0], $rows, $members, $active));

        $alerts = Rules::alerts(['is_sensitive' => 1], $rows, $members, $active);
        $this->assertSame([Rules::ALERT_SEGREGATION], array_column($alerts, 'type'));
        $this->assertSame([5], $alerts[0]['users']);

        // Même groupe qui réalise et approuve, même vide.
        $conflicts = Rules::segregationConflicts([
            $this->row('Group', 11, Rules::ROLE_RESPONSIBLE),
            $this->row('Group', 11, Rules::ROLE_ACCOUNTABLE),
        ], []);
        $this->assertSame(['users' => [], 'groups' => [11]], $conflicts);

        // Consulté ou informé : jamais un conflit.
        $this->assertSame(['users' => [], 'groups' => []], Rules::segregationConflicts([
            $this->row('User', 5, Rules::ROLE_RESPONSIBLE),
            $this->row('User', 5, Rules::ROLE_CONSULTED),
            $this->row('User', 6, Rules::ROLE_ACCOUNTABLE),
        ], []));
    }

    public function testEmptyGroupsAndInactiveUsersAreReportedOnce(): void
    {
        $alerts = Rules::alerts([], [
            $this->row('Group', 20, Rules::ROLE_RESPONSIBLE),
            $this->row('Group', 20, Rules::ROLE_INFORMED),
            $this->row('User', 8, Rules::ROLE_ACCOUNTABLE),
        ], [], []);
        $this->assertSame([Rules::ALERT_EMPTY_GROUP, Rules::ALERT_INACTIVE_USER], array_column($alerts, 'type'));
        $this->assertSame(20, $alerts[0]['items_id']);
        $this->assertSame(8, $alerts[1]['items_id']);
    }

    public function testDefaultCatalogUsesValidControlCodes(): void
    {
        $this->assertTrue(DefaultActivities::exists('access_review'));
        $this->assertFalse(DefaultActivities::exists('bogus'));
        foreach (DefaultActivities::CATALOG as $key => $entry) {
            $this->assertSame(implode(',', $entry['codes']), Rules::normalizeCodes($entry['codes']), $key);
        }
    }
}
