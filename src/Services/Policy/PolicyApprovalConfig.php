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

namespace GlpiPlugin\Grcmanager\Services\Policy;

use Config;
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\PolicyVersion;
use Group;
use User;

/**
 * Issue #611 : réglages du circuit d'approbation des politiques (Configuration de GRC Manager).
 * Désactivé par défaut : rien ne change pour une installation existante tant qu'un administrateur
 * ne l'active pas. Approbateurs par défaut (utilisateurs et groupes) et règle de validation par
 * défaut, qu'une politique peut remplacer par les siens.
 */
final class PolicyApprovalConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const ENABLED_KEY = 'policy_approval_enabled';
    public const APPROVERS_KEY = 'policy_approval_approvers';
    public const QUORUM_KEY = 'policy_approval_quorum';

    public const APPROVER_TYPES = [User::class, Group::class];

    public static function isEnabled(): bool
    {
        $values = Config::getConfigurationValues(self::CONTEXT, [self::ENABLED_KEY]);

        return !empty($values[self::ENABLED_KEY]);
    }

    public static function defaultQuorum(): int
    {
        $values = Config::getConfigurationValues(self::CONTEXT, [self::QUORUM_KEY]);

        return PolicyApprovalRules::sanitizeQuorum($values[self::QUORUM_KEY] ?? 0, false);
    }

    /**
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function defaultApprovers(): array
    {
        $values = Config::getConfigurationValues(self::CONTEXT, [self::APPROVERS_KEY]);
        $decoded = json_decode((string) ($values[self::APPROVERS_KEY] ?? ''), true);

        return self::sanitizeApprovers(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<mixed> $list
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function sanitizeApprovers(array $list): array
    {
        $clean = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemtype = (string) ($row['itemtype'] ?? '');
            $itemsId = (int) ($row['items_id'] ?? 0);
            if (!in_array($itemtype, self::APPROVER_TYPES, true) || $itemsId <= 0) {
                continue;
            }
            $clean[$itemtype . '_' . $itemsId] = ['itemtype' => $itemtype, 'items_id' => $itemsId];
        }

        return array_values($clean);
    }

    /**
     * Listes d'identifiants d'un formulaire (utilisateurs, groupes) vers la liste des approbateurs.
     *
     * @param array<mixed> $users
     * @param array<mixed> $groups
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function fromForm(array $users, array $groups): array
    {
        $list = [];
        foreach ($users as $id) {
            $list[] = ['itemtype' => User::class, 'items_id' => (int) $id];
        }
        foreach ($groups as $id) {
            $list[] = ['itemtype' => Group::class, 'items_id' => (int) $id];
        }

        return self::sanitizeApprovers($list);
    }

    /**
     * Enregistre les réglages. Activer le circuit marque les politiques déjà approuvées comme
     * « approuvées avant le circuit » (aucun statut ne change).
     *
     * @param list<array{itemtype: string, items_id: int}> $approvers
     */
    public static function save(bool $enabled, array $approvers, int $quorum): void
    {
        $wasEnabled = self::isEnabled();
        $approvers = self::sanitizeApprovers($approvers);
        $quorum = PolicyApprovalRules::sanitizeQuorum($quorum, false);
        Config::setConfigurationValues(self::CONTEXT, [
            self::ENABLED_KEY   => $enabled ? '1' : '0',
            self::APPROVERS_KEY => (string) json_encode($approvers),
            self::QUORUM_KEY    => (string) $quorum,
        ]);
        AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => [
            self::ENABLED_KEY   => $enabled,
            self::APPROVERS_KEY => $approvers,
            self::QUORUM_KEY    => $quorum,
        ]]);
        if ($enabled && !$wasEnabled) {
            PolicyVersion::markLegacyApproved();
        }
    }

    /**
     * Choix de règle pour les listes déroulantes.
     *
     * @return array<int, string>
     */
    public static function quorumChoices(bool $withDefault): array
    {
        $choices = [];
        if ($withDefault) {
            $choices[PolicyApprovalRules::QUORUM_DEFAULT] = __('Default rule of the configuration', 'grcmanager');
        }
        $choices[PolicyApprovalRules::QUORUM_ALL] = __('All approvers', 'grcmanager');
        for ($n = 1; $n <= PolicyApprovalRules::MAX_QUORUM; $n++) {
            $choices[$n] = sprintf(__('At least %d approval(s)', 'grcmanager'), $n);
        }

        return $choices;
    }
}
