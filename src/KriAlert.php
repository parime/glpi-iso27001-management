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

use Config;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use NotificationEvent;

/**
 * Issue #378, deuxième étape : alerte quand un KRI passe au rouge (ou à l'orange, selon le
 * réglage), puis au retour au vert, une seule fois par changement d'état (colonne `alert_status`
 * du KRI). Appelée chaque fois que le statut du KRI est recalculé (RiskIndicator::refresh()) :
 * relevé manuel, relevé de la tâche planifiée, relevé supprimé, seuils modifiés.
 *
 * Notifications modifiables « GRC Manager - Indicateur de risque en alerte » et « ... revenu au
 * vert » (NotificationTargetRiskIndicator), au responsable du KRI et au groupe RSSI
 * (AuditTeam::getCisoGroupId(), fourni aussi par Configuration GLPI Auto). Même mécanisme que le
 * rappel des tests d'efficacité (NotificationSupport) : droits du destinataire, charte de CGA.
 */
final class KriAlert
{
    public const EVENT_ALERT = 'kri_alert';
    public const EVENT_CLEARED = 'kri_cleared';

    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const LEVEL_KEY = 'kri_alert_level';

    public static function level(): string
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::LEVEL_KEY]);

        return KriRules::normalizeAlertLevel($values[self::LEVEL_KEY] ?? '');
    }

    /**
     * @return bool vrai si le réglage a changé
     */
    public static function saveLevel(mixed $level): bool
    {
        $before = self::level();
        $after = KriRules::normalizeAlertLevel($level);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::LEVEL_KEY => $after]);

        return $before !== $after;
    }

    /**
     * @return array<string, string> réglage => libellé
     */
    public static function getLevels(): array
    {
        return [
            KriRules::RED    => __('When an indicator turns red', 'grcmanager'),
            KriRules::ORANGE => __('From orange', 'grcmanager'),
        ];
    }

    /**
     * Droits de lecture nécessaires pour recevoir l'alerte : ceux du KRI et, pour une source
     * automatique, ceux des données mesurées sur tout le périmètre (même règle que l'affichage,
     * RiskIndicator::canUseSource()).
     *
     * @return array{rights: array<string, int>, perimeter: list<int>}
     */
    public static function requiredRights(RiskIndicator $kri): array
    {
        $rights = [RiskIndicator::RIGHTNAME => READ];
        $perimeter = [];
        $definition = RiskIndicator::SOURCES[(string) ($kri->fields['source'] ?? '')] ?? null;
        if ($definition !== null) {
            $rights += $definition['rights'];
            if (!$definition['global']) {
                $perimeter = RiskIndicator::perimeter((int) $kri->fields['entities_id']);
            }
        }

        return ['rights' => $rights, 'perimeter' => $perimeter];
    }

    public static function canReceive(int $usersId, RiskIndicator $kri): bool
    {
        $required = self::requiredRights($kri);

        return NotificationSupport::canReceive(
            $usersId,
            (int) ($kri->fields['entities_id'] ?? 0),
            false,
            $required['rights'],
            $required['perimeter']
        );
    }

    /**
     * Compare le statut du KRI à la dernière alerte envoyée et prévient si l'état a changé.
     *
     * @param string $previous statut avant le recalcul
     * @return string|null événement envoyé
     */
    public static function check(RiskIndicator $kri, string $previous): ?string
    {
        global $DB;

        if (empty($kri->fields['is_active'])) {
            return null;
        }
        $notified = (string) ($kri->fields['alert_status'] ?? '');
        $status = (string) ($kri->fields['status'] ?? KriRules::NONE);
        $transition = KriRules::alertTransition($notified, $status, self::level());
        if ($transition['notified'] !== $notified) {
            // Écriture directe : l'état de l'alerte n'est pas une modification du KRI.
            $DB->update(
                RiskIndicator::getTable(),
                ['alert_status' => $transition['notified']],
                ['id' => $kri->getID()]
            );
            $kri->fields['alert_status'] = $transition['notified'];
        }
        if ($transition['event'] === null) {
            return null;
        }
        $event = $transition['event'] === 'alert' ? self::EVENT_ALERT : self::EVENT_CLEARED;
        NotificationEvent::raiseEvent($event, $kri, ['previous_status' => $previous]);

        return $event;
    }
}
