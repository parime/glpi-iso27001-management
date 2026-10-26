<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\SafeUrl;
use PHPUnit\Framework\TestCase;

/**
 * Revue de sécurité 2.11.0 : liens de flux tiers (NVD, CERT-FR) affichés dans un href.
 */
final class SafeUrlTest extends TestCase
{
    public function testOnlyAbsoluteHttpUrlsPass(): void
    {
        $this->assertTrue(SafeUrl::isWebUrl('https://logging.apache.org/log4j/2.x/security.html'));
        $this->assertTrue(SafeUrl::isWebUrl('http://packetstormsecurity.com/files/1.html'));
        foreach (
            [
                'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=',
                'vbscript:msgbox', '//evil.example/x', '/relative/path', 'https://', '',
                "https://ok.example/\njavascript:alert(1)", ' https://ok.example/', 'ftp://files.example/a',
            ] as $url
        ) {
            $this->assertFalse(SafeUrl::isWebUrl($url), $url);
        }
    }

    public function testHttpsAndHostRestrictions(): void
    {
        $hosts = ['www.cert.cyber.gouv.fr'];
        $this->assertTrue(SafeUrl::isWebUrl('https://WWW.cert.cyber.gouv.fr/avis/', true, $hosts));
        $this->assertFalse(SafeUrl::isWebUrl('http://www.cert.cyber.gouv.fr/avis/', true, $hosts));
        $this->assertFalse(SafeUrl::isWebUrl('https://www.cert.cyber.gouv.fr.evil.example/', true, $hosts));
        $this->assertFalse(SafeUrl::isWebUrl('https://user@evil.example/www.cert.cyber.gouv.fr', true, $hosts));
    }
}
