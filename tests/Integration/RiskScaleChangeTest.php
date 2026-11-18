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

use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskScaleChange;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixDefaults;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;
use GlpiPlugin\Grcmanager\SupplierRisk;
use Log;

/**
 * Issue #706 : changement de taille de la matrice des risques sur un registre existant
 * (correspondance validée, historique, aucune réévaluation silencieuse).
 */
final class RiskScaleChangeTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Point de départ d'une installation par défaut (4x4), annulé avec la transaction du test.
        RiskMatrixConfig::saveScale(4, RiskMatrixDefaults::MATRIX);
        $_SESSION['glpi_currenttime'] ??= date('Y-m-d H:i:s');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RiskMatrixConfig::reset();
    }

    public function testFourByFourIsTheDefault(): void
    {
        $this->assertSame(4, RiskMatrixConfig::size());
        $this->assertSame(['rare', 'possible', 'probable', 'certain'], array_keys(Risk::getProbabilities()));
        $this->assertSame(['low', 'medium', 'high', 'critical'], array_keys(Risk::getImpacts()));
    }

    public function testChangingTheSizeConvertsTheRegisterWithHistory(): void
    {
        global $DB;

        $risk = new Risk();
        $id = (int) $risk->add([
            'title' => 'Risque fictif ' . uniqid(), 'category' => 'technical',
            'probability' => 'rare', 'impact' => 'low',
        ]);
        $this->assertGreaterThan(0, $id);
        $risk->update(['id' => $id, 'residual_probability' => 'rare', 'residual_impact' => 'high']);
        // Valeur d'une autre taille refusée : la valeur enregistrée est gardée.
        $risk->update(['id' => $id, 'probability' => 'very_rare']);
        $this->assertSame('rare', $this->field(Risk::class, $id, 'probability'));

        $other = (int) (new Risk())->add([
            'title' => 'Risque fictif ' . uniqid(), 'category' => 'people',
            'probability' => 'certain', 'impact' => 'low',
        ]);
        $this->assertSame('medium', $this->field(Risk::class, $other, 'risk_level'));

        $supplier = (int) (new \Supplier())->add(['name' => 'Fournisseur fictif', 'entities_id' => 0]);
        $supplierRisk = (int) (new SupplierRisk())->add([
            'title' => 'Risque fournisseur fictif', 'suppliers_id' => $supplier,
            'probability' => 'rare', 'impact' => 'high', 'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $supplierRisk);

        // Aperçu : rien n'est écrit, le résumé annonce les niveaux qui montent.
        // Correspondance choisie par l'administrateur : rare -> très rare, le reste garde sa clé.
        $mapping = RiskScale::sanitizeMapping([
            'probability' => [
                'rare' => 'very_rare', 'possible' => 'possible', 'probable' => 'probable', 'certain' => 'certain',
            ],
            'impact'      => ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'critical' => 'critical'],
        ], 4, 5);
        $preview = RiskScaleChange::preview(5, $mapping);
        $this->assertSame(4, RiskMatrixConfig::size());
        $this->assertSame('rare', $this->field(Risk::class, $id, 'probability'));
        $this->assertGreaterThanOrEqual(3, $preview['summary']['changed']);
        $this->assertGreaterThanOrEqual(1, $preview['summary']['raised']);
        $uses = array_column($preview['axes']['probability']['rows'], 'uses', 'key');
        $this->assertGreaterThanOrEqual(2, $uses['rare']);

        $logsBefore = countElementsInTable('glpi_logs', ['itemtype' => Risk::class, 'items_id' => $id]);
        $changed = RiskScaleChange::apply(5, $mapping);
        $this->assertGreaterThanOrEqual(3, (int) $changed);
        $this->assertSame(5, RiskMatrixConfig::size());

        // rare -> very_rare : very_rare x low = 2 (low) ; résiduel very_rare x high = 4 (low).
        $this->assertSame('very_rare', $this->field(Risk::class, $id, 'probability'));
        $this->assertSame('low', $this->field(Risk::class, $id, 'impact'));
        $this->assertSame('very_rare', $this->field(Risk::class, $id, 'residual_probability'));
        $this->assertSame('low', $this->field(Risk::class, $id, 'residual_level'));
        // certain x low : medium en 4x4, high en 5x5 (niveau qui monte, annoncé par l'aperçu).
        $this->assertSame('high', $this->field(Risk::class, $other, 'risk_level'));
        $this->assertSame('10.00', $this->field(Risk::class, $other, 'computed_score'));
        $this->assertSame('very_rare', $this->field(SupplierRisk::class, $supplierRisk, 'probability'));

        // Historique : ancienne et nouvelle valeur de chaque champ, et message de conversion.
        $logs = iterator_to_array($DB->request([
            'FROM'  => 'glpi_logs',
            'WHERE' => ['itemtype' => Risk::class, 'items_id' => $id, 'id' => ['>', 0]],
            'ORDER' => 'id',
        ]), false);
        $this->assertGreaterThan($logsBefore, count($logs));
        $values = array_map(static fn (array $log): string => $log['old_value'] . '>' . $log['new_value'], $logs);
        $this->assertContains('rare>very_rare', $values);
        $messages = array_filter(
            $logs,
            static fn (array $log): bool => (int) $log['linked_action'] === Log::HISTORY_LOG_SIMPLE_MESSAGE
                && str_contains((string) $log['new_value'], '5 x 5')
        );
        $this->assertCount(1, $messages);
        $this->assertSame(1, countElementsInTable('glpi_logs', [
            'itemtype' => SupplierRisk::class, 'items_id' => $supplierRisk,
            'linked_action' => Log::HISTORY_LOG_SIMPLE_MESSAGE,
        ]));

        // En 5x5, les nouvelles valeurs se saisissent et se calculent sur l'échelle à 5 niveaux.
        $this->assertSame(
            ['very_rare', 'rare', 'possible', 'probable', 'certain'],
            array_keys(Risk::getProbabilities())
        );
        $new = (int) (new Risk())->add([
            'title' => 'Risque fictif ' . uniqid(), 'category' => 'process',
            'probability' => 'certain', 'impact' => 'negligible',
        ]);
        $this->assertSame('negligible', $this->field(Risk::class, $new, 'impact'));
        $this->assertSame('medium', $this->field(Risk::class, $new, 'risk_level'));
        $this->assertSame('5.00', $this->field(Risk::class, $new, 'computed_score'));

        // Même taille demandée : rien n'est fait.
        $this->assertNull(RiskScaleChange::apply(5, $mapping));

        // Retour en 4x4 : very_rare -> rare, negligible -> low, le reste garde sa clé.
        RiskScaleChange::apply(4, RiskScale::sanitizeMapping([
            'probability' => [
                'very_rare' => 'rare', 'rare' => 'rare', 'possible' => 'possible', 'probable' => 'probable',
            ],
            'impact'      => ['negligible' => 'low', 'low' => 'low', 'medium' => 'medium'],
        ], 5, 4));
        $this->assertSame(4, RiskMatrixConfig::size());
        $this->assertSame('rare', $this->field(Risk::class, $id, 'probability'));
        $this->assertSame('low', $this->field(Risk::class, $new, 'impact'));
        $this->assertSame('medium', $this->field(Risk::class, $new, 'risk_level'));
        $this->assertSame('medium', $this->field(Risk::class, $other, 'risk_level'));
    }

    public function testInstalledTableHasTheSizeColumnWithFourByDefault(): void
    {
        global $DB;

        $this->assertTrue($DB->fieldExists('glpi_plugin_grcmanager_riskmatrixconfig', 'scale_size'));
        $column = $DB->request([
            'SELECT' => ['COLUMN_DEFAULT'],
            'FROM'   => 'information_schema.COLUMNS',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'TABLE_NAME'   => 'glpi_plugin_grcmanager_riskmatrixconfig',
                'COLUMN_NAME'  => 'scale_size',
            ],
        ])->current();
        // Installation existante mise à jour : la colonne ajoutée vaut 4, aucun registre ne change.
        $this->assertSame('4', trim((string) $column['COLUMN_DEFAULT'], "'"));
    }

    /**
     * @param class-string<\CommonDBTM> $itemtype
     */
    private function field(string $itemtype, int $id, string $field): string
    {
        global $DB;

        $row = $DB->request(['SELECT' => [$field], 'FROM' => $itemtype::getTable(), 'WHERE' => ['id' => $id]])
            ->current();

        return (string) ($row[$field] ?? '');
    }

    /**
     * Issue #706, lot 2 : libellés personnalisés des niveaux, sans effet sur les clés ni les risques.
     */
    public function testCustomLabelsReplaceTheDefaultOnes(): void
    {
        RiskMatrixConfig::saveLabels([
            'probability' => ['rare' => 'Exceptionnel', 'possible' => '   '],
            'impact'      => ['critical' => 'Vital', 'bogus' => 'Ignoré'],
        ]);

        $probabilities = Risk::getProbabilities();
        $this->assertSame(['rare', 'possible', 'probable', 'certain'], array_keys($probabilities));
        $this->assertSame('Exceptionnel', $probabilities['rare']);
        $this->assertSame(Risk::getDefaultProbabilityLabels()['possible'], $probabilities['possible']);
        $this->assertSame('Vital', Risk::getImpacts()['critical']);
        $this->assertSame(['critical' => 'Vital'], RiskMatrixConfig::labels()['impact']);
        $this->assertStringContainsString('Vital', Risk::impactBadge('critical'));

        // Le niveau de risque garde ses libellés.
        $this->assertSame(['low', 'medium', 'high', 'critical'], array_keys(Risk::getRiskLevels()));

        // Retour aux libellés par défaut.
        RiskMatrixConfig::saveLabels([]);
        $this->assertSame(Risk::getDefaultProbabilityLabels()['rare'], Risk::getProbabilities()['rare']);
    }

    public function testCustomLabelsSurviveASizeChange(): void
    {
        RiskMatrixConfig::saveLabels(['probability' => ['very_rare' => 'Exceptionnel']]);
        $this->assertArrayNotHasKey('very_rare', Risk::getProbabilities());

        RiskMatrixConfig::saveScale(5, RiskMatrixDefaults::MATRIX_5);
        $this->assertSame('Exceptionnel', Risk::getProbabilities()['very_rare']);
        $this->assertSame('Exceptionnel', Risk::getProbabilities(5)['very_rare']);
    }
}
