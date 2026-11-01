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

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #369 : référentiel MITRE ATT&CK Enterprise embarqué (resources/mitre-attack.json, tiré de
 * attack-stix-data par glpi-test-env/tools/attack_compact.py), sans accès réseau.
 *
 * © The MITRE Corporation. This work is reproduced and distributed with the permission of The
 * MITRE Corporation.
 *
 * @phpstan-type Technique array{id: string, name: string, tactics: list<string>}
 * @phpstan-type AttackData array{
 *     source: string,
 *     url: string,
 *     notice: string,
 *     tactics: list<array{id: string, name: string}>,
 *     techniques: list<Technique>
 * }
 */
final class MitreAttack
{
    private const EMPTY = ['source' => '', 'url' => '', 'notice' => '', 'tactics' => [], 'techniques' => []];

    /** @var AttackData|null */
    private static ?array $data = null;

    private static ?string $path = null;

    /**
     * Pour les tests : lit un autre fichier.
     */
    public static function useFile(?string $path): void
    {
        self::$path = $path;
        self::$data = null;
    }

    /**
     * @return AttackData
     */
    private static function data(): array
    {
        if (self::$data === null) {
            $file = self::$path ?? dirname(__DIR__, 3) . '/resources/mitre-attack.json';
            $decoded = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
            /** @var AttackData $data */
            $data = is_array($decoded) ? $decoded + self::EMPTY : self::EMPTY;
            self::$data = $data;
        }

        return self::$data;
    }

    public static function source(): string
    {
        return self::data()['source'];
    }

    public static function notice(): string
    {
        return self::data()['notice'];
    }

    /**
     * @return array<string, string> Identifiant (TA0001) => nom, dans l'ordre de la matrice.
     */
    public static function tactics(): array
    {
        return array_column(self::data()['tactics'], 'name', 'id');
    }

    /**
     * @return array<string, Technique>
     */
    public static function techniques(): array
    {
        $byId = [];
        foreach (self::data()['techniques'] as $technique) {
            $byId[$technique['id']] = $technique;
        }

        return $byId;
    }

    /**
     * @return Technique|null
     */
    public static function technique(string $id): ?array
    {
        return self::techniques()[$id] ?? null;
    }

    /**
     * Nom complet : « OS Credential Dumping: LSASS Memory » pour une sous-technique.
     */
    public static function fullName(string $id): string
    {
        $technique = self::technique($id);
        if ($technique === null) {
            return $id;
        }
        $parent = str_contains($id, '.') ? self::technique(strstr($id, '.', true)) : null;

        return $parent === null ? $technique['name'] : $parent['name'] . ': ' . $technique['name'];
    }

    public static function url(string $id): string
    {
        if (str_starts_with($id, 'TA')) {
            return 'https://attack.mitre.org/tactics/' . rawurlencode($id) . '/';
        }

        return 'https://attack.mitre.org/techniques/' . str_replace('.', '/', rawurlencode($id)) . '/';
    }

    /**
     * Ramène une saisie libre à un identifiant connu : « t1003/001 », « T1003.001 - LSASS Memory »,
     * « TA0006 », ou un nom exact (« LSASS Memory », « Credential Access »).
     *
     * @return array{kind: 'tactic'|'technique', id: string}|null
     */
    public static function normalize(string $input): ?array
    {
        $input = trim($input);
        if (preg_match('/^(TA\d{4})\b/i', $input, $m)) {
            $id = strtoupper($m[1]);

            return isset(self::tactics()[$id]) ? ['kind' => 'tactic', 'id' => $id] : null;
        }
        if (preg_match('/^(T\d{4})(?:[.\/](\d{3}))?\b/i', $input, $m)) {
            $id = strtoupper($m[1]) . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : '');

            return self::technique($id) !== null ? ['kind' => 'technique', 'id' => $id] : null;
        }
        $needle = mb_strtolower($input);
        foreach (self::tactics() as $id => $name) {
            if (mb_strtolower($name) === $needle) {
                return ['kind' => 'tactic', 'id' => $id];
            }
        }
        foreach (self::techniques() as $id => $technique) {
            $names = [mb_strtolower($technique['name']), mb_strtolower(self::fullName($id))];
            if (in_array($needle, $names, true)) {
                return ['kind' => 'technique', 'id' => $id];
            }
        }

        return null;
    }

    /**
     * Options d'une liste déroulante groupée par tactique (une technique sur plusieurs tactiques
     * apparaît dans chacune : la valeur porte la tactique, « T1078|TA0001 »).
     *
     * @return array<string, array<string, string>>
     */
    public static function dropdownOptions(): array
    {
        $tactics = self::tactics();
        $groups = [];
        foreach ($tactics as $tacticId => $tacticName) {
            $groups[$tacticId . ' - ' . $tacticName] = [];
        }
        foreach (self::techniques() as $id => $technique) {
            foreach ($technique['tactics'] as $tacticId) {
                if (isset($tactics[$tacticId])) {
                    $group = $tacticId . ' - ' . $tactics[$tacticId];
                    $groups[$group][$id . '|' . $tacticId] = $id . ' - ' . self::fullName($id);
                }
            }
        }

        return $groups;
    }
}
