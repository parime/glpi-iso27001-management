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
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\ContinuityPlanTesting;
use GlpiPlugin\Grcmanager\ContinuityPlanVersion;
use GlpiPlugin\Grcmanager\CrisisContinuity;
use GlpiPlugin\Grcmanager\Install\ContinuityPlanTables;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Services\Continuity\ContinuityPlanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules;

/**
 * Issue #1083 : plans de continuité et de reprise (PCA, PRA, PCI, PRI) : contenu, processus couverts,
 * circuit d'approbation, contenu figé, nouvelle version, tests et non-conformité, indicateurs, preuve
 * des mesures A.5.29 et A.5.30, PDF, place dans « Assistance > Crise et continuité ». Données fictives.
 */
final class ContinuityPlanTest extends GrcmanagerIntegrationTestCase
{
    use ForcesEnglishLanguage;

    protected function setUp(): void
    {
        global $DB;

        parent::setUp();
        Sections::reset();
        // Base de test partagée : on part d'un registre vide (annulé avec la transaction).
        foreach (
            [
                ContinuityPlanTables::TESTS, ContinuityPlanTables::APPROVERS, ContinuityPlanTables::VERSIONS,
                ContinuityPlanTables::ITEMS, ContinuityPlanTables::PLANS, BusinessProcess::getTable(),
            ] as $table
        ) {
            $DB->delete($table, ['id' => ['>', 0]]);
        }
        // Aucun approbateur par défaut des politiques : seul l'approbateur désigné compte.
        Config::setConfigurationValues(PolicyApprovalConfig::CONTEXT, [PolicyApprovalConfig::APPROVERS_KEY => '[]']);
    }

    protected function tearDown(): void
    {
        Sections::reset();
        parent::tearDown();
    }

