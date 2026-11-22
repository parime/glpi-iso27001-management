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

namespace GlpiPlugin\Grcmanager\Config;

use GlpiPlugin\Grcmanager\Install\Installer;
use Session;

/**
 * Bloc de réglages de la page de configuration du plugin (issue #1036). Chaque bloc est un fichier
 * src/Config/Block/<Bloc>Block.php découvert par ConfigTabs : ajouter un réglage, c'est ajouter
 * un fichier (et son gabarit templates/config/blocks/_<bloc>.html.twig), sans toucher
 * front/config.php ni templates/config_form.html.twig.
 *
 * Le gabarit reçoit, en plus de ce que renvoie data(), les variables communes de la page :
 * csrf_token, can_edit_config (droit de modifier la configuration du plugin) et
 * can_edit_sensitive (droit de modifier les registres sensibles).
 */
abstract class ConfigBlock
{
    /** Clé de l'onglet (voir src/Config/Tab/*Tab.php) : general, frameworks, risks... */
    abstract public static function tab(): string;

    /** Gabarit du bloc, par exemple « @grcmanager/config/blocks/_dpo_contact.html.twig ». */
    abstract public static function template(): string;

    /** Clé du bloc, citée par la clé « after » d'un autre bloc : nom de la classe sans « Block ». */
    final public static function key(): string
    {
        $short = substr(static::class, (int) strrpos(static::class, '\\') + 1);

        return str_ends_with($short, 'Block') ? substr($short, 0, -5) : $short;
    }

    /** Place dans l'onglet : du plus petit au plus grand (de 10 en 10), puis nom de fichier. */
    public static function order(): int
    {
        return 100;
    }

    /** Clé d'un bloc du même onglet que celui-ci suit ; vide : place donnée par order(). */
    public static function after(): string
    {
        return '';
    }

    /**
     * Le bloc est-il montré à l'utilisateur ? Par défaut, lecture de la configuration du plugin
     * (droit de la page). Un onglet n'est montré que si au moins un de ses blocs l'est.
     */
    public static function canView(): bool
    {
        return (bool) Session::haveRight(Installer::RIGHT_NAME, READ);
    }

    /**
     * Identifiants HTML du bloc : les anciens liens config.php#<identifiant> ouvrent son onglet.
     *
     * @return list<string>
     */
    public static function anchors(): array
    {
        return [];
    }

    /**
     * Variables du gabarit, calculées seulement quand l'onglet du bloc est affiché.
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return [];
    }

    /**
     * Traitement d'un envoi du formulaire du bloc (POST vers front/config.php). Renvoie false si
     * l'envoi ne concerne pas ce bloc ; sinon contrôle les droits, enregistre et redirige
     * (Html::back(), qui revient sur l'onglet).
     */
    public static function handle(): bool
    {
        return false;
    }
}
