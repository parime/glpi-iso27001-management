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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\Config\Block\AuditProgrammeBlock;
use GlpiPlugin\Grcmanager\Config\Block\CertificationReadinessBlock;
use GlpiPlugin\Grcmanager\Config\Block\DoraRegisterBlock;
use GlpiPlugin\Grcmanager\Config\Block\EvidencePackBlock;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\SettingsLink;
use GlpiPlugin\Grcmanager\DoraRegister;
use GlpiPlugin\Grcmanager\EvidencePack;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules;

/**
 * Issue #1045 : réglages du programme d'audit, de la préparation à la certification, du dossier
 * de preuves et de l'entité DORA dans la configuration du plugin ; les pages d'exploitation
 * gardent un lien « Régler ». Textes comparés en anglais (la CI tourne en anglais).
 */
final class AuditEvidenceSettingsBlocksTest extends GrcmanagerIntegrationTestCase
{
    private const BUTTONS = [
        AuditProgrammeBlock::class         => 'update_audit_programme',
        CertificationReadinessBlock::class => 'update_readiness_settings',
        EvidencePackBlock::class           => 'update_evidence_pack',
        DoraRegisterBlock::class           => 'update_dora_entity',
    ];

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION;
        FrameworkConfig::reset();
        DoraRegister::resetCache();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        parent::tearDown();
        FrameworkConfig::reset();
        DoraRegister::resetCache();
    }

    private function enableDora(bool $enabled): void
    {
        FrameworkConfig::setEnabled(FrameworkSelection::DORA, $enabled);
        FrameworkConfig::reset();
        DoraRegister::resetCache();
    }

    /** @param class-string<ConfigBlock> $block */
    private function renderBlock(string $block): string
    {
        return TemplateRenderer::getInstance()->render($block::template(), $block::data() + [
            'csrf_token'         => 'token',
            'can_edit_config'    => true,
            'can_edit_sensitive' => true,
        ]);
    }

    public function testBlocksAreInTheirTabAndOldAnchorsOpenThem(): void
    {
        $this->assertSame('controls', ConfigTabs::tabOfAnchor('grc-audit-programme-settings'));
        $this->assertSame('controls', ConfigTabs::tabOfAnchor('grc-readiness-settings'));
        $this->assertSame('controls', ConfigTabs::tabOfAnchor('grc-evidence-pack-settings'));
        $this->assertSame('frameworks', ConfigTabs::tabOfAnchor('grc-dora-settings'));

        $keys = array_map(static fn (string $b): string => $b::key(), ConfigTabs::blocksOf('controls'));
        foreach (['AuditProgramme', 'CertificationReadiness', 'EvidencePack'] as $key) {
            $this->assertContains($key, $keys);
        }

        $link = SettingsLink::data(CertificationReadinessBlock::ANCHOR);
        $this->assertStringEndsWith('/front/config.php?tab=controls#grc-readiness-settings', $link['url']);
        $this->assertStringContainsString('GRC Manager', $link['path']);
        $this->assertStringContainsString(__('Controls, audits and evidence', 'grcmanager'), $link['path']);
    }

    public function testBlocksRenderTheirFormWithTheRightToUpdateOnly(): void
    {
        $this->enableDora(true);
        foreach (self::BUTTONS as $block => $button) {
            $this->assertTrue($block::canView(), $block);
            $html = $this->renderBlock($block);
            $this->assertStringContainsString('id="' . $block::anchors()[0] . '"', $html, $block);
            $this->assertStringContainsString('name="' . $button . '"', $html, $block);
            $this->assertStringContainsString('front/config.php', $html, $block);
        }

        // Lecture seule : blocs affichés, sans bouton d'enregistrement.
        $_SESSION['glpiactiveprofile'][EvidencePack::RIGHTNAME] = READ;
        DoraRegister::resetCache();
        foreach (self::BUTTONS as $block => $button) {
            $this->assertTrue($block::canView(), $block);
            $this->assertStringNotContainsString('name="' . $button . '"', $this->renderBlock($block), $block);
        }

        // Sans droit sur le plugin : aucun bloc.
        $_SESSION['glpiactiveprofile'][EvidencePack::RIGHTNAME] = 0;
        foreach (array_keys(self::BUTTONS) as $block) {
            $this->assertFalse($block::canView(), $block);
        }
    }

    public function testDoraBlockOnlyWhileDoraIsEnabled(): void
    {
        $this->enableDora(false);
        $this->assertFalse(DoraRegisterBlock::canView());
        $this->enableDora(true);
        $this->assertTrue(DoraRegisterBlock::canView());
        $this->assertStringEndsWith(
            '/front/config.php?tab=frameworks#grc-dora-settings',
            SettingsLink::data(DoraRegisterBlock::ANCHOR)['url']
        );

        // Champs de l'entité manquants : ils mènent au bloc de la configuration.
        DoraRegister::saveSettings([]);
        $issues = DoraRegister::settingsData()['dora_entity_issues'];
        $this->assertNotEmpty($issues);
        foreach ($issues as $issue) {
            $this->assertSame('entity', $issue['scope']);
            $this->assertStringContainsString('config.php?tab=frameworks#grc-dora-settings', $issue['url']);
        }

        ob_start();
        DoraRegister::showRegisterPage();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-dora-settings-link', $html);
        $this->assertStringNotContainsString('name="save_settings"', $html);
    }

    public function testReadinessSettingsAreSavedAndListEveryStep(): void
    {
        CertificationReadiness::saveSettings(['coverage_target' => 75, 'alert_days' => 20]);
        $data = CertificationReadiness::settingsData();
        $this->assertSame(75, $data['settings']['coverage_target']);
        $this->assertSame(20, $data['settings']['alert_days']);
        $this->assertCount(count(CertificationReadinessRules::STEPS), $data['lead_rows']);
        $this->assertSame(1, $data['lead_rows'][0]['number']);
    }

    public function testAuditProgrammeSettingsAreSaved(): void
    {
        $year = (int) array_key_first(AuditProgramme::startChoices());
        AuditProgramme::saveSettings(['cycle_start' => $year, 'training_ids' => []]);
        $this->assertSame($year, AuditProgramme::cycleStart());
        $this->assertSame($year, AuditProgrammeBlock::data()['programme_start']);
    }
}
