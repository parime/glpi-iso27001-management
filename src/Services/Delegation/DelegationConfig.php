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

namespace GlpiPlugin\Grcmanager\Services\Delegation;

use Config;
use GlpiPlugin\Grcmanager\AuditLog;

/**
 * Issue #618 : types de décisions délégables à un suppléant (Configuration de GRC Manager).
 * Aucun par défaut : rien n'est délégué tant qu'un administrateur ne l'a pas choisi.
 */
final class DelegationConfig
{
    public const CONTEXT = 'plugin:grcmanager';
    public const TYPES_KEY = 'delegation_types';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        $values = Config::getConfigurationValues(self::CONTEXT, [self::TYPES_KEY]);

        return DelegationRules::sanitizeTypes($values[self::TYPES_KEY] ?? '');
    }

    public static function isDelegable(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    /**
     * @param mixed $types
     */
    public static function save(mixed $types): void
    {
        $before = self::types();
        $after = DelegationRules::sanitizeTypes($types);
        Config::setConfigurationValues(self::CONTEXT, [self::TYPES_KEY => (string) json_encode($after)]);
        if ($before !== $after) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => [self::TYPES_KEY => ['from' => $before, 'to' => $after]],
            ]);
        }
    }

    /**
     * Libellé et explication de chaque type, pour la configuration et le journal.
     *
     * @return array<string, array{label: string, help: string, icon: string}>
     */
    public static function typeInfos(): array
    {
        return [
            DelegationRules::TYPE_ACCESS_REVIEW => [
                'label' => __('Access review', 'grcmanager'),
                'help'  => __('The substitute decides the accounts entrusted to the absent reviewer (never his own account).', 'grcmanager'),
                'icon'  => 'ti-user-search',
            ],
            DelegationRules::TYPE_SOA_APPROVAL => [
                'label' => __('SoA approval', 'grcmanager'),
                'help'  => __('The substitute answers for the absent approver, unless he is himself an approver of the same version.', 'grcmanager'),
                'icon'  => 'ti-signature',
            ],
            DelegationRules::TYPE_POLICY_APPROVAL => [
                'label' => __('Policy approval', 'grcmanager'),
                'help'  => __('The substitute answers for the absent approver, unless he is himself an approver of the same version.', 'grcmanager'),
                'icon'  => 'ti-file-text',
            ],
        ];
    }
}
