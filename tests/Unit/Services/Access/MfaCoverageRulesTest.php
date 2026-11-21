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

use GlpiPlugin\Grcmanager\Services\Access\MfaCoverageRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #852 : couverture MFA, logique pure sur des comptes et des exports fictifs.
 */
final class MfaCoverageRulesTest extends TestCase
{
    public function testMethodClasses(): void
    {
        foreach (['microsoftAuthenticatorPush', 'fido2', 'windowsHelloForBusiness', 'Okta Verify', 'totp',
            'softwareOneTimePasscode', 'Google prompt', 'passKeyDeviceBound', 'glpi_totp', 'Phone app'] as $strong) {
            $this->assertSame(R::METHOD_STRONG, R::methodClass($strong), $strong);
        }
        foreach (['sms', 'mobilePhone', 'alternateMobilePhone', 'officePhone', 'voice', 'email', 'Okta SMS'] as $weak) {
            $this->assertSame(R::METHOD_WEAK, R::methodClass($weak), $weak);
        }
        foreach (['password', 'None', ''] as $ignored) {
            $this->assertSame(R::METHOD_IGNORED, R::methodClass($ignored), $ignored);
        }
    }

    public function testSplitMethods(): void
    {
        $this->assertSame(['mobilePhone', 'microsoftAuthenticatorPush'], R::splitMethods('[mobilePhone,microsoftAuthenticatorPush]'));
        $this->assertSame(['Okta Verify', 'SMS'], R::splitMethods('Okta Verify; SMS; sms'));
        $this->assertSame([], R::splitMethods('password'));
    }

    public function testLevel(): void
    {
        $this->assertSame(R::LEVEL_STRONG, R::level(['sms', 'fido2']));
        $this->assertSame(R::LEVEL_WEAK, R::level(['sms', 'voice']));
        $this->assertSame(R::LEVEL_NONE, R::level([]));
        $this->assertSame(R::LEVEL_STRONG, R::level([], true), 'MFA inscrit sans détail de la méthode.');
    }

    public function testGaps(): void
    {
        $account = ['level' => R::LEVEL_NONE, 'is_admin' => true, 'is_remote' => true, 'is_excluded' => false, 'reason' => ''];
        $this->assertSame([R::GAP_ADMIN_NO_MFA, R::GAP_REMOTE_NO_MFA], R::gaps($account));
        $this->assertSame([R::GAP_WEAK_ONLY], R::gaps(['level' => R::LEVEL_WEAK] + $account));
        $this->assertSame([], R::gaps(['level' => R::LEVEL_STRONG] + $account));
        $this->assertSame([R::GAP_EXCLUDED_NO_REASON], R::gaps(['is_excluded' => true] + $account));
        $this->assertSame([], R::gaps(['is_excluded' => true, 'reason' => 'Compte de service'] + $account));
        $this->assertSame([], R::gaps(['is_admin' => false, 'is_remote' => false] + $account), 'Simple compte sans MFA : compté dans le taux, pas un écart.');
    }

    public function testSummary(): void
    {
        $summary = R::summary([
            ['level' => R::LEVEL_STRONG, 'is_excluded' => false, 'reason' => ''],
            ['level' => R::LEVEL_WEAK, 'is_excluded' => false, 'reason' => ''],
            ['level' => R::LEVEL_NONE, 'is_excluded' => false, 'reason' => ''],
            ['level' => R::LEVEL_NONE, 'is_excluded' => true, 'reason' => 'Service'],
        ]);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['protected']);
        $this->assertSame(1, $summary['excluded']);
        $this->assertSame(66, $summary['rate']);
        $this->assertNull(R::summary([])['rate']);
    }

    public function testParseEntraExport(): void
    {
        $csv = "\xEF\xBB\xBFuserPrincipalName,userDisplayName,isAdmin,isMfaRegistered,methodsRegistered\n"
            . "alice.demo@exemple.test,Alice Demo,True,True,\"microsoftAuthenticatorPush,mobilePhone\"\n"
            . "bob.demo@exemple.test,Bob Demo,False,False,\n";
        $parsed = R::parseCsv($csv);
        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame('alice.demo@exemple.test', $parsed['rows'][0]['account']);
        $this->assertSame('alice.demo@exemple.test', $parsed['rows'][0]['email']);
        $this->assertSame(['microsoftAuthenticatorPush', 'mobilePhone'], $parsed['rows'][0]['methods']);
        $this->assertTrue($parsed['rows'][0]['admin']);
        $this->assertFalse($parsed['rows'][1]['mfa']);
    }

    public function testParseGenericExport(): void
    {
        $csv = "login;methods;remote_access;excluded;exclusion_reason\n"
            . "svc-demo;;non;oui;Compte de service sans session interactive\n"
            . "carol.demo;sms;oui;;\n";
        $parsed = R::parseCsv($csv);
        $this->assertSame([], $parsed['errors']);
        $this->assertTrue($parsed['rows'][0]['excluded']);
        $this->assertSame('Compte de service sans session interactive', $parsed['rows'][0]['reason']);
        $this->assertTrue($parsed['rows'][1]['remote']);
        $this->assertSame(['missing_account'], R::parseCsv("foo,methods\nx,sms\n")['errors']);
        $this->assertSame(['missing_methods'], R::parseCsv("login,foo\nx,y\n")['errors']);
    }

    public function testMatch(): void
    {
        $logins = ['alice.demo' => 1, 'bob' => 2];
        $emails = ['robert.demo@exemple.test' => 2];
        $this->assertSame(1, R::match('Alice.Demo', '', $logins, $emails));
        $this->assertSame(2, R::match('robert.demo@exemple.test', '', $logins, $emails));
        $this->assertSame(1, R::match('alice.demo@exemple.test', '', $logins, $emails), 'Partie locale de l\'UPN.');
        $this->assertSame(0, R::match('inconnu', 'inconnu@exemple.test', $logins, $emails));
    }
}
