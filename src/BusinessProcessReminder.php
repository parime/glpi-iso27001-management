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

namespace GlpiPlugin\Grcmanager;

use GlpiPlugin\Grcmanager\Services\Continuity\RecoveryStrategyRules;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Review\PeriodicReviewCatalog;
use NotificationEvent;

/**
 * Issue #377, quatrième étape : échéances de la continuité d'un processus métier, et tâche
 * planifiée qui les rappelle (BusinessProcess::cronBusinessprocessreminder()).
 *
 * - BIA à revoir : date de la dernière BIA plus la fréquence de la revue périodique « Revue de
 *   l'analyse d'impact sur l'activité » (catalogue PeriodicReviewCatalog, annuelle par défaut).
 * - Test de reprise à refaire : date du dernier test mesuré plus la fréquence de la revue
 *   périodique « Test du plan de continuité et de reprise ».
 *
 * Mêmes règles d'échéance que les tests d'efficacité (ControlTestRules : à venir dans les 30 jours,
 * puis en retard) et même mécanisme de notification (NotificationSupport) : un seul rappel par
 * échéance et par état (colonnes `bia_reminder_key` et `recovery_reminder_key` du processus), au
 * responsable du processus et au groupe RSSI (AuditTeam::getCisoGroupId(), fourni aussi par
 * Configuration GLPI Auto), seulement s'ils ont le droit de la continuité dans l'entité du processus.
 */
final class BusinessProcessReminder
{
    public const EVENT_BIA = 'businessprocess_bia_review';
    public const EVENT_RECOVERY = 'businessprocess_recovery_test';
    public const CRON_NAME = 'businessprocessreminder';

    /** Revues périodiques dont la fréquence fixe chaque échéance. */
    public const BIA_REVIEW = 'business_impact';
    public const RECOVERY_REVIEW = 'continuity_test';

    /** Colonne du processus qui garde le dernier rappel envoyé, par événement. */
    public const KEY_FIELDS = [
        self::EVENT_BIA      => 'bia_reminder_key',
        self::EVENT_RECOVERY => 'recovery_reminder_key',
    ];

    /**
     * Fréquence d'une revue périodique du catalogue, en mois : celle réglée dans Revues périodiques
     * (même si la revue n'est pas activée), sinon celle du catalogue.
     */
    public static function frequency(string $reviewKey): int
    {
        $review = PeriodicReview::getByKey($reviewKey);
        $months = $review !== null ? (int) $review->fields['frequency_months'] : 0;
        if ($months <= 0) {
            $months = PeriodicReviewCatalog::defaultMonths($reviewKey);
        }

        return PeriodicReviewCatalog::normalizeMonths($months);
    }

    /**
     * BIA à revoir le : dernière BIA plus la fréquence de la revue, ou null sans BIA.
     *
     * @param array<string, mixed> $fields champs du processus
     */
    public static function biaDueDate(array $fields, ?int $months = null): ?string
    {
        return ControlTestRules::nextTestDate(
            (string) ($fields['bia_date'] ?? ''),
            null,
            $months ?? self::frequency(self::BIA_REVIEW)
        );
    }

    /**
     * Prochain test de reprise dû le : dernier test plus la fréquence de la revue, ou null sans test.
     */
    public static function recoveryDueDate(?string $lastTestDate, ?int $months = null): ?string
    {
        return ControlTestRules::nextTestDate(
            (string) $lastTestDate,
            null,
            $months ?? self::frequency(self::RECOVERY_REVIEW)
        );
    }

