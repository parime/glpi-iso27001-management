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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\MailDomain;

use GlpiPlugin\Grcmanager\Services\MailDomain\DnsWire;
use GlpiPlugin\Grcmanager\Services\MailDomain\MailDomainRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #855 : règles pures de la sécurité de la messagerie et des domaines (enregistrements DNS
 * fictifs, domaines réservés à la documentation). Assertions sur des codes, jamais sur des textes.
 */
final class MailDomainRulesTest extends TestCase
{
    public function testNormalizeDomain(): void
    {
        $this->assertSame('example.org', Rules::normalizeDomain('  Example.ORG. '));
        $this->assertSame('mail.example.co.uk', Rules::normalizeDomain('mail.example.co.uk'));
        $this->assertSame('xn--exmple-cua.test', Rules::normalizeDomain('xn--exmple-cua.test'));
        $bad = ['', 'user@example.org', 'https://example.org', '192.0.2.1', 'localhost', '-bad.example', 'a b.org'];
        foreach ($bad as $value) {
            $this->assertNull(Rules::normalizeDomain($value), $value);
        }
    }

    public function testNormalizeSelectorsAndDates(): void
    {
        $this->assertSame(
            ['selector1', 'selector2', 'google'],
            Rules::normalizeSelectors("Selector1, selector2;\ngoogle selector1")
        );
        $this->assertSame(['ok'], Rules::normalizeSelectors('ok, ../x, a..b, <b>'));
        $this->assertCount(Rules::MAX_SELECTORS, Rules::normalizeSelectors(implode(',', range(1, 20))));
        $this->assertSame('2027-02-28', Rules::normalizeDate('2027-02-28'));
        $this->assertNull(Rules::normalizeDate('2027-02-30'));
        $this->assertNull(Rules::normalizeDate('28/02/2027'));
    }

    public function testSpf(): void
    {
        $this->assertSame('missing', Rules::spf(['google-site-verification=abc'])['status']);
        $strict = Rules::spf(['v=spf1 include:_spf.example.net mx -all']);
        $this->assertSame(['ok', '-all', 2], [$strict['status'], $strict['all'], $strict['lookups']]);
        $this->assertSame('+all', Rules::spf(['v=spf1 +all'])['all']);
        $this->assertSame('+all', Rules::spf(['v=spf1 a all'])['all']);
        $this->assertSame('~all', Rules::spf(['V=SPF1 ~all'])['all']);
        $this->assertSame('none', Rules::spf(['v=spf1 ip4:192.0.2.0/24'])['all']);
        $this->assertSame('redirect', Rules::spf(['v=spf1 redirect=_spf.example.net'])['all']);
        $this->assertSame('multiple', Rules::spf(['v=spf1 -all', 'v=spf1 mx -all'])['status']);
        $many = Rules::spf(['v=spf1 ' . str_repeat('include:a.example.net ', 11) . '-all']);
        $this->assertSame(11, $many['lookups']);
    }

    public function testDmarc(): void
    {
        $this->assertSame('missing', Rules::dmarc([])['status']);
        $reject = Rules::dmarc(['v=DMARC1; p=reject; rua=mailto:dmarc@example.org; pct=100']);
        $this->assertSame(
            ['ok', 'reject', 100, true],
            [$reject['status'], $reject['policy'], $reject['pct'], $reject['reports']]
        );
        $none = Rules::dmarc(['v=DMARC1;p=none']);
        $this->assertSame(['ok', 'none', false], [$none['status'], $none['policy'], $none['reports']]);
        $this->assertSame(50, Rules::dmarc(['v=DMARC1; p=quarantine; pct=50'])['pct']);
        $this->assertSame('invalid', Rules::dmarc(['v=DMARC1; p=block'])['status']);
        $this->assertSame('multiple', Rules::dmarc(['v=DMARC1; p=none', 'v=DMARC1; p=reject'])['status']);
        $this->assertTrue(Rules::dmarcEnforced(['dmarc' => Rules::dmarc(['v=DMARC1; p=quarantine'])]));
        $this->assertFalse(Rules::dmarcEnforced(['dmarc' => $none]));
        $this->assertFalse(Rules::dmarcEnforced(null));
    }

    public function testDkimMtaStsTlsRpt(): void
    {
        $this->assertSame('ok', Rules::dkim(['v=DKIM1; k=rsa; p=MIIBIjANBgkqh']));
        $this->assertSame('revoked', Rules::dkim(['v=DKIM1; p=']));
        $this->assertSame('missing', Rules::dkim([]));
        $this->assertTrue(Rules::announced(['v=STSv1; id=20270101'], 'STSv1'));
        $this->assertFalse(Rules::announced(['v=STSv10'], 'STSv1'));
        $this->assertTrue(Rules::announced(['v=TLSRPTv1; rua=mailto:tls@example.org'], 'TLSRPTv1'));
    }

