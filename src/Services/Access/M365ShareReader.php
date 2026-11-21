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

/**
 * Issue #846, lot 2 : lecture SEULE, sur clic, des droits de SharePoint, OneDrive et Teams par
 * Microsoft Graph, avec le client et la configuration de la couverture MFA (#852).
 *
 * - SharePoint : sites (sites?search=*, sauf les OneDrive), droits de la bibliothèque de documents
 *   et des éléments de premier niveau partagés à part.
 * - OneDrive : éléments de premier niveau partagés de chaque compte membre (un OneDrive sans
 *   partage n'est pas listé ; les droits du titulaire sont ignorés).
 * - Teams : équipes, propriétaires, membres et invités, visibilité publique.
 *
 * Permissions d'application à accorder (lecture seule) : Sites.Read.All, Files.Read.All,
 * User.Read.All, GroupMember.Read.All. Seulement des GET ; au plus MAX_REQUESTS requêtes par
 * lecture, au-delà la lecture est incomplète et rien n'est remplacé (import CSV à la place).
 *
 * @phpstan-import-type Row from M365ShareRules
 */
final class M365ShareReader
{
    public const MAX_REQUESTS = 400;

    /** Éléments partagés lus au plus par bibliothèque. */
    public const MAX_SHARED_ITEMS = 25;

    private const V1 = EntraGraphClient::GRAPH_BASE . 'v1.0/';

    private const ITEM_SELECT = '?$select=id,name,webUrl,shared,parentReference&$top=200';

    /** @var list<string> */
    private array $domains = [];

    private string $error = '';

    public function __construct(private readonly EntraGraphClient $client, private readonly string $server)
    {
    }

    /**
     * @return array{rows: list<Row>, error: string}
     */
    public function read(string $source): array
    {
        if (!M365ShareRules::isSource($source)) {
            return ['rows' => [], 'error' => EntraGraphClient::ERROR_INVALID];
        }
        $this->error = '';
        $organization = $this->collection(self::V1 . 'organization?$select=verifiedDomains', 1);
        foreach ($organization ?? [] as $org) {
            foreach (is_array($org['verifiedDomains'] ?? null) ? $org['verifiedDomains'] : [] as $domain) {
                if (is_array($domain) && is_string($domain['name'] ?? null)) {
                    $this->domains[] = mb_strtolower($domain['name']);
                }
            }
        }
        if ($this->error() !== '') {
            return ['rows' => [], 'error' => $this->error()];
        }
        $rows = match ($source) {
            M365ShareRules::SOURCE_SHAREPOINT => $this->sharePoint(),
            M365ShareRules::SOURCE_ONEDRIVE   => $this->oneDrive(),
            default                           => $this->teams(),
        };

        return $this->error() !== '' ? ['rows' => [], 'error' => $this->error()] : ['rows' => $rows, 'error' => ''];
    }

    /**
     * Erreur de la lecture en cours (posée par collection()).
     *
     * @phpstan-impure
     */
    private function error(): string
    {
        return $this->error;
    }

    /**
     * @return list<Row>
     */
    private function sharePoint(): array
    {
        $rows = [];
        $names = [];
        foreach ($this->collection(self::V1 . 'sites?search=*&$select=id,displayName,name,webUrl', 20) ?? [] as $site) {
            $id = self::text($site['id'] ?? '');
            $url = self::text($site['webUrl'] ?? '');
            if ($id === '' || str_contains(mb_strtolower($url), '/personal/')) {
                continue;
            }
            $name = self::text($site['displayName'] ?? '') ?: self::text($site['name'] ?? '') ?: $url;
            // Deux sites du même nom : l'adresse les distingue.
            $name = isset($names[mb_strtolower($name)]) ? $name . ' (' . $url . ')' : $name;
            $names[mb_strtolower($name)] = true;
            $context = [
                'source'       => M365ShareRules::SOURCE_SHAREPOINT,
                'server'       => $this->server,
                'share'        => mb_substr($name, 0, 255),
                'share_path'   => mb_substr($url, 0, 1000),
                'folder'       => mb_substr($url, 0, 1000),
                'depth'        => 0,
                'is_protected' => false,
                'owner'        => '',
            ];
            $drive = self::V1 . 'sites/' . rawurlencode($id) . '/drive/root';
            $siteRows = $this->driveRows($drive, $context, '');
            if ($siteRows === null) {
                break;
            }
            $rows = array_merge($rows, $siteRows);
        }

        return $rows;
    }

    /**
     * @return list<Row>
     */
    private function oneDrive(): array
    {
        $rows = [];
        $users = $this->collection(self::V1 . 'users?$select=id,displayName,userPrincipalName,userType&$top=200', 20);
        foreach ($users ?? [] as $user) {
            $id = self::text($user['id'] ?? '');
            $upn = self::text($user['userPrincipalName'] ?? '');
            if ($id === '' || $upn === '' || mb_strtolower(self::text($user['userType'] ?? '')) === 'guest') {
                continue;
            }
            $name = 'OneDrive - ' . (self::text($user['displayName'] ?? '') ?: $upn);
            $context = [
                'source'       => M365ShareRules::SOURCE_ONEDRIVE,
                'server'       => $this->server,
                'share'        => mb_substr($name, 0, 255),
                'share_path'   => '',
                'folder'       => '',
                'depth'        => 0,
                'is_protected' => false,
                'owner'        => mb_substr($upn, 0, 255),
            ];
            $root = self::V1 . 'users/' . rawurlencode($id) . '/drive/root';
            $driveRows = $this->driveRows($root, $context, $upn, false);
            if ($driveRows === null) {
                break;
            }
            $rows = array_merge($rows, $driveRows);
        }

        return $rows;
    }

