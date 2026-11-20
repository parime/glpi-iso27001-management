<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Document_Item;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\CustomRequirement;
use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\FrameworkAssessment;
use GlpiPlugin\Grcmanager\RecyfRequirement;
use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkAssessmentRules;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkSync;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

/**
 * Issue #379 : l'évaluation par référentiel lit les documents liés aux mesures et leur validité.
 */
final class FrameworkAssessmentTest extends GrcmanagerIntegrationTestCase
{
    /**
     * Deux mesures sans aucun document, pour ne dépendre d'aucune donnée de l'instance.
     *
     * @return array<string, int>
     */
    private function controlsWithoutDocuments(): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request(['SELECT' => ['id', 'code'], 'FROM' => Control::getTable(), 'ORDER' => 'id']);
        foreach ($rows as $row) {
            $links = ['itemtype' => Control::class, 'items_id' => $row['id']];
            if (countElementsInTable(Document_Item::getTable(), $links) === 0) {
                $ids[(string) $row['code']] = (int) $row['id'];
            }
            if (count($ids) === 2) {
                break;
            }
        }
        $this->assertCount(2, $ids, 'Il faut deux mesures sans document.');

        return $ids;
    }

    private function attach(int $controlId, ?string $validUntil): void
    {
        $documentsId = (int) (new \Document())->add(['name' => 'PHPUnit preuve de mesure', 'entities_id' => 0]);
        (new Document_Item())->add([
            'documents_id' => $documentsId, 'itemtype' => Control::class, 'items_id' => $controlId,
        ]);
        if ($validUntil !== null) {
            $this->assertNotFalse((new Evidence())->add([
                'itemtype' => Control::class, 'items_id' => $controlId,
                'documents_id' => $documentsId, 'valid_until' => $validUntil,
            ]));
        }
    }

    public function testEvidenceStatusComesFromTheLinkedDocumentsAndTheirValidity(): void
    {
        $today = date('Y-m-d');
        [$expiredCode, $provenCode] = array_keys($ids = $this->controlsWithoutDocuments());
        $this->attach($ids[$expiredCode], date('Y-m-d', strtotime('-1 day')));
        $this->attach($ids[$provenCode], null);

        $controls = FrameworkAssessment::controls($today);
        $this->assertSame(FrameworkAssessmentRules::EVIDENCE_EXPIRED, $controls[$expiredCode]['evidence']);
        $this->assertSame(FrameworkAssessmentRules::EVIDENCE_PROVEN, $controls[$provenCode]['evidence']);
        $this->assertSame($ids[$provenCode], $controls[$provenCode]['id']);

        // Un document encore valide à côté de l'expiré suffit à prouver la mesure.
        $this->attach($ids[$expiredCode], date('Y-m-d', strtotime('+1 year')));
        $this->assertSame(
            FrameworkAssessmentRules::EVIDENCE_PROVEN,
            FrameworkAssessment::controls($today)[$expiredCode]['evidence']
        );
    }

    /** @var list<string> */
    private array $savedFrameworks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedFrameworks = FrameworkConfig::enabled();

        // Issue #599 : aucun registre ReCyF (annulé avec la transaction), NIS2 suit alors la
        // correspondance avec l'Annexe A ; le registre est testé dans RecyfRequirementTest.
        global $DB;
        $DB->delete(RecyfRequirement::ENTITIES_TABLE, ['id' => ['>', 0]]);
        RecyfRequirement::resetCategories();
    }

    protected function tearDown(): void
    {
        unset($_SESSION[FrameworkAssessment::SESSION_KEY]);
        FrameworkConfig::save($this->savedFrameworks);
        CustomFrameworkCatalog::reset();
        RecyfRequirement::resetCategories();

        parent::tearDown();
        FrameworkConfig::reset();
    }

    /**
     * Référentiel personnalisé fictif à la manière d'un ReCyF importé : ses exigences ne citent
     * aucune mesure de l'Annexe A, ou une seule.
     *
     * @param array<string, string> $statuses code => statut d'auto-évaluation
     */
    private function customFramework(string $name, array $statuses): string
    {
        $id = (int) (new CustomFramework())->add(['name' => $name]);
        $this->assertGreaterThan(0, $id);
        $csv = "code;theme;title;description;annex_a\n"
            . "DEMO-01;Gouvernance;Politique de sécurité approuvée;;\n"
            . "DEMO-02;Protection;Authentification renforcée;;A.8.5\n"
            . "DEMO-03;Défense;Journalisation centralisée;;\n"
            . "DEMO-04;Résilience;Plan de continuité;;\n";
        $parsed = CustomFrameworkCsv::parse($csv, CustomFramework::annexACodes());
        CustomFramework::applyPlan(
            $id,
            CustomFrameworkSync::plan(CustomFramework::getExistingForSync($id), $parsed['rows'])
        );
        $requirement = new CustomRequirement();
        foreach (CustomFramework::getRequirements($id) as $row) {
            if (isset($statuses[(string) $row['code']])) {
                $this->assertTrue($requirement->update([
                    'id' => (int) $row['id'], 'status' => $statuses[(string) $row['code']],
                ]));
            }
        }
        $key = CustomFrameworkCatalog::key($id);
        FrameworkConfig::setEnabled($key, true);
        CustomFrameworkCatalog::reset();

        return $key;
    }

    /**
     * Issue #789, bogue : un référentiel personnalisé était évalué sur les mesures de l'Annexe A que
     * ses exigences citent, et non sur ses exigences et leur auto-évaluation. Un ReCyF importé sans
     * correspondance n'avait aucune mesure applicable et affichait des mesures ISO en écart.
     */
    public function testCustomFrameworkIsAssessedOnItsOwnRequirements(): void
    {
        $key = $this->customFramework('PHPUnit ReCyF démo', [
            'DEMO-01' => CustomRequirement::STATUS_COMPLIANT,
            'DEMO-02' => CustomRequirement::STATUS_PARTIAL,
            'DEMO-04' => CustomRequirement::STATUS_NOT_APPLICABLE,
        ]);
        $assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $this->assertArrayHasKey('PHPUnit ReCyF démo', $assessments);
        $assessment = $assessments['PHPUnit ReCyF démo'];

        $this->assertSame($key, $assessment['key']);
        $this->assertSame(FrameworkAssessment::BASIS_OWN, $assessment['basis']);
        $this->assertSame(3, $assessment['applicable'], 'DEMO-04 non applicable.');
        $this->assertSame(1, $assessment['compliant']);
        $this->assertSame(50.0, $assessment['implementation_rate'], '(1 + 0,5) / 3');
        $this->assertNull($assessment['evidence_rate']);
        $codes = array_column($assessment['gaps'], 'code');
        $this->assertSame(['DEMO-02', 'DEMO-03'], $codes, 'Les écarts sont les exigences, pas des mesures ISO.');
        foreach ($codes as $code) {
            $this->assertStringStartsNotWith('A.', $code);
        }

        $detail = FrameworkAssessment::detail($assessments, $key);
        $this->assertNotNull($detail);
        $this->assertSame([], $detail['gaps'][0]['controls']);
        $this->assertStringContainsString('customframework.form.php?id=', (string) $detail['gaps'][0]['url']);
        $this->assertSame('Authentification renforcée', $detail['gaps'][0]['title']);
        $this->assertStringContainsString('customframework.form.php?id=', (string) $detail['manage_url']);
    }

    /**
     * Issue #789 : NIS2 est évalué sur ses propres exigences (articles 20, 21 et 23), leur état
     * déduit des mesures de l'Annexe A reliées, tant qu'ISO 27001 est activé.
     */
    public function testBuiltinFrameworkIsAssessedOnItsOwnCatalogThroughTheMapping(): void
    {
        FrameworkConfig::save([FrameworkSelection::ISO27001, FrameworkSelection::NIS2]);
        $assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $nis2 = $assessments['NIS2'];
        $this->assertSame(FrameworkAssessment::BASIS_MAPPED, $nis2['basis']);
        $this->assertSame(count(Nis2CatalogDefaults::REQUIREMENTS), $nis2['measures']);
        $this->assertLessThanOrEqual(count(Nis2CatalogDefaults::REQUIREMENTS), $nis2['applicable']);
        $this->assertSame(FrameworkAssessment::BASIS_OWN, $assessments['ISO 27001']['basis']);

        $detail = FrameworkAssessment::detail($assessments, FrameworkSelection::NIS2);
        $this->assertNotNull($detail);
        $this->assertStringContainsString('referentiels.php#grcmanager-nis2-body', (string) $detail['manage_url']);
        foreach ($detail['gaps'] as $gap) {
            $this->assertStringStartsWith('Art. ', (string) $gap['code'], 'Exigence NIS2, pas mesure ISO.');
            foreach ($gap['controls'] as $control) {
                $this->assertStringStartsWith('A.', (string) $control['code']);
                $this->assertStringContainsString('control.form.php?id=', (string) $control['url']);
            }
        }
    }

    /**
     * Issue #789 : ISO 27001 désactivé, l'Annexe A ne sert plus de base : un référentiel sans état
     * propre n'est pas évalué (aucun taux, aucun écart), au lieu d'afficher des mesures ISO.
     */
    public function testWithoutIso27001TheAnnexAIsNotUsed(): void
    {
        FrameworkConfig::save([FrameworkSelection::NIS2, FrameworkSelection::CIS_CONTROLS]);
        $assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $this->assertSame(['NIS2', 'CIS Controls v8'], array_keys($assessments));
        foreach ($assessments as $assessment) {
            $this->assertSame(FrameworkAssessment::BASIS_NONE, $assessment['basis']);
            $this->assertSame(0, $assessment['applicable']);
            $this->assertNull($assessment['score']);
            $this->assertNull($assessment['implementation_rate']);
            $this->assertSame([], $assessment['gaps']);
            $this->assertGreaterThan(0, $assessment['measures']);
        }

        // Un référentiel personnalisé garde son évaluation propre.
        $key = $this->customFramework(
            'PHPUnit ReCyF démo sans ISO',
            ['DEMO-01' => CustomRequirement::STATUS_COMPLIANT]
        );
        $assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $this->assertSame(FrameworkAssessment::BASIS_OWN, $assessments['PHPUnit ReCyF démo sans ISO']['basis']);
        $this->assertSame($key, $assessments['PHPUnit ReCyF démo sans ISO']['key']);
        $this->assertSame(1, $assessments['PHPUnit ReCyF démo sans ISO']['compliant']);
    }

    /**
     * Issue #789 : le référentiel détaillé est retenu pour la session ; une clé inconnue ou celle
     * d'un référentiel désactivé n'est jamais retenue ni affichée.
     */
    public function testSelectedFrameworkIsRememberedForTheSession(): void
    {
        FrameworkConfig::save([FrameworkSelection::ISO27001, FrameworkSelection::NIS2]);
        $assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));

        $this->assertNull(FrameworkAssessment::selectedFramework($assessments, null));
        $this->assertSame(
            FrameworkSelection::NIS2,
            FrameworkAssessment::selectedFramework($assessments, FrameworkSelection::NIS2)
        );
        $this->assertSame(FrameworkSelection::NIS2, FrameworkAssessment::selectedFramework($assessments, null));
        // Clé inconnue ou injectée : ignorée, le choix retenu reste.
        $this->assertSame(
            FrameworkSelection::NIS2,
            FrameworkAssessment::selectedFramework($assessments, '<script>x</script>')
        );
        $this->assertSame(FrameworkSelection::NIS2, FrameworkAssessment::selectedFramework($assessments, ['nis2']));
        $this->assertSame(FrameworkSelection::NIS2, $_SESSION[FrameworkAssessment::SESSION_KEY]);

        // Référentiel désactivé depuis : plus proposé.
        FrameworkConfig::save([FrameworkSelection::ISO27001]);
        $assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $this->assertNull(FrameworkAssessment::selectedFramework($assessments, null));

        // « Synthèse seule » oublie le choix.
        $this->assertSame(
            FrameworkSelection::ISO27001,
            FrameworkAssessment::selectedFramework($assessments, FrameworkSelection::ISO27001)
        );
        $this->assertNull(FrameworkAssessment::selectedFramework($assessments, ''));
        $this->assertArrayNotHasKey(FrameworkAssessment::SESSION_KEY, $_SESSION);
        $this->assertNull(FrameworkAssessment::detail($assessments, null));
    }

    public function testOnlyEnabledFrameworksAreAssessed(): void
    {
        FrameworkConfig::save([FrameworkSelection::ISO27001, FrameworkSelection::SOC2]);
        $result = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $this->assertSame(['ISO 27001', 'SOC 2'], array_keys($result));
        $this->assertSame(FrameworkSelection::SOC2, $result['SOC 2']['key']);
        // ISO 27001 recouvre toutes les mesures, SOC 2 seulement celles de sa correspondance.
        $this->assertGreaterThanOrEqual($result['SOC 2']['applicable'], $result['ISO 27001']['applicable']);
    }
}
