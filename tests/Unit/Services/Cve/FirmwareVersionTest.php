<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\CpeVersionMatch;
use GlpiPlugin\Grcmanager\Services\Cve\FirmwareVersion;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilityRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #705 : versions de firmware (matériel réseau, imprimantes, BIOS) face aux plages d'une CVE.
 * Produits et versions fictifs.
 */
final class FirmwareVersionTest extends TestCase
{
    /**
     * @param array<string, ?string> $overrides
     * @return array{
     *     cpe23_uri: string, version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * }
     */
    private function affectedCpe(array $overrides = []): array
    {
        return array_merge([
            'cpe23_uri'               => 'cpe:2.3:o:demonet:switchos:*:*:*:*:*:*:*:*',
            'version_start_including' => null,
            'version_start_excluding' => null,
            'version_end_including'   => null,
            'version_end_excluding'   => null,
        ], $overrides);
    }

    public function testNormalizeDropsOnlyThePrefixAndTheBuild(): void
    {
        $this->assertSame('7.0.12', FirmwareVersion::normalize(' v7.0.12 '));
        $this->assertSame('7.0.12', FirmwareVersion::normalize('7.0.12,build0523'));
        $this->assertSame('7.0.12', FirmwareVersion::normalize('V7.0.12 build 0523 (GA)'));
        $this->assertSame('6.4.5', FirmwareVersion::normalize('6.4.5 b1828'));
        $this->assertSame('15.2(7)E3', FirmwareVersion::normalize('15.2(7)E3'));
        $this->assertSame('1.0 beta', FirmwareVersion::normalize('1.0 beta'), 'Pas un build.');
        $this->assertSame('vendor default', FirmwareVersion::normalize('vendor default'));
    }

    public function testNumericFirmwareInsideTheRangeIsMatched(): void
    {
        $cpe = $this->affectedCpe(['version_start_including' => '7.0.0', 'version_end_excluding' => '7.0.14']);

        $this->assertSame(CpeVersionMatch::MATCHED, FirmwareVersion::evaluate($cpe, 'v7.0.12,build0523'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, FirmwareVersion::evaluate($cpe, '7.0.14'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, FirmwareVersion::evaluate($cpe, '6.4.15'));
    }

    public function testNumericFirmwareAgainstAnExactVersion(): void
    {
        $cpe = $this->affectedCpe(['cpe23_uri' => 'cpe:2.3:o:demonet:switchos:3.2.1:*:*:*:*:*:*:*']);

        $this->assertSame(CpeVersionMatch::MATCHED, FirmwareVersion::evaluate($cpe, 'v3.2.1'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, FirmwareVersion::evaluate($cpe, '3.2.2'));
    }

    public function testTrainVersionMatchesOnlyTheSameExactVersion(): void
    {
        $cpe = $this->affectedCpe(['cpe23_uri' => 'cpe:2.3:o:demonet:switchos:15.2\(7\)e3:*:*:*:*:*:*:*']);

        $this->assertSame(CpeVersionMatch::MATCHED, FirmwareVersion::evaluate($cpe, '15.2(7)E3'));
        $this->assertSame(
            CpeVersionMatch::EXCLUDED,
            FirmwareVersion::evaluate($cpe, '15.2(7)M3'),
            'version_compare() tiendrait E3 et M3 pour égales.'
        );
        $this->assertSame(CpeVersionMatch::EXCLUDED, FirmwareVersion::evaluate($cpe, '15.2'));
    }

    public function testTrainVersionAgainstARangeIsNeverGuessed(): void
    {
        $cpe = $this->affectedCpe(['version_end_excluding' => '15.2(7)E5']);

        $this->assertSame(CpeVersionMatch::UNKNOWN, FirmwareVersion::evaluate($cpe, '15.2(7)E3'));
        $this->assertSame(CpeVersionMatch::UNKNOWN, FirmwareVersion::evaluate($cpe, '15.2(7)M9'));
    }

    public function testUnreadableFirmwareVersionIsUnknown(): void
    {
        $cpe = $this->affectedCpe(['version_end_excluding' => '7.0.14']);

        $this->assertSame(CpeVersionMatch::UNKNOWN, FirmwareVersion::evaluate($cpe, ''));
        $this->assertSame(CpeVersionMatch::UNKNOWN, FirmwareVersion::evaluate($cpe, 'vendor default'));
        $this->assertSame(CpeVersionMatch::UNKNOWN, FirmwareVersion::evaluate($this->affectedCpe(), '7.0.12'));
    }

    public function testEntityRestrictionIsRetargetedOnTheFirmwareTable(): void
    {
        $criteria = [
            'glpi_items_softwareversions.entities_id' => [0, 3],
            'OR' => [
                'glpi_items_softwareversions.is_recursive' => 1,
                'glpi_other.entities_id'                   => 4,
            ],
        ];

        $this->assertSame([
            'glpi_items_devicefirmwares.entities_id' => [0, 3],
            'OR' => [
                'glpi_items_devicefirmwares.is_recursive' => 1,
                'glpi_other.entities_id'                  => 4,
            ],
        ], ParkVulnerabilityRules::retargetCriteria(
            $criteria,
            'glpi_items_softwareversions',
            'glpi_items_devicefirmwares'
        ));
        $this->assertSame([], ParkVulnerabilityRules::retargetCriteria([], 'a', 'b'));
    }
}
