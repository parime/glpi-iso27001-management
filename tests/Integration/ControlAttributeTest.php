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
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Control\ControlAttributeCatalog;

/**
 * Issue #610 : attributs des mesures de l'Annexe A dans une vraie base. Assertions sur les codes et
 * les valeurs stockées, jamais sur des libellés traduits (la CI tourne en anglais, l'instance de dev
 * en français).
 */
final class ControlAttributeTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return array<string, array<string, mixed>> code => ligne de la SoA commune
     */
    private function commonSoa(): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request(['FROM' => Control::getTable(), 'WHERE' => ['entities_id' => 0]]) as $row) {
            $rows[(string) $row['code']] = $row;
        }

        return $rows;
    }

    public function testInstalledCatalogueCarriesTheDeliveredAttributes(): void
    {
        $rows = $this->commonSoa();
        $this->assertCount(count(ControlAttributeCatalog::CONTROLS), $rows);
        foreach (ControlAttributeCatalog::CONTROLS as $code => $values) {
            foreach (ControlAttributeCatalog::defaultsFor($code) as $attribute => $stored) {
                $this->assertSame($stored, (string) $rows[$code][$attribute], $code . ' ' . $attribute);
            }
        }
    }

    public function testReplayedSeedingKeepsStatusesAndAdminChanges(): void
    {
        global $DB;

        $rows = $this->commonSoa();
        // Mesure modifiée par un administrateur : son attribut et son état sont conservés.
        $DB->update(Control::getTable(), [
            'implementation_status' => 'implemented',
            'control_types'         => 'detective',
        ], ['id' => (int) $rows['A.5.1']['id']]);
        // Mesure issue d'une ancienne version (aucun attribut) : complétée, statut intact.
        $DB->update(
            Control::getTable(),
            array_fill_keys(ControlAttributeCatalog::attributes(), '') + ['implementation_status' => 'in_progress'],
            ['id' => (int) $rows['A.8.13']['id']]
        );

        $seed = new \ReflectionMethod(Installer::class, 'seedControlAttributes');
        $seed->invoke(new Installer());

        $after = $this->commonSoa();
        $this->assertSame('detective', $after['A.5.1']['control_types']);
        $this->assertSame('implemented', $after['A.5.1']['implementation_status']);
        $this->assertSame(
            ControlAttributeCatalog::defaultsFor('A.8.13')['cybersecurity_concepts'],
            $after['A.8.13']['cybersecurity_concepts']
        );
        $this->assertSame('in_progress', $after['A.8.13']['implementation_status']);
    }

    public function testSearchFilterMatchesEveryControlCarryingTheValue(): void
    {
        global $DB;

        $expected = array_keys(array_filter(
            ControlAttributeCatalog::CONTROLS,
            static fn (array $values, string $code): bool => ControlAttributeCatalog::has(
                ControlAttributeCatalog::CYBERSECURITY_CONCEPTS,
                ControlAttributeCatalog::defaultsFor($code)[ControlAttributeCatalog::CYBERSECURITY_CONCEPTS],
                'recover'
            ),
            ARRAY_FILTER_USE_BOTH
        ));
        $option = array_search(
            ControlAttributeCatalog::CYBERSECURITY_CONCEPTS,
            Control::attributeSearchOptions(),
            true
        );
        $this->assertIsInt($option);

        $codes = static function (array $criteria) use ($DB): array {
            $found = [];
            foreach (
                $DB->request([
                    'SELECT' => ['code'],
                    'FROM'   => Control::getTable(),
                    'WHERE'  => [Control::getTable() . '.entities_id' => 0] + ['AND' => [$criteria]],
                ]) as $row
            ) {
                $found[] = (string) $row['code'];
            }
            sort($found);

            return $found;
        };

        $included = $codes((array) Control::attributeSearchCriteria($option, 'recover', 'equals', false));
        $excluded = $codes((array) Control::attributeSearchCriteria($option, 'recover', 'notequals', false));
        sort($expected);
        $this->assertSame($expected, $included);
        $this->assertCount(count(ControlAttributeCatalog::CONTROLS) - count($expected), $excluded);
        $this->assertSame([], array_intersect($included, $excluded));

        // Valeur inconnue ou autre option : GLPI garde son traitement.
        $this->assertNull(Control::attributeSearchCriteria($option, 'unknown', 'equals', false));
        $this->assertNull(Control::attributeSearchCriteria(3, 'recover', 'equals', false));
    }

    public function testFormSaveEncodesAndClearsAttributes(): void
    {
        $rows = $this->commonSoa();
        $id = (int) $rows['A.6.8']['id'];
        $control = new Control();
        $this->assertTrue($control->update([
            'id'                     => $id,
            '_grcmanager_attributes' => 1,
            'control_types'          => ['corrective', 'not-a-value', 'preventive'],
            'security_properties'    => ['integrity'],
        ]));
        $control->getFromDB($id);
        $this->assertSame('preventive,corrective', $control->fields['control_types']);
        $this->assertSame('integrity', $control->fields['security_properties']);
        // Liste vidée dans le formulaire (non envoyée par le navigateur) : remise à vide.
        $this->assertSame('', $control->fields['security_domains']);
    }

    public function testSoaOfAnEntityGetsTheDeliveredAttributes(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Filiale Attributs');
        $control = new Control();
        $id = $control->add([
            'entities_id'         => $entityId,
            'code'                => 'A.5.30',
            'theme'               => 'organizational',
            '_grcmanager_new_soa' => true,
        ]);
        $this->assertGreaterThan(0, $id);
        $control->getFromDB($id);
        foreach (ControlAttributeCatalog::defaultsFor('A.5.30') as $attribute => $stored) {
            $this->assertSame($stored, $control->fields[$attribute]);
        }
    }

    public function testSummaryFollowsTheActiveSoa(): void
    {
        EntityPerimeter::reset();
        $summary = Control::attributeSummary(0, [ControlAttributeCatalog::CONTROL_TYPES]);
        $this->assertCount(1, $summary);
        $lines = array_column($summary[0]['lines'], null, 'value');
        $this->assertSame(['preventive', 'detective', 'corrective'], array_keys($lines));

        $rows = $this->commonSoa();
        $preventive = 0;
        foreach (array_keys(ControlAttributeCatalog::CONTROLS) as $code) {
            $preventive += (int) ControlAttributeCatalog::has(
                ControlAttributeCatalog::CONTROL_TYPES,
                $rows[$code]['control_types'],
                'preventive'
            );
        }
        $this->assertSame($preventive, $lines['preventive']['total']);
        $this->assertStringContainsString('criteria', $lines['preventive']['url']);
    }
}
