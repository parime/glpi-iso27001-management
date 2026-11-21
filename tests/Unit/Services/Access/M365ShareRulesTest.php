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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\EntraGraphClient;
use GlpiPlugin\Grcmanager\Services\Access\M365ShareReader;
use GlpiPlugin\Grcmanager\Services\Access\M365ShareRules;
use GlpiPlugin\Grcmanager\Services\Access\ShareAccessRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #846, lot 2 : droits SharePoint, OneDrive et Teams (règles pures, faux Microsoft Graph en
 * mémoire, aucun appel réseau). Assertions sur des codes, jamais sur des textes traduits.
 */
final class M365ShareRulesTest extends TestCase
{
    private const DOMAINS = ['exemple.test', 'exemple.onmicrosoft.com'];

    private const CONTEXT = [
        'source' => 'sharepoint', 'server' => 'exemple.onmicrosoft.com', 'share' => 'Finance',
        'share_path' => 'https://exemple.sharepoint.com/sites/finance',
        'folder' => 'https://exemple.sharepoint.com/sites/finance', 'depth' => 0, 'is_protected' => false,
        'owner' => '',
    ];

    public function testRoleLevels(): void
    {
        $this->assertSame(Rules::LEVEL_FULL, M365ShareRules::roleLevel('owner'));
        $this->assertSame(Rules::LEVEL_FULL, M365ShareRules::roleLevel('sp.full control'));
        $this->assertSame(Rules::LEVEL_MODIFY, M365ShareRules::roleLevel('write'));
        $this->assertSame(Rules::LEVEL_MODIFY, M365ShareRules::roleLevel('edit'));
        $this->assertSame(Rules::LEVEL_READ, M365ShareRules::roleLevel('read'));
        $this->assertSame(Rules::LEVEL_READ, M365ShareRules::roleLevel('view'));
        $this->assertSame(Rules::LEVEL_OTHER, M365ShareRules::roleLevel(''));
    }

    public function testLinksBecomeAnonymousOrBroadAccounts(): void
    {
        $anonymous = M365ShareRules::permissionRows(
            ['roles' => ['read'], 'link' => ['scope' => 'anonymous', 'type' => 'view']],
            self::CONTEXT,
            self::DOMAINS
        );
        $this->assertCount(1, $anonymous);
        $this->assertSame(Rules::KIND_ANONYMOUS, $anonymous[0]['type']);
        $this->assertSame(Rules::SCOPE_LINK, $anonymous[0]['scope']);
        $this->assertSame([Rules::GAP_ANONYMOUS_LINK], Rules::aceGaps(['kind' => Rules::principalKind(
            $anonymous[0]['identity'],
            $anonymous[0]['sid'],
            $anonymous[0]['type']
        ), 'level' => $anonymous[0]['level'], 'scope' => $anonymous[0]['scope']]));

        $organization = M365ShareRules::permissionRows(
            ['roles' => ['write'], 'link' => ['scope' => 'organization', 'type' => 'edit']],
            self::CONTEXT,
            self::DOMAINS
        );
        $this->assertSame(Rules::KIND_BROAD, $organization[0]['type']);
        $this->assertSame(Rules::LEVEL_MODIFY, $organization[0]['level']);
        $this->assertSame([Rules::GAP_BROAD_WRITE], Rules::aceGaps([
            'kind' => Rules::principalKind('', '', $organization[0]['type']), 'level' => Rules::LEVEL_MODIFY,
        ]));
    }

    public function testSpecificPeopleLinkGivesOneRowPerPersonAndFlagsGuests(): void
    {
        $rows = M365ShareRules::permissionRows([
            'roles'                 => ['write'],
            'link'                  => ['scope' => 'users', 'type' => 'edit'],
            'grantedToIdentitiesV2' => [
                ['user' => ['id' => 'u1', 'displayName' => 'Interne', 'email' => 'interne@exemple.test']],
                ['user' => ['id' => 'u2', 'displayName' => 'Partenaire', 'email' => 'p@partenaire.test']],
                ['siteUser' => ['displayName' => 'Invite', 'loginName' => 'i:0#.f|membership|x_autre.test#ext#@exemple']],
            ],
        ], self::CONTEXT, self::DOMAINS);
        $this->assertSame(['user', Rules::KIND_EXTERNAL, Rules::KIND_EXTERNAL], array_column($rows, 'type'));
        $this->assertSame([Rules::GAP_EXTERNAL_GUEST], Rules::aceGaps(['kind' => Rules::KIND_EXTERNAL, 'level' => 'modify']));
    }

