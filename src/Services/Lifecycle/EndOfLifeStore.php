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

/**
 * Issue #704 : réglages, correspondances manuelles et cache des cycles de vie (endoflife.date).
 *
 * - Réglages dans la configuration de GLPI (contexte du plugin) : source désactivée par défaut,
 *   seuil de « fin proche » en jours.
 * - Correspondances manuelles : un nom exact de GLPI (logiciel, système d'exploitation ou firmware)
 *   vers un produit endoflife.date, ou vers rien (« ne pas suivre »).
 * - Cache : les cycles de chaque produit, la dernière lecture réussie et la dernière erreur. Il
 *   n'est écrit que par la tâche automatique ou le bouton « Mettre à jour maintenant » de la
 *   configuration, jamais à l'affichage d'une page ; il reste lu tel quel hors ligne.
 *
 * NOTE : dépend du $DB et de Config de GLPI, hors de l'analyse en PHP pur (phpstan.neon.dist).
 *
 * @phpstan-import-type Cycle from EndOfLifeClient
 * @phpstan-import-type Write from CacheRefresh
 * @phpstan-type CacheEntry array{cycles: list<Cycle>|null, error: string, date_checked: string,
 *     date_success: string}
 */
final class EndOfLifeStore
{
    public const CACHE_TABLE = 'glpi_plugin_grcmanager_endoflifecycles';
    public const MAP_TABLE = 'glpi_plugin_grcmanager_endoflifemappings';

    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const KEY_ENABLED = 'endoflife_enabled';
    public const KEY_WARNING_DAYS = 'endoflife_warning_days';

    public const DEFAULT_WARNING_DAYS = 90;
    public const MIN_WARNING_DAYS = 1;
    public const MAX_WARNING_DAYS = 730;

    /** @var array<string, CacheEntry>|null */
    private static ?array $cache = null;

    /** @var array<string, string>|null */
    private static ?array $manual = null;

    /**
     * Oublie les données lues pendant la requête (après une écriture, tests).
     */
    public static function reset(): void
    {
        self::$cache = null;
        self::$manual = null;
    }

