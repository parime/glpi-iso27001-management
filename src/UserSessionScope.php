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

use Profile;
use Session;
use User;

/**
 * Issue #877 : lecture « comme la personne » pour son récapitulatif, envoyé par une tâche
 * automatique où GLPI accorde tous les droits. Pour chacun des profils de la personne, la session
 * reçoit ce profil, toutes ses entités et la langue de la personne, puis le traitement est appelé :
 * les sources de « Mon espace GRC » appliquent alors les droits et les entités de cette personne,
 * et les textes sont dans sa langue. La session d'origine (et sa langue) est toujours rétablie.
 *
 * Lecture seule : aucune écriture n'est faite sous l'identité de la personne.
 */
final class UserSessionScope
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return list<T> un résultat par profil utilisable (aucun si le compte est introuvable)
     */
    public static function eachProfile(int $usersId, callable $callback): array
    {
        $user = new User();
        if ($usersId <= 0 || !$user->getFromDB($usersId)) {
            return [];
        }
        $saved = $_SESSION;
        $savedLanguage = (string) ($_SESSION['glpilanguage'] ?? '');
        $language = (string) ($user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? ''));
        $results = [];
        try {
            // Sans ces clés, GLPI ne considère plus la session comme celle d'une tâche automatique,
            // qui a tous les droits.
            unset(
                $_SESSION['glpicronuserrunning'],
                $_SESSION['glpiinventoryuserrunning'],
                $_SESSION['glpiactiveprofile'],
                $_SESSION['glpiactiveentities'],
                $_SESSION['glpigroups']
            );
            $_SESSION['glpiID'] = $usersId;
            $_SESSION['glpiname'] = (string) $user->fields['name'];
            $_SESSION['glpidefault_entity'] = (int) $user->fields['entities_id'];
            if ($language !== '' && $language !== $savedLanguage) {
                $_SESSION['glpilanguage'] = $language;
                Session::loadLanguage($language);
            }
            Session::initEntityProfiles($usersId);
            foreach (array_keys($_SESSION['glpiprofiles'] ?? []) as $profilesId) {
                if (self::activate((int) $profilesId)) {
                    $results[] = $callback();
                }
            }
        } finally {
            $_SESSION = $saved;
            if ($language !== '' && $language !== $savedLanguage) {
                Session::loadLanguage($savedLanguage);
            }
        }

        return $results;
    }

    /**
     * Profil actif et toutes ses entités, comme Session::changeProfile(), sans le crochet de
     * changement de profil des autres plugins.
     */
    private static function activate(int $profilesId): bool
    {
        $entities = $_SESSION['glpiprofiles'][$profilesId]['entities'] ?? [];
        $profile = new Profile();
        if ($entities === [] || !$profile->getFromDB($profilesId)) {
            return false;
        }
        $profile->cleanProfile();
        $_SESSION['glpiactiveprofile'] = ['entities' => $entities] + $profile->fields;
        $_SESSION['glpiactiveentities'] = [];

        return (bool) Session::changeActiveEntities('all');
    }
}
