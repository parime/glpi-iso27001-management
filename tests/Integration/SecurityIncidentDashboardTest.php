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

use Glpi\Dashboard\Dashboard;
use Glpi\Dashboard\Grid;
use GlpiPlugin\Grcmanager\SecurityIncidentTeam;
use GlpiPlugin\Grcmanager\SecurityIncidentTechnique;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Dashboard\DefaultDashboardService;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecurityIncidentCardProvider;
use PluginGrcmanagerSecurityIncident;

/**
 * Issues #143 et #372 : cartes des incidents de sécurité. Un comptage ou une liste ne révèle aucun
 * incident que l'utilisateur ne peut pas ouvrir (cloisonnement « Voir (auteur) », équipe
 * d'intervention), et une carte n'est proposée qu'à qui a le droit de la voir.
 */
final class SecurityIncidentDashboardTest extends GrcmanagerIntegrationTestCase
{
    private const RIGHT = 'plugin_grcmanager_securityincident';

    /**
     * @param callable(): void $test
     */
    private function asUser(int $usersId, int $right, array $groups, callable $test): void
    {
        $saved = [$_SESSION['glpiID'], $_SESSION['glpigroups'] ?? [], $_SESSION['glpiactiveprofile'][self::RIGHT]];
        try {
            $_SESSION['glpiID'] = $usersId;
            $_SESSION['glpigroups'] = $groups;
            $_SESSION['glpiactiveprofile'][self::RIGHT] = $right;
            $test();
        } finally {
            [$_SESSION['glpiID'], $_SESSION['glpigroups'], $_SESSION['glpiactiveprofile'][self::RIGHT]] = $saved;
        }
    }

