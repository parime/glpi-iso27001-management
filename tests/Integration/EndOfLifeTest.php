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

use GlpiPlugin\Grcmanager\EndOfLife;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeClient;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;

/**
 * Issue #704 : fin de support des logiciels et systèmes du parc (endoflife.date). Logiciels,
 * ordinateurs et produits fictifs ; le site n'est jamais appelé (faux serveur injecté).
 */
final class EndOfLifeTest extends GrcmanagerIntegrationTestCase
{
    private const TODAY = '2026-11-18';

    private string $software;

    private string $product;

    private ?int $softwareId = null;

    private int $hiddenEntity;

    /** @var list<int> */
    private array $savedEntities;

    protected function setUp(): void
    {
        parent::setUp();
        EndOfLifeStore::reset();
        $this->savedEntities = $_SESSION['glpiactiveentities'];
        $suffix = (string) random_int(1000, 9999);
        $this->software = 'PhpUnitDemo EolSoft ' . $suffix;
        $this->product = 'phpunitdemo-eol-' . $suffix;
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo entité B ' . $suffix);
        $this->setActiveEntities(array_values(array_diff($_SESSION['glpiactiveentities'], [$this->hiddenEntity])));
        EndOfLifeStore::save(true, 90);
    }

    protected function tearDown(): void
    {
        $this->setActiveEntities($this->savedEntities);
        parent::tearDown();
        EndOfLifeStore::reset();
    }

    /**
     * @param list<int> $entities
     */
    private function setActiveEntities(array $entities): void
    {
        $_SESSION['glpiactiveentities'] = $entities;
        $_SESSION['glpiactiveentities_string'] = implode("','", $entities);
    }

