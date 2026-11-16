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
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;
use GlpiPlugin\Grcmanager\SoaVersion;
use Migration;

/**
 * Issue #536 : périmètres multiples. Deux filiales fictives sous l'entité racine ; les risques, les
 * mesures de la SoA et les versions approuvées suivent les droits d'entité de GLPI, et le
 * consolidé de la racine voit les deux filiales. Assertions sur les identifiants et les codes,
 * jamais sur des libellés traduits (la CI tourne en anglais, l'instance de dev en français).
 */
final class EntityPerimeterTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $savedSession = [];

    private int $north = 0;

    private int $south = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $keys = ['glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiparententities'];
        foreach ($keys as $key) {
            $this->savedSession[$key] = $_SESSION[$key] ?? null;
        }
        $_SESSION['glpiactive_entity'] = 0;
        $this->north = $this->createTestEntity(0, 'PHPUnit Filiale Nord');
        $this->south = $this->createTestEntity(0, 'PHPUnit Filiale Sud');
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
     * Session d'un profil limité à une seule entité (sans sous-entité).
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
     * Session de la racine en vue arborescente (les deux filiales comprises).
     */
    private function rootTree(): void
    {
        $entities = [0, $this->north, $this->south];
        $_SESSION['glpiactive_entity'] = 0;
        $_SESSION['glpiactiveentities'] = $entities;
        $_SESSION['glpiactiveentities_string'] = implode("','", $entities);
        $_SESSION['glpiparententities'] = [];
        EntityPerimeter::reset();
    }

    private function risk(int $entityId, string $title, string $level = 'high'): int
    {
        $risk = new Risk();
        $id = (int) $risk->add([
            'title'       => $title,
            'entities_id' => $entityId,
            'probability' => 'possible',
            'impact'      => $level === 'critical' ? 'critical' : 'high',
            'status'      => 'identified',
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @return list<int>
     */
    private function visibleRiskIds(): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Risk::getTable(),
                'WHERE'  => EntityPerimeter::criteria(Risk::getTable()),
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function testARiskBelongsToItsEntityAndIsHiddenFromTheOther(): void
    {
        $this->rootTree();
        $north = $this->risk($this->north, 'PHPUnit risque Nord');
        $south = $this->risk($this->south, 'PHPUnit risque Sud');

        $this->limitTo($this->north);
        $visible = $this->visibleRiskIds();
        $this->assertContains($north, $visible);
        $this->assertNotContains($south, $visible);
        $this->assertTrue((new Risk())->can($north, READ));
        $this->assertFalse((new Risk())->can($south, READ), 'fiche du risque de l\'autre filiale');

        // Création forcée dans l'autre filiale : refusée.
        $this->assertFalse((new Risk())->add([
            'title' => 'PHPUnit risque forcé', 'entities_id' => $this->south, 'probability' => 'possible',
            'impact' => 'low',
        ]));

        // Cartes du tableau de bord : seuls les risques de la filiale comptent.
        $this->limitTo($this->south);
        $before = DashboardCardService::openRisksCount()['number'];
        $this->rootTree();
        $this->risk($this->north, 'PHPUnit autre risque Nord');
        $this->limitTo($this->south);
        $this->assertSame($before, DashboardCardService::openRisksCount()['number']);
    }

    public function testAnEntityFollowsTheCommonSoaUntilItCreatesItsOwn(): void
    {
        global $DB;

        $this->limitTo($this->north);
        $this->assertSame(0, EntityPerimeter::soaEntity());
        $this->assertTrue(EntityPerimeter::canCreateSoa($this->north));
        $common = EntityPerimeter::controlIdsByCode();
        $this->assertCount(count(ControlCatalogDefaults::CONTROLS), $common);

        // Une mesure non applicable de la SoA commune garde sa justification dans la copie.
        $DB->update(Control::getTable(), [
            'applicability' => 'no', 'justification' => 'PHPUnit justification commune',
        ], ['id' => $common['A.7.4']]);

        $this->assertSame(count(ControlCatalogDefaults::CONTROLS), EntityPerimeter::createSoa($this->north));
        $this->assertSame($this->north, EntityPerimeter::soaEntity());
        $this->assertFalse(EntityPerimeter::canCreateSoa($this->north), 'une seule SoA par entité');

        $own = EntityPerimeter::controlIdsByCode();
        $this->assertCount(count(ControlCatalogDefaults::CONTROLS), $own);
        $this->assertSame([], array_intersect($own, $common));
        $copy = new Control();
        $this->assertTrue($copy->getFromDB($own['A.7.4']));
        $this->assertSame('no', $copy->fields['applicability']);
        $this->assertSame('PHPUnit justification commune', $copy->fields['justification']);
        $this->assertSame('not_started', $copy->fields['implementation_status']);
        $this->assertSame(0, (int) $copy->fields['is_reviewed'], 'chaque mesure reste à revoir');
        $this->assertSame(1, (int) $copy->fields['is_recursive']);

        // Recherche, listes déroulantes et API : la SoA propre seulement, jamais la commune en double.
        $this->assertSame(
            ['glpi_plugin_grcmanager_controls.entities_id' => [$this->north]],
            Control::getSystemSQLCriteria(Control::getTable())
        );

        // La filiale Sud suit toujours la SoA commune, qu'elle lit sans pouvoir la modifier.
        $this->limitTo($this->south);
        $this->assertSame(0, EntityPerimeter::soaEntity());
        $this->assertSame($common, EntityPerimeter::controlIdsByCode());
        $this->assertTrue((new Control())->can($common['A.5.1'], READ));
        $this->assertFalse((new Control())->can($common['A.5.1'], UPDATE), 'SoA commune en lecture seule');
        $this->assertFalse((new Control())->can($own['A.5.1'], READ), 'SoA de la filiale Nord');

        // La racine en vue arborescente consolide les deux SoA.
        $this->rootTree();
        $this->assertEqualsCanonicalizing([0, $this->north], EntityPerimeter::activeSoaEntities());
    }

    public function testSoaVersionsAndFingerprintsArePerEntity(): void
    {
        global $DB;

        $this->limitTo($this->north);
        EntityPerimeter::createSoa($this->north);
        $northPrint = SoaVersion::currentFingerprint();

        foreach ([[0, 7], [$this->north, 1]] as [$entityId, $number]) {
            $DB->insert(SoaVersion::getTable(), [
                'entities_id'   => $entityId,
                'is_recursive'  => 1,
                'version'       => $number,
                'status'        => SoaApprovalLogic::VERSION_APPROVED,
                'fingerprint'   => SoaVersion::currentFingerprint($entityId),
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->assertSame(1, (int) SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED)['version']);
        $this->limitTo($this->south);
        $this->assertSame(7, (int) SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED)['version']);

        // Une modification de la SoA de Nord ne change pas l'empreinte de la SoA commune.
        $common = SoaVersion::currentFingerprint(0);
        $this->limitTo($this->north);
        $control = new Control();
        $control->getFromDB(EntityPerimeter::controlIdsByCode()['A.5.1']);
        $control->update(['id' => $control->getID(), 'implementation_status' => 'verified']);
        $this->assertNotSame($northPrint, SoaVersion::currentFingerprint());
        $this->assertSame($common, SoaVersion::currentFingerprint(0));
    }

    public function testTheParentConsolidationSeesBothChildren(): void
    {
        $this->rootTree();
        $critical = $this->risk($this->north, 'PHPUnit risque Nord 1', 'critical');
        $this->risk($this->north, 'PHPUnit risque Nord 2');
        $level = (string) Risk::getById($critical)->fields['risk_level'];
        $this->risk($this->south, 'PHPUnit risque Sud');
        $this->limitTo($this->north);
        EntityPerimeter::createSoa($this->north);
        $control = new Control();
        $control->getFromDB(EntityPerimeter::controlIdsByCode()['A.5.1']);
        $control->update(['id' => $control->getID(), 'implementation_status' => 'implemented']);

        $this->rootTree();
        $rows = [];
        foreach (EntityPerimeter::consolidation() as $row) {
            $rows[(int) $row['entities_id']] = $row;
        }
        $this->assertArrayHasKey(0, $rows);
        $this->assertTrue($rows[0]['is_self']);
        $this->assertArrayHasKey($this->north, $rows);
        $this->assertArrayHasKey($this->south, $rows);

        $this->assertTrue($rows[$this->north]['own_soa']);
        $this->assertFalse($rows[$this->south]['own_soa']);
        $this->assertSame(0, $rows[$this->south]['soa_entities_id']);
        $this->assertGreaterThan(0.0, (float) $rows[$this->north]['rate']);
        $this->assertSame(2, $rows[$this->north]['risks']['open']);
        $this->assertGreaterThanOrEqual(1, $rows[$this->north]['risks'][$level]);
        $this->assertSame(1, $rows[$this->south]['risks']['open']);

        // Une filiale seule ne voit pas la ligne de l'autre.
        $this->limitTo($this->north);
        $ids = array_map(static fn (array $row): int => (int) $row['entities_id'], EntityPerimeter::consolidation());
        $this->assertSame([$this->north], $ids);
    }

    /**
     * Migration rejouée : colonnes et clés uniques par entité en place, aucune ligne perdue.
     */
    public function testTheMigrationCanRunTwice(): void
    {
        global $DB;

        $counts = static fn (): array => [
            countElementsInTable(Risk::getTable()),
            countElementsInTable(Control::getTable()),
            countElementsInTable(SoaVersion::getTable()),
        ];
        $before = $counts();

        $installer = new Installer();
        $method = new \ReflectionMethod($installer, 'migrateEntityPerimeters');
        $method->invoke($installer, new Migration(PLUGIN_GRCMANAGER_VERSION));
        $method->invoke($installer, new Migration(PLUGIN_GRCMANAGER_VERSION));

        $this->assertSame($before, $counts());
        foreach ([Risk::getTable(), Control::getTable(), SoaVersion::getTable()] as $table) {
            $this->assertTrue($DB->fieldExists($table, 'entities_id'), $table);
            $this->assertTrue($DB->fieldExists($table, 'is_recursive'), $table);
        }
        $columns = new \ReflectionMethod($installer, 'indexColumns');
        $this->assertSame(['code', 'entities_id'], $columns->invoke($installer, Control::getTable(), 'unicity_code'));
        $this->assertSame(
            ['entities_id', 'version'],
            $columns->invoke($installer, SoaVersion::getTable(), 'unicity_version')
        );
        // La SoA commune reste dans l'entité racine, visible des sous-entités.
        $this->assertSame(0, countElementsInTable(Control::getTable(), ['entities_id' => 0, 'is_recursive' => 0]));
    }
}
