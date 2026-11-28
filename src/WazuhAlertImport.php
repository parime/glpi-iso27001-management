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

use Computer;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidence;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Incident\MitreAttack;
use GlpiPlugin\Grcmanager\Services\Incident\WazuhAlertGrouping;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use PluginGrcmanagerSecurityIncidentTask;
use Session;

/**
 * Issue #1185 : alertes Wazuh choisies dans la revue (Assistance > Incidents de sécurité > Import des
 * alertes EDR) et transformées en incidents de sécurité GRC. Rien n'est créé sans ce choix explicite,
 * rien n'est écrit dans les tables du plugin Wazuh.
 *
 * Même circuit que l'import EDR et les alertes SentinelOne : chaque alerte importée est notée dans la
 * table des alertes de l'import EDR (anti-doublon), ses lignes brutes dans celle des imports. Une
 * alerte répétée (même règle, même ordinateur) rejoint l'incident encore ouvert de son groupe, par une
 * tâche qui la décrit, au lieu d'ouvrir un nouvel incident.
 *
 * Droits : créer un incident (et le groupe sécurité éventuel) ; l'ordinateur doit être visible et
 * lisible ; un incident existant n'est complété que si l'utilisateur peut le modifier, sinon un
 * nouvel incident est créé.
 */
final class WazuhAlertImport
{
    public static function canView(): bool
    {
        return SecurityIncidentEdrImport::canImport() && Computer::canView();
    }

    /**
     * Données de la revue des alertes : null si le plugin Wazuh ou sa table des alertes manque, ou
     * sans les droits.
     *
     * @return array<string, mixed>|null
     */
    public static function reviewData(int $minLevel): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $groups = self::groups(array_keys(WazuhEvidenceProvider::visibleComputerIds()), $minLevel);
        if ($groups === null) {
            return null;
        }
        $shown = array_slice($groups, 0, WazuhAlertGrouping::MAX_GROUPS);
        $names = self::computerNames(array_column($shown, 'computers_id'));
        $rows = [];
        foreach ($shown as $group) {
            $computerId = $group['computers_id'];
            $rows[] = [
                'key'          => $group['key'],
                'description'  => $group['description'],
                'rule_id'      => $group['rule_id'],
                'level'        => $group['level'],
                'severity'     => $group['severity'],
                'count'        => $group['count'],
                'repeated'     => $group['repeated'],
                'first'        => $group['first'],
                'last'         => $group['last'],
                'agent'        => $group['agent'],
                'computer'     => $names[$computerId] ?? (string) $computerId,
                'computer_url' => Computer::getFormURLWithID($computerId),
                'alerts_url'   => WazuhEvidenceProvider::alertsTabUrl($computerId),
            ];
        }

