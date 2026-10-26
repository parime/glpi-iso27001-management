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
 * Issue #199 : petits fragments HTML partagés (lien vers une fiche, contenu de ticket), rendus par
 * des gabarits Twig au lieu de HTML écrit en PHP. Tout le texte est échappé par Twig.
 */
final class Fragment
{
    /**
     * Lien vers une fiche ; $code (ex. « A.5.1 ») est affiché en gras devant le libellé, $icon
     * (icône Tabler, ex. « ti-shield-check ») avant.
     */
    public static function link(string $url, string $label, string $code = '', ?string $icon = null): string
    {
        return trim(TemplateRenderer::getInstance()->render('@grcmanager/_link.html.twig', [
            'url'   => $url,
            'label' => $label,
            'code'  => $code,
            'icon'  => $icon,
        ]));
    }

    /**
     * Texte court : icône optionnelle devant, et/ou classe CSS (ex. « text-muted »).
     */
    public static function text(string $label, ?string $icon = null, ?string $class = null): string
    {
        return trim(TemplateRenderer::getInstance()->render('@grcmanager/_text.html.twig', [
            'label' => $label,
            'icon'  => $icon,
            'class' => $class,
        ]));
    }

    /**
     * Contenu d'un ticket ou d'un suivi : un paragraphe par texte, puis un lien éventuel.
     *
     * @param list<string> $paragraphs
     */
    public static function ticketContent(array $paragraphs, string $url = '', ?string $linkLabel = null): string
    {
        return trim(TemplateRenderer::getInstance()->render('@grcmanager/ticket/_paragraphs.html.twig', [
            'paragraphs' => $paragraphs,
            'link'       => ['url' => $url, 'label' => $linkLabel],
        ]));
    }
}
