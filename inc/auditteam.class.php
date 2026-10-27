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

use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #140 : équipe d'un audit interne, seule audience de ses documents.
 *
 * - membres de droit : l'auditeur et le responsable de l'audit (champs de la fiche) ;
 * - membres ajoutés dans l'onglet « Équipe » : auditeurs supplémentaires, et audités, à qui
 *   les documents sont partagés en lecture (ils ne peuvent rien ajouter sans le droit de
 *   modifier l'audit) ;
 * - le groupe RSSI de la configuration du plugin, qui accède aux documents de tous les audits.
 *
 * Personne d'autre ne lit les documents de l'audit, même avec le droit Documents de GLPI
 * (PluginGrcmanagerRestrictedDocument).
 */
class PluginGrcmanagerAuditTeam extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_audits_users';
    public const ROLE_AUDITOR = 'auditor';
    public const ROLE_AUDITEE = 'auditee';
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const CISO_GROUP_KEY = 'ciso_groups_id';

    /**
     * @return array<string, string>
     */
    public static function getRoles(): array
    {
        return [
            self::ROLE_AUDITOR => __('Auditeur', 'grcmanager'),
            self::ROLE_AUDITEE => __('Audité (lecture seule)', 'grcmanager'),
        ];
    }

    public static function getCisoGroupId(): int
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CISO_GROUP_KEY]);

        return (int) ($values[self::CISO_GROUP_KEY] ?? 0);
    }

    public static function setCisoGroupId(int $groupsId): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CISO_GROUP_KEY => max(0, $groupsId)]);
    }

    public static function isCiso(int $usersId): bool
    {
        $groupsId = self::getCisoGroupId();

        return $groupsId > 0 && $usersId > 0
            && countElementsInTable(Group_User::getTable(), ['groups_id' => $groupsId, 'users_id' => $usersId]) > 0;
    }

    /**
     * Membres ajoutés dans l'onglet (hors auditeur et responsable de la fiche).
     *
     * @return list<array{id: int, users_id: int, role: string}>
     */
    public static function getMembers(int $auditsId): array
    {
        global $DB;

        $members = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'users_id', 'role'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['plugin_grcmanager_audits_id' => $auditsId],
                'ORDER'  => ['role', 'id'],
            ]) as $row
        ) {
            $members[] = [
                'id'       => (int) $row['id'],
                'users_id' => (int) $row['users_id'],
                'role'     => (string) $row['role'],
            ];
        }

        return $members;
    }

    /**
     * L'utilisateur fait-il partie de l'audience des documents de l'audit ?
     */
    public static function canAccessDocuments(int $auditsId, ?int $usersId = null): bool
    {
        global $DB;

        $usersId ??= (int) Session::getLoginUserID();
        if ($usersId <= 0 || $auditsId <= 0) {
            return false;
        }
        $audit = $DB->request([
            'SELECT' => ['users_id', 'users_id_responsible'],
            'FROM'   => PluginGrcmanagerAudit::getTable(),
            'WHERE'  => ['id' => $auditsId],
        ])->current();
        if ($audit === null) {
            return false;
        }
        if (in_array($usersId, [(int) $audit['users_id'], (int) $audit['users_id_responsible']], true)) {
            return true;
        }
        $member = ['plugin_grcmanager_audits_id' => $auditsId, 'users_id' => $usersId];
        if (countElementsInTable(self::TABLE, $member) > 0) {
            return true;
        }

        return self::isCiso($usersId);
    }

    public static function addMember(int $auditsId, int $usersId, string $role): bool
    {
        global $DB;

        if ($auditsId <= 0 || $usersId <= 0 || !array_key_exists($role, self::getRoles())) {
            return false;
        }

        return (bool) $DB->updateOrInsert(
            self::TABLE,
            ['role' => $role, 'date_creation' => date('Y-m-d H:i:s')],
            ['plugin_grcmanager_audits_id' => $auditsId, 'users_id' => $usersId]
        );
    }

    public static function removeMember(int $auditsId, int $membersId): bool
    {
        global $DB;

        return (bool) $DB->delete(self::TABLE, ['id' => $membersId, 'plugin_grcmanager_audits_id' => $auditsId]);
    }

    public static function purgeAudit(int $auditsId): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['plugin_grcmanager_audits_id' => $auditsId]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerAudit || $item->isNewItem() || !PluginGrcmanagerAudit::canView()) {
            return '';
        }

        return self::createTabEntry(
            __('Équipe', 'grcmanager'),
            count(self::getMembers($item->getID())),
            null,
            'ti ti-users-group'
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof PluginGrcmanagerAudit || !$item->can($item->getID(), READ)) {
            return false;
        }
        $rows = [];
        foreach (
            [
                [
                    'users_id' => (int) $item->fields['users_id'],
                    'role'     => __('Auditeur', 'grcmanager'),
                    'id'       => 0,
                ],
                [
                    'users_id' => (int) ($item->fields['users_id_responsible'] ?? 0),
                    'role'     => __('Responsable de l\'audit', 'grcmanager'),
                    'id'       => 0,
                ],
            ] as $row
        ) {
            if ($row['users_id'] > 0) {
                $rows[] = $row + ['from_form' => true];
            }
        }
        $roles = self::getRoles();
        foreach (self::getMembers($item->getID()) as $member) {
            $rows[] = [
                'users_id'  => $member['users_id'],
                'role'      => $roles[$member['role']] ?? $member['role'],
                'id'        => $member['id'],
                'from_form' => false,
            ];
        }
        foreach ($rows as &$row) {
            $row['name'] = getUserName($row['users_id']);
        }
        unset($row);
        $cisoGroupId = self::getCisoGroupId();

        TemplateRenderer::getInstance()->display('@grcmanager/audit_team_tab.html.twig', [
            'audit'      => $item,
            'rows'       => $rows,
            'roles'      => $roles,
            'can_edit'   => $item->can($item->getID(), UPDATE),
            'action'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/auditteam.form.php',
            'ciso_group' => $cisoGroupId > 0 ? Dropdown::getDropdownName(Group::getTable(), $cisoGroupId) : '',
        ]);

        return true;
    }
}
