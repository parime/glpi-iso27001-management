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
use Dropdown;
use Group;
use Group_User;
use GlpiPlugin\Grcmanager\Services\People\IsmsRoleRules;
use Session;
use User;

/**
 * Issue #1054 : rôles du SMSI (mesure A.5.2), RSSI et DPO. Pour chacun, un groupe GLPI (titulaires
 * et suppléants : droits, notifications) et une adresse e-mail générique, stable quand la personne
 * change (documents publiés, destinataire de notification). Le groupe RSSI reste celui des audits
 * internes (AuditTeam::CISO_GROUP_KEY, renseigné aussi par Configuration GLPI Auto) : un seul
 * réglage, lu partout. Remplace l'ancien champ texte « DPO » (issue #375), repris par la migration.
 */
final class IsmsRoles
{
    public const ROLE_CISO = 'ciso';
    public const ROLE_DPO = 'dpo';

    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const CISO_EMAIL_KEY = 'ciso_email';
    public const DPO_GROUP_KEY = 'dpo_groups_id';
    public const DPO_EMAIL_KEY = 'dpo_email';
    /** Reste de l'ancien champ texte qui n'était pas une adresse : affiché « à reprendre ». */
    public const DPO_NOTE_KEY = 'dpo_contact_note';
    /** Ancien champ texte du DPO (issue #375), supprimé une fois repris. */
    public const LEGACY_DPO_KEY = 'gdpr_dpo_contact';

    /**
     * Cibles de notification communes, hors de la plage du cœur (sinon GLPI n'appelle jamais
     * addSpecificTargets()) ; voir Services\Notification\IsmsRoleTargets.
     */
    public const TARGET_CISO_EMAIL = 1890;
    public const TARGET_DPO_GROUP = 1891;
    public const TARGET_DPO_EMAIL = 1892;

    /** @return array<string, string> rôle => libellé */
    public static function getRoles(): array
    {
        return [
            self::ROLE_CISO => __('Chief information security officer (CISO)', 'grcmanager'),
            self::ROLE_DPO  => __('Data protection officer (DPO)', 'grcmanager'),
        ];
    }

    public static function groupId(string $role): int
    {
        if ($role === self::ROLE_CISO) {
            return AuditTeam::getCisoGroupId();
        }

        return (int) (self::values()[self::DPO_GROUP_KEY] ?? 0);
    }

    public static function email(string $role): string
    {
        $key = $role === self::ROLE_CISO ? self::CISO_EMAIL_KEY : self::DPO_EMAIL_KEY;

        return (string) (self::values()[$key] ?? '');
    }

    public static function dpoNote(): string
    {
        return (string) (self::values()[self::DPO_NOTE_KEY] ?? '');
    }

    /**
     * Enregistrement du bloc « Rôles du SMSI ». Une adresse au format invalide est refusée (le
     * réglage précédent est gardé) et son rôle renvoyé dans « invalid ».
     *
     * @param array<string, mixed> $post
     * @return array{changes: array<string, bool>, invalid: list<string>}
     */
    public static function save(array $post): array
    {
        $changes = [];
        $invalid = [];
        $values = [];
        $roles = self::getRoles();

        $cisoGroup = max(0, (int) ($post['ciso_groups_id'] ?? 0));
        if ($cisoGroup !== AuditTeam::getCisoGroupId()) {
            AuditTeam::setCisoGroupId($cisoGroup);
            $changes['ciso_groups_id'] = true;
        }
        $dpoGroup = max(0, (int) ($post['dpo_groups_id'] ?? 0));
        if ($dpoGroup !== self::groupId(self::ROLE_DPO)) {
            $values[self::DPO_GROUP_KEY] = $dpoGroup;
            $changes[self::DPO_GROUP_KEY] = true;
        }
        foreach ([self::ROLE_CISO => self::CISO_EMAIL_KEY, self::ROLE_DPO => self::DPO_EMAIL_KEY] as $role => $key) {
            $email = IsmsRoleRules::normalizeEmail($post[$key] ?? '');
            if ($email === null) {
                $invalid[] = $roles[$role];
                continue;
            }
            if ($email !== self::email($role)) {
                $values[$key] = $email;
                $changes[$key] = true;
            }
        }
        if (!empty($post['clear_dpo_note']) && self::dpoNote() !== '') {
            $values[self::DPO_NOTE_KEY] = '';
            $changes[self::DPO_NOTE_KEY] = true;
        }
        if ($values !== []) {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, $values);
        }

