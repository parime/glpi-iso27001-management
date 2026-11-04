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

use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\PeriodicReview;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskAppetite;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\RiskIndicatorValue;
use GlpiPlugin\Grcmanager\SecurityCategoryLink;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use GlpiPlugin\Grcmanager\Services\Risk\RiskAppetiteRules;
use Ticket;

/**
 * Issue #378 : appétence et tolérance au risque par catégorie, acceptation formelle, indicateurs
 * de risque (KRI) avec seuils, historique, entité, ticket au rouge et cartes du tableau de bord.
 */
final class RiskAppetiteKriTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<int, int|string> entités actives à restaurer après chaque test */
    private array $activeEntities = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeEntities = $_SESSION['glpiactiveentities'];
        global $DB;
        $DB->delete(RiskAppetite::getTable(), ['id' => ['>', 0]]);
        RiskAppetite::clearCache();
        $_SESSION['glpi_currenttime'] ??= date('Y-m-d H:i:s');
    }

    protected function tearDown(): void
    {
        $_SESSION['glpiactiveentities'] = $this->activeEntities;
        RiskAppetite::clearCache();
        parent::tearDown();
    }

    private function setAppetite(string $category, string $appetite, string $tolerance): void
    {
        RiskAppetite::save([$category => [
            'appetite_level' => $appetite, 'tolerance_level' => $tolerance, 'statement' => 'Validé en comité',
        ]]);
    }

    /** Risque fictif « certain x critique » (niveau critique avec la matrice par défaut). */
    private function criticalRisk(string $category, array $extra = []): int
    {
        return (int) (new Risk())->add($extra + [
            'title' => 'Risque fictif ' . uniqid(), 'category' => $category,
            'probability' => 'certain', 'impact' => 'critical',
        ]);
    }

    public function testAppetiteIsSavedWithApproverAndRejectsIncoherentPairs(): void
    {
        $rejected = RiskAppetite::save([
            'technical' => ['appetite_level' => 'medium', 'tolerance_level' => 'high', 'statement' => 'Validé'],
            'people'    => ['appetite_level' => 'high', 'tolerance_level' => 'low'],
            'process'   => ['appetite_level' => 'bogus'],
        ]);
        $this->assertSame(['people', 'process'], $rejected);
        $all = RiskAppetite::all();
        $this->assertSame(['technical'], array_keys($all));
        $this->assertSame(2, (int) $all['technical']['users_id_approval']);
        $this->assertNotEmpty($all['technical']['date_approval']);
        $this->assertSame(RiskAppetiteRules::ABOVE_TOLERANCE, RiskAppetite::positionFor('technical', 'critical'));
        $this->assertSame(RiskAppetiteRules::ABOVE_APPETITE, RiskAppetite::positionFor('technical', 'high'));
        $this->assertSame(RiskAppetiteRules::WITHIN, RiskAppetite::positionFor('technical', 'low'));
        $this->assertSame(RiskAppetiteRules::UNDEFINED, RiskAppetite::positionFor('people', 'critical'));

        // Appétence vidée : catégorie retirée.
        RiskAppetite::save(['technical' => ['appetite_level' => '']]);
        $this->assertSame([], RiskAppetite::all());
    }

    public function testSavingRequiresTheSensitiveUpdateRight(): void
    {
        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = READ;
        try {
            $this->setAppetite('technical', 'low', 'medium');
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = $rights;
        }
        $this->assertSame([], RiskAppetite::all());
    }

    public function testAcceptingARiskAboveAppetiteIsAFormalSignedAcceptance(): void
    {
        $this->setAppetite('technical', 'medium', 'high');
        $risk = new Risk();
        $id = $this->criticalRisk('technical');
        $this->assertGreaterThan(0, $id);

        // Sans justification : refusé.
        $this->assertFalse($risk->update(['id' => $id, 'treatment' => 'accept', 'justification' => '']));
        $risk->getFromDB($id);
        $this->assertSame('', $risk->fields['treatment']);

        // Justifiée : signée par la personne qui enregistre.
        $this->assertTrue(
            $risk->update(['id' => $id, 'treatment' => 'accept', 'justification' => 'Coût disproportionné'])
        );
        $risk->getFromDB($id);
        $this->assertSame(2, (int) $risk->fields['users_id_acceptance']);
        $this->assertNotEmpty($risk->fields['date_acceptance']);

        // La signature ne vient jamais de la requête, et tombe quand la décision change.
        $risk->update(['id' => $id, 'users_id_acceptance' => 99, 'description' => 'x']);
        $risk->getFromDB($id);
        $this->assertSame(2, (int) $risk->fields['users_id_acceptance']);
        $risk->update(['id' => $id, 'treatment' => 'mitigate']);
        $risk->getFromDB($id);
        $this->assertSame(0, (int) $risk->fields['users_id_acceptance']);
    }

    public function testNothingChangesWithoutAppetite(): void
    {
        $risk = new Risk();
        $id = $this->criticalRisk('physical');
        $this->assertTrue($risk->update(['id' => $id, 'treatment' => 'accept', 'justification' => '']));
        $risk->getFromDB($id);
        $this->assertSame(0, (int) $risk->fields['users_id_acceptance']);
    }

    public function testRisksAboveAppetiteAreListedToleranceFirst(): void
    {
        $this->setAppetite('technical', 'low', 'high');
        $critical = $this->criticalRisk('technical');
        $high = (int) (new Risk())->add([
            'title' => 'Risque fictif élevé', 'category' => 'technical',
            'probability' => 'probable', 'impact' => 'high',
        ]);
        $closed = $this->criticalRisk('technical', ['status' => 'closed']);
        $rows = RiskAppetite::risksAboveAppetite();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $this->assertContains($critical, $ids);
        $this->assertNotContains($closed, $ids);
        $this->assertSame(RiskAppetiteRules::ABOVE_TOLERANCE, $rows[0]['position']);
        $this->assertTrue($rows[0]['needs_decision']);
        $level = (string) (new Risk())->find(['id' => $high])[$high]['risk_level'];
        $this->assertSame($level !== 'low', in_array($high, $ids, true));
        $this->assertGreaterThanOrEqual(1, RiskAppetite::countAbove(true));
        $this->assertGreaterThanOrEqual(RiskAppetite::countAbove(true), RiskAppetite::countAbove(false));
    }

    private function kri(array $input = []): RiskIndicator
    {
        $kri = new RiskIndicator();
        $id = (int) $kri->add($input + [
            'name' => 'KRI fictif ' . uniqid(), 'entities_id' => 0,
            'threshold_warning' => '5', 'threshold_critical' => '10',
        ]);
        $this->assertGreaterThan(0, $id);
        $kri->getFromDB($id);

        return $kri;
    }

    public function testValuesBuildAHistoryAndDriveTheStatus(): void
    {
        $kri = $this->kri();
        $this->assertSame(KriRules::NONE, $kri->fields['status']);
        $this->assertTrue($kri->recordValue(3, '2026-09-01', 'Relevé de septembre'));
        $this->assertSame(KriRules::GREEN, $kri->fields['status']);
        $kri->recordValue(12, '2026-10-01');
        $this->assertSame(KriRules::RED, $kri->fields['status']);
        // Valeur antérieure saisie après coup : la dernière valeur reste la plus récente.
        $kri->recordValue(6, '2026-08-01');
        $kri->getFromDB($kri->getID());
        $this->assertSame(KriRules::RED, $kri->fields['status']);
        $this->assertEquals(12, (float) $kri->fields['last_value']);
        $values = RiskIndicatorValue::getValues($kri->getID());
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], array_column($values, 'value_date'));
        $this->assertSame(KriRules::ORANGE, $values[0]['status']);

        // Seuils relevés : le statut suit.
        $kri->update(['id' => $kri->getID(), 'threshold_warning' => '20', 'threshold_critical' => '30']);
        $kri->getFromDB($kri->getID());
        $this->assertSame(KriRules::GREEN, $kri->fields['status']);

        // Purge : l'historique part avec le KRI.
        $kri->delete(['id' => $kri->getID()], true);
        $this->assertSame([], RiskIndicatorValue::getValues($kri->getID()));
    }

    public function testThresholdsAndComputedFieldsAreValidated(): void
    {
        $kri = new RiskIndicator();
        $this->assertFalse($kri->add([
            'name' => 'KRI incohérent', 'entities_id' => 0, 'threshold_warning' => '10', 'threshold_critical' => '5',
        ]));
        $ok = $this->kri([
            'direction' => KriRules::LOWER_IS_WORSE, 'threshold_warning' => '95', 'threshold_critical' => '80',
        ]);
        $ok->update(['id' => $ok->getID(), 'status' => 'red', 'last_value' => '1', 'tickets_id' => 5]);
        $ok->getFromDB($ok->getID());
        $this->assertSame(KriRules::NONE, $ok->fields['status']);
        $this->assertSame(0, (int) $ok->fields['tickets_id']);
    }

    public function testCountsOnlyIndicatorsOfTheUserEntities(): void
    {
        $other = $this->createTestEntity(0, 'Entité KRI fictive');
        $risk = $this->criticalRisk('process');
        $visible = $this->kri(['plugin_grcmanager_risks_id' => $risk]);
        $visible->recordValue(50, date('Y-m-d'));
        $hidden = $this->kri(['entities_id' => $other, 'plugin_grcmanager_risks_id' => $risk]);
        $hidden->recordValue(50, date('Y-m-d'));

        $before = RiskIndicator::countByStatus()['red'];
        $this->assertCount(2, RiskIndicator::forRisk($risk));
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpiactiveentities_string'] = '0';
        $this->assertSame($before - 1, RiskIndicator::countByStatus()['red']);
        $this->assertCount(1, RiskIndicator::forRisk($risk));
        $this->assertFalse($hidden->can($hidden->getID(), READ));
        $this->assertTrue($visible->can($visible->getID(), READ));
    }

    public function testAutomaticSourceNeedsTheRightsOnTheMeasuredData(): void
    {
        $rights = $_SESSION['glpiactiveprofile']['computer'] ?? 0;
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        try {
            $this->assertFalse((new RiskIndicator())->add([
                'name' => 'KRI chiffrement', 'entities_id' => 0, 'source' => 'unencrypted_computers',
            ]));
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $rights;
        }
        // Tout le périmètre de la racine (autres entités de l'instance de test comprises).
        $_SESSION['glpiactiveentities'] = array_values(array_map('intval', getSonsOf('glpi_entities', 0)));
        $kri = $this->kri(['source' => 'unencrypted_computers', 'unit' => 'bidon']);
        $this->assertSame('%', $kri->fields['unit']);

        // Source globale du registre : relevée à la demande, un seul relevé automatique par jour.
        $this->setAppetite('technical', 'low', 'medium');
        $this->criticalRisk('technical');
        $global = $this->kri([
            'source' => 'risks_above_tolerance', 'threshold_warning' => '1', 'threshold_critical' => '100',
        ]);
        $this->assertTrue($global->measure());
        $this->assertTrue($global->measure());
        $values = RiskIndicatorValue::getValues($global->getID());
        $this->assertCount(1, $values);
        $this->assertSame(1, (int) $values[0]['is_automatic']);
        $this->assertEquals(RiskAppetite::countAbove(true), (float) $values[0]['value']);
        $this->assertFalse($this->kri()->measure(), 'Un KRI manuel ne se relève pas automatiquement.');
    }

    public function testScheduledTaskMeasuresOnlyActiveAutomaticIndicators(): void
    {
        $automatic = $this->kri(['source' => 'overdue_treatment_actions']);
        $inactive = $this->kri(['source' => 'overdue_treatment_actions', 'is_active' => 0]);
        $manual = $this->kri();
        $this->assertSame(1, RiskIndicator::cronRiskindicatormeasure(new \CronTask()));
        $this->assertCount(1, RiskIndicatorValue::getValues($automatic->getID()));
        $this->assertSame([], RiskIndicatorValue::getValues($inactive->getID()));
        $this->assertSame([], RiskIndicatorValue::getValues($manual->getID()));
        $this->assertArrayHasKey('description', RiskIndicator::cronInfo('riskindicatormeasure'));
    }

    public function testRedIndicatorOffersATicketAssignedToTheCisoGroup(): void
    {
        $group = (int) (new \Group())->add(['name' => 'RSSI fictif', 'entities_id' => 0, 'is_recursive' => 1]);
        AuditTeam::setCisoGroupId($group);
        $kri = $this->kri();
        $this->assertSame(0, $kri->createTicket(), 'Pas de ticket hors du rouge.');
        $kri->recordValue(15, date('Y-m-d'));
        $ticketsId = $kri->createTicket();
        $this->assertGreaterThan(0, $ticketsId);
        $this->assertSame($ticketsId, $kri->createTicket(), 'Le ticket encore ouvert est réutilisé.');
        $ticket = new Ticket();
        $ticket->getFromDB($ticketsId);
        $this->assertSame(0, (int) $ticket->fields['entities_id']);
        $this->assertSame(1, countElementsInTable('glpi_groups_tickets', [
            'tickets_id' => $ticketsId, 'groups_id' => $group, 'type' => \CommonITILActor::ASSIGN,
        ]));
        $this->assertStringContainsString((string) $kri->fields['name'], (string) $ticket->fields['name']);
    }

    public function testCisoGroupKeepsTheTicketWhenABusinessRuleAssignsAnotherGroup(): void
    {
        $ciso = (int) (new \Group())->add(['name' => 'RSSI fictif', 'entities_id' => 0, 'is_recursive' => 1]);
        $support = (int) (new \Group())->add(['name' => 'Support fictif', 'entities_id' => 0, 'is_recursive' => 1]);
        AuditTeam::setCisoGroupId($ciso);
        $rule = new \RuleTicket();
        $ruleId = (int) $rule->add([
            'name' => 'Règle fictive', 'sub_type' => 'RuleTicket', 'match' => 'AND', 'is_active' => 1,
            'entities_id' => 0, 'is_recursive' => 1, 'condition' => \RuleTicket::ONADD, 'ranking' => 0,
        ]);
        (new \RuleCriteria())->add([
            'rules_id' => $ruleId, 'criteria' => 'name', 'condition' => \Rule::PATTERN_CONTAIN, 'pattern' => 'KRI',
        ]);
        (new \RuleAction())->add([
            'rules_id' => $ruleId, 'action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $support,
        ]);

        try {
            $kri = $this->kri();
            $kri->recordValue(15, date('Y-m-d'));
            $ticketsId = $kri->createTicket();
            $assigned = ['tickets_id' => $ticketsId, 'type' => \CommonITILActor::ASSIGN];
            $this->assertSame(1, countElementsInTable('glpi_groups_tickets', $assigned + ['groups_id' => $ciso]));
            // Le groupe de la règle reste assigné (une autre règle de l'instance peut passer après celle-ci).
            $this->assertSame(2, countElementsInTable('glpi_groups_tickets', $assigned));
        } finally {
            $rule->delete(['id' => $ruleId], true);
        }
    }

    public function testTicketUsesTheCgaSecurityBranchOnlyWhenItAcceptsRequests(): void
    {
        $category = (int) (new \ITILCategory())->add([
            'name' => 'Sécurité SI fictive', 'entities_id' => 0, 'is_recursive' => 1, 'is_request' => 1,
            'is_incident' => 1,
        ]);
        \Config::setConfigurationValues(SecurityCategoryLink::CGA_CONTEXT, ['security_itilcategories_id' => $category]);
        SecurityCategoryLink::$forceCgaActive = true;
        try {
            $this->assertSame($category, SecurityCategoryLink::forRequest(0));
            $kri = $this->kri();
            $kri->recordValue(15, date('Y-m-d'));
            $ticket = new Ticket();
            $ticket->getFromDB($kri->createTicket());
            $this->assertSame($category, (int) $ticket->fields['itilcategories_id']);

            (new \ITILCategory())->update(['id' => $category, 'is_request' => 0]);
            $this->assertSame(0, SecurityCategoryLink::forRequest(0));
            SecurityCategoryLink::$forceCgaActive = false;
            $this->assertSame(0, SecurityCategoryLink::forRequest(0), 'Sans CGA, aucune catégorie.');
        } finally {
            SecurityCategoryLink::$forceCgaActive = null;
        }
    }

    public function testAKriCannotChangeEntityAndItsCreationDefaultsToTheActiveEntity(): void
    {
        $other = $this->createTestEntity(0, 'Entité KRI destination');
        $kri = $this->kri();
        $kri->update(['id' => $kri->getID(), 'entities_id' => $other]);
        $kri->getFromDB($kri->getID());
        $this->assertSame(0, (int) $kri->fields['entities_id']);
    }

    public function testAutomaticValuesAreHiddenFromWhoCannotSeeTheMeasuredData(): void
    {
        $parent = $this->createTestEntity(0, 'Entité mère KRI');
        $child = $this->createTestEntity($parent, 'Entité fille KRI');
        // Sous-entité insérée directement : cache des fils de getSonsOf() réinitialisé.
        global $DB, $GLPI_CACHE;
        $DB->update('glpi_entities', ['sons_cache' => null], ['id' => $parent]);
        $GLPI_CACHE->delete('sons_cache_glpi_entities_' . $parent);

        $auto = $this->kri(['entities_id' => $parent, 'source' => 'antivirus_gaps']);
        $manual = $this->kri(['entities_id' => $parent]);
        $auto->recordValue(50, date('Y-m-d'));
        $this->assertTrue($auto->can($auto->getID(), READ));
        $this->assertSame(2, $this->visibleCount([$auto->getID(), $manual->getID()]));

        // Même entité, sans la lecture des ordinateurs : la valeur agrégée n'est plus visible.
        $rights = $_SESSION['glpiactiveprofile']['computer'] ?? 0;
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        try {
            $this->assertFalse($auto->can($auto->getID(), READ));
            $this->assertFalse($auto->can($auto->getID(), UPDATE));
            $this->assertTrue($manual->can($manual->getID(), READ));
            $this->assertSame(1, $this->visibleCount([$auto->getID(), $manual->getID()]));
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $rights;
        }

        // Accès à la mère sans la fille : le périmètre mesuré n'est pas entièrement visible.
        $active = $_SESSION['glpiactiveentities'];
        $_SESSION['glpiactiveentities'] = array_values(array_diff($active, [$child]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
        try {
            $this->assertFalse($auto->can($auto->getID(), READ));
            $this->assertSame(1, $this->visibleCount([$auto->getID(), $manual->getID()]));
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
            $_SESSION['glpiactiveentities_string'] = implode("','", $active);
        }
    }

    /**
     * @param list<int> $ids
     */
    private function visibleCount(array $ids): int
    {
        return countElementsInTable(
            RiskIndicator::getTable(),
            ['id' => $ids] + RiskIndicator::getSystemSQLCriteria()
        );
    }

    public function testDashboardCardsAreEmptyWithoutTheSensitiveRight(): void
    {
        $this->kri()->recordValue(20, date('Y-m-d'));
        $card = SecuredDashboardCards::riskIndicatorsRedCount();
        $this->assertGreaterThanOrEqual(1, $card['number']);
        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        try {
            $this->assertSame(0, SecuredDashboardCards::riskIndicatorsRedCount()['number']);
            $this->assertSame([], SecuredDashboardCards::riskIndicatorsByStatus()['data']);
            $this->assertSame(0, SecuredDashboardCards::risksAboveToleranceCount()['number']);
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = $rights;
        }
    }

    public function testKriReviewIsAPeriodicReview(): void
    {
        $review = PeriodicReview::getByKey('risk_indicators');
        $this->assertNotNull($review);
        $this->assertSame(3, (int) $review->fields['frequency_months']);
        $this->assertNotSame([], PeriodicReview::getChecklist('risk_indicators'));
        $this->assertSame(
            '/plugins/grcmanager/front/riskindicator.php',
            PeriodicReview::getScreenPath('risk_indicators')
        );
    }
}
