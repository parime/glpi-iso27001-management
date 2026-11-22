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
use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\MovedSetting;
use GlpiPlugin\Grcmanager\Config\Tab\AssetsTab;
use GlpiPlugin\Grcmanager\DiskEncryption;
use Html;
use Session;

/**
 * Issue #1044 : règle « doit être chiffré » du chiffrement des disques (issue #847), réglée une fois
 * par l'administrateur. La déclaration des clés de récupération reste sur la page d'exploitation.
 */
final class DiskEncryptionBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-encryption-rule';

    public static function tab(): string
    {
        return 'assets';
    }

    public static function order(): int
    {
        return 30;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_disk_encryption.html.twig';
    }

    /** Même contrôle que la page du chiffrement : droit du plugin et lecture des ordinateurs. */
    public static function canView(): bool
    {
        return DiskEncryption::canView();
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function data(): array
    {
        $rule = ComplianceAudit::getEncryptionRule();

        return [
            'encryption' => [
                'rule'       => $rule,
                'summary'    => DiskEncryption::ruleSummary($rule),
                'scopes'     => DiskEncryption::scopeLabels(),
                'thresholds' => DiskEncryption::confidentialityLabels(),
                'can_manage' => DiskEncryption::canManage(),
            ],
        ];
    }

    /** @param bool $moved envoi venu de l'ancienne page front/diskencryption.php */
    public static function handle(bool $moved = false): bool
    {
        if (!isset($_POST['save_encryption_rule'])) {
            return false;
        }
        Session::checkRight(DiskEncryption::RIGHTNAME, UPDATE);
        Session::checkRight(Computer::$rightname, READ);

        $before = ComplianceAudit::getEncryptionRule();
        $types = $_POST['encryption_types'] ?? [];
        $after = ComplianceAudit::setEncryptionRule([
            'scope'           => (string) ($_POST['encryption_scope'] ?? ''),
            'types'           => is_array($types) ? $types : [],
            'confidentiality' => (string) ($_POST['encryption_confidentiality'] ?? ''),
        ]);
        if ($after !== $before) {
            AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => ['disk_encryption_rule' => $after],
            ]);
        }
        Session::addMessageAfterRedirect(htmlescape(__('Encryption rule saved.', 'grcmanager')));
        if ($moved) {
            MovedSetting::notice(AssetsTab::label());
        }
        Html::redirect(ConfigTabs::url('assets', self::ANCHOR));

        return true;
    }
}
