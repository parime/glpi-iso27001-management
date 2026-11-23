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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #859 : règles du coffre-fort (import de métadonnées, écarts), sur des données fictives.
 */
final class PrivilegedVaultRulesTest extends TestCase
{
    public function testSecretColumnsAreRefused(): void
    {
        foreach (
            [
                'login_password', 'Password', 'login_totp', 'notes', 'fields', 'Mot de passe', 'client_secret',
                'api_token', 'passwordHistory', 'private_key',
            ] as $header
        ) {
            self::assertTrue(Rules::isSecretHeader($header), $header);
        }
        foreach (['passwordRevisionDate', 'password_last_changed', 'password_age', 'name', 'login_username'] as $header) {
            self::assertFalse(Rules::isSecretHeader($header), $header);
        }
    }

    public function testBitwardenExportWithPasswordsIsRefusedAsAWhole(): void
    {
        // En-tête de l'export CSV d'une organisation Bitwarden : mots de passe présents.
        $raw = "collections,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp\n"
            . "Serveurs,login,root srv-db01,,,0,ssh://srv-db01,root,motdepasse-fictif,\n";
        $parsed = Rules::parseCsv(Rules::KIND_ENTRIES, $raw);

        self::assertSame(['secret_column'], $parsed['errors']);
        self::assertSame('notes', $parsed['secret']);
        self::assertSame([], $parsed['rows']);
    }

    public function testEntriesMetadataImport(): void
    {
        $raw = "id;name;collectionIds;username;uri;passwordRevisionDate;revisionDate;holders\n"
            . "e1;root srv-db01;c1,c2;root;ssh://user:pw@srv-db01:22/?t=1;2024-01-15T10:00:00.000Z;2025-06-01;"
            . "A@example.test, b@example.test\n"
            . "e2;Compte iDRAC;c1;admin;;;2025-06-01T08:00:00Z;\n"
            . ";;;;;;;\n";
        $parsed = Rules::parseCsv(Rules::KIND_ENTRIES, $raw);

        self::assertSame([], $parsed['errors']);
        self::assertCount(2, $parsed['rows']);
        $first = $parsed['rows'][0];
        self::assertSame('e1', $first['external_id']);
        self::assertSame(['c1', 'c2'], $first['collections']);
        // Adresse sans identifiants ni paramètres.
        self::assertSame('ssh://srv-db01:22/', $first['uri']);
        // Date du mot de passe préférée à celle de l'élément.
        self::assertSame('2024-01-15 10:00:00', $first['date_changed']);
        self::assertSame(['a@example.test', 'b@example.test'], $first['holders']);
        self::assertSame('2025-06-01 08:00:00', $parsed['rows'][1]['date_changed']);
    }

    public function testMembersImportNeedsAnEmail(): void
    {
        self::assertSame(['missing_email'], Rules::parseCsv(Rules::KIND_MEMBERS, "name;role\nX;admin\n")['errors']);
        self::assertSame(['missing_name'], Rules::parseCsv(Rules::KIND_ENTRIES, "username\nroot\n")['errors']);
        $parsed = Rules::parseCsv(
            Rules::KIND_MEMBERS,
            "email,name,type,status,collections,twoFactorEnabled\n"
                . "Admin@example.test,Admin,1,2,c1,false\nuser@example.test,User,user,revoked,,yes\n"
        );
        self::assertCount(2, $parsed['rows']);
        self::assertSame('admin@example.test', $parsed['rows'][0]['email']);
        self::assertSame(Rules::ROLE_ADMIN, $parsed['rows'][0]['role']);
        self::assertSame(Rules::STATUS_ACTIVE, $parsed['rows'][0]['status']);
        self::assertFalse($parsed['rows'][0]['two_factor']);
        self::assertSame(Rules::STATUS_REVOKED, $parsed['rows'][1]['status']);
        self::assertTrue($parsed['rows'][1]['two_factor']);
    }

