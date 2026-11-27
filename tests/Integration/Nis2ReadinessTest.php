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

use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\Dashboard;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Nis2Readiness;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as Base;
use GlpiPlugin\Grcmanager\Services\Readiness\Nis2ReadinessRules as Rules;

/**
 * Issue #1158 : parcours de mise en conformité NIS2 sur des données fictives : critères, articles,
 * liens vers les écrans avec leur chemin dans le menu, reprise des étapes ISO 27001, droits,
 * emplacement dans la rubrique Pilotage. Indépendant de la langue : seuls les clés, statuts,
 * articles et adresses sont comparés.
 */
final class Nis2ReadinessTest extends GrcmanagerIntegrationTestCase
{
    use ForcesEnglishLanguage;

    /** @var array<string, bool> réglages des référentiels avant le test */
    private array $frameworks = [];

    protected function setUp(): void
    {
        parent::setUp();
        FrameworkConfig::reset();
        foreach ([FrameworkSelection::NIS2, FrameworkSelection::RECYF] as $framework) {
            $this->frameworks[$framework] = FrameworkConfig::isEnabled($framework);
        }
        $this->enable(FrameworkSelection::NIS2, true);
        $this->enable(FrameworkSelection::RECYF, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->frameworks as $framework => $enabled) {
            $this->enable($framework, $enabled);
        }
        parent::tearDown();
    }

    private function enable(string $framework, bool $enabled): void
    {
        FrameworkConfig::setEnabled($framework, $enabled);
        FrameworkConfig::reset();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function criteria(): array
    {
        return array_column(Nis2Readiness::report()['steps'], null, 'key');
    }

    public function testCriteriaCiteTheDirectiveAndLeadToAScreenWithItsMenuPath(): void
    {
        $criteria = $this->criteria();
        $this->assertSame(Rules::criteria(true), array_keys($criteria));
        foreach ($criteria as $key => $criterion) {
            $this->assertSame(Rules::CRITERIA[$key]['articles'], $criterion['articles'], $key);
            $this->assertContains($criterion['status'], Base::STATUSES, $key);
            $this->assertNotSame('', $criterion['title'], $key);
            if ($criterion['status'] === Base::STATUS_LOCKED) {
                continue;
            }
            $this->assertNotEmpty($criterion['links'], $key . ' : au moins un écran à ouvrir');
            $first = $criterion['links'][0];
            $this->assertStringStartsWith('/', parse_url($first['url'], PHP_URL_PATH) ?: '', $key);
            $this->assertNotSame('', $first['path'], $key . ' : chemin du menu');
            $this->assertStringContainsString(' > ', $first['path'], $key);
        }
        $this->assertStringContainsString('recyfrequirement.php', $criteria['recyf_coverage']['links'][0]['url']);
        $this->assertStringContainsString('periodicreview', $criteria['recurring']['links'][0]['url']);
    }

    public function testRecyfCriteriaDisappearWithoutTheReCyF(): void
    {
        $this->enable(FrameworkSelection::RECYF, false);
        $report = Nis2Readiness::report();
        $keys = array_column($report['steps'], 'key');
        $this->assertFalse($report['recyf_enabled']);
        $this->assertNotContains('recyf_coverage', $keys);
        $this->assertNotContains('category', $keys);
        $this->assertContains('incidents', $keys);
    }

    public function testIsoStepsAreReusedAsIs(): void
    {
        global $DB;

        $DB->delete(Risk::getTable(), ['id' => ['>', 0]]);
        $iso = CertificationReadiness::stepEvaluations();
        $criteria = $this->criteria();
        $this->assertSame(
            Rules::combine([$iso['risk_assessment']['status'], $iso['risk_treatment']['status']]),
            $criteria['risk']['status']
        );
        $this->assertSame(Base::STATUS_TODO, $criteria['risk']['status'], 'registre des risques vide');
        $this->assertSame($iso['awareness']['status'], $criteria['training']['status']);
    }

    public function testCriteriaOutsideTheRightsAreLockedAndNotCounted(): void
    {
        $sensitive = $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        try {
            $report = Nis2Readiness::report();
            $criteria = array_column($report['steps'], null, 'key');
            $this->assertSame(Base::STATUS_LOCKED, $criteria['risk']['status']);
            $this->assertSame([], $criteria['risk']['facts']);
            $this->assertSame(
                Base::score(array_column($report['steps'], 'status')),
                $report['summary']['score']
            );
            $this->assertNotSame('risk', $report['summary']['next']['key'] ?? null);
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = $sensitive;
        }
    }

    public function testPageIsATabOfTheDashboardSectionOnlyWithNis2(): void
    {
        $this->assertContains(Nis2Readiness::class, Menu::sections()[Dashboard::class]);
        $this->assertTrue(Nis2Readiness::canView());
        $this->assertIsArray(Nis2Readiness::getMenuContent());
        $this->assertStringEndsWith('/front/nis2readiness.php', Nis2Readiness::getSearchURL());

        $this->enable(FrameworkSelection::NIS2, false);
        $this->assertFalse(Nis2Readiness::canView());
        $this->assertFalse(Nis2Readiness::getMenuContent());
    }
}
