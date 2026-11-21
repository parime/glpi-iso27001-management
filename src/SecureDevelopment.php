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

use Appliance;
use Change;
use Change_Item;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\SecureDevelopmentTables;
use GlpiPlugin\Grcmanager\Services\SecureDevelopment\SecureDevelopmentRules as Rules;
use Session;

/**
 * Issue #777 : page « Développement sécurisé », sous-page de la rubrique Conformité (aucune entrée
 * de menu en plus). Registre des applications qui ont une fiche « développement sécurisé »
 * (applications du cœur), mises en production de l'année et leurs validations, indicateurs, et
 * preuves des mesures A.8.25 à A.8.31 sur la fiche de la mesure (lot 2 : A.8.26, A.8.27, A.8.30 et le
 * détail de la séparation des environnements).
 *
 * Calculé à la demande avec les droits et les entités de la personne :
 * - droit de lecture du plugin pour la page et les preuves ;
 * - applications : lecture native des applications, entités de la session ;
 * - mises en production : lecture de tous les changements (comme le contrôle « changements validés »
 *   de l'audit de conformité), entités de la session.
 * Ancre sans table.
 */
class SecureDevelopment extends CommonGLPI
{
    /** Mesures qui reçoivent les preuves du développement sécurisé. */
    public const CONTROL_CODES = ['A.8.25', 'A.8.26', 'A.8.27', 'A.8.28', 'A.8.29', 'A.8.30', 'A.8.31'];

    /** Période des mises en production comptées (jours). */
    public const RELEASE_PERIOD_DAYS = 365;

    public static function getTypeName($nb = 0)
    {
        return __('Secure development', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-code';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(Installer::RIGHT_NAME, READ);
    }

    public static function canViewApplications(): bool
    {
        return self::canView() && Appliance::canView();
    }

    /** Toutes les mises en production des entités : réservé à qui voit tous les changements. */
    public static function canViewReleases(): bool
    {
        return self::canView() && Session::haveRight(Change::$rightname, Change::READALL);
    }

    public static function getPageURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/securedevelopment.php';
    }

    /**
     * Sous-page de la rubrique « Conformité » (Menu::sections()), avec le droit général du plugin.
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getPageURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Données.

    /**
     * Applications du registre (avec une fiche), visibles par la personne.
     *
     * @return list<array<string, mixed>>
     */
    public static function applications(): array
    {
        global $DB;

        if (!self::canViewApplications()) {
            return [];
        }
        $profiles = SecureDevelopmentTables::APPLICATIONS;
        $rows = $DB->request([
            'SELECT'     => [
                $profiles . '.*',
                'glpi_appliances.name AS appliance_name',
                'glpi_appliances.entities_id',
                'glpi_appliances.users_id_tech',
            ],
            'FROM'       => $profiles,
            'INNER JOIN' => [
                'glpi_appliances' => ['FKEY' => [
                    'glpi_appliances' => 'id',
                    $profiles         => 'appliances_id',
                ]],
            ],
            'WHERE'      => [
                'glpi_appliances.is_deleted' => 0,
            ] + getEntitiesRestrictCriteria('glpi_appliances', '', '', true),
            'ORDER'      => 'glpi_appliances.name',
        ]);
        $applications = [];
        foreach ($rows as $row) {
            $applications[] = $row;
        }

        return $applications;
    }

    /**
     * Mises en production des douze derniers mois : changements résolus ou clos, liés à une
     * application du registre, avec l'état de leur validation sécurité.
     *
     * @return list<array<string, mixed>>
     */
    public static function releases(?string $now = null): array
    {
        global $DB;

        if (!self::canViewReleases()) {
            return [];
        }
        $now ??= (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $since = date('Y-m-d H:i:s', (int) strtotime($now) - self::RELEASE_PERIOD_DAYS * DAY_TIMESTAMP);
        $items = Change_Item::getTable();
        $rows = $DB->request([
            'SELECT'     => [
                'glpi_changes.id', 'glpi_changes.name', 'glpi_changes.status', 'glpi_changes.solvedate',
                'glpi_changes.closedate', 'glpi_appliances.name AS appliance_name',
            ],
            'FROM'       => 'glpi_changes',
            'INNER JOIN' => [
                $items                                => ['FKEY' => [$items => 'changes_id', 'glpi_changes' => 'id']],
                'glpi_appliances'                     => ['FKEY' => ['glpi_appliances' => 'id', $items => 'items_id']],
                SecureDevelopmentTables::APPLICATIONS => ['FKEY' => [
                    SecureDevelopmentTables::APPLICATIONS => 'appliances_id',
                    'glpi_appliances'                     => 'id',
                ]],
            ],
            'WHERE'      => [
                $items . '.itemtype'         => Appliance::class,
                'glpi_changes.is_deleted'    => 0,
                'glpi_changes.status'        => ReleaseValidation::releasedStatuses(),
                'glpi_appliances.is_deleted' => 0,
                'OR'                         => [
                    ['glpi_changes.solvedate' => ['>=', $since]],
                    ['glpi_changes.closedate' => ['>=', $since]],
                ],
            ] + getEntitiesRestrictCriteria('glpi_changes', '', '', true),
            'ORDER'      => ['glpi_changes.solvedate DESC', 'glpi_changes.id DESC'],
        ]);
        $releases = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($releases[$id])) {
                $releases[$id]['applications'][] = (string) $row['appliance_name'];
                continue;
            }
            $releases[$id] = [
                'id'           => $id,
                'name'         => (string) $row['name'],
                'released_at'  => ReleaseValidation::releaseDate($row),
                'applications' => [(string) $row['appliance_name']],
            ];
        }
        $validations = [];
        if ($releases !== []) {
            foreach (
                $DB->request([
                    'FROM'  => SecureDevelopmentTables::RELEASES,
                    'WHERE' => ['changes_id' => array_keys($releases)],
                ]) as $row
            ) {
                $validations[(int) $row['changes_id']] = $row;
            }
        }
        foreach ($releases as $id => $release) {
            $validation = $validations[$id] ?? null;
            $releases[$id]['validation'] = $validation;
            $releases[$id]['state'] = Rules::releaseState($validation, $release['released_at']);
        }

