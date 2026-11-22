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
use GlpiPlugin\Grcmanager\LogCoverage;
use Html;
use Session;

/**
 * Issue #1044 : journalisation attendue (issue #853) : actifs concernés, seuil « plus rien depuis »
 * et conservation exigée par la politique. Les imports et les déclarations de plateforme restent
 * sur la page d'exploitation.
 */
final class LogCoverageBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-log-settings';

    public static function tab(): string
    {
        return 'assets';
    }

    public static function order(): int
    {
        return 60;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_log_coverage.html.twig';
    }

    /** Même contrôle que la page de la journalisation : droit du plugin et lecture des ordinateurs. */
    public static function canView(): bool
    {
        return LogCoverage::canView();
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function data(): array
    {
        return [
            'logging' => [
                'settings'   => LogCoverage::getSettings(),
                'scopes'     => LogCoverage::scopeLabels(),
                'can_manage' => LogCoverage::canManage(),
            ],
        ];
    }

    /** @param bool $moved envoi venu de l'ancienne page front/logcoverage.php */
    public static function handle(bool $moved = false): bool
    {
        if (!isset($_POST['save_log_settings'])) {
            return false;
        }
        Session::checkRight(LogCoverage::RIGHTNAME, UPDATE);
        Session::checkRight(Computer::$rightname, READ);

        $before = LogCoverage::getSettings();
        $after = LogCoverage::setSettings([
            'scope'       => (string) ($_POST['log_scope'] ?? ''),
            'policy_days' => $_POST['log_policy_days'] ?? '',
            'stale_days'  => $_POST['log_stale_days'] ?? '',
        ]);
        if ($after !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => [LogCoverage::SETTINGS_KEY => $after],
            ]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Logging settings saved.', 'grcmanager')));
        if ($moved) {
            MovedSetting::notice(AssetsTab::label());
        }
        Html::redirect(ConfigTabs::url('assets', self::ANCHOR));

        return true;
    }
}