        return ['changes' => $changes, 'invalid' => $invalid];
    }

    /**
     * Membres actifs du groupe (titulaires et suppléants), par nom.
     *
     * @return list<string>
     */
    public static function members(int $groupsId): array
    {
        global $DB;

        if ($groupsId <= 0) {
            return [];
        }
        $names = [];
        foreach (
            $DB->request([
                'SELECT'     => [User::getTable() . '.id'],
                'FROM'       => Group_User::getTable(),
                'INNER JOIN' => [
                    User::getTable() => ['ON' => [Group_User::getTable() => 'users_id', User::getTable() => 'id']],
                ],
                'WHERE'      => [
                    Group_User::getTable() . '.groups_id' => $groupsId,
                    User::getTable() . '.is_active'       => 1,
                    User::getTable() . '.is_deleted'      => 0,
                ],
            ]) as $row
        ) {
            $names[] = (string) getUserName((int) $row['id']);
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Responsabilités affichées (matrice des responsabilités et son PDF) : un élément par rôle.
     *
     * @return list<array{key: string, label: string, group_id: int, group_name: string, group_url: string,
     *     members: list<string>, email: string, note: string}>
     */
    public static function summary(): array
    {
        $canViewGroups = (bool) Group::canView();
        $rows = [];
        foreach (self::getRoles() as $role => $label) {
            $groupsId = self::groupId($role);
            $groupName = $groupsId > 0 ? (string) Dropdown::getDropdownName(Group::getTable(), $groupsId) : '';
            $rows[] = [
                'key'        => $role,
                'label'      => $label,
                'group_id'   => $groupsId,
                'group_name' => $groupName,
                'group_url'  => $groupsId > 0 && $canViewGroups ? Group::getFormURLWithID($groupsId) : '',
                'members'    => self::members($groupsId),
                'email'      => self::email($role),
                'note'       => $role === self::ROLE_DPO ? self::dpoNote() : '',
            ];
        }

        return $rows;
    }

    /**
     * Contact du DPO sur les registres (article 30, violations) : groupe, membres et adresse
     * générique ; l'ancienne note tant qu'aucun des deux n'est choisi.
     */
    public static function dpoContactText(): string
    {
        $groupsId = self::groupId(self::ROLE_DPO);

        return IsmsRoleRules::contactText(
            $groupsId > 0 ? (string) Dropdown::getDropdownName(Group::getTable(), $groupsId) : '',
            self::members($groupsId),
            self::email(self::ROLE_DPO),
            self::dpoNote()
        );
    }

    /**
     * Migration (rejouable) : l'ancien champ texte du DPO est repris puis supprimé. Une adresse
     * trouvée devient l'adresse générique (si aucune n'est encore saisie), le reste la note « à
     * reprendre ». Une adresse qui ne peut pas être reprise reste dans la note : rien n'est perdu.
     */
    public static function migrateLegacyDpoContact(): bool
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::LEGACY_DPO_KEY]);
        if (!array_key_exists(self::LEGACY_DPO_KEY, $values)) {
            return false;
        }
        $legacy = (string) $values[self::LEGACY_DPO_KEY];
        $split = IsmsRoleRules::splitLegacyContact($legacy);
        $new = [];
        $note = $split['note'];
        if ($split['email'] !== '') {
            if (self::email(self::ROLE_DPO) === '') {
                $new[self::DPO_EMAIL_KEY] = $split['email'];
            } else {
                $note = trim($note . ' ' . $split['email']);
            }
        }
        if ($note !== '') {
            $previous = self::dpoNote();
            $new[self::DPO_NOTE_KEY] = mb_substr(
                $previous === '' ? $note : $previous . ' ' . $note,
                0,
                IsmsRoleRules::NOTE_MAX_LENGTH
            );
        }
        if ($new !== []) {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, $new);
        }
        Config::deleteConfigurationValues(self::CONFIG_CONTEXT, [self::LEGACY_DPO_KEY]);

        return true;
    }

    /** Droit de modifier les rôles : celui de la configuration du plugin. */
    public static function canEdit(): bool
    {
        return (bool) Session::haveRight(Install\Installer::RIGHT_NAME, UPDATE);
    }

    /** @return array<string, mixed> */
    private static function values(): array
    {
        return Config::getConfigurationValues(
            self::CONFIG_CONTEXT,
            [self::CISO_EMAIL_KEY, self::DPO_GROUP_KEY, self::DPO_EMAIL_KEY, self::DPO_NOTE_KEY]
        );
    }
}
