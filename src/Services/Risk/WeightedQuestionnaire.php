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
 * Issue #864 : règles des questionnaires fournisseurs configurables, sans dépendance à GLPI
 * (testées unitairement).
 *
 * Chaque question a une réponse attendue (« oui » ou « non » : la réponse conforme) et une
 * pondération de 0 à 10. La réponse attendue rapporte 1, « partiel » 0,5, la réponse contraire 0 ;
 * « sans objet » et l'absence de réponse sont exclues du calcul, comme une pondération nulle
 * (question informative). Le score est la somme pondérée des points sur la somme des pondérations
 * retenues, en pourcentage ; il éclaire l'évaluation sans jamais modifier le niveau de risque.
 */
final class WeightedQuestionnaire
{
    public const ANSWER_YES = SupplierQuestionnaire::ANSWER_YES;
    public const ANSWER_PARTIAL = SupplierQuestionnaire::ANSWER_PARTIAL;
    public const ANSWER_NO = SupplierQuestionnaire::ANSWER_NO;
    public const ANSWER_NA = SupplierQuestionnaire::ANSWER_NA;

    public const ANSWERS = [self::ANSWER_YES, self::ANSWER_PARTIAL, self::ANSWER_NO, self::ANSWER_NA];

    public const EXPECTED_ANSWERS = [self::ANSWER_YES, self::ANSWER_NO];

    public const DOMAIN_SECURITY = 'security';
    public const DOMAIN_PRIVACY = 'privacy';
    public const DOMAIN_AI = 'ai';
    public const DOMAIN_CONTINUITY = 'continuity';
    public const DOMAIN_OTHER = 'other';

    public const DOMAINS = [
        self::DOMAIN_SECURITY, self::DOMAIN_PRIVACY, self::DOMAIN_AI, self::DOMAIN_CONTINUITY, self::DOMAIN_OTHER,
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    /** Origine d'une évaluation ou d'une réponse ; l'issue #865 ajoutera « link » et « file ». */
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_LEGACY = 'legacy';

    public const MAX_WEIGHT = 10;
    public const DEFAULT_WEIGHT = 1;

    public const MAX_TEXT_LENGTH = 4000;
    public const MAX_NAME_LENGTH = 255;
    public const MAX_CODE_LENGTH = 64;

    public static function normalizeAnswer(mixed $answer): string
    {
        $answer = is_string($answer) ? strtolower(trim($answer)) : '';

        return in_array($answer, self::ANSWERS, true) ? $answer : '';
    }

    public static function normalizeExpected(mixed $expected): string
    {
        return is_string($expected) && in_array($expected, self::EXPECTED_ANSWERS, true) ? $expected : self::ANSWER_YES;
    }

    public static function normalizeWeight(mixed $weight): int
    {
        if (!is_numeric($weight)) {
            return self::DEFAULT_WEIGHT;
        }

        return max(0, min(self::MAX_WEIGHT, (int) $weight));
    }

    public static function normalizeDomain(mixed $domain): string
    {
        return is_string($domain) && in_array($domain, self::DOMAINS, true) ? $domain : self::DOMAIN_SECURITY;
    }

    /**
     * Texte libre : chaîne, sans caractère de contrôle (sauf retours à la ligne), tronquée.
     */
    public static function cleanText(mixed $text, int $max = self::MAX_TEXT_LENGTH): string
    {
        if (!is_string($text) && !is_numeric($text)) {
            return '';
        }
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) $text);

