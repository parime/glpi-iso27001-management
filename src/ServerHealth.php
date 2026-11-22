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

use GlpiPlugin\Grcmanager\Install\ServerHealthTables as Tables;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules as Rules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishHealthReader as Reader;
use Html;

/**
 * Issue #843, lot 3 : santé détaillée des serveurs (alimentations, disques, ventilateurs, températures)
 * et événements récents du journal de leur contrôleur, lus par Redfish en lecture seule avec le reste
 * du contrôleur (ServerManagement::read() ou la tâche automatique). Lecture seule de bout en bout :
 * rien n'est jamais envoyé au serveur, aucun statut ni ticket n'est créé.
 *
 * Les droits et les entités sont vérifiés par l'appelant (contrôleurs déjà filtrés).
 */
final class ServerHealth
{
    /** Événements des derniers jours qui comptent dans la vue 360°. */
    public const RECENT_DAYS = 7;

    /**
     * Remplace la santé enregistrée d'un contrôleur après une lecture réussie de la santé ; une
     * lecture sans aucune rubrique lisible garde la précédente.
     *
     * @param array{read: bool, components: list<array<string, string>>, events: list<array<string, mixed>>} $health
     */
    public static function store(int $controllerId, array $health, string $now): bool
    {
        global $DB;

        if (!$health['read']) {
            return false;
        }
        self::purge($controllerId);
        foreach ($health['components'] as $component) {
            $DB->insert(Tables::COMPONENTS, [
                'plugin_grcmanager_bmccontrollers_id' => $controllerId,
                'kind'      => (string) $component['kind'],
                'name'      => (string) $component['name'],
                'health'    => (string) $component['health'],
                'reading'   => (string) $component['reading'],
                'date_read' => $now,
            ]);
        }
        foreach ($health['events'] as $event) {
            $DB->insert(Tables::EVENTS, [
                'plugin_grcmanager_bmccontrollers_id' => $controllerId,
                'date_event' => $event['date'],
                'severity'   => (string) $event['severity'],
                'message'    => (string) $event['message'],
                'code'       => (string) $event['code'],
                'date_read'  => $now,
            ]);
        }

        return true;
    }

    /** Supprime la santé enregistrée d'un contrôleur (contrôleur supprimé ou relu). */
    public static function purge(int $controllerId): void
    {
        global $DB;

        $DB->delete(Tables::COMPONENTS, ['plugin_grcmanager_bmccontrollers_id' => $controllerId]);
        $DB->delete(Tables::EVENTS, ['plugin_grcmanager_bmccontrollers_id' => $controllerId]);
    }

    /** @return array<string, array{label: string, icon: string}> */
    public static function kindInfos(): array
    {
        return [
            Reader::KIND_POWER       => ['label' => __('Power supplies', 'grcmanager'), 'icon' => 'ti ti-plug'],
            Reader::KIND_DRIVE       => ['label' => __('Disks', 'grcmanager'), 'icon' => 'ti ti-database'],
            Reader::KIND_FAN         => ['label' => __('Fans', 'grcmanager'), 'icon' => 'ti ti-propeller'],
            Reader::KIND_TEMPERATURE => ['label' => __('Temperatures', 'grcmanager'), 'icon' => 'ti ti-temperature'],
        ];
    }

    /** @return array{label: string, color: string, icon: string} */
    public static function healthInfo(string $health): array
    {
        return match ($health) {
            Rules::HEALTH_CRITICAL => [
                'label' => __('Critical', 'grcmanager'), 'color' => 'danger', 'icon' => 'ti ti-circle-x',
            ],
            Rules::HEALTH_WARNING  => [
                'label' => __('Warning', 'grcmanager'), 'color' => 'warning', 'icon' => 'ti ti-alert-triangle',
            ],
            Rules::HEALTH_OK       => [
                'label' => __('OK', 'grcmanager'), 'color' => 'success', 'icon' => 'ti ti-circle-check',
            ],
            default                => [
                'label' => __('Unknown', 'grcmanager'), 'color' => 'secondary', 'icon' => 'ti ti-help-circle',
            ],
        };
    }