    /**
     * @return array<string, int>
     */
    private function createIncidents(int $techId, int $groupId): array
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $ids = [
            'team'      => (int) $incident->add([
                'name' => 'PHPUnit tableau de bord équipe', 'entities_id' => 0, 'severity' => 'major',
                'category' => 'malware', 'date' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'contained_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            ]),
            'requester' => (int) $incident->add([
                'name' => 'PHPUnit tableau de bord demandeur', 'entities_id' => 0, 'severity' => 'minor',
                '_users_id_requester' => $techId,
            ]),
            'group'     => (int) $incident->add([
                'name' => 'PHPUnit tableau de bord groupe', 'entities_id' => 0,
                '_groups_id_assign' => $groupId,
            ]),
            'hidden'    => (int) $incident->add([
                'name' => 'PHPUnit tableau de bord secret', 'entities_id' => 0, 'severity' => 'critical',
                'category' => 'data_breach', 'nis2_applicable' => 1,
                'date' => date('Y-m-d H:i:s', strtotime('-5 days')),
            ]),
            'exercise'  => (int) $incident->add([
                'name' => 'PHPUnit tableau de bord exercice', 'entities_id' => 0, 'severity' => 'critical',
                'is_exercise' => 1,
            ]),
        ];
        (new SecurityIncidentTeam())->add([
            'plugin_grcmanager_securityincidents_id' => $ids['team'],
            'users_id' => $techId,
            'role' => SecurityIncidentTeam::OBSERVER,
        ]);
        (new SecurityIncidentTechnique())->add([
            'plugin_grcmanager_securityincidents_id' => $ids['hidden'],
            'technique' => 'T1566', 'tactic' => 'TA0001',
        ]);

        return $ids;
    }

    public function testViewCriteriaMatchCanViewItem(): void
    {
        global $DB;

        $techId = $this->createTestUser('Camille', 'Fictive');
        $groupId = (int) (new \Group())->add(['name' => 'PHPUnit groupe sécurité', 'entities_id' => 0]);
        $ids = $this->createIncidents($techId, $groupId);

        $this->asUser($techId, PluginGrcmanagerSecurityIncident::READMY, [$groupId], function () use ($DB, $ids): void {
            $table = PluginGrcmanagerSecurityIncident::getTable();
            $sql = array_map('intval', array_column(iterator_to_array($DB->request([
                'SELECT' => ["$table.id"],
                'FROM'   => $table,
                'WHERE'  => array_merge([["$table.id" => array_values($ids)]], SecurityIncidentTeam::viewCriteria()),
            ]), false), 'id'));
            $expected = [];
            foreach ($ids as $id) {
                $incident = new PluginGrcmanagerSecurityIncident();
                if ($incident->getFromDB($id) && $incident->canViewItem()) {
                    $expected[] = $id;
                }
            }
            sort($sql);
            sort($expected);
            $this->assertSame($expected, $sql);
            $this->assertNotContains($ids['hidden'], $sql);
            $this->assertContains($ids['group'], $sql);
            $this->assertContains($ids['requester'], $sql);
        });
    }

    public function testCardsNeverRevealAnIncidentTheUserCannotOpen(): void
    {
        $techId = $this->createTestUser('Dominique', 'Fictive');
        $groupId = (int) (new \Group())->add(['name' => 'PHPUnit groupe sécurité 2', 'entities_id' => 0]);
        $ids = $this->createIncidents($techId, $groupId);

        $this->asUser($techId, PluginGrcmanagerSecurityIncident::READMY, [$groupId], function (): void {
            $this->assertSame(3, SecuredDashboardCards::incidentsTotal()['number']);
            $this->assertSame(3, SecuredDashboardCards::open()['number']);
            $this->assertSame(0, SecuredDashboardCards::openCritical()['number'], 'Ni le secret, ni l\'exercice.');
            $this->assertSame(0, SecuredDashboardCards::securityIncidentRegulatoryDeadlines()['number']);
            $this->assertSame([], SecuredDashboardCards::regulatoryNotifications()['data']);
            $this->assertSame([], SecuredDashboardCards::attackTactics()['data']);
            $this->assertSame(1, SecuredDashboardCards::averageTimeToContain()['number']);
            $severity = array_column(SecuredDashboardCards::securityIncidentsBySeverity()['data'], 'number', 'label');
            $this->assertSame(0, $severity[__('Critical', 'grcmanager')]);
            $classification = array_column(SecuredDashboardCards::byClassification()['data'], 'number', 'label');
            $this->assertArrayNotHasKey(__('Data breach', 'grcmanager'), $classification);
            $this->assertSame(3, array_sum(array_column(SecuredDashboardCards::incidentsByEntity()['data'], 'number')));
            $trend = SecuredDashboardCards::incidentsMonthlyTrend()['data'];
            $this->assertSame(3, array_sum($trend['series'][0]['data']));
        });

        // « Voir tout » : le secret apparaît, l'exercice de crise jamais.
        $this->asUser(2, PluginGrcmanagerSecurityIncident::READALL, [], function () use ($ids): void {
            $critical = SecuredDashboardCards::openCritical();
            $this->assertGreaterThanOrEqual(1, $critical['number']);
            $this->assertStringContainsString('criteria%5B1%5D%5Bfield%5D=221', $critical['url']);
            $urls = array_column(SecuredDashboardCards::regulatoryNotifications()['data'], 'url');
            $this->assertContains(PluginGrcmanagerSecurityIncident::getFormURLWithID($ids['hidden']), $urls);
            $tactics = array_column(SecuredDashboardCards::attackTactics()['data'], 'number');
            $this->assertGreaterThanOrEqual(1, array_sum($tactics));
            foreach (SecuredDashboardCards::securityIncidentsByStatus()['data'] as $entry) {
                $this->assertStringContainsString('criteria%5B0%5D%5Bfield%5D=12', $entry['url']);
            }
        });
    }

    public function testCardsAreOfferedOnlyToWhoMaySeeThem(): void
    {
        $catalog = static fn (): array => (new Grid())->getAllDasboardCards(true);

        $this->assertArrayHasKey('grcmanager_securityincident_open_critical', $catalog());
        $this->asUser(2, 0, [], function () use ($catalog): void {
            $cards = $catalog();
            $hidden = [
                'securityincidents_bn_total', 'securityincidents_by_entity',
                'grcmanager_securityincident_open_critical',
                'grcmanager_security_incidents_by_status', 'grcmanager_security_incident_regulatory_deadlines',
            ];
            foreach ($hidden as $id) {
                $this->assertArrayNotHasKey($id, $cards, $id);
            }
            $this->assertArrayHasKey('grcmanager_open_nonconformities', $cards);
            $this->assertSame(0, SecuredDashboardCards::open()['number']);
            $this->assertArrayNotHasKey('incidents', \GlpiPlugin\Grcmanager\Dashboard::getViews());
        });

        // PR #418 : sans aucun des référentiels qu'elle mesure, la conformité par référentiel disparaît.
        $before = FrameworkConfig::enabled();
        try {
            FrameworkConfig::save(['cis_controls']);
            $this->assertArrayNotHasKey('grcmanager_compliance_by_framework', $catalog());
            $this->assertSame([], SecuredDashboardCards::complianceByFramework()['data']);
            FrameworkConfig::save(['nis2']);
            $this->assertArrayHasKey('grcmanager_compliance_by_framework', $catalog());
        } finally {
            FrameworkConfig::save($before);
        }
    }

    public function testIncidentDashboardIsSeededWithKnownCardsAndSearchAcceptsItsLinks(): void
    {
        global $DB;

        $dashboard = new Dashboard(DefaultDashboardService::INCIDENTS_KEY);
        $this->assertTrue($dashboard->canViewCurrent());
        $cards = (new Grid())->getAllDasboardCards(true);
        $items = iterator_to_array($DB->request([
            'FROM'  => \Glpi\Dashboard\Item::getTable(),
            'WHERE' => ['dashboards_dashboards_id' => $dashboard->fields['id']],
        ]), false);
        $this->assertCount(13, $items);
        foreach ($items as $item) {
            $this->assertArrayHasKey($item['card_id'], $cards, $item['card_id']);
        }
        $this->assertArrayHasKey('incidents', \GlpiPlugin\Grcmanager\Dashboard::getViews());

        foreach ([220, 221] as $id) {
            $this->assertArrayHasKey('equals', \Search::getActionsFor(PluginGrcmanagerSecurityIncident::class, $id));
        }
    }

    public function testMyRemediationTasksStayLimitedToVisibleIncidents(): void
    {
        global $DB;

        $techId = $this->createTestUser('Alex', 'Fictif');
        $incident = new PluginGrcmanagerSecurityIncident();
        $visible = (int) $incident->add([
            'name' => 'PHPUnit tâche visible', 'entities_id' => 0, '_users_id_assign' => $techId,
        ]);
        $hidden = (int) $incident->add(['name' => 'PHPUnit tâche cachée', 'entities_id' => 0]);
        foreach ([$visible, $hidden] as $id) {
            $DB->insert(\GlpiPlugin\Grcmanager\SecurityIncidentPlaybookTask::getTable(), [
                'plugin_grcmanager_securityincidents_id' => $id,
                'name' => 'PHPUnit isoler le poste', 'users_id' => $techId, 'status' => 'todo',
            ]);
        }

        $this->asUser($techId, PluginGrcmanagerSecurityIncident::READMY, [], function () use ($techId, $visible): void {
            $tasks = \GlpiPlugin\Grcmanager\SecurityIncidentPlaybookTask::getOpenTasksFor($techId);
            $this->assertSame([$visible], array_map(
                static fn (array $task): int => (int) $task['plugin_grcmanager_securityincidents_id'],
                $tasks
            ));
            $this->assertSame('PHPUnit tâche visible', $tasks[0]['incident_name']);
        });
    }
}