    private function process(string $name, int $rto): int
    {
        $id = (int) (new BusinessProcess())->add([
            'name' => $name, 'rto_hours' => $rto, 'rpo_hours' => 4, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function plan(string $name, array $input = []): ContinuityPlan
    {
        $plan = new ContinuityPlan();
        $id = (int) $plan->add($input + [
            'name'             => $name,
            'plan_type'        => Rules::TYPE_PRI,
            'entities_id'      => 0,
            'is_recursive'     => 1,
            'groups_id_crisis' => 0,
            'procedures'       => "1. Restore\n2. Check",
        ]);
        $this->assertGreaterThan(0, $id);
        $plan->getFromDB($id);

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private static function presented(int $id): array
    {
        foreach (ContinuityPlan::register() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('Plan absent de la liste.');
    }

    public function testNewPlanIsADraftWithItsCoveredProcessesAndObjectives(): void
    {
        $payroll = $this->process('Payroll test plan', 4);
        $plan = $this->plan('Recovery of the data centre', [
            'status'        => Rules::STATUS_APPROVED,
            'plan_type'     => 'unknown',
            '_links_sent'   => 1,
            '_processes'    => [$payroll, 999999],
        ]);
        $this->assertSame(Rules::STATUS_DRAFT, $plan->fields['status'], 'Un plan naît en brouillon.');
        $this->assertSame(Rules::TYPE_PCA, $plan->fields['plan_type'], 'Type inconnu : PCA.');
        $this->assertSame('1.0', $plan->fields['version']);

        $processes = $plan->coveredProcesses();
        $this->assertCount(1, $processes, 'Un processus inexistant n\'est pas lié.');
        $this->assertSame('Payroll test plan', $processes[0]['name']);
        $this->assertTrue($processes[0]['critical']);
        $this->assertNotSame('', $processes[0]['rto']);
        $this->assertSame('', $processes[0]['last_test']);

        $row = self::presented((int) $plan->getID());
        $this->assertContains(Rules::GAP_NOT_APPROVED, $row['gaps']);
        $this->assertContains(Rules::GAP_NEVER_TESTED, $row['gaps']);
        $this->assertContains(Rules::GAP_NO_CRISIS_TEAM, $row['gaps']);
        $this->assertNotContains(Rules::GAP_NO_PROCESS, $row['gaps']);

        $this->assertCount(1, ContinuityPlan::plansForProcess(self::processItem($payroll)));
    }

    private static function processItem(int $id): BusinessProcess
    {
        $process = new BusinessProcess();
        $process->getFromDB($id);

        return $process;
    }

    public function testApprovalFreezesTheContentAndSetsTheReviewDate(): void
    {
        $approver = $this->createTestUser('Alice', 'Director');
        $plan = $this->plan('Continuity of order taking', ['users_id_approver' => $approver, 'review_months' => 6]);
        $planId = (int) $plan->getID();

        $versionId = ContinuityPlanVersion::submit($plan, 'Please approve');
        $this->assertGreaterThan(0, $versionId);
        $plan->getFromDB($planId);
        $this->assertSame(Rules::STATUS_PENDING, $plan->fields['status']);
        $this->assertTrue(ContinuityPlanVersion::awaitsUser($planId, $approver));
        $this->assertFalse(ContinuityPlanVersion::awaitsUser($planId, 2));

        // Contenu figé pendant l'approbation ; le commentaire reste modifiable.
        $this->assertFalse($plan->update(['id' => $planId, 'name' => 'Changed during approval']));
        $this->assertTrue($plan->update(['id' => $planId, 'comment' => 'Note']));
        $plan->getFromDB($planId);
        $this->assertSame('Continuity of order taking', $plan->fields['name']);
        // Le statut ne se change pas par le formulaire.
        $plan->update(['id' => $planId, 'status' => Rules::STATUS_APPROVED]);
        $plan->getFromDB($planId);
        $this->assertSame(Rules::STATUS_PENDING, $plan->fields['status']);

        $this->assertFalse(ContinuityPlanVersion::submit($plan, '') > 0, 'Une seule demande à la fois.');
        $this->assertFalse(ContinuityPlanVersion::answer($versionId, 2, true, ''), 'Pas un approbateur.');
        $this->assertTrue(ContinuityPlanVersion::answer($versionId, $approver, true, ''));

        $plan->getFromDB($planId);
        $today = date('Y-m-d');
        $this->assertSame(Rules::STATUS_APPROVED, $plan->fields['status']);
        $this->assertSame($today, $plan->fields['date_approved']);
        $this->assertSame(Rules::nextReviewDate($today, 6), $plan->fields['next_review_date']);

        $version = ContinuityPlanVersion::getVersion($planId, $versionId);
        $this->assertNotNull($version);
        $this->assertSame(PolicyApprovalRules::VERSION_APPROVED, $version['status']);
        $this->assertSame('Continuity of order taking', $version['snapshot']['name']);
        $this->assertSame(Rules::fingerprint($version['snapshot']), $version['fingerprint']);
        $this->assertNotContains(Rules::GAP_NOT_APPROVED, self::presented($planId)['gaps']);

        // Nouvelle version : numéro déjà approuvé refusé, puis brouillon ; l'approbation précédente reste.
        $this->assertFalse(ContinuityPlanVersion::prepareNewVersion($plan, '1.0'));
        $this->assertTrue(ContinuityPlanVersion::prepareNewVersion($plan, '2.0'));
        $plan->getFromDB($planId);
        $this->assertSame(Rules::STATUS_DRAFT, $plan->fields['status']);
        $this->assertSame('2.0', $plan->fields['version']);
        $this->assertSame($today, $plan->fields['date_approved']);
        $this->assertNotContains(Rules::GAP_NOT_APPROVED, self::presented($planId)['gaps']);
        $this->assertTrue($plan->update(['id' => $planId, 'name' => 'Continuity of order taking v2']));
    }

    public function testRejectionNeedsACommentAndWithdrawReopensTheDraft(): void
    {
        $approver = $this->createTestUser('Bob', 'Manager');
        $plan = $this->plan('Recovery of the ERP', ['users_id_approver' => $approver]);
        $planId = (int) $plan->getID();
        $versionId = ContinuityPlanVersion::submit($plan, '');
        $this->assertFalse(ContinuityPlanVersion::answer($versionId, $approver, false, ' '));
        $this->assertTrue(ContinuityPlanVersion::answer($versionId, $approver, false, 'Steps are missing'));
        $plan->getFromDB($planId);
        $this->assertSame(Rules::STATUS_DRAFT, $plan->fields['status']);

        $plan->update(['id' => $planId, 'version' => '1.1']);
        $plan->getFromDB($planId);
        $this->assertGreaterThan(0, ContinuityPlanVersion::submit($plan, ''));
        $plan->getFromDB($planId);
        $this->assertTrue(ContinuityPlanVersion::withdraw($plan));
        $plan->getFromDB($planId);
        $this->assertSame(Rules::STATUS_DRAFT, $plan->fields['status']);
        $statuses = array_column(ContinuityPlanVersion::present($planId), 'status');
        $this->assertSame([PolicyApprovalRules::VERSION_WITHDRAWN, PolicyApprovalRules::VERSION_REJECTED], $statuses);
    }

    public function testSubmissionWithoutApproverIsRefused(): void
    {
        $plan = $this->plan('Plan without approver');
        $this->assertSame(0, ContinuityPlanVersion::submit($plan, ''));
        $plan->getFromDB((int) $plan->getID());
        $this->assertSame(Rules::STATUS_DRAFT, $plan->fields['status']);
    }

    public function testTestsAndGapsBecomeANonconformity(): void
    {
        $plan = $this->plan('Recovery of the mail service');
        $this->assertSame(0, ContinuityPlanTesting::add($plan, ['test_date' => date('Y-m-d', strtotime('+3 days'))]));
        $testId = ContinuityPlanTesting::add($plan, [
            'test_date' => date('Y-m-d'),
            'result'    => Rules::RESULT_FAILED,
            'summary'   => 'Restore of the mailboxes',
            'gaps'      => 'Restore took 9 hours instead of 4',
        ]);
        $this->assertGreaterThan(0, $testId);
        $row = self::presented((int) $plan->getID());
        $this->assertSame(date('Y-m-d'), $row['last_test']);
        $this->assertContains(Rules::GAP_LAST_TEST_FAILED, $row['gaps']);
        $this->assertNotContains(Rules::GAP_NEVER_TESTED, $row['gaps']);

        $ncId = ContinuityPlanTesting::createNonconformity($plan, $testId);
        $this->assertGreaterThan(0, $ncId);
        $nc = new Nonconformity();
        $this->assertTrue($nc->getFromDB($ncId));
        $this->assertSame('major', $nc->fields['severity']);
        $this->assertStringContainsString('9 hours', (string) $nc->fields['description']);
        $this->assertSame(0, ContinuityPlanTesting::createNonconformity($plan, $testId), 'Une seule action par test.');
        $this->assertFalse(ContinuityPlanTesting::remove($plan, $testId), 'Un test suivi d\'une action reste.');
        $tests = ContinuityPlanTesting::forPlan($plan);
        $this->assertCount(1, $tests);
        $this->assertNotNull($tests[0]['action']);
    }

    public function testIndicatorsEvidenceAndPdf(): void
    {
        $covered = $this->process('Orders test plan', 4);
        $this->process('Billing test plan', 8);
        $this->process('Archives test plan', 168);
        $plan = $this->plan('Continuity of the shop', ['_links_sent' => 1, '_processes' => [$covered]]);

        $coverage = ContinuityPlan::coverage(ContinuityPlan::register());
        $this->assertSame(1, $coverage['summary']['plans']);
        $this->assertSame(1, $coverage['summary']['not_approved']);
        $this->assertSame(1, $coverage['summary']['untested']);
        $this->assertSame(1, $coverage['summary']['critical_uncovered']);
        $this->assertSame(['Billing test plan'], array_column($coverage['uncovered'], 'name'));

        $evidence = ContinuityPlan::evidence('A.5.30');
        $this->assertNotNull($evidence);
        $this->assertSame(1, $evidence['plans']);
        $this->assertCount(1, $evidence['plan_list']);
        $this->assertNotNull(ContinuityPlan::evidence('A.5.29'));
        $this->assertNull(ContinuityPlan::evidence('A.5.1'));

        $html = $plan->pdfHtml();
        $this->assertStringContainsString('Continuity of the shop', $html);
        $this->assertStringContainsString('Orders test plan', $html);

        // Carte de la page d'accueil de la rubrique, avec ses indicateurs.
        $cards = [];
        foreach (CrisisContinuity::hubData(date('Y-m-d')) as $group) {
            foreach ($group['cards'] as $card) {
                $cards[(string) $card['url']] = $card;
            }
        }
        $card = $cards[ContinuityPlan::getSearchURL()] ?? null;
        $this->assertNotNull($card);
        $this->assertSame(1, $card['count']);
        $this->assertCount(3, $card['todo']);
    }

    public function testPlansArePreparedInCrisisAndContinuity(): void
    {
        $pages = array_keys(Sections::pages(CrisisContinuity::class));
        $this->assertContains(ContinuityPlan::class, $pages);
        $this->assertSame(
            array_search(\GlpiPlugin\Grcmanager\CrisisScenario::class, $pages, true) + 1,
            array_search(ContinuityPlan::class, $pages, true),
            'Juste après les scénarios de crise.'
        );
        $this->assertLessThanOrEqual(Sections::MAX_PAGES, count($pages));
        $this->assertSame(
            ['helpdesk', CrisisContinuity::class, ContinuityPlan::class],
            Menu::sectorPath(ContinuityPlan::class)
        );
    }
}
