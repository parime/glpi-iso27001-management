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

namespace GlpiPlugin\Grcmanager\Config\Block;

use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;

/** Issue #611 : approbation des politiques par la direction. */
final class PolicyApprovalBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'people';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_policy_approval.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-policy-approval-config'];
    }

    public static function data(): array
    {
        $approvers = PolicyApprovalConfig::defaultApprovers();

        return [
            'policy_approval'                => [
                'enabled'        => PolicyApprovalConfig::isEnabled(),
                'quorum'         => PolicyApprovalConfig::defaultQuorum(),
                'quorum_choices' => PolicyApprovalConfig::quorumChoices(false),
            ],
            'policy_approval_user_dropdown'  => \User::dropdown([
                'name'     => 'policy_approval_users[]',
                'value'    => array_column(array_filter(
                    $approvers,
                    static fn (array $a): bool => $a['itemtype'] === \User::class
                ), 'items_id'),
                'multiple' => true,
                'right'    => 'all',
                'width'    => '100%',
                'display'  => false,
                'rand'     => mt_rand(),
            ]),
            'policy_approval_group_dropdown' => \Group::dropdown([
                'name'        => 'policy_approval_groups[]',
                'values'      => array_column(array_filter(
                    $approvers,
                    static fn (array $a): bool => $a['itemtype'] === \Group::class
                ), 'items_id'),
                'multiple'    => true,
                'entity'      => 0,
                'entity_sons' => true,
                'width'       => '100%',
                'display'     => false,
                'rand'        => mt_rand(),
            ]),
        ];
    }
}
