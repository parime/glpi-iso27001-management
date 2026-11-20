<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\CpeVersionMatch;
use GlpiPlugin\Grcmanager\Services\Cve\OperatingSystemVersion;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilityRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #780 : systèmes d'exploitation du parc face aux CVE (désignation, CPE proposé, version
 * comparée). Noms et versions tels que l'agent GLPI les inventorie, actifs fictifs.
 */
final class OperatingSystemVersionTest extends TestCase
{
    /**
     * @param array<string, ?string> $overrides
     * @return array{
     *     cpe23_uri: string, version_start_including: ?string, version_start_excluding: ?string,
     *     version_end_including: ?string, version_end_excluding: ?string
     * }
     */
    private function affectedCpe(string $cpe, array $overrides = []): array
    {
        return array_merge([
            'cpe23_uri'               => $cpe,
            'version_start_including' => null,
            'version_start_excluding' => null,
            'version_end_including'   => null,
            'version_end_excluding'   => null,
        ], $overrides);
    }

    public function testDesignationAddsTheFeatureReleaseOfWindowsClientsOnly(): void
    {
        $this->assertSame(
            'Microsoft Windows 11 Professionnel 23H2',
            OperatingSystemVersion::designation('Microsoft Windows 11 Professionnel', '23H2')
        );
        $this->assertSame(
            'Microsoft Windows 10 Entreprise 22H2',
            OperatingSystemVersion::designation('Microsoft Windows 10 Entreprise', '22h2 (build 19045)')
        );
        $this->assertSame(
            'Microsoft Windows 11 Pro',
            OperatingSystemVersion::designation('Microsoft Windows 11 Pro', ''),
            'Sans version de fonctionnalités, le nom reste tel quel.'
        );
        $this->assertSame(
            'Microsoft Windows Server 2022 Standard',
            OperatingSystemVersion::designation('Microsoft Windows Server 2022 Standard', '21H2'),
            'L\'année de Windows Server est déjà dans le nom.'
        );
        $this->assertSame(
            'Debian GNU/Linux 12 (bookworm)',
            OperatingSystemVersion::designation(' Debian GNU/Linux 12 (bookworm) ', '12')
        );
    }

    public function testDefaultProposalsCoverTheCommonFamiliesOnly(): void
    {
        $cases = [
            'Microsoft Windows 11 Professionnel 23H2'  => 'cpe:2.3:o:microsoft:windows_11_23h2',
            'Microsoft Windows 10 Entreprise LTSC 1809' => 'cpe:2.3:o:microsoft:windows_10_1809',
            'Microsoft Windows Server 2019 Standard'   => 'cpe:2.3:o:microsoft:windows_server_2019',
            'Microsoft Windows Server 2025 Datacenter' => 'cpe:2.3:o:microsoft:windows_server_2025',
            'Debian GNU/Linux 12 (bookworm)'           => 'cpe:2.3:o:debian:debian_linux',
            'Debian GNU/Linux'                         => 'cpe:2.3:o:debian:debian_linux',
            'Ubuntu 22.04.4 LTS'                       => 'cpe:2.3:o:canonical:ubuntu_linux',
            'Red Hat Enterprise Linux 9.3 (Plow)'      => 'cpe:2.3:o:redhat:enterprise_linux',
        ];
        foreach ($cases as $designation => $prefix) {
            $this->assertSame($prefix, OperatingSystemVersion::proposedPrefix($designation), $designation);
            $this->assertNotNull(ParkVulnerabilityRules::normalizePrefix($prefix), 'CPE valide : ' . $prefix);
        }

        $this->assertNull(OperatingSystemVersion::proposedPrefix('Microsoft Windows 11 Pro'), 'Version inconnue.');
        $this->assertNull(OperatingSystemVersion::proposedPrefix('Microsoft Windows Server 2012 R2 Standard'));
        $this->assertNull(OperatingSystemVersion::proposedPrefix('Rocky Linux 9.3 (Blue Onyx)'), 'Hors du lot 1.');
        // Ressemblance seulement : jamais de proposition.
        $this->assertNull(OperatingSystemVersion::proposedPrefix('DemoOS Debian Tools'));
        $this->assertNull(OperatingSystemVersion::proposedPrefix('Ubuntu Demo Edition'));
    }