        return [
            'rows'       => $rows,
            'total'      => count($groups),
            'min_level'  => $minLevel,
            'levels'     => self::levelLabels(),
            'days'       => WazuhEvidence::REVIEW_DAYS,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function levelLabels(): array
    {
        return [
            0  => __('All levels', 'grcmanager'),
            7  => __('Level 7 and above', 'grcmanager'),
            12 => __('Level 12 and above', 'grcmanager'),
        ];
    }

    /**
     * Groupes d'alertes des ordinateurs donnés, sur la fenêtre de revue, sans les alertes déjà
     * importées ; null si le plugin Wazuh ou sa table des alertes manque.
     *
     * @param list<int> $computerIds
     * @return list<array{key: string, computers_id: int, agent: string, rule_id: string,
     *     description: string, level: int|null, severity: string, incident_severity: string, count: int,
     *     repeated: bool, first: string, last: string, techniques: list<string>, alerts: list<array{id: int,
     *     key: string, date: string, level: int|null}>}>|null
     */
    private static function groups(array $computerIds, int $minLevel): ?array
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . WazuhEvidence::REVIEW_DAYS . ' days'));
        $alerts = WazuhEvidenceProvider::fetchReviewAlerts($computerIds, $since);
        if ($alerts === null) {
            return null;
        }
        $ids = array_map(static fn (array $alert): string => WazuhAlertGrouping::alertId($alert['key']), $alerts);

        return WazuhAlertGrouping::group($alerts, self::importedAlertIds($ids), $minLevel);
    }

    /**
     * Identifiants d'alerte déjà présents dans la table des alertes de l'import EDR.
     *
     * @param list<string> $alertIds
     * @return array<string, true>
     */
    private static function importedAlertIds(array $alertIds): array
    {
        global $DB;

        $imported = [];
        foreach (array_chunk(array_values(array_unique($alertIds)), 500) as $chunk) {
            foreach (
                $DB->request([
                    'SELECT' => ['alert_id'],
                    'FROM'   => SecurityIncidentEdrImport::getAlertsTable(),
                    'WHERE'  => ['alert_id' => $chunk],
                ]) as $row
            ) {
                $imported[(string) $row['alert_id']] = true;
            }
        }

        return $imported;
    }

    /**
     * Crée ou complète un incident par groupe choisi. Les clés reçues sont relues sur le serveur :
     * seuls les ordinateurs visibles de l'utilisateur et les alertes non encore importées comptent.
     *
     * @param list<string> $keys
     * @return array{created: list<int>, updated: list<int>, skipped: int}
     */
    public static function importSelected(array $keys): array
    {
        $result = ['created' => [], 'updated' => [], 'skipped' => 0];
        if (!self::canView()) {
            return $result;
        }
        $keys = array_slice(array_values(array_unique(array_filter(
            array_map('strval', $keys),
            [WazuhAlertGrouping::class, 'isGroupKey']
        ))), 0, WazuhAlertGrouping::MAX_GROUPS);
        $visible = WazuhEvidenceProvider::visibleComputerIds();
        $computerIds = [];
        foreach ($keys as $key) {
            $computerId = WazuhAlertGrouping::computerOf($key);
            if (isset($visible[$computerId])) {
                $computerIds[$computerId] = $computerId;
            }
        }
        $groups = [];
        foreach (self::groups(array_values($computerIds), 0) ?? [] as $group) {
            $groups[$group['key']] = $group;
        }
        foreach ($keys as $key) {
            $outcome = isset($groups[$key]) ? self::importGroup($groups[$key]) : null;
            if ($outcome === null) {
                $result['skipped']++;
            } elseif ($outcome['created']) {
                $result['created'][] = $outcome['id'];
            } else {
                $result['updated'][] = $outcome['id'];
            }
        }

        return $result;
    }

    /**
     * Un groupe d'alertes devient un incident, ou complète l'incident encore ouvert du même groupe.
     * Null si rien n'est fait (droits, ordinateur, alertes déjà importées).
     *
     * @param array{key: string, computers_id: int, agent: string, rule_id: string, description: string,
     *     level: int|null, incident_severity: string, count: int, first: string, last: string,
     *     techniques: list<string>, alerts: list<array{id: int, key: string, date: string, level: int|null}>} $group
     * @return array{id: int, created: bool}|null
     */
    public static function importGroup(array $group): ?array
    {
        global $DB;

        if (
            !SecurityIncidentEdrImport::canImport()
            || $group['computers_id'] <= 0
            || !WazuhAlertGrouping::isGroupKey($group['key'])
            || WazuhAlertGrouping::computerOf($group['key']) !== $group['computers_id']
        ) {
            return null;
        }
        $computer = new Computer();
        if (
            !$computer->can($group['computers_id'], READ)
            || (int) $computer->fields['is_deleted'] === 1
            || !Session::haveAccessToEntity((int) $computer->fields['entities_id'])
        ) {
            return null;
        }
        // Relecture de l'anti-doublon : une alerte importée entre-temps n'est pas reprise.
        $imported = self::importedAlertIds(array_map(
            static fn (array $alert): string => WazuhAlertGrouping::alertId($alert['key']),
            $group['alerts']
        ));
        $alerts = array_values(array_filter(
            $group['alerts'],
            static fn (array $alert): bool => !isset($imported[WazuhAlertGrouping::alertId($alert['key'])])
        ));
        if ($alerts === []) {
            return null;
        }
        $group['alerts'] = $alerts;
        $computerName = (string) $computer->fields['name'];
        $summary = self::summary($group, $computerName, $group['computers_id']);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $incidentId = self::openIncidentOf($group['key']);
        $created = $incidentId === 0;
        if ($created) {
            $incident = new PluginGrcmanagerSecurityIncident();
            $incidentId = (int) $incident->add([
                'name'        => self::title($group, $computerName),
                'content'     => $summary,
                'date'        => $alerts[0]['date'] !== '' ? $alerts[0]['date'] : $now,
                'severity'    => $group['incident_severity'],
                'category'    => 'other',
                'source'      => 'siem',
                'external_id' => mb_substr('Wazuh ' . ($group['rule_id'] !== '' ? $group['rule_id'] . ' ' : '')
                    . implode(', ', array_column($alerts, 'key')), 0, 255),
                'entities_id' => (int) $computer->fields['entities_id'],
            ]);
            if ($incidentId <= 0) {
                return null;
            }
            $DB->insert(SecurityIncidentEdrImport::getAlertsTable(), [
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'alert_id' => WazuhAlertGrouping::groupMarker($group['key']),
            ]);
            self::linkComputer($incidentId, $group['computers_id']);
        } else {
            (new PluginGrcmanagerSecurityIncidentTask())->add([
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'content'  => $summary,
                'state'    => 0,
                'date'     => $now,
                'users_id' => (int) Session::getLoginUserID(),
            ]);
        }

        foreach ($alerts as $alert) {
            $DB->insert(SecurityIncidentEdrImport::getAlertsTable(), [
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'alert_id' => WazuhAlertGrouping::alertId($alert['key']),
            ]);
        }
        $raw = (string) json_encode([
            'headers' => ['alert_id', 'detected_at', 'level', 'rule_id', 'description', 'agent', 'computers_id'],
            'rows'    => array_map(static fn (array $alert): array => [
                $alert['key'], $alert['date'], (string) ($alert['level'] ?? ''), $group['rule_id'],
                $group['description'], $group['agent'], (string) $group['computers_id'],
            ], $alerts),
        ], JSON_UNESCAPED_UNICODE);
        $DB->insert(SecurityIncidentEdrImport::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'file_name'     => 'Wazuh',
            'file_sha256'   => hash('sha256', $raw),
            'row_count'     => count($alerts),
            'raw'           => $raw,
            'users_id'      => (int) Session::getLoginUserID(),
            'date_creation' => $now,
        ]);
        foreach ($group['techniques'] as $technique) {
            $known = MitreAttack::normalize($technique);
            if ($known !== null && $known['kind'] === 'technique') {
                SecurityIncidentTechnique::addFromInput($incidentId, $technique);
            }
        }
        AuditLog::record('import', SecurityIncidentEdrImport::class, 0, $incidentId, 'success', [
            'file' => 'Wazuh', 'alerts' => count($alerts),
        ]);

        return ['id' => $incidentId, 'created' => $created];
    }

    /**
     * Incident encore ouvert (ni résolu, ni clos, ni à la corbeille) déjà créé pour ce groupe, que
     * l'utilisateur peut modifier ; 0 sinon.
     */
    public static function openIncidentOf(string $groupKey): int
    {
        global $DB;

        $closed = array_merge(
            PluginGrcmanagerSecurityIncident::getSolvedStatusArray(),
            PluginGrcmanagerSecurityIncident::getClosedStatusArray()
        );
        $alerts = SecurityIncidentEdrImport::getAlertsTable();
        $incidents = PluginGrcmanagerSecurityIncident::getTable();
        foreach (
            $DB->request([
                'SELECT'     => ["$incidents.id"],
                'FROM'       => $alerts,
                'INNER JOIN' => [
                    $incidents => ['FKEY' => [$alerts => 'plugin_grcmanager_securityincidents_id', $incidents => 'id']],
                ],
                'WHERE'      => [
                    "$alerts.alert_id"      => WazuhAlertGrouping::groupMarker($groupKey),
                    "$incidents.is_deleted" => 0,
                    'NOT'                   => ["$incidents.status" => $closed],
                ],
                'ORDER'      => ["$incidents.id DESC"],
            ]) as $row
        ) {
            $incident = new PluginGrcmanagerSecurityIncident();
            if ($incident->can((int) $row['id'], UPDATE)) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * @param array{description: string, rule_id: string} $group
     */
    private static function title(array $group, string $computerName): string
    {
        $what = trim($group['description']);
        if ($what === '') {
            $what = $group['rule_id'] !== ''
                ? sprintf(__('Wazuh rule %s', 'grcmanager'), $group['rule_id'])
                : __('Wazuh alert', 'grcmanager');
        }

        return mb_substr(sprintf(__('Wazuh: %1$s (%2$s)', 'grcmanager'), $what, $computerName), 0, 250);
    }

    /**
     * Description de l'incident, ou de la tâche qui ajoute des répétitions : règle, niveau, agent,
     * ordinateur, horodatages et lien vers les alertes dans le plugin Wazuh.
     *
     * @param array{rule_id: string, description: string, level: int|null, agent: string, count: int,
     *     alerts: list<array{key: string, date: string, level: int|null}>} $group
     */
    private static function summary(array $group, string $computerName, int $computerId): string
    {
        $alerts = $group['alerts'];

        return TemplateRenderer::getInstance()->render('@grcmanager/wazuh_incident_summary.html.twig', [
            'count'       => count($alerts),
            'rule_id'     => $group['rule_id'],
            'description' => $group['description'],
            'level'       => $group['level'],
            'agent'       => $group['agent'],
            'computer'    => $computerName,
            'first'       => $alerts[0]['date'] ?? '',
            'last'        => $alerts[count($alerts) - 1]['date'] ?? '',
            'alerts'      => array_slice($alerts, -WazuhAlertGrouping::MAX_DETAILED),
            'alerts_url'  => WazuhEvidenceProvider::alertsTabUrl($computerId),
        ]);
    }

    private static function linkComputer(int $incidentId, int $computerId): void
    {
        $criteria = [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'itemtype' => Computer::class,
            'items_id' => $computerId,
        ];
        if (countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), $criteria) === 0) {
            (new PluginGrcmanagerSecurityIncident_Item())->add($criteria);
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function computerNames(array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $names = [];
        $request = ['SELECT' => ['id', 'name'], 'FROM' => Computer::getTable(), 'WHERE' => ['id' => $ids]];
        foreach ($DB->request($request) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }
}
