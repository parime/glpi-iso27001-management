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

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Install\MaturityCampaignTables;
use GlpiPlugin\Grcmanager\Maturity;
use GlpiPlugin\Grcmanager\MaturityAssessment;
use GlpiPlugin\Grcmanager\MaturityCampaign;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

/**
 * Issue #603 : maturité par mesure (campagnes datées, niveau actuel et niveau cible, écarts,
 * comparaison, action depuis un écart, rapport PDF). Données fictives.
 */
final class MaturityCampaignTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function campaign(array $input = []): MaturityCampaign
    {
        $item = new MaturityCampaign();
        $id = (int) $item->add($input + ['entities_id' => 0, 'campaign_date' => '2026-03-02']);
        $this->assertGreaterThan(0, $id, 'Campagne fictive enregistrée.');
        $item->getFromDB($id);

        return $item;
    }

    /** @return array<string, array<string, mixed>> code => ligne */
    private static function linesByCode(int $campaignId): array
    {
        $lines = [];
        foreach (MaturityAssessment::getLines($campaignId) as $line) {
            $lines[(string) $line['code']] = $line;
        }

        return $lines;
    }

    /**
     * @param array<string, array<string, mixed>> $values code => champs
     */
    private static function fill(MaturityCampaign $campaign, array $values): int
    {
        $lines = self::linesByCode($campaign->getID());
        $posted = [];
        foreach ($values as $code => $fields) {
            $posted[(int) $lines[$code]['id']] = $fields;
        }

        return MaturityAssessment::updateGrid($campaign, $posted);
    }

    public function testCreationListsApplicableControlsAndLeavesStatusesUntouched(): void
    {
        global $DB;

        $controls = EntityPerimeter::controlIdsByCode(0);
        $DB->update(Control::getTable(), ['applicability' => 'no'], ['id' => $controls['A.7.5']]);
        $DB->update(Control::getTable(), ['applicability' => 'partial'], ['id' => $controls['A.7.6']]);
        $status = static fn (): string => (string) $DB->request([
            'SELECT' => ['implementation_status'], 'FROM' => Control::getTable(), 'WHERE' => ['id' => $controls['A.5.1']],
        ])->current()['implementation_status'];
        $before = $status();
        $score = Maturity::current()['score'];

        $campaign = $this->campaign(['name' => '']);
        $lines = self::linesByCode($campaign->getID());
        $this->assertArrayNotHasKey('A.7.5', $lines, 'Mesure exclue de la SoA : pas de ligne.');
        $this->assertArrayHasKey('A.7.6', $lines, 'Mesure partiellement applicable : une ligne.');
        $this->assertSame(count(MaturityAssessment::applicableCodes(0)), count($lines));
        $this->assertNull($lines['A.5.1']['current_level'], 'Non évaluée au départ.');
        $this->assertSame(2, (int) $campaign->fields['users_id'], 'Pilote : la personne connectée.');
        $this->assertStringContainsString('2026-03-02', $campaign->getName(), 'Sans nom : la date.');

        $this->assertSame(2, self::fill($campaign, [
            'A.5.1' => ['current_level' => '1', 'target_level' => '4', 'target_date' => '2027-06-30',
                'justification' => 'Politique fictive à formaliser', 'users_id' => 2],
            'A.5.2' => ['current_level' => '3', 'target_level' => '3'],
        ]));
        $this->assertSame(0, self::fill($campaign, ['A.5.2' => ['current_level' => '3', 'target_level' => '3']]), 'Rien de changé.');
        $this->assertSame($before, $status(), 'L\'état de mise en œuvre ne change pas.');
        $this->assertSame($score, Maturity::current()['score'], 'Le score de maturité global ne change pas.');

        $saved = self::linesByCode($campaign->getID())['A.5.1'];
        $this->assertSame(1, (int) $saved['current_level']);
        $this->assertSame(4, (int) $saved['target_level']);
        $this->assertSame('2027-06-30', $saved['target_date']);
    }

    public function testGridIgnoresForeignLinesAndInvalidValues(): void
    {
        $first = $this->campaign();
        $second = $this->campaign(['campaign_date' => '2026-04-01']);
        $foreign = (int) self::linesByCode($second->getID())['A.5.1']['id'];
        $this->assertSame(0, MaturityAssessment::updateGrid($first, [$foreign => ['current_level' => '2']]));
        $this->assertNull(self::linesByCode($second->getID())['A.5.1']['current_level']);

        self::fill($first, ['A.5.1' => [
            'current_level' => '9', 'target_level' => 'x', 'target_date' => '2027-02-30', 'users_id' => 999999,
        ]]);
        $line = self::linesByCode($first->getID())['A.5.1'];
        $this->assertNull($line['current_level']);
        $this->assertNull($line['target_level']);
        $this->assertNull($line['target_date']);
        $this->assertSame(0, (int) $line['users_id'], 'Utilisateur inconnu : aucun responsable.');
    }

    public function testNextCampaignTakesOverTargetsAndComparesLevels(): void
    {
        $first = $this->campaign(['campaign_date' => '2026-01-15']);
        self::fill($first, [
            'A.5.1' => ['current_level' => '1', 'target_level' => '3', 'target_date' => '2026-12-31',
                'justification' => 'Cible fictive', 'users_id' => 2],
            'A.5.2' => ['current_level' => '3', 'target_level' => '4'],
            'A.5.3' => ['current_level' => '2', 'target_level' => '2'],
        ]);
        $second = $this->campaign(['campaign_date' => '2027-01-15']);
        $this->assertSame($first->getID(), MaturityCampaign::previousCampaignId($second));
        $taken = self::linesByCode($second->getID())['A.5.1'];
        $this->assertNull($taken['current_level'], 'Niveau actuel à évaluer de nouveau.');
        $this->assertSame(3, (int) $taken['target_level']);
        $this->assertSame('2026-12-31', $taken['target_date']);
        $this->assertSame('Cible fictive', $taken['justification']);
        $this->assertSame(2, (int) $taken['users_id']);

        self::fill($second, [
            'A.5.1' => ['current_level' => '3', 'target_level' => '3'],
            'A.5.2' => ['current_level' => '2', 'target_level' => '4'],
            'A.5.3' => ['current_level' => '2', 'target_level' => '2'],
        ]);
        $rows = MaturityAssessment::present($second);
        $byCode = array_column($rows, null, 'code');
        $this->assertSame(1, $byCode['A.5.1']['previous'], 'Niveau de la campagne précédente rappelé.');

        // Comparaison dans les deux sens : la plus ancienne reste « avant ».
        foreach ([[$second, $first], [$first, $second]] as [$shown, $other]) {
            $comparison = MaturityCampaign::comparisonData($shown, $other->getID());
            $this->assertNotNull($comparison);
            $this->assertSame(1, $comparison['improved']);
            $this->assertSame(1, $comparison['declined']);
            $this->assertSame(1, $comparison['unchanged']);
            $this->assertSame($first->getName(), $comparison['before_name']);
        }

        // Une campagne d'une autre entité n'est ni proposée ni comparée.
        $childId = $this->createTestEntity(0, 'PHPUnit filiale maturité');
        $foreign = $this->campaign(['entities_id' => $childId]);
        $this->assertArrayNotHasKey($foreign->getID(), MaturityCampaign::otherCampaigns($second));
        $this->assertNull(MaturityCampaign::comparisonData($second, $foreign->getID()));
        MaturityCampaign::chooseComparison($second, $foreign->getID());
        $this->assertSame($first->getID(), MaturityCampaign::comparedCampaignId($second));
    }

    public function testGapsAreSortedAndGiveThemeAverages(): void
    {
        $campaign = $this->campaign();
        self::fill($campaign, [
            'A.5.1' => ['current_level' => '1', 'target_level' => '2'],
            'A.5.2' => ['current_level' => '0', 'target_level' => '4', 'users_id' => 2],
            'A.8.1' => ['current_level' => '2', 'target_level' => '4'],
            'A.8.2' => ['current_level' => '5', 'target_level' => '4'],
        ]);
        $data = MaturityCampaign::gapData($campaign);
        $this->assertSame(['A.5.2', 'A.8.1', 'A.5.1'], array_column($data['rows'], 'code'));
        $this->assertSame(3, $data['summary']['gaps']);
        $this->assertSame([2 => getUserName(2)], $data['owners']);
        $this->assertCount(2, $data['radar'], 'Thèmes organisationnel et technologique.');
        $this->assertSame(['organizational', 'technological'], array_keys($data['themes']));
    }

    public function testActionFromAGapIsAPrefilledImprovementLinkedOnceSaved(): void
    {
        $campaign = $this->campaign();
        self::fill($campaign, [
            'A.5.1' => ['current_level' => '1', 'target_level' => '4', 'target_date' => '2027-06-30', 'users_id' => 2,
                'justification' => 'Justification fictive'],
            'A.5.2' => ['current_level' => '3', 'target_level' => '3'],
        ]);
        $lines = self::linesByCode($campaign->getID());
        $this->assertNull(MaturityAssessment::findingDraft((int) $lines['A.5.2']['id']), 'Cible atteinte : aucune action.');
        $this->assertNull(MaturityAssessment::findingDraft((int) $lines['A.5.3']['id']), 'Non évaluée : aucune action.');

        $lineId = (int) $lines['A.5.1']['id'];
        $draft = MaturityAssessment::findingDraft($lineId);
        $this->assertNotNull($draft);
        $this->assertSame('improvement', $draft['finding_type']);
        $this->assertSame('major', $draft['severity'], 'Écart de 3 niveaux.');
        $this->assertSame('A.5.1', $draft['requirement_reference']);
        $this->assertSame('2027-06-30', $draft['due_date']);
        $this->assertSame(2, $draft['users_id']);
        $this->assertStringContainsString('Justification fictive', $draft['description']);

        $finding = new Nonconformity();
        $findingId = (int) $finding->add([
            'title' => $draft['title'], 'description' => $draft['description'], 'finding_type' => 'improvement',
            'severity' => 'major', 'entities_id' => 0, '_grcmanager_maturityassessment' => $lineId,
        ]);
        $this->assertGreaterThan(0, $findingId);
        $this->assertSame($findingId, (int) MaturityAssessment::getLine($lineId)['plugin_grcmanager_nonconformities_id']);
        $this->assertNull(MaturityAssessment::findingDraft($lineId), 'Déjà une action.');
        $this->assertCount(1, MaturityAssessment::linesForFinding($findingId));
        $byCode = array_column(MaturityAssessment::present($campaign), null, 'code');
        $this->assertSame($findingId, $byCode['A.5.1']['finding_id']);

        // Action purgée : la ligne reste, sans lien.
        $finding->delete(['id' => $findingId], true);
        $this->assertSame(0, (int) MaturityAssessment::getLine($lineId)['plugin_grcmanager_nonconformities_id']);

        // Action d'une autre entité : non liée.
        $childId = $this->createTestEntity(0, 'PHPUnit filiale action maturité');
        $other = (int) (new Nonconformity())->add([
            'title' => 'PHPUnit action filiale', 'entities_id' => $childId, '_grcmanager_maturityassessment' => $lineId,
        ]);
        $this->assertGreaterThan(0, $other);
        $this->assertSame(0, (int) MaturityAssessment::getLine($lineId)['plugin_grcmanager_nonconformities_id']);
    }

    public function testScaleLabelsReportAndPurge(): void
    {
        MaturityCampaign::saveScaleLabels([3 => 'Documenté fictif', 7 => 'Inconnu']);
        $labels = MaturityCampaign::scaleLabels();
        $this->assertSame('Documenté fictif', $labels[3]);
        $this->assertSame(MaturityCampaign::defaultScaleLabels()[4], $labels[4]);
        $this->assertSame([3 => 'Documenté fictif'], MaturityCampaign::customScaleLabels());

        $campaign = $this->campaign(['name' => 'PHPUnit campagne <b>fictive</b>']);
        self::fill($campaign, ['A.5.1' => [
            'current_level' => '1', 'target_level' => '3', 'justification' => 'PHPUnit <script>x</script>',
        ]]);
        $html = MaturityCampaign::renderPdf($campaign);
        $this->assertStringContainsString('A.5.1', $html);
        $this->assertStringContainsString('PHPUnit &lt;script&gt;', $html, 'Justification échappée.');
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringNotContainsString('<b>fictive</b>', $html);
        $this->assertStringStartsWith('%PDF-', PdfRenderer::renderHtmlToPdf($html));
        MaturityCampaign::saveScaleLabels([]);

        $id = $campaign->getID();
        $this->assertTrue($campaign->delete(['id' => $id], true));
        $this->assertSame(0, countElementsInTable(MaturityCampaignTables::ASSESSMENTS, [MaturityCampaign::FK => $id]));
    }

    public function testInstallIsReplayable(): void
    {
        global $DB;

        // Tables présentes : un nouveau passage de l'installeur ne change rien.
        MaturityCampaignTables::install('unsigned', 'utf8mb4', 'utf8mb4_unicode_ci');
        MaturityCampaignTables::install('unsigned', 'utf8mb4', 'utf8mb4_unicode_ci');
        $this->assertTrue($DB->tableExists(MaturityCampaignTables::CAMPAIGNS));
        $this->assertTrue($DB->fieldExists(MaturityCampaignTables::ASSESSMENTS, 'target_level'));
    }
}