    /**
     * @return list<Row>
     */
    private function teams(): array
    {
        $filter = rawurlencode("resourceProvisioningOptions/Any(x:x eq 'Team')");
        $teams = $this->collection(
            self::V1 . 'groups?$filter=' . $filter . '&$select=id,displayName,visibility,mail&$count=true&$top=200',
            20,
            ['ConsistencyLevel' => 'eventual']
        );
        $rows = [];
        $select = '?$select=id,displayName,userPrincipalName,mail,userType&$top=200';
        foreach ($teams ?? [] as $team) {
            $id = self::text($team['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $base = self::V1 . 'groups/' . rawurlencode($id);
            $owners = $this->collection($base . '/owners' . $select, 5);
            $members = $owners === null ? null : $this->collection($base . '/members' . $select, 20);
            if ($owners === null || $members === null) {
                break;
            }
            $rows = array_merge(
                $rows,
                M365ShareRules::teamRows($this->server, $team, $owners, $members, $this->domains)
            );
        }

        return $rows;
    }

    /**
     * Droits d'une bibliothèque : ceux de la racine ($withRoot), puis ceux des éléments de premier
     * niveau partagés à part. Null si la lecture doit s'arrêter ; bibliothèque absente : liste vide.
     *
     * @param array{source: string, server: string, share: string, share_path: string, folder: string,
     *     depth: int, is_protected: bool, owner: string} $context
     * @return list<Row>|null
     */
    private function driveRows(string $root, array $context, string $driveOwner, bool $withRoot = true): ?array
    {
        $rows = [];
        if ($withRoot) {
            $permissions = $this->collection($root . '/permissions', 5);
            if ($permissions === null) {
                return $this->error === EntraGraphClient::ERROR_NOT_FOUND ? $this->skip() : null;
            }
            foreach ($permissions as $permission) {
                $rows = array_merge($rows, M365ShareRules::permissionRows($permission, $context, $this->domains));
            }
        }
        $children = $this->collection($root . '/children' . self::ITEM_SELECT, 1);
        if ($children === null) {
            return $this->error === EntraGraphClient::ERROR_NOT_FOUND ? $this->skip($rows) : null;
        }
        $shared = 0;
        foreach ($children as $item) {
            $itemId = self::text($item['id'] ?? '');
            $parent = is_array($item['parentReference'] ?? null) ? $item['parentReference'] : [];
            $driveId = self::text($parent['driveId'] ?? '');
            if (!isset($item['shared']) || $itemId === '' || $driveId === '' || ++$shared > self::MAX_SHARED_ITEMS) {
                continue;
            }
            $itemUrl = self::text($item['webUrl'] ?? '') ?: self::text($item['name'] ?? '');
            $permissions = $this->collection(
                self::V1 . 'drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId) . '/permissions',
                5
            );
            if ($permissions === null) {
                if ($this->error === EntraGraphClient::ERROR_NOT_FOUND) {
                    $this->error = '';
                    continue;
                }

                return null;
            }
            $itemContext = ['folder' => mb_substr($itemUrl, 0, 1000), 'depth' => 1, 'is_protected' => true] + $context;
            foreach ($permissions as $permission) {
                // Droits hérités de la bibliothèque : déjà lus sur la racine.
                if (is_array($permission['inheritedFrom'] ?? null) && $permission['inheritedFrom'] !== []) {
                    continue;
                }
                foreach (M365ShareRules::permissionRows($permission, $itemContext, $this->domains) as $row) {
                    // OneDrive : le titulaire est propriétaire de tous ses fichiers, ce n'est pas un écart.
                    if ($driveOwner !== '' && mb_strtolower($row['identity']) === mb_strtolower($driveOwner)) {
                        continue;
                    }
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Ressource absente (site sans bibliothèque, compte sans OneDrive) : on passe à la suivante.
     *
     * @param list<Row> $rows
     * @return list<Row>
     */
    private function skip(array $rows = []): array
    {
        $this->error = '';

        return $rows;
    }

    /**
     * Collection complète, ou null en cas d'erreur (posée dans $this->error) ou de budget épuisé.
     *
     * @param array<string, string> $headers
     * @return list<array<string, mixed>>|null
     */
    private function collection(string $url, int $maxPages, array $headers = []): ?array
    {
        $left = self::MAX_REQUESTS - $this->client->requests();
        if ($left <= 0) {
            $this->error = EntraGraphClient::ERROR_TOO_LARGE;

            return null;
        }
        $result = $this->client->fetchCollection($url, min($maxPages, $left), $headers);
        if ($result['error'] !== '') {
            $this->error = $result['error'];

            return null;
        }
        if (!$result['complete']) {
            $this->error = EntraGraphClient::ERROR_TOO_LARGE;

            return null;
        }

        return $result['items'];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
