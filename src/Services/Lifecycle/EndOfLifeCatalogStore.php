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

namespace GlpiPlugin\Grcmanager\Services\Lifecycle;

use Config;
use GlpiPlugin\Grcmanager\Install\EndOfLifeCatalogTables;

/**
 * Issue #1056 : catalogue des produits d'endoflife.date (pour proposer des correspondances) et
 * refus des propositions. Le catalogue n'est relu que par la tâche automatique ou le bouton
 * « Mettre à jour maintenant », au plus une fois par semaine (trois requêtes : /products,
 * /identifiers/cpe, /identifiers/purl ; https://endoflife.date/docs/api/v1/), jamais à
 * l'affichage d'une page.
 *
 * NOTE : dépend du $DB et de Config de GLPI, hors de l'analyse en PHP pur (phpstan.neon.dist).
 *
 * @phpstan-import-type Product from EndOfLifeCatalog
 */
final class EndOfLifeCatalogStore
{
    public const KEY_SUCCESS = 'endoflife_catalog_success';

    /** Durée de validité du catalogue (il change peu : quelques produits par mois). */
    public const TTL_SECONDS = 7 * 86400;

    /** Résultats de refreshIfStale(). */
    public const FRESH = 'fresh';
    public const UPDATED = 'updated';
    public const UNAVAILABLE = 'unavailable';

    /** @var array<string, Product>|null */
    private static ?array $catalog = null;

    public static function reset(): void
    {
        self::$catalog = null;
    }

    public static function lastSuccess(): string
    {
        $values = Config::getConfigurationValues(EndOfLifeStore::CONFIG_CONTEXT, [self::KEY_SUCCESS]);

        return (string) ($values[self::KEY_SUCCESS] ?? '');
    }

    /**
     * Relit le catalogue s'il a plus d'une semaine. Jamais d'exception : en cas d'erreur, le
     * catalogue déjà connu reste utilisé et le code d'erreur est renvoyé.
     */
    public static function refreshIfStale(EndOfLifeClient $client, string $now): string
    {
        $last = self::lastSuccess();
        $lastTime = $last === '' ? false : strtotime($last);
        $nowTime = strtotime($now);
        if ($lastTime !== false && $nowTime !== false && $lastTime > $nowTime - self::TTL_SECONDS) {
            return self::FRESH;
        }
        if (!$client->hasCatalog()) {
            return self::UNAVAILABLE;
        }
        try {
            $products = $client->json('products');
            $cpe = $client->json('identifiers/cpe');
            $purl = $client->json('identifiers/purl');
            $catalog = EndOfLifeCatalog::build($products, $cpe, $purl);
        } catch (EndOfLifeException $e) {
            return $e->getMessage();
        }
        if ($catalog === []) {
            return EndOfLifeException::UNEXPECTED;
        }
        self::save($catalog, $now);

        return self::UPDATED;
    }

    /**
     * Remplace le catalogue enregistré.
     *
     * @param array<string, Product> $catalog
     */
    public static function save(array $catalog, string $now): void
    {
        global $DB;

        $known = [];
        foreach ($DB->request(['SELECT' => ['id', 'product'], 'FROM' => EndOfLifeCatalogTables::CATALOG]) as $row) {
            $known[(string) $row['product']] = (int) $row['id'];
        }
        foreach ($catalog as $product => $entry) {
            $values = [
                'label'    => $entry['label'],
                'category' => $entry['category'],
                'terms'    => (string) json_encode($entry['terms'], JSON_UNESCAPED_UNICODE),
                'date_mod' => $now,
            ];
            if (isset($known[$product])) {
                $DB->update(EndOfLifeCatalogTables::CATALOG, $values, ['id' => $known[$product]]);
                unset($known[$product]);
            } else {
                $DB->insert(EndOfLifeCatalogTables::CATALOG, ['product' => $product] + $values);
            }
        }
        if ($known !== []) {
            $DB->delete(EndOfLifeCatalogTables::CATALOG, ['id' => array_values($known)]);
        }
        Config::setConfigurationValues(EndOfLifeStore::CONFIG_CONTEXT, [self::KEY_SUCCESS => $now]);
        self::reset();
    }

    /**
     * @return array<string, Product>
     */
    public static function catalog(): array
    {
        global $DB;

        if (self::$catalog === null) {
            self::$catalog = [];
            foreach ($DB->request(['FROM' => EndOfLifeCatalogTables::CATALOG, 'ORDER' => 'product']) as $row) {
                $terms = json_decode((string) $row['terms'], true);
                $valid = [];
                foreach (is_array($terms) ? $terms : [] as $term) {
                    if (is_array($term) && is_string($term['term'] ?? null) && is_int($term['level'] ?? null)) {
                        $valid[] = ['term' => $term['term'], 'level' => $term['level'],
                            'source' => (string) ($term['source'] ?? '')];
                    }
                }
                self::$catalog[(string) $row['product']] = [
                    'label'    => (string) $row['label'],
                    'category' => (string) $row['category'],
                    'terms'    => $valid,
                ];
            }
        }

        return self::$catalog;
    }

    /**
     * @return array<string, true> EndOfLifeCatalog::rejectKey() => true.
     */
    public static function rejected(): array
    {
        global $DB;

        $keys = [];
        foreach ($DB->request(['FROM' => EndOfLifeCatalogTables::REJECTS]) as $row) {
            $keys[EndOfLifeCatalog::rejectKey((string) $row['kind'], (string) $row['name'], (string) $row['product'])] = true;
        }

        return $keys;
    }

    public static function reject(string $kind, string $name, string $product, int $usersId): void
    {
        global $DB;

        if (!in_array($kind, ProductMatcher::KINDS, true) || !EndOfLifeClient::isValidProduct($product)) {
            return;
        }
        $DB->insert(EndOfLifeCatalogTables::REJECTS, [
            'kind'          => $kind,
            'name'          => mb_substr(trim($name), 0, 255),
            'product'       => $product,
            'users_id'      => $usersId,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }
}
