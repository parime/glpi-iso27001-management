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
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditChecklist;
use GlpiPlugin\Grcmanager\AuditChecklistTemplate;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules as Rules;

/**
 * Issue #606 : grille d'audit interne (lignes depuis les exigences, saisie de l'auditeur, constat
 * créé depuis une ligne, modèles), couverture du programme d'audit et règles d'accès (celles des
 * documents de l'audit). Données fictives (« Audit interne 2027, société Exemple »). Indépendant de
 * la langue de l'instance : aucune comparaison de texte traduit.
 */
final class AuditChecklistTest extends GrcmanagerIntegrationTestCase
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
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function audit(string $title = 'Audit interne 2027, société Exemple', array $extra = []): Audit
    {
        $audit = new Audit();
        $id = (int) $audit->add($extra + [
            'title'           => $title,
            'users_id'        => 2,
            'status'          => 'in_progress',
            'planned_date'    => '2027-03-15',
            'linked_controls' => [$this->control('A.5.15')->getID()],
        ]);
        $this->assertGreaterThan(0, $id);
        $audit->getFromDB($id);

        return $audit;
    }

    private function control(string $code): Control
    {
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code, 'entities_id' => 0]));

        return $control;
    }

    /**
     * @return array<string, array<string, mixed>> lignes par numéro
     */
    private function linesByCode(Audit $audit): array
    {
        return array_column(AuditChecklist::getLines($audit->getID()), null, 'code');
    }

    private function document(Audit $audit, string $name): int
    {
        global $DB;

        $DB->insert(Document::getTable(), ['name' => $name, 'entities_id' => 0, 'is_recursive' => 1]);
        $documentsId = (int) $DB->insertId();
        $DB->insert(Document_Item::getTable(), [
            'documents_id' => $documentsId,
            'itemtype'     => Audit::class,
            'items_id'     => $audit->getID(),
        ]);

        return $documentsId;
    }

    public function testLinesAreGeneratedFromTheRequirementsWithoutDuplicates(): void
    {
        $audit = $this->audit();
        $catalog = AuditChecklist::catalog(0);
        $this->assertArrayHasKey('clause:9.2.2', $catalog);
        $this->assertArrayHasKey('control:A.5.15', $catalog);

        $added = AuditChecklist::addRequirements($audit, ['clause:9.2.2', 'control:A.5.15', 'control:A.99', 'x']);
        $this->assertSame(2, $added, 'clé inconnue ignorée');
        $this->assertSame(0, AuditChecklist::addRequirements($audit, ['control:A.5.15']), 'pas de doublon');

        $lines = $this->linesByCode($audit);
        $this->assertSame(Rules::TYPE_CLAUSE, $lines['9.2.2']['ref_type']);
        $this->assertSame(Rules::RESULT_NOT_AUDITED, $lines['A.5.15']['result']);
        $this->assertSame($this->control('A.5.15')->getID(), (int) $lines['A.5.15']['items_id']);
        $this->assertStringStartsWith('A.5.15', AuditChecklist::lineTitle($lines['A.5.15']));

        // Exigences déjà présentes : plus proposées.
        $options = AuditChecklist::catalogOptions($catalog, AuditChecklist::existingKeys(array_values($lines)));
        $offered = array_merge(...array_values(array_map('array_keys', $options)));
        $this->assertNotContains('control:A.5.15', $offered);
        $this->assertContains('control:A.8.13', $offered);

        $this->assertFalse(AuditChecklist::addFreeLine($audit, 'P-1', '', '  '), 'titre ou question exigé');
        $this->assertTrue(AuditChecklist::addFreeLine($audit, 'P-1', 'Procédure de départ', 'Comptes fermés ?'));
        $this->assertCount(3, AuditChecklist::getLines($audit->getID()));
    }

    public function testAuditorEntryKeepsDocumentsAndFindingsWithinTheAudit(): void
    {
        $audit = $this->audit();
        $other = $this->audit('Autre audit fictif');
        AuditChecklist::addRequirements($audit, ['control:A.5.15']);
        $line = $this->linesByCode($audit)['A.5.15'];
        $ownDocument = $this->document($audit, 'Revue des droits T1 (fictive)');
        $foreignDocument = $this->document($other, 'Document d\'un autre audit');
        $foreignFinding = (int) (new Nonconformity())->add([
            'title' => 'Constat d\'un autre audit', 'plugin_grcmanager_audits_id' => $other->getID(),
        ]);
        $auditee = $this->createTestUser('Alice', 'Exemple');

        $this->assertTrue(AuditChecklist::updateLine($audit, (int) $line['id'], [
            'question'                             => 'Comment les droits sont-ils revus ?',
            'users_id_auditee'                     => $auditee,
            'evidence'                             => 'Extrait de la revue du premier trimestre',
            'documents'                            => [$ownDocument, $foreignDocument],
            'result'                               => Rules::RESULT_NC_MINOR,
            'comment'                              => 'Deux comptes de prestataires non revus',
            'plugin_grcmanager_nonconformities_id' => $foreignFinding,
        ]));
        $this->assertFalse(AuditChecklist::updateLine($other, (int) $line['id'], ['result' => 'compliant']), 'autre audit');

        $row = AuditChecklist::present($audit->getID())[0];
        $this->assertSame(Rules::RESULT_NC_MINOR, $row['result']);
        $this->assertSame($auditee, $row['auditee_id']);
        $this->assertSame([$ownDocument], $row['document_ids'], 'seuls les documents de l\'audit');
        $this->assertSame(0, $row['finding_id'], 'constat d\'un autre audit refusé');
        $this->assertTrue($row['can_raise']);

        $this->assertTrue(AuditChecklist::updateLine($audit, (int) $line['id'], ['result' => 'inconnu']));
        $this->assertSame(Rules::RESULT_NOT_AUDITED, $this->linesByCode($audit)['A.5.15']['result']);
    }

    public function testFindingIsCreatedFromALineOnlyByTheUserAndLinkedBothWays(): void
    {
        $audit = $this->audit();
        AuditChecklist::addRequirements($audit, ['control:A.5.15', 'clause:9.2.2']);
        $lines = $this->linesByCode($audit);
        $lineId = (int) $lines['A.5.15']['id'];
        $this->assertNull(AuditChecklist::findingDraft($lineId), 'non audité : pas de constat');
        AuditChecklist::updateLine($audit, $lineId, [
            'result' => Rules::RESULT_NC_MAJOR, 'question' => 'Revue des droits ?', 'comment' => 'Aucune revue',
        ]);
        $countBefore = countElementsInTable(Nonconformity::getTable(), ['plugin_grcmanager_audits_id' => $audit->getID()]);

        $draft = AuditChecklist::findingDraft($lineId);
        $this->assertNotNull($draft);
        $this->assertSame('nonconformity', $draft['finding_type']);
        $this->assertSame('major', $draft['severity']);
        $this->assertSame($audit->getID(), $draft['plugin_grcmanager_audits_id']);
        $this->assertStringContainsString('A.5.15', $draft['title']);
        $this->assertStringContainsString('Aucune revue', $draft['description']);
        $this->assertSame(
            $countBefore,
            countElementsInTable(Nonconformity::getTable(), ['plugin_grcmanager_audits_id' => $audit->getID()]),
            'le brouillon ne crée rien'
        );

        // Un constat d'un autre audit n'est jamais lié à la ligne.
        $other = $this->audit('Autre audit fictif');
        $foreign = new Nonconformity();
        $foreign->add([
            'title' => 'Constat ailleurs', 'plugin_grcmanager_audits_id' => $other->getID(),
            '_grcmanager_checklistitem' => $lineId,
        ]);
        $this->assertSame(0, (int) $this->linesByCode($audit)['A.5.15']['plugin_grcmanager_nonconformities_id']);

        $finding = new Nonconformity();
        $findingId = (int) $finding->add([
            'title'                       => $draft['title'],
            'description'                 => $draft['description'],
            'finding_type'                => $draft['finding_type'],
            'severity'                    => $draft['severity'],
            'plugin_grcmanager_audits_id' => $audit->getID(),
            '_grcmanager_checklistitem'   => $lineId,
        ]);
        $this->assertGreaterThan(0, $findingId);
        $this->assertSame($findingId, (int) $this->linesByCode($audit)['A.5.15']['plugin_grcmanager_nonconformities_id']);
        $this->assertSame('open', (string) $finding->fields['status'], 'statut du constat inchangé');
        $this->assertNull(AuditChecklist::findingDraft($lineId), 'déjà un constat');

        $back = AuditChecklist::linesForFinding($findingId);
        $this->assertCount(1, $back);
        $this->assertStringContainsString('#grc-checklist-line-' . $lineId, $back[0]['url']);
        $this->assertSame($findingId, AuditChecklist::present($audit->getID())[0]['finding_id']);

        // Constat purgé : la ligne reste, sans lien.
        $finding->delete(['id' => $findingId], true);
        $this->assertSame(0, (int) $this->linesByCode($audit)['A.5.15']['plugin_grcmanager_nonconformities_id']);
        $this->assertCount(2, AuditChecklist::getLines($audit->getID()));
    }

    public function testSameAccessRulesAsTheAuditDocuments(): void
    {
        $audit = $this->audit();
        $stranger = $this->createTestUser('Bob', 'Exterieur');
        $auditee = $this->createTestUser('Chloe', 'Auditee');
        AuditTeam::addMember($audit->getID(), $auditee, AuditTeam::ROLE_AUDITEE);

        $this->assertTrue(AuditChecklist::canViewFor($audit, 2), 'auditeur de la fiche');
        $this->assertTrue(AuditChecklist::canEditFor($audit, 2));
        $this->assertTrue(AuditChecklist::canViewFor($audit, $auditee), 'membre de l\'équipe');
        $this->assertFalse(AuditChecklist::canViewFor($audit, $stranger), 'hors équipe, même avec les droits');
        $this->assertFalse(AuditChecklist::canEditFor($audit, $stranger));

        // Audit terminé : grille figée, toujours lisible et enregistrable comme modèle.
        AuditChecklist::addRequirements($audit, ['control:A.5.15']);
        $audit->update(['id' => $audit->getID(), 'status' => 'cancelled']);
        $audit->getFromDB($audit->getID());
        $this->assertFalse(AuditChecklist::canEditFor($audit, 2));
        $this->assertTrue(AuditChecklist::canManageFor($audit, 2));
        $this->assertTrue(AuditChecklist::canViewFor($audit, 2));

        ob_start();
        AuditChecklist::showForAudit($audit);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-checklist-closed', $html);
        $this->assertStringNotContainsString('grc-checklist-add', $html);
    }

    public function testTemplateKeepsRequirementsAndQuestionsNeverAnswers(): void
    {
        $audit = $this->audit();
        AuditChecklist::addRequirements($audit, ['control:A.5.15', 'clause:9.2.2']);
        AuditChecklist::addFreeLine($audit, 'P-1', 'Procédure de départ', 'Comptes fermés ?');
        $lines = $this->linesByCode($audit);
        AuditChecklist::updateLine($audit, (int) $lines['A.5.15']['id'], [
            'question' => 'Comment les droits sont-ils revus ?', 'result' => Rules::RESULT_COMPLIANT, 'comment' => 'RAS',
        ]);

        $this->assertSame(0, AuditChecklistTemplate::saveFromAudit($audit, '  '), 'nom exigé');
        $templateId = AuditChecklistTemplate::saveFromAudit($audit, 'Audit annuel du contrôle d\'accès');
        $this->assertGreaterThan(0, $templateId);
        $this->assertSame(0, AuditChecklistTemplate::saveFromAudit($audit, 'Audit annuel du contrôle d\'accès'), 'doublon');
        $this->assertArrayHasKey($templateId, AuditChecklistTemplate::available());

        $next = $this->audit('Audit interne 2028, société Exemple');
        $this->assertSame(['added' => 3, 'skipped' => 0], AuditChecklistTemplate::applyToAudit($templateId, $next));
        $copied = $this->linesByCode($next);
        $this->assertSame('Comment les droits sont-ils revus ?', $copied['A.5.15']['question']);
        $this->assertSame(Rules::RESULT_NOT_AUDITED, $copied['A.5.15']['result'], 'jamais les réponses');
        $this->assertSame('', (string) $copied['A.5.15']['comment']);
        $this->assertSame('Procédure de départ', $copied['P-1']['label']);
        $this->assertSame(['added' => 0, 'skipped' => 3], AuditChecklistTemplate::applyToAudit($templateId, $next));

        $this->assertTrue(AuditChecklistTemplate::delete($templateId));
        $this->assertCount(3, AuditChecklist::getLines($next->getID()), 'les grilles construites restent');
    }

    public function testCoverageShowsTheLastAuditAndNeverAuditedRequirementsFirst(): void
    {
        $audit = $this->audit('Audit interne 2030, société Exemple', ['actual_date' => '2030-12-01']);
        AuditChecklist::addRequirements($audit, ['control:A.5.15', 'control:A.8.13']);
        $lines = $this->linesByCode($audit);
        AuditChecklist::updateLine($audit, (int) $lines['A.5.15']['id'], ['result' => Rules::RESULT_OBSERVATION]);
        // Audit annulé : ses résultats ne comptent pas.
        $cancelled = $this->audit('Audit annulé fictif', ['actual_date' => '2030-12-20']);
        AuditChecklist::addRequirements($cancelled, ['control:A.8.13']);
        AuditChecklist::updateLine(
            $cancelled,
            (int) $this->linesByCode($cancelled)['A.8.13']['id'],
            ['result' => Rules::RESULT_COMPLIANT]
        );
        $cancelled->update(['id' => $cancelled->getID(), 'status' => 'cancelled']);

        $coverage = AuditChecklist::coverage(3, '2031-01-15');
        $rows = array_column($coverage['rows'], null, 'key');
        $this->assertTrue($rows['control:A.5.15']['audited']);
        $this->assertSame('2030-12-01', $rows['control:A.5.15']['last_date']);
        $this->assertSame(Rules::RESULT_OBSERVATION, $rows['control:A.5.15']['last_result']);
        $this->assertSame($audit->getID(), $rows['control:A.5.15']['audit_id']);
        $this->assertFalse($rows['control:A.8.13']['audited'], 'non audité et audit annulé ignorés');
        $this->assertArrayHasKey('clause:9.2.2', $rows);
        $this->assertSame($coverage['total'] - $coverage['covered'], $coverage['never']);
        $first = $coverage['rows'][0];
        $this->assertTrue($coverage['never'] === 0 || !$first['audited'], 'jamais auditées en tête');

        // Hors période : plus couverte.
        $later = array_column(AuditChecklist::coverage(1, '2032-06-01')['rows'], null, 'key');
        $this->assertFalse($later['control:A.5.15']['audited']);
    }

    public function testReportAndPurge(): void
    {
        $audit = $this->audit();
        AuditChecklist::addRequirements($audit, ['control:A.5.15']);
        AuditChecklist::updateLine($audit, (int) $this->linesByCode($audit)['A.5.15']['id'], [
            'question' => 'Question fictive de la grille',
            'result'   => Rules::RESULT_COMPLIANT,
        ]);
        $html = Audit::renderReport($audit);
        $this->assertStringContainsString('Question fictive de la grille', $html);
        $this->assertStringContainsString('A.5.15', $html);

        $auditId = $audit->getID();
        $audit->delete(['id' => $auditId], true);
        $this->assertSame([], AuditChecklist::getLines($auditId));
    }
}
