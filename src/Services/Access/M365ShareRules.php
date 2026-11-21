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

namespace GlpiPlugin\Grcmanager\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\ShareAccessRules as Rules;
use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;

/**
 * Issue #846, lot 2 : droits d'accès aux sites SharePoint, aux OneDrive et aux équipes Teams.
 *
 * Règles pures : transforme les droits lus par Microsoft Graph (permissions des bibliothèques et des
 * éléments partagés, membres et propriétaires des équipes) ou un export CSV en lignes de droits au
 * format des partages de fichiers (ShareAccessRules::parseCsv()), pour les mêmes écarts et la même
 * revue. Un lien « tout le monde » devient un compte anonyme, un lien « personnes de l'organisation »
 * ou une équipe publique un groupe large, un compte d'un autre domaine un invité externe.
 *
 * @phpstan-type Row array{source: string, server: string, share: string, share_path: string,
 *     folder: string, depth: int, scope: string, identity: string, sid: string, type: string,
 *     rights: string, level: string, is_deny: bool, is_inherited: bool, is_protected: bool, owner: string}
 */
final class M365ShareRules
{
    public const SOURCE_SHAREPOINT = 'sharepoint';
    public const SOURCE_ONEDRIVE = 'onedrive';
    public const SOURCE_TEAMS = 'teams';

    public const SOURCES = [self::SOURCE_SHAREPOINT, self::SOURCE_ONEDRIVE, self::SOURCE_TEAMS];

    /** Locataire par défaut d'un import CSV sans colonne Tenant. */
    public const DEFAULT_TENANT = 'Microsoft 365';

    /** Identités posées pour les liens et les équipes publiques (en anglais, comme Microsoft 365). */
    public const ANYONE_LINK = 'Anyone with the link';
    public const ORGANIZATION_LINK = 'People in the organization';
    public const PUBLIC_TEAM = 'Everyone in the organization (public team)';

    /** Noms et identifiants SharePoint des groupes « tout le monde ». */
    private const BROAD_NAMES = [
        'everyone', 'everyone except external users', 'tout le monde', 'tout le monde sauf les utilisateurs externes',
        'jeder', 'jeder außer externen benutzern', 'todos', 'todos excepto los usuarios externos', 'chiunque',
        'tutti tranne gli utenti esterni', 'wszyscy', 'wszyscy z wyjątkiem użytkowników zewnętrznych',
        'todos exceto usuários externos',
    ];

