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
use GlpiPlugin\Grcmanager\EvidencePack;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidencePackRules;
use Html;
use Session;

/**
 * Issue #1045 : limite de taille du dossier de preuves (issue #868), venue de
 * front/evidencepack.php. Lecture avec le droit de lire le plugin, enregistrement avec celui de
 * le modifier (contrôles d'origine).
 */
final class EvidencePackBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-evidence-pack-settings';

    public static function tab(): string
    {
        return 'controls';
    }

    public static function order(): int
    {
        return 60;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_evidence_pack.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return EvidencePack::canView();
    }

    public static function data(): array
    {
        return [
            'pack_title'    => EvidencePack::getTypeName(1),
            'pack_max_mb'   => EvidencePack::getMaxMb(),
            'pack_min_mb'   => EvidencePackRules::MIN_MAX_MB,
            'pack_limit_mb' => EvidencePackRules::MAX_MAX_MB,
            'pack_can_edit' => EvidencePack::canGenerate(),
        ];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['update_evidence_pack'])) {
            return false;
        }
        Session::checkRight(EvidencePack::RIGHTNAME, UPDATE);
        EvidencePack::saveMaxMb($_POST['max_mb'] ?? null);
        Session::addMessageAfterRedirect(__('Evidence pack settings saved.', 'grcmanager'));
        Html::back();

        return true;
    }
}
