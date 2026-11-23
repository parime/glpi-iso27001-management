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

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Config\Block\BitwardenConnectorBlock;
use GlpiPlugin\Grcmanager\Config\Block\PrivilegedVaultPolicyBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Install\PrivilegedVaultTables;
use GlpiPlugin\Grcmanager\Install\ServerManagementTables;
use GlpiPlugin\Grcmanager\Services\Access\BitwardenPublicClient;
use GlpiPlugin\Grcmanager\Services\Access\MfaCoverageRules;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultConfig;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultRules as Rules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules;
use Html;
use Session;
use User;

/**
 * Issue #859 : comptes à privilèges et coffre-fort de mots de passe (A.5.17 informations
 * d'authentification, A.8.2 droits d'accès privilégiés).
 *
 * - Métadonnées seulement, jamais un secret : éléments du coffre (comptes partagés, mots de passe
 *   des équipements) et membres du coffre, importés en CSV (Bitwarden, Vaultwarden par
 *   l'interface « bw », solution PAM) ou, pour les membres, lus sur clic par l'API publique
 *   d'organisation de Bitwarden (BitwardenPublicClient).
 * - Écarts signalés : secret non renouvelé depuis le délai choisi (ou de date inconnue), accès d'une
 *   personne partie (compte GLPI désactivé ou supprimé, fiche de départ passée), membre inconnu de
 *   GLPI, administrateur du coffre sans double authentification, compte d'administration d'un
 *   contrôleur de gestion de serveur (#843) sans élément dans le coffre.
 * - Repris dans la revue des accès (onglet de la campagne) et en preuve des mesures A.5.17 et A.8.2.
 *
 * Lecture seule : rien n'est modifié dans le coffre ni dans GLPI. Droit du plugin et lecture native
 * des utilisateurs ; imports dans les entités de la session seulement.
 */
