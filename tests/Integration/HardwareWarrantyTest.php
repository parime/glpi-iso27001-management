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

use GlpiPlugin\Grcmanager\ConsistencyCheck;
use GlpiPlugin\Grcmanager\HardwareWarranty;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\HardwareModelEolStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\WarrantyRules as R;

/**
 * Issue #782 : fin de garantie du matériel d'après les informations financières natives de GLPI.
 * Entités, ordinateurs, moniteurs et garanties fictifs.
 */
final class HardwareWarrantyTest extends GrcmanagerIntegrationTestCase
{
    private const TODAY = '2026-11-20';

    private string $suffix;

    private int $visible;

    private int $hidden;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        HardwareWarranty::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->visible = $this->createTestEntity(0, 'PhpUnitDemo Atelier ' . $this->suffix);
        $this->hidden = $this->createTestEntity(0, 'PhpUnitDemo Filiale ' . $this->suffix);
        // Session : l'atelier seulement ; la filiale reste hors des entités actives.
        $_SESSION['glpiactiveentities'] = [$this->visible];
        $_SESSION['glpiactiveentities_string'] = (string) $this->visible;
        EndOfLifeStore::save(false, 90);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        HardwareWarranty::reset();
        parent::tearDown();
    }

    /**
     * Matériel fictif, avec ses informations financières si $start ou $months sont donnés.
     *
     * @param class-string<\CommonDBTM> $itemtype
     * @param array<string, mixed> $extra
     */
    private function asset(
        string $itemtype,
        string $name,
        int $entity,
        ?string $start,
        ?int $months,
        array $extra = []
    ): int {
        $id = (int) (new $itemtype())->add([
            'name' => 'PhpUnitDemo ' . $name . ' ' . $this->suffix, 'entities_id' => $entity,
        ] + $extra);
        $this->assertGreaterThan(0, $id);
        if ($start !== null || $months !== null) {
            $this->assertGreaterThan(0, (int) (new \Infocom())->add([
                'itemtype' => $itemtype, 'items_id' => $id, 'entities_id' => $entity,
                'warranty_date' => $start, 'warranty_duration' => $months ?? 0,
            ]));
        }

        return $id;
    }

    /**
     * État de chaque matériel fictif de ce test : « Type:id » => état.
     *
     * @return array<string, string>
     */
    private function mine(): array
    {
        HardwareWarranty::reset();
        $mine = [];
        foreach (HardwareWarranty::assets(self::TODAY) as $asset) {
            if (str_ends_with($asset['item_name'], ' ' . $this->suffix)) {
                $mine[$asset['itemtype'] . ':' . $asset['items_id']] = $asset['status'];
            }
        }
        ksort($mine);

        return $mine;
    }

    /**
     * @return array{int, string}
     */
    private function glpiState(): array
    {
        global $DB;

        $rows = iterator_to_array($DB->request(['FROM' => 'glpi_infocoms', 'ORDER' => 'id']), false);

        return [count($rows), md5((string) json_encode($rows))];
    }

    public function testStatesEntitiesAndExportFromNativeInfocoms(): void
    {
        $expired = $this->asset(\Computer::class, 'PC expire', $this->visible, '2022-01-10', 36);
        $soon = $this->asset(\Computer::class, 'PC proche', $this->visible, '2024-01-15', 36);
        $covered = $this->asset(\Computer::class, 'PC couvert', $this->visible, '2026-01-01', 36);
        $lifetime = $this->asset(\Computer::class, 'PC a vie', $this->visible, null, R::LIFETIME);
        $none = $this->asset(\Computer::class, 'PC sans infocom', $this->visible, null, null);
        $noDuration = $this->asset(\Computer::class, 'PC sans duree', $this->visible, '2025-01-01', 0);
        $monitor = $this->asset(\Monitor::class, 'Ecran expire', $this->visible, '2020-05-01', 24);
        // Hors périmètre : entité cachée, corbeille, gabarit.
        $this->asset(\Computer::class, 'PC filiale', $this->hidden, '2020-01-01', 12);
        $this->asset(\Computer::class, 'PC corbeille', $this->visible, '2020-01-01', 12, ['is_deleted' => 1]);
        $this->asset(\Computer::class, 'PC gabarit', $this->visible, '2020-01-01', 12, [
            'is_template' => 1, 'template_name' => 'PhpUnitDemo gabarit ' . $this->suffix,
        ]);

        $before = $this->glpiState();
        $expected = [
            'Computer:' . $expired    => R::STATUS_EXPIRED,
            'Computer:' . $soon       => R::STATUS_SOON,
            'Computer:' . $covered    => R::STATUS_COVERED,
            'Computer:' . $lifetime   => R::STATUS_COVERED,
            'Computer:' . $none       => R::STATUS_UNKNOWN,
            'Computer:' . $noDuration => R::STATUS_UNKNOWN,
            'Monitor:' . $monitor     => R::STATUS_EXPIRED,
        ];
        ksort($expected);
        $this->assertSame($expected, $this->mine());

        // Seuil commun de la fin de support : 2027-01-15 est à 56 jours.
        EndOfLifeStore::save(false, 30);
        $this->assertSame(R::STATUS_COVERED, $this->mine()['Computer:' . $soon]);
        EndOfLifeStore::save(false, 90);

        // Carte : tableau par type et par entité, matériels à traiter seulement par défaut.
        HardwareWarranty::reset();
        $page = HardwareWarranty::pageData(false, self::TODAY);
        $this->assertNotNull($page);
        $entityName = 'PhpUnitDemo Atelier ' . $this->suffix;
        $mineGroups = array_values(array_filter($page['groups'], fn (array $g): bool => $g['entity'] === $entityName));
        // Groupes rangés par nom de type traduit (Ordinateur après Moniteur en français) : l'ordre
        // dépend de la langue, seuls les types présents sont comparés.
        $byType = array_column($mineGroups, null, 'itemtype');
        $types = array_keys($byType);
        sort($types);
        $this->assertSame([\Computer::class, \Monitor::class], $types);
        $this->assertSame(
            [R::STATUS_EXPIRED => 1, R::STATUS_SOON => 1, R::STATUS_COVERED => 2, R::STATUS_UNKNOWN => 2],
            $byType[\Computer::class]['counts']
        );
        $this->assertNotContains('PhpUnitDemo Filiale ' . $this->suffix, array_column($page['groups'], 'entity'));
        $names = array_column($page['rows'], 'name');
        $this->assertContains('PhpUnitDemo PC expire ' . $this->suffix, $names);
        $this->assertContains('PhpUnitDemo PC proche ' . $this->suffix, $names);
        $this->assertNotContains('PhpUnitDemo PC couvert ' . $this->suffix, $names);
        $all = HardwareWarranty::pageData(true, self::TODAY);
        $this->assertNotNull($all);
        $this->assertContains('PhpUnitDemo PC couvert ' . $this->suffix, array_column($all['rows'], 'name'));

        // Export : un matériel par ligne, avec la date de fin calculée comme GLPI.
        $csv = HardwareWarranty::csvRows(self::TODAY);
        $lines = array_values(array_filter($csv, fn (array $r): bool => str_ends_with($r[1], ' ' . $this->suffix)));
        $this->assertCount(7, $lines);
        $byName = array_column($lines, null, 1);
        $this->assertSame('2025-01-10', $byName['PhpUnitDemo PC expire ' . $this->suffix][8]);
        $this->assertSame('36', $byName['PhpUnitDemo PC expire ' . $this->suffix][7]);
        $this->assertSame($entityName, $byName['PhpUnitDemo PC expire ' . $this->suffix][3]);

        // Preuves A.7.13 et A.8.9, même sans la source endoflife.date.
        $evidence = HardwareWarranty::evidence('A.7.13');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(2, $evidence['expired']);
        $this->assertNotNull(HardwareWarranty::evidence('A.8.9'));
        $this->assertNull(HardwareWarranty::evidence('A.5.1'));

        // Contrôle de cohérence : les matériels expirés visibles, rien d'autre.
        $results = ConsistencyCheck::run(self::TODAY);
        $this->assertTrue($results['hardware_warranty_expired']['evaluated']);
        $gaps = array_map(
            static fn (array $gap): string => $gap['itemtype'] . ':' . $gap['id'],
            $results['hardware_warranty_expired']['gaps']
        );
        $this->assertContains('Computer:' . $expired, $gaps);
        $this->assertContains('Monitor:' . $monitor, $gaps);
        $this->assertNotContains('Computer:' . $soon, $gaps);

        $this->assertSame($before, $this->glpiState(), 'Aucune information financière modifiée.');
    }

    public function testNativeRightsAreRequired(): void
    {
        $computer = $this->asset(\Computer::class, 'PC expire', $this->visible, '2020-01-01', 12);
        $monitor = $this->asset(\Monitor::class, 'Ecran expire', $this->visible, '2020-01-01', 12);
        $this->assertCount(2, $this->mine());

        foreach ([\Infocom::$rightname, 'plugin_grcmanager_sensitive'] as $right) {
            $saved = $_SESSION['glpiactiveprofile'][$right] ?? 0;
            $_SESSION['glpiactiveprofile'][$right] = 0;
            try {
                $this->assertFalse(HardwareWarranty::canView(), $right);
                $this->assertSame([], $this->mine(), $right);
                $this->assertNull(HardwareWarranty::pageData(false, self::TODAY), $right);
                $this->assertNull(HardwareWarranty::evidence('A.7.13'), $right);
                $this->assertNull(HardwareWarranty::consistencyGaps(self::TODAY), $right);
                $this->assertFalse(ConsistencyCheck::run(self::TODAY)['hardware_warranty_expired']['evaluated']);
            } finally {
                $_SESSION['glpiactiveprofile'][$right] = $saved;
            }
        }

        // Sans droit de lire les ordinateurs, seuls les moniteurs sont lus.
        $saved = $_SESSION['glpiactiveprofile'][\Computer::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][\Computer::$rightname] = 0;
        try {
            $this->assertSame(['Monitor:' . $monitor => R::STATUS_EXPIRED], $this->mine());
        } finally {
            $_SESSION['glpiactiveprofile'][\Computer::$rightname] = $saved;
        }
        $this->assertArrayHasKey('Computer:' . $computer, $this->mine());
    }

    public function testCsvCellsCannotInjectFormulas(): void
    {
        (new \Computer())->add([
            'name' => '=HYPERLINK("http://example.test") ' . $this->suffix, 'entities_id' => $this->visible,
        ]);
        $rows = array_values(array_filter(
            HardwareWarranty::csvRows(self::TODAY),
            fn (array $r): bool => str_ends_with($r[1], ' ' . $this->suffix)
        ));
        $this->assertCount(1, $rows);
        $this->assertSame(HardwareWarranty::statusInfo(R::STATUS_UNKNOWN)['label'], $rows[0][0]);
        $this->assertStringStartsWith("'=", ConsistencyRules::csvCell($rows[0][1]));
    }

    /**
     * Lot 2 : fin de vie constructeur saisie par modèle, sans rien écrire dans les modèles de GLPI.
     */
    public function testManufacturerEndOfLifePerModel(): void
    {
        global $DB;

        $old = (int) (new \ComputerModel())->add(['name' => 'PhpUnitDemo Ancien modele ' . $this->suffix]);
        $recent = (int) (new \ComputerModel())->add(['name' => 'PhpUnitDemo Modele recent ' . $this->suffix]);
        $this->assertGreaterThan(0, $old);
        $oldPc = $this->asset(\Computer::class, 'PC ancien', $this->visible, '2026-01-01', 36, [
            'computermodels_id' => $old,
        ]);
        $recentPc = $this->asset(\Computer::class, 'PC recent', $this->visible, '2026-01-01', 36, [
            'computermodels_id' => $recent,
        ]);
        $plainPc = $this->asset(\Computer::class, 'PC sans modele', $this->visible, '2026-01-01', 36);
        $models = iterator_to_array($DB->request(['FROM' => 'glpi_computermodels', 'ORDER' => 'id']), false);

        // Saisie depuis la configuration : refus d'une classe non suivie et d'une date invalide.
        $this->assertSame(2, HardwareWarranty::saveModelDates([
            'ComputerModel:' . $old    => '2025-06-30',
            'ComputerModel:' . $recent => '2027-01-15',
            'User:1'                   => '2025-01-01',
            'ComputerModel:' . $plainPc => 'demain',
        ]));
        $this->assertFalse(HardwareModelEolStore::save('Computer', $oldPc, '2025-01-01', 0));
        $this->assertSame('2025-06-30', HardwareModelEolStore::dateFor('ComputerModel', $old));

        $eol = [];
        HardwareWarranty::reset();
        foreach (HardwareWarranty::assets(self::TODAY) as $asset) {
            if (str_ends_with($asset['item_name'], ' ' . $this->suffix)) {
                $eol[$asset['items_id']] = $asset['eol']['status'];
            }
        }
        $this->assertSame(R::EOL_REACHED, $eol[$oldPc]);
        $this->assertSame(R::EOL_SOON, $eol[$recentPc]);
        $this->assertSame(R::EOL_NONE, $eol[$plainPc]);

        // Carte : sous garantie, mais à traiter pour la fin de vie ; compteurs.
        $page = HardwareWarranty::pageData(false, self::TODAY);
        $this->assertNotNull($page);
        $names = array_column($page['rows'], 'name');
        $this->assertContains('PhpUnitDemo PC ancien ' . $this->suffix, $names);
        $this->assertContains('PhpUnitDemo PC recent ' . $this->suffix, $names);
        $this->assertNotContains('PhpUnitDemo PC sans modele ' . $this->suffix, $names);
        $this->assertGreaterThanOrEqual(1, $page['eol_counts'][R::EOL_REACHED]);

        // Export : date et état de la fin de vie en dernières colonnes.
        $byName = array_column(array_values(array_filter(
            HardwareWarranty::csvRows(self::TODAY),
            fn (array $r): bool => str_ends_with($r[1], ' ' . $this->suffix)
        )), null, 1);
        $this->assertSame('2025-06-30', $byName['PhpUnitDemo PC ancien ' . $this->suffix][10]);
        $this->assertSame(
            HardwareWarranty::endOfLifeInfos()[R::EOL_REACHED]['label'],
            $byName['PhpUnitDemo PC ancien ' . $this->suffix][11]
        );
        $this->assertSame('', $byName['PhpUnitDemo PC sans modele ' . $this->suffix][10]);

        // Preuve et contrôle de cohérence : fin de vie atteinte seulement.
        $evidence = HardwareWarranty::evidence('A.7.13');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(1, $evidence['eol_reached']);
        $results = ConsistencyCheck::run(self::TODAY);
        $this->assertTrue($results['hardware_end_of_life_reached']['evaluated']);
        $gaps = array_map(
            static fn (array $gap): string => $gap['itemtype'] . ':' . $gap['id'],
            $results['hardware_end_of_life_reached']['gaps']
        );
        $this->assertContains('Computer:' . $oldPc, $gaps);
        $this->assertNotContains('Computer:' . $recentPc, $gaps);

        // Configuration : modèles en service, datés d'abord ; effacer une date.
        $config = HardwareWarranty::modelConfigData();
        $fields = array_column($config['models'], 'date', 'field');
        $this->assertSame('2025-06-30', $fields['ComputerModel:' . $old]);
        $this->assertSame(0, HardwareWarranty::saveModelDates(['ComputerModel:' . $old => '']));
        $this->assertNull(HardwareModelEolStore::dateFor('ComputerModel', $old));

        $this->assertSame(
            $models,
            iterator_to_array($DB->request(['FROM' => 'glpi_computermodels', 'ORDER' => 'id']), false),
            'Aucun modèle de GLPI modifié.'
        );

        // Sans les droits : règle non évaluable.
        $saved = $_SESSION['glpiactiveprofile'][\Infocom::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][\Infocom::$rightname] = 0;
        try {
            $this->assertNull(HardwareWarranty::endOfLifeGaps(self::TODAY));
            $this->assertFalse(ConsistencyCheck::run(self::TODAY)['hardware_end_of_life_reached']['evaluated']);
        } finally {
            $_SESSION['glpiactiveprofile'][\Infocom::$rightname] = $saved;
        }
    }
}
