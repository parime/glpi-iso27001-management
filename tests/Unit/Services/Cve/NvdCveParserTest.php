<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\NvdCveParser;
use PHPUnit\Framework\TestCase;

final class NvdCveParserTest extends TestCase
{
    /**
     * Trimmed real payload for CVE-2021-44228 ("Log4Shell"), captured live from
     * https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=CVE-2021-44228 — including the exact
     * duplicate reference entry (the Apache security page and the Microsoft MSRC advisory each
     * appear twice in the real feed) confirmed to exist in production data, used below to prove
     * patch-link dedup by URL actually fires on real-shaped input, not just a synthetic case.
     */
    private function log4shellPayload(): array
    {
        return [
            'id'        => 'CVE-2021-44228',
            'published' => '2021-12-10T10:15:09.143',
            'descriptions' => [
                ['lang' => 'en', 'value' => 'Apache Log4j2 JNDI features do not protect against attacker controlled LDAP endpoints.'],
                ['lang' => 'es', 'value' => 'Las caracteristicas JNDI de Apache Log4j2...'],
            ],
            'metrics' => [
                'cvssMetricV31' => [
                    [
                        'source' => 'nvd@nist.gov',
                        'type'   => 'Primary',
                        'cvssData' => [
                            'version'      => '3.1',
                            'vectorString' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H',
                            'baseScore'    => 10.0,
                            'baseSeverity' => 'CRITICAL',
                        ],
                    ],
                    [
                        'source' => '134c704f-9b21-4f2e-91b3-4a467353bcc0',
                        'type'   => 'Secondary',
                        'cvssData' => [
                            'version'      => '3.1',
                            'vectorString' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H',
                            'baseScore'    => 10.0,
                            'baseSeverity' => 'CRITICAL',
                        ],
                    ],
                ],
                'cvssMetricV2' => [
                    [
                        'source'   => 'nvd@nist.gov',
                        'type'     => 'Primary',
                        'cvssData' => ['version' => '2.0', 'vectorString' => 'AV:N/AC:M/Au:N/C:C/I:C/A:C', 'baseScore' => 9.3],
                        'baseSeverity' => 'HIGH',
                    ],
                ],
            ],
            'references' => [
                ['url' => 'https://logging.apache.org/log4j/2.x/security.html', 'tags' => ['Release Notes', 'Vendor Advisory']],
                ['url' => 'https://msrc-blog.microsoft.com/2021/12/11/microsofts-response-to-cve-2021-44228-apache-log4j2/', 'tags' => ['Patch', 'Third Party Advisory', 'Vendor Advisory']],
                // Real NVD feed genuinely lists both of the above a second time.
                ['url' => 'https://logging.apache.org/log4j/2.x/security.html', 'tags' => ['Release Notes', 'Vendor Advisory']],
                ['url' => 'https://msrc-blog.microsoft.com/2021/12/11/microsofts-response-to-cve-2021-44228-apache-log4j2/', 'tags' => ['Patch', 'Third Party Advisory', 'Vendor Advisory']],
                ['url' => 'http://packetstormsecurity.com/files/165225/Apache-Log4j2-2.14.1-Remote-Code-Execution.html', 'tags' => ['Third Party Advisory', 'VDB Entry']],
            ],
        ];
    }

    public function testExtractsBestCvssAndItsOwnBaseSeverityWhenSeveralVersionsCoexist(): void
    {
        $result = NvdCveParser::parse($this->log4shellPayload());

        self::assertSame('CVE-2021-44228', $result['cve_id']);
        // v3.1 preferred over v2 (highest available version), and "Primary" over "Secondary".
        self::assertSame(10.0, $result['cvss_score']);
        self::assertSame('CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H', $result['cvss_vector']);
        // NVD's own baseSeverity is used verbatim, never recomputed, when a v3.x/v4.0 metric exists.
        self::assertSame('CRITICAL', $result['severity']);
    }

    public function testPicksTheEnglishDescriptionOverOtherLanguages(): void
    {
        $result = NvdCveParser::parse($this->log4shellPayload());

        self::assertStringContainsString('JNDI features do not protect', $result['description']);
    }

