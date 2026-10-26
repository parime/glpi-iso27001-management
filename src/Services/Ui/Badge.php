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

namespace GlpiPlugin\Grcmanager\Services\Ui;

use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #199 : badges colorés des listes et fiches (statut, niveau, type), rendus par
 * templates/_badge.html.twig au lieu de HTML écrit en PHP. Le libellé est échappé par Twig.
 */
final class Badge
{
    /**
     * @param string  $class classes Tabler (ex. « bg-red-lt »)
     * @param ?string $icon  icône Tabler sans le préfixe « ti » (ex. « ti-alert-triangle »), ou null
     */
    public static function html(string $class, ?string $icon, string $label): string
    {
        return trim(TemplateRenderer::getInstance()->render('@grcmanager/_badge.html.twig', [
            'class' => $class,
            'icon'  => $icon,
            'label' => $label,
        ]));
    }
}
