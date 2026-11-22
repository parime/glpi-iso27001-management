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

use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditChecklist;
use GlpiPlugin\Grcmanager\AuditDuplication;
use GlpiPlugin\Grcmanager\AuditEvidenceRequest;
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\Services\Audit\DuplicationRules;
use Profile_User;

/**
 * Issue #881 : l'audit du cycle suivant reprend la préparation d'un audit passé (périmètre,
 * personnes, demandes de preuves, grille) et repart de zéro. Données fictives (« société Exemple »).
 */
final class AuditDuplicationTest extends GrcmanagerIntegrationTestCase
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

    private function member(string $firstname, bool $active = true): int
    {
        $id = $this->createTestUser($firstname, 'Exemple', ['is_active' => $active ? 1 : 0]);
        (new Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => 0, 'is_recursive' => 1]);

        return $id;
    }

    private function firstControlId(): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Control::getTable(),
            'WHERE'  => ['entities_id' => 0],
            'ORDER'  => 'id',
            'LIMIT'  => 1,
        ])->current();
        $this->assertNotNull($row, 'La SoA de la racine a au moins une mesure.');

        return (int) $row['id'];
    }

    /**
     * Audit passé, terminé : une mesure, un processus, une équipe (dont un compte désactivé), une
     * demande de preuve acceptée et une ligne de grille conforme.
     *
     * @return array{audit: Audit, controlId: int, processId: int, assignee: int, gone: int}
     */
    private function pastAudit(): array
    {
        global $DB;

        $auditor = $this->member('Alice');
        $assignee = $this->member('Bruno');
        $gone = $this->member('Claire', false);
        $controlId = $this->firstControlId();
        $audit = new Audit();
        $id = (int) $audit->add([
            'title'           => 'Audit interne Exemple 2026',
            'objectives'      => 'Objectifs Exemple',
            'criteria'        => 'Criteres Exemple',
            'scope'           => 'Perimetre Exemple',
            'users_id'        => $auditor,
            // La personne connectée, responsable de l'audit, en voit la grille.
            'users_id_responsible' => (int) \Session::getLoginUserID(),
            'status'          => 'planned',
            'planned_date'    => '2026-03-10',
            'risk_categories' => 'people',
            'linked_controls' => [$controlId],
        ]);
        $this->assertGreaterThan(0, $id);
        $audit->getFromDB($id);
        $processId = (int) (new ResponsibilityActivity())->add(['name' => 'Sauvegardes Exemple', 'entities_id' => 0]);
        AuditProgramme::setProcesses($audit, [$processId]);
        $this->assertTrue(AuditTeam::addMember($id, $assignee, 'auditee'));
        $DB->insert(AuditTeam::TABLE, ['plugin_grcmanager_audits_id' => $id, 'users_id' => $gone, 'role' => 'auditor']);
        $DB->insert(AuditEvidenceRequest::getTable(), [
            'plugin_grcmanager_audits_id'   => $id,
            'plugin_grcmanager_controls_id' => $controlId,
            'reference'                     => 'REQ-Exemple-1',
            'description'                   => 'Liste des sauvegardes Exemple',
            'users_id'                      => $assignee,
            'due_date'                      => '2026-02-28',
            'status'                        => 'accepted',
            'review_comment'                => 'OK',
        ]);
        $DB->insert(AuditChecklist::TABLE, [
            'plugin_grcmanager_audits_id' => $id,
            'ref_type'                    => 'free',
            'code'                        => 'P1',
            'label'                       => 'Point Exemple',
            'question'                    => 'Question Exemple',
            'users_id_auditee'            => $gone,
            'evidence'                    => 'Preuve Exemple',
            'result'                      => 'compliant',
            'rank'                        => 1,
        ]);
        $DB->update(Audit::getTable(), [
            'status' => 'completed', 'actual_date' => '2026-03-12', 'conclusion' => 'Conclusion Exemple',
        ], ['id' => $id]);
        $audit->getFromDB($id);

        return ['audit' => $audit, 'controlId' => $controlId, 'processId' => $processId, 'assignee' => $assignee, 'gone' => $gone];
    }

    public function testNextCycleCopiesThePreparationAndStartsFromScratch(): void
    {
        $past = $this->pastAudit();
        $source = $past['audit'];
        $this->assertSame(
            ['controls' => 1, 'processes' => 1, 'people' => 4, 'evidence' => 1, 'checklist' => 1],
            AuditDuplication::counts($source)
        );

        $result = AuditDuplication::duplicate($source, [
            'title'        => 'Audit interne Exemple 2027',
            'planned_date' => '2027-03-10',
            'parts'        => DuplicationRules::PARTS,
        ]);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame(2, $result['dropped'], 'compte désactivé : équipe et audité de la grille');

        $copy = new Audit();
        $this->assertTrue($copy->getFromDB($result['id']));
        $this->assertSame('Audit interne Exemple 2027', $copy->fields['title']);
        $this->assertSame('2027-03-10', $copy->fields['planned_date']);
        $this->assertSame('planned', $copy->fields['status']);
        $this->assertEmpty($copy->fields['actual_date']);
        $this->assertEmpty($copy->fields['conclusion']);
        $this->assertSame('Criteres Exemple', $copy->fields['criteria']);
        $this->assertSame('people', $copy->fields['risk_categories']);
        $this->assertSame((int) $source->fields['users_id'], (int) $copy->fields['users_id']);
        $this->assertSame($source->getID(), (int) $copy->fields[DuplicationRules::SOURCE_FIELD]);

        $this->assertSame([$past['controlId']], Audit::getLinkedControlIds($copy->getID()));
        $this->assertSame([$past['processId']], AuditProgramme::processIds($copy->getID()));
        $members = AuditTeam::getMembers($copy->getID());
        $this->assertSame([$past['assignee']], array_column($members, 'users_id'));

        $requests = AuditEvidenceRequest::getForAudit($copy->getID());
        $this->assertCount(1, $requests);
        $this->assertSame('REQ-Exemple-1', $requests[0]['reference']);
        $this->assertSame('requested', $requests[0]['status']);
        $this->assertSame('2027-02-28', $requests[0]['due_date'], 'échéance décalée d\'un an');
        $this->assertSame($past['assignee'], (int) $requests[0]['users_id']);
        $this->assertEmpty($requests[0]['review_comment']);

        $lines = AuditChecklist::getLines($copy->getID());
        $this->assertCount(1, $lines);
        $this->assertSame('Question Exemple', $lines[0]['question']);
        $this->assertSame('not_audited', $lines[0]['result']);
        $this->assertEmpty($lines[0]['evidence']);
        $this->assertSame(0, (int) $lines[0]['users_id_auditee']);

        // L'audit d'origine n'a pas changé ; chacun voit l'autre.
        $source->getFromDB($source->getID());
        $this->assertSame('completed', $source->fields['status']);
        $this->assertCount(1, AuditEvidenceRequest::getForAudit($source->getID()));
        $this->assertSame($source->getID(), AuditDuplication::sourceOf($copy)['id'] ?? null);
        $this->assertSame([$copy->getID()], array_column(AuditDuplication::copiesOf($source), 'id'));
    }

    public function testUncheckedPartsAreNotCopied(): void
    {
        $source = $this->pastAudit()['audit'];
        $result = AuditDuplication::duplicate($source, [
            'title'        => 'Audit interne Exemple nu',
            'planned_date' => '2027-03-10',
            'parts'        => [],
        ]);
        $this->assertGreaterThan(0, $result['id']);
        $copy = new Audit();
        $copy->getFromDB($result['id']);
        $this->assertSame(0, (int) $copy->fields['users_id']);
        $this->assertSame('', (string) $copy->fields['risk_categories']);
        $this->assertSame('Objectifs Exemple', $copy->fields['objectives']);
        $this->assertSame([], Audit::getLinkedControlIds($copy->getID()));
        $this->assertSame([], AuditTeam::getMembers($copy->getID()));
        $this->assertSame([], AuditEvidenceRequest::getForAudit($copy->getID()));
        $this->assertSame([], AuditChecklist::getLines($copy->getID()));
    }

    public function testCertificationBodyAuditMovesToTheNextStep(): void
    {
        $audit = new Audit();
        $id = (int) $audit->add([
            'title'        => 'Audit initial Exemple',
            'audit_type'   => 'initial',
            'lead_auditor' => 'Auditeur Exemple',
            'status'       => 'completed',
            'planned_date' => '2026-05-04',
        ]);
        $audit->getFromDB($id);
        $result = AuditDuplication::duplicate($audit, [
            'title'        => 'Audit de surveillance Exemple',
            'planned_date' => '2027-05-04',
            'audit_type'   => 'surveillance_1',
            'lead_auditor' => 'Nouvel auditeur Exemple',
            'parts'        => [],
        ]);
        $copy = new Audit();
        $this->assertTrue($copy->getFromDB($result['id']));
        $this->assertSame('surveillance_1', $copy->fields['audit_type']);
        $this->assertSame('Nouvel auditeur Exemple', $copy->fields['lead_auditor']);
    }

    public function testInternalAuditStaysInternalAndTitleAndDateAreRequired(): void
    {
        $source = $this->pastAudit()['audit'];
        $result = AuditDuplication::duplicate($source, [
            'title' => 'Audit Exemple', 'planned_date' => '2027-01-10', 'audit_type' => 'initial', 'parts' => [],
        ]);
        $copy = new Audit();
        $copy->getFromDB($result['id']);
        $this->assertSame('internal', $copy->fields['audit_type']);

        $this->assertSame(0, AuditDuplication::duplicate($source, ['title' => '', 'planned_date' => '2027-01-10'])['id']);
        $this->assertSame(0, AuditDuplication::duplicate($source, ['title' => 'X', 'planned_date' => 'demain'])['id']);
    }

    public function testReadOnlyProfileCannotDuplicate(): void
    {
        $source = $this->pastAudit()['audit'];
        $_SESSION['glpiactiveprofile'][Audit::RIGHTNAME] = READ;
        $this->assertFalse(AuditDuplication::canDuplicate($source));
        $this->assertSame(0, AuditDuplication::duplicate($source, [
            'title' => 'Audit Exemple', 'planned_date' => '2027-01-10', 'parts' => DuplicationRules::PARTS,
        ])['id']);
    }
}