    public function testDirectPermissionsAndEveryoneGroups(): void
    {
        $group = M365ShareRules::permissionRows(
            ['roles' => ['write'], 'grantedToV2' => ['siteGroup' => ['id' => '5', 'displayName' => 'Finance Members']]],
            self::CONTEXT,
            self::DOMAINS
        );
        $this->assertSame('group', $group[0]['type']);
        $this->assertSame(Rules::SCOPE_SHARE, $group[0]['scope']);
        $everyone = M365ShareRules::permissionRows(
            ['roles' => ['read'], 'grantedToV2' => ['siteUser' => [
                'displayName' => 'Everyone except external users', 'loginName' => 'c:0-.f|rolemanager|spo-grid-all-users/x',
            ]]],
            self::CONTEXT,
            self::DOMAINS
        );
        $this->assertSame(Rules::KIND_BROAD, $everyone[0]['type']);
        // Invitation pas encore acceptée.
        $invited = M365ShareRules::permissionRows(
            ['roles' => ['read'], 'invitation' => ['email' => 'quelqu.un@ailleurs.test']],
            self::CONTEXT,
            self::DOMAINS
        );
        $this->assertSame(Rules::KIND_EXTERNAL, $invited[0]['type']);
    }

    public function testTeamRows(): void
    {
        $rows = M365ShareRules::teamRows(
            'exemple.onmicrosoft.com',
            ['id' => 't1', 'displayName' => 'Projet Atlas', 'visibility' => 'Public', 'mail' => 'atlas@exemple.test'],
            [['id' => 'o1', 'userPrincipalName' => 'chef@exemple.test', 'userType' => 'Member']],
            [
                ['id' => 'o1', 'userPrincipalName' => 'chef@exemple.test', 'userType' => 'Member'],
                ['id' => 'm1', 'userPrincipalName' => 'membre@exemple.test', 'userType' => 'Member'],
                ['id' => 'g1', 'userPrincipalName' => 'c_partenaire.test#EXT#@exemple.onmicrosoft.com', 'mail' => 'c@partenaire.test', 'userType' => 'Guest'],
            ],
            self::DOMAINS
        );
        $this->assertCount(4, $rows);
        $this->assertSame([Rules::LEVEL_FULL, Rules::LEVEL_MODIFY, Rules::LEVEL_MODIFY, Rules::LEVEL_MODIFY], array_column($rows, 'level'));
        $this->assertSame(['user', 'user', Rules::KIND_EXTERNAL, Rules::KIND_BROAD], array_column($rows, 'type'));
        $this->assertSame('chef@exemple.test', $rows[0]['owner']);
        // Un membre nommé d'une équipe n'est pas un « droit nominatif ».
        $this->assertSame([], Rules::aceGaps(['kind' => Rules::KIND_USER, 'level' => 'modify', 'scope' => Rules::SCOPE_MEMBER]));
        $this->assertSame([Rules::GAP_DIRECT_USER], Rules::aceGaps(['kind' => Rules::KIND_USER, 'level' => 'modify', 'scope' => Rules::SCOPE_LINK]));
    }

    public function testParseCsv(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/m365-share-access-example.csv');
        $parsed = M365ShareRules::parseCsv($raw);
        $this->assertSame([], $parsed['errors']);
        $this->assertCount(7, $parsed['rows']);
        $this->assertSame(
            ['sharepoint', 'sharepoint', 'sharepoint', 'sharepoint', 'onedrive', 'teams', 'teams'],
            array_column($parsed['rows'], 'source')
        );
        $this->assertSame(
            ['group', Rules::KIND_BROAD, Rules::KIND_ANONYMOUS, Rules::KIND_EXTERNAL, Rules::KIND_BROAD, 'user', Rules::KIND_EXTERNAL],
            array_column($parsed['rows'], 'type')
        );
        $this->assertSame([0, 0, 1, 1, 1, 0, 0], array_column($parsed['rows'], 'depth'));
        $this->assertSame(Rules::SCOPE_MEMBER, $parsed['rows'][6]['scope']);
        $this->assertSame(Rules::SCOPE_LINK, $parsed['rows'][2]['scope']);
        $this->assertSame(['missing_site'], M365ShareRules::parseCsv("Identity,Roles\na,read\n")['errors']);
        $this->assertSame(['missing_rights'], M365ShareRules::parseCsv("Site,Identity\na,b\n")['errors']);
    }

