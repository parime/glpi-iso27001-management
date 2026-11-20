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

use Group;
use Group_User;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\ManagementReviewDossier;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\Services\Audit\ProgrammeRules as Rules;
use GlpiPlugin\Grcmanager\Training;
use Profile_User;

/**
 * Issue #773 : audit interne selon l'ISO 19011. Programme pluriannuel par processus, contrôle
 * d'indépendance des auditeurs (blocage du démarrage et de la clôture, justification tracée),
 * compétence des auditeurs, remise du rapport reprise dans le dossier de revue de direction,
 * droits et entités. Données fictives (« société Exemple »).
 */
final class AuditProgrammeTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION;
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        parent::tearDown();
    }

    private function member(string $firstname): int
    {
        $id = $this->createTestUser($firstname, 'Exemple');
        (new Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => 0, 'is_recursive' => 1]);

        return $id;
    }

    private function activity(string $name, int $entityId = 0): int
    {
        $id = (int) (new ResponsibilityActivity())->add(['name' => $name, 'entities_id' => $entityId]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function assign(int $activityId, string $itemtype, int $itemsId, string $role): void
    {
        global $DB;

        $DB->insert(ResponsibilityActivity::ASSIGNMENTS_TABLE, [
            ResponsibilityActivity::FK => $activityId,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemsId,
            'role'                     => $role,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function audit(string $title, array $extra = []): Audit
    {
        $audit = new Audit();
        $id = (int) $audit->add($extra + ['title' => $title, 'status' => 'planned', 'planned_date' => '2026-12-10']);
        $this->assertGreaterThan(0, $id);
        $audit->getFromDB($id);

        return $audit;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $programme, int $processId): array
    {
        foreach ($programme['rows'] as $row) {
            if ($row['id'] === $processId) {
                return $row;
            }
        }
        $this->fail('Processus absent du programme.');
    }

    public function testProgrammeMatrixCoversProcessesOverTheCycle(): void
    {
        $access = $this->activity('Gestion des accès Exemple');
        $backups = $this->activity('Sauvegardes Exemple');
        $suppliers = $this->activity('Fournisseurs Exemple');

        $done = $this->audit('Audit accès 2025 Exemple', ['status' => 'completed', 'actual_date' => '2025-04-10']);
        AuditProgramme::setProcesses($done, [$access]);
        $planned = $this->audit('Audit sauvegardes 2027 Exemple', ['planned_date' => '2027-03-01']);
        AuditProgramme::setProcesses($planned, [$backups, $access]);
        $this->audit('Audit sans processus Exemple', ['planned_date' => '2026-12-15']);

        $programme = AuditProgramme::programme(2025, '2026-11-20');
        $this->assertSame([2025, 2026, 2027], $programme['years']);
        $this->assertSame(Rules::STATE_DONE, $this->row($programme, $access)['state']);
        $this->assertSame(Rules::STATE_PLANNED, $this->row($programme, $backups)['state']);
        $this->assertSame(Rules::STATE_NONE, $this->row($programme, $suppliers)['state']);
        $this->assertSame(
            ['Audit accès 2025 Exemple'],
            array_column($this->row($programme, $access)['cells'][2025]['audits'], 'title')
        );
        $this->assertContains('Audit sans processus Exemple', array_column($programme['unassigned'], 'title'));
        $this->assertGreaterThanOrEqual(1, $programme['uncovered']);
        $this->assertArrayHasKey('clauses_never', $programme);

        // Le cycle suivant ne compte plus l'audit de 2025.
        $next = AuditProgramme::programme(2026, '2026-11-20');
        $this->assertSame(Rules::STATE_PLANNED, $this->row($next, $access)['state']);
    }

    public function testProcessesFollowTheEntityOfTheAudit(): void
    {
        $child = $this->createTestEntity(0, 'Filiale Exemple');
        EntityPerimeter::reset();
        $root = $this->activity('Activité racine Exemple');
        $other = $this->activity('Activité de la filiale Exemple', $child);
        $audit = $this->audit('Audit racine Exemple');

        $this->assertArrayHasKey($root, AuditProgramme::processChoices($audit));
        $this->assertArrayNotHasKey($other, AuditProgramme::processChoices($audit), 'audit non récursif');
        AuditProgramme::setProcesses($audit, [$root, $other, 999999]);
        $this->assertSame([$root], AuditProgramme::processIds($audit->getID()));

        $audit->update(['id' => $audit->getID(), 'is_recursive' => 1]);
        $audit->getFromDB($audit->getID());
        $this->assertArrayHasKey($other, AuditProgramme::processChoices($audit), 'audit récursif');

        // Hors des entités de la session : invisible.
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpiactiveentities_string'] = '0';
        $this->assertArrayNotHasKey($other, AuditProgramme::processChoices($audit));
    }

    public function testIndependenceConflictBlocksStartUntilJustified(): void
    {
        $lead = $this->member('Alice');
        $second = $this->member('Bruno');
        $group = (int) (new Group())->add([
            'name'         => 'Équipe exploitation Exemple',
            'entities_id'  => 0,
            'is_recursive' => 1,
        ]);
        (new Group_User())->add(['groups_id' => $group, 'users_id' => $second]);

        $access = $this->activity('Revue des accès Exemple');
        $backups = $this->activity('Sauvegardes et restauration Exemple');
        $this->assign($access, 'User', $lead, 'responsible');
        $this->assign($backups, 'Group', $group, 'accountable');
        $this->assign($backups, 'User', $lead, 'informed');

        $audit = $this->audit('Audit indépendance Exemple', ['users_id' => $lead]);
        $this->assertTrue(AuditTeam::addMember($audit->getID(), $second, AuditTeam::ROLE_AUDITOR));
        AuditProgramme::setProcesses($audit, [$access, $backups]);

        $conflicts = AuditProgramme::conflicts($audit);
        $this->assertSame(
            [Rules::conflictKey($lead, $access), Rules::conflictKey($second, $backups)],
            array_column($conflicts, 'key')
        );
        $this->assertSame($group, $conflicts[1]['groups_id']);

        // Démarrage refusé tant qu'un conflit n'est pas justifié.
        $this->assertFalse($audit->update(['id' => $audit->getID(), 'status' => 'in_progress']));
        $audit->getFromDB($audit->getID());
        $this->assertSame('planned', $audit->fields['status']);
        // Une autre modification reste possible.
        $this->assertTrue($audit->update(['id' => $audit->getID(), 'scope' => 'Périmètre Exemple']));

        $this->assertFalse(AuditProgramme::justify($audit, $lead, $access, 'court'));
        $this->assertFalse(AuditProgramme::justify($audit, $lead, 999999, 'Justification sans conflit'));
        $text = 'Équipe de deux personnes, revue par la direction';
        $this->assertTrue(AuditProgramme::justify($audit, $lead, $access, $text));
        $this->assertFalse(AuditProgramme::justify($audit, $lead, $access, 'Seconde justification refusée'));
        $this->assertFalse($audit->update(['id' => $audit->getID(), 'status' => 'in_progress']));

        $this->assertTrue(AuditProgramme::justify($audit, $second, $backups, 'Auditeur extérieur aux sauvegardes'));
        $this->assertTrue($audit->update(['id' => $audit->getID(), 'status' => 'in_progress']));

        $waiver = AuditProgramme::waivers($audit->getID())[Rules::conflictKey($lead, $access)];
        $this->assertSame('Équipe de deux personnes, revue par la direction', $waiver['justification']);
        $this->assertGreaterThan(0, countElementsInTable('glpi_logs', [
            'itemtype' => Audit::class,
            'items_id' => $audit->getID(),
            'new_value' => ['LIKE', '%Équipe de deux personnes%'],
        ]), 'justification tracée dans l\'historique');

        // Nouvel auditeur en conflit pendant l'audit : refusé.
        $other = $this->member('Chloé');
        $this->assign($access, 'User', $other, 'accountable');
        $this->assertFalse($audit->update(['id' => $audit->getID(), 'users_id' => $other]));

        // Suppression définitive : rien ne reste.
        $audit->delete(['id' => $audit->getID()], true);
        foreach ([AuditProgramme::PROCESSES_TABLE, AuditProgramme::WAIVERS_TABLE] as $table) {
            $this->assertSame(0, countElementsInTable($table, ['plugin_grcmanager_audits_id' => $audit->getID()]));
        }
    }

    public function testPurgedProcessLeavesTheAudits(): void
    {
        $process = $this->activity('Activité supprimée Exemple');
        $audit = $this->audit('Audit processus supprimé Exemple');
        AuditProgramme::setProcesses($audit, [$process]);
        (new ResponsibilityActivity())->delete(['id' => $process], true);
        $this->assertSame([], AuditProgramme::processIds($audit->getID()));
    }

    public function testAuditorCompetenceFromQualifyingTrainings(): void
    {
        global $DB;

        $qualified = $this->member('Denis');
        $missing = $this->member('Emma');
        $training = (int) (new Training())->add(['title' => 'Auditeur interne ISO 27001 Exemple']);
        $this->assertGreaterThan(0, $training);
        $DB->insert(Training::PARTICIPANTS_TABLE, [
            'plugin_grcmanager_trainings_id' => $training,
            'users_id'                       => $qualified,
            'completion_status'              => 'completed',
            'completion_date'                => '2026-02-01',
            'assessment_result'              => 'passed',
        ]);

        AuditProgramme::setQualifyingTrainingIds([]);
        $this->assertSame(
            Rules::COMPETENCE_NOT_CONFIGURED,
            AuditProgramme::competences([$qualified])[$qualified]['status']
        );

        AuditProgramme::setQualifyingTrainingIds([$training, 0, 999999]);
        $this->assertSame([$training], AuditProgramme::qualifyingTrainingIds());
        $competences = AuditProgramme::competences([$qualified, $missing], '2026-11-20');
        $this->assertSame(Rules::COMPETENCE_QUALIFIED, $competences[$qualified]['status']);
        $this->assertSame('Auditeur interne ISO 27001 Exemple', $competences[$qualified]['trainings'][0]['title']);
        $this->assertSame(Rules::COMPETENCE_MISSING, $competences[$missing]['status']);

        // L'onglet « Équipe » affiche la compétence des auditeurs.
        $audit = $this->audit('Audit compétence Exemple', ['users_id' => $missing]);
        $this->assertTrue(AuditTeam::addMember($audit->getID(), $qualified, AuditTeam::ROLE_AUDITOR));
        ob_start();
        AuditTeam::displayTabContentForItem($audit);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-competence-qualified', $html);
        $this->assertStringContainsString('grc-competence-missing', $html);
    }

    public function testReportDeliveryIsTracedAndInTheManagementReviewDossier(): void
    {
        $recipient = $this->member('Fanny');
        // Le profil par défaut d'un nouveau compte ne vaut que pour la racine, pas pour la filiale.
        $outsider = $this->createTestUser('Gaston', 'Exemple');
        $child = $this->createTestEntity(0, 'Filiale direction Exemple');
        EntityPerimeter::reset();
        (new Profile_User())->add([
            'users_id'     => $recipient,
            'profiles_id'  => 1,
            'entities_id'  => $child,
            'is_recursive' => 0,
        ]);
        $audit = $this->audit('Audit remis à la direction Exemple', [
            'entities_id' => $child,
            'status'      => 'completed',
            'actual_date' => '2026-10-15',
        ]);

        $this->assertFalse(AuditProgramme::saveDelivery($audit, '2026-13-40', $recipient, ''));
        $this->assertFalse(AuditProgramme::saveDelivery($audit, '2026-10-20', $outsider, ''), 'hors entité');
        $this->assertTrue(AuditProgramme::saveDelivery($audit, '2026-10-20', $recipient, 'Comité de direction'));
        $this->assertTrue(AuditProgramme::saveDelivery($audit, '2026-10-21', $recipient, 'Comité de direction'));
        $delivery = AuditProgramme::delivery($audit->getID());
        $this->assertNotNull($delivery);
        $this->assertSame('2026-10-21', $delivery['delivery_date']);
        $this->assertSame(1, countElementsInTable(
            AuditProgramme::DELIVERIES_TABLE,
            ['plugin_grcmanager_audits_id' => $audit->getID()]
        ));

        $review = new ManagementReview();
        $reviewId = (int) $review->add([
            'title'       => 'Revue fictive Exemple',
            'status'      => 'planned',
            'review_date' => '2026-11-25',
        ]);
        $this->assertGreaterThan(0, $reviewId);
        $review->getFromDB($reviewId);
        $dossier = ManagementReviewDossier::collect($review, '2026-01-01', '2026-11-25');
        $sections = array_column($dossier['sections'], null, 'key');
        $audits = array_column($sections['audits']['data']['completed'], null, 'title');
        $this->assertArrayHasKey('Audit remis à la direction Exemple', $audits);
        $this->assertNotNull($audits['Audit remis à la direction Exemple']['delivery']);
        $delivered = $audits['Audit remis à la direction Exemple']['delivery'];
        $this->assertStringContainsString('Fanny', $delivered['recipient']);

        $audit->delete(['id' => $audit->getID()], true);
        $this->assertNull(AuditProgramme::delivery($audit->getID()));
    }

    public function testCertificationAuditsStayOutOfTheProgramme(): void
    {
        $process = $this->activity('Processus audité par l\'organisme Exemple');
        $external = $this->audit('Audit de surveillance Exemple', [
            'audit_type'   => 'surveillance_1',
            'status'       => 'completed',
            'actual_date'  => '2026-03-10',
        ]);
        AuditProgramme::setProcesses($external, [$process]);

        $this->assertFalse(AuditProgramme::isInternal($external));
        $this->assertSame('', (new AuditProgramme())->getTabNameForItem($external));
        $programme = AuditProgramme::programme(2025, '2026-11-20');
        $this->assertSame(Rules::STATE_NONE, $this->row($programme, $process)['state']);
    }

    public function testTabFollowsTheRightsOfTheAudit(): void
    {
        $audit = $this->audit('Audit droits Exemple');
        $tab = new AuditProgramme();
        $this->assertNotSame('', $tab->getTabNameForItem($audit));
        ob_start();
        $this->assertTrue(AuditProgramme::displayTabContentForItem($audit));
        $this->assertStringContainsString('grc-audit-programme-tab', (string) ob_get_clean());

        $_SESSION['glpiactiveprofile'][Audit::$rightname] = 0;
        $this->assertSame('', $tab->getTabNameForItem($audit));
        $this->assertFalse(AuditProgramme::displayTabContentForItem($audit));
        $this->assertFalse(AuditProgramme::canView());
        $this->assertFalse(AuditProgramme::getMenuContent());
    }
}
