<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Config;

use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1036 : page de configuration en onglets, blocs déclarés par fichier.
 */
final class ConfigTabsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testTheSevenTabsAreDiscoveredInOrder(): void
    {
        $keys = array_map(static fn (string $tab): string => $tab::key(), ConfigTabs::tabClasses());
        $this->assertSame(
            ['general', 'frameworks', 'risks', 'controls', 'people', 'connectors', 'assets'],
            $keys
        );
    }

    public function testEveryBlockBelongsToAKnownTabAndEveryTabHasABlock(): void
    {
        $tabs = array_map(static fn (string $tab): string => $tab::key(), ConfigTabs::tabClasses());
        $used = [];
        foreach (ConfigTabs::blockClasses() as $block) {
            $this->assertContains($block::tab(), $tabs, $block);
            $used[$block::tab()] = true;
        }
        foreach ($tabs as $tab) {
            $this->assertArrayHasKey($tab, $used, "onglet $tab sans bloc");
        }
    }

    public function testEveryBlockTemplateExistsAndEveryTemplateIsUsed(): void
    {
        $templates = [];
        foreach (ConfigTabs::blockClasses() as $block) {
            $template = $block::template();
            $this->assertStringStartsWith('@grcmanager/config/blocks/_', $template, $block);
            $file = self::ROOT . '/templates/' . substr($template, strlen('@grcmanager/'));
            $this->assertFileExists($file, $block);
            $templates[] = basename($file);
        }
        $this->assertSame(count($templates), count(array_unique($templates)), 'un gabarit par bloc');
        foreach (glob(self::ROOT . '/templates/config/blocks/_*.html.twig') ?: [] as $file) {
            $this->assertContains(basename($file), $templates, 'gabarit sans bloc : ' . basename($file));
        }
    }

    public function testAnchorsAreUniqueAndPresentInTheirTemplate(): void
    {
        $seen = [];
        foreach (ConfigTabs::blockClasses() as $block) {
            $html = (string) file_get_contents(
                self::ROOT . '/templates/' . substr($block::template(), strlen('@grcmanager/'))
            );
            foreach ($block::anchors() as $anchor) {
                $this->assertArrayNotHasKey($anchor, $seen, "identifiant $anchor en double");
                $seen[$anchor] = true;
                $this->assertStringContainsString('id="' . $anchor . '"', $html, $block);
            }
        }
    }

    public function testOldLinksToTheConfigurationOpenAKnownBlock(): void
    {
        $files = array_merge(
            glob(self::ROOT . '/src/*.php') ?: [],
            glob(self::ROOT . '/front/*.php') ?: [],
            glob(self::ROOT . '/templates/*.twig') ?: []
        );
        $map = ConfigTabs::anchorMap();
        foreach ($files as $file) {
            preg_match_all('/config\.php#([a-z0-9-]+)/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $anchor) {
                $this->assertArrayHasKey($anchor, $map, basename($file) . " : config.php#$anchor");
            }
        }
        $this->assertSame('risks', ConfigTabs::tabOfAnchor('grc-risk-appetite-config'));
        // Issue #1039 : la fin de vie des modèles est sortie de la configuration (Parc > Sécurité du parc).
        $this->assertSame(
            '/plugins/grcmanager/front/hardwaremodeleol.php',
            ConfigTabs::anchorUrl('grc-hardware-eol-config', false)
        );
        $this->assertArrayHasKey('grc-hardware-eol-config', $map);
        $this->assertSame('', ConfigTabs::tabOfAnchor('grc-hardware-eol-config'));
        $this->assertSame('/plugins/grcmanager/front/config.php', ConfigTabs::anchorUrl('absent', false));
    }

    public function testBlocksKeepTheirPlaceInsideATab(): void
    {
        $keys = array_map(static fn (string $block): string => $block::key(), ConfigTabs::blocksOf('risks'));
        $this->assertSame(['RiskMatrix', 'RiskAppetite', 'KriAlert', 'ConsistencyRules'], $keys);
    }
}
