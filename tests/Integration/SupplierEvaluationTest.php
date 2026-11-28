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
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\SupplierEvaluation;
use GlpiPlugin\Grcmanager\SupplierEvaluationAnswer;
use GlpiPlugin\Grcmanager\SupplierQuestionnaireQuestion;
use GlpiPlugin\Grcmanager\SupplierQuestionnaireTemplate;
use GlpiPlugin\Grcmanager\SupplierRisk;
use Supplier;

/**
 * Issue #864 : questionnaires fournisseurs configurables (modèles pondérés, pièce justificative par
 * question, plusieurs évaluations par fournisseur) et reprise des questionnaires fixes de l'issue #127.
 */
final class SupplierEvaluationTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    /** @var list<string> */
    private array $files = [];

    /** @var list<int> */
    private array $documents = [];

    private int $maxSize = 0;

    protected function setUp(): void
    {
        global $CFG_GLPI;

        parent::setUp();
        $this->session = $_SESSION;
        $this->maxSize = (int) $CFG_GLPI['document_max_size'];
        EntityPerimeter::reset();
    }

    protected function tearDown(): void
    {
        global $CFG_GLPI;

        $_SESSION = $this->session;
        $CFG_GLPI['document_max_size'] = $this->maxSize;
        // Le fichier rangé par GLPI survivrait au retour en arrière de la base.
        foreach ($this->documents as $documentsId) {
            (new Document())->delete(['id' => $documentsId], true);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        EntityPerimeter::reset();
        parent::tearDown();
    }

    private function risk(int $entityId = 0, string $title = 'PHPUnit hosting of the data'): SupplierRisk
    {
        $suppliersId = (int) (new Supplier())->add(['name' => 'PHPUnit Hosting provider', 'entities_id' => 0]);
        $risk = new SupplierRisk();
        $id = (int) $risk->add([
            'suppliers_id' => $suppliersId,
            'title'        => $title,
            'entities_id'  => $entityId,
            'users_id'     => (int) \Session::getLoginUserID(),
        ]);
        $this->assertGreaterThan(0, $id);
        $risk->getFromDB($id);

        return $risk;
    }

    /**
     * @param list<array<string, mixed>> $questions
     */
    private function template(array $questions, array $input = []): SupplierQuestionnaireTemplate
    {
        $template = new SupplierQuestionnaireTemplate();
        $id = (int) $template->add($input + ['name' => 'PHPUnit hosting security', 'domain' => 'security']);
        $this->assertGreaterThan(0, $id);
        $template->getFromDB($id);
        SupplierQuestionnaireQuestion::saveQuestions($template, [], $questions);
        $template->getFromDB($id);

        return $template;
    }

    private function evaluation(SupplierRisk $risk, SupplierQuestionnaireTemplate $template): SupplierEvaluation
    {
        $evaluation = new SupplierEvaluation();
        $id = (int) $evaluation->add([
            Tables::RISK_FK     => $risk->getID(),
            Tables::TEMPLATE_FK => $template->getID(),
        ]);
        $this->assertGreaterThan(0, $id);
        $evaluation->getFromDB($id);

        return $evaluation;
    }

    /**
     * @return array<string, int> code => id de la réponse
     */
    private function answerIds(SupplierEvaluation $evaluation): array
    {
        $ids = [];
        foreach (SupplierEvaluationAnswer::getAnswers($evaluation->getID()) as $row) {
            $ids[(string) $row['code']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function threeQuestions(): array
    {
        return [
            [
                'section' => 'Data', 'question' => 'Is our data encrypted at rest?', 'weight' => 3,
                'requires_evidence' => 1,
            ],
            [
                'section' => 'Data', 'question' => 'Breach in the last 12 months?', 'expected_answer' => 'no',
                'weight' => 1,
            ],
            ['section' => 'Access', 'question' => 'Is MFA required?', 'weight' => 2],
            ['question' => ''],
        ];
    }

    public function testATemplateKeepsItsQuestionsAndItsVersionIncreasesOnChange(): void
    {
        $template = $this->template($this->threeQuestions());

        $questions = SupplierQuestionnaireQuestion::getQuestions($template->getID());
        $this->assertCount(3, $questions, 'une ligne vide n\'ajoute rien');
        $this->assertSame(['Q01', 'Q02', 'Q03'], array_column($questions, 'code'));
        $this->assertSame('no', $questions[1]['expected_answer']);
        $this->assertSame(1, (int) $questions[0]['requires_evidence']);
        $this->assertSame(2, (int) $template->fields['version']);
        $fingerprint = $template->fingerprint();

        $result = SupplierQuestionnaireQuestion::saveQuestions($template, [
            (int) $questions[0]['id'] => ['weight' => 99] + $questions[0],
            (int) $questions[2]['id'] => ['delete' => 1],
        ], []);
        $template->getFromDB($template->getID());

        $this->assertSame(['changed' => 1, 'added' => 0, 'deleted' => 1], $result);
        $this->assertSame(10, (int) SupplierQuestionnaireQuestion::getQuestions($template->getID())[0]['weight']);
        $this->assertSame(3, (int) $template->fields['version']);
        $this->assertNotSame($fingerprint, $template->fingerprint());

        $unchanged = SupplierQuestionnaireQuestion::saveQuestions($template, [], []);
        $template->getFromDB($template->getID());
        $this->assertSame(0, array_sum($unchanged));
        $this->assertSame(3, (int) $template->fields['version'], 'aucune nouvelle version sans changement');
    }

    public function testAnEvaluationCopiesTheQuestionsAndComputesAWeightedScore(): void
    {
        $risk = $this->risk();
        $level = $risk->fields['risk_level'];
        $template = $this->template($this->threeQuestions());
        $evaluation = $this->evaluation($risk, $template);

        $this->assertSame('in_progress', $evaluation->fields['status']);
        $this->assertSame((int) $risk->fields['suppliers_id'], (int) $evaluation->fields['suppliers_id']);
        $this->assertSame((int) $template->fields['version'], (int) $evaluation->fields['template_version']);
        $ids = $this->answerIds($evaluation);
        $this->assertSame(['Q01', 'Q02', 'Q03'], array_keys($ids));

        // Le modèle change ensuite : l'évaluation garde ses questions.
        $first = SupplierQuestionnaireQuestion::getQuestions($template->getID())[0];
        SupplierQuestionnaireQuestion::saveQuestions($template, [
            (int) $first['id'] => ['question' => 'Changed question'] + $first,
        ], []);
        $copied = SupplierEvaluationAnswer::getAnswers($evaluation->getID())[0];
        $this->assertSame('Is our data encrypted at rest?', $copied['question']);

        SupplierEvaluationAnswer::saveAnswers($evaluation, [
            $ids['Q01'] => 'yes',
            $ids['Q02'] => 'yes',
            $ids['Q03'] => 'partial',
            999999      => 'yes',
        ], [$ids['Q02'] => 'Phishing incident in March, fixed.']);
        $evaluation->getFromDB($evaluation->getID());

        // (3 x 1 + 1 x 0 + 2 x 0,5) / 6 = 66,7 % -> 67.
        $this->assertSame(67, (int) $evaluation->fields['score']);
        $answers = SupplierEvaluationAnswer::getAnswers($evaluation->getID());
        $this->assertSame('Phishing incident in March, fixed.', $answers[1]['answer_comment']);
        $this->assertSame((int) \Session::getLoginUserID(), (int) $answers[0]['users_id']);

        $risk->getFromDB($risk->getID());
        $this->assertSame($level, $risk->fields['risk_level'], 'niveau de risque inchangé');
    }

    /**
     * Issue #1159 : échelle de conformité par question, copiée dans l'évaluation, notée 1 / 0,5 / 0,
     * « Non applicable » exclu ; les questions Oui / Non gardent leur type par défaut.
     */
    public function testAComplianceScaleQuestionIsCopiedAndScored(): void
    {
        $template = $this->template([
            ['question' => 'Is a security policy approved?', 'answer_type' => 'compliance', 'expected_answer' => 'no',
                'weight' => 2],
            ['question' => 'Are backups tested?', 'answer_type' => 'compliance', 'weight' => 2],
            ['question' => 'Is there a data centre in the EU?', 'answer_type' => 'compliance', 'weight' => 5],
            ['question' => 'Is MFA required?', 'weight' => 1],
        ]);
        $questions = SupplierQuestionnaireQuestion::getQuestions($template->getID());
        $types = ['compliance', 'compliance', 'compliance', 'yes_no'];
        $this->assertSame($types, array_column($questions, 'answer_type'));
        $this->assertSame('yes', $questions[0]['expected_answer'], 'échelle : réponse attendue « Conforme »');

        $evaluation = $this->evaluation($this->risk(), $template);
        $answers = SupplierEvaluationAnswer::getAnswers($evaluation->getID());
        $this->assertSame($types, array_column($answers, 'answer_type'));
        $ids = $this->answerIds($evaluation);
        SupplierEvaluationAnswer::saveAnswers($evaluation, [
            $ids['Q01'] => 'yes',
            $ids['Q02'] => 'partial',
            $ids['Q03'] => 'na',
            $ids['Q04'] => 'no',
        ], []);
        $evaluation->getFromDB($evaluation->getID());

        // (2 x 1 + 2 x 0,5 + 1 x 0) / 5 = 60 %, la question non applicable (poids 5) ne compte pas.
        $this->assertSame(60, (int) $evaluation->fields['score']);
    }

    public function testCompletingFreezesTheAnswersAndFeedsTheScoreOfTheRisk(): void
    {
        $risk = $this->risk();
        $template = $this->template($this->threeQuestions());
        $evaluation = $this->evaluation($risk, $template);
        $ids = $this->answerIds($evaluation);
        SupplierEvaluationAnswer::saveAnswers($evaluation, [$ids['Q01'] => 'yes', $ids['Q02'] => 'no'], []);
        $evaluation->getFromDB($evaluation->getID());

        $this->assertTrue($evaluation->complete());
        $this->assertSame('completed', $evaluation->fields['status']);
        $this->assertNotEmpty($evaluation->fields['date_completed']);
        $risk->getFromDB($risk->getID());
        $this->assertSame(100, (int) $risk->fields['questionnaire_score'], 'score repris par le tableau de bord');

        $this->assertSame(-1, SupplierEvaluationAnswer::saveAnswers($evaluation, [$ids['Q01'] => 'no'], []));
        $this->assertFalse($evaluation->update(['id' => $evaluation->getID(), 'name' => 'Changed']));
        $this->assertSame('yes', SupplierEvaluationAnswer::getAnswers($evaluation->getID())[0]['answer']);

        $this->assertTrue($evaluation->reopen());
        $this->assertSame('in_progress', $evaluation->fields['status']);
        $this->assertSame(1, SupplierEvaluationAnswer::saveAnswers($evaluation, [$ids['Q01'] => 'no'], []));
    }

    public function testASupplierHasSeveralEvaluationsWithTheirOwnScore(): void
    {
        $risk = $this->risk();
        $security = $this->template($this->threeQuestions());
        $privacy = $this->template(
            [['question' => 'Is a processing agreement (GDPR art. 28) signed?', 'weight' => 5]],
            ['name' => 'PHPUnit personal data', 'domain' => 'privacy']
        );
        $first = $this->evaluation($risk, $security);
        $second = $this->evaluation($risk, $privacy);
        $ids = $this->answerIds($second);
        SupplierEvaluationAnswer::saveAnswers($second, [$ids['Q01'] => 'no'], []);
        $second->getFromDB($second->getID());
        $second->complete();

        $rows = SupplierEvaluation::forRisk($risk->getID());
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['security', 'privacy'], array_column($rows, 'domain'));
        $this->assertNull($first->fields['score']);
        $risk->getFromDB($risk->getID());
        $this->assertNull(
            $risk->fields['questionnaire_score'],
            'seul le domaine « sécurité » alimente le score du risque'
        );
    }

    public function testOnlyTemplatesOfTheEntityOfTheRiskAreOffered(): void
    {
        $north = $this->createTestEntity(0, 'PHPUnit North subsidiary');
        $south = $this->createTestEntity(0, 'PHPUnit South subsidiary');
        EntityPerimeter::reset();
        $shared = $this->template(
            [['question' => 'Shared question?']],
            ['name' => 'PHPUnit shared', 'is_recursive' => 1]
        );
        $southOnly = $this->template(
            [['question' => 'South question?']],
            ['name' => 'PHPUnit south only', 'entities_id' => $south]
        );
        $inactive = $this->template(
            [['question' => 'Old question?']],
            ['name' => 'PHPUnit inactive', 'is_active' => 0]
        );
        $risk = $this->risk($north, 'PHPUnit north hosting');

        $available = SupplierQuestionnaireTemplate::availableFor($north);
        $this->assertArrayHasKey($shared->getID(), $available);
        $this->assertArrayNotHasKey($southOnly->getID(), $available);
        $this->assertArrayNotHasKey($inactive->getID(), $available);

        $refused = (new SupplierEvaluation())->add([
            Tables::RISK_FK     => $risk->getID(),
            Tables::TEMPLATE_FK => $southOnly->getID(),
        ]);
        $this->assertFalse($refused, 'modèle d\'une autre entité refusé');
        $evaluation = $this->evaluation($risk, $shared);
        $this->assertSame($north, (int) $evaluation->fields['entities_id'], 'entité du risque');

        // Profil limité à la filiale Sud : ni le risque, ni l'évaluation de la filiale Nord.
        $_SESSION['glpiactive_entity'] = $south;
        $_SESSION['glpiactiveentities'] = [$south];
        $_SESSION['glpiactiveentities_string'] = (string) $south;
        $_SESSION['glpiparententities'] = [0];
        EntityPerimeter::reset();
        $this->assertFalse((bool) (new SupplierEvaluation())->can($evaluation->getID(), READ));
        $this->assertFalse((bool) (new SupplierEvaluation())->can($evaluation->getID(), UPDATE));
    }

    public function testTheLegacyQuestionnaireIsTakenOverOnceWithoutLoss(): void
    {
        global $DB;

        $risk = $this->risk();
        $DB->update(SupplierRisk::getTable(), [
            'questionnaire_answers'  => json_encode(['certification' => 'yes', 'mfa' => 'no', 'audits' => 'na']),
            'questionnaire_score'    => 50,
            'questionnaire_date'     => '2026-03-02 10:00:00',
            'questionnaire_users_id' => 2,
        ], ['id' => $risk->getID()]);

        $this->assertGreaterThanOrEqual(1, Tables::migrateLegacy());
        $this->assertSame(0, Tables::migrateLegacy(), 'rejouable : rien de repris deux fois');

        $rows = SupplierEvaluation::forRisk($risk->getID());
        $this->assertCount(1, $rows);
        $this->assertSame('legacy', $rows[0]['source']);
        $this->assertSame('completed', $rows[0]['status']);
        $this->assertSame(50, (int) $rows[0]['score'], 'score d\'origine conservé');
        $this->assertSame('2026-03-02', $rows[0]['evaluation_date']);
        $this->assertSame(2, (int) $rows[0]['users_id']);
        $answers = [];
        foreach (SupplierEvaluationAnswer::getAnswers((int) $rows[0]['id']) as $answer) {
            $answers[(string) $answer['code']] = (string) $answer['answer'];
        }
        $this->assertCount(12, $answers);
        $this->assertSame('yes', $answers['certification']);
        $this->assertSame('no', $answers['mfa']);
        $this->assertSame('na', $answers['audits']);
        $this->assertSame('', $answers['encryption']);

        $legacy = new SupplierQuestionnaireTemplate();
        $this->assertTrue($legacy->getFromDB((int) $rows[0][Tables::TEMPLATE_FK]));
        $this->assertSame(1, (int) $legacy->fields['is_legacy']);
        $this->assertFalse($legacy->canPurgeItem(), 'le modèle historique ne se supprime pas');
        $risk->getFromDB($risk->getID());
        $this->assertNotEmpty($risk->fields['questionnaire_answers'], 'colonnes d\'origine conservées');
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'grc');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    public function testEvidenceIsAControlledDocumentOfTheSupplierRisk(): void
    {
        global $CFG_GLPI;

        $risk = $this->risk();
        $evaluation = $this->evaluation($risk, $this->template($this->threeQuestions()));
        $ids = $this->answerIds($evaluation);
        $pdf = $this->file("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        $documentsId = SupplierEvaluationAnswer::attachEvidence(
            $evaluation,
            $ids['Q01'],
            $pdf,
            '../ISO 27001 certificate.pdf'
        );
        $this->assertGreaterThan(0, $documentsId);
        $this->documents[] = $documentsId;
        $document = new Document();
        $this->assertTrue($document->getFromDB($documentsId));
        $this->assertSame('ISO 27001 certificate.pdf', $document->fields['filename'], 'aucun chemin conservé');
        $this->assertSame((int) $risk->fields['entities_id'], (int) $document->fields['entities_id']);
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documentsId,
            'itemtype'     => SupplierRisk::class,
            'items_id'     => $risk->getID(),
        ]), 'pièce dans l\'onglet Documents du risque');
        $this->assertSame(
            ['itemtype' => SupplierRisk::class, 'items_id' => $risk->getID()],
            RestrictedDocument::sourceOf($documentsId),
            'lisible avec les droits du risque fournisseur'
        );
        $answers = SupplierEvaluationAnswer::getAnswers($evaluation->getID());
        $this->assertSame($documentsId, (int) $answers[0]['documents_id']);

        // Faux PDF (du PHP), type interdit, fichier trop gros, question d'une autre évaluation : refusés.
        $this->assertSame(0, SupplierEvaluationAnswer::attachEvidence(
            $evaluation,
            $ids['Q02'],
            $this->file("<?php echo 'x';\n"),
            'report.pdf'
        ));
        $this->assertSame(0, SupplierEvaluationAnswer::attachEvidence(
            $evaluation,
            $ids['Q02'],
            $this->file('echo x'),
            'script.sh'
        ));
        $CFG_GLPI['document_max_size'] = 1;
        $this->assertSame(0, SupplierEvaluationAnswer::attachEvidence(
            $evaluation,
            $ids['Q02'],
            $this->file('%PDF-1.4' . str_repeat('x', 2 * 1024 * 1024)),
            'big.pdf'
        ));
        $CFG_GLPI['document_max_size'] = $this->maxSize;
        $other = $this->evaluation($this->risk(0, 'PHPUnit other supplier'), $this->template($this->threeQuestions()));
        $this->assertSame(0, SupplierEvaluationAnswer::attachEvidence($other, $ids['Q02'], $pdf, 'c.pdf'));
        $this->assertSame(0, (int) SupplierEvaluationAnswer::getAnswers($evaluation->getID())[1]['documents_id']);

        // Retirer la pièce d'une question : le document reste dans l'onglet Documents du risque.
        SupplierEvaluationAnswer::saveAnswers($evaluation, [], [], [$ids['Q01'] => 1]);
        $this->assertSame(0, (int) SupplierEvaluationAnswer::getAnswers($evaluation->getID())[0]['documents_id']);
        $this->assertTrue((new Document())->getFromDB($documentsId));
        unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
    }

    public function testPurgingATemplateKeepsItsEvaluations(): void
    {
        $risk = $this->risk();
        $template = $this->template($this->threeQuestions());
        $evaluation = $this->evaluation($risk, $template);

        $this->assertTrue($template->delete(['id' => $template->getID()], true));

        $evaluation->getFromDB($evaluation->getID());
        $this->assertSame(0, (int) $evaluation->fields[Tables::TEMPLATE_FK]);
        $this->assertSame('PHPUnit hosting security', $evaluation->fields['template_name']);
        $this->assertCount(3, SupplierEvaluationAnswer::getAnswers($evaluation->getID()));

        $this->assertTrue($evaluation->delete(['id' => $evaluation->getID()], true));
        $this->assertSame([], SupplierEvaluationAnswer::getAnswers($evaluation->getID()));
    }
}
