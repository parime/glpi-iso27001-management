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

use GlpiPlugin\Grcmanager\AssetSecurity;
use Computer;
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use Session;

/**
 * Issue #1042 : actifs à sauvegarder, ancienneté admise sans RPO et fréquence des tests de
 * restauration (déplacés de front/backupcoverage.php, save_backup_settings). Valables pour tous les
 * outils de sauvegarde (Veeam et imports CSV) : onglet Parc. Droits d'origine.
 */
final class BackupSettingsBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-backup-settings-config';

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
        return '@grcmanager/config/blocks/_backup_settings.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return BackupCoverage::canView();
    }

    public static function data(): array
    {
        $settings = BackupCoverage::getSettings();

        return [
            'backup' => [
                'settings'      => $settings,
                'max_age_label' => BackupCoverage::formatHours($settings['max_age_hours']),
                'scopes'        => BackupCoverage::scopeLabels(),
                'page_path'     => AssetSecurity::menuPath(BackupCoverage::getTypeName(2)),
            ],
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['save_backup_settings'])) {
            return false;
        }
        Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
        Session::checkRight(Computer::$rightname, READ);
        $before = BackupCoverage::getSettings();
        $after = BackupCoverage::setSettings([
            'scope'          => (string) ($_POST['backup_scope'] ?? ''),
            'max_age_hours'  => $_POST['backup_max_age_hours'] ?? '',
            'restore_months' => $_POST['backup_restore_months'] ?? '',
        ]);
        if ($after !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => [BackupCoverage::SETTINGS_KEY => $after],
            ]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Backup settings saved.', 'grcmanager')));
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }

    /** Ancien envoi de front/backupcoverage.php, accepté pendant une version. */
    public static function handleLegacyPost(): void
    {
        if (isset($_POST['save_backup_settings'])) {
            Session::checkRight(BackupCoverage::RIGHTNAME, UPDATE);
            MovedSettings::notice(\GlpiPlugin\Grcmanager\Config\Tab\AssetsTab::label());
            self::handle();
        }
    }
}
