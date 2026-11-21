<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Install\PolicyCoverageTables;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyCoverage;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyCoverageRules as Rules;

/**
 * Issue #879 : sections de politiques reliées aux mesures de la SoA, sur une vraie instance :
 * liaison en masse, état de chaque mesure, rapport, contrôle de cohérence, droits.
 * Indépendant de la langue de l'instance : aucune comparaison de texte traduit.
 */
final class PolicyCoverageTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return array<string, int>
     */
    private function seed(): array
    {
        global $DB;

        $DB->delete(PolicyCoverageTables::LINKS, ['id' => ['>', 0]]);
        $codes = EntityPerimeter::controlIdsByCode();
        foreach (['A.5.1', 'A.5.15', 'A.5.16', 'A.8.5'] as $code) {
            $DB->update(Control::getTable(), ['applicability' => 'yes'], ['id' => $codes[$code]]);
        }
        $DB->update(Control::getTable(), ['applicability' => 'no', 'justification' => 'Hors périmètre fictif'], [
            'id' => $codes['A.7.1'],
        ]);
        $DB->insert(Policy::getTable(), [
            'title' => 'Politique fictive approuvée 879', 'status' => 'approved', 'users_id' => 2,
            'approval_date' => date('Y-m-d'), 'next_review_date' => date('Y-m-d', strtotime('+1 year')),
        ]);
        $approved = (int) $DB->insertId();
        $DB->insert(Policy::getTable(), [
            'title' => 'Politique fictive à réviser 879', 'status' => 'approved', 'users_id' => 2,
            'approval_date' => date('Y-m-d', strtotime('-2 years')),
            'next_review_date' => date('Y-m-d', strtotime('-1 day')),
        ]);
        $overdue = (int) $DB->insertId();
        $DB->insert(Policy::getTable(), [
            'title' => 'Politique fictive brouillon 879', 'status' => 'draft', 'users_id' => 2,
        ]);
        $draft = (int) $DB->insertId();

        return ['approved' => $approved, 'overdue' => $overdue, 'draft' => $draft] + [
            'a51' => $codes['A.5.1'], 'a515' => $codes['A.5.15'], 'a516' => $codes['A.5.16'],
            'a85' => $codes['A.8.5'], 'a71' => $codes['A.7.1'],
        ];
    }

    public function testBulkLinkingByCodesAndListWithoutDuplicates(): void
    {
        $ids = $this->seed();
        $resolved = PolicyCoverage::resolveControls([$ids['a51'], 999999], 'A.5.15-A.5.16, A.99.1');
        $this->assertEqualsCanonicalizing([$ids['a51'], $ids['a515'], $ids['a516']], $resolved['ids']);
        $this->assertSame(['A.99.1'], $resolved['unknown']);

        $this->assertSame(3, PolicyCoverage::addLinks([$ids['approved']], $resolved['ids'], '4.2 Access'));
        $again = PolicyCoverage::addLinks([$ids['approved']], $resolved['ids'], '4.2 Access');
        $this->assertSame(0, $again, 'pas de doublon');
        $this->assertSame(1, PolicyCoverage::addLinks([$ids['approved']], [$ids['a51']], ''), 'texte entier en plus');

        $links = PolicyCoverage::policyLinks($ids['approved']);
        $this->assertCount(4, $links);
        $this->assertSame(['', '4.2 Access'], array_values(array_unique(array_column($links, 'section'))));
    }

    public function testControlStatusAndReport(): void
    {
        $ids = $this->seed();
        PolicyCoverage::addLinks([$ids['approved']], [$ids['a51']], '');
        PolicyCoverage::addLinks([$ids['overdue']], [$ids['a515']], '');
        PolicyCoverage::addLinks([$ids['draft']], [$ids['a516']], '2.1');
        PolicyCoverage::addLinks([$ids['draft']], [$ids['a71']], '');

        $collected = PolicyCoverage::collect();
        $this->assertSame(Rules::STATUS_COVERED, $collected[$ids['a51']]['status']);
        $this->assertSame(Rules::STATUS_REVIEW_DUE, $collected[$ids['a515']]['status']);
        $this->assertSame(Rules::STATUS_NOT_APPROVED, $collected[$ids['a516']]['status']);
        $this->assertSame(Rules::STATUS_UNCOVERED, $collected[$ids['a85']]['status']);
        $this->assertNull($collected[$ids['a71']]['status'], 'mesure non applicable hors rapport');

        $report = PolicyCoverage::report(Rules::FILTER_GAPS);
        $codes = array_column($report['rows'], 'code');
        $this->assertNotContains('A.5.1', $codes);
        $this->assertNotContains('A.7.1', $codes);
        $this->assertContains('A.5.15', $codes);
        $this->assertContains('A.8.5', $codes);
        $this->assertTrue($report['linked']);
        $this->assertContains('A.5.1', array_column(PolicyCoverage::report(Rules::FILTER_ALL)['rows'], 'code'));

        $csv = PolicyCoverage::csvRows($report);
        $this->assertCount(7, $csv[0]);
        $this->assertGreaterThan(count($report['rows']), count($csv), 'en-tête en plus');
        $this->assertStringStartsWith('%PDF', \GlpiPlugin\Grcmanager\Pdf\PdfRenderer::renderHtmlToPdf(
            PolicyCoverage::renderPdf($report)
        ));
    }

    public function testConsistencyRuleIsEvaluatedOnceTextsAreLinked(): void
    {
        global $DB;

        $ids = $this->seed();
        $results = ConsistencyCheck::run(null, ['control_without_approved_policy']);
        $this->assertFalse($results['control_without_approved_policy']['evaluated'], 'aucun lien : non évaluée');

        PolicyCoverage::addLinks([$ids['approved']], [$ids['a51']], '');
        $results = ConsistencyCheck::run(null, ['control_without_approved_policy']);
        $rule = $results['control_without_approved_policy'];
        $this->assertTrue($rule['evaluated']);
        $gapIds = array_map('intval', array_column($rule['gaps'], 'id'));
        $this->assertContains($ids['a85'], $gapIds);
        $this->assertNotContains($ids['a51'], $gapIds);
        $this->assertSame('A.5.1', $rule['clause']);

        $before = $DB->request(['FROM' => Policy::getTable(), 'WHERE' => ['id' => $ids['draft']]])->current();
        PolicyCoverage::collect();
        $after = $DB->request(['FROM' => Policy::getTable(), 'WHERE' => ['id' => $ids['draft']]])->current();
        $this->assertSame($before, $after, 'lecture seule');
    }

    public function testLinkingAndRemovingNeedTheRight(): void
    {
        global $DB;

        $ids = $this->seed();
        PolicyCoverage::addLinks([$ids['approved']], [$ids['a51']], '');
        $linkId = (int) $DB->request(['FROM' => PolicyCoverageTables::LINKS, 'LIMIT' => 1])->current()['id'];

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertFalse(PolicyCoverage::canEditLinks());
            $this->assertSame(0, PolicyCoverage::addLinks([$ids['approved']], [$ids['a85']], ''));
            $this->assertFalse(PolicyCoverage::removeLink($linkId));
            $this->assertNotEmpty(PolicyCoverage::collect($ids['a51'])[$ids['a51']]['texts'], 'lecture permise');
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
            $this->assertSame([], PolicyCoverage::collect($ids['a51']));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }
        $this->assertTrue(PolicyCoverage::removeLink($linkId));
        $this->assertSame([], PolicyCoverage::policyLinks($ids['approved']));
    }
}
