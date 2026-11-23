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

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\CrisisContinuity;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\CrisisMessageTemplate;
use GlpiPlugin\Grcmanager\CrisisScenario;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use GlpiPlugin\Grcmanager\Playbook;
use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\SentinelOneAlert;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #1041 : « Assistance > Incidents de sécurité » (juste après Changements, outils en onglets)
 * et « Assistance > Crise et continuité » (juste après, page d'accueil à cartes) ; les actions
 * SentinelOne d'exploitation ne sont plus dans la configuration.
 */
final class AssistanceSectionsTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sections::reset();
    }

    protected function tearDown(): void
    {
        Sections::reset();
        parent::tearDown();
    }

    public function testSecurityIncidentToolsAreTabsOfTheirSection(): void
    {
        $pages = array_keys(Sections::pages(PluginGrcmanagerSecurityIncident::class));
        $this->assertSame([Playbook::class, SecurityIncidentEdrImport::class, AuditLog::class], $pages);
        foreach ($pages as $page) {
            $this->assertSame(
                ['helpdesk', PluginGrcmanagerSecurityIncident::class, $page],
                Menu::sectorPath($page),
                "$page : fil d'Ariane Assistance > Incidents de sécurité."
            );
        }
        // Plus de liens d'en-tête vers ces outils : ce sont des onglets.
        $menu = PluginGrcmanagerSecurityIncident::getMenuContent();
        $this->assertIsArray($menu);
        $links = implode(' ', array_map('strval', (array) ($menu['links'] ?? [])));
        $this->assertStringNotContainsString('playbook.php', $links);
        $this->assertStringNotContainsString('securityincidentedrimport.php', $links);
        $this->assertStringNotContainsString('auditlog.php', $links);
        $this->assertStringNotContainsString('crisismessagetemplate.php', $links);
        $this->assertArrayHasKey(Playbook::class, $menu['options'] ?? []);
    }

    public function testCrisisAndContinuitySectionGathersTheCrisisScreens(): void
    {
        $pages = array_keys(Sections::pages(CrisisContinuity::class));
        $this->assertSame([
            BusinessProcess::class,
            CrisisScenario::class,
            // Issue #1083 : plans de continuité et de reprise, groupe « Se préparer ».
            ContinuityPlan::class,
            CrisisExercise::class,
            CrisisContinuity::RECOVERY_PROGRAMME,
            CrisisMessageTemplate::class,
        ], $pages);
        // Issue #1083 : 6 onglets sur 7 au plus.
        $this->assertLessThanOrEqual(Sections::MAX_PAGES, count($pages));
        foreach ($pages as $page) {
            $this->assertSame(['helpdesk', CrisisContinuity::class, $page], Menu::sectorPath($page), "$page");
        }
        // Retirés de la rubrique « Opérations » du menu GRC.
        $operations = array_keys(Sections::pages(AccessReview::class));
        foreach ([BusinessProcess::class, CrisisScenario::class, CrisisExercise::class] as $moved) {
            $this->assertNotContains($moved, $operations, "$moved : plus dans Opérations.");
        }
    }

    public function testEntriesFollowChangesInTheAssistanceMenu(): void
    {
        $incident = strtolower(PluginGrcmanagerSecurityIncident::class);
        $crisis = strtolower(CrisisContinuity::class);
        // GLPI ajoute les entrées des plugins à la fin du secteur.
        $menu = ['helpdesk' => ['content' => [
            'ticket'          => [],
            'problem'         => [],
            'change'          => [],
            'recurrentchange' => [],
            $incident         => [],
            $crisis           => [],
        ]]];
        $enabled = (bool) SecurityIncidentModuleConfig::load()['securityincident_enabled'];
        // Module des incidents désactivé : son entrée n'est pas au menu, Crise et continuité suit Changements.
        $this->assertSame(
            $enabled
                ? ['ticket', 'problem', 'change', $incident, $crisis, 'recurrentchange']
                : ['ticket', 'problem', 'change', $crisis, $incident, 'recurrentchange'],
            array_keys(Sections::reorderMenu($menu)['helpdesk']['content'])
        );

        // Incidents absents du menu (droit manquant) : Crise et continuité prend leur place.
        unset($menu['helpdesk']['content'][$incident]);
        $this->assertSame(
            ['ticket', 'problem', 'change', $crisis, 'recurrentchange'],
            array_keys(Sections::reorderMenu($menu)['helpdesk']['content'])
        );
    }

    public function testOverviewShowsGroupedCardsForTheProfile(): void
    {
        $this->assertTrue(CrisisContinuity::canView());
        $menu = CrisisContinuity::getMenuContent();
        $this->assertIsArray($menu);
        $this->assertSame('/plugins/grcmanager/front/crisiscontinuity.php', $menu['page']);
        $groups = CrisisContinuity::hubData(date('Y-m-d'));
        $this->assertCount(3, $groups);
        $urls = [];
        foreach ($groups as $group) {
            $this->assertNotSame([], $group['cards']);
            foreach ($group['cards'] as $card) {
                $urls[] = (string) $card['url'];
            }
        }
        $this->assertCount(6, $urls);

        // Sans aucun droit du plugin : ni page, ni carte.
        $saved = $_SESSION['glpiactiveprofile'];
        try {
            foreach (array_keys($_SESSION['glpiactiveprofile']) as $right) {
                if (str_starts_with((string) $right, 'plugin_grcmanager')) {
                    $_SESSION['glpiactiveprofile'][$right] = 0;
                }
            }
            $this->assertFalse(CrisisContinuity::canView());
            $this->assertSame([], CrisisContinuity::hubData(date('Y-m-d')));
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
    }

    public function testSentinelOneOperationsLeftTheConfiguration(): void
    {
        $config = SentinelOneAlert::configData();
        $this->assertArrayNotHasKey('review_url', $config);
        $this->assertArrayNotHasKey('agents', $config);
        $this->assertArrayHasKey('url', $config);
        $this->assertArrayHasKey('has_token', $config);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/config/blocks/_sentinelone.html.twig');
        $this->assertStringNotContainsString('fetch_sentinelone_now', $template);
        $this->assertStringNotContainsString('review_url', $template);
        $this->assertStringContainsString('test_sentinelone_config', $template);
        $import = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/edr_import.html.twig');
        $this->assertStringContainsString('s1_fetch_now', $import);
    }
}