    /**
     * Dernier test de reprise de chaque processus (date et temps mesuré), en une requête.
     *
     * @return array<int, array{test_date: string, measured_minutes: int}>
     */
    public static function lastTests(): array
    {
        global $DB;

        $latest = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'plugin_grcmanager_businessprocesses_id', 'test_date', 'measured_minutes'],
                'FROM'   => BusinessProcessRecovery::getTable(),
                'ORDER'  => ['test_date DESC', 'id DESC'],
            ]) as $row
        ) {
            $latest[(int) $row['plugin_grcmanager_businessprocesses_id']] ??= [
                'test_date'        => (string) $row['test_date'],
                'measured_minutes' => (int) $row['measured_minutes'],
            ];
        }

        return $latest;
    }

    /**
     * Échéances d'un processus.
     *
     * @param array<string, mixed>                                   $fields champs du processus
     * @param array{test_date: string, measured_minutes: int}|null $last   dernier test de reprise
     * @return array<string, string|null> événement => échéance
     */
    public static function dueDates(array $fields, ?array $last, int $biaMonths, int $recoveryMonths): array
    {
        return [
            self::EVENT_BIA      => self::biaDueDate($fields, $biaMonths),
            self::EVENT_RECOVERY => self::recoveryDueDate($last['test_date'] ?? null, $recoveryMonths),
        ];
    }

    /**
     * Le compte GLPI peut-il recevoir le rappel de ce processus ? Il lui faut le droit de lecture de
     * la continuité sur un profil valable dans l'entité du processus (mêmes critères que GLPI). Une
     * adresse sans compte GLPI ne reçoit rien.
     */
    public static function canReceive(int $usersId, BusinessProcess $process): bool
    {
        return NotificationSupport::canReceive(
            $usersId,
            (int) ($process->fields['entities_id'] ?? 0),
            (bool) ($process->fields['is_recursive'] ?? false),
            [BusinessProcess::RIGHTNAME => READ]
        );
    }

    /**
     * Rappelle chaque échéance proche ou dépassée, une fois par échéance et par état.
     *
     * @param bool $notify faux : enregistre seulement l'état actuel (mise à jour du plugin, pour ne
     *                     pas envoyer d'un coup les rappels d'échéances déjà passées)
     * @return array<string, int> événement => nombre de rappels
     */
    public static function run(string $today, bool $notify = true): array
    {
        global $DB;

        $result = array_fill_keys(array_keys(self::KEY_FIELDS), 0);
        $biaMonths = self::frequency(self::BIA_REVIEW);
        $recoveryMonths = self::frequency(self::RECOVERY_REVIEW);
        $lastTests = self::lastTests();
        $process = new BusinessProcess();
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => BusinessProcess::getTable()]) as $row) {
            $processId = (int) $row['id'];
            if (!$process->getFromDB($processId)) {
                continue;
            }
            $dueDates = self::dueDates($process->fields, $lastTests[$processId] ?? null, $biaMonths, $recoveryMonths);
            foreach ($dueDates as $event => $due) {
                $field = self::KEY_FIELDS[$event];
                $key = ControlTestRules::reminderKey($due, $today) ?? '';
                if ($key === (string) ($process->fields[$field] ?? '')) {
                    continue;
                }
                if ($key !== '' && $notify) {
                    NotificationEvent::raiseEvent($event, $process, [
                        'due_status' => ControlTestRules::dueStatus($due, $today),
                        'due_date'   => $due,
                    ]);
                    $result[$event]++;
                }
                // Écriture directe : un rappel n'est pas une modification du processus (ni historique,
                // ni date de modification). Une échéance repoussée (nouvelle BIA, nouveau test) vide la
                // clé et réarme le rappel suivant.
                $DB->update(BusinessProcess::getTable(), [$field => $key], ['id' => $processId]);
                $process->fields[$field] = $key;
            }
        }

        return $result;
    }

    /**
     * Indicateurs du tableau de bord, sur les processus des entités de la personne : sans BIA ou
     * BIA à revoir dépassée, et dernier test de reprise au-delà du RTO.
     *
     * @return array{bia_due: int, recovery_above_rto: int}
     */
    public static function dashboardCounts(string $today): array
    {
        $counts = ['bia_due' => 0, 'recovery_above_rto' => 0];
        if (!BusinessProcess::canView()) {
            return $counts;
        }
        $biaMonths = self::frequency(self::BIA_REVIEW);
        $lastTests = self::lastTests();
        $criteria = getEntitiesRestrictCriteria(BusinessProcess::getTable(), '', '', true);
        foreach ((new BusinessProcess())->find($criteria) as $row) {
            $due = self::biaDueDate($row, $biaMonths);
            if ($due === null || $due < $today) {
                $counts['bia_due']++;
            }
            $last = $lastTests[(int) $row['id']] ?? null;
            if (
                $last !== null
                && RecoveryStrategyRules::exceedsRto((int) $row['rto_hours'], $last['measured_minutes'])
            ) {
                $counts['recovery_above_rto']++;
            }
        }

        return $counts;
    }
}