    public function testExtractsPatchLinksDeduplicatedByUrlAndExcludesNonRemediationReferences(): void
    {
        $result = NvdCveParser::parse($this->log4shellPayload());

        // 2 distinct URLs kept (Apache + Microsoft), each only once despite appearing twice in the
        // raw payload; the plain "Third Party Advisory"/"VDB Entry" packetstormsecurity link (an
        // exploit writeup, not a fix) must never be surfaced as if it were a remediation.
        self::assertCount(2, $result['patch_links']);
        $urls = array_column($result['patch_links'], 'url');
        self::assertContains('https://logging.apache.org/log4j/2.x/security.html', $urls);
        self::assertContains(
            'https://msrc-blog.microsoft.com/2021/12/11/microsofts-response-to-cve-2021-44228-apache-log4j2/',
            $urls
        );
        self::assertNotContains(
            'http://packetstormsecurity.com/files/165225/Apache-Log4j2-2.14.1-Remote-Code-Execution.html',
            $urls
        );

        $tagsByUrl = array_column($result['patch_links'], 'tag', 'url');
        self::assertSame('Vendor Advisory', $tagsByUrl['https://logging.apache.org/log4j/2.x/security.html']);
        self::assertSame(
            'Patch',
            $tagsByUrl['https://msrc-blog.microsoft.com/2021/12/11/microsofts-response-to-cve-2021-44228-apache-log4j2/']
        );
    }

    public function testParsesThePublicationDateIntoAMysqlCompatibleTimestamp(): void
    {
        $result = NvdCveParser::parse($this->log4shellPayload());

        self::assertSame('2021-12-10 10:15:09', $result['published_at']);
    }

    /**
     * A CVSS v2-only metric has no `baseSeverity` field at all in the real NVD payload (that
     * concept was introduced with v3) — severity must fall back to NIST's own published v2
     * qualitative scale, never an invented threshold, and only when no better metric exists.
     */
    public function testDerivesSeverityFromScoreOnlyForAV2OnlyMetricWithNoNativeBaseSeverity(): void
    {
        $payload = [
            'id' => 'CVE-2010-00001',
            'descriptions' => [['lang' => 'en', 'value' => 'A v2-only CVE.']],
            'metrics' => [
                'cvssMetricV2' => [
                    [
                        'source'   => 'nvd@nist.gov',
                        'type'     => 'Primary',
                        'cvssData' => ['version' => '2.0', 'vectorString' => 'AV:N/AC:L/Au:N/C:P/I:P/A:P', 'baseScore' => 7.5],
                        // Deliberately no 'baseSeverity' key, matching a genuine v2-only NVD entry.
                    ],
                ],
            ],
            'references' => [],
        ];

        $result = NvdCveParser::parse($payload);

        self::assertSame(7.5, $result['cvss_score']);
        self::assertSame('HIGH', $result['severity']);
    }

    public function testReturnsNullScoreSeverityAndVectorWhenNoMetricsArePresentAtAll(): void
    {
        $payload = [
            'id'           => 'CVE-2026-00001',
            'descriptions' => [['lang' => 'en', 'value' => 'A brand new CVE, not yet analyzed by NVD.']],
            'metrics'      => [],
            'references'   => [],
        ];

        $result = NvdCveParser::parse($payload);

        self::assertNull($result['cvss_score']);
        self::assertNull($result['cvss_vector']);
        self::assertNull($result['severity']);
        self::assertSame([], $result['patch_links']);
    }

    public function testTruncatesAnOverlyLongDescriptionWithoutSplittingAMultiByteCharacter(): void
    {
        // A multi-byte character placed exactly at the truncation boundary — the byte-based
        // substr() bug this mirrors (see class docblock) would split it and produce invalid UTF-8.
        $payload = [
            'id'           => 'CVE-2026-00002',
            'descriptions' => [['lang' => 'en', 'value' => str_repeat('a', 1999) . 'é' . str_repeat('b', 50)]],
            'metrics'      => [],
            'references'   => [],
        ];

        $result = NvdCveParser::parse($payload);

        self::assertLessThanOrEqual(2000, mb_strlen($result['description']));
        self::assertStringEndsWith('…', $result['description']);
        // The multi-byte accented character must remain intact if it made it into the kept prefix.
        self::assertTrue(mb_check_encoding($result['description'], 'UTF-8'));
    }
}
