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

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #865 : questionnaire fournisseur hors ligne, fichier CSV à remplir puis à réimporter
 * (PHP pur, testé seul).
 *
 * Le fichier exporté commence par une ligne de repère (marqueur, numéro de l'évaluation, empreinte
 * de ses questions) puis une ligne d'en-tête et une ligne par question. Colonnes, dans cet ordre :
 * code, section, question, aide, réponse attendue, pièce exigée, réponses possibles, réponse,
 * commentaire, nom de la pièce jointe.
 *
 * Au retour, le fichier est refusé s'il ne porte pas le repère, vise une autre évaluation ou a été
 * fait sur d'autres questions (empreinte). Les lignes sont rattachées par leur code ; code inconnu,
 * doublon ou réponse hors liste sont signalés et ignorés. Une cellule vide ne change rien. Rien
 * n'est écrit ici : le résultat sert à l'aperçu, l'appelant enregistre après confirmation.
 *
 * Les cellules exportées qui commencent par = + - @ ou une tabulation sont préfixées d'une
 * apostrophe pour qu'un tableur ne les exécute pas comme des formules ; l'apostrophe est retirée à
 * l'import.
 *
 * Issue #1159 : une question en échelle de conformité est exportée avec les libellés de l'échelle
 * (réponse attendue « Conforme », réponses possibles Conforme / Partiellement conforme / Non
 * conforme / Non applicable) ; au retour, ces libellés et les mots usuels de l'échelle sont reconnus.
 */
final class SupplierAnswerFile
{
    public const MARKER = 'grcmanager-supplier-evaluation';
    public const FORMAT_VERSION = '1';

    public const SOURCE_FILE = 'file';

    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 1000;

    public const COLUMN_CODE = 0;
    public const COLUMN_ANSWER = 7;
    public const COLUMN_COMMENT = 8;
    public const COLUMN_EVIDENCE = 9;
    public const COLUMN_COUNT = 10;

    public const ERROR_EMPTY = 'empty';
    public const ERROR_TOO_LARGE = 'too_large';
    public const ERROR_BINARY = 'binary';
    public const ERROR_NOT_QUESTIONNAIRE = 'not_questionnaire';
    public const ERROR_OTHER_EVALUATION = 'other_evaluation';
    public const ERROR_OTHER_QUESTIONS = 'other_questions';
    public const ERROR_NO_HEADER = 'no_header';

    public const WARNING_UNKNOWN_CODE = 'unknown_code';
    public const WARNING_DUPLICATE_CODE = 'duplicate_code';
    public const WARNING_BAD_ANSWER = 'bad_answer';
    public const WARNING_TOO_MANY = 'too_many';
    public const WARNING_MISSING_QUESTION = 'missing_question';

    /** Mots reconnus pour chaque réponse (minuscules, sans accent), en plus des libellés traduits. */
    public const ANSWER_WORDS = [
        WeightedQuestionnaire::ANSWER_YES     => [
            'yes', 'y', 'oui', 'o', 'si', 'ja', 'sim', 'tak', 'true', '1', 'compliant', 'conforme', 'konform',
        ],
        WeightedQuestionnaire::ANSWER_PARTIAL => [
            'partial', 'partially', 'partiel', 'partielle', 'partiellement', 'parcial', 'teilweise', 'parziale',
            'czesciowo', 'partially compliant', 'partiellement conforme', 'partly compliant',
        ],
        WeightedQuestionnaire::ANSWER_NO      => [
            'no', 'n', 'non', 'nein', 'nao', 'nie', 'false', '0', 'non-compliant', 'non compliant', 'not compliant',
            'non conforme', 'non-conforme', 'nicht konform',
        ],
        WeightedQuestionnaire::ANSWER_NA      => [
            'na', 'n/a', 'n.a.', 'not applicable', 'sans objet', 'non applicable', 'non concerne', 'no aplica',
            'nicht zutreffend', 'non applicabile', 'nao aplicavel', 'nie dotyczy',
        ],
    ];

