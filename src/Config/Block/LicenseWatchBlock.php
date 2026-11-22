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

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\MovedSetting;
use GlpiPlugin\Grcmanager\Config\Tab\AssetsTab;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\License\LicenseComplianceRules as Rules;
use GlpiPlugin\Grcmanager\Services\License\LicenseInventory;
use GlpiPlugin\Grcmanager\SoftwareLicenseCompliance;
use Html;
use Session;

/**
 * Issue #1044 : éditeurs commerciaux surveillés, logiciels ignorés et horizon d'alerte avant
 * l'échéance des licences (issue #779).
 */
final class LicenseWatchBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-license-watch';

    public static function tab(): string
    {
        return 'assets';
    }

    public static function order(): int
    {
        return 50;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_license_watch.html.twig';
    }

    /** Même contrôle que la page des licences : registres sensibles, logiciels et licences en lecture. */
    public static function canView(): bool
    {
        return SoftwareLicenseCompliance::canView();
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function data(): array
    {
        $settings = LicenseInventory::settings();

        return [
            'license' => [
                'publishers'        => implode("\n", $settings['publishers']),
                'ignored'           => implode("\n", $settings['ignored']),
                'publisher_list'    => $settings['publishers'],
                'expiring_days'     => $settings['expiring_days'],
                'max_expiring_days' => Rules::MAX_EXPIRING_DAYS,
                'can_manage'        => SoftwareLicenseCompliance::canConfigure(),
            ],
        ];
    }

    /** @param bool $moved envoi venu de l'ancienne page front/softwarelicensecompliance.php */
    public static function handle(bool $moved = false): bool
    {
        if (!isset($_POST['save_license_watch']) && !isset($_POST['reset_license_watch'])) {
            return false;
        }
        Session::checkRight(Installer::RIGHT_NAME, UPDATE);
        if (!SoftwareLicenseCompliance::canView()) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        $before = LicenseInventory::settings();
        if (isset($_POST['reset_license_watch'])) {
            LicenseInventory::reset();
        } else {
            LicenseInventory::save(
                (string) ($_POST['license_publishers'] ?? ''),
                (string) ($_POST['license_ignored'] ?? ''),
                isset($_POST['license_expiring_days'])
                    ? (int) $_POST['license_expiring_days']
                    : Rules::DEFAULT_EXPIRING_DAYS
            );
        }
        if (LicenseInventory::settings() !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => ['license_watch' => LicenseInventory::settings()],
            ]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Watched publishers saved.', 'grcmanager')));
        if ($moved) {
            MovedSetting::notice(AssetsTab::label());
        }
        Html::redirect(ConfigTabs::url('assets', self::ANCHOR));
    }
}
