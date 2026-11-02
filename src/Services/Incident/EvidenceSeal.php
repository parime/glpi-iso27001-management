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
 * Issue #371 : scellement des preuves. Empreinte SHA-256 d'un fichier et comparaison avec
 * l'empreinte de référence relevée à l'ajout. Sans base : testable seul.
 */
final class EvidenceSeal
{
    public const OK = 'ok';
    public const MODIFIED = 'modified';
    public const MISSING = 'missing';

    /**
     * @return array{sha256: string, size: int}|null Null si le fichier n'est pas lisible.
     */
    public static function seal(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $hash = hash_file('sha256', $path);

        return $hash === false ? null : ['sha256' => $hash, 'size' => (int) filesize($path)];
    }

    /**
     * Compare un fichier à son empreinte de référence.
     */
    public static function check(string $path, string $referenceSha256): string
    {
        $current = self::seal($path);
        if ($current === null) {
            return self::MISSING;
        }

        return hash_equals(strtolower($referenceSha256), $current['sha256']) ? self::OK : self::MODIFIED;
    }
}
