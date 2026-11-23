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
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\ShareAccessTables;
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use GlpiPlugin\Grcmanager\Services\Access\EntraGraphClient;
use GlpiPlugin\Grcmanager\Services\Access\EntraGraphConfig;
use GlpiPlugin\Grcmanager\Services\Access\M365ShareReader;
use GlpiPlugin\Grcmanager\Services\Access\M365ShareRules;
use GlpiPlugin\Grcmanager\Services\Access\ShareAccessRules as Rules;
use GlpiPlugin\Grcmanager\Services\Classification\ClassificationLevels;
use Group;
use Html;
use Session;
use User;

/**
 * Issue #846 : droits d'accès aux partages de fichiers Windows (SMB et NTFS), pour les mesures
 * A.5.15 (contrôle d'accès), A.5.18 (droits d'accès) et A.8.3 (restriction d'accès à l'information).
 *
 * - Import du CSV produit par le script PowerShell fourni (lancé par l'administrateur avec un compte
 *   en lecture) ou par un outil tiers : partages, droits de partage, droits NTFS des dossiers,
 *   héritage, propriétaire. Le plugin ne se connecte à aucun serveur de fichiers et ne modifie aucun
 *   droit.
 * - Rapprochement des comptes avec les utilisateurs et groupes GLPI ; écarts signalés : comptes
 *   larges en écriture, droits nominatifs, comptes supprimés ou désactivés, héritage cassé sur un
 *   partage sensible, partage sans propriétaire ; pondérés par la classification C/I/D du partage.
 * - Suivi d'un import à l'autre (droits ajoutés, retirés), revue par partage : le propriétaire
 *   désigné décide pour chaque droit (garder, modifier, retirer), décision tracée.
 * - Lot 2 : sites SharePoint, OneDrive et équipes Teams, lus par Microsoft Graph en lecture seule
 *   sur clic (configuration et client de la couverture MFA, #852) ou importés en CSV ; écarts en
 *   plus : lien anonyme, invité externe ; mêmes tables (colonne source), même revue.
 *
 * Données réservées au droit des registres sensibles, dans les entités de la session.
 */
