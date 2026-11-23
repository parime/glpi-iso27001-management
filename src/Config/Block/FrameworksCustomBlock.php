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

use GlpiPlugin\Grcmanager\Config\FrameworkFamilyBlock;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkFamilyRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

/**
 * Issue #1053 : référentiels importés depuis un fichier, et tout référentiel intégré qu'aucune autre
 * famille ne déclare (jamais perdu).
 */
final class FrameworksCustomBlock extends FrameworkFamilyBlock
{
    public static function order(): int
    {
        return 70;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_frameworks_custom.html.twig';
    }

    public static function label(): string
    {
        return _n('Custom framework', 'Custom frameworks', 2, 'grcmanager');
    }

    public static function icon(): string
    {
        return 'ti-file-spreadsheet';
    }

    public static function members(): array
    {
        return [];
    }

    public static function extraMembers(): array
    {
        $declared = [];
        foreach (\GlpiPlugin\Grcmanager\Config\FrameworkFamilies::classes() as $class) {
            if ($class !== self::class) {
                $declared = array_merge($declared, FrameworkFamilyRules::keys($class::members()));
            }
        }
        $orphans = array_values(array_diff(array_keys(FrameworkSelection::FRAMEWORKS), $declared));

        return array_merge($orphans, array_keys(CustomFrameworkCatalog::labels()));
    }

    public static function footerLink(): array
    {
        return [
            'label' => __('Import a custom framework', 'grcmanager'),
            // Issue #1047 : le bloc de création et d'import, juste après cette famille.
            'url'   => CustomFrameworkImportBlock::url(),
            'icon'  => 'ti-file-spreadsheet',
        ];
    }
}
