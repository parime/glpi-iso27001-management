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

use Session;

/**
 * Issue #371 : groupe sécurité optionnel. Quand il est défini, seuls ses membres utilisent le module
 * des incidents de sécurité, quels que soient les droits de leur profil. Un administrateur GLPI
 * (droit de configuration) n'est jamais bloqué, mais son accès hors du groupe est signalé dans le
 * journal d'audit.
 */
final class IncidentSecurityGroup
{
    private const TABLE = 'glpi_plugin_grcmanager_securityincidentconfig';

    private static ?int $groupId = null;

    /** @var array<int, bool> */
    private static array $members = [];

    public static function groupId(): int
    {
        global $DB;

        if (self::$groupId === null) {
            self::$groupId = 0;
            if ($DB->fieldExists(self::TABLE, 'security_groups_id')) {
                $row = $DB->request([
                    'SELECT' => ['security_groups_id'],
                    'FROM'   => self::TABLE,
                    'LIMIT'  => 1,
                ])->current();
                self::$groupId = (int) ($row['security_groups_id'] ?? 0);
            }
        }

        return self::$groupId;
    }

    public static function save(int $groupId): void
    {
        global $DB;

        $DB->update(self::TABLE, ['security_groups_id' => max(0, $groupId)], ['id' => 1]);
        self::$groupId = null;
        self::$members = [];
    }

    /**
     * Pour les tests : oublie le réglage lu.
     */
    public static function reset(): void
    {
        self::$groupId = null;
        self::$members = [];
    }

    public static function isMember(int $usersId): bool
    {
        $groupId = self::groupId();
        if ($groupId === 0 || $usersId <= 0) {
            return $groupId === 0;
        }

        return self::$members[$usersId] ??= countElementsInTable('glpi_groups_users', [
            'groups_id' => $groupId,
            'users_id'  => $usersId,
        ]) > 0;
    }

    /**
     * L'utilisateur connecté peut-il utiliser le module ?
     */
    public static function allows(): bool
    {
        return self::isMember((int) Session::getLoginUserID()) || self::isAdministratorBypass();
    }

    /**
     * Administrateur GLPI hors du groupe : jamais bloqué, mais signalé.
     */
    public static function isAdministratorBypass(): bool
    {
        return self::groupId() > 0
            && !self::isMember((int) Session::getLoginUserID())
            && Session::haveRight('config', UPDATE);
    }
}
