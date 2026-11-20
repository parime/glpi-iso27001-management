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
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\License\LicenseComplianceRules as Rules;
use GlpiPlugin\Grcmanager\Services\License\LicenseInventory;
use GlpiPlugin\Grcmanager\SoftwareLicenseCompliance;

/**
 * Issue #779 : conformité des licences logicielles (A.5.32) sur les objets natifs de GLPI.
 * Entités, logiciels, ordinateurs et licences fictifs.
 *
 * Entités : Siège (sous la racine) > Agence ; Filiale cachée (hors des entités de la session).
 */
final class SoftwareLicenseComplianceTest extends GrcmanagerIntegrationTestCase
{
    private const TODAY = '2026-11-20';

    private string $suffix;

    private int $head;

    private int $branch;

    private int $hidden;

    private int $microsoft;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        LicenseInventory::reset();
        SoftwareLicenseCompliance::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiparententities'        => $_SESSION['glpiparententities'] ?? null,
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->head = $this->createTestEntity(0, 'PhpUnitDemo Siège ' . $this->suffix);
        $this->branch = $this->createTestEntity($this->head, 'PhpUnitDemo Agence ' . $this->suffix);
        global $DB;
        // Ascendance réelle de l'agence (le gabarit des tests ne connaît que la racine).
        $DB->update(
            'glpi_entities',
            ['level' => 2, 'ancestors_cache' => json_encode([0 => 0, $this->head => $this->head])],
            ['id' => $this->branch]
        );
        $this->hidden = $this->createTestEntity(0, 'PhpUnitDemo Filiale ' . $this->suffix);
        // Session : Siège et Agence seulement ; la racine est une entité parente.
        $_SESSION['glpiactiveentities'] = [$this->head, $this->branch];
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
        $_SESSION['glpiparententities'] = [0 => 0];
        $this->microsoft = (int) (new \Manufacturer())->import(['name' => 'Microsoft Corporation']);
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
        LicenseInventory::reset();
        SoftwareLicenseCompliance::reset();
        parent::tearDown();
    }

    private function software(string $name, int $manufacturer): int
    {
        return (int) (new \Software())->add([
            'name' => 'PhpUnitDemo ' . $name . ' ' . $this->suffix, 'entities_id' => 0, 'is_recursive' => 1,
            'manufacturers_id' => $manufacturer,
        ]);
    }

    /**
     * Ordinateur fictif de l'entité, avec le logiciel installé.
     */
    private function computerWith(int $softwareId, int $entityId, string $version = '1.0'): int
    {
        $versionId = (int) (new \SoftwareVersion())->add([
            'name' => $version, 'softwares_id' => $softwareId, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $computerId = (int) (new \Computer())->add([
            'name' => 'PC demo ' . random_int(1, 999999), 'entities_id' => $entityId,
        ]);
        (new \Item_SoftwareVersion())->add([
            'itemtype' => \Computer::class, 'items_id' => $computerId, 'softwareversions_id' => $versionId,
            'entities_id' => $entityId,
        ]);

        return $computerId;
    }

    private function licence(int $softwareId, int $entityId, int $number, bool $recursive, ?string $expire = null): int
    {
        $input = [
            'name' => 'Licence demo ' . random_int(1, 999999), 'softwares_id' => $softwareId,
            'entities_id' => $entityId, 'is_recursive' => $recursive ? 1 : 0, 'number' => $number,
        ];
        if ($expire !== null) {
            $input['expire'] = $expire;
        }

        return (int) (new \SoftwareLicense())->add($input);
    }

    /**
     * Constats de ce test (logiciels fictifs), sous la forme « type:portée:entité:logiciel:postes ».
     *
     * @return list<string>
     */
    private function mine(): array
    {
        SoftwareLicenseCompliance::reset();
        $mine = [];
        foreach (SoftwareLicenseCompliance::findings(self::TODAY) as $finding) {
            if (!str_ends_with($finding['software_name'], ' ' . $this->suffix)) {
                continue;
            }
            $mine[] = $finding['type'] . ':' . $finding['scope'] . ':' . $finding['entity_id'] . ':'
                . $finding['software_id'] . ':' . $finding['installs'] . '/' . $finding['seats'];
        }
        sort($mine);

        return $mine;
    }

    /**
     * @return array<string, int>
     */
    private function glpiCounts(): array
    {
        $counts = [];
        foreach (
            [
                'glpi_softwares', 'glpi_softwareversions', 'glpi_items_softwareversions', 'glpi_softwarelicenses',
                'glpi_items_softwarelicenses', 'glpi_tickets', 'glpi_manufacturers',
            ] as $table
        ) {
            $counts[$table] = countElementsInTable($table);
        }

        return $counts;
    }

    public function testThreeFindingsInTheUserEntitiesOnly(): void
    {
        $suite = $this->software('Suite', $this->microsoft);
        $free = $this->software('Libre', 0);
        $cad = $this->software('CAO', $this->microsoft);

        // Logiciel commercial sans licence dans l'agence ; aussi dans la filiale cachée.
        $this->computerWith($suite, $this->branch);
        $this->computerWith($suite, $this->hidden);
        // La licence récursive de la filiale ne couvre pas l'agence (entité soeur) et reste invisible.
        $this->licence($suite, $this->hidden, 10, true);
        // Logiciel sans éditeur surveillé : jamais signalé.
        $this->computerWith($free, $this->branch);
        // Une licence récursive du siège d'un poste, deux installations dans l'agence.
        $pc1 = $this->computerWith($cad, $this->branch);
        $this->computerWith($cad, $this->branch, '2.0');
        $this->licence($cad, $this->head, 1, true);
        // Licence expirée de l'agence, à laquelle un ordinateur équipé est encore affecté.
        $expired = $this->licence($cad, $this->branch, 5, false, '2026-06-30');
        (new \Item_SoftwareLicense())->add([
            'itemtype' => \Computer::class, 'items_id' => $pc1, 'softwarelicenses_id' => $expired,
        ]);

        $before = $this->glpiCounts();
        $expected = [
            Rules::FINDING_EXPIRED . ':entity:' . $this->branch . ':' . $cad . ':2/5',
            Rules::FINDING_OVERDEPLOYED . ':entity:' . $this->branch . ':' . $cad . ':2/1',
            Rules::FINDING_UNLICENSED . ':entity:' . $this->branch . ':' . $suite . ':1/0',
        ];
        $this->assertSame($expected, $this->mine());
        $this->assertSame($before, $this->glpiCounts(), 'Rien n\'est créé ni modifié dans GLPI.');

        // Page : regroupée par entité, sans la filiale.
        $page = SoftwareLicenseCompliance::pageData(self::TODAY);
        $entities = array_column($page['groups'], 'entity');
        $this->assertNotContains('PhpUnitDemo Filiale ' . $this->suffix, $entities);
        $this->assertGreaterThanOrEqual(1, $page['counts'][Rules::FINDING_UNLICENSED]);

        // Export : une ligne par constat ; preuve et contrôle de cohérence.
        $csv = SoftwareLicenseCompliance::csvRows(self::TODAY);
        $lines = array_values(array_filter($csv, fn (array $r): bool => str_ends_with($r[3], ' ' . $this->suffix)));
        $this->assertCount(3, $lines);
        $evidence = SoftwareLicenseCompliance::evidence('A.5.32');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(1, $evidence['expired']);
        $this->assertNull(SoftwareLicenseCompliance::evidence('A.5.1'));

        $results = ConsistencyCheck::run(self::TODAY);
        $this->assertTrue($results['software_license_gap']['evaluated']);
        $gaps = array_map(
            static fn (array $gap): string => $gap['itemtype'] . ':' . $gap['id'],
            $results['software_license_gap']['gaps']
        );
        $this->assertContains(\Software::class . ':' . $suite, $gaps);
        $this->assertContains(\SoftwareLicense::class . ':' . $expired, $gaps);
    }

    public function testRecursiveParentLicenceCoversTheBranchAndUnlimitedIsRespected(): void
    {
        $suite = $this->software('Suite', $this->microsoft);
        $this->computerWith($suite, $this->branch);
        $this->computerWith($suite, $this->branch);
        // Non récursive au siège : ne compte pas pour l'agence.
        $this->licence($suite, $this->head, 5, false);
        $this->assertSame(
            [Rules::FINDING_UNLICENSED . ':entity:' . $this->branch . ':' . $suite . ':2/0'],
            $this->mine()
        );
        // Récursive et illimitée (nombre -1) : tout est couvert.
        $this->licence($suite, $this->head, -1, true);
        $this->assertSame([], $this->mine());
    }

    public function testWatchedPublishersCanBeChanged(): void
    {
        $suite = $this->software('Suite', $this->microsoft);
        $this->computerWith($suite, $this->branch);
        $this->assertCount(1, $this->mine());

        LicenseInventory::save("Autodesk\nAdobe", '');
        $this->assertSame(['Autodesk', 'Adobe'], LicenseInventory::settings()['publishers']);
        $this->assertSame([], $this->mine(), 'Éditeur retiré de la liste : plus signalé.');

        LicenseInventory::save('Microsoft', 'Suite');
        $this->assertSame([], $this->mine(), 'Logiciel ignoré par son nom.');

        LicenseInventory::reset();
        $this->assertSame(Rules::DEFAULT_PUBLISHERS, LicenseInventory::settings()['publishers']);
        $this->assertCount(1, $this->mine());
    }

    public function testNativeRightsAreRequired(): void
    {
        $suite = $this->software('Suite', $this->microsoft);
        $this->computerWith($suite, $this->branch);
        $this->assertCount(1, $this->mine());

        foreach ([\SoftwareLicense::$rightname, \Software::$rightname, 'plugin_grcmanager_sensitive'] as $right) {
            $saved = $_SESSION['glpiactiveprofile'][$right] ?? 0;
            $_SESSION['glpiactiveprofile'][$right] = 0;
            try {
                $this->assertFalse(SoftwareLicenseCompliance::canView(), $right);
                $this->assertSame([], $this->mine(), $right);
                $this->assertNull(SoftwareLicenseCompliance::evidence('A.5.32'), $right);
                $this->assertNull(SoftwareLicenseCompliance::consistencyGaps(self::TODAY), $right);
                $this->assertFalse(SoftwareLicenseCompliance::getMenuContent(), $right);
            } finally {
                $_SESSION['glpiactiveprofile'][$right] = $saved;
            }
        }

        // Sans droit de lire les ordinateurs, leurs installations ne sont pas lues.
        $saved = $_SESSION['glpiactiveprofile'][\Computer::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][\Computer::$rightname] = 0;
        try {
            $this->assertSame([], $this->mine());
        } finally {
            $_SESSION['glpiactiveprofile'][\Computer::$rightname] = $saved;
        }
    }

    public function testExpiringLicenceOverquotaAndCoverage(): void
    {
        $suite = $this->software('Suite', $this->microsoft);
        $cad = $this->software('CAO', $this->microsoft);
        $other = $this->software('Bureau', $this->microsoft);
        // Licence de l'agence qui expire dans 25 jours, encore utilisée.
        $this->computerWith($suite, $this->branch);
        $soon = $this->licence($suite, $this->branch, 5, false, '2026-12-15');
        // Surdéploiement sur une licence qui autorise le dépassement.
        $this->computerWith($cad, $this->branch);
        $this->computerWith($cad, $this->branch);
        $overquota = $this->licence($cad, $this->branch, 1, false);
        (new \SoftwareLicense())->update(['id' => $overquota, 'allow_overquota' => 1]);
        // Logiciel commercial sans licence : couverture 2 sur 3.
        $this->computerWith($other, $this->branch);

        $before = $this->glpiCounts();
        $this->assertSame([
            Rules::FINDING_EXPIRING . ':entity:' . $this->branch . ':' . $suite . ':1/5',
            Rules::FINDING_OVERDEPLOYED . ':entity:' . $this->branch . ':' . $cad . ':2/1',
            Rules::FINDING_UNLICENSED . ':entity:' . $this->branch . ':' . $other . ':1/0',
        ], $this->mine());
        $this->assertSame($before, $this->glpiCounts(), 'Aucune donnée de GLPI créée ni modifiée.');

        $mine = array_values(array_filter(
            SoftwareLicenseCompliance::findings(self::TODAY),
            fn (array $f): bool => $f['software_id'] === $cad
        ));
        $this->assertTrue($mine[0]['overquota_allowed']);

        // L'échéance proche n'est pas un écart du contrôle de cohérence.
        $gaps = array_map(
            static fn (array $gap): string => $gap['itemtype'] . ':' . $gap['items_id'],
            SoftwareLicenseCompliance::consistencyGaps(self::TODAY) ?? []
        );
        $this->assertNotContains(\SoftwareLicense::class . ':' . $soon, $gaps);
        $this->assertContains(\Software::class . ':' . $cad, $gaps);

        // Preuve : échéances comptées à part, couverture.
        $evidence = SoftwareLicenseCompliance::evidence('A.5.32');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(1, $evidence['expiring']);
        $this->assertNotNull($evidence['coverage']);
        $this->assertGreaterThanOrEqual(3, $evidence['coverage']['watched']);

        // Horizon réglable : 10 jours, l'échéance du 15/12 n'est plus signalée ; borné à 365.
        LicenseInventory::save('Microsoft', '', 10);
        $this->assertSame(10, LicenseInventory::settings()['expiring_days']);
        $this->assertNotContains(
            Rules::FINDING_EXPIRING . ':entity:' . $this->branch . ':' . $suite . ':1/5',
            $this->mine()
        );
        LicenseInventory::save('Microsoft', '', 9999);
        $this->assertSame(Rules::MAX_EXPIRING_DAYS, LicenseInventory::settings()['expiring_days']);
        LicenseInventory::reset();
        $this->assertSame(Rules::DEFAULT_EXPIRING_DAYS, LicenseInventory::settings()['expiring_days']);

        // Export : colonne du dépassement autorisé.
        $rows = array_values(array_filter(
            SoftwareLicenseCompliance::csvRows(self::TODAY),
            fn (array $r): bool => $r[3] === 'PhpUnitDemo CAO ' . $this->suffix
        ));
        $this->assertCount(1, $rows);
        $this->assertSame(__('Yes'), $rows[0][10]);
    }

    public function testCsvCellsCannotInjectFormulas(): void
    {
        $id = (int) (new \Software())->add([
            'name' => '=HYPERLINK("http://example.test") ' . $this->suffix, 'entities_id' => 0, 'is_recursive' => 1,
            'manufacturers_id' => $this->microsoft,
        ]);
        $this->computerWith($id, $this->branch);
        SoftwareLicenseCompliance::reset();
        $rows = array_values(array_filter(
            SoftwareLicenseCompliance::csvRows(self::TODAY),
            fn (array $r): bool => str_ends_with($r[3], ' ' . $this->suffix)
        ));
        $this->assertCount(1, $rows);
        $this->assertStringStartsWith("'=", ConsistencyRules::csvCell($rows[0][3]));
    }
}
