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

use Config;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Config\Block\PluginVersionBlock;
use GlpiPlugin\Grcmanager\Config\Block\StartChecklistBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\StartChecklist;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\SoaScopingTables;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityConfig;
use GlpiPlugin\Grcmanager\Services\Control\SoaScopingRules;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneConfig;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaConfig;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\SoaScoping;
use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #1046 : liste de démarrage de l'onglet Général, calculée sur l'existant, sans rien modifier.
 */
final class StartChecklistTest extends GrcmanagerIntegrationTestCase
{
    public function testTheChecklistIsTheFirstBlockOfTheGeneralTabAndOnlyForConfigEditors(): void
    {
        $this->assertSame('general', ConfigTabs::tabOfAnchor('grc-start-checklist'));
        $general = array_values(array_filter(
            ConfigTabs::blockClasses(),
            static fn (string $class): bool => $class::tab() === 'general'
        ));
        // #1110 : l'encadré « Version du plugin » passe en tête, la liste de démarrage juste après.
        $this->assertSame(PluginVersionBlock::class, $general[0] ?? null);
        $this->assertSame(StartChecklistBlock::class, $general[1] ?? null);

        $rights = $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = READ;
        try {
            $this->assertFalse(StartChecklistBlock::canView(), 'Read-only users do not see it.');
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = $rights;
        }
        $this->assertTrue(StartChecklistBlock::canView());
    }

    public function testEachStepFollowsTheExistingSettings(): void
    {
        global $DB;

        $this->resetSteps();
        $state = StartChecklist::state();
        $this->assertFalse($state['ciso_group']);
        $this->assertFalse($state['connector']);
        $this->assertFalse($state['soa_scope']);
        $this->assertFalse($state['profiles']);

        $group = (int) (new \Group())->add(['name' => 'Test CISO group', 'entities_id' => 0]);
        AuditTeam::setCisoGroupId($group);
        ZabbixConfig::saveLastRead(['date' => '2026-01-15 10:00:00', 'error' => '']);
        $answers = [];
        foreach (array_keys(SoaScopingRules::QUESTIONS) as $question) {
            $answers[$question] = 'no';
        }
        SoaScoping::saveAnswers(SoaScoping::entity(), $answers);
        $profile = (int) (new \Profile())->add(['name' => 'Test ISMS contributor', 'interface' => 'central']);
        $DB->updateOrInsert(
            'glpi_profilerights',
            ['rights' => READ],
            ['profiles_id' => $profile, 'name' => Installer::RIGHT_NAME]
        );
        RiskMatrixConfig::save(RiskMatrixConfig::load());

        $state = StartChecklist::state();
        $this->assertTrue($state['ciso_group']);
        $this->assertTrue($state['connector']);
        $this->assertTrue($state['soa_scope']);
        $this->assertTrue($state['profiles']);
        $this->assertTrue($state['risk_matrix'], 'Saving the matrix, even unchanged, validates it.');
    }

    public function testTheBlockShowsThePercentageAndLinksOnlyToSettings(): void
    {
        $this->resetSteps();
        $checklist = StartChecklist::build();
        $this->assertFalse($checklist['complete']);
        $this->assertLessThan(100, $checklist['percent']);

        $html = TemplateRenderer::getInstance()->render(
            StartChecklistBlock::template(),
            StartChecklistBlock::data()
        );
        $this->assertStringContainsString('id="grc-start-checklist"', $html);
        $this->assertStringContainsString($checklist['percent'] . ' %', $html);
        $this->assertStringContainsString(' open', $html, 'Open while a step remains.');
        preg_match_all('/href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $link) {
            $this->assertMatchesRegularExpression(
                '#/front/(config\.php\?tab=|profile\.php|soascoping\.php)#',
                html_entity_decode($link)
            );
        }
        $this->assertCount(6, $links[1], 'Every step has a button (#1110: the SoA scoping opens its screen).');
    }

    /** Étapes remises « à faire » (la transaction du test annule ces écritures). */
    private function resetSteps(): void
    {
        global $DB;

        AuditTeam::setCisoGroupId(0);
        Config::setConfigurationValues('plugin:grcmanager', [
            ZabbixConfig::LAST_READ_KEY       => '',
            UptimeKumaConfig::LAST_READ_KEY   => '',
            CodeSecurityConfig::LAST_READ_KEY => '',
            SentinelOneConfig::LAST_RUN_KEY   => '',
        ]);
        $DB->delete(EndOfLifeStore::CACHE_TABLE, ['id' => ['>', 0]]);
        EndOfLifeStore::reset();
        $DB->delete(SoaScopingTables::ANSWERS, ['entities_id' => SoaScoping::entity()]);
        $DB->update('glpi_profilerights', ['rights' => 0], [
            'name' => Installer::RIGHT_NAME,
            'NOT'  => ['profiles_id' => 4],
        ]);
    }
}
