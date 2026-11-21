<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Policy;

use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyTemplateCatalog as Catalog;
use PHPUnit\Framework\TestCase;

/** Issue #545 : catalogue de modèles de politiques prêts à adapter. */
final class PolicyTemplateCatalogTest extends TestCase
{
    private const EXPECTED = [
        'isms', 'access_control', 'asset_management', 'backup', 'logging', 'incident_management',
        'supplier', 'teleworking', 'secure_development', 'cryptography', 'continuity', 'classification',
    ];

    public function testCatalogListsTheTwelveTemplatesWithKnownAnnexAControls(): void
    {
        self::assertSame(self::EXPECTED, Catalog::keys());
        foreach (Catalog::STRUCTURE as $key => $sections) {
            self::assertGreaterThanOrEqual(3, count($sections), $key);
            foreach ($sections as $controls) {
                foreach ($controls as $code) {
                    self::assertArrayHasKey($code, ControlCatalogDefaults::CONTROLS, $key . ' ' . $code);
                }
            }
        }
    }

    public function testEveryLanguageHasTheSameStructureAndNoLeftoverVariable(): void
    {
        foreach (Catalog::LANGUAGES as $language) {
            $data = Catalog::load($language);
            self::assertSame(Catalog::LICENSE, $data['license'] ?? null, $language);
            $templates = Catalog::templates($data);
            self::assertSame(self::EXPECTED, array_keys($templates), $language);
            foreach ($templates as $key => $template) {
                self::assertNotSame('', $template['summary'], $language . ' ' . $key);
                self::assertSame(
                    array_merge(array_keys(Catalog::STRUCTURE[$key]), [Catalog::COMMON_REVIEW]),
                    array_column($template['sections'], 'key'),
                    $language . ' ' . $key
                );
                self::assertContains('A.5.1', $template['controls'], 'chaque politique relève de A.5.1');
                $text = Catalog::render($template, $data, ['organisation' => 'Fictive', 'ciso' => 'Alice']);
                self::assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $text, $language . ' ' . $key);
                self::assertStringNotContainsString("\u{2014}", $text, 'pas de tiret cadratin');
                foreach ($template['sections'] as $section) {
                    self::assertNotSame('', $section['body'], $language . ' ' . $key . ' ' . $section['key']);
                }
            }
        }
    }

    public function testSectionsAreNumberedAndCarryTheirControls(): void
    {
        $templates = Catalog::templates(Catalog::load('en'));
        $access = $templates['access_control'];
        self::assertSame('1. Purpose and scope', $access['sections'][0]['title']);
        self::assertSame([], $access['sections'][0]['controls']);
        self::assertSame(['A.5.17', 'A.8.5'], $access['sections'][3]['controls']);
        self::assertSame('6. Approval, review and exceptions', $access['sections'][5]['title']);
        self::assertSame(['A.5.1'], $access['sections'][5]['controls']);
        self::assertSame(
            ['A.5.1', 'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3', 'A.8.5'],
            $access['controls']
        );
    }

    public function testFillReplacesVariablesAndMarksEmptyOnes(): void
    {
        $data = Catalog::load('en');
        $text = '{organisation} / {ciso} / {review_months}';
        $values = ['organisation' => 'Fictive Ltd', 'ciso' => 'Alice Martin', 'review_months' => 6];
        self::assertSame('Fictive Ltd / Alice Martin / 6', Catalog::fill($text, $data, $values));
        self::assertSame(
            '[organisation name] / [information security manager] / 12',
            Catalog::fill($text, $data, ['organisation' => '  '])
        );
    }

    public function testLanguageReviewPeriodAndRequestedKeys(): void
    {
        self::assertSame('fr', Catalog::language(null, 'fr_CA'));
        self::assertSame('en', Catalog::language(null, 'de_DE'));
        self::assertSame('en', Catalog::language('en', 'fr_FR'));
        self::assertSame('fr', Catalog::language('xx', 'fr_FR'));
        self::assertSame(12, Catalog::reviewMonths('abc'));
        self::assertSame(1, Catalog::reviewMonths(0));
        self::assertSame(36, Catalog::reviewMonths(120));
        self::assertSame(24, Catalog::reviewMonths('24'));
        self::assertSame(['isms', 'backup'], Catalog::requested(['backup', 'unknown', 'isms', 'isms']));
    }
}
