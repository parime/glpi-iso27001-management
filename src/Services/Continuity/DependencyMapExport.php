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

namespace GlpiPlugin\Grcmanager\Services\Continuity;

/**
 * Issue #701 : export de la carte des dépendances d'un processus métier en image, sans base.
 *
 * L'image est produite dans le navigateur, à partir du graphe affiché (mêmes nœuds, même
 * disposition) : elle ne contient jamais que ce que la personne voit à l'écran. Cette classe fixe
 * les formats proposés et le nom du fichier téléchargé.
 */
final class DependencyMapExport
{
    /** Formats proposés : extension => type MIME. Le SVG est vectoriel, les deux autres non. */
    public const FORMATS = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
    ];

    /** Longueur maximale du nom de fichier, sans l'extension. */
    public const MAX_NAME_LENGTH = 100;

    /**
     * Nom du fichier, sans extension : « <préfixe>-<processus>-<date> », en minuscules, lettres et
     * chiffres seulement (accents conservés), séparés par des tirets. Aucun caractère interdit par
     * Windows ni séparateur de chemin ne peut y figurer.
     */
    public static function fileName(string $prefix, string $processName, string $date): string
    {
        $slug = static fn (string $text): string => trim(
            (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($text, 'UTF-8')),
            '-'
        );
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
        $suffix = $date !== '' ? '-' . $date : '';
        $head = $slug($prefix);
        $head = $head !== '' ? $head : 'dependency-map';
        $name = $slug($processName);
        $base = $name !== '' ? $head . '-' . $name : $head;
        $base = rtrim(mb_substr($base, 0, self::MAX_NAME_LENGTH - mb_strlen($suffix, 'UTF-8'), 'UTF-8'), '-');

        return $base . $suffix;
    }

    /**
     * @return list<array{format: string, mime: string, vector: bool}>
     */
    public static function formats(): array
    {
        $formats = [];
        foreach (self::FORMATS as $format => $mime) {
            $formats[] = ['format' => $format, 'mime' => $mime, 'vector' => $format === 'svg'];
        }

        return $formats;
    }
}
