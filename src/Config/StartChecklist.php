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

use Config;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\SoaScopingRules;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneConfig;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaConfig;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixDefaults;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;
use GlpiPlugin\Grcmanager\Services\Setup\StartChecklistRules;
use GlpiPlugin\Grcmanager\SoaScoping;
use Profile;
use Toolbox;

/**
 * Issue #1046 : liste de démarrage de l'administrateur, en tête de l'onglet Général. Chaque étape
 * est lue dans l'existant (réglages, droits, dernières lectures des connecteurs, cadrage de la
 * SoA), sans rien modifier ; le calcul de l'avancement est dans StartChecklistRules.
 *
 * Chaque étape mène à son onglet de configuration. Le cadrage de la SoA est un écran d'usage :
 * l'étape indique où le trouver dans le menu, sans lien (la page de configuration ne sert pas de
 * porte d'entrée vers l'exploitation).
 */
final class StartChecklist
{
    /**
     * @return array{
     *     steps: list<array{key: string, done: bool, label: string, help: string, icon: string, url: string, link: string}>,
     *     done: int, total: int, percent: int, complete: bool
     * }
     */
    public static function build(): array
    {
        $summary = StartChecklistRules::summarize(self::state());
        $texts = self::texts();
        $steps = [];
        foreach ($summary['steps'] as $step) {
            $steps[] = $step + $texts[$step['key']];
        }
        $summary['steps'] = $steps;

        return $summary;
    }

    /**
     * État de chaque étape, lu dans l'existant.
     *
     * @return array<string, bool>
     */
    public static function state(): array
    {
        return [
            'frameworks'  => self::frameworksChosen(),
            'risk_matrix' => self::riskMatrixValidated(),
            'profiles'    => self::profileRightsGiven(),
            'ciso_group'  => AuditTeam::getCisoGroupId() > 0,
            'connector'   => self::connectorTested(),
            'soa_scope'   => StartChecklistRules::isSoaScoped(
                SoaScoping::answers(SoaScoping::entity()),
                array_keys(SoaScopingRules::QUESTIONS)
            ),
        ];
    }

    /** Le choix des référentiels a été enregistré au moins une fois. */
    private static function frameworksChosen(): bool
    {
        $values = Config::getConfigurationValues(FrameworkConfig::CONFIG_CONTEXT, [FrameworkConfig::KNOWN_KEY]);

        return trim((string) ($values[FrameworkConfig::KNOWN_KEY] ?? '')) !== '' && FrameworkConfig::enabled() !== [];
    }

    private static function riskMatrixValidated(): bool
    {
        return StartChecklistRules::isMatrixValidated(
            RiskMatrixConfig::validatedAt(),
            RiskMatrixConfig::load(),
            RiskScale::normalizeMatrix(RiskMatrixDefaults::forSize(RiskScale::DEFAULT_SIZE), RiskScale::DEFAULT_SIZE),
            RiskMatrixConfig::size(),
            RiskScale::DEFAULT_SIZE,
            RiskMatrixConfig::labels()
        );
    }

    /**
     * Le droit du plugin est donné à au moins un profil autre que Super-Admin (qui le reçoit à
     * l'installation).
     */
    private static function profileRightsGiven(): bool
    {
        global $DB;

        $superAdmins = [];
        $profiles = $DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['name' => 'Super-Admin']]);
        foreach ($profiles as $row) {
            $superAdmins[] = (int) $row['id'];
        }
        $where = ['name' => Installer::RIGHT_NAME, 'rights' => ['>', 0]];
        if ($superAdmins !== []) {
            $where['NOT'] = ['profiles_id' => $superAdmins];
        }

        return countElementsInTable('glpi_profilerights', $where) > 0;
    }

    /** Au moins un connecteur a une lecture réussie. */
    private static function connectorTested(): bool
    {
        $reads = [];
        $sentinelOne = SentinelOneConfig::lastRun();
        if ($sentinelOne !== null) {
            $errors = array_filter(array_map(
                static fn (array $step): string => (string) $step['error'],
                $sentinelOne['steps']
            ));
            $reads[] = ['date' => (string) $sentinelOne['date'], 'error' => implode(' ', $errors)];
        }
        foreach ([ZabbixConfig::lastRead(), UptimeKumaConfig::lastRead(), CodeSecurityConfig::lastRead()] as $read) {
            $reads[] = ['date' => $read['date'], 'error' => $read['error']];
        }
        $reads[] = ['date' => (string) EndOfLifeStore::sourceState()['last_success'], 'error' => ''];

        return StartChecklistRules::hasSuccessfulRead($reads);
    }

    /**
     * Libellé, aide, icône et lien de chaque étape.
     *
     * @return array<string, array{label: string, help: string, icon: string, url: string, link: string}>
     */
    private static function texts(): array
    {
        $open = __('Open the setting', 'grcmanager');

        return [
            'frameworks' => [
                'label' => __('Choose the frameworks', 'grcmanager'),
                'help'  => __('Tick the frameworks your organisation follows and save the choice: the other ones disappear from the screens.', 'grcmanager'),
                'icon'  => 'ti-books',
                'url'   => ConfigTabs::url('frameworks'),
                'link'  => $open,
            ],
            'risk_matrix' => [
                'label' => __('Validate the risk matrix', 'grcmanager'),
                'help'  => __('Check the probability x impact grid, its size and its labels, then save it, even unchanged.', 'grcmanager'),
                'icon'  => 'ti-grid-dots',
                'url'   => ConfigTabs::anchorUrl('grc-risk-matrix-config'),
                'link'  => $open,
            ],
            'profiles' => [
                'label' => __('Give the rights to a profile', 'grcmanager'),
                'help'  => __('After installation, only the Super-Admin profile can use the plugin. Open a profile, then its GRC Manager tab, to give the rights to the people who work on the ISMS.', 'grcmanager'),
                'icon'  => 'ti-user-check',
                'url'   => Toolbox::getItemTypeSearchURL(Profile::class),
                'link'  => __('Open the profiles', 'grcmanager'),
            ],
            'ciso_group' => [
                'label' => __('Choose the CISO group', 'grcmanager'),
                'help'  => __('The members of this group can see every audit and its documents.', 'grcmanager'),
                'icon'  => 'ti-users-group',
                'url'   => ConfigTabs::anchorUrl('grc-audit-config'),
                'link'  => $open,
            ],
            'connector' => [
                'label' => __('Test at least one connector', 'grcmanager'),
                'help'  => __('Enter the address and the key of a tool you use (SentinelOne, Zabbix, Uptime Kuma, GitHub or GitLab, endoflife.date): the step is done after a first successful read.', 'grcmanager'),
                'icon'  => 'ti-plug-connected',
                'url'   => ConfigTabs::url('connectors'),
                'link'  => $open,
            ],
            'soa_scope' => [
                'label' => __('Scope the Statement of Applicability', 'grcmanager'),
                'help'  => sprintf(
                    __('Answer every question of the "%s" screen, in the GRC menu: the answers suggest which controls apply.', 'grcmanager'),
                    SoaScoping::getTypeName()
                ),
                'icon'  => 'ti-checklist',
                'url'   => '',
                'link'  => '',
            ],
        ];
    }
}