    /**
     * @return array{enabled: bool, warning_days: int}
     */
    public static function settings(): array
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::KEY_ENABLED, self::KEY_WARNING_DAYS]);
        $days = $values[self::KEY_WARNING_DAYS] ?? null;

        return [
            'enabled'      => !empty($values[self::KEY_ENABLED]),
            'warning_days' => is_numeric($days) ? self::clampDays((int) $days) : self::DEFAULT_WARNING_DAYS,
        ];
    }

    public static function isEnabled(): bool
    {
        return self::settings()['enabled'];
    }

    public static function warningDays(): int
    {
        return self::settings()['warning_days'];
    }

    public static function save(bool $enabled, int $warningDays): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            self::KEY_ENABLED      => $enabled ? 1 : 0,
            self::KEY_WARNING_DAYS => self::clampDays($warningDays),
        ]);
    }

    public static function clampDays(int $days): int
    {
        return max(self::MIN_WARNING_DAYS, min(self::MAX_WARNING_DAYS, $days));
    }

    // ------------------------------------------------------------------ Correspondances manuelles.

    /**
     * @return array<string, string> ProductMatcher::key() => produit ('' : ne pas suivre).
     */
    public static function manualMap(): array
    {
        if (self::$manual === null) {
            self::$manual = [];
            foreach (self::mappings() as $row) {
                self::$manual[ProductMatcher::key($row['kind'], $row['name'])] = $row['product'];
            }
        }

        return self::$manual;
    }

    /**
     * @return list<array{id: int, kind: string, name: string, product: string}>
     */
    public static function mappings(): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request(['FROM' => self::MAP_TABLE, 'ORDER' => ['kind', 'name']]) as $row) {
            $rows[] = [
                'id'      => (int) $row['id'],
                'kind'    => (string) $row['kind'],
                'name'    => (string) $row['name'],
                'product' => (string) $row['product'],
            ];
        }

        return $rows;
    }

    /**
     * Ajoute ou remplace une correspondance. Renvoie false si la saisie est invalide.
     */
    public static function saveMapping(string $kind, string $name, string $product): bool
    {
        global $DB;

        $name = mb_substr(trim($name), 0, 255);
        $product = strtolower(trim($product));
        if (
            !in_array($kind, ProductMatcher::KINDS, true)
            || $name === ''
            || ($product !== '' && !EndOfLifeClient::isValidProduct($product))
        ) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $existing = null;
        foreach (self::mappings() as $row) {
            if (ProductMatcher::key($row['kind'], $row['name']) === ProductMatcher::key($kind, $name)) {
                $existing = $row['id'];
            }
        }
        if ($existing !== null) {
            $DB->update(
                self::MAP_TABLE,
                ['name' => $name, 'product' => $product, 'date_mod' => $now],
                ['id' => $existing]
            );
        } else {
            $DB->insert(self::MAP_TABLE, [
                'kind'          => $kind,
                'name'          => $name,
                'product'       => $product,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }
        self::reset();

        return true;
    }

    public static function deleteMapping(int $id): void
    {
        global $DB;

        $DB->delete(self::MAP_TABLE, ['id' => $id]);
        self::reset();
    }

    // ------------------------------------------------------------------ Cache.

    /**
     * Cycles connus de chaque produit (cycles null : jamais lus avec succès).
     *
     * @return array<string, CacheEntry>
     */
    public static function cache(): array
    {
        global $DB;

        if (self::$cache === null) {
            self::$cache = [];
            foreach ($DB->request(['FROM' => self::CACHE_TABLE]) as $row) {
                $cycles = null;
                if (($row['date_success'] ?? null) !== null) {
                    $decoded = json_decode((string) $row['cycles'], true);
                    $cycles = self::cyclesFrom(is_array($decoded) ? $decoded : []);
                }
                self::$cache[(string) $row['product']] = [
                    'cycles'       => $cycles,
                    'error'        => (string) $row['error'],
                    'date_checked' => (string) ($row['date_checked'] ?? ''),
                    'date_success' => (string) ($row['date_success'] ?? ''),
                ];
            }
        }

        return self::$cache;
    }

    /**
     * Relecture défensive du cache (une ligne modifiée à la main ne casse pas l'affichage).
     *
     * @param array<mixed> $decoded
     * @return list<Cycle>
     */
    private static function cyclesFrom(array $decoded): array
    {
        $cycles = [];
        foreach ($decoded as $cycle) {
            if (!is_array($cycle) || !is_string($cycle['cycle'] ?? null)) {
                continue;
            }
            $eol = is_string($cycle['eol'] ?? null) ? $cycle['eol'] : '';
            if ($eol !== EndOfLifeClient::EOL_WITHOUT_DATE && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eol) !== 1) {
                $eol = '';
            }
            $cycles[] = [
                'cycle'    => $cycle['cycle'],
                'codename' => is_string($cycle['codename'] ?? null) ? $cycle['codename'] : '',
                'eol'      => $eol,
                'latest'   => is_string($cycle['latest'] ?? null) ? $cycle['latest'] : '',
            ];
        }

        return $cycles;
    }

    /**
     * Enregistre le résultat d'une mise à jour (CacheRefresh::run()).
     *
     * @param list<Write> $writes
     */
    public static function applyWrites(array $writes, string $now): void
    {
        global $DB;

        foreach ($writes as $write) {
            $values = ['error' => $write['error'], 'date_checked' => $now];
            if ($write['cycles'] !== null) {
                $values['cycles'] = (string) json_encode($write['cycles']);
            }
            if ($write['success']) {
                $values['date_success'] = $now;
            }
            $where = ['product' => $write['product']];
            $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::CACHE_TABLE, 'WHERE' => $where])->current();
            if (is_array($existing)) {
                $DB->update(self::CACHE_TABLE, $values, ['id' => (int) $existing['id']]);
            } else {
                $empty = $write['cycles'] === null ? ['cycles' => '[]'] : [];
                $DB->insert(self::CACHE_TABLE, $where + $values + $empty);
            }
        }
        self::reset();
    }

    /**
     * État de la source : dernière lecture réussie, dernier essai, produits en erreur.
     *
     * @return array{last_success: string, last_attempt: string, products: int,
     *     errors: list<array{product: string, code: string, date_success: string}>}
     */
    public static function sourceState(): array
    {
        $success = '';
        $attempt = '';
        $errors = [];
        foreach (self::cache() as $product => $row) {
            $success = max($success, $row['date_success']);
            $attempt = max($attempt, $row['date_checked']);
            if ($row['error'] !== '' && $row['error'] !== EndOfLifeException::NOT_FOUND) {
                $errors[] = [
                    'product'      => (string) $product,
                    'code'         => $row['error'],
                    'date_success' => $row['date_success'],
                ];
            }
        }

        return ['last_success' => $success, 'last_attempt' => $attempt, 'products' => count(self::cache()),
            'errors' => $errors];
    }
}
