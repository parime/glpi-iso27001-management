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

namespace GlpiPlugin\Grcmanager\Services\Audit;

/**
 * Issue #538 : lecture de la liste des pièces demandées par l'auditeur, au format CSV (PHP pur,
 * testé seul).
 *
 * Une ligne d'en-tête, séparateur « ; », « , » ou tabulation (détecté sur l'en-tête), encodage
 * UTF-8 (avec ou sans BOM) ou Windows-1252. Colonnes reconnues, dans n'importe quel ordre, par
 * leur nom anglais ou français : référence, mesure (code de l'Annexe A), description, échéance,
 * responsable (identifiant de connexion). Il faut au moins une référence ou une description par
 * ligne ; les lignes vides sont ignorées. Les mesures et les comptes sont résolus par l'appelant.
 */
final class EvidenceRequestCsv
{
    public const MAX_ROWS = 500;

    public const ERROR_NO_HEADER = 'no_header';
    public const ERROR_TOO_MANY = 'too_many';
    public const ERROR_MISSING_TEXT = 'missing_text';
    public const ERROR_BAD_DATE = 'bad_date';

    /** Noms de colonnes acceptés (minuscules, sans accent), par champ. */
    public const COLUMNS = [
        'reference'   => ['reference', 'ref', 'requirement', 'exigence', 'item', 'numero', 'id'],
        'control'     => ['control', 'mesure', 'controle', 'code', 'annex a', 'annexe a'],
        'description' => ['description', 'request', 'demande', 'evidence', 'preuve', 'piece', 'pieces'],
        'due_date'    => ['due date', 'due', 'deadline', 'echeance', 'date'],
        'assignee'    => ['assignee', 'owner', 'login', 'responsable', 'utilisateur', 'user'],
    ];

    /**
     * @return array{rows: list<array{line: int, reference: string, control: string, description: string,
     *     due_date: ?string, assignee: string}>, errors: list<array{line: int, error: string}>}
     */
    public static function parse(string $content): array
    {
        $content = self::toUtf8($content);
        $firstLine = '';
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }
        if ($firstLine === '') {
            return ['rows' => [], 'errors' => [['line' => 0, 'error' => self::ERROR_NO_HEADER]]];
        }
        $delimiter = self::delimiter($firstLine);
        // Lecture enregistrement par enregistrement : une cellule entre guillemets peut contenir
        // des retours à la ligne (description saisie dans un tableur).
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return ['rows' => [], 'errors' => [['line' => 0, 'error' => self::ERROR_NO_HEADER]]];
        }
        fwrite($stream, $content);
        rewind($stream);
        $records = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $records[] = array_map(static fn ($c): string => trim((string) $c), $cells);
        }
        fclose($stream);

        $headerIndex = null;
        foreach ($records as $index => $cells) {
            if (implode('', $cells) !== '') {
                $headerIndex = $index;
                break;
            }
        }
        $map = $headerIndex === null ? [] : self::columnMap($records[$headerIndex]);
        if (!isset($map['reference']) && !isset($map['description'])) {
            return ['rows' => [], 'errors' => [['line' => (int) $headerIndex + 1, 'error' => self::ERROR_NO_HEADER]]];
        }

        $rows = [];
        $errors = [];
        $count = count($records);
        for ($i = (int) $headerIndex + 1; $i < $count; $i++) {
            $cells = $records[$i];
            if (implode('', $cells) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = ['line' => $i + 1, 'error' => self::ERROR_TOO_MANY];
                break;
            }
            $cell = static fn (string $field): string => isset($map[$field])
                ? trim((string) ($cells[$map[$field]] ?? ''))
                : '';
            $reference = EvidenceRequestRules::reference($cell('reference'));
            $description = trim($cell('description'));
            if ($reference === '' && $description === '') {
                $errors[] = ['line' => $i + 1, 'error' => self::ERROR_MISSING_TEXT];
                continue;
            }
            $rawDate = $cell('due_date');
            $dueDate = $rawDate === '' ? null : EvidenceRequestRules::parseDate($rawDate);
            if ($rawDate !== '' && $dueDate === null) {
                $errors[] = ['line' => $i + 1, 'error' => self::ERROR_BAD_DATE];
                continue;
            }
            $rows[] = [
                'line'        => $i + 1,
                'reference'   => $reference,
                'control'     => strtoupper($cell('control')),
                'description' => $description,
                'due_date'    => $dueDate,
                'assignee'    => $cell('assignee'),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param list<string|null> $header
     * @return array<string, int> champ => index de colonne
     */
    public static function columnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $key = self::normalize((string) $name);
            foreach (self::COLUMNS as $field => $names) {
                if (!isset($map[$field]) && in_array($key, $names, true)) {
                    $map[$field] = (int) $index;
                    break;
                }
            }
        }

        return $map;
    }

    public static function delimiter(string $headerLine): string
    {
        $best = ';';
        $bestCount = -1;
        foreach ([';', ',', "\t"] as $candidate) {
            $count = substr_count($headerLine, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name, " \t\"'\u{FEFF}"));
        $name = strtr($name, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '_' => ' ', '-' => ' ']);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }
}
