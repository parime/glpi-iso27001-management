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
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeCatalogStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeClient;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;

/**
 * Issue #1056 : correspondances proposées d'après le catalogue de l'API v1 d'endoflife.date
 * (https://endoflife.date/docs/api/v1/). Faux serveur : extraits enregistrés des réponses de
 * /products, /identifiers/cpe et /identifiers/purl (tests/Unit/.../fixtures/catalog-*.json), plus
 * un produit fictif aux champs du schéma ProductSummary. Le site n'est jamais appelé.
 */
final class EndOfLifeCatalogTest extends GrcmanagerIntegrationTestCase
{
    private string $suffix;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        EndOfLifeStore::reset();
        EndOfLifeCatalogStore::reset();
        $this->suffix = (string) random_int(1000, 9999);
        EndOfLifeStore::save(true, 90);
        // Catalogue jamais lu dans cette transaction.
        \Config::setConfigurationValues(EndOfLifeStore::CONFIG_CONTEXT, [EndOfLifeCatalogStore::KEY_SUCCESS => '']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        EndOfLifeStore::reset();
        EndOfLifeCatalogStore::reset();
    }

    /**
     * @return array<mixed>
     */
    private static function fixture(string $name): array
    {
        $path = __DIR__ . '/../Unit/Services/Lifecycle/fixtures/catalog-' . $name . '.json';

        return (array) json_decode((string) file_get_contents($path), true);
    }

    private function client(): EndOfLifeClient
    {
        $products = self::fixture('products');
        $products['result'][] = [
            'name'     => 'phpunitdemo-eol-' . $this->suffix,
            'label'    => 'PhpUnitDemo EolCat ' . $this->suffix,
            'aliases'  => ['phpunitdemo-alias-' . $this->suffix],
            'category' => 'app',
            'tags'     => ['app'],
            'uri'      => 'https://eol.example.test/api/v1/products/phpunitdemo-eol-' . $this->suffix,
        ];
        $answers = [
            '/products'         => $products,
            '/identifiers/cpe'  => self::fixture('cpe'),
            '/identifiers/purl' => self::fixture('purl'),
        ];
        $calls = &$this->calls;

        return new EndOfLifeClient(
            'https://eol.example.test/api/v1/products/{product}/',
            static function (string $url) use ($answers, &$calls): array {
                $calls[] = $url;
                foreach ($answers as $suffix => $body) {
                    if (str_ends_with($url, $suffix)) {
                        return ['status' => 200, 'body' => (string) json_encode($body), 'retry_after' => 0];
                    }
                }

                return ['status' => 404, 'body' => '<html></html>', 'retry_after' => 0];
            }
        );
    }

    private function software(string $name): void
    {
        $softwareId = (int) (new \Software())->add(['name' => $name, 'entities_id' => 0, 'is_recursive' => 1]);
        $versionId = (int) (new \SoftwareVersion())->add([
            'name' => '1.0', 'softwares_id' => $softwareId, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $computerId = (int) (new \Computer())->add(['name' => 'PC demo ' . random_int(1, 999999), 'entities_id' => 0]);
        (new \Item_SoftwareVersion())->add([
            'itemtype' => \Computer::class, 'items_id' => $computerId, 'softwareversions_id' => $versionId,
            'entities_id' => 0,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>> Nom => proposition.
     */
    private function proposals(): array
    {
        $rows = [];
        foreach (EndOfLife::suggestions() as $row) {
            $rows[$row['name']] = $row;
        }

        return $rows;
    }

    public function testCatalogIsReadOnceAWeekAndProposesWithoutApplying(): void
    {
        $label = 'PhpUnitDemo EolCat ' . $this->suffix;
        $alias = 'phpunitdemo-alias-' . $this->suffix;
        $this->software($label . ' 2.1.0');
        $this->software($alias);

        $report = EndOfLife::refresh(5, $this->client());
        $this->assertSame(EndOfLifeCatalogStore::UPDATED, $report['catalog']);
        $this->assertStringContainsString('catalogue', EndOfLife::refreshMessage($report));
        $catalogCalls = array_filter($this->calls, static fn (string $u): bool => !str_contains($u, '/products/'));
        $this->assertCount(3, $catalogCalls, 'Trois listes lues : produits, CPE, purl.');
        $this->assertArrayHasKey('ubuntu', EndOfLifeCatalogStore::catalog());

        $proposals = $this->proposals();
        $this->assertSame('phpunitdemo-eol-' . $this->suffix, $proposals[$label . ' 2.1.0']['candidates'][0]['product']);
        $this->assertSame('product', $proposals[$alias]['candidates'][0]['via']);
        $this->assertSame([], array_filter(
            EndOfLifeStore::mappings(),
            fn (array $m): bool => str_contains($m['name'], $this->suffix)
        ), 'Aucune correspondance enregistrée avant validation.');
        $data = EndOfLife::configData();
        $this->assertGreaterThanOrEqual(2, $data['suggestions_total']);
        $this->assertNotSame('', $data['catalog_date']);

        $this->calls = [];
        $this->assertSame(EndOfLifeCatalogStore::FRESH, EndOfLife::refresh(5, $this->client())['catalog']);
        $this->assertSame([], array_filter($this->calls, static fn (string $u): bool => !str_contains($u, '/products/')));
    }

    public function testAcceptSavesALinkAndRejectHidesTheProposal(): void
    {
        $label = 'PhpUnitDemo EolCat ' . $this->suffix;
        $alias = 'phpunitdemo-alias-' . $this->suffix;
        $this->software($label);
        $this->software($alias);
        EndOfLife::refresh(5, $this->client());
        $proposals = $this->proposals();
        $tokenA = (string) $proposals[$label]['token'];
        $tokenB = (string) $proposals[$alias]['token'];

        // Produit hors de la proposition : refusé, rien n'est enregistré.
        $this->assertSame(0, EndOfLife::reviewSuggestions(
            ['endoflife_suggestion' => [$tokenA], 'endoflife_choice' => [$tokenA => 'debian']],
            true
        ));
        $this->assertSame(1, EndOfLife::reviewSuggestions(['endoflife_suggestion' => [$tokenA]], true));
        $this->assertSame(
            'phpunitdemo-eol-' . $this->suffix,
            EndOfLifeStore::manualMap()['software' . "\0" . mb_strtolower($label)] ?? null,
            'Acceptée : correspondance manuelle.'
        );
        $this->assertSame(1, EndOfLife::reviewSuggestions(['endoflife_suggestion' => [$tokenB]], false));
        EndOfLifeStore::reset();
        $left = $this->proposals();
        $this->assertArrayNotHasKey($label, $left, 'Acceptée : plus proposée.');
        $this->assertArrayNotHasKey($alias, $left, 'Refusée : plus proposée.');
        $this->assertArrayNotHasKey(
            'software' . "\0" . mb_strtolower($alias),
            EndOfLifeStore::manualMap(),
            'Refusée : aucune correspondance.'
        );
    }
}
