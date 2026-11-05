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

namespace GlpiPlugin\Grcmanager;

use Config;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use Plugin;
use Profile_User;
use User;

/**
 * Mécanisme commun aux notifications habillées du plugin (issue #380, puis #378) : droit du
 * destinataire, charte de Configuration GLPI Auto, nom et langue d'un utilisateur.
 *
 * - canReceive() : un destinataire ne reçoit le contenu que s'il a, sur un profil valable dans
 *   l'entité de la notification (mêmes critères que GLPI), tous les droits de lecture demandés. Un
 *   périmètre peut s'y ajouter : chacune de ses entités doit alors être couverte de la même façon.
 *   Une adresse sans compte GLPI ne reçoit rien.
 * - brandColor() : couleur publiée par Configuration GLPI Auto (contexte
 *   `plugin:configurationglpiauto`, clé `notification_branding_color`) s'il est actif, sinon la
 *   couleur par défaut. Toute valeur autre que #rrggbb est ignorée.
 */
final class NotificationSupport
{
    public const CGA_CONTEXT = SecurityCategoryLink::CGA_CONTEXT;
    public const CGA_BRAND_COLOR_KEY = 'notification_branding_color';

    /** Pour les tests : force la présence de Configuration GLPI Auto (null = détection réelle). */
    public static ?bool $forceCgaActive = null;

    public static function isCgaActive(): bool
    {
        return self::$forceCgaActive ?? Plugin::isPluginActive('configurationglpiauto');
    }

    /**
     * Couleur de la charte des notifications de Configuration GLPI Auto, sinon la couleur par défaut.
     */
    public static function brandColor(): string
    {
        if (!self::isCgaActive()) {
            return ControlTestRules::DEFAULT_BRAND_COLOR;
        }
        $values = Config::getConfigurationValues(self::CGA_CONTEXT, [self::CGA_BRAND_COLOR_KEY]);

        return ControlTestRules::brandColor($values[self::CGA_BRAND_COLOR_KEY] ?? '');
    }

    /**
     * Le compte GLPI peut-il recevoir une notification sur un objet de cette entité ?
     *
     * @param array<string, int> $rights    droit => valeur, tous exigés sur un même profil
     * @param list<int>          $perimeter entités supplémentaires à couvrir (sous-entités mesurées)
     */
    public static function canReceive(
        int $usersId,
        int $entitiesId,
        bool $recursive,
        array $rights,
        array $perimeter = []
    ): bool {
        if ($usersId <= 0 || $rights === []) {
            return false;
        }
        $entitiesId = max(0, $entitiesId);
        if (!self::hasRightsIn($usersId, $entitiesId, $recursive, $rights)) {
            return false;
        }
        foreach (array_unique(array_map('intval', $perimeter)) as $entity) {
            if ($entity !== $entitiesId && !self::hasRightsIn($usersId, $entity, false, $rights)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int> $rights
     */
    private static function hasRightsIn(int $usersId, int $entitiesId, bool $recursive, array $rights): bool
    {
        $filter = getEntitiesRestrictCriteria('glpi_profiles_users', '', $entitiesId, true);
        if ($recursive) {
            $filter = ['OR' => [$filter, ['entities_id' => getSonsOf('glpi_entities', $entitiesId)]]];
        }
        $profiles = array_map('intval', array_keys(Profile_User::getUserProfiles($usersId, $filter)));
        foreach ($profiles as $profileId) {
            $granted = true;
            foreach ($rights as $name => $value) {
                if (
                    countElementsInTable('glpi_profilerights', [
                        'profiles_id' => $profileId,
                        'name'        => $name,
                        'rights'      => ['&', $value],
                    ]) === 0
                ) {
                    $granted = false;
                    break;
                }
            }
            if ($granted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Langue et nom affiché d'un destinataire désigné par une cible propre au plugin.
     *
     * @return array{language: string, name: string}
     */
    public static function userInfo(int $usersId): array
    {
        $user = new User();
        if (!$user->getFromDB($usersId)) {
            return ['language' => '', 'name' => ''];
        }

        return [
            'language' => (string) ($user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? 'en_GB')),
            'name'     => formatUserName(
                0,
                $user->fields['name'],
                $user->fields['realname'],
                $user->fields['firstname']
            ),
        ];
    }
}
