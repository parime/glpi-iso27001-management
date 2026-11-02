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
 * Issue #371 : journal d'audit infalsifiable. Chaque entrée porte l'empreinte SHA-256 de son
 * contenu et de l'empreinte de l'entrée précédente : modifier, supprimer ou insérer une entrée
 * casse la chaîne à partir de cet endroit. Sans base : testable seul.
 */
final class AuditChain
{
    /** Empreinte qui précède la première entrée. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Champs scellés, dans cet ordre. */
    public const SEALED = [
        'date', 'users_id', 'users_id_real', 'profiles_id', 'action', 'itemtype', 'items_id',
        'plugin_grcmanager_securityincidents_id', 'ip', 'result', 'details',
    ];

    /**
     * @param array<string, mixed> $entry
     */
    public static function hash(string $previousHash, array $entry): string
    {
        $values = [$previousHash];
        foreach (self::SEALED as $field) {
            $values[] = (string) ($entry[$field] ?? '');
        }

        return hash('sha256', (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Vérifie une suite d'entrées dans l'ordre d'insertion.
     *
     * @param iterable<array<string, mixed>> $entries
     * @param string $previousHash Empreinte de l'entrée qui précède la première lue.
     * @return array{valid: bool, checked: int, broken_id: ?int, last_hash: string}
     */
    public static function verify(iterable $entries, string $previousHash = self::GENESIS): array
    {
        $checked = 0;
        foreach ($entries as $entry) {
            $expected = self::hash($previousHash, $entry);
            if (($entry['prev_hash'] ?? '') !== $previousHash || ($entry['hash'] ?? '') !== $expected) {
                return [
                    'valid'     => false,
                    'checked'   => $checked,
                    'broken_id' => (int) ($entry['id'] ?? 0),
                    'last_hash' => $previousHash,
                ];
            }
            $previousHash = (string) $entry['hash'];
            $checked++;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_id' => null, 'last_hash' => $previousHash];
    }
}
