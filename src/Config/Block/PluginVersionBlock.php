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
use GlpiPlugin\Grcmanager\PluginVersion;

/** Version installée et dernière version publiée. */
final class PluginVersionBlock extends ConfigBlock
{
    public static function tab(): string
    {
        return 'general';
    }

    public static function order(): int
    {
        // Premier bloc affiché de la configuration (demande du mainteneur, 23/11).
        return 1;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_plugin_version.html.twig';
    }

    public static function anchors(): array
    {
        return ['grc-plugin-version'];
    }

    public static function data(): array
    {
        // Issue #1064 : composant commun du modèle (parime/glpi-plugin-template#12). La dernière version
        // et, en cas d'échec, sa raison et la date de la dernière vérification réussie sont affichées.
        $version = new PluginVersion('grcmanager', 'parime/glpi-iso27001-management', PLUGIN_GRCMANAGER_VERSION);

        return ['plugin_version' => $version->status(self::checkEnabled())];
    }

    /**
     * Opt-in conservé : aucun appel sortant vers GitHub tant que l'action automatique native
     * « checkupdate » de GLPI (Configuration > Actions automatiques, désactivée par défaut) n'est pas
     * activée. Refuser la vérification de mise à jour de GLPI refuse aussi celle du plugin.
     */
    private static function checkEnabled(): bool
    {
        $checkUpdate = new \CronTask();

        return $checkUpdate->getFromDBbyName('CronTask', 'checkupdate')
            && (int) $checkUpdate->fields['state'] !== \CronTask::STATE_DISABLE;
    }
}
