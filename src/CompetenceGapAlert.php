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

use CronTask;
use GlpiPlugin\Grcmanager\Compat\Base\CompetenceGapAlertBase;
use GlpiPlugin\Grcmanager\Services\Competence\CompetenceRules as Rules;
use NotificationEvent;
use Session;

/**
 * Issue #774, lot 3 : prévient le responsable des écarts de compétence (article 7.2) qui n'ont
 * aucune formation planifiée (notification modifiable « GRC Manager - Competence gaps without
 * planned training »). Aucune modification automatique : ni inscription, ni session, ni droit.
 *
 * Chaque envoi est enregistré ici avec les écarts signalés (personne, compétence, niveau exigé) :
 * un écart n'est signalé qu'une fois (tant qu'il reste dans l'historique des dernières alertes).
 *
 * Droits : la tâche automatique calcule les écarts de toutes les entités (comme GLPI le fait pour
 * toute tâche) ; un destinataire ne reçoit l'alerte que s'il lit le plugin et les utilisateurs sur
 * toutes les entités (NotificationTargetCompetenceGapAlert::validateSendTo()), puisqu'elle cite
 * des personnes de tout le SMSI.
 */
class CompetenceGapAlert extends CompetenceGapAlertBase
{
    /** Même droit que les fonctions du SMSI ; lu par la classe parente et le test de compatibilité. */
    public const RIGHTNAME = IsmsFunction::RIGHTNAME;

    public const EVENT = 'competence_gaps_unplanned';
    public const CRON_NAME = 'competencegaps';

    /** Écarts cités dans un envoi : au-delà, le nombre seul est donné. */
    public const MAX_LISTED = 50;

    /** Alertes conservées : au-delà, les plus anciennes sont supprimées. */
    private const KEPT = 100;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_competencegapalerts';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Competence gap alert', 'Competence gap alerts', $nb, 'grcmanager');
    }

    /**
     * Écarts signalés dans l'alerte.
     *
     * @return list<array{person: int, competence_id: int, level: int, trainings: list<int>}>
     */
    public function gaps(): array
    {
        $gaps = json_decode((string) ($this->fields['gaps'] ?? ''), true);
        if (!is_array($gaps)) {
            return [];
        }
        $list = [];
        foreach ($gaps as $gap) {
            if (is_array($gap) && isset($gap['person'], $gap['competence_id'], $gap['level'])) {
                $list[] = [
                    'person'        => (int) $gap['person'],
                    'competence_id' => (int) $gap['competence_id'],
                    'level'         => Rules::normalizeLevel($gap['level']),
                    'trainings'     => array_values(array_map('intval', (array) ($gap['trainings'] ?? []))),
                ];
            }
        }

        return $list;
    }

    /**
     * @return array{description: string}|array{}
     */
    public static function cronInfo($name)
    {
        return $name === self::CRON_NAME
            ? [
                'description' => __(
                    'Warns the owner of the competence gaps that have no planned training',
                    'grcmanager'
                ),
            ]
            : [];
    }

    public static function cronCompetencegaps(CronTask $task): int
    {
        $alerted = self::runAlerts();
        $task->addVolume($alerted);
        $task->log(sprintf(__('%d competence gap(s) reported.', 'grcmanager'), $alerted));

        return $alerted > 0 ? 1 : 0;
    }

    /**
     * Signale les écarts sans formation planifiée qui ne l'ont pas encore été. Le responsable est
     * celui de la préparation à la certification ; d'autres destinataires (groupe RSSI, profils)
     * se règlent dans la notification.
     *
     * @return int nombre d'écarts signalés
     */
    public static function runAlerts(): int
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return 0;
        }
        $unplanned = [];
        foreach (CompetenceMatrix::data(null, false)['gaps'] as $gap) {
            if ($gap['planned'] !== []) {
                continue;
            }
            $unplanned[] = [
                'person'        => (int) $gap['person'],
                'competence_id' => (int) $gap['competence_id'],
                'level'         => (int) $gap['level'],
                'trainings'     => array_map(static fn (array $t): int => (int) $t['id'], $gap['trainings']),
            ];
        }
        $new = Rules::newGaps($unplanned, self::alertedKeys());
        if ($new === []) {
            return 0;
        }

        $alert = new self();
        $id = (int) $alert->add([
            'entities_id'   => 0,
            'users_id'      => CertificationReadiness::getSettings()['owner'],
            'gaps'          => json_encode($new),
            'total'         => count($unplanned),
            'is_sent'       => 0,
            'date_creation' => Session::getCurrentTime() ?: date('Y-m-d H:i:s'),
        ]);
        if ($id <= 0 || !$alert->getFromDB($id)) {
            return 0;
        }
        $raised = NotificationEvent::raiseEvent(self::EVENT, $alert);
        $DB->update(self::getTable(), ['is_sent' => (int) $raised], ['id' => $id]);
        self::prune();

        return count($new);
    }

    /**
     * Écarts déjà signalés.
     *
     * @return array<string, true>
     */
    private static function alertedKeys(): array
    {
        global $DB;

        $keys = [];
        foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
            $alert = new self();
            $alert->fields = $row;
            foreach ($alert->gaps() as $gap) {
                $keys[Rules::gapKey($gap['person'], $gap['competence_id'], $gap['level'])] = true;
            }
        }

        return $keys;
    }

    /** Historique borné : seules les dernières alertes servent à ne pas répéter un signalement. */
    private static function prune(): void
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::getTable(),
                'ORDER'  => ['id DESC'],
                'START'  => self::KEPT,
                'LIMIT'  => 1000,
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }
        if ($ids !== []) {
            $DB->delete(self::getTable(), ['id' => $ids]);
        }
    }
}
