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
 * Issue #537 : lecture du fichier CSV d'un référentiel personnalisé, sans dépendance à GLPI (testée
 * unitairement). UTF-8 avec ou sans BOM, séparateur « ; », « , » ou tabulation détecté sur l'en-tête,
 * en-têtes français ou anglais, champs entre guillemets sur plusieurs lignes acceptés.
 *
 * Colonnes : code, theme, title, description, annex_a (codes de l'Annexe A séparés par une virgule,
 * un espace, une barre verticale ou un point-virgule). Seuls code et titre sont obligatoires. Une
 * ligne invalide est rejetée avec son numéro et sa raison, jamais ignorée en silence.
 */
final class CustomFrameworkCsv
{
    public const MAX_ROWS = 2000;
    public const MAX_CODE_LENGTH = 50;

    // Raisons de rejet (traduites par l'écran d'import).
    public const ERROR_EMPTY = 'empty';
    public const ERROR_MISSING_COLUMNS = 'missing_columns';
    public const ERROR_MISSING_CODE = 'missing_code';
    public const ERROR_MISSING_TITLE = 'missing_title';
    public const ERROR_DUPLICATE_CODE = 'duplicate_code';
    public const ERROR_UNKNOWN_ANNEX_A = 'unknown_annex_a';
    public const ERROR_CODE_TOO_LONG = 'code_too_long';
    public const ERROR_TOO_MANY_ROWS = 'too_many_rows';

    /** En-tête du modèle téléchargeable, dans l'ordre des colonnes. */
    public const TEMPLATE_HEADER = ['code', 'theme', 'title', 'description', 'annex_a'];

    private const HEADERS = [
        'code'        => ['code', 'id', 'ref', 'reference', 'identifiant', 'numero', 'exigence', 'requirement'],
        'theme'       => ['theme', 'group', 'groupe', 'chapter', 'chapitre', 'domain', 'domaine', 'category',
            'categorie', 'section'],
        'title'       => ['title', 'titre', 'intitule', 'libelle', 'name', 'nom'],
        'description' => ['description', 'detail', 'details', 'texte', 'text', 'contenu'],
        'annex_a'     => ['annex_a', 'annexe_a', 'annexa', 'annexea', 'iso_27001', 'iso27001', 'iso_27001_annex_a',
            'iso_27001_annexe_a', 'correspondance', 'mapping', 'mesures', 'controls'],
    ];

    /**
     * @param list<string> $annexACodes codes connus de l'Annexe A (« A.5.1 »)
     * @return array{rows: list<array{line: int, code: string, theme: string, title: string,
     *     description: string, annex_a: list<string>}>,
     *     errors: list<array{line: int, code: string, reason: string, detail: string}>}
     */
    public static function parse(string $content, array $annexACodes): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $rows = [];
        $errors = [];
        $records = self::records($content);
        if ($records === []) {
            return ['rows' => [], 'errors' => [self::error(1, '', self::ERROR_EMPTY)]];
        }
        [$headerLine, $header] = $records[0];
        $columns = self::mapHeader($header);
        if (!in_array('code', $columns, true) || !in_array('title', $columns, true)) {
            return ['rows' => [], 'errors' => [self::error($headerLine, '', self::ERROR_MISSING_COLUMNS)]];
        }
        $known = array_fill_keys($annexACodes, true);
        $seen = [];
        foreach (array_slice($records, 1) as [$line, $fields]) {
            $values = [];
            foreach ($fields as $position => $value) {
                if (($columns[$position] ?? '') !== '') {
                    $values[$columns[$position]] = trim((string) $value);
                }
            }
            if (implode('', $values) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = self::error($line, '', self::ERROR_TOO_MANY_ROWS, (string) self::MAX_ROWS);
                break;
            }
            $code = self::normalizeSpaces($values['code'] ?? '');
            $title = self::normalizeSpaces($values['title'] ?? '');
            if ($code === '') {
                $errors[] = self::error($line, '', self::ERROR_MISSING_CODE);
                continue;
            }
            if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
                $errors[] = self::error($line, mb_substr($code, 0, self::MAX_CODE_LENGTH), self::ERROR_CODE_TOO_LONG);
                continue;
            }
            if ($title === '') {
                $errors[] = self::error($line, $code, self::ERROR_MISSING_TITLE);
                continue;
            }
            $key = mb_strtolower($code);
            if (isset($seen[$key])) {
                $errors[] = self::error($line, $code, self::ERROR_DUPLICATE_CODE, (string) $seen[$key]);
                continue;
            }
            $annexA = [];
            $unknown = [];
            foreach (self::splitAnnexA($values['annex_a'] ?? '') as $annexCode) {
                if (isset($known[$annexCode])) {
                    $annexA[$annexCode] = $annexCode;
                } else {
                    $unknown[] = $annexCode;
                }
            }
            if ($unknown !== []) {
                $errors[] = self::error($line, $code, self::ERROR_UNKNOWN_ANNEX_A, implode(', ', $unknown));
                continue;
            }
            $seen[$key] = $line;
            $rows[] = [
                'line'        => $line,
                'code'        => $code,
                'theme'       => mb_substr(self::normalizeSpaces($values['theme'] ?? ''), 0, 255),
                'title'       => mb_substr($title, 0, 255),
                'description' => $values['description'] ?? '',
                'annex_a'     => array_values($annexA),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Codes de l'Annexe A d'une cellule, normalisés au format « A.5.1 » (« 5.1 », « a.5.1 » acceptés).
     *
     * @return list<string>
     */
    public static function splitAnnexA(string $cell): array
    {
        $codes = [];
        foreach (preg_split('/[\s,;|\/]+/u', trim($cell)) ?: [] as $part) {
            $part = strtoupper(trim($part));
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\d/', $part) === 1) {
                $part = 'A.' . $part;
            } elseif (preg_match('/^A(\d)/', $part) === 1) {
                $part = 'A.' . substr($part, 1);
            }
            $codes[] = $part;
        }

        return array_values(array_unique($codes));
    }