    /**
     * @param array<string, list<array<string, mixed>>|int> $routes fin d'adresse => éléments, ou statut HTTP
     */
    private static function client(array $routes, int &$gets = 0): EntraGraphClient
    {
        return new EntraGraphClient(
            'exemple.onmicrosoft.com',
            '11111111-2222-3333-4444-555555555555',
            'secret-fictif',
            static function (string $method, string $url, array $headers, ?string $body) use ($routes, &$gets): array {
                if ($method === 'POST') {
                    return ['status' => 200, 'body' => '{"access_token":"jeton-fictif"}', 'retry_after' => 0];
                }
                $gets++;
                $path = (string) parse_url($url, PHP_URL_PATH);
                foreach ($routes as $suffix => $answer) {
                    if (str_ends_with($path, $suffix)) {
                        return is_int($answer)
                            ? ['status' => $answer, 'body' => '', 'retry_after' => 0]
                            : ['status' => 200, 'body' => (string) json_encode(['value' => $answer]), 'retry_after' => 0];
                    }
                }

                return ['status' => 200, 'body' => '{"value":[]}', 'retry_after' => 0];
            }
        );
    }

    public function testReaderSharePoint(): void
    {
        $gets = 0;
        $client = self::client([
            '/organization' => [['verifiedDomains' => [['name' => 'exemple.test']]]],
            '/sites'        => [
                ['id' => 's1', 'displayName' => 'Finance', 'webUrl' => 'https://exemple.sharepoint.com/sites/finance'],
                ['id' => 's2', 'displayName' => 'Paul', 'webUrl' => 'https://exemple-my.sharepoint.com/personal/paul'],
                ['id' => 's3', 'displayName' => 'Sans bibliotheque', 'webUrl' => 'https://exemple.sharepoint.com/sites/vide'],
            ],
            '/sites/s1/drive/root/permissions' => [
                ['roles' => ['write'], 'grantedToV2' => ['siteGroup' => ['id' => '5', 'displayName' => 'Finance Members']]],
            ],
            '/sites/s1/drive/root/children' => [
                ['id' => 'i1', 'name' => 'Budget', 'webUrl' => 'https://exemple.sharepoint.com/sites/finance/Budget', 'shared' => [], 'parentReference' => ['driveId' => 'd1']],
                ['id' => 'i2', 'name' => 'Prive', 'parentReference' => ['driveId' => 'd1']],
            ],
            '/drives/d1/items/i1/permissions' => [
                ['roles' => ['read'], 'link' => ['scope' => 'anonymous', 'type' => 'view']],
                ['roles' => ['write'], 'inheritedFrom' => ['id' => 'root'], 'grantedToV2' => ['siteGroup' => ['displayName' => 'Finance Members']]],
            ],
            '/sites/s3/drive/root/permissions' => 404,
        ], $gets);
        $read = (new M365ShareReader($client, 'exemple.onmicrosoft.com'))->read(M365ShareRules::SOURCE_SHAREPOINT);
        $this->assertSame('', $read['error']);
        $this->assertCount(2, $read['rows']);
        $this->assertSame(['group', Rules::KIND_ANONYMOUS], array_column($read['rows'], 'type'));
        $this->assertSame([0, 1], array_column($read['rows'], 'depth'));
        $this->assertTrue($read['rows'][1]['is_protected']);
        $this->assertSame(6, $gets, 'Le site personnel est ignoré, le site sans bibliothèque passé.');
    }

    public function testReaderTeamsAndErrors(): void
    {
        $client = self::client([
            '/groups'          => [['id' => 't1', 'displayName' => 'Atlas', 'visibility' => 'Private']],
            '/groups/t1/owners' => [['id' => 'o1', 'userPrincipalName' => 'chef@exemple.test']],
            '/groups/t1/members' => [['id' => 'g1', 'userPrincipalName' => 'x#EXT#@exemple.onmicrosoft.com', 'userType' => 'Guest']],
        ]);
        $read = (new M365ShareReader($client, 'exemple.onmicrosoft.com'))->read(M365ShareRules::SOURCE_TEAMS);
        $this->assertSame(['user', Rules::KIND_EXTERNAL], array_column($read['rows'], 'type'));

        $forbidden = self::client(['/sites' => 403]);
        $read = (new M365ShareReader($forbidden, 'exemple.onmicrosoft.com'))->read(M365ShareRules::SOURCE_SHAREPOINT);
        $this->assertSame(EntraGraphClient::ERROR_FORBIDDEN, $read['error']);
        $this->assertSame([], $read['rows']);

        $unknown = (new M365ShareReader($forbidden, 'exemple.onmicrosoft.com'))->read('ftp');
        $this->assertSame(EntraGraphClient::ERROR_INVALID, $unknown['error']);
    }

    public function testCollectionStaysOnGraph(): void
    {
        $client = self::client([]);
        $this->assertSame(EntraGraphClient::ERROR_INVALID, $client->fetchCollection('https://ailleurs.test/v1.0/sites')['error']);
        $this->assertSame(0, $client->requests());
    }
}
