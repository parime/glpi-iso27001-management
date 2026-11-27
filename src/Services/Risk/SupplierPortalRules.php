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
 * Issue #865, lot 2 : règles du lien sécurisé de réponse du fournisseur (sans base de données).
 * Jeton de 32 octets aléatoires dont seule l'empreinte est gardée ; état d'un lien ; réponses
 * reçues vérifiées question par question ; limitation de débit par fenêtre fixe.
 */
final class SupplierPortalRules
{
    public const TOKEN_BYTES = 32;

    public const DEFAULT_DAYS = 14;
    public const MAX_DAYS = 60;
    public const DEFAULT_USES = 1;
    public const MAX_USES = 5;

    /** Taille d'une pièce reçue par le lien (bornée aussi par le réglage de GLPI). */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_FILES = 50;
    public const MAX_COMMENT_LENGTH = 4000;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_USED = 'used';
    public const STATUS_CLOSED = 'closed';

    public const SOURCE_LINK = 'link';

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public static function isWellFormed(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[0-9a-f]{' . (self::TOKEN_BYTES * 2) . '}$/D', $token) === 1;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normalizeDays(mixed $days): int
    {
        return self::bounded($days, self::DEFAULT_DAYS, self::MAX_DAYS);
    }

    public static function normalizeUses(mixed $uses): int
    {
        return self::bounded($uses, self::DEFAULT_USES, self::MAX_USES);
    }

    /**
     * État d'un lien : révoqué, évaluation terminée, envois épuisés, expiré, sinon actif.
     *
     * @param array<string, mixed> $link
     */
    public static function status(array $link, string $now, bool $evaluationCompleted): string
    {
        $expiration = (string) ($link['date_expiration'] ?? '');

        return match (true) {
            !empty($link['is_revoked'])                                  => self::STATUS_REVOKED,
            $evaluationCompleted                                         => self::STATUS_CLOSED,
            (int) ($link['uses'] ?? 0) >= (int) ($link['max_uses'] ?? 0) => self::STATUS_USED,
            $expiration === '' || $expiration <= $now                    => self::STATUS_EXPIRED,
            default                                                      => self::STATUS_ACTIVE,
        };
    }

    /**
     * Réponses envoyées par le fournisseur, gardées seulement pour les questions de l'évaluation.
     * Une réponse hors liste est une erreur ; une question sans réponse ni commentaire est ignorée.
     *
     * @param list<array<string, mixed>> $rows     lignes de réponse de l'évaluation
     * @param mixed                      $answers  id de la réponse => réponse
     * @param mixed                      $comments id de la réponse => commentaire
     * @return array{answers: array<int, array{answer: string, comment: string}>, errors: list<string>}
     */
    public static function collect(array $rows, mixed $answers, mixed $comments): array
    {
        $answers = is_array($answers) ? $answers : [];
        $comments = is_array($comments) ? $comments : [];
        $result = ['answers' => [], 'errors' => []];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $answer = $answers[$id] ?? '';
            $answer = is_string($answer) ? trim($answer) : '';
            if ($answer !== '' && !in_array($answer, WeightedQuestionnaire::ANSWERS, true)) {
                $result['errors'][] = (string) $row['code'];
                continue;
            }
            $comment = WeightedQuestionnaire::cleanText($comments[$id] ?? '', self::MAX_COMMENT_LENGTH);
            if ($answer === '' && $comment === '') {
                continue;
            }
            $result['answers'][$id] = ['answer' => $answer, 'comment' => $comment];
        }

        return $result;
    }

    /**
     * Nom d'une pièce reçue, préfixé du code de la question pour qu'il reste unique.
     */
    public static function evidenceName(string $code, string $safeName): string
    {
        return mb_substr(WeightedQuestionnaire::cleanCode($code) . '-' . $safeName, 0, 180);
    }

    /**
     * Brouillon converti au format de l'aperçu du fichier (lot 1), face aux réponses actuelles : la
     * relecture et l'enregistrement sont les mêmes. Les lignes qui ne sont plus dans l'évaluation
     * sont ignorées.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<mixed>               $draftAnswers id => [answer, comment]
     * @param array<mixed>               $draftFiles   id => [name, ...]
     * @return array{error: null, changes: list<array<string, mixed>>, warnings: list<mixed>,
     *                evidence: array<string, list<int>>}
     */
    public static function changes(array $rows, array $draftAnswers, array $draftFiles): array
    {
        $result = ['error' => null, 'changes' => [], 'warnings' => [], 'evidence' => []];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $draft = is_array($draftAnswers[$id] ?? null) ? $draftAnswers[$id] : [];
            $file = is_array($draftFiles[$id] ?? null) ? $draftFiles[$id] : [];
            $oldAnswer = (string) $row['answer'];
            $oldComment = (string) ($row['answer_comment'] ?? '');
            $answer = (string) ($draft['answer'] ?? '');
            $comment = (string) ($draft['comment'] ?? '');
            $answer = $answer === '' || $answer === $oldAnswer
                || !in_array($answer, WeightedQuestionnaire::ANSWERS, true) ? null : $answer;
            $comment = $comment === '' || $comment === $oldComment ? null : $comment;
            $evidence = (string) ($file['name'] ?? '');
            if ($answer === null && $comment === null && $evidence === '') {
                continue;
            }
            if ($evidence !== '') {
                $result['evidence'][SupplierAnswerFile::evidenceName($evidence)][] = $id;
            }
            $result['changes'][] = [
                'id'          => $id,
                'code'        => (string) $row['code'],
                'question'    => (string) $row['question'],
                'line'        => 0,
                'old_answer'  => $oldAnswer,
                'answer'      => $answer,
                'old_comment' => $oldComment,
                'comment'     => $comment,
                'evidence'    => $evidence,
                'answer_type' => WeightedQuestionnaire::normalizeAnswerType($row['answer_type'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Fenêtre fixe : nouveau compteur si la fenêtre est passée, refus au-delà de la limite.
     *
     * @param array<string, mixed>|null $row compteur actuel (hits, window_start)
     * @return array{hits: int, window_start: string, allowed: bool}
     */
    public static function throttle(?array $row, string $now, int $limit, int $windowSeconds): array
    {
        $start = (string) ($row['window_start'] ?? '');
        if ($row === null || $start === '' || (int) strtotime($start) + $windowSeconds <= (int) strtotime($now)) {
            return ['hits' => 1, 'window_start' => $now, 'allowed' => $limit >= 1];
        }
        $hits = (int) ($row['hits'] ?? 0) + 1;

        return ['hits' => $hits, 'window_start' => $start, 'allowed' => $hits <= $limit];
    }

    private static function bounded(mixed $value, int $default, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(1, min($max, (int) $value));
    }
}
