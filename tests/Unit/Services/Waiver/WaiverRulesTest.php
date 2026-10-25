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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Waiver;

use GlpiPlugin\Grcmanager\Services\Waiver\WaiverRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #170 : états, rappels et validation des dérogations.
 */
final class WaiverRulesTest extends TestCase
{
    public function testStateFollowsStatusThenEndDate(): void
    {
        $this->assertSame('requested', WaiverRules::state('requested', '2026-12-31', '2026-10-25'));
        $this->assertSame('rejected', WaiverRules::state('rejected', '2026-01-01', '2026-10-25'));
        $this->assertSame('closed', WaiverRules::state('closed', '2026-01-01', '2026-10-25'));
        $this->assertSame('active', WaiverRules::state('approved', '2026-12-31', '2026-10-25'));
        $this->assertSame('expiring', WaiverRules::state('approved', '2026-11-09', '2026-10-25'), 'J-15.');
        $this->assertSame('active', WaiverRules::state('approved', '2026-11-10', '2026-10-25'), 'J-16.');
        $this->assertSame('expiring', WaiverRules::state('approved', '2026-10-25', '2026-10-25'), 'Fin incluse.');
        $this->assertSame('expired', WaiverRules::state('approved', '2026-10-24', '2026-10-25'));
    }

    public function testReminderAndTicketOnlyOnceAndOnlyWhenApproved(): void
    {
        $this->assertTrue(WaiverRules::reminderDue('approved', '2026-11-01', null, '2026-10-25'));
        $this->assertFalse(WaiverRules::reminderDue('approved', '2026-11-01', '2026-10-20 08:00:00', '2026-10-25'));
        $this->assertFalse(WaiverRules::reminderDue('requested', '2026-11-01', null, '2026-10-25'));
        $this->assertTrue(WaiverRules::ticketDue('approved', '2026-10-24', 0, '2026-10-25'));
        $this->assertFalse(WaiverRules::ticketDue('approved', '2026-10-24', 42, '2026-10-25'));
        $this->assertFalse(WaiverRules::ticketDue('closed', '2026-10-24', 0, '2026-10-25'), 'Close : plus de relance.');
    }

    public function testValidation(): void
    {
        $this->assertSame([], WaiverRules::validate('Serveur ancien', '2026-10-01', '2027-04-01'));
        $this->assertSame(['name_required'], WaiverRules::validate(' ', null, '2027-04-01'));
        $this->assertSame(['date_end_required'], WaiverRules::validate('Sans fin', '2026-10-01', null));
        $this->assertSame(['date_end_before_begin'], WaiverRules::validate('Inversée', '2026-10-01', '2026-09-01'));
    }
}
