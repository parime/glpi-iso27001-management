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

use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Control\SoaReportBuilder;
use GlpiPlugin\Grcmanager\SoaVersion;
use Migration;

/**
 * Issue #609 : motifs de sélection et mesures compensatoires des exclusions de la SoA, dans une
 * vraie base. Données fictives ; assertions sur les codes et les valeurs stockées, les libellés
 * étant relus par les fonctions de traduction (la CI tourne en anglais, l'instance de dev en
 * français).
 */
final class SoaSelectionTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $savedSession = [];

    protected function setUp(): void
    {
        parent::setUp();

        $keys = [
            'glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiparententities',
            'glpiactiveprofile',
        ];
        foreach ($keys as $key) {
            $this->savedSession[$key] = $_SESSION[$key] ?? null;
        }
        $_SESSION['glpiactive_entity'] = 0;
        EntityPerimeter::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        EntityPerimeter::reset();

        parent::tearDown();
    }

    /**
     * Session limitée à une entité (sans sous-entité).
     */
    private function limitTo(int $entityId): void
    {
        $_SESSION['glpiactive_entity'] = $entityId;
        $_SESSION['glpiactiveentities'] = [$entityId];
        $_SESSION['glpiactiveentities_string'] = (string) $entityId;
        $_SESSION['glpiparententities'] = $entityId === 0 ? [] : [0];
        EntityPerimeter::reset();
    }

    /**
     * Pose une exclusion fictive sur A.7.4 de la SoA commune, compensée par A.5.20.
     *
     * @return array<string, int> code => id dans la SoA commune
     */
    private function excludeA74(): array
    {
        $ids = EntityPerimeter::controlIdsByCode(0);
        $this->assertTrue((new Control())->update([
            'id'                    => $ids['A.7.4'],
            'applicability'         => 'no',
            'justification'         => 'PHPUnit aucun local propre, hébergement chez un prestataire certifié',
            '_grcmanager_selection' => 1,
            'selection_reasons'     => ['best_practice', 'not-a-reason'],
            '_grcmanager_exclusion' => 1,
            'exclusion_category'    => 'certified_provider',
            'residual_risk'         => 'low',
            'compensating_measures' => '=PHPUnit clause d\'audit du contrat',
            // Elle-même et une mesure inexistante : écartées.
            'compensating_controls' => [$ids['A.5.20'], $ids['A.7.4'], 999999999],
        ]));

        return $ids;
    }

    public function testTheMigrationCanRunTwiceWithoutTouchingJustifications(): void
    {
        global $DB;

        $ids = EntityPerimeter::controlIdsByCode(0);
        $DB->update(Control::getTable(), [
            'applicability' => 'partial', 'justification' => 'PHPUnit justification existante',
        ], ['id' => $ids['A.8.11']]);
        $before = countElementsInTable(Control::getTable());

        $installer = new Installer();
        $method = new \ReflectionMethod($installer, 'installSoaSelectionFields');
        foreach ([1, 2] as $run) {
            $method->invoke(
                $installer,
                new Migration(PLUGIN_GRCMANAGER_VERSION),
                'security_domains',
                'unsigned',
                'utf8mb4',
                'utf8mb4_unicode_ci'
            );
        }

        foreach (['selection_reasons', 'exclusion_category', 'compensating_measures', 'residual_risk'] as $field) {
            $this->assertTrue($DB->fieldExists(Control::getTable(), $field), $field);
        }
        $this->assertTrue($DB->tableExists(Control::COMPENSATIONS_TABLE));
        $this->assertSame($before, countElementsInTable(Control::getTable()));
        $control = Control::getById($ids['A.8.11']);
        $this->assertSame('PHPUnit justification existante', $control->fields['justification']);
        $this->assertSame('partial', $control->fields['applicability']);
    }

    public function testAnExclusionStoresItsReasonsCategoryAndCompensatingControls(): void
    {
        $ids = $this->excludeA74();

        $control = Control::getById($ids['A.7.4']);
        $this->assertSame('best_practice', $control->fields['selection_reasons']);
        $this->assertSame('certified_provider', $control->fields['exclusion_category']);
        $this->assertSame('low', $control->fields['residual_risk']);
        $this->assertSame([$ids['A.5.20'] => 'A.5.20'], Control::getCompensatingControls($ids['A.7.4']));

        // Cases toutes décochées : le navigateur n'envoie rien, le marqueur les remet à vide.
        $this->assertTrue($control->update(['id' => $ids['A.7.4'], '_grcmanager_selection' => 1]));
        $this->assertSame('', Control::getById($ids['A.7.4'])->fields['selection_reasons']);

        // Valeurs hors liste : écartées.
        $this->assertTrue($control->update([
            'id' => $ids['A.7.4'], 'exclusion_category' => 'whatever', 'residual_risk' => 'extreme',
        ]));
        $control = Control::getById($ids['A.7.4']);
        $this->assertSame('', $control->fields['exclusion_category']);
        $this->assertSame('', $control->fields['residual_risk']);

        // Redevenue applicable : le détail de l'exclusion et ses liens disparaissent.
        $this->assertTrue($control->update(['id' => $ids['A.7.4'], 'applicability' => 'yes']));
        $control = Control::getById($ids['A.7.4']);
        $this->assertSame('', $control->fields['compensating_measures']);
        $this->assertSame([], Control::getCompensatingControls($ids['A.7.4']));
        $this->assertSame(
            'PHPUnit aucun local propre, hébergement chez un prestataire certifié',
            $control->fields['justification']
        );
    }

    public function testTheJustificationStaysRequiredForAnExclusion(): void
    {
        $ids = EntityPerimeter::controlIdsByCode(0);
        $this->assertFalse((new Control())->update([
            'id'                 => $ids['A.7.6'],
            'applicability'      => 'no',
            'justification'      => '',
            'exclusion_category' => 'activity_absent',
        ]));
        $this->assertSame('', (string) Control::getById($ids['A.7.6'])->fields['exclusion_category']);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    public function testLinkedRisksAndObligationsTickTheReasons(): void
    {
        $ids = EntityPerimeter::controlIdsByCode(0);
        $riskId = (int) (new Risk())->add([
            'title' => 'PHPUnit risque fictif', 'entities_id' => 0, 'probability' => 'possible', 'impact' => 'high',
        ]);
        $this->assertGreaterThan(0, $riskId);
        $this->assertGreaterThan(0, (int) (new ComplianceObligation())->add([
            'title'                      => 'PHPUnit contrat client fictif',
            'type'                       => 'contractual',
            'plugin_grcmanager_risks_id' => $riskId,
        ]));

        $this->assertSame(['risk_assessment', 'contractual'], Control::suggestedReasons([$riskId]));
        $this->assertSame([], Control::suggestedReasons([]));

        // Un risque nouvellement lié coche « appréciation des risques », sans retirer les autres.
        $control = new Control();
        $this->assertTrue($control->update([
            'id'                    => $ids['A.8.8'],
            'linked_risks'          => [$riskId],
            '_grcmanager_selection' => 1,
            'selection_reasons'     => ['best_practice'],
        ]));
        $this->assertSame(
            'risk_assessment,best_practice',
            Control::getById($ids['A.8.8'])->fields['selection_reasons']
        );

        // Le même risque, déjà lié : la case décochée reste décochée.
        $this->assertTrue($control->update([
            'id' => $ids['A.8.8'], 'linked_risks' => [$riskId], '_grcmanager_selection' => 1,
        ]));
        $this->assertSame('', Control::getById($ids['A.8.8'])->fields['selection_reasons']);
    }

    public function testCompensatingControlsStayInTheSameSoa(): void
    {
        $common = $this->excludeA74();
        $north = $this->createTestEntity(0, 'PHPUnit Filiale Compensation');

        // La filiale se dote de sa SoA : motifs, détail et liens repris, reliés à ses propres mesures.
        $this->limitTo($north);
        $this->assertGreaterThan(0, EntityPerimeter::createSoa($north));
        $own = EntityPerimeter::controlIdsByCode($north);
        $copy = Control::getById($own['A.7.4']);
        $this->assertSame('best_practice', $copy->fields['selection_reasons']);
        $this->assertSame('certified_provider', $copy->fields['exclusion_category']);
        $this->assertSame([$own['A.5.20'] => 'A.5.20'], Control::getCompensatingControls($own['A.7.4']));
        // Motifs jamais renseignés : ils le restent (le formulaire propose les motifs déduits).
        $this->assertNull(Control::getById($own['A.5.1'])->fields['selection_reasons']);

        // Proposées : les mesures de la SoA de la filiale seulement.
        $options = Control::compensatingOptions($north, $own['A.7.4']);
        $this->assertArrayHasKey($own['A.5.20'], $options);
        $this->assertArrayNotHasKey($common['A.5.20'], $options);
        $this->assertArrayNotHasKey($own['A.7.4'], $options);

        // Formulaire forgé avec une mesure de la SoA commune : lien refusé.
        $this->assertTrue((new Control())->update([
            'id'                    => $own['A.7.4'],
            '_grcmanager_exclusion' => 1,
            'compensating_controls' => [$common['A.5.21'], $own['A.5.21']],
        ]));
        $this->assertSame([$own['A.5.21'] => 'A.5.21'], Control::getCompensatingControls($own['A.7.4']));
    }

    public function testTheNewFieldsFollowTheRightOfTheSoa(): void
    {
        $ids = EntityPerimeter::controlIdsByCode(0);
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        $this->assertTrue((new Control())->can($ids['A.7.4'], READ));
        $this->assertFalse((new Control())->can($ids['A.7.4'], UPDATE));

        // Une filiale qui suit la SoA commune la lit sans pouvoir la modifier.
        $_SESSION['glpiactiveprofile']['plugin_grcmanager']
            = $this->savedSession['glpiactiveprofile']['plugin_grcmanager'];
        $south = $this->createTestEntity(0, 'PHPUnit Filiale Lecture');
        $this->limitTo($south);
        $this->assertFalse((new Control())->can($ids['A.7.4'], UPDATE));
    }

    public function testSearchFiltersOnAReason(): void
    {
        $criteria = Control::attributeSearchCriteria(Control::SEARCH_SELECTION_REASONS, 'legal', 'equals', false);
        $this->assertSame(
            [Control::getTable() . '.selection_reasons' => ['LIKE', '%legal%']],
            $criteria
        );
        $this->assertNull(Control::attributeSearchCriteria(Control::SEARCH_SELECTION_REASONS, 'x', 'equals', false));
        $this->assertNotNull(
            Control::attributeSearchCriteria(Control::SEARCH_SELECTION_REASONS, 'legal', 'notequals', false)
        );
    }

    public function testCsvExportCarriesTheReasonsAndTheExclusion(): void
    {
        $this->excludeA74();

        $lines = Control::csvExportRows(0);
        $header = $lines[0];
        foreach (
            ['code', 'justification', 'motifs_selection', 'categorie_exclusion', 'mesures_compensatoires_liees',
                'mesures_compensatoires', 'risque_residuel'] as $column
        ) {
            $this->assertContains($column, $header);
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $this->assertCount(count($header), $line);
            $rows[$line[0]] = array_combine($header, $line);
        }
        $a74 = $rows['A.7.4'];
        $this->assertSame(Control::getSelectionReasons()['best_practice'], $a74['motifs_selection']);
        $this->assertSame(Control::getExclusionCategories()['certified_provider'], $a74['categorie_exclusion']);
        $this->assertSame('A.5.20', $a74['mesures_compensatoires_liees']);
        // Texte libre commençant par « = » : neutralisé pour le tableur.
        $this->assertSame('\'=PHPUnit clause d\'audit du contrat', $a74['mesures_compensatoires']);
        $this->assertSame(Control::getResidualRiskLevels()['low'], $a74['risque_residuel']);
        // Une mesure applicable n'a pas de détail d'exclusion.
        $this->assertSame('', $rows['A.5.20']['categorie_exclusion']);
    }

    public function testPdfReportShowsReasonsExclusionAndThemeSummary(): void
    {
        $this->excludeA74();

        $html = SoaReportBuilder::renderHtml(0);
        $this->assertStringContainsString(htmlspecialchars(__('Summary by theme', 'grcmanager')), $html);
        $this->assertStringContainsString(htmlspecialchars(__('Reasons for selection', 'grcmanager')), $html);
        $this->assertStringContainsString(
            htmlspecialchars(Control::getExclusionCategories()['certified_provider']),
            $html
        );
        $this->assertStringContainsString('PHPUnit clause d&#039;audit du contrat', $html);

        $summary = Control::themeSummary(0);
        $physical = array_values(array_filter(
            $summary['lines'],
            static fn (array $line): bool => $line['theme'] === 'physical'
        ))[0];
        $this->assertGreaterThanOrEqual(1, $physical['excluded']);
        $totals = $summary['totals'];
        $this->assertSame($totals['total'], $totals['applicable'] + $totals['excluded']);
    }

    public function testTheFingerprintChangesOnceTheNewFieldsAreFilledIn(): void
    {
        $before = SoaVersion::currentFingerprint(0);
        $ids = EntityPerimeter::controlIdsByCode(0);
        // Aucun motif renseigné : enregistrer la mesure sans rien changer garde l'empreinte.
        $this->assertTrue((new Control())->update(['id' => $ids['A.5.1']]));
        $this->assertSame($before, SoaVersion::currentFingerprint(0));

        $this->excludeA74();
        $this->assertNotSame($before, SoaVersion::currentFingerprint(0));
    }
}
