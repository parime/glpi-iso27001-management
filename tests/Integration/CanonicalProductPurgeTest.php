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

use PluginGrcmanagerCanonicalProduct as Product;
use PluginGrcmanagerCpeReference as Cpe;
use PluginGrcmanagerProductAlias as Alias;

/**
 * Supprimer un produit du catalogue laissait ses CPE et alias orphelins, qui bloquaient ensuite
 * la saisie du même identifiant (clé unique, erreur 500).
 */
final class CanonicalProductPurgeTest extends GrcmanagerIntegrationTestCase
{
    private const CPE = 'cpe:2.3:a:phpunit:purge:*:*:*:*:*:*:*:*';

    public function testPurgeRemovesIdentifiersAndOrphansDoNotBlockANewEntry(): void
    {
        global $DB;

        $productId = (int) (new Product())->add(['manufacturer' => 'PHPUnit', 'product' => 'Produit purgé']);
        $this->assertGreaterThan(0, (int) (new Cpe())->add([
            'plugin_grcmanager_canonicalproducts_id' => $productId,
            'cpe'                                    => self::CPE,
        ]));
        $this->assertGreaterThan(0, (int) (new Alias())->add([
            'plugin_grcmanager_canonicalproducts_id' => $productId,
            'alias'                                  => 'PHPUnit produit purgé',
        ]));

        (new Product())->delete(['id' => $productId], true);
        $this->assertSame(0, countElementsInTable(Cpe::getTable(), ['cpe' => self::CPE]));
        $this->assertSame(0, countElementsInTable(Alias::getTable(), ['alias' => 'PHPUnit produit purgé']));

        // Orphelin laissé par une version antérieure : la nouvelle saisie le remplace.
        $DB->insert(Cpe::getTable(), ['plugin_grcmanager_canonicalproducts_id' => 999999999, 'cpe' => self::CPE]);
        $otherId = (int) (new Product())->add(['manufacturer' => 'PHPUnit', 'product' => 'Autre produit']);
        $cpeId = (int) (new Cpe())->add([
            'plugin_grcmanager_canonicalproducts_id' => $otherId,
            'cpe'                                    => self::CPE,
        ]);
        $this->assertGreaterThan(0, $cpeId);
        $this->assertSame(1, countElementsInTable(Cpe::getTable(), ['cpe' => self::CPE]));

        // Un CPE rattaché à un produit existant reste refusé, avec un message.
        $this->assertFalse((new Cpe())->add([
            'plugin_grcmanager_canonicalproducts_id' => $otherId,
            'cpe'                                    => self::CPE,
        ]));
    }
}
