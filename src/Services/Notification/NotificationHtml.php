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

namespace GlpiPlugin\Grcmanager\Services\Notification;

use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #199 : corps HTML des modèles de notification créés à l'installation, composé par blocs
 * (strong(), lines(), link()) et rendu par templates/notifications/template_html.html.twig au lieu
 * de HTML écrit en PHP.
 */
final class NotificationHtml
{
    /**
     * @param list<array<string, mixed>> $blocks
     */
    public static function render(array $blocks): string
    {
        return trim(self::environment()->load('@grcmanager/notifications/template_html.html.twig')->render([
            'blocks' => $blocks,
        ]));
    }

    /** @return array{type: string, text: string} ligne de titre en gras */
    public static function strong(string $text): array
    {
        return ['type' => 'strong', 'text' => $text];
    }

    /**
     * @param list<string> $lines
     * @return array{type: string, lines: list<string>} paragraphe, une ligne par élément
     */
    public static function lines(array $lines): array
    {
        return ['type' => 'lines', 'lines' => $lines];
    }

    /** @return array{type: string, url: string, text: string} paragraphe contenant un lien */
    public static function link(string $url, string $text): array
    {
        return ['type' => 'link', 'url' => $url, 'text' => $text];
    }

    /**
     * Environnement Twig de GLPI avec l'espace de noms @grcmanager : ces modèles sont créés à
     * l'installation, avant l'activation du plugin, quand GLPI n'a pas encore enregistré ses gabarits.
     */
    private static function environment(): \Twig\Environment
    {
        $environment = TemplateRenderer::getInstance()->getEnvironment();
        $loader = $environment->getLoader();
        if (
            $loader instanceof \Twig\Loader\FilesystemLoader
            && !in_array('grcmanager', $loader->getNamespaces(), true)
        ) {
            $loader->addPath(dirname(__DIR__, 3) . '/templates', 'grcmanager');
        }

        return $environment;
    }
}
