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
use Config;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\MfaCoverageTables;
use GlpiPlugin\Grcmanager\Services\Access\MfaCoverageRules as Rules;
use Group;
use Html;
use Session;
use User;

/**
 * Issue #852 : couverture de l'authentification multifacteur (MFA) par compte, pour les mesures
 * A.8.5 (authentification sécurisée) et A.5.17 (informations d'authentification).
 *
 * - Double authentification native de GLPI (application TOTP) lue sur chaque compte : le plugin
 *   regarde seulement si elle est activée, il ne lit jamais le secret.
 * - Import CSV générique des méthodes enregistrées chez le fournisseur d'identité (Entra ID, Okta,
 *   Google Workspace, solution sur site), rapproché des comptes GLPI (identifiant, e-mail, UPN).
 * - Taux global, des administrateurs (profils qui modifient la configuration ou les profils de GLPI,
 *   ou rôle d'administrateur déclaré par le fournisseur) et des comptes à accès distant (groupes
 *   choisis ou colonne de l'import) ; écarts : administrateur ou accès distant sans MFA, méthode
 *   faible seule (SMS, appel, e-mail), compte exclu sans justification.
 *
 * Mêmes comptes que l'hygiène des comptes : actifs, avec une habilitation dans les entités de la
 * session ; droit du plugin et lecture native des utilisateurs. Rien n'est modifié dans GLPI.
 */