        return array_values($releases);
    }

    /**
     * @param list<array<string, mixed>>|null $applications
     * @param list<array<string, mixed>>|null $releases
     * @return array<string, int>
     */
    public static function indicators(?array $applications = null, ?array $releases = null): array
    {
        $applications ??= self::applications();
        $releases ??= self::releases();

        return Rules::indicators(
            $applications,
            array_map(static fn (array $release): string => (string) $release['state'], $releases)
        );
    }

    // ------------------------------------------------------------------ Affichage.

    public static function showPage(): void
    {
        $applications = self::applications();
        $releases = self::releases();
        $frameworks = ApplicationSecurity::frameworkLabels();
        $practices = ApplicationSecurity::practiceLabels();
        $stateInfos = ReleaseValidation::releaseStateInfos();

        $rows = array_map(static function (array $app) use ($frameworks): array {
            $owner = (int) $app['users_id_owner'] ?: (int) $app['users_id_tech'];

            return [
                'url'           => Appliance::getFormURLWithID((int) $app['appliances_id'])
                    . '&forcetab=' . rawurlencode(ApplicationSecurity::class . '$1'),
                'name'          => (string) $app['appliance_name'],
                'entity'        => (string) Dropdown::getDropdownName('glpi_entities', (int) $app['entities_id']),
                'owner'         => $owner > 0 ? getUserName($owner) : '',
                'repository'    => (string) $app['repository_url'],
                'repository_link' => Rules::isWebLink((string) $app['repository_url']),
                'framework'     => $frameworks[(string) $app['framework']] ?? '',
                'practices'     => array_combine(
                    Rules::PRACTICES,
                    array_map(static fn (string $p): bool => (int) $app[$p] === 1, Rules::PRACTICES)
                ),
                'separation'    => (string) $app['environments_separated'],
                'unjustified'   => Rules::justificationMissing(
                    (string) $app['environments_separated'],
                    (string) ($app['separation_justification'] ?? '')
                ),
                'separation_gaps' => count(Rules::separationGaps($app)),
                'requirements'  => !Rules::withoutRequirements($app),
                'architecture'  => !Rules::withoutArchitecture($app),
                'outsourced'    => Rules::isOutsourced($app),
                'outsourcing_gap' => Rules::outsourcingGap($app),
            ];
        }, $applications);

        $releaseRows = array_map(static function (array $release) use ($stateInfos): array {
            $validation = $release['validation'];

            return [
                'url'          => Change::getFormURLWithID($release['id'])
                    . '&forcetab=' . rawurlencode(ReleaseValidation::class . '$1'),
                'name'         => $release['name'],
                'applications' => implode(', ', array_unique($release['applications'])),
                'released_at'  => $release['released_at'],
                'state'        => $stateInfos[$release['state']],
                'ok'           => !Rules::withoutValidation($release['state']),
                'decider'      => $validation !== null && (int) $validation['users_id_decision'] > 0
                    ? getUserName((int) $validation['users_id_decision'])
                    : '',
                'decided_at'   => $validation['date_decision'] ?? '',
            ];
        }, $releases);

        TemplateRenderer::getInstance()->display('@grcmanager/secure_development_page.html.twig', [
            'indicators'        => self::indicators($applications, $releases),
            'applications'      => $rows,
            'releases'          => $releaseRows,
            'practices'         => $practices,
            'can_see_apps'      => self::canViewApplications(),
            'can_see_releases'  => self::canViewReleases(),
            'appliances_url'    => Appliance::getSearchURL(),
            'period_days'       => self::RELEASE_PERIOD_DAYS,
        ]);
    }

    /**
     * Preuves sur la fiche des mesures A.8.25 à A.8.31 (null pour une autre mesure
     * ou sans le droit du plugin).
     *
     * @return array{badges: list<array{label: string, color: string, icon: string}>, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::CONTROL_CODES, true) || !self::canView()) {
            return null;
        }
        $applications = self::applications();
        $releases = self::releases();
        $i = self::indicators($applications, $releases);
        $badge = static fn (bool $ok, string $label, string $icon): array => [
            'label' => $label,
            'color' => $ok ? 'success' : 'warning',
            'icon'  => $icon,
        ];
        $total = $i['applications'];
        $badges = [[
            'label' => sprintf(
                _n('%d application in the register', '%d applications in the register', $total, 'grcmanager'),
                $total
            ),
            'color' => $total > 0 ? 'success' : 'danger',
            'icon'  => 'ti ti-apps',
        ]];
        // Sans la lecture de tous les changements, les mises en production ne sont pas comptées.
        $releasesBadge = !self::canViewReleases() ? null : $badge(
            $i['releases_without_validation'] === 0,
            sprintf(
                __('%1$d release(s) without security validation out of %2$d over 12 months', 'grcmanager'),
                $i['releases_without_validation'],
                $i['releases']
            ),
            'ti ti-rocket'
        );
        switch ($code) {
            case 'A.8.25':
                $badges[] = $badge($i['without_framework'] === 0, sprintf(
                    __('%d without secure development framework', 'grcmanager'),
                    $i['without_framework']
                ), 'ti ti-book');
                $badges[] = $releasesBadge;
                break;
            case 'A.8.26':
                $badges[] = $badge($i['without_requirements'] === 0, sprintf(
                    __('%d without documented security requirements', 'grcmanager'),
                    $i['without_requirements']
                ), 'ti ti-list-check');
                break;
            case 'A.8.27':
                $badges[] = $badge($i['without_architecture'] === 0, sprintf(
                    __('%d without secure architecture principles', 'grcmanager'),
                    $i['without_architecture']
                ), 'ti ti-building');
                break;
            case 'A.8.30':
                $badges[] = [
                    'label' => sprintf(
                        _n('%d outsourced application', '%d outsourced applications', $i['outsourced'], 'grcmanager'),
                        $i['outsourced']
                    ),
                    'color' => 'info',
                    'icon'  => 'ti ti-building-store',
                ];
                $badges[] = $badge($i['outsourced_without_clauses'] === 0, sprintf(
                    __('%d outsourced without supplier or security clauses', 'grcmanager'),
                    $i['outsourced_without_clauses']
                ), 'ti ti-file-alert');
                break;
            case 'A.8.28':
                $badges[] = $badge($i['without_framework'] === 0, sprintf(
                    __('%d without secure development framework', 'grcmanager'),
                    $i['without_framework']
                ), 'ti ti-book');
                $badges[] = $badge($i['without_review'] === 0, sprintf(
                    __('%d without mandatory code review', 'grcmanager'),
                    $i['without_review']
                ), 'ti ti-eye-check');
                break;
            case 'A.8.29':
                $badges[] = $badge(
                    $i['without_sast'] === 0,
                    sprintf(__('%d without SAST', 'grcmanager'), $i['without_sast']),
                    'ti ti-scan'
                );
                $badges[] = $badge(
                    $i['without_dast'] === 0,
                    sprintf(__('%d without DAST', 'grcmanager'), $i['without_dast']),
                    'ti ti-world-search'
                );
                $badges[] = $badge($i['without_dependency_scan'] === 0, sprintf(
                    __('%d without dependency analysis', 'grcmanager'),
                    $i['without_dependency_scan']
                ), 'ti ti-packages');
                $badges[] = $releasesBadge;
                break;
            default:
                $badges[] = $badge($i['without_separation'] === 0, sprintf(
                    __('%d without separation of environments', 'grcmanager'),
                    $i['without_separation']
                ), 'ti ti-arrows-split');
                $badges[] = $badge($i['separation_unjustified'] === 0, sprintf(
                    __('%d not separated without justification', 'grcmanager'),
                    $i['separation_unjustified']
                ), 'ti ti-message-exclamation');
                $badges[] = $badge($i['separation_incomplete'] === 0, sprintf(
                    __('%d with access, data or configuration not separated', 'grcmanager'),
                    $i['separation_incomplete']
                ), 'ti ti-arrows-split-2');
        }

        return ['badges' => array_values(array_filter($badges)), 'url' => self::getPageURL()];
    }
}
