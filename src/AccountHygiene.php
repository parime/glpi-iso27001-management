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
use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;
use Log;
use Session;
use Ticket;
use User;

/**
 * Issue #184 : hygiène des comptes GLPI (A.5.16, A.5.18, A.8.2). Liste les comptes actifs qui
 * méritent une vérification : supprimés de l'annuaire (synchronisation LDAP native de GLPI,
 * `is_deleted_ldap`), date de fin dépassée, sans connexion depuis N jours ou jamais utilisés,
 * doublons (même adresse e-mail, ou mêmes prénom et nom).
 *
 * Rien n'est désactivé ni modifié : la personne qui consulte peut ouvrir un ticket de revue pour
 * les comptes cochés (règle commune du plugin). Ancre sans table, comme Referentials.
 *
 * Seuls les comptes ayant une habilitation dans les entités actives de la personne sont lus, et
 * l'écran exige, en plus du droit du plugin, le droit natif de lecture des utilisateurs.
 */
class AccountHygiene extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Account hygiene', 'grcmanager');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-user-search';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/accounthygiene.php';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ) && Session::haveRight(User::$rightname, READ);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function getCheckLabel(string $check, int $days): string
    {
        return match ($check) {
            AccountHygieneRules::DIRECTORY_DELETED => __('Deleted from the directory', 'grcmanager'),
            AccountHygieneRules::EXPIRED           => __('End date passed', 'grcmanager'),
            AccountHygieneRules::INACTIVE          => sprintf(
                __('No login for more than %d days', 'grcmanager'),
                $days
            ),
            AccountHygieneRules::NEVER_USED        => sprintf(
                __('Never used (created more than %d days ago)', 'grcmanager'),
                $days
            ),
            AccountHygieneRules::DUPLICATE_EMAIL   => __('Duplicate email address', 'grcmanager'),
            AccountHygieneRules::DUPLICATE_NAME    => __('Duplicate first and last name', 'grcmanager'),
            AccountHygieneRules::GROUP_CHANGED     => sprintf(
                __('Groups changed less than %d days ago, profiles not reviewed', 'grcmanager'),
                $days
            ),
            default                                => $check,
        };
    }

    /**
     * Les contrôles qui appellent une action rapide (compte qui ne devrait plus être actif).
     */
    public static function isSerious(string $check): bool
    {
        return in_array($check, [AccountHygieneRules::DIRECTORY_DELETED, AccountHygieneRules::EXPIRED], true);
    }

    /**
     * Comptes actifs visibles (habilités dans les entités actives) qui lèvent au moins un contrôle.
     *
     * @return list<array{id: int, name: string, fullname: string, last_login: ?string, checks: list<string>}>
     */
    public static function findAccounts(int $days, ?string $now = null): array
    {
        global $DB;

        $now ??= $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $users = [];
        $iterator = $DB->request([
            'SELECT'     => [
                'glpi_users.id', 'glpi_users.name', 'glpi_users.firstname', 'glpi_users.realname',
                'glpi_users.is_deleted_ldap', 'glpi_users.end_date', 'glpi_users.last_login',
                'glpi_users.date_creation',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => [
                    'ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id'],
                ],
            ],
            'WHERE'      => [
                'glpi_users.is_active'  => 1,
                'glpi_users.is_deleted' => 0,
            ] + getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true),
            'ORDER'      => ['glpi_users.realname', 'glpi_users.firstname', 'glpi_users.name'],
        ]);
        foreach ($iterator as $row) {
            $users[(int) $row['id']] = $row;
        }
        if ($users === []) {
            return [];
        }

        $emails = [];
        foreach ($DB->request(['FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => array_keys($users)]]) as $row) {
            $emails[(int) $row['users_id']][] = (string) $row['email'];
        }
        $duplicates = AccountHygieneRules::duplicates(array_values(array_map(
            static fn (array $user): array => [
                'id'        => (int) $user['id'],
                'emails'    => $emails[(int) $user['id']] ?? [],
                'firstname' => $user['firstname'],
                'realname'  => $user['realname'],
            ],
            $users
        )));

        // Issue #184, phase 2 : derniers changements de groupe et de profil, d'après l'historique
        // de GLPI (ajout ou retrait d'un groupe ; ajout, modification ou retrait d'une habilitation).
        $lastChange = static function (string $linked, array $actions) use ($DB, $users): array {
            $dates = [];
            foreach (
                $DB->request([
                    'SELECT' => ['items_id', 'MAX' => 'date_mod AS last'],
                    'FROM'   => 'glpi_logs',
                    'WHERE'  => [
                        'itemtype'      => 'User',
                        'items_id'      => array_keys($users),
                        'itemtype_link' => $linked,
                        'linked_action' => $actions,
                    ],
                    'GROUPBY' => 'items_id',
                ]) as $row
            ) {
                $dates[(int) $row['items_id']] = (string) $row['last'];
            }

            return $dates;
        };
        $groupChanges = $lastChange('Group', [Log::HISTORY_ADD_RELATION, Log::HISTORY_DEL_RELATION]);
        $profileChanges = $lastChange(
            'Profile',
            [Log::HISTORY_ADD_SUBITEM, Log::HISTORY_UPDATE_SUBITEM, Log::HISTORY_DELETE_SUBITEM]
        );

        $accounts = [];
        foreach ($users as $id => $user) {
            $checks = array_merge(AccountHygieneRules::checks($user, $now, $days), $duplicates[$id] ?? []);
            if (
                AccountHygieneRules::groupChangedWithoutProfileReview(
                    $groupChanges[$id] ?? null,
                    $profileChanges[$id] ?? null,
                    $user['date_creation'] ?? null,
                    $now,
                    $days
                )
            ) {
                $checks[] = AccountHygieneRules::GROUP_CHANGED;
            }
            if ($checks === []) {
                continue;
            }
            $accounts[] = [
                'id'         => $id,
                'name'       => (string) $user['name'],
                'fullname'   => formatUserName(
                    $id,
                    (string) $user['name'],
                    (string) $user['realname'],
                    (string) $user['firstname']
                ),
                'last_login' => $user['last_login'] ?: null,
                'checks'     => $checks,
            ];
        }

        return $accounts;
    }

    /**
     * Ticket de revue pour les comptes cochés (parmi ceux que l'écran affiche à cette personne).
     *
     * @param list<int> $usersIds
     * @return int id du ticket, 0 si rien à faire ou refusé
     */
    public static function createReviewTicket(array $usersIds, int $days): int
    {
        if (!self::canView() || !Session::haveRight(Ticket::$rightname, CREATE)) {
            return 0;
        }
        $accounts = array_values(array_filter(
            self::findAccounts($days),
            static fn (array $account): bool => in_array($account['id'], $usersIds, true)
        ));
        if ($accounts === []) {
            return 0;
        }

        $content = TemplateRenderer::getInstance()->render('@grcmanager/account_hygiene_ticket.html.twig', [
            'accounts' => array_map(
                static fn (array $account): array => $account + ['labels' => array_map(
                    static fn (string $check): string => self::getCheckLabel($check, $days),
                    $account['checks']
                )],
                $accounts
            ),
        ]);

        return (int) (new Ticket())->add([
            'name'        => sprintf(
                _n(
                    'Account hygiene: %d account to check',
                    'Account hygiene: %d accounts to check',
                    count($accounts),
                    'grcmanager'
                ),
                count($accounts)
            ),
            'content'     => $content,
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => Session::getActiveEntity(),
        ]);
    }

    public static function showList(int $days): void
    {
        $badge = static fn (string $check): array => [
            'label'   => self::getCheckLabel($check, $days),
            'serious' => self::isSerious($check),
        ];
        $accounts = [];
        $byCheck = [];
        foreach (self::findAccounts($days) as $account) {
            foreach ($account['checks'] as $check) {
                $byCheck[$check] ??= $badge($check) + ['count' => 0];
                $byCheck[$check]['count']++;
            }
            $accounts[] = [
                'url'    => User::getFormURLWithID($account['id']),
                'checks' => array_map($badge, $account['checks']),
            ] + $account;
        }

        TemplateRenderer::getInstance()->display('@grcmanager/account_hygiene.html.twig', [
            'accounts'    => $accounts,
            'by_check'    => array_values($byCheck),
            'days'        => $days,
            'day_options' => AccountHygieneRules::INACTIVITY_DAYS,
            'action'      => self::getSearchURL(),
            'can_ticket'  => Session::haveRight(Ticket::$rightname, CREATE),
        ]);
    }
}