    public function testReleaseUsesTheFormNvdUsesForEachFamily(): void
    {
        $release = static fn (string $name, string $version, string $kernel = ''): string
            => OperatingSystemVersion::release($name, $version, $kernel);

        // Windows : build du noyau, révision comprise quand l'agent la remonte.
        $this->assertSame('10.0.22631', $release('Microsoft Windows 11 Pro', '23H2', '10.0.22631'));
        $this->assertSame(
            '10.0.20348.2762',
            $release('Microsoft Windows Server 2022 Standard', '21H2', ' 10.0.20348.2762 ')
        );
        $this->assertSame('', $release('Microsoft Windows 11 Pro', '23H2'));
        // Debian et RHEL : version majeure (« 12.0 »), Ubuntu : « 22.04 ».
        $this->assertSame('12.0', $release('Debian GNU/Linux 12 (bookworm)', '12', '6.1.0-18-amd64'));
        $this->assertSame('12.0', $release('Debian GNU/Linux', '12.5'));
        $this->assertSame('12.0', $release('Debian GNU/Linux 12 (bookworm)', ''), 'Lu dans le nom.');
        $this->assertSame('22.04', $release('Ubuntu 22.04.4 LTS', '22.04.4 LTS (Jammy Jellyfish)', '5.15.0-105'));
        $this->assertSame('22.04', $release('Ubuntu 22.04.4 LTS', ''));
        $this->assertSame('9.0', $release('Red Hat Enterprise Linux 9.3 (Plow)', '9.3'));
        // Autre système : nombres en tête de la version, rien de deviné sinon.
        $this->assertSame('3.2.1', $release('DemoOS', '3.2.1 build 7'));
        $this->assertSame('', $release('DemoOS', 'latest'));
    }

    public function testWindowsBuildWithoutRevisionIsNotVerifiable(): void
    {
        $cve = $this->affectedCpe(
            'cpe:2.3:o:microsoft:windows_11_23h2:*:*:*:*:*:*:x64:*',
            ['version_end_excluding' => '10.0.22631.4317']
        );

        $this->assertSame(CpeVersionMatch::UNKNOWN, OperatingSystemVersion::evaluate($cve, '10.0.22631'));
        $this->assertSame(CpeVersionMatch::MATCHED, OperatingSystemVersion::evaluate($cve, '10.0.22631.4169'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, OperatingSystemVersion::evaluate($cve, '10.0.22631.4317'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, OperatingSystemVersion::evaluate($cve, '10.0.22631.4460'));
        $this->assertSame(CpeVersionMatch::UNKNOWN, OperatingSystemVersion::evaluate($cve, ''));

        $windows11 = 'cpe:2.3:o:microsoft:windows_11_23h2';
        $this->assertFalse(OperatingSystemVersion::isQueryable($windows11, '10.0.22631'));
        $this->assertTrue(OperatingSystemVersion::isQueryable($windows11, '10.0.22631.4169'));
        $queryable = static fn (string $prefix, string $version): bool
            => ParkVulnerabilityRules::isQueryableFor($prefix, $version);
        $this->assertFalse($queryable('cpe:2.3:o:microsoft:windows_server_2022', '10.0.20348'));
        $this->assertTrue($queryable('cpe:2.3:o:debian:debian_linux', '12.0'));
        $this->assertFalse($queryable('cpe:2.3:o:debian:debian_linux', ''));
        $this->assertTrue($queryable('cpe:2.3:a:demo:demo_app', '10.0.1'), 'Logiciel inchangé.');
    }

    public function testLinuxReleasesAreComparedToTheExactCpeVersion(): void
    {
        $debian12 = $this->affectedCpe('cpe:2.3:o:debian:debian_linux:12.0:*:*:*:*:*:*:*');
        $this->assertSame(CpeVersionMatch::MATCHED, OperatingSystemVersion::evaluate($debian12, '12.0'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, OperatingSystemVersion::evaluate($debian12, '11.0'));

        $ubuntu = $this->affectedCpe('cpe:2.3:o:canonical:ubuntu_linux:22.04:*:*:*:lts:*:*:*');
        $this->assertSame(CpeVersionMatch::MATCHED, OperatingSystemVersion::evaluate($ubuntu, '22.04'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, OperatingSystemVersion::evaluate($ubuntu, '24.04'));

        $rhel = $this->affectedCpe(
            'cpe:2.3:o:redhat:enterprise_linux:*:*:*:*:*:*:*:*',
            ['version_start_including' => '8.0', 'version_end_excluding' => '9.0']
        );
        $this->assertSame(CpeVersionMatch::MATCHED, OperatingSystemVersion::evaluate($rhel, '8.0'));
        $this->assertSame(CpeVersionMatch::EXCLUDED, OperatingSystemVersion::evaluate($rhel, '9.0'));

        $any = $this->affectedCpe('cpe:2.3:o:microsoft:windows_server_2019:-:*:*:*:*:*:*:*');
        $this->assertSame(
            CpeVersionMatch::UNKNOWN,
            OperatingSystemVersion::evaluate($any, '10.0.17763.6414'),
            'Aucune version publiée : jamais « touché » par supposition.'
        );
    }
}