    /**
     * Contenu du modèle à télécharger : en-tête puis lignes d'exemple (fictives), séparateur « ; »,
     * BOM UTF-8 pour qu'un tableur l'ouvre avec les accents.
     *
     * @param list<list<string>> $examples
     */
    public static function template(array $examples): string
    {
        $lines = [self::csvLine(self::TEMPLATE_HEADER)];
        foreach ($examples as $example) {
            $lines[] = self::csvLine($example);
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<string> $values
     */
    private static function csvLine(array $values): string
    {
        $cells = [];
        foreach ($values as $value) {
            $cells[] = preg_match('/[;"\r\n]/', $value) === 1
                ? '"' . str_replace('"', '""', $value) . '"'
                : $value;
        }

        return implode(';', $cells);
    }

    /**
     * Enregistrements du fichier avec le numéro de leur première ligne physique. Un champ entre
     * guillemets peut contenir des retours à la ligne.
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private static function records(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $firstLine = '';
        foreach (explode("\n", $content) as $candidate) {
            if (trim($candidate) !== '') {
                $firstLine = $candidate;
                break;
            }
        }
        if ($firstLine === '') {
            return [];
        }
        $delimiter = self::detectDelimiter($firstLine);

        $records = [];
        $fields = [];
        $field = '';
        $quoted = false;
        $line = 1;
        $start = 1;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($quoted) {
                if ($char === '"') {
                    if ($i + 1 < $length && $content[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    if ($char === "\n") {
                        $line++;
                    }
                    $field .= $char;
                }
                continue;
            }
            if ($char === '"' && trim($field) === '') {
                $quoted = true;
                $field = '';
            } elseif ($char === $delimiter) {
                $fields[] = $field;
                $field = '';
            } elseif ($char === "\n") {
                $fields[] = $field;
                self::pushRecord($records, $start, $fields);
                $fields = [];
                $field = '';
                $line++;
                $start = $line;
            } else {
                $field .= $char;
            }
        }
        $fields[] = $field;
        self::pushRecord($records, $start, $fields);

        return $records;
    }

    /**
     * @param list<array{0: int, 1: list<string>}> $records
     * @param list<string> $fields
     */
    private static function pushRecord(array &$records, int $line, array $fields): void
    {
        if (trim(implode('', $fields)) === '') {
            return;
        }
        $records[] = [$line, $fields];
    }

    private static function detectDelimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param list<string> $header
     * @return array<int, string> position => colonne reconnue ('' si inconnue)
     */
    private static function mapHeader(array $header): array
    {
        $columns = [];
        $used = [];
        foreach ($header as $position => $label) {
            $slug = self::slug($label);
            $columns[$position] = '';
            foreach (self::HEADERS as $key => $aliases) {
                if (!isset($used[$key]) && in_array($slug, $aliases, true)) {
                    $columns[$position] = $key;
                    $used[$key] = true;
                    break;
                }
            }
        }

        return $columns;
    }

    public static function slug(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = strtr($label, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i',
            'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $label), '_');
    }

    private static function normalizeSpaces(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array{line: int, code: string, reason: string, detail: string}
     */
    private static function error(int $line, string $code, string $reason, string $detail = ''): array
    {
        return ['line' => $line, 'code' => $code, 'reason' => $reason, 'detail' => $detail];
    }
}
