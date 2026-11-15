<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-iso27001-management
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\CalendarToken;

/**
 * Issue #664 : jeton dédié au lien d'abonnement du calendrier (aléatoire, haché, à durée limitée,
 * révocable) ; le jeton personnel GLPI n'ouvre plus le flux.
 */
final class CalendarTokenTest extends GrcmanagerIntegrationTestCase
{
    public function testAValidTokenIdentifiesItsOwnerAndIsStoredHashed(): void
    {
        global $DB;

        $owner = $this->createTestUser('Abonne', 'Calendrier');
        $token = CalendarToken::generate($owner);

        $this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', $token, '32 octets aléatoires en hexadécimal.');
        $this->assertSame($owner, CalendarToken::findUser($token));

        $row = $DB->request(['FROM' => CalendarToken::TABLE, 'WHERE' => ['users_id' => $owner]])->current();
        [, $secret] = explode('.', $token);
        $this->assertSame(hash('sha256', $secret), $row['token_hash'], 'Seule l\'empreinte est conservée.');
        $this->assertStringNotContainsString($secret, json_encode($row), 'Jamais le secret en clair.');
        $this->assertNotEmpty($row['date_last_use'], 'Dernière lecture notée.');

        $status = CalendarToken::status($owner);
        $this->assertIsArray($status);
        $this->assertFalse($status['expired']);
        $this->assertSame(
            date('Y-m-d', strtotime('+' . CalendarToken::DEFAULT_LIFETIME_DAYS . ' days')),
            substr($status['date_expiration'], 0, 10),
            'Expiration à la durée de vie configurée.'
        );
    }

    public function testATamperedOrUnknownTokenIsRefused(): void
    {
        $owner = $this->createTestUser('Abonne', 'Altere');
        $token = CalendarToken::generate($owner);
        [$id, $secret] = explode('.', $token);

        $this->assertNull(CalendarToken::findUser($id . '.' . strrev($secret)));
        $this->assertNull(CalendarToken::findUser('999999999.' . $secret));
        $this->assertNull(CalendarToken::findUser($secret));
        $this->assertNull(CalendarToken::findUser(''));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        global $DB;

        $owner = $this->createTestUser('Abonne', 'Expire');
        $token = CalendarToken::generate($owner);
        $DB->update(
            CalendarToken::TABLE,
            ['date_expiration' => date('Y-m-d H:i:s', strtotime('-1 minute'))],
            ['users_id' => $owner]
        );

        $this->assertNull(CalendarToken::findUser($token));
        $this->assertTrue(CalendarToken::status($owner)['expired'] ?? false);
    }

    public function testARevokedOrRegeneratedTokenIsRefused(): void
    {
        $owner = $this->createTestUser('Abonne', 'Revoque');
        $first = CalendarToken::generate($owner);
        $second = CalendarToken::generate($owner);

        $this->assertNull(CalendarToken::findUser($first), 'Régénérer invalide l\'ancien lien.');
        $this->assertSame($owner, CalendarToken::findUser($second));

        $this->assertTrue(CalendarToken::revoke($owner));
        $this->assertNull(CalendarToken::findUser($second));
        $this->assertNull(CalendarToken::status($owner));
        $this->assertFalse(CalendarToken::revoke($owner), 'Rien à révoquer.');
    }

    public function testThePersonalGlpiTokenNoLongerOpensTheFeed(): void
    {
        $user = new \User();
        $user->getFromDB(2);
        $personal = (string) $user->getAuthToken('personal_token');
        $this->assertNotSame('', $personal);
        CalendarToken::generate(2);

        $this->assertNull(CalendarToken::findUser($personal));
    }

    public function testLifetimeIsConfigurableWithinBounds(): void
    {
        $owner = $this->createTestUser('Abonne', 'Duree');
        try {
            CalendarToken::saveLifetime('30');
            $this->assertSame(30, CalendarToken::lifetimeDays());
            CalendarToken::generate($owner);
            $this->assertSame(
                date('Y-m-d', strtotime('+30 days')),
                substr((string) CalendarToken::status($owner)['date_expiration'], 0, 10)
            );

            CalendarToken::saveLifetime('0');
            $this->assertSame(CalendarToken::DEFAULT_LIFETIME_DAYS, CalendarToken::lifetimeDays());
            CalendarToken::saveLifetime('abc');
            $this->assertSame(CalendarToken::DEFAULT_LIFETIME_DAYS, CalendarToken::lifetimeDays());
            $this->assertSame(CalendarToken::MAX_LIFETIME_DAYS, CalendarToken::normalizeLifetime(CalendarToken::MAX_LIFETIME_DAYS));
        } finally {
            CalendarToken::saveLifetime(CalendarToken::DEFAULT_LIFETIME_DAYS);
        }
    }
}
