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

use Computer;
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\MovedSetting;
use GlpiPlugin\Grcmanager\Config\Tab\AssetsTab;
use GlpiPlugin\Grcmanager\PatchCompliance;
use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules as Rules;
use Html;
use Session;

/** Issue #1044 : délais cibles des correctifs de sécurité par criticité (issue #854). */
final class PatchTargetsBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-patch-targets';

    public static function tab(): string
    {
        return 'assets';
    }

    public static function order(): int
    {
        return 40;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_patch_targets.html.twig';
    }

    /** Même contrôle que la page des correctifs : droit du plugin et lecture des ordinateurs. */
    public static function canView(): bool
    {
        return PatchCompliance::canView();
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function data(): array
    {
        return [
            'patch' => [
                'tiers'      => PatchCompliance::tierInfos(),
                'targets'    => PatchCompliance::getTargets(),
                'min_target' => Rules::MIN_TARGET,
                'max_target' => Rules::MAX_TARGET,
                'can_manage' => PatchCompliance::canManage(),
            ],
        ];
    }

    /** @param bool $moved envoi venu de l'ancienne page front/patchcompliance.php */
    public static function handle(bool $moved = false): bool
    {
        if (!isset($_POST['save_patch_targets'])) {
            return false;
        }
        Session::checkRight(PatchCompliance::RIGHTNAME, UPDATE);
        Session::checkRight(Computer::$rightname, READ);

        $before = PatchCompliance::getTargets();
        $submitted = $_POST['patch_targets'] ?? [];
        $after = PatchCompliance::setTargets(is_array($submitted) ? $submitted : []);
        if ($after !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => [PatchCompliance::TARGETS_KEY => $after],
            ]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Target delays saved.', 'grcmanager')));
        if ($moved) {
            MovedSetting::notice(AssetsTab::label());
        }
        Html::redirect(ConfigTabs::url('assets', self::ANCHOR));
    }
}