    /**
     * Santé enregistrée d'un contrôleur, prête pour l'affichage : composants par famille (dégradés
     * d'abord), événements récents, compteurs.
     *
     * @return array<string, mixed>
     */
    public static function forController(int $controllerId): array
    {
        global $DB;

        $groups = [];
        $dateRead = '';
        $counts = [Rules::HEALTH_CRITICAL => 0, Rules::HEALTH_WARNING => 0];
        $rank = [Rules::HEALTH_CRITICAL => 0, Rules::HEALTH_WARNING => 1, '' => 2, Rules::HEALTH_OK => 3];
        $rows = iterator_to_array($DB->request([
            'FROM'  => Tables::COMPONENTS,
            'WHERE' => ['plugin_grcmanager_bmccontrollers_id' => $controllerId],
            'ORDER' => ['id ASC'],
        ]), false);
        usort($rows, static fn (array $a, array $b): int => [$rank[$a['health']] ?? 2, (int) $a['id']]
            <=> [$rank[$b['health']] ?? 2, (int) $b['id']]);
        foreach ($rows as $row) {
            $health = (string) $row['health'];
            if (isset($counts[$health])) {
                ++$counts[$health];
            }
            $dateRead = (string) ($row['date_read'] ?? '');
            $groups[(string) $row['kind']][] = [
                'name'    => (string) $row['name'],
                'reading' => (string) $row['reading'],
                'health'  => self::healthInfo($health),
            ];
        }
        $sections = [];
        foreach (self::kindInfos() as $kind => $info) {
            if (isset($groups[$kind])) {
                $sections[] = $info + ['kind' => $kind, 'items' => $groups[$kind]];
            }
        }
        $alerts = [Rules::HEALTH_WARNING, Rules::HEALTH_CRITICAL];
        $events = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::EVENTS,
                'WHERE' => ['plugin_grcmanager_bmccontrollers_id' => $controllerId],
                'ORDER' => ['date_event DESC', 'id ASC'],
                'LIMIT' => Reader::MAX_EVENTS,
            ]) as $row
        ) {
            $dateRead = $dateRead !== '' ? $dateRead : (string) ($row['date_read'] ?? '');
            $rawDate = (string) ($row['date_event'] ?? '');
            $events[] = [
                'date'        => $rawDate !== '' ? (string) Html::convDateTime($rawDate) : '',
                'raw_date'    => $rawDate,
                'severity'    => self::healthInfo((string) $row['severity']),
                'is_alert'    => in_array((string) $row['severity'], $alerts, true),
                'is_critical' => (string) $row['severity'] === Rules::HEALTH_CRITICAL,
                'message'     => (string) $row['message'],
                'code'        => (string) $row['code'],
            ];
        }

        return [
            'has_data'  => $sections !== [] || $events !== [],
            'sections'  => $sections,
            'events'    => $events,
            'critical'  => $counts[Rules::HEALTH_CRITICAL],
            'warning'   => $counts[Rules::HEALTH_WARNING],
            'date_read' => $dateRead !== '' ? (string) Html::convDateTime($dateRead) : '',
        ];
    }

    /**
     * Résumé pour la vue 360° : composants dégradés et alertes des RECENT_DAYS derniers jours.
     *
     * @param list<array<string, mixed>> $healths santé de chaque contrôleur de l'actif (forController())
     * @return array{critical: int, warning: int, recent_alerts: int, recent_critical: int, has_data: bool}
     */
    public static function summarize(array $healths, string $now): array
    {
        $limit = date('Y-m-d H:i:s', (int) strtotime($now) - self::RECENT_DAYS * 86400);
        $out = ['critical' => 0, 'warning' => 0, 'recent_alerts' => 0, 'recent_critical' => 0, 'has_data' => false];
        foreach ($healths as $health) {
            $out['has_data'] = $out['has_data'] || (bool) $health['has_data'];
            $out['critical'] += (int) $health['critical'];
            $out['warning'] += (int) $health['warning'];
            foreach ($health['events'] as $event) {
                if ($event['is_alert'] && $event['raw_date'] !== '' && $event['raw_date'] >= $limit) {
                    ++$out['recent_alerts'];
                    $out['recent_critical'] += $event['is_critical'] ? 1 : 0;
                }
            }
        }

        return $out;
    }
}
