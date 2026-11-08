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

namespace GlpiPlugin\Grcmanager\Services\Control;

use Config;
use Glpi\Exception\Http\AccessDeniedHttpException;

/**
 * Issue #403 : référentiels de conformité actifs, réglés dans la configuration du plugin (contexte
 * plugin:grcmanager, clé frameworks_disabled : liste JSON des référentiels désactivés, voir
 * FrameworkSelection). Réglage absent : tout reste actif, comme avant ; aucune migration n'est donc
 * nécessaire, et la désinstallation le retire avec le reste du contexte.
 *
 * NOTE : dépend de la classe Config de GLPI, hors du périmètre de phpstan.neon.dist (même raison que
 * RiskMatrixConfig) ; la logique est testée dans FrameworkSelection.
 */
final class FrameworkConfig
{
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const CONFIG_KEY = 'frameworks_disabled';
    /** Issue #379 : référentiels connus au dernier enregistrement (un optionnel ajouté depuis reste désactivé). */
    public const KNOWN_KEY = 'frameworks_known';

    /** @var list<string>|null lu une fois par requête, oublié à l'enregistrement */
    private static ?array $disabled = null;

    /**
     * @return list<string>
     */
    public static function disabled(): array
    {
        if (self::$disabled === null) {
            $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY, self::KNOWN_KEY]);
            $disabled = FrameworkSelection::decode(
                isset($values[self::CONFIG_KEY]) ? (string) $values[self::CONFIG_KEY] : null
            );
            $known = FrameworkSelection::decode(
                isset($values[self::KNOWN_KEY]) ? (string) $values[self::KNOWN_KEY] : null
            );
            // Un référentiel optionnel jamais présenté à l'enregistrement reste désactivé.
            foreach (FrameworkSelection::OPTIONAL as $optional) {
                if (!in_array($optional, $known, true) && !in_array($optional, $disabled, true)) {
                    $disabled[] = $optional;
                }
            }
            self::$disabled = $disabled;
        }

        return self::$disabled;
    }

    /**
     * @return list<string>
     */
    public static function enabled(): array
    {
        return FrameworkSelection::enabled(self::disabled());
    }

    public static function isEnabled(string $framework): bool
    {
        return !in_array($framework, self::disabled(), true);
    }

    /**
     * Écran propre à un référentiel désactivé, demandé par son adresse : refusé comme sans droit.
     */
    public static function checkEnabled(string $framework): void
    {
        if (!self::isEnabled($framework)) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * Ce que masque la désactivation de chaque référentiel (écran de configuration).
     *
     * @return array<string, string>
     */
    public static function helpTexts(): array
    {
        return [
            FrameworkSelection::ISO27001     => __(
                'Annex A table, ISMS requirements (clauses 4 to 10) and ISO 27001 scores. The list of security '
                    . 'controls stays: the other frameworks rely on it.',
                'grcmanager'
            ),
            FrameworkSelection::NIS2         => __(
                'NIS2 requirements, their references on the controls and NIS2 scores. The notification deadlines '
                    . 'of security incidents stay: they are a legal obligation.',
                'grcmanager'
            ),
            FrameworkSelection::ANSSI        => __(
                'Measures of the IT hygiene guide, their references on the controls and ANSSI score.',
                'grcmanager'
            ),
            FrameworkSelection::NIST_CSF     => __(
                'CSF 2.0 catalog, its equivalences on the controls and NIST score.',
                'grcmanager'
            ),
            FrameworkSelection::CIS_CONTROLS => __(
                'CIS Controls v8 catalog, its indicative references on the controls and CIS score.',
                'grcmanager'
            ),
            FrameworkSelection::ISO27090     => __(
                'AI security catalog and its references in the catalog of typical risks.',
                'grcmanager'
            ),
            FrameworkSelection::DORA         => __(
                'Financial sector (Regulation (EU) 2022/2554): DORA requirements, their references on the '
                    . 'controls and DORA score. Disabled by default.',
                'grcmanager'
            ),
            FrameworkSelection::ISO27701     => __(
                'Privacy (PIMS): ISO/IEC 27701 objectives, their references on the controls and links to the '
                    . 'GDPR modules. Disabled by default.',
                'grcmanager'
            ),
            FrameworkSelection::ISO22301     => __(
                'Business continuity (BCMS): ISO 22301 requirements, their references on the controls and links '
                    . 'to crisis exercises, risks and reviews. Disabled by default.',
                'grcmanager'
            ),
            FrameworkSelection::ISO42001     => __(
                'AI management (AIMS): ISO/IEC 42001 control groups, their references on the controls and links '
                    . 'to the AI risks, impact assessments and suppliers. Disabled by default.',
                'grcmanager'
            ),
            FrameworkSelection::PCI_DSS      => __(
                'Payment cards: the twelve PCI DSS requirements, their references on the controls and links to '
                    . 'network flows, vulnerabilities and access reviews. Disabled by default.',
                'grcmanager'
            ),
            FrameworkSelection::SOC2         => __(
                'SOC 2 report: Trust Services Criteria, their references on the controls and links to the '
                    . 'modules that produce the evidence. Disabled by default.',
                'grcmanager'
            ),
            FrameworkSelection::AI_ACT       => __(
                'European AI Act (Regulation (EU) 2024/1689, consolidated in 2026): obligations by role with their '
                    . 'application date, references on the controls. Disabled by default.',
                'grcmanager'
            ),
        ];
    }

    /**
     * Enregistre les référentiels cochés (valeurs inconnues ignorées).
     *
     * @param array<mixed> $enabled
     */
    public static function save(array $enabled): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            self::CONFIG_KEY => FrameworkSelection::encode($enabled),
            self::KNOWN_KEY  => (string) json_encode(array_keys(FrameworkSelection::FRAMEWORKS)),
        ]);
        self::$disabled = null;
    }
}