class PrivilegedVault extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Lignes listées au plus par tableau (écarts d'abord). */
    public const MAX_ROWS = 300;

    public static function getTypeName($nb = 0)
    {
        return __('Privileged accounts', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-lock-access';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/privilegedvault.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Session::haveRight(User::$rightname, READ);
    }

    /** Import, lecture de Bitwarden et effacement d'un import. */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Accès et comptes » (PrivilegedVaultFeature::menuPages()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            'bitwarden' => __('Bitwarden or Vaultwarden', 'grcmanager'),
            'pam'       => __('Privileged access management (PAM) solution', 'grcmanager'),
            'other'     => __('Other password vault', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            Rules::KIND_ENTRIES => __('Vault items (shared accounts)', 'grcmanager'),
            Rules::KIND_MEMBERS => __('Vault members (who has access)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function roleInfos(): array
    {
        return [
            Rules::ROLE_OWNER  => ['label' => __('Owner', 'grcmanager'), 'color' => 'purple'],
            Rules::ROLE_ADMIN  => ['label' => __('Administrator', 'grcmanager'), 'color' => 'purple'],
            Rules::ROLE_CUSTOM => ['label' => __('Custom role', 'grcmanager'), 'color' => 'azure'],
            Rules::ROLE_USER   => ['label' => __('User', 'grcmanager'), 'color' => 'secondary'],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_ACTIVE  => ['label' => __('Active', 'grcmanager'), 'color' => 'success'],
            Rules::STATUS_INVITED => ['label' => __('Invited', 'grcmanager'), 'color' => 'azure'],
            Rules::STATUS_REVOKED => ['label' => __('Revoked', 'grcmanager'), 'color' => 'secondary'],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function gapInfos(): array
    {
        return [
            Rules::GAP_STALE           => [
                'label' => __('Not changed within the expected delay', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-clock-exclamation',
            ],
            Rules::GAP_NO_DATE         => [
                'label' => __('Date of the last change unknown', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-calendar-question',
            ],
            Rules::GAP_DEPARTED_HOLDER => [
                'label' => __('Still reachable by a person who left', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-user-exclamation',
            ],
            Rules::GAP_DEPARTED        => [
                'label' => __('Person who left', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-user-x',
            ],
            Rules::GAP_UNKNOWN         => [
                'label' => __('Unknown in GLPI', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-user-question',
            ],
            Rules::GAP_ADMIN_NO_2FA    => [
                'label' => __('Vault administrator without two-step login', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-shield-x',
            ],
        ];
    }

    public static function importErrorMessage(string $code, string $column = ''): string
    {
        return match ($code) {
            'secret_column' => sprintf(
                __(
                    'The file was refused because the column "%s" may contain secrets. Remove the passwords, '
                        . 'codes, notes and custom fields from the export, then import it again. Nothing was imported.',
                    'grcmanager'
                ),
                $column
            ),
            'missing_name'  => __(
                'No item name column found: the file needs a header with a column such as name.',
                'grcmanager'
            ),
            'missing_email' => __(
                'No email column found: the file of the members needs a column such as email.',
                'grcmanager'
            ),
            default         => __('The file could not be read.', 'grcmanager'),
        };
    }

    /**
     * Message d'une erreur de l'API de Bitwarden (jamais le secret ni le jeton).
     */
    public static function apiErrorMessage(string $code): string
    {
        return match ($code) {
            BitwardenPublicClient::ERROR_CONFIG       => __(
                'Enter the server, the client ID (organization.…) and the client secret of the organization API '
                    . 'key first.',
                'grcmanager'
            ),
            BitwardenPublicClient::ERROR_UNAUTHORIZED => __(
                'Bitwarden refused the client ID or the client secret of the organization API key.',
                'grcmanager'
            ),
            BitwardenPublicClient::ERROR_FORBIDDEN    => __(
                'Access refused by Bitwarden: the organization API is available on the Teams and Enterprise plans.',
                'grcmanager'
            ),
            BitwardenPublicClient::ERROR_NOT_FOUND    => __(
                'The Bitwarden API was not found at this address: check the server address (Vaultwarden does '
                    . 'not provide this API, import a CSV file instead).',
                'grcmanager'
            ),
            BitwardenPublicClient::ERROR_RATE_LIMITED => __(
                'Bitwarden asks to slow down: try again in a few minutes.',
                'grcmanager'
            ),
            BitwardenPublicClient::ERROR_INTERNAL     => __(
                'The server is on an internal address: an administrator must allow internal addresses in the '
                    . 'plugin configuration (Outgoing connections).',
                'grcmanager'
            ),
            BitwardenPublicClient::ERROR_UNREACHABLE  => __(
                'Bitwarden could not be reached: check the network access or the GLPI proxy.',
                'grcmanager'
            ),
            default                                   => __('Bitwarden returned an unexpected answer.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Calculs.

    /**
     * Critère d'entité : celles de la session par défaut, ou le périmètre donné (sans session).
     *
     * @param list<int>|null $entities
     * @return array<mixed>|null null : aucun accès
     */
    private static function entityCriteria(string $table, ?array $entities): ?array
    {
        if ($entities === null) {
            return self::canView() ? getEntitiesRestrictCriteria($table) : null;
        }

        return $entities === [] ? null : [$table . '.entities_id' => $entities];
    }

    /**
     * État GLPI des adresses données : compte rapproché (identifiant, adresse, partie avant le @) et
     * parti (désactivé, supprimé, ou fiche de départ d'assetsign passée).
     *
     * @param list<string> $emails en minuscules
     * @return array<string, array{matched: bool, departed: bool, users_id: int}>
     */
    public static function glpiStates(array $emails, ?string $today = null): array
    {
        global $DB;

        $emails = array_values(array_unique(array_filter($emails, static fn (string $e): bool => $e !== '')));
        if ($emails === []) {
            return [];
        }
        $byEmail = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id', 'email'],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => ['email' => $emails],
            ]) as $row
        ) {
            $byEmail[mb_strtolower((string) $row['email'])] = (int) $row['users_id'];
        }
        $logins = $emails;
        foreach ($emails as $email) {
            $local = strstr($email, '@', true);
            if (is_string($local) && $local !== '') {
                $logins[] = $local;
            }
        }
        $byLogin = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['name' => array_values(array_unique($logins))],
            ]) as $row
        ) {
            $byLogin[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }
        $matched = [];
        foreach ($emails as $email) {
            $matched[$email] = MfaCoverageRules::match($email, $email, $byLogin, $byEmail);
        }
        $ids = array_values(array_unique(array_filter($matched)));
        $users = [];
        if ($ids !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'is_active', 'is_deleted'],
                    'FROM'   => 'glpi_users',
                    'WHERE'  => ['id' => $ids],
                ]) as $row
            ) {
                $users[(int) $row['id']] = (int) $row['is_active'] === 0 || (int) $row['is_deleted'] === 1;
            }
            $today ??= substr(Session::getCurrentTime() ?? date('Y-m-d H:i:s'), 0, 10);
            foreach (AccessReviewSuggestion::departures($ids) as $usersId => $date) {
                if (substr($date, 0, 10) < $today) {
                    $users[$usersId] = true;
                }
            }
        }
        $states = [];
        foreach ($matched as $email => $usersId) {
            $states[$email] = [
                'matched'  => $usersId > 0 && isset($users[$usersId]),
                'departed' => (bool) ($users[$usersId] ?? false),
                'users_id' => $usersId,
            ];
        }

        return $states;
    }

    /**
     * Membres du coffre visibles, avec leur état GLPI et leurs écarts.
     *
     * @param list<int>|null $entities
     * @return list<array<string, mixed>>
     */
    public static function members(?array $entities = null): array
    {
        global $DB;

        $criteria = self::entityCriteria(PrivilegedVaultTables::MEMBERS, $entities);
        if ($criteria === null) {
            return [];
        }
        $rows = iterator_to_array($DB->request([
            'FROM'  => PrivilegedVaultTables::MEMBERS,
            'WHERE' => $criteria,
            'ORDER' => ['email'],
        ]), false);
        $states = self::glpiStates(array_map(static fn (array $row): string => (string) $row['email'], $rows));
        $sources = self::sourceLabels();
        $members = [];
        foreach ($rows as $row) {
            $email = (string) $row['email'];
            $state = $states[$email] ?? ['matched' => false, 'departed' => false, 'users_id' => 0];
            $twoFactor = (int) $row['two_factor'] === -1 ? null : (int) $row['two_factor'] === 1;
            $members[] = [
                'id'          => (int) $row['id'],
                'source'      => (string) $row['source'],
                'source_name' => $sources[(string) $row['source']] ?? (string) $row['source'],
                'email'       => $email,
                'name'        => (string) $row['name'],
                'role'        => (string) $row['role'],
                'status'      => (string) $row['status'],
                'collections' => Rules::splitList((string) $row['collections']),
                'two_factor'  => $twoFactor,
                'users_id'    => $state['matched'] ? $state['users_id'] : 0,
                'gaps'        => Rules::memberGaps(
                    (string) $row['role'],
                    (string) $row['status'],
                    $twoFactor,
                    $state
                ),
            ];
        }

        return $members;
    }

    /**
     * Éléments du coffre visibles, avec les personnes qui y ont accès et leurs écarts.
     *
     * @param list<int>|null $entities
     * @param list<array<string, mixed>>|null $members
     * @return list<array<string, mixed>>
     */
    public static function entries(?array $entities = null, ?array $members = null, ?string $now = null): array
    {
        global $DB;

        $criteria = self::entityCriteria(PrivilegedVaultTables::ENTRIES, $entities);
        if ($criteria === null) {
            return [];
        }
        $members ??= self::members($entities);
        $rows = iterator_to_array($DB->request([
            'FROM'  => PrivilegedVaultTables::ENTRIES,
            'WHERE' => $criteria,
            'ORDER' => ['name'],
        ]), false);
        $now ??= Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $months = PrivilegedVaultConfig::rotationMonths();
        $holdersById = [];
        $allHolders = [];
        foreach ($rows as $row) {
            $holders = Rules::holdersOf(
                Rules::splitList((string) $row['holders']),
                Rules::splitList((string) $row['collection']),
                $members
            );
            $holdersById[(int) $row['id']] = $holders;
            array_push($allHolders, ...$holders);
        }
        $departed = [];
        foreach (self::glpiStates($allHolders) as $email => $state) {
            if ($state['departed']) {
                $departed[$email] = true;
            }
        }
        foreach ($members as $member) {
            if (in_array(Rules::GAP_DEPARTED, $member['gaps'], true)) {
                $departed[(string) $member['email']] = true;
            }
        }
        $sources = self::sourceLabels();
        $entries = [];
        foreach ($rows as $row) {
            $holders = $holdersById[(int) $row['id']];
            $date = $row['date_changed'] !== null ? (string) $row['date_changed'] : null;
            $entries[] = [
                'id'           => (int) $row['id'],
                'source'       => (string) $row['source'],
                'source_name'  => $sources[(string) $row['source']] ?? (string) $row['source'],
                'name'         => (string) $row['name'],
                'collections'  => Rules::splitList((string) $row['collection']),
                'username'     => (string) $row['username'],
                'uri'          => (string) $row['uri'],
                'date_changed' => $date,
                'date_label'   => $date !== null ? (string) Html::convDate(substr($date, 0, 10)) : '',
                'holders'      => $holders,
                'departed'     => array_values(array_filter(
                    $holders,
                    static fn (string $holder): bool => isset($departed[$holder])
                )),
                'gaps'         => Rules::entryGaps($date, $months, $now, $holders, $departed),
            ];
        }

        return $entries;
    }

    /**
     * Comptes d'administration actifs des contrôleurs de gestion de serveur (#843) sans élément
     * dans le coffre, si la personne voit les contrôleurs.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<int>|null $entities
     * @return list<array{controller: string, username: string, role: string, url: string}>
     */
    public static function equipmentWithoutEntry(array $entries, ?array $entities = null): array
    {
        global $DB;

        if (!$DB->tableExists(ServerManagementTables::ACCOUNTS)) {
            return [];
        }
        if ($entities === null) {
            if (!ServerManagement::canView()) {
                return [];
            }
            $criteria = getEntitiesRestrictCriteria(ServerManagementTables::CONTROLLERS, '', '', true);
        } elseif ($entities === []) {
            return [];
        } else {
            $criteria = [ServerManagementTables::CONTROLLERS . '.entities_id' => $entities];
        }
        $shaped = array_map(static fn (array $entry): array => [
            'username' => (string) $entry['username'],
            'name'     => (string) $entry['name'],
            'uri'      => (string) $entry['uri'],
        ], $entries);
        $missing = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    ServerManagementTables::ACCOUNTS . '.username',
                    ServerManagementTables::ACCOUNTS . '.role',
                    ServerManagementTables::CONTROLLERS . '.name',
                    ServerManagementTables::CONTROLLERS . '.url',
                    ServerManagementTables::CONTROLLERS . '.ip_address',
                ],
                'FROM'       => ServerManagementTables::ACCOUNTS,
                'INNER JOIN' => [
                    ServerManagementTables::CONTROLLERS => ['ON' => [
                        ServerManagementTables::ACCOUNTS    => 'plugin_grcmanager_bmccontrollers_id',
                        ServerManagementTables::CONTROLLERS => 'id',
                    ]],
                ],
                'WHERE'      => [ServerManagementTables::ACCOUNTS . '.is_enabled' => 1] + $criteria,
                'ORDER'      => [
                    ServerManagementTables::CONTROLLERS . '.name',
                    ServerManagementTables::ACCOUNTS . '.username',
                ],
            ]) as $row
        ) {
            if (!BmcRules::isAdminRole((string) $row['role'])) {
                continue;
            }
            $host = parse_url((string) $row['url'], PHP_URL_HOST);
            $hosts = [(string) $row['name'], is_string($host) ? $host : '', (string) $row['ip_address']];
            if (Rules::hasEntry((string) $row['username'], $hosts, $shaped)) {
                continue;
            }
            $missing[] = [
                'controller' => (string) $row['name'],
                'username'   => (string) $row['username'],
                'role'       => (string) $row['role'],
                'url'        => ServerManagement::getSearchURL(),
            ];
        }

        return $missing;
    }

    /**
     * Chiffres de la page, de la preuve et du KRI.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<array<string, mixed>> $members
     * @param list<array<string, mixed>> $equipment
     * @return array<string, int>
     */
    public static function summary(array $entries, array $members, array $equipment): array
    {
        $count = static fn (array $rows, string $gap): int => count(array_filter(
            $rows,
            static fn (array $row): bool => in_array($gap, $row['gaps'], true)
        ));
        $active = array_filter($members, static fn (array $m): bool => $m['status'] !== Rules::STATUS_REVOKED);

        return [
            'entries'           => count($entries),
            'members'           => count($active),
            'admins'            => count(array_filter(
                $active,
                static fn (array $m): bool => Rules::isAdminRole((string) $m['role'])
            )),
            'stale'             => $count($entries, Rules::GAP_STALE),
            'no_date'           => $count($entries, Rules::GAP_NO_DATE),
            'departed_holder'   => $count($entries, Rules::GAP_DEPARTED_HOLDER),
            'departed'          => $count($members, Rules::GAP_DEPARTED),
            'unknown'           => $count($members, Rules::GAP_UNKNOWN),
            'admin_no_2fa'      => $count($members, Rules::GAP_ADMIN_NO_2FA),
            'equipment_missing' => count($equipment),
        ];
    }

    /**
     * KRI « secrets du coffre non renouvelés » sur un périmètre d'entités (sans session).
     *
     * @param list<int> $perimeter
     */
    public static function staleSecrets(array $perimeter): float
    {
        $entries = self::entries($perimeter, []);

        return (float) count(array_filter(
            $entries,
            static fn (array $row): bool => in_array(Rules::GAP_STALE, $row['gaps'], true)
        ));
    }

    // ------------------------------------------------------------------ Imports.

    /**
     * Importe un export CSV (éléments ou membres) : remplace les lignes de même source et de même
     * nature dans l'entité active. Un fichier qui peut contenir un secret est refusé en entier.
     *
     * @return array{imported: int, errors: list<string>, secret: string, truncated: bool}
     */
    public static function import(string $source, string $kind, string $raw): array
    {
        if (!self::canManage()) {
            return ['imported' => 0, 'errors' => ['rights'], 'secret' => '', 'truncated' => false];
        }
        $kind = Rules::normalizeKind($kind);
        $parsed = Rules::parseCsv($kind, $raw);
        if ($parsed['errors'] !== []) {
            return [
                'imported'  => 0,
                'errors'    => $parsed['errors'],
                'secret'    => $parsed['secret'],
                'truncated' => false,
            ];
        }
        $imported = self::store(Rules::normalizeSource($source), $kind, $parsed['rows'], 'csv');

        return ['imported' => $imported, 'errors' => [], 'secret' => '', 'truncated' => $parsed['truncated']];
    }

    /**
     * Lit les membres de l'organisation Bitwarden et les range comme un import de membres de source
     * Bitwarden (remplace le précédent dans l'entité active). Lecture complète seulement.
     *
     * @return array{imported: int, error: string, truncated: bool}
     */
    public static function fetchFromBitwarden(?BitwardenPublicClient $client = null): array
    {
        if (!self::canManage()) {
            return ['imported' => 0, 'error' => 'rights', 'truncated' => false];
        }
        $client ??= PrivilegedVaultConfig::client();
        $fetch = $client->fetchMembers();
        if ($fetch['error'] !== '' || !$fetch['complete']) {
            return [
                'imported'  => 0,
                'error'     => $fetch['error'] !== '' ? $fetch['error'] : BitwardenPublicClient::ERROR_INVALID,
                'truncated' => false,
            ];
        }

        return [
            'imported'  => self::store('bitwarden', Rules::KIND_MEMBERS, $fetch['members'], 'api'),
            'error'     => '',
            'truncated' => $fetch['truncated'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function store(string $source, string $kind, array $rows, string $origin): int
    {
        global $DB;

        $entity = (int) Session::getActiveEntity();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $table = $kind === Rules::KIND_MEMBERS ? PrivilegedVaultTables::MEMBERS : PrivilegedVaultTables::ENTRIES;
        $DB->delete($table, ['source' => $source, 'entities_id' => $entity]);
        $states = $kind === Rules::KIND_MEMBERS
            ? self::glpiStates(array_map(static fn (array $row): string => (string) $row['email'], $rows))
            : [];
        $count = 0;
        foreach ($rows as $row) {
            $common = [
                'entities_id'     => $entity,
                'source'          => $source,
                'external_id'     => (string) $row['external_id'],
                'users_id_import' => (int) Session::getLoginUserID(),
                'date_import'     => $now,
            ];
            if ($kind === Rules::KIND_MEMBERS) {
                $state = $states[(string) $row['email']] ?? ['matched' => false, 'users_id' => 0];
                $DB->insert($table, $common + [
                    'email'       => (string) $row['email'],
                    'name'        => (string) $row['name'],
                    'role'        => (string) $row['role'],
                    'status'      => (string) $row['status'],
                    'collections' => mb_substr(implode(',', (array) $row['collections']), 0, 60000),
                    'two_factor'  => $row['two_factor'] === null ? -1 : ($row['two_factor'] ? 1 : 0),
                    'users_id'    => $state['matched'] ? (int) $state['users_id'] : 0,
                ]);
            } else {
                $DB->insert($table, $common + [
                    'name'         => (string) $row['name'],
                    'collection'   => mb_substr(implode(',', (array) $row['collections']), 0, 255),
                    'username'     => (string) $row['username'],
                    'uri'          => (string) $row['uri'],
                    'date_changed' => $row['date_changed'],
                    'holders'      => mb_substr(implode(',', (array) $row['holders']), 0, 60000),
                ]);
            }
            $count++;
        }
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'source'   => $source,
            'kind'     => $kind,
            'origin'   => $origin,
            'imported' => $count,
        ]);

        return $count;
    }

    /**
     * Efface un import (source et nature) dans l'entité active.
     */
    public static function clear(string $source, string $kind): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $source = Rules::normalizeSource($source);
        $kind = Rules::normalizeKind($kind);
        $ok = $DB->delete(
            $kind === Rules::KIND_MEMBERS ? PrivilegedVaultTables::MEMBERS : PrivilegedVaultTables::ENTRIES,
            ['source' => $source, 'entities_id' => (int) Session::getActiveEntity()]
        );
        AuditLog::record('purge', self::class, 0, 0, 'success', ['source' => $source, 'kind' => $kind]);

        return (bool) $ok;
    }

    /**
     * Derniers imports par source et nature, dans les entités de la session.
     *
     * @return list<array{source: string, kind: string, label: string, kind_label: string, count: int, date: string}>
     */
    public static function imports(): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $sources = self::sourceLabels();
        $kinds = self::kindLabels();
        $imports = [];
        $tables = [
            Rules::KIND_ENTRIES => PrivilegedVaultTables::ENTRIES,
            Rules::KIND_MEMBERS => PrivilegedVaultTables::MEMBERS,
        ];
        foreach ($tables as $kind => $table) {
            foreach (
                $DB->request([
                    'SELECT'  => ['source', 'COUNT' => 'id AS total', 'MAX' => 'date_import AS last'],
                    'FROM'    => $table,
                    'WHERE'   => getEntitiesRestrictCriteria($table),
                    'GROUPBY' => ['source'],
                ]) as $row
            ) {
                $source = (string) $row['source'];
                $imports[] = [
                    'source'     => $source,
                    'kind'       => $kind,
                    'label'      => $sources[$source] ?? $source,
                    'kind_label' => $kinds[$kind],
                    'count'      => (int) $row['total'],
                    'date'       => $row['last'] !== null ? (string) Html::convDateTime((string) $row['last']) : '',
                ];
            }
        }

        return $imports;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function gapsFirst(array $rows, string $label): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['gaps'] === [], (string) $a[$label]]
                <=> [$b['gaps'] === [], (string) $b[$label]]
        );

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $members = self::members();
        $entries = self::entries(null, $members);
        $equipment = self::equipmentWithoutEntry($entries);
        $shownMembers = self::gapsFirst($members, 'email');
        foreach ($shownMembers as &$member) {
            $member['url'] = $member['users_id'] > 0 ? User::getFormURLWithID((int) $member['users_id']) : '';
        }
        unset($member);

        return [
            'summary'         => self::summary($entries, $members, $equipment),
            'entries'         => self::gapsFirst($entries, 'name'),
            'total_entries'   => count($entries),
            'members'         => $shownMembers,
            'total_members'   => count($members),
            'equipment'       => array_slice($equipment, 0, self::MAX_ROWS),
            'show_equipment'  => ServerManagement::canView(),
            'gap_infos'       => self::gapInfos(),
            'role_infos'      => self::roleInfos(),
            'status_infos'    => self::statusInfos(),
            'sources'         => self::sourceLabels(),
            'kinds'           => self::kindLabels(),
            'imports'         => self::imports(),
            'rotation_months' => PrivilegedVaultConfig::rotationMonths(),
            'bitwarden'       => ['configured' => PrivilegedVaultConfig::isConfigured()],
            'connector_url'   => ConfigTabs::anchorUrl(BitwardenConnectorBlock::ANCHOR),
            'policy_url'      => ConfigTabs::anchorUrl(PrivilegedVaultPolicyBlock::ANCHOR),
            'can_manage'      => self::canManage(),
            'action'          => self::getSearchURL(),
            'review_url'      => AccessReview::getSearchURL(),
            'mfa_url'         => MfaCoverage::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/privileged_vault.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Revue des accès.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof AccessReview || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return [1 => self::createTabEntry(self::getTypeName(2), 0, null, self::getIcon())];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof AccessReview || !self::canView()) {
            return false;
        }
        $entities = array_values(array_intersect(
            array_map('intval', $item->getEntities()),
            array_map('intval', $_SESSION['glpiactiveentities'] ?? [])
        ));
        $members = self::members($entities);
        $entries = self::entries($entities, $members);
        $equipment = ServerManagement::canView() ? self::equipmentWithoutEntry($entries, $entities) : [];
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/access_review_privileged_vault.html.twig', [
            'summary'         => self::summary($entries, $members, $equipment),
            'members'         => array_slice(array_values(array_filter(
                $members,
                static fn (array $m): bool => $m['gaps'] !== []
            )), 0, self::MAX_ROWS),
            'entries'         => array_slice(array_values(array_filter(
                $entries,
                static fn (array $e): bool => $e['gaps'] !== []
            )), 0, self::MAX_ROWS),
            'equipment'       => array_slice($equipment, 0, self::MAX_ROWS),
            'gap_infos'       => self::gapInfos(),
            'role_infos'      => self::roleInfos(),
            'rotation_months' => PrivilegedVaultConfig::rotationMonths(),
            'page_url'        => self::getSearchURL(),
        ]);

        return true;
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuve des mesures A.5.17 et A.8.2 : éléments, membres et écarts.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.5.17', 'A.8.2'], true) || !self::canView()) {
            return null;
        }
        $members = self::members();
        $entries = self::entries(null, $members);
        if ($entries === [] && $members === []) {
            return null;
        }
        $summary = self::summary($entries, $members, self::equipmentWithoutEntry($entries));

        return $summary + [
            'rotation_months' => PrivilegedVaultConfig::rotationMonths(),
            'date'            => (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'url'             => self::getSearchURL(),
        ];
    }
}
