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

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlBlocker;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\Services\Control\ControlBlockerRules;

/**
 * Issue #867 : ce qui bloque une mesure, sur des données fictives. Indépendant de la langue : seuls
 * les identifiants, les catégories et les liens sont comparés.
 */
final class ControlBlockerTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return array<string, int>
     */
    private function seed(): array
    {
        global $DB;

        $ids = [];
        $controlIds = EntityPerimeter::controlIdsByCode();
        $ids['control'] = $controlIds['A.5.7'];
        $ids['policy_control'] = $controlIds['A.5.1'];
        $DB->update(Control::getTable(), ['applicability' => 'yes', 'implementation_status' => 'implemented'], [
            'id' => $ids['control'],
        ]);
        $DB->update(Control::getTable(), ['applicability' => 'yes'], ['id' => $ids['policy_control']]);
        $DB->delete('glpi_documents_items', ['itemtype' => Control::class, 'items_id' => $ids['control']]);
        $DB->delete(ControlTest::getTable(), ['plugin_grcmanager_controls_id' => $ids['control']]);
        // Dernier test inefficace, ancien : il bloque, et ne prouve pas la mesure.
        $DB->insert(ControlTest::getTable(), [
            'plugin_grcmanager_controls_id' => $ids['control'],
            'test_date'                     => date('Y-m-d', strtotime('-20 months')),
            'result'                        => 'ineffective',
        ]);
        $DB->insert(Nonconformity::getTable(), [
            'title'                 => 'Constat fictif 867',
            'status'                => 'open',
            'finding_type'          => 'nonconformity',
            'severity'              => 'minor',
            'requirement_reference' => 'Annexe A.5.7',
            'due_date'              => date('Y-m-d', strtotime('-10 days')),
        ]);
        $ids['nonconformity'] = (int) $DB->insertId();
        $DB->insert(Nonconformity::getTable(), [
            'title'                 => 'Constat fictif clos 867',
            'status'                => 'closed',
            'finding_type'          => 'observation',
            'requirement_reference' => 'A.5.7',
        ]);
        $ids['closed'] = (int) $DB->insertId();
        $DB->insert(Policy::getTable(), [
            'title' => 'Politique fictive brouillon 867', 'status' => 'draft', 'users_id' => 2,
        ]);
        $ids['policy'] = (int) $DB->insertId();

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $blockers
     * @return list<string>
     */
    private function categories(array $blockers): array
    {
        return array_values(array_unique(array_column($blockers, 'category')));
    }

    public function testEveryBlockerOfAControlIsListedWithALink(): void
    {
        $ids = $this->seed();
        $blockers = ControlBlocker::collect($ids['control'])[$ids['control']]['blockers'];

        $categories = $this->categories($blockers);
        $this->assertContains(ControlBlockerRules::CATEGORY_EVIDENCE, $categories, 'aucune preuve valide');
        $this->assertContains(ControlBlockerRules::CATEGORY_TEST, $categories, 'dernier test inefficace');
        $this->assertContains(ControlBlockerRules::CATEGORY_NONCONFORMITY, $categories, 'non-conformité ouverte');

        $urls = implode(' ', array_column($blockers, 'url')) . ' ';
        $this->assertStringContainsString('id=' . $ids['nonconformity'] . ' ', $urls, 'lien vers la non-conformité');
        $this->assertStringNotContainsString('id=' . $ids['closed'] . ' ', $urls, 'constat clos ignoré');
        foreach ($blockers as $blocker) {
            $this->assertNotSame('', $blocker['url']);
            $this->assertNotSame('', $blocker['name']);
            $this->assertArrayHasKey($blocker['severity'], ControlBlocker::getSeverityStyles());
        }
        $this->assertSame('high', $blockers[0]['severity'], 'du plus grave au moins grave');
    }

    public function testDraftPoliciesBlockThePolicyControl(): void
    {
        $ids = $this->seed();
        $blockers = ControlBlocker::collect($ids['policy_control'])[$ids['policy_control']]['blockers'];

        $policies = array_filter(
            $blockers,
            static fn (array $b): bool => $b['category'] === ControlBlockerRules::CATEGORY_POLICY
        );
        $policyUrls = array_column($policies, 'url');
        $this->assertContains(Policy::getFormURLWithID($ids['policy']), $policyUrls);
    }

    public function testGlobalViewListsOnlyBlockedControlsAndSorts(): void
    {
        $ids = $this->seed();
        $collected = ControlBlocker::collect();
        $rows = ControlBlocker::rows($collected, 'severity', 'ASC');

        $this->assertContains($ids['control'], array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row['summary']['total']);
            $this->assertStringContainsString('forcetab=', $row['url'], 'lien vers l\'onglet de la mesure');
        }
        $ranks = array_map(
            static fn (array $row): int => ControlBlockerRules::severityRank((string) $row['summary']['worst']),
            $rows
        );
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks);

        $byCode = array_column(ControlBlocker::rows($collected, 'code', 'ASC'), 'code');
        $natural = $byCode;
        natsort($natural);
        $this->assertSame(array_values($natural), $byCode);
    }

    public function testCollectIsReadOnlyAndNeedsTheRight(): void
    {
        global $DB;

        $ids = $this->seed();
        $before = $DB->request(['FROM' => Control::getTable(), 'WHERE' => ['id' => $ids['control']]])->current();
        ControlBlocker::collect();
        $after = $DB->request(['FROM' => Control::getTable(), 'WHERE' => ['id' => $ids['control']]])->current();
        $this->assertSame($before, $after);

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        try {
            $this->assertSame([], ControlBlocker::collect($ids['control']));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }
    }

    public function testUnreadableNonconformityIsNeverCited(): void
    {
        global $DB;

        $ids = $this->seed();
        $other = $this->createTestEntity(0, 'Entité fictive 867');
        $DB->update(Nonconformity::getTable(), ['entities_id' => $other], ['id' => $ids['nonconformity']]);
        $active = $_SESSION['glpiactiveentities'];
        $activeString = $_SESSION['glpiactiveentities_string'];
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpiactiveentities_string'] = '0';
        try {
            $blockers = ControlBlocker::collect($ids['control'])[$ids['control']]['blockers'] ?? [];
            $urls = implode(' ', array_column($blockers, 'url')) . ' ';
            $this->assertStringNotContainsString('id=' . $ids['nonconformity'] . ' ', $urls);
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
            $_SESSION['glpiactiveentities_string'] = $activeString;
        }
    }
}
