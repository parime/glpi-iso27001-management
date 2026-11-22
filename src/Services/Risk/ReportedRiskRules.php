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
 * Issue #872 : risques signalés par les collaborateurs (ISO/IEC 27001:2022, 6.1.2 ; mesure A.6.8).
 *
 * Un collaborateur, même sans aucun droit sur le plugin, signale un risque ou une situation
 * dangereuse ; le responsable des risques trie ensuite chaque signalement : accepté dans le
 * registre (un nouveau risque est créé), rattaché à un risque existant, ou rejeté avec un motif.
 * Une décision est définitive et n'est prise que par une personne : rien n'est trié automatiquement.
 *
 * PHP pur, sans dépendance à GLPI : les libellés traduits sont dans \GlpiPlugin\Grcmanager\ReportedRisk.
 */
final class ReportedRiskRules
{
    public const STATUS_NEW = 'new';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_MERGED = 'merged';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [self::STATUS_NEW, self::STATUS_ACCEPTED, self::STATUS_MERGED, self::STATUS_REJECTED];

    /** Décisions du tri (le statut qui en résulte porte le même code). */
    public const DECISIONS = [self::STATUS_ACCEPTED, self::STATUS_MERGED, self::STATUS_REJECTED];

    /** Gravité ressentie par la personne qui signale (indication pour le tri, jamais reprise telle quelle). */
    public const SEVERITY_UNKNOWN = '';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITIES = [self::SEVERITY_UNKNOWN, self::SEVERITY_LOW, self::SEVERITY_MEDIUM, self::SEVERITY_HIGH];

    public const NAME_MAX = 255;
    public const DESCRIPTION_MAX = 10000;
    public const REASON_MAX = 5000;

    public const ERROR_NAME_REQUIRED = 'name_required';
    public const ERROR_NAME_TOO_LONG = 'name_too_long';
    public const ERROR_DESCRIPTION_REQUIRED = 'description_required';
    public const ERROR_DESCRIPTION_TOO_LONG = 'description_too_long';
    public const ERROR_UNKNOWN_DECISION = 'unknown_decision';
    public const ERROR_ALREADY_DECIDED = 'already_decided';
    public const ERROR_REASON_REQUIRED = 'reason_required';
    public const ERROR_REASON_TOO_LONG = 'reason_too_long';
    public const ERROR_RISK_REQUIRED = 'risk_required';

    /**
     * Contrôle d'un signalement : un titre et une description, de taille raisonnable.
     */
    public static function reportError(string $name, string $description): ?string
    {
        $name = trim($name);
        $description = trim($description);
        if ($name === '') {
            return self::ERROR_NAME_REQUIRED;
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return self::ERROR_NAME_TOO_LONG;
        }
        if ($description === '') {
            return self::ERROR_DESCRIPTION_REQUIRED;
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            return self::ERROR_DESCRIPTION_TOO_LONG;
        }

        return null;
    }

    /** Gravité ressentie connue, sinon « non précisée ». */
    public static function normalizeSeverity(string $severity): string
    {
        return in_array($severity, self::SEVERITIES, true) ? $severity : self::SEVERITY_UNKNOWN;
    }

    /**
     * Contrôle d'une décision de tri : seul un signalement nouveau se trie, un rejet exige un motif,
     * un rattachement exige le risque existant choisi.
     */
    public static function decisionError(string $status, string $decision, string $reason, int $riskId): ?string
    {
        if (!in_array($decision, self::DECISIONS, true)) {
            return self::ERROR_UNKNOWN_DECISION;
        }
        if ($status !== self::STATUS_NEW) {
            return self::ERROR_ALREADY_DECIDED;
        }
        if (mb_strlen(trim($reason)) > self::REASON_MAX) {
            return self::ERROR_REASON_TOO_LONG;
        }
        if ($decision === self::STATUS_REJECTED && trim($reason) === '') {
            return self::ERROR_REASON_REQUIRED;
        }
        if ($decision === self::STATUS_MERGED && $riskId <= 0) {
            return self::ERROR_RISK_REQUIRED;
        }

        return null;
    }

    /**
     * Nombre de signalements par statut (tous les statuts présents, à zéro au besoin).
     *
     * @param list<array{status: string}> $rows
     * @return array<string, int>
     */
    public static function summary(array $rows): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']]++;
            }
        }

        return $counts;
    }

    /**
     * Ordre de la file : les nouveaux d'abord (gravité ressentie la plus forte, puis le plus ancien),
     * ensuite les signalements triés, du plus récent au plus ancien.
     *
     * @param list<array{status: string, severity: string, date_creation: string}> $rows
     * @return list<array{status: string, severity: string, date_creation: string}>
     */
    public static function sortQueue(array $rows): array
    {
        $weight = [self::SEVERITY_HIGH => 0, self::SEVERITY_MEDIUM => 1, self::SEVERITY_LOW => 2];
        usort($rows, static function (array $x, array $y) use ($weight): int {
            $xNew = $x['status'] === self::STATUS_NEW;
            $yNew = $y['status'] === self::STATUS_NEW;
            if ($xNew !== $yNew) {
                return $xNew ? -1 : 1;
            }
            if ($xNew) {
                return [$weight[$x['severity']] ?? 3, $x['date_creation']]
                    <=> [$weight[$y['severity']] ?? 3, $y['date_creation']];
            }

            return $y['date_creation'] <=> $x['date_creation'];
        });

        return $rows;
    }
}