class MfaCoverage extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const REMOTE_GROUPS_KEY = 'mfa_remote_groups';

    /** Comptes listés au plus sur la page (écarts d'abord). */
    public const MAX_ROWS = 300;

    /** @var list<array<string, mixed>>|null comptes calculés pendant la requête (session) */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('MFA coverage', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/mfacoverage.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Session::haveRight(User::$rightname, READ);
    }

    /**
     * Import, effacement d'un import et choix des groupes d'accès distant.
     */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Revue des accès » (Menu::sections()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function reset(): void
    {
        self::$memo = null;
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, string>
     */
    public static function providerLabels(): array
    {
        return [
            'entra'     => __('Microsoft Entra ID', 'grcmanager'),
            'okta'      => __('Okta', 'grcmanager'),
            'google'    => __('Google Workspace', 'grcmanager'),
            'onpremise' => __('On-premises MFA solution', 'grcmanager'),
            'other'     => __('Other identity provider', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function levelInfos(): array
    {
        return [
            Rules::LEVEL_STRONG => [
                'label' => __('MFA', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-shield-check',
            ],
            Rules::LEVEL_WEAK   => [
                'label' => __('Weak method only', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-message-2',
            ],
            Rules::LEVEL_NONE   => [
                'label' => __('No MFA', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-shield-x',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function gapInfos(): array
    {
        return [
            Rules::GAP_ADMIN_NO_MFA       => [
                'label' => __('Administrator without MFA', 'grcmanager'),
                'color' => 'danger',
            ],
            Rules::GAP_REMOTE_NO_MFA      => [
                'label' => __('Remote access without MFA', 'grcmanager'),
                'color' => 'danger',
            ],
            Rules::GAP_WEAK_ONLY          => [
                'label' => __('SMS, call or email only', 'grcmanager'),
                'color' => 'warning',
            ],
            Rules::GAP_EXCLUDED_NO_REASON => [
                'label' => __('Excluded without justification', 'grcmanager'),
                'color' => 'warning',
            ],
        ];
    }

    public static function importErrorMessage(string $code): string
    {
        return match ($code) {
            'missing_account' => __(
                'No account column found: the file needs a header with a column such as login, '
                    . 'userPrincipalName or email.',
                'grcmanager'
            ),
            'missing_methods' => __(
                'No method column found: the file needs a column such as methodsRegistered, methods or '
                    . 'isMfaRegistered.',
                'grcmanager'
            ),
            default           => __('The file could not be read.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Réglages.

    /**
     * Groupes GLPI dont les membres ont un accès distant (VPN, bureau à distance…).
     *
     * @return list<int>
     */
    public static function getRemoteGroups(): array
    {
        $values = Config::getConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::REMOTE_GROUPS_KEY]);
        $groups = json_decode((string) ($values[self::REMOTE_GROUPS_KEY] ?? ''), true);

        return is_array($groups) ? self::ids($groups) : [];
    }

    /**
     * @param array<mixed> $groups
     * @return list<int> groupes enregistrés (existants seulement)
     */
    public static function setRemoteGroups(array $groups): array
    {
        global $DB;

        $ids = self::ids($groups);
        $existing = [];
        if ($ids !== []) {
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => Group::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
                $existing[] = (int) $row['id'];
            }
            sort($existing);
        }
        Config::setConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::REMOTE_GROUPS_KEY => json_encode($existing)]);
        self::reset();

        return $existing;
    }

    /**
     * @param array<mixed> $values
     * @return list<int> identifiants positifs, sans doublon
     */
    private static function ids(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0
        )));
    }

    // ------------------------------------------------------------------ Calculs.

    /**
     * Profils « administrateur » : ceux qui modifient la configuration générale ou les profils de
     * GLPI (ils peuvent se donner tous les droits).
     *
     * @return list<int>
     */
    public static function privilegedProfiles(): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['profiles_id', 'rights'],
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => ['name' => ['config', 'profile']],
            ]) as $row
        ) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                $ids[(int) $row['profiles_id']] = (int) $row['profiles_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * Comptes actifs ayant une habilitation dans les entités données (celles de la session par
     * défaut), avec leur niveau de MFA et leurs écarts. Sans session (tâche planifiée), passer le
     * périmètre.
     *
     * @param list<int>|null $entities
     * @return list<array<string, mixed>>
     */
    public static function accounts(?array $entities = null): array
    {
        if ($entities === null) {
            if (!self::canView()) {
                return [];
            }

            return self::$memo ??= self::compute(getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true));
        }

        return $entities === [] ? [] : self::compute(['glpi_profiles_users.entities_id' => $entities]);
    }

    /**
     * @param array<mixed> $entityCriteria
     * @return list<array<string, mixed>>
     */
    private static function compute(array $entityCriteria): array
    {
        global $DB;

        $users = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'glpi_users.id', 'glpi_users.name', 'glpi_users.firstname', 'glpi_users.realname',
                    'glpi_users.2fa_unenforced',
                ],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_users',
                'INNER JOIN' => [
                    'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
                ],
                'WHERE'      => ['glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0] + $entityCriteria,
                'ORDER'      => ['glpi_users.realname', 'glpi_users.firstname', 'glpi_users.name'],
            ]) as $row
        ) {
            $users[(int) $row['id']] = $row;
        }
        if ($users === []) {
            return [];
        }
        $ids = array_keys($users);

        // Administrateurs : profil privilégié dans le périmètre.
        $admins = [];
        $privileged = self::privilegedProfiles();
        if ($privileged !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['glpi_profiles_users.users_id'],
                    'FROM'   => 'glpi_profiles_users',
                    'WHERE'  => [
                        'glpi_profiles_users.users_id'    => $ids,
                        'glpi_profiles_users.profiles_id' => $privileged,
                    ] + $entityCriteria,
                ]) as $row
            ) {
                $admins[(int) $row['users_id']] = true;
            }
        }

        // Double authentification native : activée ou non, sans jamais lire le secret.
        $totp = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $ids, 'NOT' => ['2fa' => null], ['2fa' => ['<>', '']]],
            ]) as $row
        ) {
            $totp[(int) $row['id']] = true;
        }

        // Accès distant : membres des groupes choisis.
        $remote = [];
        $groups = self::getRemoteGroups();
        if ($groups !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['users_id'],
                    'FROM'   => 'glpi_groups_users',
                    'WHERE'  => ['users_id' => $ids, 'groups_id' => $groups],
                ]) as $row
            ) {
                $remote[(int) $row['users_id']] = true;
            }
        }

        // Méthodes importées, rapprochées des comptes.
        $imported = [];
        foreach ($DB->request(['FROM' => MfaCoverageTables::ACCOUNTS, 'WHERE' => ['users_id' => $ids]]) as $row) {
            $imported[(int) $row['users_id']][] = $row;
        }

        $providers = self::providerLabels();
        $accounts = [];
        foreach ($users as $id => $user) {
            $methods = [];
            $sources = [];
            $registered = false;
            $isAdmin = isset($admins[$id]);
            $isRemote = isset($remote[$id]);
            $excluded = false;
            $reasons = [];
            if (isset($totp[$id])) {
                $methods[] = Rules::GLPI_TOTP;
                $sources[] = __('GLPI two-factor authentication', 'grcmanager');
            }
            foreach ($imported[$id] ?? [] as $row) {
                $sources[] = $providers[(string) $row['provider']] ?? (string) $row['provider'];
                foreach (array_filter(explode(',', (string) $row['methods'])) as $method) {
                    $methods[] = $method;
                }
                $registered = $registered || (bool) $row['is_mfa_registered'];
                $isAdmin = $isAdmin || (bool) $row['is_admin'];
                $isRemote = $isRemote || (bool) $row['is_remote'];
                if ((bool) $row['is_excluded']) {
                    $excluded = true;
                    if (trim((string) $row['exclusion_reason']) !== '') {
                        $reasons[] = trim((string) $row['exclusion_reason']);
                    }
                }
            }
            $level = Rules::level(array_values(array_unique($methods)), $registered);
            $account = [
                'level'       => $level,
                'is_admin'    => $isAdmin,
                'is_remote'   => $isRemote,
                'is_excluded' => $excluded,
                'reason'      => implode(' ; ', array_unique($reasons)),
            ];
            $accounts[] = $account + [
                'id'             => $id,
                'name'           => (string) $user['name'],
                'fullname'       => formatUserName(
                    $id,
                    (string) $user['name'],
                    (string) $user['realname'],
                    (string) $user['firstname']
                ),
                'methods'        => array_values(array_unique(array_map(
                    static fn (string $method): string => $method === Rules::GLPI_TOTP
                        ? __('GLPI authenticator app', 'grcmanager')
                        : $method,
                    $methods
                ))),
                'weak_methods'   => array_values(array_filter(
                    array_unique($methods),
                    static fn (string $method): bool => Rules::methodClass($method) === Rules::METHOD_WEAK
                )),
                'sources'        => array_values(array_unique($sources)),
                'glpi_totp'      => isset($totp[$id]),
                'unenforced'     => (bool) ($user['2fa_unenforced'] ?? false),
                'gaps'           => Rules::gaps($account),
            ];
        }

        return $accounts;
    }

    /**
     * @param list<array<string, mixed>>|null $accounts
     * @return array{all: array<string, mixed>, admins: array<string, mixed>, remote: array<string, mixed>,
     *     gaps: array<string, int>, gap_accounts: int, admins_without_mfa: int}
     */
    public static function summary(?array $accounts = null): array
    {
        $accounts ??= self::accounts();
        $gaps = array_fill_keys(array_keys(self::gapInfos()), 0);
        $gapAccounts = 0;
        foreach ($accounts as $account) {
            foreach ($account['gaps'] as $gap) {
                $gaps[$gap] = ($gaps[$gap] ?? 0) + 1;
            }
            $gapAccounts += $account['gaps'] !== [] ? 1 : 0;
        }

        return [
            'all'                => Rules::summary($accounts),
            'admins'             => Rules::summary(array_values(array_filter(
                $accounts,
                static fn (array $account): bool => (bool) $account['is_admin']
            ))),
            'remote'             => Rules::summary(array_values(array_filter(
                $accounts,
                static fn (array $account): bool => (bool) $account['is_remote']
            ))),
            'gaps'               => $gaps,
            'gap_accounts'       => $gapAccounts,
            'admins_without_mfa' => $gaps[Rules::GAP_ADMIN_NO_MFA],
        ];
    }

    /**
     * KRI « administrateurs sans MFA » sur un périmètre d'entités (sans session).
     *
     * @param list<int> $perimeter
     */
    public static function adminsWithoutMfa(array $perimeter): float
    {
        return (float) self::summary(self::accounts($perimeter))['admins_without_mfa'];
    }

    /**
     * Comptes importés qu'aucun compte GLPI ne reconnaît, dans les entités de la session.
     *
     * @return list<array<string, mixed>>
     */
    public static function unmatched(): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $providers = self::providerLabels();
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => MfaCoverageTables::ACCOUNTS,
                'WHERE' => ['users_id' => 0] + getEntitiesRestrictCriteria(MfaCoverageTables::ACCOUNTS),
                'ORDER' => ['provider', 'account'],
                'LIMIT' => self::MAX_ROWS,
            ]) as $row
        ) {
            $methods = array_values(array_filter(explode(',', (string) $row['methods'])));
            $rows[] = [
                'provider' => $providers[(string) $row['provider']] ?? (string) $row['provider'],
                'account'  => (string) $row['account'],
                'email'    => (string) $row['email'],
                'methods'  => $methods,
                'level'    => Rules::level($methods, (bool) $row['is_mfa_registered']),
                'is_admin' => (bool) $row['is_admin'],
            ];
        }

        return $rows;
    }

    /**
     * Derniers imports par fournisseur, dans les entités de la session.
     *
     * @return list<array{provider: string, label: string, count: int, matched: int, date: string}>
     */
    public static function imports(): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $providers = self::providerLabels();
        $imports = [];
        foreach (
            $DB->request([
                'FROM'  => MfaCoverageTables::ACCOUNTS,
                'WHERE' => getEntitiesRestrictCriteria(MfaCoverageTables::ACCOUNTS),
            ]) as $row
        ) {
            $provider = (string) $row['provider'];
            $imports[$provider] ??= [
                'provider' => $provider,
                'label'    => $providers[$provider] ?? $provider,
                'count'    => 0,
                'matched'  => 0,
                'date'     => '',
            ];
            $imports[$provider]['count']++;
            $imports[$provider]['matched'] += (int) $row['users_id'] > 0 ? 1 : 0;
            $imports[$provider]['date'] = max($imports[$provider]['date'], (string) $row['date_import']);
        }
        foreach ($imports as &$import) {
            $import['date'] = $import['date'] !== '' ? (string) Html::convDateTime($import['date']) : '';
        }
        unset($import);

        return array_values($imports);
    }

    /**
     * Comptes GLPI visibles, pour le rapprochement : identifiant et adresses en minuscules.
     *
     * @return array{logins: array<string, int>, emails: array<string, int>}
     */
    private static function lookup(): array
    {
        global $DB;

        $logins = [];
        foreach (
            $DB->request([
                'SELECT'     => ['glpi_users.id', 'glpi_users.name'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_users',
                'INNER JOIN' => [
                    'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
                ],
                'WHERE'      => ['glpi_users.is_deleted' => 0]
                    + getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true),
            ]) as $row
        ) {
            $logins[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }
        $emails = [];
        if ($logins !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['users_id', 'email'],
                    'FROM'   => 'glpi_useremails',
                    'WHERE'  => ['users_id' => array_values($logins)],
                ]) as $row
            ) {
                $emails[mb_strtolower((string) $row['email'])] = (int) $row['users_id'];
            }
        }

        return ['logins' => $logins, 'emails' => $emails];
    }

    /**
     * Importe un export CSV d'un fournisseur : remplace les lignes de ce fournisseur dans l'entité
     * active, rapproche chaque ligne d'un compte GLPI visible. Rien n'est modifié dans GLPI.
     *
     * @return array{imported: int, matched: int, unmatched: int, errors: list<string>, truncated: bool}
     */
    public static function import(string $provider, string $raw): array
    {
        global $DB;

        $result = ['imported' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => [], 'truncated' => false];
        if (!self::canManage()) {
            $result['errors'][] = 'rights';

            return $result;
        }
        $provider = Rules::normalizeProvider($provider);
        $parsed = Rules::parseCsv($raw);
        if ($parsed['errors'] !== []) {
            $result['errors'] = $parsed['errors'];

            return $result;
        }
        $entity = (int) Session::getActiveEntity();
        $lookup = self::lookup();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $DB->delete(MfaCoverageTables::ACCOUNTS, ['provider' => $provider, 'entities_id' => $entity]);
        foreach ($parsed['rows'] as $row) {
            $usersId = Rules::match($row['account'], $row['email'], $lookup['logins'], $lookup['emails']);
            $DB->insert(MfaCoverageTables::ACCOUNTS, [
                'entities_id'       => $entity,
                'provider'          => $provider,
                'account'           => $row['account'],
                'email'             => $row['email'],
                'users_id'          => $usersId,
                'methods'           => mb_substr(implode(',', $row['methods']), 0, 60000),
                'is_mfa_registered' => $row['mfa'] === true ? 1 : 0,
                'is_admin'          => $row['admin'] === true ? 1 : 0,
                'is_remote'         => $row['remote'] === true ? 1 : 0,
                'is_excluded'       => $row['excluded'] ? 1 : 0,
                'exclusion_reason'  => $row['reason'],
                'users_id_import'   => (int) Session::getLoginUserID(),
                'date_import'       => $now,
            ]);
            $result['imported']++;
            $result[$usersId > 0 ? 'matched' : 'unmatched']++;
        }
        $result['truncated'] = $parsed['truncated'];
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'provider' => $provider,
            'imported' => $result['imported'],
            'matched'  => $result['matched'],
        ]);
        self::reset();

        return $result;
    }

    /**
     * Efface l'import d'un fournisseur dans l'entité active.
     */
    public static function clear(string $provider): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $provider = Rules::normalizeProvider($provider);
        $ok = $DB->delete(MfaCoverageTables::ACCOUNTS, [
            'provider'    => $provider,
            'entities_id' => (int) Session::getActiveEntity(),
        ]);
        AuditLog::record('purge', self::class, 0, 0, 'success', ['provider' => $provider]);
        self::reset();

        return (bool) $ok;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        global $CFG_GLPI;

        $accounts = self::accounts();
        $listed = $accounts;
        $rank = static fn (array $row): int => $row['gaps'] !== []
            ? 0
            : ($row['level'] === Rules::LEVEL_NONE ? 1 : 2);
        usort(
            $listed,
            static fn (array $a, array $b): int => [$rank($a), !$a['is_admin'], $a['fullname']]
                <=> [$rank($b), !$b['is_admin'], $b['fullname']]
        );
        $shown = array_slice($listed, 0, self::MAX_ROWS);
        foreach ($shown as &$row) {
            $row['url'] = User::getFormURLWithID((int) $row['id']);
        }
        unset($row);
        $remoteGroups = self::getRemoteGroups();

        return [
            'summary'        => self::summary($accounts),
            'levels'         => self::levelInfos(),
            'gap_infos'      => self::gapInfos(),
            'rows'           => $shown,
            'total_rows'     => count($listed),
            'unmatched'      => self::unmatched(),
            'imports'        => self::imports(),
            'providers'      => self::providerLabels(),
            'remote_groups'  => $remoteGroups,
            'remote_names'   => array_map(
                static fn (int $id): string => (string) Dropdown::getDropdownName(Group::getTable(), $id),
                $remoteGroups
            ),
            'glpi_enforced'  => (int) ($CFG_GLPI['2fa_enforced'] ?? 0) === 1,
            'can_manage'     => self::canManage(),
            'action'         => self::getSearchURL(),
            'hygiene_url'    => AccountHygiene::getSearchURL(),
            'review_url'     => AccessReview::getSearchURL(),
            'personnel_url'  => PersonnelCompliance::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/mfa_coverage.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Preuves, tableau de bord.

    /**
     * Preuve des mesures A.8.5 et A.5.17 : taux, administrateurs, accès distants, écarts.
     *
     * @return array{rate: ?int, admin_rate: ?int, remote_rate: ?int, accounts: int, admins_without_mfa: int,
     *     gaps: int, date: string, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.5', 'A.5.17'], true) || !self::canView()) {
            return null;
        }
        $summary = self::summary();

        return [
            'rate'               => $summary['all']['rate'],
            'admin_rate'         => $summary['admins']['rate'],
            'remote_rate'        => $summary['remote']['rate'],
            'accounts'           => (int) $summary['all']['total'],
            'admins_without_mfa' => $summary['admins_without_mfa'],
            'gaps'               => $summary['gap_accounts'],
            'date'               => (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'url'                => self::getSearchURL(),
        ];
    }

    /**
     * Carte du tableau de bord : administrateurs protégés par MFA (%).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function dashboardCard(array $params = []): array
    {
        $rate = self::canView() ? self::summary()['admins']['rate'] : null;

        return [
            'number' => $rate ?? 0,
            'url'    => self::getSearchURL(),
            'label'  => $params['label'] ?? __('Administrators protected by MFA (%)', 'grcmanager'),
            'icon'   => self::getIcon(),
        ];
    }

    /**
     * Préparation à la certification : constat, null sans les droits ou sans compte.
     */
    public static function readinessFact(): ?string
    {
        if (!self::canView()) {
            return null;
        }
        $summary = self::summary();
        if ($summary['all']['rate'] === null) {
            return null;
        }

        return sprintf(
            __('MFA: %1$d%% of the accounts, %2$d administrator(s) without MFA', 'grcmanager'),
            $summary['all']['rate'],
            $summary['admins_without_mfa']
        );
    }
}