class ShareAccess extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const SOURCE_SMB = 'smb';

    /** Partages listés au plus sur la page, et droits au plus sur la fiche d'un partage. */
    public const MAX_ROWS = 500;

    /** Mesures dont la page est une preuve. */
    public const CONTROLS = ['A.5.15', 'A.5.18', 'A.8.3'];

    /** @var list<array<string, mixed>>|null partages calculés pendant la requête */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('File share access rights', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-folder-share';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/shareaccess.php';
    }

    public static function getShareURL(int $id): string
    {
        return self::getSearchURL() . '?share=' . $id;
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ)
            && (bool) Session::haveRight(Installer::SENSITIVE_RIGHT, READ);
    }

    /**
     * Import, effacement, classification, propriétaire et lancement des revues.
     */
    public static function canManage(): bool
    {
        return self::canView()
            && (bool) Session::haveRight(self::RIGHTNAME, UPDATE)
            && (bool) Session::haveRight(Installer::SENSITIVE_RIGHT, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Opérations » (Menu::sections()).
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
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function levelInfos(): array
    {
        return [
            Rules::LEVEL_FULL   => self::info(__('Full control', 'grcmanager'), 'danger', 'ti ti-key'),
            Rules::LEVEL_MODIFY => self::info(__('Modify', 'grcmanager'), 'warning', 'ti ti-pencil'),
            Rules::LEVEL_READ   => self::info(__('Read', 'grcmanager'), 'success', 'ti ti-eye'),
            Rules::LEVEL_OTHER  => [
                'label' => __('Special permissions', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-adjustments',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function kindInfos(): array
    {
        return [
            Rules::KIND_BROAD   => self::info(__('Everyone (broad group)', 'grcmanager'), 'danger', 'ti ti-world'),
            Rules::KIND_SYSTEM  => self::info(__('System account', 'grcmanager'), 'secondary', 'ti ti-settings'),
            Rules::KIND_ORPHAN  => self::info(__('Deleted account', 'grcmanager'), 'danger', 'ti ti-user-x'),
            Rules::KIND_USER    => self::info(__('User', 'grcmanager'), 'azure', 'ti ti-user'),
            Rules::KIND_GROUP   => self::info(__('Group', 'grcmanager'), 'purple', 'ti ti-users-group'),
            Rules::KIND_UNKNOWN => self::info(__('Account', 'grcmanager'), 'secondary', 'ti ti-user-question'),
            Rules::KIND_ANONYMOUS => self::info(__('Anyone with the link', 'grcmanager'), 'danger', 'ti ti-link'),
            Rules::KIND_EXTERNAL  => self::info(__('External guest', 'grcmanager'), 'orange', 'ti ti-user-share'),
        ];
    }

    /**
     * Sources des droits : partages Windows, SharePoint, OneDrive, Teams.
     *
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function sourceInfos(): array
    {
        return [
            self::SOURCE_SMB                  => self::info(
                __('Windows file share', 'grcmanager'),
                'secondary',
                'ti ti-server'
            ),
            M365ShareRules::SOURCE_SHAREPOINT => self::info('SharePoint', 'teal', 'ti ti-world-www'),
            M365ShareRules::SOURCE_ONEDRIVE   => self::info('OneDrive', 'azure', 'ti ti-cloud'),
            M365ShareRules::SOURCE_TEAMS      => self::info('Teams', 'indigo', 'ti ti-messages'),
        ];
    }

    /**
     * @return array{label: string, color: string, icon: string}
     */
    private static function info(string $label, string $color, string $icon): array
    {
        return ['label' => $label, 'color' => $color, 'icon' => $icon];
    }

    /**
     * @return array<string, array{label: string, color: string, help: string}>
     */
    public static function gapInfos(): array
    {
        return [
            Rules::GAP_BROAD_WRITE        => [
                'label' => __('Everyone can modify', 'grcmanager'),
                'color' => 'danger',
                'help'  => __(
                    'Everyone, Authenticated Users, Domain Users or Guests can modify or have full control.',
                    'grcmanager'
                ),
            ],
            Rules::GAP_DELETED_ACCOUNT    => [
                'label' => __('Deleted account still has rights', 'grcmanager'),
                'color' => 'danger',
                'help'  => __(
                    'The account no longer exists in the directory (unresolved SID) or is in the GLPI trash.',
                    'grcmanager'
                ),
            ],
            Rules::GAP_DISABLED_ACCOUNT   => [
                'label' => __('Disabled account still has rights', 'grcmanager'),
                'color' => 'danger',
                'help'  => __('The matching GLPI account is disabled: the person has probably left.', 'grcmanager'),
            ],
            Rules::GAP_DIRECT_USER        => [
                'label' => __('Right given to a person', 'grcmanager'),
                'color' => 'warning',
                'help'  => __(
                    'Rights should be given to groups, not to named people: they are easier to review.',
                    'grcmanager'
                ),
            ],
            Rules::GAP_BROKEN_INHERITANCE => [
                'label' => __('Inheritance broken', 'grcmanager'),
                'color' => 'warning',
                'help'  => __(
                    'A subfolder of a sensitive share does not inherit the rights of its parent folder.',
                    'grcmanager'
                ),
            ],
            Rules::GAP_ANONYMOUS_LINK     => [
                'label' => __('Public link (anyone)', 'grcmanager'),
                'color' => 'danger',
                'help'  => __('A sharing link works for anyone who has it, without signing in.', 'grcmanager'),
            ],
            Rules::GAP_EXTERNAL_GUEST     => [
                'label' => __('External guest has access', 'grcmanager'),
                'color' => 'warning',
                'help'  => __(
                    'A person from another organization (guest account) has access: check it is still needed.',
                    'grcmanager'
                ),
            ],
            Rules::GAP_NO_OWNER           => [
                'label' => __('No owner', 'grcmanager'),
                'color' => 'warning',
                'help'  => __(
                    'No GLPI user is designated as the owner who reviews the rights of this share.',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function scoreInfos(): array
    {
        return [
            'none'   => ['label' => __('No gap', 'grcmanager'), 'color' => 'success'],
            'low'    => ['label' => __('Low', 'grcmanager'), 'color' => 'info'],
            'medium' => ['label' => __('Medium', 'grcmanager'), 'color' => 'warning'],
            'high'   => ['label' => __('High', 'grcmanager'), 'color' => 'danger'],
        ];
    }

    public static function importErrorMessage(string $code): string
    {
        return match ($code) {
            'missing_identity' => __(
                'No account column found: the file needs a header with a column such as Identity, '
                    . 'IdentityReference or Sid.',
                'grcmanager'
            ),
            'missing_rights'   => __(
                'No rights column found: the file needs a column such as Rights, FileSystemRights or AccessRight.',
                'grcmanager'
            ),
            'missing_share'    => __(
                'No share column found: the file needs a column such as Share, or Folder with a '
                    . '\\\\server\\share path.',
                'grcmanager'
            ),
            'missing_site'     => __(
                'No site column found: the file needs a column such as Site, Team or OneDrive.',
                'grcmanager'
            ),
            default            => __('The file could not be read.', 'grcmanager'),
        };
    }

    /**
     * Message d'une erreur de lecture de Microsoft 365 par Graph (jamais le secret ni le jeton).
     */
    public static function graphErrorMessage(string $code): string
    {
        return match ($code) {
            EntraGraphClient::ERROR_FORBIDDEN => __(
                'Access refused by Microsoft Graph: grant the Sites.Read.All, Files.Read.All, User.Read.All '
                    . 'and GroupMember.Read.All application permissions with admin consent.',
                'grcmanager'
            ),
            EntraGraphClient::ERROR_TOO_LARGE => __(
                'Too many sites or accounts to read in one click: nothing was replaced. Import a CSV export '
                    . 'instead.',
                'grcmanager'
            ),
            default                           => MfaCoverage::graphErrorMessage($code),
        };
    }

    // ------------------------------------------------------------------ Calculs.

    /**
     * Partages visibles (entités de la session) avec leurs compteurs, écarts et score pondéré.
     * Sans session (preuve calculée hors page), passer le périmètre.
     *
     * @param list<int>|null $entities
     * @return list<array<string, mixed>>
     */
    public static function shares(?array $entities = null): array
    {
        if ($entities === null) {
            if (!self::canView()) {
                return [];
            }

            return self::$memo ??= self::compute(getEntitiesRestrictCriteria(ShareAccessTables::SHARES));
        }

        return $entities === [] ? [] : self::compute([ShareAccessTables::SHARES . '.entities_id' => $entities]);
    }

    /**
     * État des comptes GLPI rapprochés : actif, à la corbeille.
     *
     * @param list<int> $ids
     * @return array<int, array{is_active: bool, is_deleted: bool}>
     */
    private static function accountStates(array $ids): array
    {
        global $DB;

        $states = [];
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $request = ['SELECT' => ['id', 'is_active', 'is_deleted'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $ids]];
        foreach ($DB->request($request) as $row) {
            $states[(int) $row['id']] = [
                'is_active'  => (bool) $row['is_active'],
                'is_deleted' => (bool) $row['is_deleted'],
            ];
        }

        return $states;
    }

    /**
     * @param array<mixed> $criteria
     * @return list<array<string, mixed>>
     */
    private static function compute(array $criteria): array
    {
        global $DB;

        $shares = [];
        foreach (
            $DB->request([
                'FROM'  => ShareAccessTables::SHARES,
                'WHERE' => $criteria,
                'ORDER' => ['source', 'server', 'name'],
            ]) as $row
        ) {
            $shares[(int) $row['id']] = $row;
        }
        if ($shares === []) {
            return [];
        }
        $aces = [];
        $userIds = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'plugin_grcmanager_fileshares_id', 'scope', 'folder', 'depth', 'kind', 'level', 'is_deny',
                    'is_protected', 'users_id', 'status', 'decision',
                ],
                'FROM'   => ShareAccessTables::ACES,
                'WHERE'  => ['plugin_grcmanager_fileshares_id' => array_keys($shares)],
            ]) as $row
        ) {
            $aces[(int) $row['plugin_grcmanager_fileshares_id']][] = $row;
            $userIds[] = (int) $row['users_id'];
        }
        $states = self::accountStates($userIds);

        $out = [];
        foreach ($shares as $id => $share) {
            $classification = self::classification($share);
            $aceGaps = [];
            $folders = [];
            $counts = ['aces' => 0, 'removed' => 0, 'pending' => 0];
            foreach ($aces[$id] ?? [] as $ace) {
                if ($ace['status'] !== 'current') {
                    $counts['removed']++;
                    continue;
                }
                $counts['aces']++;
                $counts['pending'] += $ace['decision'] === AccessReviewLogic::DECISION_PENDING ? 1 : 0;
                $aceGaps[] = Rules::aceGaps($ace, $states[(int) $ace['users_id']] ?? null);
                $folder = mb_strtolower((string) $ace['folder']);
                $folders[$folder] = [
                    'depth'        => (int) $ace['depth'],
                    'is_protected' => ($folders[$folder]['is_protected'] ?? false) || (bool) $ace['is_protected'],
                ];
            }
            $gaps = $share['is_present'] ? Rules::shareGaps(
                $aceGaps,
                array_values($folders),
                $classification,
                (int) $share['users_id_owner'] > 0
            ) : [];
            $score = Rules::score($gaps, $classification);
            $out[] = [
                'id'              => $id,
                'entities_id'     => (int) $share['entities_id'],
                'source'          => (string) $share['source'],
                'server'          => (string) $share['server'],
                'name'            => (string) $share['name'],
                'label'           => $share['source'] === self::SOURCE_SMB
                    ? '\\\\' . $share['server'] . '\\' . $share['name']
                    : (string) $share['name'],
                'share_path'      => (string) $share['share_path'],
                'owner'           => (string) $share['owner'],
                'users_id_owner'  => (int) $share['users_id_owner'],
                'classification'  => $classification,
                'is_sensitive'    => Rules::isSensitive($classification),
                'is_present'      => (bool) $share['is_present'],
                'review_open'     => $share['review_status'] === AccessReviewLogic::STATUS_OPEN,
                'date_last_review' => (string) ($share['date_last_review'] ?? ''),
                'date_import'     => (string) ($share['date_import'] ?? ''),
                'date_creation'   => (string) ($share['date_creation'] ?? ''),
                'comment'         => (string) ($share['comment'] ?? ''),
                'aces'            => $counts['aces'],
                'removed'         => $counts['removed'],
                'pending'         => $counts['pending'],
                'gaps'            => $gaps,
                'score'           => $score,
                'score_level'     => Rules::scoreLevel($score),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $share
     * @return array{confidentiality: string, integrity: string, availability: string}
     */
    private static function classification(array $share): array
    {
        $out = [];
        foreach (ClassificationLevels::AXES as $axis) {
            $out[$axis] = ClassificationLevels::sanitize((string) ($share[$axis] ?? '')) ?? '';
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>>|null $shares
     * @return array{shares: int, aces: int, gap_shares: int, gaps: array<string, int>, high: int,
     *     reviewed: int, without_owner: int}
     */
    public static function summary(?array $shares = null): array
    {
        $shares ??= self::shares();
        $gaps = array_fill_keys(array_keys(self::gapInfos()), 0);
        $out = ['shares' => 0, 'aces' => 0, 'gap_shares' => 0, 'high' => 0, 'reviewed' => 0, 'without_owner' => 0];
        $yearAgo = date('Y-m-d H:i:s', (int) strtotime((Session::getCurrentTime() ?? 'now') . ' -1 year'));
        foreach ($shares as $share) {
            if (!$share['is_present']) {
                continue;
            }
            $out['shares']++;
            $out['aces'] += $share['aces'];
            foreach ($share['gaps'] as $gap => $count) {
                $gaps[$gap] = ($gaps[$gap] ?? 0) + $count;
            }
            $out['gap_shares'] += $share['gaps'] !== [] ? 1 : 0;
            $out['high'] += $share['score_level'] === 'high' ? 1 : 0;
            $out['reviewed'] += $share['date_last_review'] !== '' && $share['date_last_review'] >= $yearAgo ? 1 : 0;
            $out['without_owner'] += $share['users_id_owner'] > 0 ? 0 : 1;
        }

        return $out + ['gaps' => $gaps];
    }

    /**
     * Partage visible dans les entités de la session, ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function getShare(int $id): ?array
    {
        if ($id <= 0 || !self::canView()) {
            return null;
        }
        foreach (self::shares() as $share) {
            if ($share['id'] === $id) {
                return $share;
            }
        }

        return null;
    }

    /**
     * Droits d'un partage (actuels puis retirés au dernier import), décorés pour la fiche.
     *
     * @return list<array<string, mixed>>
     */
    public static function aces(int $shareId): array
    {
        global $DB;

        $share = self::getShare($shareId);
        if ($share === null) {
            return [];
        }
        $rows = iterator_to_array($DB->request([
            'FROM'  => ShareAccessTables::ACES,
            'WHERE' => ['plugin_grcmanager_fileshares_id' => $shareId],
            'ORDER' => ['status', 'depth', 'folder', 'scope DESC', 'identity'],
            'LIMIT' => self::MAX_ROWS,
        ]), false);
        $states = self::accountStates(array_map(static fn (array $row): int => (int) $row['users_id'], $rows));
        $out = [];
        foreach ($rows as $row) {
            $usersId = (int) $row['users_id'];
            $groupsId = (int) $row['groups_id'];
            $firstSeen = (string) ($row['date_first_seen'] ?? '');
            $out[] = [
                'id'           => (int) $row['id'],
                'scope'        => (string) $row['scope'],
                'folder'       => (string) $row['folder'],
                'depth'        => (int) $row['depth'],
                'identity'     => (string) $row['identity'],
                'sid'          => (string) $row['sid'],
                'kind'         => (string) $row['kind'],
                'rights'       => (string) $row['rights'],
                'level'        => (string) $row['level'],
                'is_deny'      => (bool) $row['is_deny'],
                'is_inherited' => (bool) $row['is_inherited'],
                'is_protected' => (bool) $row['is_protected'],
                'folder_owner' => (string) $row['folder_owner'],
                'is_removed'   => $row['status'] !== 'current',
                // Ajouté au dernier import, qui n'était pas le premier du partage.
                'is_new'       => $row['status'] === 'current' && $firstSeen !== ''
                    && $firstSeen === $share['date_import'] && $share['date_creation'] < $firstSeen,
                'user'         => $usersId > 0 ? [
                    'name' => (string) getUserName($usersId),
                    'url'  => User::getFormURLWithID($usersId),
                ] : null,
                'group'        => $groupsId > 0 ? [
                    'name' => (string) \Dropdown::getDropdownName(Group::getTable(), $groupsId),
                    'url'  => Group::getFormURLWithID($groupsId),
                ] : null,
                'gaps'         => $row['status'] === 'current'
                    ? Rules::aceGaps($row, $states[$usersId] ?? null)
                    : [],
                'decision'     => (string) $row['decision'],
                'comment'      => (string) ($row['decision_comment'] ?? ''),
                'decided_by'   => (int) $row['users_id_decision'] > 0
                    ? (string) getUserName((int) $row['users_id_decision'])
                    : '',
                'decided_on'   => (string) ($row['date_decision'] ?? '') !== ''
                    ? (string) Html::convDateTime((string) $row['date_decision'])
                    : '',
            ];
        }

        return $out;
    }

    /**
     * Point d'extension pour la revue des accès (#866) : droits sur les partages d'un utilisateur
     * GLPI, directement ou par un groupe GLPI dont il est membre, dans les entités de la session.
     * Une campagne de revue des accès peut les afficher sur la ligne de la personne ou s'en servir
     * dans ses règles de suggestion ; rien n'est modifié.
     *
     * @return list<array{share_id: int, share: string, folder: string, level: string, via: string,
     *     url: string}>
     */
    public static function accessesForUser(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0 || !self::canView()) {
            return [];
        }
        $shares = [];
        foreach (self::shares() as $share) {
            if ($share['is_present']) {
                $shares[$share['id']] = $share;
            }
        }
        if ($shares === []) {
            return [];
        }
        $groups = [];
        $request = ['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $usersId]];
        foreach ($DB->request($request) as $row) {
            $groups[] = (int) $row['groups_id'];
        }
        $who = ['users_id' => $usersId];
        if ($groups !== []) {
            $who = ['OR' => [['users_id' => $usersId], ['groups_id' => $groups]]];
        }
        $out = [];
        foreach (
            $DB->request([
                'FROM'  => ShareAccessTables::ACES,
                'WHERE' => [
                    'plugin_grcmanager_fileshares_id' => array_keys($shares),
                    'status'                          => 'current',
                    'is_deny'                         => 0,
                ] + $who,
                'ORDER' => ['plugin_grcmanager_fileshares_id', 'folder'],
                'LIMIT' => self::MAX_ROWS,
            ]) as $row
        ) {
            $shareId = (int) $row['plugin_grcmanager_fileshares_id'];
            $out[] = [
                'share_id' => $shareId,
                'share'    => (string) $shares[$shareId]['label'],
                'folder'   => (string) $row['folder'],
                'level'    => (string) $row['level'],
                'via'      => (int) $row['users_id'] === $usersId
                    ? ''
                    : (string) \Dropdown::getDropdownName(Group::getTable(), (int) $row['groups_id']),
                'url'      => self::getShareURL($shareId),
            ];
        }

        return $out;
    }

    /**
     * Derniers imports (les plus récents d'abord), dans les entités de la session.
     *
     * @return list<array{source: string, server: string, shares: int, aces: int, added: int, removed: int,
     *     date: string, user: string}>
     */
    public static function imports(int $limit = 20): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $out = [];
        foreach (
            $DB->request([
                'FROM'  => ShareAccessTables::IMPORTS,
                'WHERE' => getEntitiesRestrictCriteria(ShareAccessTables::IMPORTS),
                'ORDER' => ['date_import DESC', 'id DESC'],
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $out[] = [
                'source'  => (string) $row['source'],
                'server'  => (string) $row['server'],
                'shares'  => (int) $row['shares'],
                'aces'    => (int) $row['aces'],
                'added'   => (int) $row['added'],
                'removed' => (int) $row['removed'],
                'date'    => (string) Html::convDateTime((string) $row['date_import']),
                'user'    => (int) $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '',
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ Import.

    /**
     * Comptes et groupes GLPI visibles, pour le rapprochement (clés en minuscules).
     *
     * @return array{users: array<string, int>, groups: array<string, int>}
     */
    private static function lookup(): array
    {
        global $DB;

        $users = [];
        foreach (
            $DB->request([
                'SELECT'     => ['glpi_users.id', 'glpi_users.name'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_users',
                'INNER JOIN' => [
                    'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
                ],
                'WHERE'      => getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true),
            ]) as $row
        ) {
            $users[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }
        $groups = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => Group::getTable(),
                'WHERE'  => getEntitiesRestrictCriteria(Group::getTable(), '', '', true),
            ]) as $row
        ) {
            $groups[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }

        return ['users' => $users, 'groups' => $groups];
    }

    /**
     * Nature et rapprochement d'un compte importé.
     *
     * @param array{users: array<string, int>, groups: array<string, int>} $lookup
     * @return array{kind: string, users_id: int, groups_id: int}
     */
    public static function resolve(string $identity, string $sid, string $type, array $lookup): array
    {
        $kind = Rules::principalKind($identity, $sid, $type);
        $unmatched = [
            Rules::KIND_BROAD, Rules::KIND_SYSTEM, Rules::KIND_ORPHAN, Rules::KIND_ANONYMOUS, Rules::KIND_EXTERNAL,
        ];
        if (in_array($kind, $unmatched, true)) {
            return ['kind' => $kind, 'users_id' => 0, 'groups_id' => 0];
        }
        $full = mb_strtolower(trim($identity));
        $short = Rules::shortName($identity);
        if ($kind !== Rules::KIND_GROUP) {
            foreach ([$full, $short] as $key) {
                if ($key !== '' && isset($lookup['users'][$key])) {
                    return ['kind' => Rules::KIND_USER, 'users_id' => $lookup['users'][$key], 'groups_id' => 0];
                }
            }
        }
        if ($kind !== Rules::KIND_USER && $short !== '' && isset($lookup['groups'][$short])) {
            return ['kind' => Rules::KIND_GROUP, 'users_id' => 0, 'groups_id' => $lookup['groups'][$short]];
        }

        return ['kind' => $kind, 'users_id' => 0, 'groups_id' => 0];
    }

    /**
     * Importe un export CSV : pour chaque serveur du fichier, met à jour ses partages dans l'entité
     * active, marque comme retirés les droits qui ont disparu et ajoute les nouveaux. La
     * classification, le propriétaire désigné et les décisions de revue des droits inchangés sont
     * gardés. Rien n'est modifié sur les serveurs de fichiers.
     *
     * @return array{shares: int, aces: int, added: int, removed: int, matched: int, errors: list<string>,
     *     truncated: bool}
     */
    public static function import(string $raw): array
    {
        $result = self::emptyResult();
        if (!self::canManage()) {
            $result['errors'][] = 'rights';

            return $result;
        }
        $parsed = Rules::parseCsv($raw);
        if ($parsed['errors'] !== []) {
            $result['errors'] = $parsed['errors'];

            return $result;
        }
        $rows = array_map(static fn (array $row): array => $row + ['source' => self::SOURCE_SMB], $parsed['rows']);

        return ['truncated' => $parsed['truncated']] + self::storeRows($rows, [], 'csv');
    }

    /**
     * Lot 2 : importe un export CSV des droits SharePoint, OneDrive ou Teams (solution de repli
     * quand Microsoft Graph n'est pas joignable ou que le locataire est trop grand).
     *
     * @return array{shares: int, aces: int, added: int, removed: int, matched: int, errors: list<string>,
     *     truncated: bool}
     */
    public static function importM365Csv(string $raw): array
    {
        $result = self::emptyResult();
        if (!self::canManage()) {
            $result['errors'][] = 'rights';

            return $result;
        }
        $tenant = EntraGraphConfig::tenant();
        $parsed = M365ShareRules::parseCsv($raw, $tenant !== '' ? $tenant : M365ShareRules::DEFAULT_TENANT);
        if ($parsed['errors'] !== []) {
            $result['errors'] = $parsed['errors'];

            return $result;
        }

        return ['truncated' => $parsed['truncated']] + self::storeRows($parsed['rows'], [], 'csv');
    }

    /**
     * Lot 2 : lit par Microsoft Graph, sur clic et en lecture seule, les droits d'une source
     * (sharepoint, onedrive ou teams) et les range comme un import. Lecture incomplète ou en erreur :
     * rien n'est remplacé.
     *
     * @return array{shares: int, aces: int, added: int, removed: int, matched: int, errors: list<string>,
     *     truncated: bool, graph_error: string}
     */
    public static function importFromGraph(string $source, ?EntraGraphClient $client = null): array
    {
        $result = self::emptyResult() + ['graph_error' => ''];
        if (!self::canManage()) {
            $result['errors'][] = 'rights';

            return $result;
        }
        if (!M365ShareRules::isSource($source)) {
            return ['graph_error' => EntraGraphClient::ERROR_INVALID] + $result;
        }
        if ($client === null && !EntraGraphConfig::isConfigured()) {
            return ['graph_error' => EntraGraphClient::ERROR_CONFIG] + $result;
        }
        $client ??= EntraGraphConfig::client();
        $server = EntraGraphConfig::tenant() !== '' ? EntraGraphConfig::tenant() : M365ShareRules::DEFAULT_TENANT;
        $read = (new M365ShareReader($client, $server))->read($source);
        if ($read['error'] !== '') {
            return ['graph_error' => $read['error']] + $result;
        }

        return ['graph_error' => ''] + self::storeRows($read['rows'], [[$source, $server]], 'graph');
    }

    /**
     * @return array{shares: int, aces: int, added: int, removed: int, matched: int, errors: list<string>,
     *     truncated: bool}
     */
    private static function emptyResult(): array
    {
        return [
            'shares' => 0, 'aces' => 0, 'added' => 0, 'removed' => 0, 'matched' => 0,
            'errors' => [], 'truncated' => false,
        ];
    }

    /**
     * Range des lignes de droits : pour chaque source et serveur (ou locataire), met à jour ses
     * partages dans l'entité active, marque comme retirés les droits qui ont disparu et ajoute les
     * nouveaux. La classification, le propriétaire désigné et les décisions de revue des droits
     * inchangés sont gardés. $cover : couples [source, serveur] lus en entier même sans ligne (une
     * lecture Graph sans aucun site marque les sites connus absents).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{0: string, 1: string}> $cover
     * @return array{shares: int, aces: int, added: int, removed: int, matched: int, errors: list<string>,
     *     truncated: bool}
     */
    private static function storeRows(array $rows, array $cover, string $origin): array
    {
        global $DB;

        $result = self::emptyResult();
        $entity = (int) Session::getActiveEntity();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $lookup = self::lookup();

        // Lignes regroupées par source et serveur, puis par partage (insensible à la casse).
        $servers = [];
        foreach ($cover as [$source, $name]) {
            $servers[$source . '|' . mb_strtolower($name)] = ['source' => $source, 'name' => $name, 'shares' => []];
        }
        foreach ($rows as $row) {
            $source = (string) $row['source'];
            $serverKey = $source . '|' . mb_strtolower((string) $row['server']);
            $servers[$serverKey]['source'] ??= $source;
            $servers[$serverKey]['name'] ??= $row['server'];
            $servers[$serverKey]['shares'][mb_strtolower((string) $row['share'])][] = $row;
        }

        foreach ($servers as $server) {
            $source = $server['source'];
            $stats = ['shares' => 0, 'aces' => 0, 'added' => 0, 'removed' => 0];
            // Partages déjà connus de ce serveur dans l'entité active.
            $known = [];
            foreach (
                $DB->request([
                    'FROM'  => ShareAccessTables::SHARES,
                    'WHERE' => ['entities_id' => $entity, 'source' => $source, 'server' => $server['name']],
                ]) as $row
            ) {
                $known[mb_strtolower((string) $row['name'])] = $row;
            }
            $seenShares = [];
            foreach ($server['shares'] as $shareKey => $shareRows) {
                $root = self::rootOwner($shareRows);
                $sharePath = '';
                foreach ($shareRows as $row) {
                    $sharePath = $sharePath !== '' ? $sharePath : (string) $row['share_path'];
                }
                if (isset($known[$shareKey])) {
                    $shareId = (int) $known[$shareKey]['id'];
                    $reviewOpen = $known[$shareKey]['review_status'] === AccessReviewLogic::STATUS_OPEN;
                    $DB->update(ShareAccessTables::SHARES, [
                        'share_path'  => $sharePath,
                        'owner'       => $root,
                        'is_present'  => 1,
                        'date_import' => $now,
                    ], ['id' => $shareId]);
                } else {
                    $DB->insert(ShareAccessTables::SHARES, [
                        'entities_id'   => $entity,
                        'source'        => $source,
                        'server'        => $server['name'],
                        'name'          => $shareRows[0]['share'],
                        'share_path'    => $sharePath,
                        'owner'         => $root,
                        'is_present'    => 1,
                        'date_import'   => $now,
                        'date_creation' => $now,
                    ]);
                    $shareId = (int) $DB->insertId();
                    $reviewOpen = false;
                }
                $seenShares[$shareId] = true;
                $stats['shares']++;
                $diff = self::storeAces($shareId, $shareRows, $lookup, $now, $reviewOpen);
                $stats['aces'] += $diff['aces'];
                $stats['added'] += $diff['added'];
                $stats['removed'] += $diff['removed'];
                $result['matched'] += $diff['matched'];
            }
            // Partages du serveur absents du fichier : marqués absents, leurs droits retirés.
            foreach ($known as $row) {
                $shareId = (int) $row['id'];
                if (isset($seenShares[$shareId])) {
                    continue;
                }
                $DB->update(ShareAccessTables::SHARES, ['is_present' => 0, 'date_import' => $now], ['id' => $shareId]);
                $stats['removed'] += self::storeAces($shareId, [], $lookup, $now, false)['removed'];
            }
            $DB->insert(ShareAccessTables::IMPORTS, [
                'entities_id' => $entity,
                'source'      => $source,
                'server'      => $server['name'],
                'users_id'    => (int) Session::getLoginUserID(),
                'date_import' => $now,
            ] + $stats);
            foreach (['shares', 'aces', 'added', 'removed'] as $key) {
                $result[$key] += $stats[$key];
            }
        }
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'origin'  => $origin,
            'sources' => array_values(array_unique(array_column($servers, 'source'))),
            'servers' => count($servers),
            'shares'  => $result['shares'],
            'aces'    => $result['aces'],
            'added'   => $result['added'],
            'removed' => $result['removed'],
        ]);
        self::reset();

        return $result;
    }

    /**
     * Propriétaire de la racine du partage (ligne de profondeur 0 qui le déclare).
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function rootOwner(array $rows): string
    {
        $owner = '';
        foreach ($rows as $row) {
            if ((string) $row['owner'] !== '' && ((int) $row['depth'] === 0 || $owner === '')) {
                $owner = (string) $row['owner'];
                if ((int) $row['depth'] === 0) {
                    break;
                }
            }
        }

        return $owner;
    }

    /**
     * Range les droits d'un partage : efface les droits retirés à l'import précédent, garde les
     * droits inchangés (et leur décision), ajoute les nouveaux, marque les disparus « retirés ».
     *
     * @param list<array<string, mixed>> $rows
     * @param array{users: array<string, int>, groups: array<string, int>} $lookup
     * @return array{aces: int, added: int, removed: int, matched: int}
     */
    private static function storeAces(int $shareId, array $rows, array $lookup, string $now, bool $reviewOpen): array
    {
        global $DB;

        $stats = ['aces' => 0, 'added' => 0, 'removed' => 0, 'matched' => 0];
        $DB->delete(ShareAccessTables::ACES, ['plugin_grcmanager_fileshares_id' => $shareId, 'status' => 'removed']);
        $existing = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'fingerprint'],
                'FROM'   => ShareAccessTables::ACES,
                'WHERE'  => ['plugin_grcmanager_fileshares_id' => $shareId],
            ]) as $row
        ) {
            $existing[(string) $row['fingerprint']] = (int) $row['id'];
        }
        $seen = [];
        foreach ($rows as $row) {
            $fingerprint = Rules::fingerprint($row);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $resolved = self::resolve($row['identity'], $row['sid'], $row['type'], $lookup);
            $values = [
                'scope'        => $row['scope'],
                'folder'       => $row['folder'],
                'depth'        => $row['depth'],
                'identity'     => $row['identity'],
                'sid'          => $row['sid'],
                'kind'         => $resolved['kind'],
                'users_id'     => $resolved['users_id'],
                'groups_id'    => $resolved['groups_id'],
                'rights'       => $row['rights'],
                'level'        => $row['level'],
                'is_deny'      => $row['is_deny'] ? 1 : 0,
                'is_inherited' => $row['is_inherited'] ? 1 : 0,
                'is_protected' => $row['is_protected'] ? 1 : 0,
                'folder_owner' => $row['owner'],
            ];
            $stats['aces']++;
            $stats['matched'] += $resolved['users_id'] > 0 || $resolved['groups_id'] > 0 ? 1 : 0;
            if (isset($existing[$fingerprint])) {
                $DB->update(ShareAccessTables::ACES, $values, ['id' => $existing[$fingerprint]]);
                continue;
            }
            $DB->insert(ShareAccessTables::ACES, $values + [
                'plugin_grcmanager_fileshares_id' => $shareId,
                'fingerprint'                     => $fingerprint,
                'status'                          => 'current',
                'date_first_seen'                 => $now,
                'decision'                        => $reviewOpen ? AccessReviewLogic::DECISION_PENDING : '',
            ]);
            $stats['added']++;
        }
        foreach ($existing as $fingerprint => $id) {
            if (!isset($seen[$fingerprint])) {
                $DB->update(ShareAccessTables::ACES, ['status' => 'removed', 'date_removed' => $now], ['id' => $id]);
                $stats['removed']++;
            }
        }

        return $stats;
    }

    /**
     * Efface tout ce qui a été importé pour un serveur dans l'entité active (partages, droits,
     * historique), classification et revues comprises.
     */
    public static function clear(string $server, string $source = self::SOURCE_SMB): bool
    {
        global $DB;

        if (!self::canManage() || trim($server) === '' || !isset(self::sourceInfos()[$source])) {
            return false;
        }
        $where = ['entities_id' => (int) Session::getActiveEntity(), 'source' => $source, 'server' => $server];
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => ShareAccessTables::SHARES, 'WHERE' => $where]) as $row) {
            $ids[] = (int) $row['id'];
        }
        if ($ids !== []) {
            $DB->delete(ShareAccessTables::ACES, ['plugin_grcmanager_fileshares_id' => $ids]);
            $DB->delete(ShareAccessTables::SHARES, ['id' => $ids]);
        }
        $DB->delete(ShareAccessTables::IMPORTS, $where);
        AuditLog::record('purge', self::class, 0, 0, 'success', [
            'source' => $source, 'server' => $server, 'shares' => count($ids),
        ]);
        self::reset();

        return $ids !== [];
    }

    // ------------------------------------------------------------------ Partage : classification, revue.

    /**
     * Classification C/I/D, propriétaire désigné et commentaire d'un partage visible.
     *
     * @param array<string, mixed> $input confidentiality, integrity, availability, users_id_owner, comment
     */
    public static function updateShare(int $id, array $input): bool
    {
        global $DB;

        if (!self::canManage() || self::getShare($id) === null) {
            return false;
        }
        $values = [];
        foreach (ClassificationLevels::AXES as $axis) {
            $values[$axis] = ClassificationLevels::sanitize((string) ($input[$axis] ?? '')) ?? '';
        }
        $owner = (int) ($input['users_id_owner'] ?? 0);
        $values['users_id_owner'] = $owner > 0 && self::isVisibleUser($owner) ? $owner : 0;
        $values['comment'] = mb_substr(trim((string) ($input['comment'] ?? '')), 0, 5000);
        $ok = $DB->update(ShareAccessTables::SHARES, $values, ['id' => $id]);
        AuditLog::record('update', self::class, $id, 0, 'success', [
            'classification' => [$values['confidentiality'], $values['integrity'], $values['availability']],
            'users_id_owner' => $values['users_id_owner'],
        ]);
        self::reset();

        return (bool) $ok;
    }

    private static function isVisibleUser(int $usersId): bool
    {
        return countElementsInTable('glpi_profiles_users', [
            'users_id' => $usersId,
        ] + getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true)) > 0;
    }

    /**
     * Le propriétaire désigné décide pendant la revue ; un gestionnaire aussi.
     *
     * @param array<string, mixed> $share
     */
    public static function canDecide(array $share): bool
    {
        return $share['review_open']
            && (self::canManage()
                || ((int) $share['users_id_owner'] > 0
                    && (int) $share['users_id_owner'] === (int) Session::getLoginUserID()));
    }

    /**
     * Lance la revue d'un partage : chaque droit actuel passe « à revoir ». Il faut un propriétaire.
     */
    public static function startReview(int $id): string
    {
        global $DB;

        $share = self::getShare($id);
        if ($share === null || !self::canManage()) {
            return 'rights';
        }
        if ($share['users_id_owner'] <= 0) {
            return 'no_owner';
        }
        $DB->update(ShareAccessTables::SHARES, [
            'review_status'     => AccessReviewLogic::STATUS_OPEN,
            'date_review_start' => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        $DB->update(ShareAccessTables::ACES, [
            'decision'          => AccessReviewLogic::DECISION_PENDING,
            'decision_comment'  => '',
            'users_id_decision' => 0,
            'date_decision'     => null,
        ], ['plugin_grcmanager_fileshares_id' => $id, 'status' => 'current']);
        AuditLog::record('update', self::class, $id, 0, 'success', ['review' => 'start']);
        self::reset();

        return '';
    }

    /**
     * Décision sur un droit pendant la revue. Retirer ou modifier exige un commentaire. Rien n'est
     * changé sur le serveur : l'administrateur applique la décision lui-même.
     */
    public static function decide(int $aceId, string $decision, string $comment): string
    {
        global $DB;

        $allowed = [
            AccessReviewLogic::DECISION_KEEP, AccessReviewLogic::DECISION_MODIFY, AccessReviewLogic::DECISION_REMOVE,
        ];
        if (!in_array($decision, $allowed, true)) {
            return 'decision';
        }
        $shareId = 0;
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_fileshares_id'],
                'FROM'   => ShareAccessTables::ACES,
                'WHERE'  => ['id' => $aceId, 'status' => 'current'],
            ]) as $row
        ) {
            $shareId = (int) $row['plugin_grcmanager_fileshares_id'];
        }
        $share = self::getShare($shareId);
        if ($share === null || !self::canDecide($share)) {
            return 'rights';
        }
        $comment = mb_substr(trim($comment), 0, 2000);
        if ($decision !== AccessReviewLogic::DECISION_KEEP && $comment === '') {
            return 'comment';
        }
        $DB->update(ShareAccessTables::ACES, [
            'decision'          => $decision,
            'decision_comment'  => $comment,
            'users_id_decision' => (int) Session::getLoginUserID(),
            'date_decision'     => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
        ], ['id' => $aceId]);
        AuditLog::record('update', self::class, $shareId, 0, 'success', ['ace' => $aceId, 'decision' => $decision]);
        self::reset();

        return '';
    }

    /**
     * Clôt la revue d'un partage quand tous les droits ont une décision : date de dernière revue.
     */
    public static function closeReview(int $id): string
    {
        global $DB;

        $share = self::getShare($id);
        if ($share === null || !self::canDecide($share)) {
            return 'rights';
        }
        if ($share['pending'] > 0) {
            return 'pending';
        }
        $counts = [];
        foreach (
            $DB->request([
                'SELECT' => ['decision'],
                'FROM'   => ShareAccessTables::ACES,
                'WHERE'  => ['plugin_grcmanager_fileshares_id' => $id, 'status' => 'current'],
            ]) as $row
        ) {
            $counts[(string) $row['decision']] = ($counts[(string) $row['decision']] ?? 0) + 1;
        }
        $DB->update(ShareAccessTables::SHARES, [
            'review_status'        => '',
            'date_last_review'     => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
            'users_id_last_review' => (int) Session::getLoginUserID(),
        ], ['id' => $id]);
        AuditLog::record('review', self::class, $id, 0, 'success', ['decisions' => $counts]);
        self::reset();

        return '';
    }

    public static function reviewErrorMessage(string $code): string
    {
        return match ($code) {
            'no_owner' => __('Designate the owner of the share first: they review its rights.', 'grcmanager'),
            'comment'  => __('Explain what must be changed or removed in the comment.', 'grcmanager'),
            'pending'  => __('Some rights have no decision yet.', 'grcmanager'),
            'decision' => __('Unknown decision.', 'grcmanager'),
            default    => __('You cannot do this on this share.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $shares = self::shares();
        $listed = $shares;
        usort(
            $listed,
            static fn (array $a, array $b): int => [!$a['is_present'], -$a['score'], $a['label']]
                <=> [!$b['is_present'], -$b['score'], $b['label']]
        );
        $shown = array_slice($listed, 0, self::MAX_ROWS);
        foreach ($shown as &$row) {
            $row['url'] = self::getShareURL($row['id']);
            $row['owner_name'] = $row['users_id_owner'] > 0 ? (string) getUserName($row['users_id_owner']) : '';
            $row['last_review'] = self::date($row['date_last_review']);
        }
        unset($row);
        $servers = [];
        foreach ($shares as $share) {
            if ((int) $share['entities_id'] === (int) Session::getActiveEntity()) {
                $servers[$share['source'] . '|' . $share['server']] = [
                    'source' => $share['source'], 'server' => $share['server'],
                ];
            }
        }

        return self::commonData() + [
            'summary'     => self::summary($shares),
            'rows'        => $shown,
            'total_rows'  => count($listed),
            'imports'     => self::imports(),
            'servers'     => array_values($servers),
            'example_url' => self::getSearchURL() . '?example=1',
            'm365_example_url' => self::getSearchURL() . '?example=m365',
            // Lecture de Microsoft 365 par Graph : réglages de la couverture MFA (#852), jamais le secret.
            'graph'       => [
                'configured'   => EntraGraphConfig::isConfigured(),
                'tenant'       => EntraGraphConfig::tenant(),
                // Issue #1043 : la connexion se règle dans la configuration, onglet Connecteurs.
                'settings_url' => \GlpiPlugin\Grcmanager\Config\ConfigTabs::anchorUrl(
                    \GlpiPlugin\Grcmanager\Config\Block\EntraGraphConnectorBlock::ANCHOR
                ),
                'sources'      => M365ShareRules::SOURCES,
            ],
            'script_url'  => self::getSearchURL() . '?script=1',
            'review_url'  => AccessReview::getSearchURL(),
            'hygiene_url' => AccountHygiene::getSearchURL(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commonData(): array
    {
        return [
            'levels'     => self::levelInfos(),
            'kinds'      => self::kindInfos(),
            'sources'    => self::sourceInfos(),
            'gap_infos'  => self::gapInfos(),
            'scores'     => self::scoreInfos(),
            'cid_levels' => AssetClassification::getLevels(),
            'decisions'  => AccessReview::getDecisionLabels(),
            'can_manage' => self::canManage(),
            'action'     => self::getSearchURL(),
            'list_url'   => self::getSearchURL(),
        ];
    }

    /**
     * @param array<string, mixed> $share
     * @return array<string, mixed>
     */
    public static function shareData(array $share): array
    {
        $aces = self::aces((int) $share['id']);
        $folders = [];
        foreach ($aces as $ace) {
            if ($ace['is_removed']) {
                continue;
            }
            $key = $ace['folder'];
            $folders[$key] ??= [
                'folder' => $key, 'depth' => $ace['depth'], 'is_protected' => false, 'owner' => '', 'aces' => [],
            ];
            $folders[$key]['is_protected'] = $folders[$key]['is_protected'] || $ace['is_protected'];
            $folders[$key]['owner'] = $ace['folder_owner'] !== '' ? $ace['folder_owner'] : $folders[$key]['owner'];
            $folders[$key]['aces'][] = $ace;
        }

        return self::commonData() + [
            'share'      => $share + [
                'owner_name'  => $share['users_id_owner'] > 0 ? (string) getUserName($share['users_id_owner']) : '',
                'last_review' => self::date($share['date_last_review']),
                'imported'    => self::date($share['date_import']),
            ],
            'folders'    => array_values($folders),
            'removed'    => array_values(array_filter($aces, static fn (array $ace): bool => $ace['is_removed'])),
            'added'      => count(array_filter($aces, static fn (array $ace): bool => $ace['is_new'])),
            'can_decide' => self::canDecide($share),
        ];
    }

    private static function date(string $value): string
    {
        return $value !== '' ? (string) Html::convDateTime($value) : '';
    }

    public static function showPage(int $shareId = 0): void
    {
        if ($shareId > 0) {
            $share = self::getShare($shareId);
            if ($share !== null) {
                TemplateRenderer::getInstance()->display(
                    '@grcmanager/share_access_detail.html.twig',
                    self::shareData($share)
                );

                return;
            }
        }
        TemplateRenderer::getInstance()->display('@grcmanager/share_access.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuve des mesures A.5.15, A.5.18 et A.8.3 : partages, écarts, revues des douze derniers mois.
     *
     * @return array{shares: int, gap_shares: int, broad_write: int, stale_accounts: int, reviewed: int,
     *     without_owner: int, date: string, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::CONTROLS, true) || !self::canView()) {
            return null;
        }
        $summary = self::summary();
        if ($summary['shares'] === 0) {
            return null;
        }

        return [
            'shares'         => $summary['shares'],
            'gap_shares'     => $summary['gap_shares'],
            'broad_write'    => $summary['gaps'][Rules::GAP_BROAD_WRITE] ?? 0,
            'stale_accounts' => ($summary['gaps'][Rules::GAP_DELETED_ACCOUNT] ?? 0)
                + ($summary['gaps'][Rules::GAP_DISABLED_ACCOUNT] ?? 0),
            'reviewed'       => $summary['reviewed'],
            'without_owner'  => $summary['without_owner'],
            'date'           => (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'url'            => self::getSearchURL(),
        ];
    }
}
