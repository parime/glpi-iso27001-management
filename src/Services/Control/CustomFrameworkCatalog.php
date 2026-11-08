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

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #537 : référentiels personnalisés importés, vus par le reste du plugin comme les catalogues
 * intégrés (clé d'activation « custom_<id> », correspondance avec l'Annexe A). Lus une fois par
 * requête depuis la base (GlpiPlugin\Grcmanager\CustomFramework::catalogRows()) ; sans base (tests
 * unitaires), la liste est vide, ou celle donnée par set().
 *
 * Les exigences retirées par un réimport ne comptent plus : ni dans les badges, ni dans les taux.
 */
final class CustomFrameworkCatalog
{
    public const KEY_PREFIX = 'custom_';

    private const LOADER = 'GlpiPlugin\\Grcmanager\\CustomFramework::catalogRows';

    /**
     * @var array<string, array{id: int, name: string, label: string,
     *     requirements: array<string, array{code: string, theme: string, title: string, annex_a: list<string>}>}>|null
     */
    private static ?array $frameworks = null;

    /**
     * @return array<string, array{id: int, name: string, label: string,
     *     requirements: array<string, array{code: string, theme: string, title: string, annex_a: list<string>}>}>
     *     clé => référentiel, dans l'ordre de création
     */
    public static function all(): array
    {
        if (self::$frameworks === null) {
            $rows = isset($GLOBALS['DB']) && is_callable(self::LOADER) ? call_user_func(self::LOADER) : [];
            self::set(is_array($rows) ? $rows : []);
        }

        return self::$frameworks ?? [];
    }

    /**
     * @param list<array{id: int, name: string, version?: string,
     *     requirements: list<array{code: string, theme: string, title: string, annex_a: list<string>}>}> $rows
     */
    public static function set(array $rows): void
    {
        $frameworks = [];
        $labels = [];
        foreach ($rows as $row) {
            $label = trim($row['name'] . ' ' . ($row['version'] ?? ''));
            // Deux référentiels du même nom restent distincts sur le tableau de bord.
            if (isset($labels[mb_strtolower($label)])) {
                $label .= ' (#' . $row['id'] . ')';
            }
            $labels[mb_strtolower($label)] = true;
            $requirements = [];
            foreach ($row['requirements'] as $requirement) {
                $requirements[$requirement['code']] = $requirement;
            }
            $frameworks[self::key($row['id'])] = [
                'id'           => $row['id'],
                'name'         => $row['name'],
                'label'        => $label,
                'requirements' => $requirements,
            ];
        }
        self::$frameworks = $frameworks;
    }

    /** Relit la base à la prochaine demande (après un import, une suppression). */
    public static function reset(): void
    {
        self::$frameworks = null;
    }

    public static function key(int $id): string
    {
        return self::KEY_PREFIX . $id;
    }

    public static function isCustomKey(string $key): bool
    {
        return preg_match('/^' . self::KEY_PREFIX . '\d+$/', $key) === 1;
    }

    public static function idFromKey(string $key): ?int
    {
        return self::isCustomKey($key) ? (int) substr($key, strlen(self::KEY_PREFIX)) : null;
    }

    /**
     * @return array<string, string> clé => nom affiché
     */
    public static function labels(): array
    {
        return array_map(static fn (array $framework): string => $framework['label'], self::all());
    }

    /**
     * Codes des exigences du référentiel $key qui renvoient à la mesure $annexACode.
     *
     * @return list<string>
     */
    public static function requirementsForControl(string $key, string $annexACode): array
    {
        $codes = [];
        foreach (self::all()[$key]['requirements'] ?? [] as $requirement) {
            if (in_array($annexACode, $requirement['annex_a'], true)) {
                $codes[] = $requirement['code'];
            }
        }

        return $codes;
    }
}
