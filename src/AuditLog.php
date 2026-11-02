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