        return mb_substr(trim($text), 0, $max);
    }

    /**
     * Code stable d'une question : lettres, chiffres, point, tiret et soulignement.
     */
    public static function cleanCode(mixed $code): string
    {
        $code = is_string($code) || is_numeric($code) ? (string) $code : '';
        $code = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($code));

        return substr(trim($code, '_'), 0, self::MAX_CODE_LENGTH);
    }

    /**
     * Points d'une réponse au regard de la réponse attendue, null si elle ne compte pas.
     */
    public static function points(string $answer, string $expected): ?float
    {
        $expected = self::normalizeExpected($expected);

        return match (self::normalizeAnswer($answer)) {
            self::ANSWER_PARTIAL => 0.5,
            self::ANSWER_YES     => $expected === self::ANSWER_YES ? 1.0 : 0.0,
            self::ANSWER_NO      => $expected === self::ANSWER_NO ? 1.0 : 0.0,
            default              => null,
        };
    }

    /**
     * Score pondéré en %, null si aucune question notée n'a reçu de réponse comptée.
     *
     * @param iterable<array{answer?: mixed, expected_answer?: mixed, weight?: mixed}> $rows
     */
    public static function score(iterable $rows): ?int
    {
        $points = 0.0;
        $weights = 0;
        foreach ($rows as $row) {
            $weight = self::normalizeWeight($row['weight'] ?? self::DEFAULT_WEIGHT);
            $earned = self::points((string) ($row['answer'] ?? ''), (string) ($row['expected_answer'] ?? ''));
            if ($weight === 0 || $earned === null) {
                continue;
            }
            $points += $weight * $earned;
            $weights += $weight;
        }

        return $weights === 0 ? null : (int) round(100 * $points / $weights);
    }

    /**
     * Synthèse d'une évaluation : questions, réponses données, sans objet, écarts (réponse non
     * conforme ou partielle), pièces justificatives manquantes (exigées pour une réponse autre que
     * « sans objet ») et score.
     *
     * @param list<array{answer?: mixed, expected_answer?: mixed, weight?: mixed, requires_evidence?: mixed,
     *     documents_id?: mixed}> $rows
     * @return array{total: int, answered: int, not_applicable: int, gaps: int, missing_evidence: int,
     *     score: ?int, level: ?string, complete: bool}
     */
    public static function summary(array $rows): array
    {
        $answered = 0;
        $notApplicable = 0;
        $gaps = 0;
        $missing = 0;
        foreach ($rows as $row) {
            $answer = self::normalizeAnswer($row['answer'] ?? '');
            if ($answer === '') {
                continue;
            }
            $answered++;
            if ($answer === self::ANSWER_NA) {
                $notApplicable++;
                continue;
            }
            $earned = self::points($answer, (string) ($row['expected_answer'] ?? ''));
            if ($earned !== null && $earned < 1.0) {
                $gaps++;
            }
            if (self::evidenceMissing($row)) {
                $missing++;
            }
        }
        $score = self::score($rows);

        return [
            'total'            => count($rows),
            'answered'         => $answered,
            'not_applicable'   => $notApplicable,
            'gaps'             => $gaps,
            'missing_evidence' => $missing,
            'score'            => $score,
            'level'            => SupplierQuestionnaire::level($score),
            'complete'         => count($rows) > 0 && $answered === count($rows),
        ];
    }

    /**
     * La question exige une pièce justificative, a reçu une réponse autre que « sans objet » et n'en
     * a pas.
     *
     * @param array{answer?: mixed, requires_evidence?: mixed, documents_id?: mixed} $row
     */
    public static function evidenceMissing(array $row): bool
    {
        $answer = self::normalizeAnswer($row['answer'] ?? '');

        return !empty($row['requires_evidence'])
            && $answer !== '' && $answer !== self::ANSWER_NA
            && (int) ($row['documents_id'] ?? 0) <= 0;
    }

    /**
     * Empreinte de la structure d'un modèle (codes, réponses attendues, pondérations, pièces
     * exigées, textes) : l'issue #865 la reporte dans le fichier exporté pour reconnaître au retour
     * un fichier fait sur une autre version du modèle.
     *
     * @param list<array{code?: mixed, question?: mixed, expected_answer?: mixed, weight?: mixed,
     *     requires_evidence?: mixed}> $questions
     */
    public static function fingerprint(array $questions): string
    {
        $parts = [];
        foreach ($questions as $question) {
            $parts[] = implode("\x1F", [
                (string) ($question['code'] ?? ''),
                self::normalizeExpected($question['expected_answer'] ?? ''),
                (string) self::normalizeWeight($question['weight'] ?? self::DEFAULT_WEIGHT),
                empty($question['requires_evidence']) ? '0' : '1',
                (string) ($question['question'] ?? ''),
            ]);
        }

        return substr(hash('sha256', implode("\x1E", $parts)), 0, 16);
    }

    /**
     * Prochain code libre pour une nouvelle question (« Q01 », « Q02 »...).
     *
     * @param list<string> $used
     */
    public static function nextCode(array $used): string
    {
        $index = count($used) + 1;
        do {
            $code = sprintf('Q%02d', $index++);
        } while (in_array($code, $used, true));

        return $code;
    }
}