    /**
     * Ordinateur fictif avec le logiciel installé dans cette version.
     */
    private function computerWith(string $version, int $entityId = 0): int
    {
        // Un seul logiciel, plusieurs versions (comme l'inventaire).
        $this->softwareId ??= (int) (new \Software())->add([
            'name' => $this->software, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $softwareId = $this->softwareId;
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

    /**
     * Cycles fictifs du produit, enregistrés comme par la tâche automatique.
     */
    private function seedCycles(): void
    {
        EndOfLifeStore::applyWrites([[
            'product' => $this->product,
            'cycles'  => [
                ['cycle' => '3', 'codename' => '', 'eol' => '2027-12-31', 'latest' => '3.4'],
                ['cycle' => '2', 'codename' => '', 'eol' => '2026-12-01', 'latest' => '2.9'],
                ['cycle' => '1', 'codename' => '', 'eol' => '2025-01-01', 'latest' => '1.8'],
            ],
            'error'   => '',
            'success' => true,
        ]], '2026-11-18 02:00:00');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mine(): array
    {
        return array_values(array_filter(
            EndOfLife::trackedItems(self::TODAY),
            fn (array $item): bool => $item['name'] === $this->software
        ));
    }

    public function testUnlinkedNameIsNeverGuessed(): void
    {
        $this->computerWith('1.2');
        $this->seedCycles();

        $this->assertSame([], $this->mine(), 'Sans correspondance, le logiciel n\'est pas suivi.');
        $names = array_column(EndOfLife::configData()['unlinked'], 'name');
        $this->assertContains($this->software, $names, 'Proposé comme non rapproché dans la configuration.');
    }

    public function testStatusPerVersionOnlyInTheUserEntities(): void
    {
        $old = $this->computerWith('1.2');
        $soon = $this->computerWith('2.5');
        $current = $this->computerWith('3.1');
        $hidden = $this->computerWith('1.2', $this->hiddenEntity);
        $this->assertTrue(EndOfLifeStore::saveMapping('software', strtolower($this->software), $this->product));
        $this->assertFalse(EndOfLifeStore::saveMapping('software', $this->software, 'Not a product!'));

        // Jamais lu : support inconnu, jamais « supporté ».
        $this->assertSame(['unknown'], array_values(array_unique(array_map(
            static fn (array $i): string => $i['evaluation']['status'],
            $this->mine()
        ))));

        $this->seedCycles();
        $byComputer = [];
        foreach ($this->mine() as $item) {
            $byComputer[$item['items_id']] = $item['evaluation']['status'];
        }
        $this->assertSame([$old => 'eol', $soon => 'soon', $current => 'supported'], $byComputer);
        $this->assertArrayNotHasKey($hidden, $byComputer, 'Ordinateur de l\'entité B jamais nommé.');

        $page = EndOfLife::pageData(false, self::TODAY);
        $groups = array_values(array_filter($page['groups'], fn (array $g): bool => $g['name'] === $this->software));
        $this->assertSame(['eol', 'soon'], array_column($groups, 'status_code'), 'Vue par défaut.');
        $this->assertSame(1, $groups[0]['count']);

        $csv = EndOfLife::csvRows(self::TODAY);
        $lines = array_values(array_filter($csv, fn (array $row): bool => ($row[4] ?? '') === $this->software));
        $this->assertCount(3, $lines);
        $this->assertSame($this->product, $lines[0][6]);

        $assets = EndOfLife::unsupportedAssets(self::TODAY) ?? [];
        $ids = array_column(array_values(array_filter(
            $assets,
            fn (array $a): bool => in_array($this->software . ' 1.2', $a['details'], true)
        )), 'items_id');
        $this->assertSame([$old], $ids, 'Contrôle de cohérence : seulement l\'ordinateur visible.');

        // Sans droit de lire les ordinateurs, rien n'est nommé.
        $right = $_SESSION['glpiactiveprofile'][\Computer::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][\Computer::$rightname] = 0;
        try {
            $this->assertSame([], $this->mine());
        } finally {
            $_SESSION['glpiactiveprofile'][\Computer::$rightname] = $right;
        }
    }

    public function testManualLinkCanStopAnAutomaticOne(): void
    {
        $this->assertSame('debian', \GlpiPlugin\Grcmanager\Services\Lifecycle\ProductMatcher::resolve(
            'os',
            'Debian GNU/Linux',
            EndOfLifeStore::manualMap()
        )['product']);
        $this->assertTrue(EndOfLifeStore::saveMapping('os', 'Debian GNU/Linux', ''));
        $this->assertSame('', \GlpiPlugin\Grcmanager\Services\Lifecycle\ProductMatcher::resolve(
            'os',
            'Debian GNU/Linux',
            EndOfLifeStore::manualMap()
        )['product']);
    }

    public function testRefreshNeverFailsOnRateLimitAndKeepsTheCache(): void
    {
        $this->computerWith('1.2');
        EndOfLifeStore::saveMapping('software', $this->software, $this->product);
        $this->seedCycles();
        // Cache vieux de plus d'un jour : à relire.
        global $DB;
        $DB->update(
            EndOfLifeStore::CACHE_TABLE,
            ['date_success' => '2026-01-01 00:00:00'],
            ['product' => $this->product]
        );
        EndOfLifeStore::reset();

        $calls = [];
        $transport = static function (string $url) use (&$calls): array {
            $calls[] = $url;

            return ['status' => 429, 'body' => '', 'retry_after' => 60];
        };
        $client = new EndOfLifeClient('https://eol.example.test/{product}.json', $transport);
        $report = EndOfLife::refresh(100, $client);

        $this->assertSame('partial', $report['status']);
        $this->assertLessThanOrEqual(1, count($calls), 'Plus aucun appel après la limite de requêtes.');
        $this->assertCount(3, EndOfLifeStore::cache()[$this->product]['cycles'] ?? [], 'Cycles déjà connus gardés.');
        $this->assertNotSame('', EndOfLife::refreshMessage($report));

        EndOfLifeStore::save(false, 90);
        $this->assertSame('disabled', EndOfLife::refresh(100, $client)['status'], 'Source désactivée : aucun appel.');
        $this->assertNull(EndOfLife::evidence('A.8.8'));
    }

    public function testProductsInUseForTheDailyTask(): void
    {
        $this->computerWith('1.2', $this->hiddenEntity);
        EndOfLifeStore::saveMapping('software', $this->software, $this->product);

        $this->assertContains($this->product, EndOfLifeInventory::productsInUse(), 'Tâche : tout le parc.');
        $this->assertNotNull(EndOfLife::evidence('A.8.9'));
        $this->assertNull(EndOfLife::evidence('A.5.1'));
    }
}
