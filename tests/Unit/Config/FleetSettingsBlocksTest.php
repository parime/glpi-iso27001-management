<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Config;

use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1044 : règles et seuils du parc dans l'onglet Parc de la configuration. Les pages
 * d'exploitation n'ont plus de formulaire de réglage ; leur ancien envoi passe par le bloc.
 */
final class FleetSettingsBlocksTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** Bloc, page d'exploitation, gabarit de la page, bouton d'envoi du réglage. */
    private const MOVED = [
        'DiskEncryption' => ['diskencryption.php', 'disk_encryption.html.twig', 'save_encryption_rule'],
        'PatchTargets'   => ['patchcompliance.php', 'patch_compliance.html.twig', 'save_patch_targets'],
        'LicenseWatch'   => [
            'softwarelicensecompliance.php',
            'software_license_compliance.html.twig',
            'save_license_watch',
        ],
        'LogCoverage'    => ['logcoverage.php', 'log_coverage.html.twig', 'save_log_settings'],
    ];

    public function testTheFourBlocksAreInTheFleetTabAfterTheExistingOnes(): void
    {
        $keys = array_map(static fn (string $block): string => $block::key(), ConfigTabs::blocksOf('assets'));
        $this->assertSame(
            ['FleetVulnerabilities', 'HardwareEol', 'DiskEncryption', 'PatchTargets', 'LicenseWatch', 'LogCoverage'],
            $keys
        );
    }

    public function testOldAnchorsOpenTheFleetTab(): void
    {
        foreach (['grc-encryption-rule', 'grc-patch-targets', 'grc-license-watch', 'grc-log-settings'] as $anchor) {
            $this->assertSame('assets', ConfigTabs::tabOfAnchor($anchor), $anchor);
            $this->assertSame(
                '/plugins/grcmanager/front/config.php?tab=assets#' . $anchor,
                ConfigTabs::anchorUrl($anchor, false)
            );
        }
    }

    public function testTheSettingFormsLeftTheOperationPages(): void
    {
        foreach (self::MOVED as $block => [$page, $template, $button]) {
            $blockHtml = (string) file_get_contents(
                self::ROOT . '/templates/config/blocks/_' . $this->snake($block) . '.html.twig'
            );
            $this->assertStringContainsString('name="' . $button . '"', $blockHtml, $block);

            $pageHtml = (string) file_get_contents(self::ROOT . '/templates/' . $template);
            $this->assertStringNotContainsString('name="' . $button . '"', $pageHtml, $template);
            $this->assertStringContainsString('settings_url', $pageHtml, $template);

            // L'ancien envoi reste accepté une version : il est confié au bloc.
            $front = (string) file_get_contents(self::ROOT . '/front/' . $page);
            $this->assertStringContainsString('Block\\' . $block . 'Block::handle(true)', $front, $page);
        }
    }

    public function testTheRecoveryKeyDeclarationStaysOnTheEncryptionPage(): void
    {
        $html = (string) file_get_contents(self::ROOT . '/templates/disk_encryption.html.twig');
        $this->assertStringContainsString('name="declare_key"', $html);
        $front = (string) file_get_contents(self::ROOT . '/front/logcoverage.php');
        $this->assertStringContainsString("\$_POST['import_log_hosts']", $front);
        $this->assertStringContainsString("\$_POST['declare_log_platform']", $front);
    }

    private function snake(string $key): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }
}