    public function testExpiry(): void
    {
        $this->assertSame(['status' => 'none', 'days' => null], Rules::expiry(null, '2027-01-01'));
        $this->assertSame(['status' => 'expired', 'days' => -1], Rules::expiry('2026-12-31', '2027-01-01'));
        $this->assertSame(['status' => 'soon', 'days' => 30], Rules::expiry('2027-01-31', '2027-01-01'));
        $this->assertSame(['status' => 'ok', 'days' => 31], Rules::expiry('2027-02-01', '2027-01-01 10:00:00'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function dns(array $overrides = []): array
    {
        /** @var array{resolved: bool, mx: list<string>, txt: list<string>, dmarc: list<string>,
         *     mta_sts: list<string>, tls_rpt: list<string>, dkim: array<string, list<string>>, dnssec: ?bool} $dns */
        $dns = $overrides + [
            'resolved' => true,
            'mx'       => ['mx1.example.org'],
            'txt'      => ['v=spf1 mx -all'],
            'dmarc'    => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org'],
            'mta_sts'  => ['v=STSv1; id=1'],
            'tls_rpt'  => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
            'dkim'     => ['selector1' => ['v=DKIM1; p=abc']],
            'dnssec'   => true,
        ];

        return Rules::analyse($dns);
    }

    /**
     * @param list<array{code: string, severity: string}> $findings
     * @return list<string>
     */
    private static function codes(array $findings): array
    {
        return array_column($findings, 'code');
    }

    public function testWellProtectedDomainHasNoGap(): void
    {
        $findings = Rules::findings(self::dns(), '2028-01-01', '2028-01-01', '2027-01-01');
        $this->assertSame([], $findings);
        $this->assertSame('success', Rules::worst($findings));
    }

    public function testFindingsOfAWeakDomain(): void
    {
        $result = self::dns([
            'txt'     => ['v=spf1 +all'],
            'dmarc'   => ['v=DMARC1; p=none'],
            'mta_sts' => [],
            'tls_rpt' => [],
            'dkim'    => ['s1' => [], 's2' => ['v=DKIM1; p=']],
            'dnssec'  => false,
        ]);
        $findings = Rules::findings($result, '2027-01-10', '2026-12-01', '2027-01-01');
        $this->assertSame([
            'spf_pass_all', 'dmarc_none', 'dmarc_no_reports', 'dkim_missing', 'dkim_revoked', 'dnssec_missing',
            'mta_sts_missing', 'tls_rpt_missing', 'domain_expiring', 'cert_expired',
        ], self::codes($findings));
        $this->assertSame('danger', Rules::worst($findings));
    }

    public function testMissingRecordsAndDomainWithoutMx(): void
    {
        $result = self::dns([
            'mx' => [], 'txt' => [], 'dmarc' => [], 'mta_sts' => [], 'tls_rpt' => [], 'dkim' => [], 'dnssec' => null,
        ]);
        $codes = self::codes(Rules::findings($result, null, null, '2027-01-01'));
        $this->assertSame(
            ['spf_missing', 'dmarc_missing', 'dkim_not_declared', 'dnssec_unknown', 'domain_expiry_unknown'],
            $codes
        );
        $this->assertNotContains('mta_sts_missing', $codes, 'sans MX, MTA-STS ne concerne pas le domaine');
    }

    public function testUnresolvedDomainAndUncheckedDomain(): void
    {
        $result = self::dns(['resolved' => false]);
        $this->assertSame(['unresolved'], self::codes(Rules::findings($result, '2028-01-01', null, '2027-01-01')));
        $this->assertSame(['domain_expiry_unknown'], self::codes(Rules::findings(null, null, null, '2027-01-01')));
        $this->assertSame('info', Rules::worst(Rules::findings(null, null, null, '2027-01-01')));
    }

    public function testEverySeverityIsKnown(): void
    {
        foreach (Rules::FINDINGS as $severity) {
            $this->assertContains($severity, Rules::SEVERITIES);
        }
    }

    public function testDnsWireQueryAndAnswers(): void
    {
        $question = "\x07example\x03org\x00\x00\x2b\x00\x01";
        $query = DnsWire::query(0x1234, 'example.org.', DnsWire::TYPE_DS);
        $this->assertSame("\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00" . $question, $query);

        // Réponse fictive : la question, puis un enregistrement DS (nom compressé vers la question).
        $answer = "\x12\x34\x81\x80\x00\x01\x00\x01\x00\x00\x00\x00" . $question
            . "\xc0\x0c\x00\x2b\x00\x01\x00\x00\x0e\x10\x00\x04\x00\x01\x08\x02";
        $this->assertSame(['rcode' => 0, 'count' => 1], DnsWire::answers($answer, 0x1234, DnsWire::TYPE_DS));

        $empty = "\x12\x34\x81\x80\x00\x01\x00\x00\x00\x00\x00\x00" . $question;
        $this->assertSame(['rcode' => 0, 'count' => 0], DnsWire::answers($empty, 0x1234, DnsWire::TYPE_DS));
        $nx = "\x12\x34\x81\x83\x00\x01\x00\x00\x00\x00\x00\x00" . $question;
        $this->assertSame(['rcode' => 3, 'count' => 0], DnsWire::answers($nx, 0x1234, DnsWire::TYPE_DS));

        $this->assertNull(DnsWire::answers($answer, 0x9999, DnsWire::TYPE_DS), 'autre identifiant');
        $this->assertNull(DnsWire::answers($query, 0x1234, DnsWire::TYPE_DS), 'une question, pas une réponse');
        $this->assertNull(DnsWire::answers(substr($answer, 0, -3), 0x1234, DnsWire::TYPE_DS), 'tronquée');
        $this->assertNull(DnsWire::answers('', 0x1234, DnsWire::TYPE_DS));
    }
}