    /**
     * Contenu CSV (UTF-8 avec BOM, séparateur « ; ») du questionnaire à remplir.
     *
     * @param list<array<string, mixed>> $answers lignes de réponse de l'évaluation
     * @param list<string>               $header  libellés traduits des dix colonnes
     * @param array<string, string>      $labels  réponse => libellé traduit
     * @param array<string, string>      $scaleLabels réponse => libellé traduit de l'échelle de conformité
     */
    public static function build(
        int $evaluationId,
        array $answers,
        array $header,
        array $labels,
        array $scaleLabels = []
    ): string {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return '';
        }
        $write = static function (array $cells) use ($stream): void {
            fputcsv($stream, array_map([self::class, 'protectCell'], $cells), ';', '"', '');
        };
        fwrite($stream, "\u{FEFF}");
        $fingerprint = WeightedQuestionnaire::fingerprint($answers);
        $write([self::MARKER, self::FORMAT_VERSION, (string) $evaluationId, $fingerprint]);
        $write(array_slice(array_pad($header, self::COLUMN_COUNT, ''), 0, self::COLUMN_COUNT));
        foreach ($answers as $row) {
            $answer = (string) ($row['answer'] ?? '');
            $rowLabels = self::labelsFor($row, $labels, $scaleLabels);
            $expected = WeightedQuestionnaire::expectedFor($row);
            $write([
                (string) ($row['code'] ?? ''),
                (string) ($row['section'] ?? ''),
                (string) ($row['question'] ?? ''),
                (string) ($row['guidance'] ?? ''),
                $rowLabels[$expected] ?? $expected,
                empty($row['requires_evidence']) ? '' : ($labels[WeightedQuestionnaire::ANSWER_YES] ?? 'yes'),
                implode(' / ', $rowLabels),
                $answer === '' ? '' : ($rowLabels[$answer] ?? $answer),
                (string) ($row['answer_comment'] ?? ''),
                '',
            ]);
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * Libellés des réponses d'une ligne selon son type (échelle de conformité ou Oui / Non).
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $labels
     * @param array<string, string> $scaleLabels
     * @return array<string, string>
     */
    public static function labelsFor(array $row, array $labels, array $scaleLabels): array
    {
        $isScale = WeightedQuestionnaire::normalizeAnswerType($row['answer_type'] ?? '')
            === WeightedQuestionnaire::TYPE_COMPLIANCE;

        return $isScale && $scaleLabels !== [] ? $scaleLabels : $labels;
    }

    /**
     * Neutralise une cellule qu'un tableur prendrait pour une formule.
     */
    public static function protectCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    /**
     * Lit le fichier retourné et le compare aux réponses de l'évaluation.
     *
     * @param list<array<string, mixed>> $answers lignes de réponse actuelles de l'évaluation
     * @param array<string, string>      $labels  réponse => libellé traduit
     * @param array<string, string>      $scaleLabels réponse => libellé traduit de l'échelle de conformité
     * @return array{error: ?string, changes: list<array{id: int, code: string, question: string,
     *     line: int, old_answer: string, answer: ?string, old_comment: string, comment: ?string,
     *     evidence: string, answer_type: string}>, warnings: list<array{line: int, warning: string,
     *     value: string}>, evidence: array<string, list<int>>}
     */
    public static function parse(
        string $content,
        int $evaluationId,
        array $answers,
        array $labels,
        array $scaleLabels = []
    ): array {
        $result = ['error' => null, 'changes' => [], 'warnings' => [], 'evidence' => []];
        if (strlen($content) > self::MAX_BYTES) {
            return ['error' => self::ERROR_TOO_LARGE] + $result;
        }
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }
        if (trim($content) === '') {
            return ['error' => self::ERROR_EMPTY] + $result;
        }
        // Classeur, archive ou exécutable renommé en .csv : refusé sans être interprété.
        if (str_contains($content, "\0") || str_starts_with($content, 'PK') || str_starts_with($content, '%PDF')) {
            return ['error' => self::ERROR_BINARY] + $result;
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $records = self::records($content);
        $marker = array_shift($records) ?? [];
        if (self::unprotect($marker[0] ?? '') !== self::MARKER) {
            return ['error' => self::ERROR_NOT_QUESTIONNAIRE] + $result;
        }
        if ((int) self::unprotect($marker[2] ?? '') !== $evaluationId) {
            return ['error' => self::ERROR_OTHER_EVALUATION] + $result;
        }
        if (self::unprotect($marker[3] ?? '') !== WeightedQuestionnaire::fingerprint($answers)) {
            return ['error' => self::ERROR_OTHER_QUESTIONS] + $result;
        }
        $header = array_shift($records) ?? [];
        if (count($header) < self::COLUMN_COUNT) {
            return ['error' => self::ERROR_NO_HEADER] + $result;
        }

        $byCode = [];
        foreach ($answers as $row) {
            $byCode[(string) $row['code']] = $row;
        }
        $seen = [];
        $lineNumber = 2;
        foreach ($records as $cells) {
            $lineNumber++;
            if (implode('', $cells) === '') {
                continue;
            }
            if (count($seen) >= self::MAX_ROWS) {
                $result['warnings'][] = ['line' => $lineNumber, 'warning' => self::WARNING_TOO_MANY, 'value' => ''];
                break;
            }
            $code = self::unprotect($cells[self::COLUMN_CODE] ?? '');
            if (!isset($byCode[$code])) {
                $result['warnings'][] = [
                    'line' => $lineNumber, 'warning' => self::WARNING_UNKNOWN_CODE, 'value' => mb_substr($code, 0, 64),
                ];
                continue;
            }
            if (isset($seen[$code])) {
                $result['warnings'][] = [
                    'line' => $lineNumber, 'warning' => self::WARNING_DUPLICATE_CODE, 'value' => $code,
                ];
                continue;
            }
            $seen[$code] = true;
            $row = $byCode[$code];
            $rawAnswer = self::unprotect($cells[self::COLUMN_ANSWER] ?? '');
            $answer = $rawAnswer === '' ? null : (
                self::matchAnswer($rawAnswer, self::labelsFor($row, $labels, $scaleLabels))
                ?? self::matchAnswer($rawAnswer, $labels)
            );
            if ($rawAnswer !== '' && $answer === null) {
                $result['warnings'][] = [
                    'line'    => $lineNumber,
                    'warning' => self::WARNING_BAD_ANSWER,
                    'value'   => mb_substr($rawAnswer, 0, 64),
                ];
                continue;
            }
            $rawComment = self::unprotect($cells[self::COLUMN_COMMENT] ?? '');
            $comment = $rawComment === '' ? null : WeightedQuestionnaire::cleanText($rawComment);
            $evidence = self::evidenceName(self::unprotect($cells[self::COLUMN_EVIDENCE] ?? ''));
            if ($evidence !== '') {
                $result['evidence'][$evidence][] = (int) $row['id'];
            }
            $oldAnswer = (string) $row['answer'];
            $oldComment = (string) ($row['answer_comment'] ?? '');
            if ($answer === $oldAnswer) {
                $answer = null;
            }
            if ($comment === $oldComment) {
                $comment = null;
            }
            if ($answer === null && $comment === null && $evidence === '') {
                continue;
            }
            $result['changes'][] = [
                'id'          => (int) $row['id'],
                'code'        => $code,
                'question'    => (string) $row['question'],
                'line'        => $lineNumber,
                'old_answer'  => $oldAnswer,
                'answer'      => $answer,
                'old_comment' => $oldComment,
                'comment'     => $comment,
                'evidence'    => $evidence,
                'answer_type' => WeightedQuestionnaire::normalizeAnswerType($row['answer_type'] ?? ''),
            ];
        }
        foreach ($byCode as $code => $row) {
            if (!isset($seen[$code])) {
                $result['warnings'][] = [
                    'line' => 0, 'warning' => self::WARNING_MISSING_QUESTION, 'value' => (string) $code,
                ];
            }
        }

        return $result;
    }

    /**
     * Réponse reconnue (code, libellé traduit ou mot usuel), null sinon.
     *
     * @param array<string, string> $labels
     */
    public static function matchAnswer(string $value, array $labels): ?string
    {
        $key = self::normalize($value);
        foreach (WeightedQuestionnaire::ANSWERS as $answer) {
            $words = self::ANSWER_WORDS[$answer];
            if ($key === $answer || in_array($key, $words, true)) {
                return $answer;
            }
            if (isset($labels[$answer]) && $key === self::normalize($labels[$answer])) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * Nom de pièce attendu, réduit à son nom de fichier (sans chemin), en minuscules ; vide si rien.
     */
    public static function evidenceName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name);

        return $name === '' || $name === '.' || $name === '..' ? '' : mb_strtolower(mb_substr($name, 0, 255));
    }

    private static function unprotect(mixed $value): string
    {
        $value = trim((string) $value);
        if (strlen($value) > 1 && $value[0] === "'" && strpbrk($value[1], "=+-@\t\r") !== false) {
            $value = substr($value, 1);
        }

        return $value;
    }

    /**
     * @return list<list<string>>
     */
    private static function records(string $content): array
    {
        $firstLine = (string) strtok($content, "\r\n");
        $delimiter = ';';
        $best = -1;
        foreach ([';', ',', "\t"] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $best) {
                $delimiter = $candidate;
                $best = $count;
            }
        }
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $content);
        rewind($stream);
        $records = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $records[] = array_map(static fn ($cell): string => (string) $cell, $cells);
            if (count($records) > self::MAX_ROWS + 3) {
                break;
            }
        }
        fclose($stream);

        return $records;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'á' => 'a', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ó' => 'o', 'õ' => 'o', 'û' => 'u',
            'ù' => 'u', 'ú' => 'u', 'ç' => 'c', 'ś' => 's', 'ć' => 'c', 'ł' => 'l',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
