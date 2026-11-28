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

/**
 * Issue #1163 : matrice 3 x 5 (probabilité et impact réglables indépendamment), score résiduel
 * enregistré et colonnes « score initial » / « score résiduel » de la liste. Aucun texte attendu
 * dans une langue donnée : seules les clés, les valeurs et les identifiants sont comparés.
 */
final class RiskMatrixShapeTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RiskMatrixConfig::saveScale(4, RiskMatrixDefaults::MATRIX);
        $_SESSION['glpi_currenttime'] ??= date('Y-m-d H:i:s');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RiskMatrixConfig::reset();
    }

    public function testResidualScoreColumnExistsAndIsFilledWithTheResidualLevel(): void
    {
        global $DB;

        $this->assertTrue($DB->fieldExists(Risk::getTable(), 'residual_score'));
        $risk = new Risk();
        $id = (int) $risk->add([
            'title' => 'Risque fictif ' . uniqid(), 'category' => 'technical',
            'probability' => 'certain', 'impact' => 'critical',
        ]);
        $this->assertGreaterThan(0, $id);
        $this->assertNull($this->row($id)['residual_score']);

        $risk->update(['id' => $id, 'residual_probability' => 'rare', 'residual_impact' => 'high']);
        $row = $this->row($id);
        $this->assertEqualsWithDelta(16.0, (float) $row['computed_score'], 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $row['residual_score'], 0.001);

        $this->assertTrue($risk->getFromDB($id));
        $scores = Risk::scoreComparison($risk->fields);
        $this->assertSame(['initial' => '16', 'residual' => '3', 'max' => 16], $scores);

        // Risque résiduel remis à « à évaluer » : le score résiduel redevient vide.
        $risk->update(['id' => $id, 'residual_probability' => '', 'residual_impact' => '']);
        $this->assertNull($this->row($id)['residual_score']);
    }

    public function testInitialAndResidualScoresAreSearchColumns(): void
    {
        $fields = [];
        foreach ((new Risk())->rawSearchOptions() as $option) {
            if (isset($option['id'], $option['field']) && in_array($option['id'], [17, 18], true)) {
                $fields[$option['id']] = $option['field'];
            }
        }
        $this->assertSame([17 => 'computed_score', 18 => 'residual_score'], $fields);
        $this->assertSame('12', Risk::getSpecificValueToDisplay('computed_score', ['computed_score' => '12.00']));
    }

    public function testThreeByFiveMatrixConvertsTheRegister(): void
    {
        $risk = new Risk();
        $id = (int) $risk->add([
            'title' => 'Risque fictif ' . uniqid(), 'category' => 'process',
            'probability' => 'certain', 'impact' => 'critical',
        ]);
        $risk->update(['id' => $id, 'residual_probability' => 'rare', 'residual_impact' => 'low']);

        $mapping = RiskScale::sanitizeMapping([], 4, 35, RiskMatrixConfig::load());
        $this->assertNotNull(RiskScaleChange::apply(35, $mapping));
        $this->assertSame(35, RiskMatrixConfig::size());
        $this->assertSame(['rare', 'possible', 'probable'], array_keys(Risk::getProbabilities()));
        $this->assertSame(
            ['negligible', 'low', 'medium', 'high', 'critical'],
            array_keys(Risk::getImpacts())
        );
        $this->assertArrayHasKey(35, RiskScaleChange::sizeLabels());

        $row = $this->row($id);
        $this->assertContains($row['probability'], RiskScale::probabilityKeys(35));
        $this->assertContains($row['impact'], RiskScale::impactKeys(35));
        $this->assertEqualsWithDelta(
            RiskScale::weight('probability', $row['probability'], 35)
                * RiskScale::weight('impact', $row['impact'], 35),
            (float) $row['computed_score'],
            0.001
        );
        $this->assertEqualsWithDelta(
            RiskScale::weight('probability', $row['residual_probability'], 35)
                * RiskScale::weight('impact', $row['residual_impact'], 35),
            (float) $row['residual_score'],
            0.001
        );

        // Nouvelle saisie sur l'échelle 3 x 5 : score maximal 15.
        $risk->update(['id' => $id, 'probability' => 'probable', 'impact' => 'critical']);
        $this->assertEqualsWithDelta(15.0, (float) $this->row($id)['computed_score'], 0.001);
        $this->assertSame('critical', $this->row($id)['risk_level']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        global $DB;

        return $DB->request(['FROM' => Risk::getTable(), 'WHERE' => ['id' => $id]])->current() ?? [];
    }
}
