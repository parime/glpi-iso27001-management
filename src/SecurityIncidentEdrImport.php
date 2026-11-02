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

namespace GlpiPlugin\Grcmanager;

use Computer;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentEdrImportBase;
use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use Session;
use User;

/**
 * Issue #370 : import d'un export CSV d'EDR / XDR. Chaque import garde ses lignes brutes (jamais
 * modifiées) rattachées à l'incident ; chaque identifiant d'alerte n'est importé qu'une fois.
 */
class SecurityIncidentEdrImport extends SecurityIncidentEdrImportBase
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_securityincidentedrimports';
    }

    public static function getAlertsTable(): string
    {
        return 'glpi_plugin_grcmanager_securityincidentedralerts';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('EDR / XDR import', 'EDR / XDR imports', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-import';
    }

    public static function getImportURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/securityincidentedrimport.php';
    }

    /**
     * @return array<string, string>
     */
    public static function getFieldLabels(): array
    {
        return [
            'alert_id'     => __('Alert identifier', 'grcmanager'),
            'detected_at'  => __('Detection date', 'grcmanager'),
            'title'        => __('Alert name', 'grcmanager'),
            'hostname'     => __('Host', 'grcmanager'),
            'username'     => __('Account', 'grcmanager'),
            'severity'     => __('Severity', 'grcmanager'),
            'tactic'       => __('MITRE ATT&CK tactic', 'grcmanager'),
            'technique'    => __('MITRE ATT&CK technique', 'grcmanager'),
            'command_line' => __('Command line', 'grcmanager'),
            'sha256'       => 'SHA-256',
            'md5'          => 'MD5',
            'file_name'    => __('File name', 'grcmanager'),
            'ip'           => __('IP address', 'grcmanager'),
            'domain'       => __('Domain', 'grcmanager'),
            'url'          => __('URL', 'grcmanager'),
            'console_url'  => __('Console link', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getGroupingLabels(): array
    {
        return [
            'detection' => __('One incident per alert identifier', 'grcmanager'),
            'host'      => __('One incident per host', 'grcmanager'),
            'single'    => __('A single incident for the whole file', 'grcmanager'),
            'row'       => __('One incident per line', 'grcmanager'),
        ];
    }

    public static function canImport(): bool
    {
        return PluginGrcmanagerSecurityIncident::canCreate();
    }

    /**
     * @param array{headers: list<string>, rows: list<list<string>>} $parsed
     * @param array<string, int> $mapping
     * @return array{created: list<int>, updated: list<int>, skipped: int}
     */
    public static function import(
        array $parsed,
        array $mapping,
        string $grouping,
        string $timezone,
        int $targetIncidentId,
        string $fileName,
        string $fileHash
    ): array {
        global $DB;

        $result = ['created' => [], 'updated' => [], 'skipped' => 0];
        if ($targetIncidentId > 0) {
            $grouping = 'single';
        }
        foreach (EdrCsvImport::group($parsed['rows'], $mapping, $grouping, $timezone) as $group) {
            $new = array_values(array_filter(
                $group['external_ids'],
                static fn (string $alertId): bool
                    => countElementsInTable(self::getAlertsTable(), ['alert_id' => $alertId]) === 0
            ));
            // Alertes toutes déjà importées : ré-import sans doublon.
            if ($group['external_ids'] !== [] && $new === []) {
                $result['skipped']++;
                continue;
            }
            $incidentId = $targetIncidentId;
            $incident = new PluginGrcmanagerSecurityIncident();
            if ($incidentId > 0) {
                if (!$incident->can($incidentId, UPDATE)) {
                    break;
                }
                $result['updated'][] = $incidentId;
            } else {
                $incidentId = (int) $incident->add([
                    'name'        => mb_substr($group['title'], 0, 250),
                    'content'     => self::summary($group),
                    'date'        => $group['date'] ?? ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
                    'severity'    => $group['severity'],
                    'category'    => self::guessCategory($group),
                    'source'      => 'edr',
                    'external_id' => mb_substr(implode(', ', $group['external_ids']), 0, 255),
                    'entities_id' => (int) ($_SESSION['glpiactive_entity'] ?? 0),
                ]);
                if ($incidentId <= 0) {
                    continue;
                }
                $result['created'][] = $incidentId;
            }

            $DB->insert(self::getTable(), [
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'file_name'     => mb_substr($fileName, 0, 255),
                'file_sha256'   => $fileHash,
                'row_count'     => count($group['rows']),
                'raw'           => json_encode(
                    ['headers' => $parsed['headers'], 'rows' => $group['rows']],
                    JSON_UNESCAPED_UNICODE
                ),
                'users_id'      => (int) Session::getLoginUserID(),
                'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
            foreach ($new as $alertId) {
                $DB->insert(self::getAlertsTable(), [
                    'plugin_grcmanager_securityincidents_id' => $incidentId,
                    'alert_id' => mb_substr($alertId, 0, 255),
                ]);
            }
            if ($group['iocs'] !== []) {
                SecurityIncidentIoc::addFromText($incidentId, implode("\n", $group['iocs']));
            }
            foreach ($group['techniques'] as $technique) {
                SecurityIncidentTechnique::addFromInput($incidentId, $technique);
            }
            self::linkItems($incidentId, $group['hosts'], $group['users']);
        }

        return $result;
    }

    /**
     * Ordinateurs (nom court ou FQDN) et comptes (DOMAINE\login, login@domaine) reconnus dans GLPI,
     * ajoutés aux éléments de l'incident.
     *
     * @param list<string> $hosts
     * @param list<string> $users
     */
    public static function linkItems(int $incidentId, array $hosts, array $users): int
    {
        $linked = 0;
        $targets = [];
        foreach ($hosts as $host) {
            $short = strtok($host, '.') ?: $host;
            foreach ([$host, $short] as $name) {
                $computer = new Computer();
                if ($computer->getFromDBByCrit(['name' => $name, 'is_deleted' => 0, 'is_template' => 0])) {
                    $targets[Computer::class . ':' . $computer->getID()] = [Computer::class, (int) $computer->getID()];
                    break;
                }
            }
        }
        foreach ($users as $account) {
            $login = self::loginOf($account);
            $user = new User();
            if ($login !== '' && $user->getFromDBByCrit(['name' => $login, 'is_deleted' => 0])) {
                $targets[User::class . ':' . $user->getID()] = [User::class, (int) $user->getID()];
            }
        }
        foreach ($targets as [$itemtype, $itemsId]) {
            $criteria = [
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
            ];
            if (countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), $criteria) === 0) {
                $linked += (new PluginGrcmanagerSecurityIncident_Item())->add($criteria) ? 1 : 0;
            }
        }

        return $linked;
    }

    public static function loginOf(string $account): string
    {
        $account = trim($account);
        if (str_contains($account, '\\')) {
            $account = substr($account, strrpos($account, '\\') + 1);
        }
        if (str_contains($account, '@')) {
            $account = strstr($account, '@', true) ?: $account;
        }

        return $account;
    }

    /**
     * @param array{iocs: list<string>, techniques: list<string>, users: list<string>} $group
     */
    public static function guessCategory(array $group): string
    {
        $pattern = '/^[a-f0-9]{32,64}$|\.(exe|dll|ps1|bat|js|vbs)$/i';
        $hasFile = (bool) array_filter(
            $group['iocs'],
            static fn (string $ioc): bool => (bool) preg_match($pattern, $ioc)
        );
        if ($hasFile) {
            return 'malware';
        }

        return $group['users'] !== [] ? 'unauthorized_access' : 'other';
    }

    /**
     * Description de l'incident créé : origine, hôtes, comptes, lien vers la console.
     *
     * @param array{rows: list<list<string>>, hosts: list<string>, users: list<string>, console_url: string} $group
     */
    private static function summary(array $group): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/edr_incident_summary.html.twig', [
            'rows'        => count($group['rows']),
            'hosts'       => $group['hosts'],
            'users'       => $group['users'],
            'console_url' => $group['console_url'],
        ]);
    }

    public function canViewItem(): bool
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incident->getFromDB((int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0))
            && $incident->canViewItem();
    }

    /**
     * Les lignes brutes ne se modifient pas.
     */
    public function canUpdateItem(): bool
    {
        return false;
    }
}
