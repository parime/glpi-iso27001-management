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

use Document;
use Document_Item;
use NotificationEventMailing;
use Notification_NotificationTemplate;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditEvidenceRequest;
use GlpiPlugin\Grcmanager\AuditorAccess;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\DocumentAccess;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\NotificationTargetAuditEvidenceRequest;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Audit\EvidenceRequestRules as Rules;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;
use GlpiPlugin\Grcmanager\TrainingQuiz;

/**
 * Issue #538 : demandes de preuves d'un audit (demande, pièces, soumission, revue, relances,
 * import CSV) et accès de l'auditeur limité à son audit et dans le temps. Indépendant de la langue
 * de l'instance : aucune comparaison de texte traduit.
 */
final class AuditEvidenceRequestTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    /** @var list<string> */
    private array $files = [];

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION;
        $_SESSION['glpiactive_entity'] ??= 0;
        $this->ids = [
            'alice'  => $this->createTestUser('Alice', 'Preuves'),
            'bob'    => $this->createTestUser('Bob', 'Autre'),
            'oscar'  => $this->createTestUser('Oscar', 'Auditeur'),
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        // Les fichiers rangés par GLPI survivraient au retour en arrière de la base.
        global $DB;
        foreach (
            $DB->request([
                'SELECT' => ['documents_id'],
                'FROM'   => AuditEvidenceRequest::PIECES_TABLE,
                'WHERE'  => ['origin' => AuditEvidenceRequest::ORIGIN_UPLOAD],
            ]) as $row
        ) {
            (new Document())->delete(['id' => (int) $row['documents_id']], true);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function control(string $code): Control
    {
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code]));

        return $control;
    }

    private function audit(string $title = 'PHPUnit audit de certification'): Audit
    {
        $audit = new Audit();
        $id = (int) $audit->add([
            'title'           => $title,
            'users_id'        => 2,
            'status'          => 'in_progress',
            'planned_date'    => date('Y-m-d'),
            'linked_controls' => [$this->control('A.5.15')->getID(), $this->control('A.8.13')->getID()],
        ]);
        $this->assertGreaterThan(0, $id);
        $audit->getFromDB($id);

        return $audit;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function request(Audit $audit, string $code = 'A.5.15', array $extra = []): AuditEvidenceRequest
    {
        $request = new AuditEvidenceRequest();
        $id = (int) $request->add($extra + [
            'plugin_grcmanager_audits_id'   => $audit->getID(),
            'plugin_grcmanager_controls_id' => $this->control($code)->getID(),
            'reference'                     => 'REQ-' . $code,
            'description'                   => 'Revue des droits du dernier trimestre',
            'users_id'                      => $this->ids['alice'],
            'due_date'                      => date('Y-m-d', strtotime('+20 days')),
        ]);
        $this->assertGreaterThan(0, $id);
        $request->getFromDB($id);

        return $request;
    }

    private function pdf(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'grc');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $this->files[] = $path;

        return $path;
    }

    private function requestStatus(int $requestId): string
    {
        return (string) AuditEvidenceRequest::getRow($requestId)['status'];
    }

    public function testARequestIsCreatedWaitingForItsAssignee(): void
    {
        $audit = $this->audit();
        $request = $this->request($audit, 'A.5.15', ['status' => Rules::STATUS_ACCEPTED]);

        $this->assertSame(Rules::STATUS_REQUESTED, $request->fields['status'], 'statut imposé à la création');
        $this->assertSame(2, (int) $request->fields['users_id_requester']);
        $this->assertFalse(
            (new AuditEvidenceRequest())->add(['plugin_grcmanager_audits_id' => $audit->getID(), 'reference' => '  ']),
            'ni référence ni description'
        );
        $this->assertFalse(
            (new AuditEvidenceRequest())->add(['plugin_grcmanager_audits_id' => 999999999, 'reference' => 'R']),
            'audit introuvable'
        );
        $free = $this->request($audit, 'A.5.15', ['plugin_grcmanager_controls_id' => 999999999]);
        $this->assertSame(0, (int) $free->fields['plugin_grcmanager_controls_id'], 'mesure inconnue : exigence libre');

        // Une modification ordinaire ne touche ni au statut ni à l'audit.
        $request->update([
            'id' => $request->getID(), 'status' => Rules::STATUS_ACCEPTED, 'plugin_grcmanager_audits_id' => 1,
        ]);
        $request->getFromDB($request->getID());
        $this->assertSame(Rules::STATUS_REQUESTED, $request->fields['status']);
        $this->assertSame($audit->getID(), (int) $request->fields['plugin_grcmanager_audits_id']);

        $sources = array_column(MySpaceProvider::items($this->ids['alice'], date('Y-m-d')), 'source');
        $this->assertContains('evidence_request', $sources, 'Mon espace GRC de la personne désignée');
        $this->assertNotContains(
            'evidence_request',
            array_column(MySpaceProvider::items($this->ids['bob'], date('Y-m-d')), 'source')
        );
        $this->assertSame(['A.5.15', 'A.5.15'], array_map(
            static fn (array $r): string => substr((string) $r['reference'], 4),
            AuditEvidenceRequest::getForAudit($audit->getID())
        ));
    }

    public function testTheAssigneeProvidesAFileAndSubmitsIt(): void
    {
        $audit = $this->audit();
        $request = $this->request($audit);
        $id = $request->getID();

        $this->assertNotSame('', AuditEvidenceRequest::submit($id, $this->ids['alice']), 'aucune pièce');
        $this->assertNotSame(
            '',
            AuditEvidenceRequest::addUploadedPiece($id, $this->ids['bob'], $this->pdf(), 'revue.pdf'),
            'une autre personne'
        );
        $this->assertSame(
            '',
            AuditEvidenceRequest::addUploadedPiece($id, $this->ids['alice'], $this->pdf(), 'revue.pdf')
        );

        $pieces = AuditEvidenceRequest::getPieces($id);
        $this->assertCount(1, $pieces);
        $this->assertFalse($pieces[0]['is_submitted']);
        $documentsId = $pieces[0]['documents_id'];
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documentsId, 'itemtype' => Audit::class, 'items_id' => $audit->getID(),
        ]), 'document de l\'audit');
        $this->assertSame(
            ['itemtype' => Audit::class, 'items_id' => $audit->getID()],
            RestrictedDocument::sourceOf($documentsId),
            'réservé à l\'équipe de l\'audit'
        );

        $this->assertSame('', AuditEvidenceRequest::submit($id, $this->ids['alice'], 'Extrait de l\'outil de revue'));
        $row = AuditEvidenceRequest::getRow($id);
        $this->assertSame(Rules::STATUS_SUBMITTED, $row['status']);
        $this->assertNotNull($row['date_submitted']);
        $this->assertTrue(AuditEvidenceRequest::getPieces($id)[0]['is_submitted']);
        $this->assertNotSame(
            '',
            AuditEvidenceRequest::addUploadedPiece($id, $this->ids['alice'], $this->pdf(), 'autre.pdf'),
            'en revue : plus de pièce'
        );
        $this->assertNotSame(
            '',
            AuditEvidenceRequest::removePiece($pieces[0]['id'], $this->ids['alice']),
            'pièce soumise'
        );
        $this->assertNotContains(
            'evidence_request',
            array_column(MySpaceProvider::items($this->ids['alice'], date('Y-m-d')), 'source')
        );
        $this->assertContains(
            'evidence_review',
            array_column(MySpaceProvider::items(2, date('Y-m-d')), 'source'),
            'l\'auditeur de l\'audit a une preuve à revoir'
        );
    }

    public function testReviewAcceptsOrAsksToCompleteWithAReason(): void
    {
        $audit = $this->audit();
        $first = $this->request($audit, 'A.5.15');
        $second = $this->request($audit, 'A.8.13');
        foreach ([$first, $second] as $request) {
            AuditEvidenceRequest::addUploadedPiece($request->getID(), $this->ids['alice'], $this->pdf(), 'preuve.pdf');
            $this->assertSame('', AuditEvidenceRequest::submit($request->getID(), $this->ids['alice']));
        }

        $this->assertSame('', AuditEvidenceRequest::review($first->getID(), 2, Rules::DECISION_ACCEPT, ''));
        $this->assertSame(Rules::STATUS_ACCEPTED, $this->requestStatus($first->getID()));
        $this->assertNotSame('', AuditEvidenceRequest::review($first->getID(), 2, Rules::DECISION_TO_COMPLETE, 'x'));
        $this->assertNotSame('', AuditEvidenceRequest::review($second->getID(), 2, Rules::DECISION_TO_COMPLETE, ' '));
        $this->assertSame(Rules::STATUS_SUBMITTED, $this->requestStatus($second->getID()), 'renvoi sans motif refusé');
        $this->assertSame(
            '',
            AuditEvidenceRequest::review($second->getID(), 2, Rules::DECISION_TO_COMPLETE, 'Rapport non signé')
        );
        $row = AuditEvidenceRequest::getRow($second->getID());
        $this->assertSame(Rules::STATUS_TO_COMPLETE, $row['status']);
        $this->assertSame('Rapport non signé', $row['review_comment']);
        $this->assertSame((int) $this->ids['alice'], (int) $row['users_id'], 'attribution inchangée');

        // La personne complète et soumet de nouveau : la pièce déjà soumise suffit, un ajout aussi.
        $this->assertSame(
            '',
            AuditEvidenceRequest::addUploadedPiece($second->getID(), $this->ids['alice'], $this->pdf(), 'signe.pdf')
        );
        $this->assertSame('', AuditEvidenceRequest::submit($second->getID(), $this->ids['alice'], 'Version signée'));
        $this->assertSame(Rules::STATUS_SUBMITTED, $this->requestStatus($second->getID()));

        $progress = AuditEvidenceRequest::progressForAudit($audit->getID());
        $this->assertSame(['total' => 2, 'submitted' => 1, 'accepted' => 1, 'percent' => 50], array_intersect_key(
            $progress,
            ['total' => 0, 'accepted' => 0, 'submitted' => 0, 'percent' => 0]
        ));
    }

    public function testRemindersOnlyNotifyAndNeverChangeTheRequest(): void
    {
        global $DB;

        $audit = $this->audit();
        $soon = $this->request($audit, 'A.5.15', ['due_date' => date('Y-m-d', strtotime('+3 days'))]);
        $later = $this->request($audit, 'A.8.13');
        $late = $this->request($audit, 'A.8.13', ['due_date' => date('Y-m-d', strtotime('-2 days'))]);
        $submitted = $this->request($audit, 'A.5.15', ['due_date' => date('Y-m-d', strtotime('-2 days'))]);
        AuditEvidenceRequest::addUploadedPiece($submitted->getID(), $this->ids['alice'], $this->pdf(), 'x.pdf');
        AuditEvidenceRequest::submit($submitted->getID(), $this->ids['alice']);
        $before = AuditEvidenceRequest::getRow($late->getID());

        $today = date('Y-m-d');
        $this->assertSame(2, AuditEvidenceRequest::sendReminders($today), 'bientôt dû et en retard');
        $this->assertSame(0, AuditEvidenceRequest::sendReminders($today), 'une relance à la fois');
        $this->assertSame(
            2,
            AuditEvidenceRequest::sendReminders(date('Y-m-d', strtotime('+8 days'))),
            'relance hebdomadaire du retard, et la première après l\'échéance'
        );

        $after = AuditEvidenceRequest::getRow($late->getID());
        foreach (['status', 'users_id', 'due_date', 'plugin_grcmanager_audits_id'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertSame(Rules::STATUS_SUBMITTED, $this->requestStatus($submitted->getID()));
        $this->assertNull(AuditEvidenceRequest::getRow($later->getID())['date_reminder'], 'échéance lointaine');
        $this->assertSame((int) $soon->getID(), (int) AuditEvidenceRequest::getRow($soon->getID())['id']);

        // Relance à la demande : toutes celles qui attendent encore ; rien pour un audit annulé.
        $this->assertSame(3, AuditEvidenceRequest::sendReminders($today, $audit->getID()));
        $DB->update(Audit::getTable(), ['status' => 'cancelled'], ['id' => $audit->getID()]);
        $this->assertSame(0, AuditEvidenceRequest::sendReminders(date('Y-m-d', strtotime('+30 days'))));
        $this->assertSame([], AuditEvidenceRequest::getAssignedTo($this->ids['alice']), 'audit annulé');
    }

    public function testTheAuditorSeesOnlyItsAuditAndTheSubmittedEvidence(): void
    {
        $audit = $this->audit();
        $other = $this->audit('PHPUnit autre audit');
        $submitted = $this->request($audit, 'A.5.15');
        $draft = $this->request($audit, 'A.8.13');
        $hidden = $this->request($audit, 'A.8.13', ['reference' => 'REQ-pas-encore']);
        AuditEvidenceRequest::addUploadedPiece($submitted->getID(), $this->ids['alice'], $this->pdf(), 'soumise.pdf');
        $this->assertSame('', AuditEvidenceRequest::submit($submitted->getID(), $this->ids['alice']));
        AuditEvidenceRequest::review($submitted->getID(), 2, Rules::DECISION_TO_COMPLETE, 'Ajouter la version signée');
        $this->assertSame(
            '',
            AuditEvidenceRequest::addUploadedPiece($submitted->getID(), $this->ids['alice'], $this->pdf(), 'x.pdf')
        );
        AuditEvidenceRequest::addUploadedPiece($draft->getID(), $this->ids['alice'], $this->pdf(), 'brouillon.pdf');
        (new Nonconformity())->add([
            'title' => 'PHPUnit constat mineur', 'plugin_grcmanager_audits_id' => $audit->getID(),
            'finding_type' => 'observation', 'severity' => 'minor', 'status' => 'open',
        ]);
        $oscar = $this->ids['oscar'];

        $tooLate = date('Y-m-d', strtotime('+400 days'));
        $this->assertNotSame('', AuditorAccess::grant($audit->getID(), $oscar, $tooLate, 2), 'plus d\'un an');
        $this->assertNotSame('', AuditorAccess::grant($audit->getID(), 999999999, date('Y-m-d'), 2), 'compte inconnu');
        $this->assertSame('', AuditorAccess::grant($audit->getID(), $oscar, date('Y-m-d', strtotime('+10 days')), 2));
        $this->assertTrue(AuditorAccess::isActive($audit->getID(), $oscar));
        $this->assertFalse(AuditorAccess::isActive($other->getID(), $oscar), 'un autre audit');
        $this->assertFalse(AuditorAccess::isActive($audit->getID(), $this->ids['bob']));
        $this->assertFalse(
            AuditorAccess::isActive($audit->getID(), $oscar, date('Y-m-d', strtotime('+11 days'))),
            'après la date de fin'
        );
        $this->assertSame([$audit->getID()], array_column(AuditorAccess::getActiveAudits($oscar), 'id'));
        $this->assertContains('audit_access', array_column(MySpaceProvider::items($oscar, date('Y-m-d')), 'source'));

        // L'auditeur ne fait pas partie de l'équipe : aucun document de l'audit par GLPI.
        $this->assertFalse(AuditTeam::canAccessDocuments($audit->getID(), $oscar));

        $space = AuditorAccess::buildSpace($audit, $oscar);
        $this->assertSame([$submitted->getID()], array_column($space['requests'], 'id'), 'demandes soumises seulement');
        $names = array_column($space['requests'][0]['pieces'], 'name');
        $this->assertSame(['soumise.pdf'], $names, 'pièces soumises seulement');
        $this->assertNotSame('', $space['requests'][0]['pieces'][0]['url']);
        $this->assertCount(2, $space['controls']);
        $this->assertCount(1, $space['findings']);
        $this->assertNotContains($hidden->getID(), array_column($space['requests'], 'id'));

        $piece = static fn (int $requestId, string $name): array => array_values(array_filter(
            array_map(
                static fn (array $p): array => $p + ['plugin_grcmanager_auditevidencerequests_id' => $requestId],
                AuditEvidenceRequest::getPieces($requestId)
            ),
            static fn (array $p): bool => $p['name'] === $name
        ))[0];
        $submittedPiece = AuditEvidenceRequest::getPieceRow($piece($submitted->getID(), 'soumise.pdf')['id']);
        $draftPiece = AuditEvidenceRequest::getPieceRow($piece($draft->getID(), 'brouillon.pdf')['id']);
        $laterPiece = AuditEvidenceRequest::getPieceRow($piece($submitted->getID(), 'x.pdf')['id']);
        $this->assertTrue(AuditEvidenceRequest::canDownloadPiece($submittedPiece, $oscar));
        $this->assertFalse(AuditEvidenceRequest::canDownloadPiece($draftPiece, $oscar), 'demande jamais soumise');
        $this->assertFalse(
            AuditEvidenceRequest::canDownloadPiece($laterPiece, $oscar),
            'pièce ajoutée après la soumission'
        );
        $this->assertFalse(AuditEvidenceRequest::canDownloadPiece($submittedPiece, $this->ids['bob']));
        $this->assertTrue(
            AuditEvidenceRequest::canDownloadPiece($draftPiece, $this->ids['alice']),
            'la personne désignée'
        );
        $this->assertTrue(AuditEvidenceRequest::canDownloadPiece($draftPiece, 2), 'l\'auditeur de la fiche (équipe)');

        // Révocation : plus rien, sans rien supprimer.
        $accesses = AuditorAccess::getForAudit($audit->getID());
        $this->assertTrue(AuditorAccess::revoke($audit->getID(), $accesses[0]['id'], 2));
        $this->assertFalse(AuditorAccess::isActive($audit->getID(), $oscar));
        $this->assertFalse(AuditEvidenceRequest::canDownloadPiece($submittedPiece, $oscar));
        $this->assertSame('revoked', AuditorAccess::getForAudit($audit->getID())[0]['state']);
        $this->assertFalse(AuditorAccess::revoke($other->getID(), $accesses[0]['id'], 2), 'accès d\'un autre audit');

        DocumentAccess::log($submittedPiece['documents_id'], Audit::class, $audit->getID(), $oscar);
        $accesses = DocumentAccess::getAccesses(Audit::class, $audit->getID());
        $this->assertCount(1, $accesses, 'téléchargement historisé');
    }

    public function testExistingEvidenceOfTheControlCanBeCitedNotOtherDocuments(): void
    {
        $audit = $this->audit();
        $request = $this->request($audit, 'A.8.13');
        $control = $this->control('A.8.13');
        $documents = [];
        foreach (['Rapport de sauvegarde', 'Copie de quiz', 'Autre document'] as $name) {
            $documents[$name] = (int) (new Document())->add(['name' => 'PHPUnit ' . $name, 'entities_id' => 0]);
        }
        foreach (['Rapport de sauvegarde', 'Copie de quiz'] as $name) {
            (new Document_Item())->add([
                'documents_id' => $documents[$name], 'itemtype' => Control::class, 'items_id' => $control->getID(),
            ]);
        }
        RestrictedDocument::registerFor($documents['Copie de quiz'], TrainingQuiz::class, 1);

        $selectable = array_column(AuditEvidenceRequest::getSelectableDocuments($request->fields), 'documents_id');
        $this->assertSame(
            [$documents['Rapport de sauvegarde']],
            $selectable,
            'preuve de la mesure, hors document restreint d\'une autre source'
        );
        $this->assertNotSame(
            '',
            AuditEvidenceRequest::addExistingPiece($request->getID(), $this->ids['alice'], $documents['Autre document'])
        );
        $this->assertNotSame(
            '',
            AuditEvidenceRequest::addExistingPiece(
                $request->getID(),
                $this->ids['bob'],
                $documents['Rapport de sauvegarde']
            )
        );
        $this->assertSame(
            '',
            AuditEvidenceRequest::addExistingPiece(
                $request->getID(),
                $this->ids['alice'],
                $documents['Rapport de sauvegarde']
            )
        );
        $this->assertSame([], AuditEvidenceRequest::getSelectableDocuments($request->fields), 'déjà jointe');
        $this->assertNull(RestrictedDocument::sourceOf($documents['Rapport de sauvegarde']), 'audience inchangée');
        $this->assertSame(0, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documents['Rapport de sauvegarde'], 'itemtype' => Audit::class,
        ]), 'citée, pas rattachée à l\'audit');

        // Retrait : la preuve existante reste ; un fichier déposé est supprimé.
        $pieces = AuditEvidenceRequest::getPieces($request->getID());
        $this->assertSame('', AuditEvidenceRequest::removePiece($pieces[0]['id'], $this->ids['alice']));
        $this->assertTrue((new Document())->getFromDB($documents['Rapport de sauvegarde']));
        AuditEvidenceRequest::addUploadedPiece($request->getID(), $this->ids['alice'], $this->pdf(), 'retire.pdf');
        $uploaded = AuditEvidenceRequest::getPieces($request->getID())[0];
        $this->assertSame('', AuditEvidenceRequest::removePiece($uploaded['id'], $this->ids['alice']));
        $this->assertFalse((new Document())->getFromDB($uploaded['documents_id']));
    }

    public function testTheAuditorsListIsImportedFromCsv(): void
    {
        $audit = $this->audit();
        $alice = new \User();
        $alice->getFromDB($this->ids['alice']);
        $csv = "Référence;Mesure;Description;Échéance;Responsable\n"
            . "REQ-1;A.5.15;Revue des droits;30/12/2026;" . $alice->fields['name'] . "\n"
            . "REQ-2;Z.9.99;Mesure inconnue;;\n"
            . "REQ-3;;Exigence libre;;compte.inexistant\n"
            . "REQ-4;A.8.13;Sauvegardes;31/02/2026;\n"
            . "REQ-5;;Sans responsable;;\n";
        $result = AuditEvidenceRequest::importCsv($audit, $csv, [
            'users_id' => $this->ids['bob'], 'due_date' => date('Y-m-d', strtotime('+15 days')),
        ]);

        $this->assertSame(2, $result['created']);
        $this->assertCount(3, $result['errors'], 'mesure inconnue, compte inconnu, date invalide');
        $rows = AuditEvidenceRequest::getForAudit($audit->getID());
        $this->assertSame(['REQ-1', 'REQ-5'], array_column($rows, 'reference'));
        $this->assertSame($this->ids['alice'], (int) $rows[0]['users_id']);
        $this->assertSame('2026-12-30', $rows[0]['due_date']);
        $this->assertSame($this->control('A.5.15')->getID(), (int) $rows[0]['plugin_grcmanager_controls_id']);
        $this->assertSame($this->ids['bob'], (int) $rows[1]['users_id'], 'personne par défaut');
        $this->assertSame(date('Y-m-d', strtotime('+15 days')), $rows[1]['due_date'], 'échéance par défaut');
    }

    public function testRightsFollowTheAudit(): void
    {
        $audit = $this->audit();
        $request = $this->request($audit);
        AuditEvidenceRequest::addUploadedPiece($request->getID(), $this->ids['alice'], $this->pdf(), 'p.pdf');
        AuditEvidenceRequest::submit($request->getID(), $this->ids['alice']);
        $this->assertTrue($request->canUpdateItem());

        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertTrue($request->canViewItem());
            $this->assertFalse($request->canUpdateItem(), 'lecture seule');
            $this->assertNotSame('', AuditEvidenceRequest::review($request->getID(), 2, Rules::DECISION_ACCEPT, ''));
            $this->assertSame(Rules::STATUS_SUBMITTED, $this->requestStatus($request->getID()));
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
            $this->assertFalse($request->canViewItem(), 'sans droit sur les audits');
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $rights;
        }
    }

    public function testNotificationsTargetTheAssigneeAndTheAuditLeads(): void
    {
        $audit = $this->audit();
        $audit->update(['id' => $audit->getID(), 'users_id_responsible' => $this->ids['bob']]);
        $request = $this->request($audit);
        $target = new NotificationTargetAuditEvidenceRequest(0, AuditEvidenceRequest::SUBMITTED_EVENT, $request);
        $this->assertArrayHasKey(AuditEvidenceRequest::TO_COMPLETE_EVENT, $target->getEvents());

        global $DB;
        // Une habilitation non récursive suffit : l'audit est global. Pas d'entité de test : la liste
        // des sous-entités en cache survivrait au retour en arrière (KriAlertTest).
        $entity = 0;
        foreach ([$this->ids['alice'], $this->ids['bob'], 2] as $usersId) {
            (new \UserEmail())->add([
                'users_id' => $usersId, 'email' => "phpunit$usersId@example.com", 'is_default' => 1,
            ]);
            if ($usersId !== 2) {
                $DB->insert('glpi_profiles_users', [
                    'users_id' => $usersId, 'profiles_id' => 1, 'entities_id' => $entity, 'is_recursive' => 0,
                ]);
            }
        }
        $recipients = function (int $targetId) use ($request): array {
            $target = new NotificationTargetAuditEvidenceRequest(0, AuditEvidenceRequest::SUBMITTED_EVENT, $request);
            $target->setMode(Notification_NotificationTemplate::MODE_MAIL)->setEvent(NotificationEventMailing::class);
            $target->addSpecificTargets(['items_id' => $targetId], []);

            return array_map('intval', array_column($target->getTargets(), 'users_id'));
        };
        $this->assertSame([$this->ids['alice']], $recipients(NotificationTargetAuditEvidenceRequest::TARGET_ASSIGNEE));
        $leads = $recipients(NotificationTargetAuditEvidenceRequest::TARGET_AUDIT_LEADS);
        sort($leads);
        $expected = [2, $this->ids['bob']];
        sort($expected);
        $this->assertSame($expected, $leads);
    }

    public function testPurgingTheAuditRemovesItsRequestsPiecesAndAccesses(): void
    {
        $audit = $this->audit();
        $request = $this->request($audit);
        AuditEvidenceRequest::addUploadedPiece($request->getID(), $this->ids['alice'], $this->pdf(), 'p.pdf');
        AuditorAccess::grant($audit->getID(), $this->ids['oscar'], date('Y-m-d', strtotime('+5 days')), 2);
        $documentsId = AuditEvidenceRequest::getPieces($request->getID())[0]['documents_id'];

        $audit->delete(['id' => $audit->getID()], true);

        $this->assertSame(0, countElementsInTable(AuditEvidenceRequest::getTable(), ['id' => $request->getID()]));
        $this->assertSame(0, countElementsInTable(AuditEvidenceRequest::PIECES_TABLE, [
            'plugin_grcmanager_auditevidencerequests_id' => $request->getID(),
        ]));
        $this->assertSame(
            0,
            countElementsInTable(AuditorAccess::TABLE, ['plugin_grcmanager_audits_id' => $audit->getID()])
        );
        (new Document())->delete(['id' => $documentsId], true);
    }
}
