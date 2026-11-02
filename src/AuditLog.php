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

use CommonGLPI;
use GlpiPlugin\Grcmanager\Services\Incident\AuditChain;
use Session;

/**
 * Issue #371 : journal d'audit infalsifiable des incidents de sécurité. Écriture seule : aucune
 * entrée n'est modifiée ni supprimée par le plugin (pas même à la désinstallation), chaque entrée
 * est chaînée à la précédente par SHA-256 (voir AuditChain).
 *
 * Lecture réservée au droit des registres sensibles.
 */
class AuditLog extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    private const LOCK = 'grcmanager_auditlog';

    public static function getTable(): string
    {
        return 'glpi_plugin_grcmanager_auditlogs';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Security audit log', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/auditlog.php';
    }

    /**
     * @return array<string, string>
     */
    public static function getActions(): array
    {
        return [
            'view'          => __('Consultation', 'grcmanager'),
            'create'        => __('Creation', 'grcmanager'),
            'update'        => __('Modification', 'grcmanager'),
            'delete'        => __('Moved to the trash', 'grcmanager'),
            'restore'       => __('Restoration', 'grcmanager'),
            'purge'         => __('Permanent deletion', 'grcmanager'),
            'add_link'      => __('Item added', 'grcmanager'),
            'remove_link'   => __('Item removed', 'grcmanager'),
            'export'        => __('Export or print', 'grcmanager'),
            'import'        => __('Import', 'grcmanager'),
            'verify'        => __('Integrity check', 'grcmanager'),
            'seal'          => __('Evidence sealed', 'grcmanager'),
            'config_change' => __('Module settings changed', 'grcmanager'),
            'access_denied' => __('Access denied', 'grcmanager'),
        ];
    }

    /**
     * Ajoute une entrée. Ne lève jamais d'exception : un échec du journal ne doit pas bloquer le
     * travail sur l'incident.
     *
     * @param array<string, mixed> $details
     */
    public static function record(
        string $action,
        string $itemtype = '',
        int $itemsId = 0,
        int $incidentId = 0,
        string $result = 'success',
        array $details = []
    ): bool {
        global $DB;

        try {
            $entry = [
                'date'          => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'users_id'      => (int) Session::getLoginUserID(),
                // « Prendre l'identité » : l'utilisateur réel derrière l'identité prise.
                'users_id_real' => Session::isImpersonateActive() ? (int) ($_SESSION['impersonator_id'] ?? 0) : 0,
                'profiles_id'   => (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0),
                'action'        => mb_substr($action, 0, 32),
                'itemtype'      => mb_substr($itemtype, 0, 100),
                'items_id'      => $itemsId,
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'ip'            => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
                'result'        => $result === 'denied' ? 'denied' : 'success',
                'details'       => $details === [] ? '' : (string) json_encode($details, JSON_UNESCAPED_UNICODE),
            ];
            $locked = $DB->getLock(self::LOCK);
            $last = $DB->request([
                'SELECT' => ['hash'],
                'FROM'   => self::getTable(),
                'ORDER'  => 'id DESC',
                'LIMIT'  => 1,
            ])->current();
            $entry['prev_hash'] = (string) ($last['hash'] ?? AuditChain::GENESIS);
            $entry['hash'] = AuditChain::hash($entry['prev_hash'], $entry);
            $inserted = (bool) $DB->insert(self::getTable(), $entry);
            if ($locked) {
                $DB->releaseLock(self::LOCK);
            }

            return $inserted;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{from: string, to: string, action: string, result: string, incident: int, ip: string}
     */
    public static function filtersFrom(array $input): array
    {
        $date = static fn ($value): string
            => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : '';

        $action = (string) ($input['action'] ?? '');
        $result = (string) ($input['result'] ?? '');

        return [
            'from'     => $date($input['from'] ?? ''),
            'to'       => $date($input['to'] ?? ''),
            'action'   => array_key_exists($action, self::getActions()) ? $action : '',
            'result'   => in_array($result, ['success', 'denied'], true) ? $result : '',
            'incident' => max(0, (int) ($input['incident'] ?? 0)),
            'ip'       => mb_substr(trim((string) ($input['ip'] ?? '')), 0, 45),
        ];
    }

    /**
     * @param array{from: string, to: string, action: string, result: string, incident: int, ip: string} $filters
     * @return array<int|string, mixed>
     */
    public static function criteria(array $filters): array
    {
        $where = [];
        if ($filters['from'] !== '') {
            $where[] = ['date' => ['>=', $filters['from'] . ' 00:00:00']];
        }
        if ($filters['to'] !== '') {
            $where[] = ['date' => ['<=', $filters['to'] . ' 23:59:59']];
        }
        foreach (['action', 'result', 'ip'] as $field) {
            if ($filters[$field] !== '') {
                $where[$field] = $filters[$field];
            }
        }
        if ($filters['incident'] > 0) {
            $where['plugin_grcmanager_securityincidents_id'] = $filters['incident'];
        }

        return $where;
    }

    /**
     * Lignes prêtes à afficher (noms d'utilisateurs et de profils, objet, lien vers l'incident).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function present(array $rows): array
    {
        $names = [];
        $name = static function (int $id) use (&$names): string {
            if ($id <= 0) {
                return '';
            }

            return $names[$id] ??= getUserName($id);
        };
        foreach ($rows as &$row) {
            $row['user'] = $name((int) $row['users_id']);
            $row['real_user'] = $name((int) $row['users_id_real']);
            $row['profile'] = (int) $row['profiles_id'] > 0
                ? \Dropdown::getDropdownName('glpi_profiles', (int) $row['profiles_id'])
                : '';
            $itemtype = (string) $row['itemtype'];
            $row['object'] = $itemtype !== '' && class_exists($itemtype)
                ? $itemtype::getTypeName(1) . ((int) $row['items_id'] > 0 ? ' #' . (int) $row['items_id'] : '')
                : '';
            $incidentId = (int) $row['plugin_grcmanager_securityincidents_id'];
            $row['incident_url'] = $incidentId > 0
                ? \PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentId)
                : '';
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'id', __('Date', 'grcmanager'), __('User', 'grcmanager'), __('Real user (impersonation)', 'grcmanager'),
            __('Profile', 'grcmanager'), __('Action', 'grcmanager'), 'itemtype', 'items_id',
            __('Incident', 'grcmanager'), 'IP', __('Result', 'grcmanager'), __('Details', 'grcmanager'),
            'prev_hash', 'hash',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function csvRow(array $row): array
    {
        $presented = self::present([$row])[0];

        return array_map('strval', [
            $row['id'], $row['date'], $presented['user'], $presented['real_user'], $presented['profile'],
            $row['action'], $row['itemtype'], $row['items_id'], $row['plugin_grcmanager_securityincidents_id'],
            $row['ip'], $row['result'], $row['details'], $row['prev_hash'], $row['hash'],
        ]);
    }

    /**
     * Vérifie toute la chaîne, par paquets ; la vérification est elle-même tracée.
     *
     * @return array{valid: bool, checked: int, broken_id: ?int, last_hash: string}
     */
    public static function verifyAll(): array
    {
        global $DB;

        $previous = AuditChain::GENESIS;
        $checked = 0;
        $lastId = 0;
        do {
            $rows = iterator_to_array($DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['id' => ['>', $lastId]],
                'ORDER' => 'id ASC',
                'LIMIT' => 5000,
            ]), false);
            $result = AuditChain::verify($rows, $previous);
            $checked += $result['checked'];
            if (!$result['valid']) {
                $result['checked'] = $checked;
                self::record('verify', '', 0, 0, 'success', ['valid' => false, 'broken_id' => $result['broken_id']]);

                return $result;
            }
            $previous = $result['last_hash'];
            $lastId = $rows === [] ? $lastId : (int) end($rows)['id'];
        } while (count($rows) === 5000);

        self::record('verify', '', 0, 0, 'success', ['valid' => true, 'checked' => $checked]);

        return ['valid' => true, 'checked' => $checked, 'broken_id' => null, 'last_hash' => $previous];
    }
}
