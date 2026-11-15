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

namespace GlpiPlugin\Grcmanager\Services\Incident;

use Config;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

/**
 * Issue #600 : option « signalement CRA » (règlement (UE) 2024/2847, article 14), réservée aux
 * fabricants de produits comportant des éléments numériques. Désactivée par défaut : la case et les
 * échéances CRA des incidents n'apparaissent qu'une fois l'option activée (contexte
 * plugin:grcmanager, retiré avec le reste à la désinstallation). Le référentiel DORA, lui, se règle
 * avec les autres référentiels (FrameworkConfig).
 *
 * NOTE : dépend de la classe Config de GLPI, hors du périmètre des tests unitaires (même raison que
 * FrameworkConfig) ; la logique des échéances est testée dans RegulatoryDeadlines.
 */
final class CraReportingConfig
{
    public const CONFIG_KEY = 'cra_reporting_enabled';

    private static ?bool $enabled = null;

    public static function isEnabled(): bool
    {
        if (self::$enabled === null) {
            $values = Config::getConfigurationValues(FrameworkConfig::CONFIG_CONTEXT, [self::CONFIG_KEY]);
            self::$enabled = !empty($values[self::CONFIG_KEY]);
        }

        return self::$enabled;
    }

    public static function save(bool $enabled): void
    {
        Config::setConfigurationValues(FrameworkConfig::CONFIG_CONTEXT, [self::CONFIG_KEY => $enabled ? 1 : 0]);
        self::$enabled = $enabled;
    }

    /** Relit le réglage à la prochaine demande (tests). */
    public static function reset(): void
    {
        self::$enabled = null;
    }

    /**
     * Champs de l'incident limités aux régimes actifs : DORA si son référentiel est activé, CRA si
     * l'option l'est.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function restrict(array $fields): array
    {
        return RegulatoryDeadlines::restrictRegimes(
            $fields,
            FrameworkConfig::isEnabled(FrameworkSelection::DORA),
            self::isEnabled()
        );
    }
}
