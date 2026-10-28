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

/**
 * Issue #164 : couverture des preuves sur des pare-feu fictifs, dans une entité de test isolée.
 */
final class CoverageRuleTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $sessionEntities = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['glpiactiveentities', 'glpiactiveentities_string'] as $key) {
            $this->sessionEntities[$key] = $_SESSION[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        // L'entité isolée de ce test disparaît au rollback : les tests suivants ne doivent pas en hériter.
        foreach ($this->sessionEntities as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testSearchListRuns(): void
    {
        $data = \Glpi\Search\SearchEngine::getData(
            \GlpiPlugin\Grcmanager\CoverageRule::class,
            ['criteria' => []],
            \GlpiPlugin\Grcmanager\Services\DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\CoverageRule::class]
        );
        $this->assertArrayHasKey('data', $data);
    }

    public function testFirewallsWithTheirFilteringPolicy(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Couverture');
        $_SESSION['glpiactiveentities'] = [$entityId];
        $_SESSION['glpiactiveentities_string'] = (string) $entityId;

        $typeId = (int) (new \NetworkEquipmentType())->add(['name' => 'Pare-feu PHPUnit']);
        $categoryId = (int) (new \DocumentCategory())->add(['name' => 'Politique de filtrage PHPUnit']);
        $firewalls = [];
        foreach (['FW-A', 'FW-B', 'FW-C'] as $name) {
            $firewalls[$name] = (int) (new \NetworkEquipment())->add([
                'name' => $name, 'entities_id' => $entityId, 'networkequipmenttypes_id' => $typeId,
            ]);
        }
        (new \NetworkEquipment())->add(['name' => 'SW-SANS-TYPE', 'entities_id' => $entityId]);

        $rule = new \GlpiPlugin\Grcmanager\CoverageRule();
        $this->assertFalse($rule->add(['name' => 'Sans catégorie', 'itemtype' => 'NetworkEquipment']));
        $ruleId = (int) $rule->add([
            'name'                  => 'Politique de filtrage des pare-feu',
            'itemtype'              => 'NetworkEquipment',
            'types_id'              => $typeId,
            'documentcategories_id' => $categoryId,
            'plugin_grcmanager_controls_id' => 1,
        ]);
        $this->assertGreaterThan(0, $ruleId);
        $rule->getFromDB($ruleId);

        $coverage = $rule->computeCoverage();
        $this->assertSame([3, 0, 0], [$coverage['total'], $coverage['covered'], $coverage['rate']], 'Sous-type seul.');

        foreach (['FW-A', 'FW-B'] as $name) {
            $documentId = (int) (new \Document())->add([
                'name' => 'Filtrage ' . $name, 'entities_id' => $entityId, 'documentcategories_id' => $categoryId,
            ]);
            (new \Document_Item())->add([
                'documents_id' => $documentId, 'itemtype' => 'NetworkEquipment', 'items_id' => $firewalls[$name],
                'entities_id' => $entityId,
            ]);
        }
        // Un document d'une autre catégorie ne couvre pas.
        $otherId = (int) (new \Document())->add(['name' => 'Autre', 'entities_id' => $entityId]);
        (new \Document_Item())->add([
            'documents_id' => $otherId, 'itemtype' => 'NetworkEquipment', 'items_id' => $firewalls['FW-C'],
            'entities_id' => $entityId,
        ]);

        $coverage = $rule->computeCoverage();
        $this->assertSame(
            [3, 2, 66, 'low'],
            [$coverage['total'], $coverage['covered'], $coverage['rate'], $coverage['level']]
        );
        $this->assertSame(['FW-C'], array_column($coverage['uncovered'], 'name'));
        $this->assertStringContainsString('forcetab=Document_Item', $coverage['uncovered'][0]['url']);

        $control = new \GlpiPlugin\Grcmanager\Control();
        $control->getFromDB(1);
        $this->assertCount(1, \GlpiPlugin\Grcmanager\CoverageRule::getForItem($control));
    }
}
