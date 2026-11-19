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
 * Issue #607 : règles pures de l'échantillonnage d'un test d'efficacité (sans base ni session).
 *
 * Tirage reproductible : chaque élément de la population reçoit l'empreinte SHA-256 de
 * « graine:identifiant » ; les éléments sont classés par empreinte croissante (puis par
 * identifiant) et les N premiers forment l'échantillon. Même population et même graine donnent
 * toujours le même échantillon, quel que soit l'ordre de lecture de la population ou la version de
 * PHP ; un auditeur peut refaire le calcul avec n'importe quel outil SHA-256.
 */
final class SampleRules
{
    /** Identifiant de la méthode de tirage, conservé avec chaque échantillon. */
    public const METHOD = 'sha256-rank-v1';

    /** Populations GLPI proposées (itemtype => clé courte). */
    public const POPULATIONS = ['User', 'Computer', 'Ticket', 'Change'];

    public const ITEM_PENDING = '';
    public const ITEM_COMPLIANT = 'compliant';
    public const ITEM_NONCOMPLIANT = 'noncompliant';
    public const ITEM_NA = 'na';
    public const ITEM_RESULTS = [self::ITEM_COMPLIANT, self::ITEM_NONCOMPLIANT, self::ITEM_NA];

    /**
     * Taille proposée selon la fréquence de fonctionnement du contrôle (pratique courante des
     * auditeurs ; l'utilisateur peut toujours saisir une autre taille).
     */
    public const FREQUENCY_SIZES = [
        'annual'    => 1,
        'quarterly' => 2,
        'monthly'   => 2,
        'weekly'    => 5,
        'daily'     => 25,
        'recurring' => 40,
    ];
    public const DEFAULT_FREQUENCY = 'monthly';

    public const MAX_SAMPLE = 100;
    /** Population lue au plus : au-delà, l'utilisateur resserre les critères. */
    public const MAX_POPULATION = 50000;

    public const SEED_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public static function normalizePopulation(mixed $value): ?string
    {
        return in_array($value, self::POPULATIONS, true) ? (string) $value : null;
    }

    public static function normalizeFrequency(mixed $value): string
    {
        return is_string($value) && isset(self::FREQUENCY_SIZES[$value]) ? $value : self::DEFAULT_FREQUENCY;
    }

    /**
     * Taille de l'échantillon : celle saisie (1 à MAX_SAMPLE), sinon celle de la fréquence ;
     * jamais plus que la population (tout est alors tiré).
     */
    public static function sampleSize(mixed $requested, string $frequency, int $population): int
    {
        $size = is_numeric($requested) ? (int) $requested : 0;
        if ($size <= 0) {
            $size = self::FREQUENCY_SIZES[self::normalizeFrequency($frequency)];
        }
        $size = min($size, self::MAX_SAMPLE);

        return max(0, min($size, $population));
    }

    /**
     * Graine saisie (lettres, chiffres, tiret, souligné, 64 caractères au plus), null sinon.
     */
    public static function normalizeSeed(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match(self::SEED_PATTERN, $value) === 1 ? $value : null;
    }

    public static function newSeed(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function normalizeDate(mixed $value): ?string
    {
        return ControlTestRules::normalizeDate($value);
    }

    /**
     * Période couverte : null si elle est incohérente (début après la fin).
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    public static function period(mixed $start, mixed $end): ?array
    {
        $start = self::normalizeDate($start);
        $end = self::normalizeDate($end);
        if ($start !== null && $end !== null && $start > $end) {
            return null;
        }

        return [$start, $end];
    }

    public static function rankKey(string $seed, int $id): string
    {
        return hash('sha256', $seed . ':' . $id);
    }

    /**
     * Tirage sans remise : identifiants retenus, dans l'ordre du tirage, sans doublon.
     *
     * @param iterable<int|string> $ids population
     * @return list<int>
     */
    public static function draw(iterable $ids, int $size, string $seed): array
    {
        $keys = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $keys[$id] = self::rankKey($seed, $id);
            }
        }
        uksort($keys, static fn (int $a, int $b): int => strcmp($keys[$a], $keys[$b]) ?: $a <=> $b);

        return array_slice(array_keys($keys), 0, max(0, $size));
    }

    public static function normalizeItemResult(mixed $value): string
    {
        return in_array($value, self::ITEM_RESULTS, true) ? (string) $value : self::ITEM_PENDING;
    }

    /**
     * Un écart (non conforme) ou une exclusion (non applicable) se justifie par un commentaire.
     */
    public static function needsComment(string $result): bool
    {
        return $result === self::ITEM_NONCOMPLIANT || $result === self::ITEM_NA;
    }

    /**
     * Décompte des résultats des éléments tirés.
     *
     * @param list<array<string, mixed>> $items
     * @return array{total: int, compliant: int, noncompliant: int, na: int, pending: int, missing_comment: int}
     */
    public static function tally(array $items): array
    {
        $tally = ['total' => 0, 'compliant' => 0, 'noncompliant' => 0, 'na' => 0, 'pending' => 0, 'missing_comment' => 0];
        foreach ($items as $item) {
            $tally['total']++;
            $result = self::normalizeItemResult($item['result'] ?? '');
            $key = $result === self::ITEM_PENDING ? 'pending' : $result;
            $tally[$key]++;
            if (self::needsComment($result) && trim((string) ($item['comment'] ?? '')) === '') {
                $tally['missing_comment']++;
            }
        }

        return $tally;
    }

    /**
     * Résultat du test proposé à partir des écarts : aucun écart, efficace ; peu d'écarts (taux de
     * conformité d'au moins TECHNICAL_PARTIAL_THRESHOLD %), partiellement efficace ; sinon
     * inefficace. Les éléments non applicables ne comptent pas. Null tant qu'un élément reste à
     * évaluer, ou si tous sont non applicables.
     *
     * @param array{compliant: int, noncompliant: int, pending: int} $tally
     */
    public static function proposedResult(array $tally): ?string
    {
        if ($tally['pending'] > 0) {
            return null;
        }

        return ControlTestRules::resultFromTechnicalCheck(
            $tally['noncompliant'],
            $tally['compliant'] + $tally['noncompliant']
        );
    }

    /**
     * Le test peut-il être enregistré depuis l'échantillon ? Code d'erreur, null si oui.
     *
     * @param array{total: int, pending: int, missing_comment: int} $tally
     */
    public static function recordError(array $tally, ?string $proposed, string $chosen, string $justification): ?string
    {
        if ($tally['total'] === 0) {
            return 'empty';
        }
        if ($tally['pending'] > 0) {
            return 'pending';
        }
        if ($tally['missing_comment'] > 0) {
            return 'comment';
        }
        if ($proposed === null) {
            return 'all_na';
        }
        if (!ControlTestRules::isResult($chosen)) {
            return 'result';
        }
        if ($chosen !== $proposed && trim($justification) === '') {
            return 'justification';
        }

        return null;
    }
}
