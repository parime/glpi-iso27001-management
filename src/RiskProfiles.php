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

use GlpiPlugin\Grcmanager\Install\Installer;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

/**
 * Issue #1182 : profils GLPI prêts à attribuer pour la gestion des risques (gestionnaire des
 * risques, propriétaire de risque, responsable de traitement, comité des risques, audit interne).
 *
 * Créés à la demande depuis la configuration du plugin, jamais à l'installation : un profil du cœur
 * appartient à l'administrateur, qui l'attribue ensuite (Administration > Utilisateurs) et peut le
 * modifier. Chaque profil reçoit le strict nécessaire : les droits du plugin listés ici, aucun droit
 * sur le parc (ordinateurs, logiciels, réseau...) ni sur l'assistance. L'audit interne est en lecture
 * seule. Seule une personne qui peut créer des profils, modifier la configuration du plugin et qui a
 * elle-même au moins ces droits peut les créer (aucune élévation de droits).
 *
 * La séparation des tâches de l'acceptation ne dépend pas du profil : elle est contrôlée sur chaque
 * risque (Risk::applyAppetite(), jamais le propriétaire du risque).
 */
final class RiskProfiles
{
    public const KEYS = ['risk_manager', 'risk_owner', 'treatment_owner', 'risk_committee', 'internal_audit'];

    /**
     * Droits de chaque profil : uniquement les droits du plugin, plus les préférences personnelles.
     *
     * @return array<string, int>
     */
    public static function rights(string $key): array
    {
        $common = [
            Installer::RIGHT_NAME => READ,
            // Préférences personnelles (langue, thème) : rien d'autre du cœur.
            'personalization'     => READ | UPDATE,
        ];

        return match ($key) {
            'risk_manager' => $common + [Installer::SENSITIVE_RIGHT => READ | UPDATE | CREATE | DELETE | PURGE],
            // Mêmes droits sur le registre : ce qui les distingue est le propriétaire de chaque risque
            // et la séparation des tâches de l'acceptation, contrôlée sur le risque.
            'risk_owner', 'treatment_owner', 'risk_committee'
                => $common + [Installer::SENSITIVE_RIGHT => READ | UPDATE],
            'internal_audit' => $common + [Installer::SENSITIVE_RIGHT => READ],
            default => [],
        };
    }

    /**
     * Nom, rôle et droits lisibles de chaque profil.
     *
     * @return array<string, array{name: string, purpose: string}>
     */
    public static function definitions(): array
    {
        return [
            'risk_manager' => [
                'name'    => __('GRC - Risk manager', 'grcmanager'),
                'purpose' => __(
                    'Keeps the risk register: creates, assesses, reviews and closes risks, sets the appetite and '
                        . 'signs acceptances of risks they do not own.',
                    'grcmanager'
                ),
            ],
            'risk_owner' => [
                'name'    => __('GRC - Risk owner', 'grcmanager'),
                'purpose' => __(
                    'Treats the risks entrusted to them: updates the assessment, the treatment plan and records '
                        . 'reviews. Never signs the acceptance of their own risks.',
                    'grcmanager'
                ),
            ],
            'treatment_owner' => [
                'name'    => __('GRC - Treatment action owner', 'grcmanager'),
                'purpose' => __('Carries out treatment actions and updates their status.', 'grcmanager'),
            ],
            'risk_committee' => [
                'name'    => __('GRC - Risk committee', 'grcmanager'),
                'purpose' => __(
                    'Reviews the register and signs or renews the acceptance of residual risks owned by others.',
                    'grcmanager'
                ),
            ],
            'internal_audit' => [
                'name'    => __('GRC - Internal audit', 'grcmanager'),
                'purpose' => __(
                    'Reads the risk register, its reviews and the ISMS to audit them. Read only, without any '
                        . 'access to the assets.',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * Droits lisibles d'un profil, pour la configuration.
     *
     * @return list<string>
     */
    public static function rightsSummary(string $key): array
    {
        $labels = [
            Installer::RIGHT_NAME      => __('Security governance (ISMS)', 'grcmanager'),
            Installer::SENSITIVE_RIGHT => __(
                'Sensitive registers (risks, risk indicators, vulnerabilities, network flows)',
                'grcmanager'
            ),
        ];
        $names = [READ => __('Read'), UPDATE => __('Update'), CREATE => __('Create'),
            DELETE => _x('button', 'Put in trashbin'), PURGE => __('Purge')];
        $summary = [];
        foreach (self::rights($key) as $right => $value) {
            if (!isset($labels[$right])) {
                continue;
            }
            $granted = [];
            foreach ($names as $bit => $name) {
                if (($value & $bit) === $bit) {
                    $granted[] = $name;
                }
            }
            $summary[] = sprintf(__('%1$s: %2$s', 'grcmanager'), $labels[$right], implode(', ', $granted));
        }
        $summary[] = __('No right on assets, assistance or administration', 'grcmanager');

        return $summary;
    }

    /** Identifiant du profil déjà créé (même nom), 0 sinon. */
    public static function existingId(string $key): int
    {
        $definition = self::definitions()[$key] ?? null;
        if ($definition === null) {
            return 0;
        }
        $profile = new GlpiProfile();

        return $profile->getFromDBByCrit(['name' => $definition['name']]) ? (int) $profile->getID() : 0;
    }

    /**
     * La personne connectée peut-elle créer ce profil ? Droits de créer et de modifier les profils,
     * de modifier la configuration du plugin, et chacun des droits du plugin donnés au profil.
     */
    public static function canCreate(string $key): bool
    {
        $rights = self::rights($key);
        if (
            $rights === []
            || !Session::haveRight('profile', CREATE)
            || !Session::haveRight('profile', UPDATE)
            || !Session::haveRight(Installer::RIGHT_NAME, UPDATE)
        ) {
            return false;
        }
        foreach ($rights as $right => $value) {
            if ($right !== 'personalization' && !Session::haveRightsAnd($right, self::bits($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Crée le profil s'il n'existe pas encore. Renvoie son identifiant, 0 en cas de refus ou d'échec.
     */
    public static function create(string $key): int
    {
        if (!in_array($key, self::KEYS, true) || !self::canCreate($key)) {
            return 0;
        }
        $existing = self::existingId($key);
        if ($existing > 0) {
            return $existing;
        }
        $definition = self::definitions()[$key];
        $profile = new GlpiProfile();
        $id = (int) $profile->add([
            'name'      => $definition['name'],
            'interface' => 'central',
            'comment'   => $definition['purpose'],
        ]);
        if ($id <= 0) {
            return 0;
        }
        // Tous les droits du cœur à zéro (le profil n'hérite d'aucun modèle), puis ceux du plugin.
        global $DB;
        $DB->update(ProfileRight::getTable(), ['rights' => 0], ['profiles_id' => $id]);
        ProfileRight::updateProfileRights($id, self::rights($key));
        AuditLog::record('risk_profile', GlpiProfile::class, $id, 0, 'success', ['profile' => $key]);

        return $id;
    }

    /**
     * @return list<int>
     */
    private static function bits(int $value): array
    {
        $bits = [];
        foreach ([READ, UPDATE, CREATE, DELETE, PURGE] as $bit) {
            if (($value & $bit) === $bit) {
                $bits[] = $bit;
            }
        }

        return $bits;
    }
}
