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
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\RiskProfiles;
use Profile;
use Session;

/**
 * Issue #1182 : profils prêts à attribuer pour la gestion des risques (onglet « Risques » de la
 * configuration). Chaque profil se crée d'un clic, avec le strict nécessaire (RiskProfiles) ; il
 * s'attribue ensuite comme tout profil GLPI.
 */
final class RiskProfilesBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-risk-profiles-config';

    public static function tab(): string
    {
        return 'risks';
    }

    public static function order(): int
    {
        return 70;
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_risk_profiles.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function data(): array
    {
        $profiles = [];
        foreach (RiskProfiles::definitions() as $key => $definition) {
            $id = RiskProfiles::existingId($key);
            $profiles[] = [
                'key'        => $key,
                'name'       => $definition['name'],
                'purpose'    => $definition['purpose'],
                'rights'     => RiskProfiles::rightsSummary($key),
                'url'        => $id > 0 && Profile::canView() ? Profile::getFormURLWithID($id) : '',
                'exists'     => $id > 0,
                'can_create' => $id === 0 && RiskProfiles::canCreate($key),
            ];
        }

        return ['risk_profiles' => $profiles];
    }

    public static function handle(): bool
    {
        if (!isset($_POST['create_risk_profile'])) {
            return false;
        }
        Session::checkRight(Installer::RIGHT_NAME, UPDATE);
        Session::checkRight('profile', CREATE);
        Session::checkRight('profile', UPDATE);

        $key = (string) $_POST['create_risk_profile'];
        if (RiskProfiles::create($key) > 0) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Profile created. Assign it to people from their user record.', 'grcmanager'))
            );
        } else {
            Session::addMessageAfterRedirect(
                htmlescape(__('The profile was not created: you need at least the rights it gives.', 'grcmanager')),
                false,
                ERROR
            );
        }
        MovedSettings::redirect(self::ANCHOR);

        return true;
    }
}