    /**
     * Colonnes reconnues de l'export CSV (en-têtes normalisés).
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'source'    => ['source', 'platform', 'service', 'workload', 'plateforme'],
        'tenant'    => ['tenant', 'tenantname', 'locataire', 'domain'],
        'site'      => ['site', 'sitename', 'team', 'teamname', 'onedrive', 'library', 'equipe', 'quipe'],
        'url'       => ['siteurl', 'url', 'weburl', 'adresse'],
        'item'      => ['item', 'itempath', 'itemurl', 'folder', 'file', 'path', 'element', 'lment', 'dossier'],
        'identity'  => [
            'identity', 'user', 'username', 'userprincipalname', 'upn', 'email', 'principal', 'member',
            'account', 'compte', 'utilisateur',
        ],
        'type'      => ['type', 'principaltype', 'usertype', 'accounttype', 'typedecompte'],
        'rights'    => ['roles', 'role', 'rights', 'permission', 'permissions', 'access', 'droits', 'droit'],
        'linkscope' => ['linkscope', 'sharinglink', 'link', 'scope', 'lien', 'portee', 'porte'],
        'owner'     => ['owner', 'siteowner', 'proprietaire', 'propritaire'],
    ];

    public static function isSource(string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    /**
     * Niveau d'un rôle Microsoft 365 (owner, write, read, edit, view, « Full Control »…).
     */
    public static function roleLevel(string $roles): string
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($roles));
        if ($key === '') {
            return Rules::LEVEL_OTHER;
        }
        if (preg_match('/owner|fullcontrol|proprietaire|propritaire|controletotal/', $key) === 1) {
            return Rules::LEVEL_FULL;
        }
        if (preg_match('/edit|contribute|member|membre/', $key) === 1) {
            return Rules::LEVEL_MODIFY;
        }
        if (preg_match('/view|visitor|visiteur/', $key) === 1) {
            return Rules::LEVEL_READ;
        }

        return Rules::rightLevel($roles);
    }

    /**
     * Domaine d'une adresse, en minuscules ; vide sans « @ ».
     */
    public static function domainOf(string $address): string
    {
        $at = mb_strrpos($address, '@');

        return $at === false ? '' : mb_strtolower(trim(mb_substr($address, $at + 1)));
    }

    /**
     * Compte externe ? Marque « #EXT# » des invités Entra ID, ou domaine hors des domaines vérifiés
     * du locataire (quand ils sont connus).
     *
     * @param list<string> $domains domaines vérifiés, en minuscules
     */
    public static function isExternal(string $address, string $loginName, array $domains): bool
    {
        if (str_contains(mb_strtolower($address . ' ' . $loginName), '#ext#')) {
            return true;
        }
        $domain = self::domainOf($address);

        return $domain !== '' && $domains !== [] && !in_array($domain, $domains, true);
    }

    /**
     * Groupe SharePoint « tout le monde » (nom ou identifiant de réclamation).
     */
    public static function isBroadPrincipal(string $name, string $loginName): bool
    {
        $login = mb_strtolower($loginName);

        return in_array(mb_strtolower(trim($name)), self::BROAD_NAMES, true)
            || str_contains($login, 'spo-grid-all-users')
            || str_starts_with($login, 'c:0(.s|true');
    }

    /**
     * Identité d'un principal Graph (identitySet ou sharePointIdentitySet).
     *
     * @param array<string, mixed> $set
     * @param list<string> $domains
     * @return array{identity: string, sid: string, type: string}|null
     */
    public static function principal(array $set, array $domains): ?array
    {
        foreach (['user', 'siteUser', 'group', 'siteGroup', 'application', 'device'] as $key) {
            $entry = $set[$key] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $name = self::text($entry['displayName'] ?? '');
            $email = self::text($entry['email'] ?? '');
            $login = self::text($entry['loginName'] ?? '');
            $id = self::text($entry['id'] ?? '');
            $identity = $email !== '' ? $email : ($name !== '' ? $name : $login);
            if ($identity === '') {
                return null;
            }
            $type = in_array($key, ['group', 'siteGroup'], true) ? 'group' : 'user';
            if (self::isBroadPrincipal($name, $login)) {
                $type = Rules::KIND_BROAD;
            } elseif ($type === 'user' && self::isExternal($email, $login, $domains)) {
                $type = Rules::KIND_EXTERNAL;
            }

            return [
                'identity' => mb_substr($identity, 0, 255),
                'sid'      => mb_substr($id !== '' ? $id : $login, 0, 190),
                'type'     => $type,
            ];
        }

        return null;
    }

    /**
     * Lignes de droits d'une permission Graph (permission d'un dossier, d'un fichier ou d'une
     * bibliothèque). Un lien anonyme ou « organisation » donne une seule ligne ; un lien « personnes
     * précises » une ligne par personne ; une permission directe une ligne pour son principal.
     *
     * @param array<string, mixed> $permission
     * @param array{source: string, server: string, share: string, share_path: string, folder: string,
     *     depth: int, is_protected: bool, owner: string} $context
     * @param list<string> $domains
     * @return list<Row>
     */
    public static function permissionRows(array $permission, array $context, array $domains): array
    {
        $roles = [];
        foreach (is_array($permission['roles'] ?? null) ? $permission['roles'] : [] as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }
        $link = is_array($permission['link'] ?? null) ? $permission['link'] : null;
        $rights = implode(', ', $roles);
        if ($link !== null) {
            $linkType = self::text($link['type'] ?? '');
            $rights = trim($rights . ($linkType !== '' ? ' (' . $linkType . ' link)' : ' (link)'));
        }
        $level = self::roleLevel(implode(' ', $roles) . ' ' . self::text($link['type'] ?? ''));
        $base = $context + [
            'scope'        => $link !== null ? Rules::SCOPE_LINK : Rules::SCOPE_SHARE,
            'rights'       => mb_substr($rights, 0, 255),
            'level'        => $level,
            'is_deny'      => false,
            'is_inherited' => is_array($permission['inheritedFrom'] ?? null) && $permission['inheritedFrom'] !== [],
        ];
        $linkScope = mb_strtolower(self::text($link['scope'] ?? ''));
        if ($linkScope === 'anonymous') {
            return [self::row($base, self::ANYONE_LINK, 'link:anonymous', Rules::KIND_ANONYMOUS)];
        }
        if ($linkScope === 'organization') {
            return [self::row($base, self::ORGANIZATION_LINK, 'link:organization', Rules::KIND_BROAD)];
        }
        $sets = [];
        foreach (['grantedToIdentitiesV2', 'grantedToIdentities'] as $key) {
            if ($sets === [] && is_array($permission[$key] ?? null)) {
                $sets = array_values(array_filter($permission[$key], 'is_array'));
            }
        }
        if ($sets === []) {
            foreach (['grantedToV2', 'grantedTo'] as $key) {
                if ($sets === [] && is_array($permission[$key] ?? null)) {
                    $sets = [$permission[$key]];
                }
            }
        }
        $rows = [];
        foreach ($sets as $set) {
            $principal = self::principal($set, $domains);
            if ($principal !== null) {
                $rows[] = self::row($base, $principal['identity'], $principal['sid'], $principal['type']);
            }
        }
        // Invitation pas encore acceptée : seule l'adresse invitée est connue.
        $invited = self::text(is_array($permission['invitation'] ?? null) ? ($permission['invitation']['email'] ?? '') : '');
        if ($rows === [] && $invited !== '') {
            $type = self::isExternal($invited, '', $domains) ? Rules::KIND_EXTERNAL : 'user';
            $rows[] = self::row($base, mb_substr($invited, 0, 255), '', $type);
        }

        return $rows;
    }

    /**
     * Lignes de droits d'une équipe Teams : propriétaires (contrôle total), membres (modification),
     * invités externes, et « toute l'organisation » pour une équipe publique.
     *
     * @param array<string, mixed> $team groupe Graph (id, displayName, visibility, mail)
     * @param list<array<string, mixed>> $owners
     * @param list<array<string, mixed>> $members
     * @param list<string> $domains
     * @return list<Row>
     */
    public static function teamRows(string $server, array $team, array $owners, array $members, array $domains): array
    {
        $name = mb_substr(self::text($team['displayName'] ?? ''), 0, 255);
        if ($name === '') {
            return [];
        }
        $ownerIds = [];
        $firstOwner = '';
        foreach ($owners as $owner) {
            $ownerIds[self::text($owner['id'] ?? '')] = true;
            $firstOwner = $firstOwner !== '' ? $firstOwner : self::text($owner['userPrincipalName'] ?? '');
        }
        $context = [
            'source'       => self::SOURCE_TEAMS,
            'server'       => $server,
            'share'        => $name,
            'share_path'   => mb_substr(self::text($team['mail'] ?? ''), 0, 1000),
            'folder'       => $name,
            'depth'        => 0,
            'is_protected' => false,
            'owner'        => mb_substr($firstOwner, 0, 255),
            'scope'        => Rules::SCOPE_MEMBER,
            'is_deny'      => false,
            'is_inherited' => false,
        ];
        $rows = [];
        $seen = [];
        foreach (array_merge($owners, $members) as $person) {
            $id = self::text($person['id'] ?? '');
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $upn = self::text($person['userPrincipalName'] ?? '');
            $mail = self::text($person['mail'] ?? '');
            $identity = $upn !== '' ? $upn : ($mail !== '' ? $mail : self::text($person['displayName'] ?? ''));
            if ($identity === '') {
                continue;
            }
            $isOwner = isset($ownerIds[$id]);
            $guest = mb_strtolower(self::text($person['userType'] ?? '')) === 'guest'
                || self::isExternal($mail !== '' ? $mail : $upn, $upn, $domains);
            $rows[] = self::row(
                $context + [
                    'rights' => $isOwner ? 'Owner' : 'Member',
                    'level'  => $isOwner ? Rules::LEVEL_FULL : Rules::LEVEL_MODIFY,
                ],
                mb_substr($identity, 0, 255),
                mb_substr($id, 0, 190),
                $guest ? Rules::KIND_EXTERNAL : 'user'
            );
        }
        // Équipe publique : toute personne de l'organisation peut la rejoindre et y écrire.
        if (mb_strtolower(self::text($team['visibility'] ?? '')) === 'public') {
            $rows[] = self::row(
                $context + ['rights' => 'Public team', 'level' => Rules::LEVEL_MODIFY],
                self::PUBLIC_TEAM,
                'team:public',
                Rules::KIND_BROAD
            );
        }

        return $rows;
    }

    /**
     * Lit un export CSV des droits Microsoft 365 (une ligne par droit).
     *
     * @return array{rows: list<Row>, errors: list<string>, truncated: bool}
     */
    public static function parseCsv(string $raw, string $defaultTenant = self::DEFAULT_TENANT): array
    {
        $parsed = EdrCsvImport::parse($raw);
        $mapping = [];
        foreach (self::FIELDS as $field => $aliases) {
            foreach ($parsed['headers'] as $index => $header) {
                if (!in_array($index, $mapping, true) && in_array(EdrCsvImport::normalizeHeader($header), $aliases, true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }
        foreach (['site' => 'missing_site', 'identity' => 'missing_identity', 'rights' => 'missing_rights'] as $f => $e) {
            if (!isset($mapping[$f])) {
                return ['rows' => [], 'errors' => [$e], 'truncated' => false];
            }
        }
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $site = mb_substr($get($row, 'site'), 0, 255);
            $identity = mb_substr($get($row, 'identity'), 0, 255);
            $linkScope = mb_strtolower($get($row, 'linkscope'));
            if ($site === '' || ($identity === '' && !in_array($linkScope, ['anonymous', 'organization'], true))) {
                continue;
            }
            $source = self::sourceOf($get($row, 'source'));
            $url = mb_substr($get($row, 'url'), 0, 1000);
            $item = mb_substr($get($row, 'item'), 0, 1000);
            $tenant = mb_substr($get($row, 'tenant'), 0, 255);
            $rights = mb_substr($get($row, 'rights'), 0, 255);
            $type = self::typeOf($get($row, 'type'));
            $scope = Rules::SCOPE_SHARE;
            if ($linkScope !== '' && !in_array($linkScope, ['none', 'no', 'non', 'direct'], true)) {
                $scope = Rules::SCOPE_LINK;
            } elseif ($source === self::SOURCE_TEAMS) {
                $scope = Rules::SCOPE_MEMBER;
            }
            if ($linkScope === 'anonymous' || $linkScope === 'anyone') {
                $identity = self::ANYONE_LINK;
                $type = Rules::KIND_ANONYMOUS;
            } elseif ($linkScope === 'organization') {
                $identity = $identity !== '' ? $identity : self::ORGANIZATION_LINK;
                $type = Rules::KIND_BROAD;
            }
            $rows[] = [
                'source'       => $source,
                'server'       => $tenant !== '' ? $tenant : $defaultTenant,
                'share'        => $site,
                'share_path'   => $url,
                'folder'       => $item !== '' ? $item : ($url !== '' ? $url : $site),
                'depth'        => $item !== '' && $item !== $url ? 1 : 0,
                'scope'        => $scope,
                'identity'     => $identity,
                'sid'          => '',
                'type'         => $type,
                'rights'       => $rights,
                'level'        => self::roleLevel($rights),
                'is_deny'      => false,
                'is_inherited' => false,
                // Élément partagé à part : ses droits ne suivent plus ceux du site.
                'is_protected' => $item !== '' && $item !== $url,
                'owner'        => mb_substr($get($row, 'owner'), 0, 255),
            ];
        }

        return ['rows' => $rows, 'errors' => [], 'truncated' => $parsed['truncated']];
    }

    /**
     * Source d'une cellule (SharePoint par défaut).
     */
    public static function sourceOf(string $cell): string
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($cell));

        return match (true) {
            str_contains($key, 'onedrive') => self::SOURCE_ONEDRIVE,
            str_contains($key, 'team')     => self::SOURCE_TEAMS,
            default                        => self::SOURCE_SHAREPOINT,
        };
    }

    /**
     * Type de compte d'une cellule : external, anonymous, broad, group ou user.
     */
    public static function typeOf(string $cell): string
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($cell));

        return match (true) {
            in_array($key, ['guest', 'external', 'externaluser', 'invite', 'invit', 'externe'], true) => Rules::KIND_EXTERNAL,
            in_array($key, ['anonymous', 'anyone', 'anonyme'], true)                                  => Rules::KIND_ANONYMOUS,
            in_array($key, ['everyone', 'organization', 'toutlemonde', 'organisation'], true)         => Rules::KIND_BROAD,
            str_contains($key, 'group') || str_contains($key, 'groupe')                               => 'group',
            default                                                                                    => 'user',
        };
    }

    /**
     * @param array<string, mixed> $base
     * @return Row
     */
    private static function row(array $base, string $identity, string $sid, string $type): array
    {
        /** @var Row */
        return [
            'source'       => (string) $base['source'],
            'server'       => (string) $base['server'],
            'share'        => (string) $base['share'],
            'share_path'   => (string) $base['share_path'],
            'folder'       => (string) $base['folder'],
            'depth'        => (int) $base['depth'],
            'scope'        => (string) $base['scope'],
            'identity'     => $identity,
            'sid'          => $sid,
            'type'         => $type,
            'rights'       => (string) $base['rights'],
            'level'        => (string) $base['level'],
            'is_deny'      => false,
            'is_inherited' => (bool) $base['is_inherited'],
            'is_protected' => (bool) $base['is_protected'],
            'owner'        => (string) $base['owner'],
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
