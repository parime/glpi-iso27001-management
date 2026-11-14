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

namespace GlpiPlugin\Grcmanager\Services\Dora;

/**
 * Issue #540 : un fichier CSV par modèle du registre, dans la forme des tableaux d'un paquet
 * xBRL-CSV : UTF-8 sans BOM, virgule, première ligne = codes des colonnes du modèle (c0010...),
 * une ligne par enregistrement, cellules vides pour les valeurs absentes. PHP pur, testé seul.
 */
final class RegisterCsv
{
    /**
     * @param list<array<string, string>> $rows
     */
    public static function render(string $template, array $rows): string
    {
        $columns = array_keys(RegisterTemplates::TEMPLATES[$template]['columns'] ?? []);
        if ($columns === []) {
            return '';
        }
        $csv = self::line($columns);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = (string) ($row[$column] ?? '');
            }
            $csv .= self::line($cells);
        }

        return $csv;
    }

    /**
     * Une ligne (RFC 4180) : guillemets seulement autour d'une cellule qui contient une virgule,
     * un guillemet ou un retour à la ligne.
     *
     * @param list<string> $cells
     */
    private static function line(array $cells): string
    {
        $quoted = array_map(
            static fn (string $cell): string => strpbrk($cell, ",\"\r\n") === false
                ? $cell
                : '"' . str_replace('"', '""', $cell) . '"',
            $cells
        );

        return implode(',', $quoted) . "\r\n";
    }

    /**
     * Tous les modèles : nom de fichier => contenu, dans l'ordre de l'annexe I.
     *
     * @param array<string, list<array<string, string>>> $rows
     * @return array<string, string>
     */
    public static function files(array $rows): array
    {
        $files = [];
        foreach (array_keys(RegisterTemplates::TEMPLATES) as $template) {
            $files[RegisterTemplates::fileName($template)] = self::render($template, $rows[$template] ?? []);
        }

        return $files;
    }
}
