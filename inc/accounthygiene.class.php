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

use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;

/**
 * Issue #184 : hygiène des comptes GLPI (A.5.16, A.5.18, A.8.2). Liste les comptes actifs qui
 * méritent une vérification : supprimés de l'annuaire (synchronisation LDAP native de GLPI,
 * `is_deleted_ldap`), date de fin dépassée, sans connexion depuis N jours ou jamais utilisés,
 * doublons (même adresse e-mail, ou mêmes prénom et nom).
 *
 * Rien n'est désactivé ni modifié : la personne qui consulte peut ouvrir un ticket de revue pour
 * les comptes cochés (règle commune du plugin). Ancre sans table, comme PluginGrcmanagerReferentials.
 *
 * Seuls les comptes ayant une habilitation dans les entités actives de la personne sont lus, et
 * l'écran exige, en plus du droit du plugin, le droit natif de lecture des utilisateurs.
 */
class PluginGrcmanagerAccountHygiene extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Hygiène des comptes', 'grcmanager');
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
            AccountHygieneRules::DIRECTORY_DELETED => __('Supprimé de l\'annuaire', 'grcmanager'),
            AccountHygieneRules::EXPIRED           => __('Date de fin dépassée', 'grcmanager'),
            AccountHygieneRules::INACTIVE          => sprintf(
                __('Sans connexion depuis plus de %d jours', 'grcmanager'),
                $days
            ),
            AccountHygieneRules::NEVER_USED        => sprintf(
                __('Jamais utilisé (créé il y a plus de %d jours)', 'grcmanager'),
                $days
            ),
            AccountHygieneRules::DUPLICATE_EMAIL   => __('Adresse e-mail en double', 'grcmanager'),
            AccountHygieneRules::DUPLICATE_NAME    => __('Prénom et nom en double', 'grcmanager'),
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

        $accounts = [];
        foreach ($users as $id => $user) {
            $checks = array_merge(AccountHygieneRules::checks($user, $now, $days), $duplicates[$id] ?? []);
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

        $rows = '';
        foreach ($accounts as $account) {
            $labels = array_map(
                static fn (string $check): string => self::getCheckLabel($check, $days),
                $account['checks']
            );
            $rows .= '<tr><td>' . htmlescape($account['fullname']) . '</td><td>' . htmlescape($account['name'])
                . '</td><td>' . htmlescape(implode(', ', $labels)) . '</td></tr>';
        }
        $content = '<p>' . htmlescape(__(
            'Comptes GLPI actifs à vérifier (écran « Hygiène des comptes »). Pour chacun : confirmer le besoin, '
                . 'ou désactiver le compte et retirer ses habilitations. Rien n\'a été modifié automatiquement.',
            'grcmanager'
        )) . '</p><table border="1" cellpadding="4"><tr><th>' . htmlescape(__('Utilisateur', 'grcmanager'))
            . '</th><th>' . htmlescape(__('Identifiant', 'grcmanager')) . '</th><th>'
            . htmlescape(__('Points à vérifier', 'grcmanager')) . '</th></tr>' . $rows . '</table>';

        return (int) (new Ticket())->add([
            'name'        => sprintf(
                _n(
                    'Hygiène des comptes : %d compte à vérifier',
                    'Hygiène des comptes : %d comptes à vérifier',
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
        $accounts = self::findAccounts($days);
        $canTicket = Session::haveRight(Ticket::$rightname, CREATE);
        $byCheck = [];
        foreach ($accounts as $account) {
            foreach ($account['checks'] as $check) {
                $byCheck[$check] = ($byCheck[$check] ?? 0) + 1;
            }
        }

        echo '<div class="card"><div class="card-body">';
        echo '<p class="text-muted"><i class="ti ti-info-circle me-1"></i>' . htmlescape(__(
            'Comptes actifs des entités que vous voyez, qui méritent une vérification. Rien n\'est désactivé '
                . 'automatiquement : cochez les comptes et ouvrez un ticket de revue, ou traitez-les dans une '
                . 'campagne de revue des accès.',
            'grcmanager'
        )) . '</p>';

        echo '<form method="get" class="d-flex align-items-center gap-2 mb-3">';
        echo '<label for="grc-hygiene-days" class="mb-0">' . htmlescape(__('Inactivité au-delà de', 'grcmanager'))
            . '</label>';
        echo '<select id="grc-hygiene-days" name="days" class="form-select form-select-sm" style="width: auto;"'
            . ' onchange="this.form.submit()">';
        foreach (AccountHygieneRules::INACTIVITY_DAYS as $option) {
            echo '<option value="' . $option . '"' . ($option === $days ? ' selected' : '') . '>'
                . htmlescape(sprintf(__('%d jours', 'grcmanager'), $option)) . '</option>';
        }
        echo '</select></form>';

        if ($accounts === []) {
            echo '<div class="alert alert-success mb-0"><i class="ti ti-circle-check me-1"></i>'
                . htmlescape(__('Aucun compte à vérifier.', 'grcmanager')) . '</div></div></div>';
            return;
        }

        echo '<div class="d-flex flex-wrap gap-2 mb-3">';
        foreach ($byCheck as $check => $count) {
            echo '<span class="badge ' . (self::isSerious($check) ? 'bg-red-lt' : 'bg-yellow-lt') . '">'
                . htmlescape(self::getCheckLabel($check, $days)) . ' : ' . $count . '</span>';
        }
        echo '</div>';

        echo '<form method="post" action="' . htmlescape(self::getSearchURL()) . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape(Session::getNewCSRFToken()) . '">';
        echo '<input type="hidden" name="days" value="' . $days . '">';
        echo '<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th></th><th>'
            . htmlescape(__('Utilisateur', 'grcmanager')) . '</th><th>' . htmlescape(__('Identifiant', 'grcmanager'))
            . '</th><th>' . htmlescape(__('Dernière connexion', 'grcmanager')) . '</th><th>'
            . htmlescape(__('Points à vérifier', 'grcmanager')) . '</th></tr></thead><tbody>';
        foreach ($accounts as $account) {
            echo '<tr><td><input type="checkbox" class="form-check-input" name="users_id[]" value="' . $account['id']
                . '" aria-label="' . htmlescape($account['fullname']) . '"></td>';
            echo '<td><a href="' . htmlescape(User::getFormURLWithID($account['id'])) . '">'
                . htmlescape($account['fullname']) . '</a></td>';
            echo '<td>' . htmlescape($account['name']) . '</td>';
            echo '<td>' . htmlescape($account['last_login'] !== null
                ? (string) Html::convDateTime($account['last_login'])
                : __('Jamais', 'grcmanager')) . '</td><td>';
            foreach ($account['checks'] as $check) {
                echo '<span class="badge ' . (self::isSerious($check) ? 'bg-red-lt' : 'bg-yellow-lt') . ' me-1">'
                    . htmlescape(self::getCheckLabel($check, $days)) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($canTicket) {
            echo '<button type="submit" name="create_ticket" value="1" class="btn btn-primary">'
                . '<i class="ti ti-ticket me-1"></i>'
                . htmlescape(__('Ouvrir un ticket de revue pour les comptes cochés', 'grcmanager'))
                . '</button>';
        }
        echo '</form></div></div>';
    }
}
