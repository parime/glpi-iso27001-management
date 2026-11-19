<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\ManagementReviewAction;
use GlpiPlugin\Grcmanager\ManagementReviewDossier;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Review\ManagementReviewDossierRules;

/**
 * Issue #542 : dossier préparatoire de la revue de direction (9.3.2) et actions décidées.
 */
final class ManagementReviewDossierTest extends GrcmanagerIntegrationTestCase
{
    private function createReview(string $title, string $date, string $decisions = ''): ManagementReview
    {
        $review = new ManagementReview();
        $id = (int) $review->add([
            'title'       => $title,
            'status'      => 'planned',
            'review_date' => $date,
            'decisions'   => $decisions,
        ]);
        $this->assertGreaterThan(0, $id);
        $review->getFromDB($id);

        return $review;
    }

    public function testDecisionsBecomeTrackedActionsOnlyOnce(): void
    {
        $review = $this->createReview(
            'Revue fictive 2026',
            '2026-11-25',
            "- Renouveler la sensibilisation au hameçonnage\n- Nommer un suppléant au RSSI\n"
        );

        $this->assertSame(2, ManagementReviewAction::createFromDecisions($review));
        $this->assertSame(0, ManagementReviewAction::createFromDecisions($review), 'Pas de doublon.');

        $actions = ManagementReviewAction::getForReview($review->getID());
        $this->assertCount(2, $actions);
        foreach ($actions as $action) {
            // Rien n'est affecté ni planifié à la place de l'utilisateur.
            $this->assertSame('planned', $action['status']);
            $this->assertSame(0, $action['users_id']);
            $this->assertNull($action['due_date']);
        }

        // Suivi : passage à « Fait », date de réalisation posée ; retour en cours, date retirée.
        $item = new ManagementReviewAction();
        $this->assertTrue($item->update(['id' => $actions[0]['id'], 'status' => 'done']));
        $item->getFromDB($actions[0]['id']);
        $this->assertSame(date('Y-m-d'), $item->fields['completion_date']);
        $this->assertTrue($item->update(['id' => $actions[0]['id'], 'status' => 'in_progress']));
        $item->getFromDB($actions[0]['id']);
        $this->assertNull($item->fields['completion_date']);

        // Une action sans description ou sans revue est refusée.
        $this->assertFalse((new ManagementReviewAction())->add([
            'plugin_grcmanager_managementreviews_id' => $review->getID(),
            'description'                            => '   ',
        ]));
        $this->assertFalse((new ManagementReviewAction())->add([
            'plugin_grcmanager_managementreviews_id' => 999999999,
            'description'                            => 'Action orpheline',
        ]));

        // La purge de la revue emporte ses actions.
        $this->assertTrue($review->delete(['id' => $review->getID()], true));
        $this->assertSame(0, countElementsInTable(ManagementReviewAction::getTable(), [
            'plugin_grcmanager_managementreviews_id' => $review->getID(),
        ]));
    }

    public function testDossierIsArchivedOnTheReviewWithItsFingerprint(): void
    {
        global $DB;

        $previous = $this->createReview('Revue fictive 2025', '2025-11-20');
        $late = (int) (new ManagementReviewAction())->add([
            'plugin_grcmanager_managementreviews_id' => $previous->getID(),
            'description'                            => 'Action fictive en retard',
            'due_date'                               => '2026-01-31',
        ]);
        $this->assertGreaterThan(0, $late);
        $review = $this->createReview('Revue fictive 2026', '2026-11-25');
        $this->assertSame('2025-11-20', $review->previousReviewDate());

        $finding = (int) (new Nonconformity())->add([
            'title'        => 'Constat fictif de la période',
            'finding_type' => 'nonconformity',
            'severity'     => 'minor',
        ]);
        $this->assertGreaterThan(0, $finding);

        $data = ManagementReviewDossier::collect($review, '2025-11-20', '2026-11-25');
        $sections = array_column($data['sections'], null, 'key');
        $this->assertSame(array_keys(ManagementReviewDossierRules::SECTIONS), array_keys($sections));
        foreach ($sections as $key => $section) {
            $this->assertTrue($section['included'], "Section $key incluse pour un super-administrateur.");
        }
        $previousActions = $sections['previous_actions']['data'];
        // L'instance de test peut contenir d'autres revues : on retrouve l'action, signalée en retard.
        $this->assertGreaterThanOrEqual(1, $previousActions['summary']['overdue']);
        $listed = array_column($previousActions['actions'], 'overdue', 'description');
        $this->assertArrayHasKey('Action fictive en retard', $listed);
        $this->assertTrue($listed['Action fictive en retard']);
        $raised = array_column($sections['nonconformities']['data']['raised'], 'number', 'label');
        $this->assertGreaterThanOrEqual(1, $raised[Nonconformity::getFindingTypes()['nonconformity']]);

        $dossierId = ManagementReviewDossier::generate($review, '2025-11-20', '2026-11-25');
        $this->assertGreaterThan(0, $dossierId);
        $row = $DB->request(['FROM' => ManagementReviewDossier::TABLE, 'WHERE' => ['id' => $dossierId]])->current();
        $documentsId = (int) $row['documents_id'];
        try {
            $this->assertSame('2025-11-20', $row['period_start']);
            $this->assertSame('2026-11-25', $row['period_end']);
            $document = new \Document();
            $this->assertTrue($document->getFromDB($documentsId));
            $this->assertSame('application/pdf', $document->fields['mime']);
            $this->assertSame(
                hash_file('sha256', GLPI_DOC_DIR . '/' . $document->fields['filepath']),
                $row['sha256'],
                'L\'empreinte enregistrée est celle du fichier archivé.'
            );
            $this->assertSame(1, countElementsInTable('glpi_documents_items', [
                'documents_id' => $documentsId,
                'itemtype'     => ManagementReview::class,
                'items_id'     => $review->getID(),
            ]));
            $this->assertSame(
                ['itemtype' => ManagementReview::class, 'items_id' => $review->getID()],
                RestrictedDocument::sourceOf($documentsId)
            );
            $history = ManagementReviewDossier::history($review->getID());
            $this->assertCount(1, $history);
            $this->assertSame($row['sha256'], $history[0]['sha256']);
            $this->assertNotSame('', $history[0]['url']);

            // Période incohérente : rien n'est généré.
            $this->assertSame(0, ManagementReviewDossier::generate($review, '2026-11-25', '2025-11-20'));
        } finally {
            (new \Document())->delete(['id' => $documentsId], true);
        }
    }

    public function testSectionsWithoutRightAreNotFilled(): void
    {
        $review = $this->createReview('Revue fictive droits', '2026-11-25');
        $saved = $_SESSION['glpiactiveprofile'];
        try {
            // Droit général du SMSI sans les registres sensibles (risques, KRI) ni les incidents.
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_securityincident'] = 0;
            $sections = array_column(
                ManagementReviewDossier::collect($review, '2025-11-25', '2026-11-25')['sections'],
                null,
                'key'
            );
            $this->assertFalse($sections['risks']['included']);
            $this->assertSame([], $sections['risks']['data']);
            $this->assertNull($sections['nonconformities']['data']['trend'], 'Tendance tirée du registre des risques.');
            $this->assertTrue($sections['objectives']['included']);
            $html = ManagementReviewDossier::render($review, '2025-11-25', '2026-11-25');
            $this->assertStringContainsString(
                htmlspecialchars(__('Not included: the person who generated this file cannot read this register.', 'grcmanager'), ENT_QUOTES),
                $html
            );
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
    }
}
