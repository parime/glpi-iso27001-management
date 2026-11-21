<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\DockerHardeningEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Issue #916 : synthèse de la configuration à risque Docker (A.8.9) à partir de la réponse de
 * Evidence::hardening() (jeu fictif). Assertions sur des nombres et des dates, jamais sur des libellés.
 */
final class DockerHardeningEvidenceTest extends TestCase
{
    private const TODAY = '2026-03-10 12:00:00';

    /** @var array<int, true> */
    private const ALLOWED = [0 => true, 10 => true];

    /**
     * @return array<string, mixed>
     */
    private static function api(): array
    {
        $finding = static fn (string $exception, string $end): array => [
            'code' => 'privileged', 'label' => 'Privileged', 'severity' => 'critical', 'detail' => '',
            'exception' => $exception, 'exception_end' => $end,
        ];

        return [
            'analysed' => 8, 'compliant' => 0, 'not_analysed' => 2, 'with_exception' => 3,
            'rate' => 0, 'rate_serious' => 75,
            'by_severity' => ['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 6],
            'services' => [
                ['service' => 'web', 'entities_id' => 10, 'findings' => [
                    $finding('expired', '2026-03-01'),
                    $finding('active', '2026-03-25'),
                    $finding('active', '2026-03-15'),
                    $finding('active', '2026-06-30'),
                    $finding('', ''),
                ]],
                // Entité non autorisée : ses exceptions ne sont jamais comptées.
                ['service' => 'db', 'entities_id' => 99, 'findings' => [
                    $finding('expired', '2026-02-01'),
                    $finding('active', '2026-03-12'),
                ]],
            ],
            'services_total' => 2,
            'page_url' => '/plugins/dockerinventory/front/hardening.php',
        ];
    }

    public function testSummaryHighlightsTheSeriousRateAndExceptions(): void
    {
        $summary = DockerHardeningEvidence::summary(self::api(), self::ALLOWED, self::TODAY);

        $this->assertNotNull($summary);
        $this->assertSame(75, $summary['rate_serious']);
        $this->assertSame(0, $summary['rate']);
        $this->assertSame(8, $summary['analysed']);
        $this->assertSame(2, $summary['not_analysed']);
        $this->assertSame(['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 6], $summary['by_severity']);
        $this->assertSame(1, $summary['expired']);
        $this->assertSame(2, $summary['expiring']);
        $this->assertSame('2026-03-15', $summary['next_end']);
        $this->assertSame('/plugins/dockerinventory/front/hardening.php', $summary['page_url']);
    }

    public function testUnusableAnswersGiveNothing(): void
    {
        $this->assertNull(DockerHardeningEvidence::summary(null, self::ALLOWED, self::TODAY));
        $this->assertNull(DockerHardeningEvidence::summary(['services' => []], self::ALLOWED, self::TODAY));
    }

    public function testRatesAreBoundedAndUnsafeLinksDropped(): void
    {
        $summary = DockerHardeningEvidence::summary([
            'analysed' => 0, 'rate' => null, 'rate_serious' => 140, 'services' => 'x',
            'page_url' => 'javascript:alert(1)',
        ], self::ALLOWED, self::TODAY);

        $this->assertNotNull($summary);
        $this->assertNull($summary['rate']);
        $this->assertSame(100, $summary['rate_serious']);
        $this->assertSame(0, $summary['expired']);
        $this->assertSame('', $summary['page_url']);
    }
}
