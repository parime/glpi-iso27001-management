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

namespace GlpiPlugin\Grcmanager\Services\Cartography;

/**
 * Issue #1082 : placement du schéma par couches (SVG dessiné par le gabarit, sans bibliothèque
 * externe). Une bande horizontale par couche, les processus métier en haut et le réseau en bas ;
 * au plus MAX_DRAWN éléments dessinés par couche, les autres restent dans la liste sous le schéma.
 */
final class LayeredLayout
{
    public const WIDTH = 1100;
    public const PER_ROW = 6;
    public const MAX_DRAWN = 12;
    public const NODE_WIDTH = 148;
    public const NODE_HEIGHT = 46;
    public const ROW_HEIGHT = 64;
    public const BAND_PADDING = 30;
    public const LEFT = 170;

    /**
     * @param array<int, list<string>> $layers couche => clés, dans l'ordre d'affichage
     * @return array{width: int, height: int,
     *     bands: list<array{layer: int, y: int, height: int, hidden: int}>,
     *     nodes: array<string, array{x: int, y: int, cx: int, cy: int}>}
     */
    public static function place(array $layers): array
    {
        $bands = [];
        $nodes = [];
        $y = 0;
        foreach (array_reverse(Layers::ALL) as $layer) {
            $keys = $layers[$layer] ?? [];
            $drawn = array_slice($keys, 0, self::MAX_DRAWN);
            $rows = max(1, (int) ceil(count($drawn) / self::PER_ROW));
            $height = $rows * self::ROW_HEIGHT + self::BAND_PADDING;
            $slot = intdiv(self::WIDTH - self::LEFT, self::PER_ROW);
            foreach ($drawn as $index => $key) {
                $x = self::LEFT + ($index % self::PER_ROW) * $slot + intdiv($slot - self::NODE_WIDTH, 2);
                $top = $y + intdiv(self::BAND_PADDING, 2) + intdiv($index, self::PER_ROW) * self::ROW_HEIGHT
                    + intdiv(self::ROW_HEIGHT - self::NODE_HEIGHT, 2);
                $nodes[$key] = [
                    'x'  => $x,
                    'y'  => $top,
                    'cx' => $x + intdiv(self::NODE_WIDTH, 2),
                    'cy' => $top + intdiv(self::NODE_HEIGHT, 2),
                ];
            }
            $bands[] = ['layer' => $layer, 'y' => $y, 'height' => $height, 'hidden' => count($keys) - count($drawn)];
            $y += $height;
        }

        return ['width' => self::WIDTH, 'height' => $y, 'bands' => $bands, 'nodes' => $nodes];
    }

    /**
     * Libellé court pour une case du schéma (le libellé complet est dans l'infobulle et la liste).
     */
    public static function shortLabel(string $label, int $width = 20): string
    {
        return mb_strlen($label) > $width ? rtrim(mb_substr($label, 0, $width - 1)) . '…' : $label;
    }
}
