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

namespace GlpiPlugin\Grcmanager\Services\Setup;

/**
 * Issue #1046 : liste de démarrage de l'administrateur (onglet Général de la configuration),
 * modèle des « Quick Start » des outils GRC du marché. Règles en PHP pur : l'état de chaque étape
 * est lu ailleurs (StartChecklist), ici on calcule l'avancement. Rien n'est jamais modifié.
 *
 * @phpstan-type Step array{key: string, done: bool}
 */
final class StartChecklistRules
{
    /** Étapes, dans l'ordre d'affichage. */
    public const STEPS = ['frameworks', 'risk_matrix', 'profiles', 'ciso_group', 'connector', 'soa_scope'];

    /**
     * Avancement : nombre d'étapes faites, pourcentage arrondi vers le bas (100 seulement quand
     * tout est fait) et liste complète.
     *
     * @param array<string, bool> $done clé d'étape => faite ; une étape absente compte comme à faire
     * @return array{steps: list<Step>, done: int, total: int, percent: int, complete: bool}
     */
    public static function summarize(array $done): array
    {
        $steps = [];
        $count = 0;
        foreach (self::STEPS as $key) {
            $isDone = ($done[$key] ?? false) === true;
            $count += $isDone ? 1 : 0;
            $steps[] = ['key' => $key, 'done' => $isDone];
        }
        $total = count(self::STEPS);

        return [
            'steps'    => $steps,
            'done'     => $count,
            'total'    => $total,
            'percent'  => intdiv($count * 100, $total),
            'complete' => $count === $total,
        ];
    }

    /**
     * La matrice des risques est-elle validée par l'administrateur ? Oui dès qu'elle a été
     * enregistrée depuis la configuration ($validatedAt), ou, pour une installation antérieure à ce
     * repère, dès qu'elle diffère de la grille livrée (taille, niveaux ou libellés).
     *
     * @param array<mixed> $matrix grille actuelle
     * @param array<mixed> $defaultMatrix grille livrée, pour la taille par défaut
     * @param array<string, array<string, string>> $labels libellés personnalisés par axe
     */
    public static function isMatrixValidated(
        string $validatedAt,
        array $matrix,
        array $defaultMatrix,
        int $size,
        int $defaultSize,
        array $labels
    ): bool {
        if (trim($validatedAt) !== '' || $size !== $defaultSize || $matrix != $defaultMatrix) {
            return true;
        }
        foreach ($labels as $axis) {
            if ($axis !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un connecteur est-il « testé » ? Au moins une lecture réussie : une date, sans erreur.
     *
     * @param list<array{date: string, error: string}> $reads dernière lecture de chaque connecteur
     */
    public static function hasSuccessfulRead(array $reads): bool
    {
        foreach ($reads as $read) {
            if (trim($read['date']) !== '' && trim($read['error']) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * La SoA est-elle cadrée ? Toutes les questions du questionnaire de cadrage ont une réponse.
     *
     * @param array<string, string> $answers question => réponse
     * @param list<string> $questions questions du questionnaire
     */
    public static function isSoaScoped(array $answers, array $questions): bool
    {
        if ($questions === []) {
            return false;
        }
        foreach ($questions as $question) {
            if (($answers[$question] ?? '') === '') {
                return false;
            }
        }

        return true;
    }
}
