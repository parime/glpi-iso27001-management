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

namespace GlpiPlugin\Grcmanager\Tests\Compat;

use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Compat\LegacyNamespace;
use PHPUnit\Framework\TestCase;

/**
 * Issue #658 : contrôles du point de passage unique des appels sortants, sans GLPI ni réseau
 * (checkUrl() sans résolution, réglage passé en paramètre). Les assertions portent sur des codes
 * d'erreur, jamais sur des textes traduits : indépendantes de la langue de l'instance.
 */
final class HttpTest extends TestCase
{
    public function testSchemeOtherThanHttpIsRefused(): void
    {
        $urls = [
            'ftp://example.com/x', 'file:///etc/passwd', 'gopher://example.com/', 'javascript:alert(1)',
            'dict://example.com:11211/', 'php://filter/resource=/etc/passwd', 'phar:///tmp/x.phar', 'pas une adresse', '',
        ];
        foreach ($urls as $url) {
            self::assertSame(Http::ERROR_SCHEME, Http::checkUrl($url, false, true), $url);
        }
        self::assertNull(Http::checkUrl('https://services.nvd.nist.gov/rest/json/cves/2.0', false, false));
        self::assertNull(Http::checkUrl('HTTP://8.8.8.8/', false, false));
    }

    public function testInternalAddressIsRefused(): void
    {
        $internal = [
            'http://127.0.0.1/', 'http://127.1.2.3:8080/', 'https://10.0.0.5/feed', 'http://172.16.0.1/',
            'http://192.168.1.10/', 'http://169.254.169.254/latest/meta-data/', 'http://100.64.0.1/',
            'http://0.0.0.0/', 'http://224.0.0.1/', 'http://[::1]/', 'http://[fe80::1]/', 'http://[fd00::1]/',
            'http://[::ffff:127.0.0.1]/', 'http://[::ffff:169.254.169.254]/', 'http://localhost/',
            'http://LOCALHOST./', 'http://api.localhost/', 'http://nas.local/', 'http://db.internal/',
        ];
        foreach ($internal as $url) {
            self::assertSame(Http::ERROR_INTERNAL, Http::checkUrl($url, false, false), $url);
        }
        // Écritures numériques de 127.0.0.1 : refusées même quand les adresses internes sont permises.
        foreach (['http://2130706433/', 'http://0x7f.1/', 'http://017700000001/', 'http://127.1/'] as $url) {
            self::assertSame(Http::ERROR_HOST, Http::checkUrl($url, false, true), $url);
        }
        // Identifiants dans l'adresse : jamais transmis.
        self::assertSame(Http::ERROR_HOST, Http::checkUrl('https://user:secret@example.com/', false, true));
        // Autorisées explicitement par l'administrateur.
        self::assertNull(Http::checkUrl('http://127.0.0.1/', false, true));
        self::assertNull(Http::checkUrl('https://10.0.0.5/feed', false, true));
    }

    public function testIsInternalIp(): void
    {
        $internal = [
            '127.0.0.1', '10.1.2.3', '192.168.0.1', '172.31.255.255', '169.254.169.254', '::1', 'fe80::1',
            'fc00::1', '::ffff:10.0.0.1', 'ff02::1', '[::1]', 'nimportequoi',
        ];
        foreach ($internal as $ip) {
            self::assertTrue(Http::isInternalIp($ip), $ip);
        }
        foreach (['8.8.8.8', '93.184.215.14', '2606:4700:4700::1111'] as $ip) {
            self::assertFalse(Http::isInternalIp($ip), $ip);
        }
    }

    public function testLegacyNamespaceMapsToCompat(): void
    {
        self::assertSame(Http::class, LegacyNamespace::newName('GlpiPlugin\\Grcmanager\\Compatibility\\Http'));
        self::assertSame(
            'GlpiPlugin\\Grcmanager\\Compat\\Base\\RiskBase',
            LegacyNamespace::newName('GlpiPlugin\\Grcmanager\\Compatibility\\Base\\RiskBase')
        );
        self::assertNull(LegacyNamespace::newName('GlpiPlugin\\Grcmanager\\Risk'));

        LegacyNamespace::register();
        self::assertTrue(class_exists('GlpiPlugin\\Grcmanager\\Compatibility\\Http'));
        self::assertSame(Http::class, (new \ReflectionClass('GlpiPlugin\\Grcmanager\\Compatibility\\Http'))->getName());
    }
}
