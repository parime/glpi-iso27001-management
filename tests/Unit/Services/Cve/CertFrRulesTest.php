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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\CertFrRules;
use PHPUnit\Framework\TestCase;

final class CertFrRulesTest extends TestCase
{
    private const FEED = <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<rss version="2.0"><channel><title>Flux fictif</title>
<item><title>Multiples vulnérabilités dans Démo Navigateur (1 janvier 2099)</title>
<link>https://cert.example/avis/CERTFR-2099-AVI-0001/</link>
<description>De multiples vulnérabilités ont été découvertes dans Démo Navigateur.</description>
<pubDate>Thu, 01 Jan 2099 00:00:00 +0000</pubDate></item>
<item><title>Bulletin sans référence</title><link>https://cert.example/actualite/</link></item>
<item><title>Vulnérabilité dans Fournidémo Pare-feu (2 janvier 2099)</title>
<link>https://cert.example/alerte/CERTFR-2099-ALE-0002/</link><pubDate>Fri, 02 Jan 2099 08:30:00 +0000</pubDate></item>
</channel></rss>
XML;

    public function testParseFeedKeepsOnlyReferencedNotices(): void
    {
        $items = CertFrRules::parseFeed(self::FEED, 'avis');
        $this->assertSame(['CERTFR-2099-AVI-0001', 'CERTFR-2099-ALE-0002'], array_column($items, 'ref'));
        $this->assertSame('2099-01-01 00:00:00', $items[0]['published']);
        $this->assertStringContainsString('Démo Navigateur', $items[0]['summary']);
        $this->assertSame([], CertFrRules::parseFeed('<html>302 Found</html>', 'avis'));
        $this->assertSame([], CertFrRules::parseFeed('', 'avis'));
    }

    public function testMentionsIsAccentInsensitiveAndWholeWord(): void
    {
        $this->assertTrue(CertFrRules::mentions('Vulnérabilité dans Fournidémo Pare-feu', 'fournidemo'));
        $this->assertTrue(CertFrRules::mentions('Multiples vulnérabilités dans les produits Dell', 'Dell'));
        $this->assertFalse(CertFrRules::mentions('Vulnérabilité dans Modell Server', 'Dell'), 'Mot entier.');
        $this->assertFalse(CertFrRules::mentions('Vulnérabilité dans HP', 'HP'), 'Nom trop court.');
    }

    public function testSupplierKeywordsDropLegalNoise(): void
    {
        $this->assertSame(['fournidemo'], CertFrRules::supplierKeywords('Fournidémo SAS'));
        $this->assertSame(['dell technologies', 'dell'], CertFrRules::supplierKeywords('Dell Technologies France'));
        $this->assertSame(['demo logiciels', 'demo'], CertFrRules::supplierKeywords('Groupe Démo Logiciels SA'));
        $this->assertSame([], CertFrRules::supplierKeywords('SA'));
        $this->assertTrue(CertFrRules::mentionsAny('Vulnérabilité dans Dell iDRAC', ['dell technologies', 'dell']));
    }
}
