<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EdrCsvImportTest extends TestCase
{
    private const CSV = "Alert ID;Detection Time;Hostname;User Name;Severity;Technique;Command Line;SHA256\n"
        . "A-1;2026-10-30T08:00:00Z;PC-COMPTA-03;DOM\\jdoe;High;T1059.001;\"powershell -enc AAA\";"
        . "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\n"
        . "A-1;2026-10-30T07:55:00Z;PC-COMPTA-03;DOM\\jdoe;Critical;T1003.001;mimikatz.exe;\n"
        . "A-2;2026-10-30T09:00:00Z;SRV-AD01;;Low;;;\n";

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
    }

    public function testParseDetectsDelimiterAndEncoding(): void
    {
        $latin1 = mb_convert_encoding("Nom;Gravité\nPoste;Élevée\n", 'Windows-1252', 'UTF-8');
        $parsed = EdrCsvImport::parse("\xEF\xBB\xBF" . self::CSV);
        self::assertSame('Alert ID', $parsed['headers'][0]);
        self::assertCount(3, $parsed['rows']);
        self::assertSame('powershell -enc AAA', $parsed['rows'][0][6]);

        $parsedLatin = EdrCsvImport::parse($latin1);
        self::assertSame(['Nom', 'Gravité'], $parsedLatin['headers']);
        self::assertSame('Élevée', $parsedLatin['rows'][0][1]);
        self::assertSame("\t", EdrCsvImport::detectDelimiter("a\tb\tc"));
    }

    public function testGuessMappingIncludingFlattenedHeaders(): void
    {
        $mapping = EdrCsvImport::guessMapping(EdrCsvImport::parse(self::CSV)['headers']);
        self::assertSame(
            ['alert_id' => 0, 'detected_at' => 1, 'hostname' => 2, 'username' => 3, 'severity' => 4,
                'technique' => 5, 'command_line' => 6, 'sha256' => 7],
            $mapping
        );
        self::assertSame(
            ['hostname' => 0, 'command_line' => 1],
            EdrCsvImport::guessMapping(['device.hostname', 'process.command_line'])
        );
    }

    #[DataProvider('severityProvider')]
    public function testSeverity(string $value, string $expected): void
    {
        self::assertSame($expected, EdrCsvImport::severity($value));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function severityProvider(): array
    {
        return [
            'critical' => ['Critical', 'critical'],
            'élevée'   => ['Élevée', 'major'],
            'medium'   => ['Medium', 'major'],
            'low'      => ['Low', 'minor'],
            '90 / 100' => ['90', 'critical'],
            '6 / 10'   => ['6', 'major'],
        ];
    }

    public function testGroupByDetectionKeepsEarliestDateAndHighestSeverity(): void
    {
        $parsed = EdrCsvImport::parse(self::CSV);
        $mapping = EdrCsvImport::guessMapping($parsed['headers']);
        $incidents = EdrCsvImport::group($parsed['rows'], $mapping, 'detection');

        self::assertCount(2, $incidents);
        self::assertSame('2026-10-30 07:55:00', $incidents[0]['date']);
        self::assertSame('critical', $incidents[0]['severity']);
        self::assertSame(['A-1'], $incidents[0]['external_ids']);
        self::assertSame(['T1059.001', 'T1003.001'], $incidents[0]['techniques']);
        self::assertSame(['DOM\\jdoe'], $incidents[0]['users']);
        self::assertSame(['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'], $incidents[0]['iocs']);
        self::assertSame('EDR: SRV-AD01', $incidents[1]['title']);

        self::assertCount(1, EdrCsvImport::group($parsed['rows'], $mapping, 'single'));
        self::assertCount(2, EdrCsvImport::group($parsed['rows'], $mapping, 'host'));
        self::assertCount(3, EdrCsvImport::group($parsed['rows'], $mapping, 'row'));
    }

    public function testParseDate(): void
    {
        self::assertSame('2026-10-30 13:05:00', EdrCsvImport::parseDate('30/10/2026 14:05', 'Europe/Paris'));
        self::assertSame('2026-10-30 08:00:00', EdrCsvImport::parseDate('1793347200'));
        self::assertSame('2026-10-30 08:00:00', EdrCsvImport::parseDate('1793347200000'));
        self::assertNull(EdrCsvImport::parseDate('pas une date'));
    }
}
