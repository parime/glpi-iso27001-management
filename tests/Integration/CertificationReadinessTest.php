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
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as Rules;

/**
 * Issue #619 : préparation à la certification sur des données fictives : ordre des étapes, état
 * calculé à partir des modules, droits et entités, lecture seule, PDF et carte du tableau de bord.
 * Indépendant de la langue : seuls les clés, statuts et liens sont comparés.
 */
final class CertificationReadinessTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return array<string, array<string, mixed>> étape => données de l'étape
     */
    private function steps(): array
    {
        $steps = [];
        foreach (CertificationReadiness::report()['steps'] as $step) {
            $steps[$step['key']] = $step;
        }

        return $steps;
    }

    private function emptyRiskRegister(): void
    {
        global $DB;

        $DB->delete(Risk::getTable(), ['id' => ['>', 0]]);
    }

    public function testStepsAreOrderedAndEachLeadsToAScreen(): void
    {
        global $CFG_GLPI;

        $report = CertificationReadiness::report();
        $this->assertSame(array_keys(Rules::STEPS), array_column($report['steps'], 'key'));
        $this->assertSame(range(1, count(Rules::STEPS)), array_column($report['steps'], 'number'));
        $this->assertSame(Rules::PHASES, array_column($report['phases'], 'key'));
        $this->assertSame(count(Rules::STEPS), array_sum($report['summary']['counts']));

        foreach ($report['steps'] as $step) {
            $this->assertContains($step['status'], Rules::STATUSES, $step['key']);
            $this->assertNotSame(Rules::STATUS_LOCKED, $step['status'], $step['key'] . ' : super-admin');
            $this->assertNotSame('', $step['title']);
            $this->assertNotSame('', $step['expected']);
            $this->assertNotEmpty($step['facts'], $step['key']);
            $this->assertNotEmpty($step['links'], $step['key'] . ' : lien vers l\'écran à compléter');
            foreach ($step['links'] as $link) {
                $this->assertStringStartsWith(
                    $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/',
                    $link['url'],
                    $step['key']
                );
            }
        }
        $next = $report['summary']['next'];
        if ($next !== null) {
            $this->assertSame(Rules::nextStep(array_column($report['steps'], 'status', 'key')), $next['key']);
        }
    }

    public function testRiskStepFollowsTheRegister(): void
    {
        global $DB;

        $this->emptyRiskRegister();
        $this->assertSame(Rules::STATUS_TODO, $this->steps()['risk_assessment']['status'], 'registre vide');

        $DB->insert(Risk::getTable(), [
            'title' => 'Risque fictif sans propriétaire 619', 'status' => 'identified', 'users_id' => 0,
            'risk_level' => 'low', 'review_date' => date('Y-m-d', strtotime('+100 days')), 'entities_id' => 0,
        ]);
        $riskId = (int) $DB->insertId();
        $this->assertSame(Rules::STATUS_PARTIAL, $this->steps()['risk_assessment']['status'], 'sans propriétaire');

        $DB->update(Risk::getTable(), ['users_id' => 2], ['id' => $riskId]);
        $steps = $this->steps();
        $this->assertSame(Rules::STATUS_DONE, $steps['risk_assessment']['status']);
        $this->assertStringContainsString('risk.php', $steps['risk_assessment']['links'][0]['url']);
    }

    public function testScopeFollowsTheDeclaredRequirement(): void
    {
        global $DB;

        $row = $DB->request(['FROM' => IsmsRequirement::getTable(), 'WHERE' => ['code' => '4.3']])->current();
        $this->assertNotNull($row, 'exigence 4.3 installée');
        $DB->update(IsmsRequirement::getTable(), ['status' => IsmsClauseCatalog::STATUS_NOT_STARTED], [
            'id' => (int) $row['id'],
        ]);
        $DB->delete('glpi_documents_items', ['itemtype' => IsmsRequirement::class, 'items_id' => (int) $row['id']]);
        $step = $this->steps()['scope'];
        $this->assertSame(Rules::STATUS_TODO, $step['status']);
        $this->assertStringContainsString('ismsrequirement.form.php?id=' . (int) $row['id'], $step['links'][0]['url']);

        $DB->update(IsmsRequirement::getTable(), ['status' => IsmsClauseCatalog::STATUS_COMPLIANT], [
            'id' => (int) $row['id'],
        ]);
        $this->assertSame(Rules::STATUS_DONE, $this->steps()['scope']['status']);
    }

    public function testStepsOutsideTheRightsAreLockedAndNotCounted(): void
    {
        $sensitive = $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        try {
            $report = CertificationReadiness::report();
            $steps = array_column($report['steps'], null, 'key');
            foreach (['risk_assessment', 'risk_treatment'] as $key) {
                $this->assertSame(Rules::STATUS_LOCKED, $steps[$key]['status'], $key);
                $this->assertSame([], $steps[$key]['facts'], $key);
                $this->assertSame([], $steps[$key]['links'], $key);
            }
            $this->assertSame(2, $report['summary']['counts'][Rules::STATUS_LOCKED]);
            $this->assertSame(count(Rules::STEPS) - 2, $report['summary']['evaluated']);
            $this->assertSame(
                Rules::score(array_column($report['steps'], 'status')),
                $report['summary']['score']
            );
            $this->assertNotSame('risk_assessment', $report['summary']['next']['key'] ?? null);
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = $sensitive;
        }
    }

    public function testRisksOfAnotherEntityAreNotCounted(): void
    {
        global $DB;

        $this->emptyRiskRegister();
        $entityA = $this->createTestEntity(0, 'Entité fictive A 619');
        $entityB = $this->createTestEntity(0, 'Entité fictive B 619');
        $DB->insert(Risk::getTable(), [
            'title' => 'Risque fictif de B 619', 'status' => 'identified', 'users_id' => 2,
            'risk_level' => 'low', 'review_date' => date('Y-m-d', strtotime('+100 days')), 'entities_id' => $entityB,
        ]);
        $active = $_SESSION['glpiactiveentities'];
        $activeString = $_SESSION['glpiactiveentities_string'];
        $parents = $_SESSION['glpiparententities'] ?? [];
        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        // Entité active sous la racine : GLPI ajoute la condition sur les parents (is_recursive).
        $_SESSION['glpiparententities'] = [0 => 0];
        try {
            $steps = $this->steps();
            $this->assertSame(Rules::STATUS_TODO, $steps['risk_assessment']['status'], 'risque de B ignoré');
            $this->assertNotSame(Rules::STATUS_LOCKED, $steps['exercises']['status'], 'exercices sans is_recursive');
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
            $_SESSION['glpiactiveentities_string'] = $activeString;
            $_SESSION['glpiparententities'] = $parents;
        }
        $this->assertSame(Rules::STATUS_DONE, $this->steps()['risk_assessment']['status']);
    }

    public function testReportIsReadOnlyAndExportedAsPdfAndDashboardCard(): void
    {
        global $DB;

        $before = iterator_to_array($DB->request(['FROM' => IsmsRequirement::getTable(), 'ORDER' => 'id']));
        $report = CertificationReadiness::report();
        $after = iterator_to_array($DB->request(['FROM' => IsmsRequirement::getTable(), 'ORDER' => 'id']));
        $this->assertSame($before, $after);

        $html = CertificationReadiness::renderPdf($report);
        $this->assertStringContainsString(__('Assess the risks', 'grcmanager'), $html);
        $this->assertStringContainsString(__('Certification readiness report', 'grcmanager'), $html);
        $this->assertStringContainsString('grc-st-' . $report['steps'][0]['status'], $html);

        $card = SecuredDashboardCards::certificationReadinessScore();
        $this->assertSame($report['summary']['score'] ?? 0, $card['number']);
        $this->assertStringContainsString('certificationreadiness.php', $card['url']);
        $this->assertTrue(SecuredDashboardCards::canShow('certificationReadinessScore'));
    }

    public function testPageIsATabOfTheDashboardSection(): void
    {
        $this->assertContains(CertificationReadiness::class, Menu::sections()[Dashboard::class]);
        $menu = CertificationReadiness::getMenuContent();
        $this->assertIsArray($menu);
        $this->assertSame('/plugins/grcmanager/front/certificationreadiness.php', $menu['page']);
        $this->assertStringEndsWith('certificationreadiness.php?format=pdf', CertificationReadiness::getPdfURL());
    }
}
