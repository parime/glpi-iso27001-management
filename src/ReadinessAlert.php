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
use GlpiPlugin\Grcmanager\Compat\Base\ReadinessAlertBase;
use NotificationEvent;
use Session;

/**
 * Issue #761 : alerte au responsable de la préparation à la certification quand une étape non
 * faite approche ou dépasse son échéance conseillée (notification modifiable « GRC Manager -
 * Certification step at risk »). Aucune modification automatique : l'alerte ne fait que prévenir.
 *
 * Chaque envoi est enregistré ici, avec les étapes signalées : une étape n'est signalée qu'une fois
 * par état (à risque, puis en retard) pour une même date cible.
 *
 * Droits : la tâche automatique évalue les étapes comme GLPI le fait pour toute tâche (sans
 * restriction) ; le responsable ne reçoit l'alerte que s'il peut lire le plugin sur toutes les
 * entités (NotificationSupport::canReceive(), entité racine et sous-entités), puisque l'état
 * couvre tout le SMSI.
 */
class ReadinessAlert extends ReadinessAlertBase
{
    /** Même droit que l'écran de préparation ; lu par la classe parente et le test de compatibilité. */
    public const RIGHTNAME = CertificationReadiness::RIGHTNAME;

    public const EVENT = 'readiness_step_at_risk';
    public const CRON_NAME = 'certificationreadiness';

    /** Alertes conservées : au-delà, les plus anciennes sont supprimées. */
    private const KEPT = 100;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_readinessalerts';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Certification readiness alert', 'Certification readiness alerts', $nb, 'grcmanager');
    }

    /**
     * Étapes signalées dans l'alerte (clé, échéance conseillée, état).
     *
     * @return list<array{step: string, deadline: string, state: string}>
     */
    public function steps(): array
    {
        $steps = json_decode((string) ($this->fields['steps'] ?? ''), true);

        return is_array($steps) ? array_values(array_filter($steps, 'is_array')) : [];
    }

    /**
     * @return array{description: string}|array{}
     */
    public static function cronInfo($name)
    {
        return $name === self::CRON_NAME
            ? [
                'description' => __(
                    'Warns the owner when a certification readiness step risks being late',
                    'grcmanager'
                ),
            ]
            : [];
    }

    public static function cronCertificationreadiness(CronTask $task): int
    {
        $alerted = self::runAlerts(date('Y-m-d'));
        $task->addVolume($alerted);
        $task->log(sprintf(__('%d certification readiness step(s) reported.', 'grcmanager'), $alerted));

        return $alerted > 0 ? 1 : 0;
    }

    /**
     * Signale au responsable les étapes à risque ou en retard qui ne l'ont pas encore été.
     *
     * @return int nombre d'étapes signalées
     */
    public static function runAlerts(string $today): int
    {
        global $DB;

        $report = CertificationReadiness::report($today);
        $plan = $report['plan'];
        $owner = (int) $plan['owner'];
        if ($plan['target'] === null || $owner <= 0) {
            return 0;
        }
        if (!NotificationSupport::canReceive($owner, 0, true, [CertificationReadiness::RIGHTNAME => READ])) {
            return 0;
        }
        $target = (string) $plan['target']['date'];
        $known = self::alertedKeys($target);
        $new = array_values(array_filter(
            CertificationReadiness::stepsToAlert($report),
            static fn (array $s): bool => !isset($known[self::key($s)])
        ));
        if ($new === []) {
            return 0;
        }

        $alert = new self();
        $id = (int) $alert->add([
            'entities_id'   => 0,
            'users_id'      => $owner,
            'target_date'   => $target,
            'steps'         => json_encode($new),
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
     * @param array{step: string, deadline: string, state: string} $step
     */
    private static function key(array $step): string
    {
        return $step['step'] . '|' . $step['deadline'] . '|' . $step['state'];
    }

    /**
     * Étapes déjà signalées pour cette date cible.
     *
     * @return array<string, true>
     */
    private static function alertedKeys(string $target): array
    {
        global $DB;

        $keys = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['target_date' => $target]]) as $row) {
            $alert = new self();
            $alert->fields = $row;
            foreach ($alert->steps() as $step) {
                if (isset($step['step'], $step['deadline'], $step['state'])) {
                    $keys[self::key($step)] = true;
                }
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