    public function testRolesAndStatusesOfTheBitwardenApi(): void
    {
        self::assertSame(Rules::ROLE_OWNER, Rules::normalizeRole(0));
        self::assertSame(Rules::ROLE_ADMIN, Rules::normalizeRole(1));
        self::assertSame(Rules::ROLE_USER, Rules::normalizeRole(2));
        self::assertSame(Rules::ROLE_CUSTOM, Rules::normalizeRole(4));
        self::assertSame(Rules::STATUS_INVITED, Rules::normalizeStatus(0));
        self::assertSame(Rules::STATUS_ACTIVE, Rules::normalizeStatus(1));
        self::assertSame(Rules::STATUS_ACTIVE, Rules::normalizeStatus(2));
        self::assertSame(Rules::STATUS_REVOKED, Rules::normalizeStatus(-1));
    }

    public function testDates(): void
    {
        self::assertSame('2024-03-05 00:00:00', Rules::parseDate('05/03/2024'));
        self::assertSame('2024-03-05 00:00:00', Rules::parseDate('2024-03-05'));
        self::assertNull(Rules::parseDate('hier'));
        self::assertNull(Rules::parseDate('1900-01-01'));
        self::assertSame(12, Rules::normalizeRotationMonths('abc'));
        self::assertSame(1, Rules::normalizeRotationMonths(0));
        self::assertSame(60, Rules::normalizeRotationMonths(500));
    }

    public function testEntryGaps(): void
    {
        $now = '2026-06-01 12:00:00';
        self::assertSame([Rules::GAP_NO_DATE], Rules::entryGaps(null, 12, $now, [], []));
        self::assertSame([Rules::GAP_STALE], Rules::entryGaps('2025-05-01 00:00:00', 12, $now, [], []));
        self::assertSame([], Rules::entryGaps('2025-07-01 00:00:00', 12, $now, ['a@example.test'], []));
        self::assertSame(
            [Rules::GAP_DEPARTED_HOLDER],
            Rules::entryGaps('2026-01-01 00:00:00', 12, $now, ['a@example.test'], ['a@example.test' => true])
        );
    }

    public function testMemberGaps(): void
    {
        $known = ['matched' => true, 'departed' => false];
        self::assertSame([], Rules::memberGaps(Rules::ROLE_USER, Rules::STATUS_ACTIVE, null, $known));
        self::assertSame(
            [Rules::GAP_UNKNOWN],
            Rules::memberGaps(Rules::ROLE_USER, Rules::STATUS_ACTIVE, null, ['matched' => false, 'departed' => false])
        );
        self::assertSame(
            [Rules::GAP_DEPARTED, Rules::GAP_ADMIN_NO_2FA],
            Rules::memberGaps(Rules::ROLE_ADMIN, Rules::STATUS_ACTIVE, false, ['matched' => true, 'departed' => true])
        );
        // Révoqué : plus d'accès, aucun écart.
        self::assertSame(
            [],
            Rules::memberGaps(Rules::ROLE_OWNER, Rules::STATUS_REVOKED, false, ['matched' => false, 'departed' => true])
        );
    }

    public function testHoldersFromCollections(): void
    {
        $members = [
            ['email' => 'a@example.test', 'status' => Rules::STATUS_ACTIVE, 'collections' => ['C1']],
            ['email' => 'b@example.test', 'status' => Rules::STATUS_REVOKED, 'collections' => ['c1']],
            ['email' => 'c@example.test', 'status' => Rules::STATUS_INVITED, 'collections' => ['c2']],
        ];
        self::assertSame(
            ['d@example.test', 'a@example.test'],
            Rules::holdersOf(['d@example.test'], ['c1'], $members)
        );
    }

    public function testEquipmentAccountFoundInTheVault(): void
    {
        $entries = [
            ['username' => 'Administrator', 'name' => 'iLO srv-app01', 'uri' => ''],
            ['username' => 'root', 'name' => 'iDRAC', 'uri' => 'https://10.0.0.21'],
        ];
        self::assertTrue(Rules::hasEntry('administrator', ['SRV-APP01', '', ''], $entries));
        self::assertTrue(Rules::hasEntry('root', ['idrac-02', '10.0.0.21', ''], $entries));
        self::assertFalse(Rules::hasEntry('root', ['srv-app01', '', ''], $entries));
        self::assertFalse(Rules::hasEntry('admin', ['', '', ''], $entries));
    }
}
