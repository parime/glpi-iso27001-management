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
use GlpiPlugin\Grcmanager\SupplierSecurityTab;
use Supplier;

/**
 * Issue #1095 : un risque général se lie à un fournisseur GLPI, par la même table de liaison que les
 * actifs de la CMDB (données fictives). Le lien se voit dans les deux sens, reste en place pour une
 * personne qui ne lit pas les fournisseurs, et disparaît avec le fournisseur.
 */
final class RiskSupplierLinkTest extends GrcmanagerIntegrationTestCase
{
    private function supplier(string $name, int $entity = 0): int
    {
        $id = (int) (new Supplier())->add(['name' => $name, 'entities_id' => $entity]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function linkedKeys(int $riskId): array
    {
        return array_map(
            static fn (array $link): string => $link['itemtype'] . ':' . $link['items_id'],
            Risk::getLinkedAssets($riskId)
        );
    }

    public function testSupplierIsLinkableButNotACmdbAsset(): void
    {
        $this->assertContains(Supplier::class, Risk::getRiskLinkItemtypes());
        $this->assertNotContains(Supplier::class, Risk::getLinkableItemtypes(), 'Pas un actif de la CMDB.');
    }

    public function testRiskLinksSupplierBothWaysAndFormReplacesTheLink(): void
    {
        $supplierId = $this->supplier('PHPUnit hébergeur fictif 1095');
        $other = $this->supplier('PHPUnit infogérant fictif 1095');
        $risk = new Risk();
        $riskId = (int) $risk->add([
            'title' => 'PHPUnit panne de l\'hébergeur 1095', 'entities_id' => 0,
            'linked_items_Supplier' => [$supplierId],
        ]);
        $this->assertGreaterThan(0, $riskId);

        $this->assertSame(['Supplier:' . $supplierId], $this->linkedKeys($riskId));
        $this->assertSame([$riskId], array_column(Risk::getRisksLinkedToSupplier($supplierId), 'id'));
        $this->assertSame([], Risk::getRisksLinkedToSupplier($other));

        // Le formulaire envoie la nouvelle sélection : l'ancien fournisseur est retiré.
        $this->assertTrue($risk->update(['id' => $riskId, 'linked_items_Supplier' => [$other]]));
        $this->assertSame(['Supplier:' . $other], $this->linkedKeys($riskId));
        $this->assertSame([], Risk::getRisksLinkedToSupplier($supplierId));
    }

    public function testSecurityTabListsGeneralRisksSeparately(): void
    {
        $supplierId = $this->supplier('PHPUnit centre de données fictif 1095');
        $riskId = (int) (new Risk())->add([
            'title' => 'PHPUnit coupure du centre de données 1095', 'entities_id' => 0,
            'linked_items_Supplier' => [$supplierId],
        ]);

        $data = SupplierSecurityTab::tabData($supplierId, '2026-01-15');
        $this->assertSame([], $data['risks'], 'Aucun risque fournisseur : section séparée.');
        $this->assertSame(['PHPUnit coupure du centre de données 1095'], array_column($data['general_risks'], 'title'));
        $this->assertSame(Risk::getFormURLWithID($riskId), $data['general_risks'][0]['url']);
    }

    public function testLinkKeptAndHiddenWithoutSupplierRight(): void
    {
        $supplierId = $this->supplier('PHPUnit prestataire fictif 1095');
        $risk = new Risk();
        $riskId = (int) $risk->add([
            'title' => 'PHPUnit fuite chez le prestataire 1095', 'entities_id' => 0,
            'linked_items_Supplier' => [$supplierId],
        ]);

        $saved = $_SESSION['glpiactiveprofile']['contact_enterprise'] ?? 0;
        $_SESSION['glpiactiveprofile']['contact_enterprise'] = 0;
        try {
            $this->assertSame([], $this->linkedKeys($riskId), 'Fournisseur non lisible : jamais affiché.');
            // Sa sélection n'est pas affichée : l'enregistrement du formulaire ne retire pas le lien.
            $this->assertTrue($risk->update(['id' => $riskId, 'linked_items_Computer' => []]));
        } finally {
            $_SESSION['glpiactiveprofile']['contact_enterprise'] = $saved;
        }
        $this->assertSame(['Supplier:' . $supplierId], $this->linkedKeys($riskId));
    }

    public function testRisksOfOtherEntitiesAreNotListedOnTheSupplier(): void
    {
        $entity = $this->createTestEntity(0, 'PHPUnit entité fournisseurs 1095');
        $supplierId = $this->supplier('PHPUnit éditeur fictif 1095');
        $riskId = (int) (new Risk())->add([
            'title' => 'PHPUnit risque d\'une autre entité 1095', 'entities_id' => $entity,
            'linked_items_Supplier' => [$supplierId],
        ]);
        $this->assertSame([$riskId], array_column(Risk::getRisksLinkedToSupplier($supplierId), 'id'));

        $saved = [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']];
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpiactiveentities_string'] = self::entitiesString([0]);
        \GlpiPlugin\Grcmanager\EntityPerimeter::reset();
        try {
            $this->assertSame([], Risk::getRisksLinkedToSupplier($supplierId));
        } finally {
            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']] = $saved;
            \GlpiPlugin\Grcmanager\EntityPerimeter::reset();
        }
    }

    public function testPurgedSupplierLeavesNoLink(): void
    {
        global $DB;

        $supplierId = $this->supplier('PHPUnit fournisseur supprimé 1095');
        $riskId = (int) (new Risk())->add([
            'title' => 'PHPUnit dépendance au fournisseur 1095', 'entities_id' => 0,
            'linked_items_Supplier' => [$supplierId],
        ]);
        $this->assertSame(1, countElementsInTable('glpi_plugin_grcmanager_risks_items', [
            'itemtype' => Supplier::class, 'items_id' => $supplierId,
        ]));

        $this->assertTrue((new Supplier())->delete(['id' => $supplierId], true));
        $this->assertSame(0, countElementsInTable('glpi_plugin_grcmanager_risks_items', [
            'itemtype' => Supplier::class, 'items_id' => $supplierId,
        ]));
        $this->assertSame([], $this->linkedKeys($riskId));
        $this->assertTrue($DB->tableExists('glpi_plugin_grcmanager_risks_items'));
    }
}
