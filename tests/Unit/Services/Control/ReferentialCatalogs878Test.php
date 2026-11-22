<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkCatalogMeasures;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Etsi319401CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Control\MonServiceSecuriseCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ScfCatalogDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Issue #878 : catalogues MonServiceSécurisé (ANSSI), ETSI EN 319 401 et Secure Controls Framework.
 */
final class ReferentialCatalogs878Test extends TestCase
{
    /**
     * @param array<int|string, array<string, mixed>> $entries
     * @param array<int|string, mixed> $groups
     * @param list<string> $modules
     * @param list<string> $withoutAnnexA entrées portées par les chapitres 4 à 10 d'ISO 27001
     */
    private function assertCatalog(
        array $entries,
        array $groups,
        string $groupField,
        array $modules,
        array $withoutAnnexA
    ): void {
        foreach ($entries as $code => $entry) {
            $code = (string) $code;
            self::assertArrayHasKey((string) $entry[$groupField], $groups, $code);
            self::assertNotSame('', trim((string) $entry['fr']), "$code fr");
            self::assertNotSame('', trim((string) $entry['en']), "$code en");
            if (in_array($code, $withoutAnnexA, true)) {
                self::assertSame([], $entry['annex_a'], $code);
            } else {
                self::assertNotEmpty($entry['annex_a'], $code);
            }
            foreach ($entry['annex_a'] as $annexACode) {
                self::assertArrayHasKey($annexACode, ControlCatalogDefaults::CONTROLS, "$code -> $annexACode");
            }
            foreach ($entry['modules'] as $module) {
                self::assertContains($module, $modules, "$code -> $module");
            }
        }
    }

    public function testMonServiceSecuriseHasThe135MeasuresOfVersion2(): void
    {
        $this->assertCatalog(
            MonServiceSecuriseCatalogDefaults::THEMES,
            MonServiceSecuriseCatalogDefaults::CATEGORIES,
            'category',
            MonServiceSecuriseCatalogDefaults::MODULES,
            ['RISQUE']
        );
        self::assertCount(29, MonServiceSecuriseCatalogDefaults::THEMES);
        self::assertSame(135, MonServiceSecuriseCatalogDefaults::measureCount());
        $all = [];
        foreach (MonServiceSecuriseCatalogDefaults::THEMES as $theme => $entry) {
            foreach ($entry['measures'] as $measure) {
                // Chaque identifiant commence par le préfixe de son thème (AUTH.1 dans AUTH).
                self::assertMatchesRegularExpression('/^' . preg_quote($theme, '/') . '\.\d+$/', $measure);
                $all[] = $measure;
            }
        }
        self::assertSame(count($all), count(array_unique($all)), 'Aucune mesure en double.');
    }

    public function testEtsiClausesFiveToSevenOfVersion321(): void
    {
        $this->assertCatalog(
            Etsi319401CatalogDefaults::REQUIREMENTS,
            Etsi319401CatalogDefaults::CLAUSES,
            'clause',
            Etsi319401CatalogDefaults::MODULES,
            ['5']
        );
        foreach (array_keys(Etsi319401CatalogDefaults::REQUIREMENTS) as $code) {
            $code = (string) $code;
            self::assertSame(explode('.', $code)[0], (string) Etsi319401CatalogDefaults::REQUIREMENTS[$code]['clause']);
        }
        self::assertArrayHasKey('7.14.3', Etsi319401CatalogDefaults::REQUIREMENTS, 'Chaîne d\'approvisionnement.');
        self::assertArrayHasKey('7.4.6', Etsi319401CatalogDefaults::REQUIREMENTS, 'Authentification forte.');
    }

    public function testScfDomainsAreThe34PublishedOnesUntranslated(): void
    {
        $this->assertCatalog(
            ScfCatalogDefaults::DOMAINS,
            ScfCatalogDefaults::GROUPS,
            'group',
            ScfCatalogDefaults::MODULES,
            ['RSK']
        );
        self::assertCount(34, ScfCatalogDefaults::DOMAINS);
        foreach (ScfCatalogDefaults::DOMAINS as $code => $domain) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $code);
            // Licence CC BY-ND : le nom publié n'est pas traduit.
            self::assertSame($domain['en'], $domain['fr'], $code);
        }
    }

    public function testReverseMappings(): void
    {
        self::assertSame(['DISTANCE', 'AUTH'], MonServiceSecuriseCatalogDefaults::themesForControl('A.8.5'));
        self::assertSame(['7.14.1'], Etsi319401CatalogDefaults::requirementsForControl('A.5.19'));
        self::assertSame(['VPM'], ScfCatalogDefaults::domainsForControl('A.8.8'));
        self::assertSame([], ScfCatalogDefaults::domainsForControl('A.7.12'));
    }

    public function testFrameworksAreOptionalAndListedInTheAssessment(): void
    {
        foreach ([FrameworkSelection::MSS, FrameworkSelection::ETSI_319401, FrameworkSelection::SCF] as $key) {
            self::assertContains($key, FrameworkSelection::OPTIONAL, $key);
            self::assertArrayHasKey($key, FrameworkSelection::FRAMEWORKS, $key);
            self::assertNotSame([], FrameworkCatalogMeasures::measures($key, 'en'), $key);
        }
        $codes = array_column(FrameworkCatalogMeasures::measures(FrameworkSelection::ETSI_319401, 'en'), 'code');
        self::assertContains('5', $codes);
        self::assertContains('7.9.3', $codes);
    }
}
