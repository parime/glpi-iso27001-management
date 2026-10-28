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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\Installer;
use PluginGrcmanagerSecurityIncident;
use Profile as GlpiProfile;
use RulePluginGrcmanagerSecurityIncident;
use Session;

/**
 * Onglet « GRC Manager » des profils GLPI : donner les droits du plugin à un profil.
 *
 * Jusqu'ici, seuls les droits du Super-Admin étaient posés à l'installation ; pour tout autre
 * profil il fallait écrire en base. La matrice est celle du cœur (Profile::displayRightsChoiceMatrix)
 * et le formulaire est envoyé à front/profile.form.php, qui enregistre lui-même les droits avec
 * le contrôle du droit « profile » du cœur.
 */
class Profile extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::canManage($item)) {
            return '';
        }

        return self::createTabEntry(__('GRC Manager', 'grcmanager'), 0, null, 'ti ti-shield-check');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!self::canManage($item)) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/profile_rights.html.twig', [
            'item'   => $item,
            'rights' => self::getRightsMatrix(),
        ]);

        return true;
    }

    /**
     * Lignes de la matrice : le droit commun à tous les objets du SMSI, puis les droits propres
     * aux incidents de sécurité (objet ITIL, avec « voir tous » / « voir les siens »).
     *
     * @return list<array<string, mixed>>
     */
    public static function getRightsMatrix(): array
    {
        return [
            [
                // Droit partagé par tous les objets du SMSI, dont certains ont une corbeille
                // (dérogations) : colonnes posées ici plutôt que reprises d'un objet en particulier.
                'rights'   => [
                    READ   => __('Read'),
                    UPDATE => __('Update'),
                    CREATE => __('Create'),
                    DELETE => _x('button', 'Put in trashbin'),
                    PURGE  => ['short' => __('Purge'), 'long' => _x('button', 'Delete permanently')],
                    // Issue #140 : notes de travail des audits.
                    READNOTE   => ['short' => __('Read notes'), 'long' => __('Read the item\'s notes')],
                    UPDATENOTE => ['short' => __('Update notes'), 'long' => __('Update the item\'s notes')],
                ],
                'label'    => __('Security governance (ISMS)', 'grcmanager'),
                'field'    => Installer::RIGHT_NAME,
            ],
            [
                // Registres sensibles : registre des risques et risques fournisseurs, plans de
                // traitement, analyse d'impact, effet domino, vulnérabilités du parc, matrice des flux.
                'rights'   => [
                    READ   => __('Read'),
                    UPDATE => __('Update'),
                    CREATE => __('Create'),
                    DELETE => _x('button', 'Put in trashbin'),
                    PURGE  => ['short' => __('Purge'), 'long' => _x('button', 'Delete permanently')],
                ],
                'label'    => __('Sensitive registers (risks, vulnerabilities, network flows)', 'grcmanager'),
                'field'    => Installer::SENSITIVE_RIGHT,
            ],
            [
                'itemtype' => PluginGrcmanagerSecurityIncident::class,
                'label'    => PluginGrcmanagerSecurityIncident::getTypeName(Session::getPluralNumber()),
                'field'    => Installer::SECURITY_INCIDENT_RIGHT,
            ],
            [
                'itemtype' => RulePluginGrcmanagerSecurityIncident::class,
                'label'    => RulePluginGrcmanagerSecurityIncident::getTypeName(Session::getPluralNumber()),
                'field'    => Installer::SECURITY_INCIDENT_RULE_RIGHT,
            ],
        ];
    }

    /**
     * Droit « profile » du cœur pour lire et enregistrer la matrice, et accès au module GRC : on
     * ne délègue pas des droits sur un module qu'on ne voit pas. Un profil « interface simplifiée »
     * n'a accès à aucun écran du plugin.
     */
    private static function canManage(CommonGLPI $item): bool
    {
        // GlpiProfile : le profil du cœur et son droit « profile », pas cette classe (même nom court).
        return $item instanceof GlpiProfile && !$item->isNewItem()
            && ($item->fields['interface'] ?? '') === 'central'
            && GlpiProfile::canView()
            && Session::haveRight(Installer::RIGHT_NAME, READ);
    }
}
