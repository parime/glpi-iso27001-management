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

/**
 * Issue #704 : mise à jour du cache des cycles de vie, sans base de données (PHP pur). Décide quels
 * produits relire et ce qu'il faut enregistrer ; l'appelant écrit le résultat (EndOfLifeStore).
 *
 * - Produit lu avec succès depuis moins d'un jour : gardé tel quel, aucun appel.
 * - Produit inconnu de la source (404) : enregistré comme tel, résultat valable un jour.
 * - Limite de requêtes (429), panne du serveur ou source injoignable : les cycles déjà connus sont
 *   gardés, l'erreur est notée, et plus aucun appel n'est fait pendant cette mise à jour (les
 *   produits suivants sont « non interrogés »). Jamais d'exception : la tâche se termine normalement.
 * - Au plus $budget appels par mise à jour.
 *
 * @phpstan-import-type Cycle from EndOfLifeClient
 * @phpstan-type CacheRow array{date_success: string, error: string}
 * @phpstan-type Write array{product: string, cycles: list<Cycle>|null, error: string, success: bool}
 * @phpstan-type Report array{products: int, fetched: int, cached: int, not_found: int, requests: int,
 *     errors: list<array{product: string, code: string}>}
 */
final class CacheRefresh
{
    /** Durée de validité d'une lecture réussie. */
    public const TTL_SECONDS = 86400;

    /** Appels au plus par mise à jour. */
    public const DEFAULT_BUDGET = 100;

    /**
     * @param list<string> $products Produits en service.
     * @param array<string, CacheRow> $cache État actuel du cache, par produit.
     * @return array{writes: list<Write>, report: Report}
     */
    public static function run(
        array $products,
        array $cache,
        EndOfLifeClient $client,
        string $now,
        int $budget = self::DEFAULT_BUDGET
    ): array {
        $report = ['products' => 0, 'fetched' => 0, 'cached' => 0, 'not_found' => 0, 'requests' => 0, 'errors' => []];
        $writes = [];
        $blocked = false;
        $calls = 0;
        foreach (array_values(array_unique($products)) as $product) {
            $report['products']++;
            $cached = $cache[$product] ?? null;
            if ($cached !== null && self::isFresh($cached['date_success'], $now)) {
                $report['cached']++;
                if ($cached['error'] === EndOfLifeException::NOT_FOUND) {
                    $report['not_found']++;
                }
                continue;
            }
            if ($blocked || $calls >= $budget) {
                $report['errors'][] = ['product' => $product, 'code' => EndOfLifeException::SKIPPED];
                continue;
            }
            $calls++;
            try {
                $writes[] = ['product' => $product, 'cycles' => $client->cycles($product), 'error' => '',
                    'success' => true];
                $report['fetched']++;
            } catch (EndOfLifeException $e) {
                $code = $e->getMessage();
                if ($code === EndOfLifeException::NOT_FOUND) {
                    $writes[] = ['product' => $product, 'cycles' => [], 'error' => $code, 'success' => true];
                    $report['not_found']++;
                    continue;
                }
                // Cycles déjà connus gardés (cycles null), erreur notée.
                $writes[] = ['product' => $product, 'cycles' => null, 'error' => $code, 'success' => false];
                $report['errors'][] = ['product' => $product, 'code' => $code];
                $blocked = EndOfLifeException::stopsRefresh($code);
            }
        }
        $report['requests'] = $client->requests();

        return ['writes' => $writes, 'report' => $report];
    }

    public static function isFresh(string $dateSuccess, string $now): bool
    {
        $success = $dateSuccess === '' ? false : strtotime($dateSuccess);
        $current = strtotime($now);

        return $success !== false && $current !== false && $success > $current - self::TTL_SECONDS;
    }
}
