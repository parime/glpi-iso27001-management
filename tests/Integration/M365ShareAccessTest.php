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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Install\ShareAccessTables;
use GlpiPlugin\Grcmanager\Services\Access\EntraGraphClient;
use GlpiPlugin\Grcmanager\Services\Access\ShareAccessRules as Rules;
use GlpiPlugin\Grcmanager\ShareAccess;

/**
 * Issue #846, lot 2 : droits SharePoint, OneDrive et Teams (export CSV fictif, faux Microsoft Graph
 * en mémoire), dans une entité de test seule active. Assertions sur des codes, pas sur des textes.
 */
final class M365ShareAccessTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private string $suffix;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo M365 ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;
        ShareAccess::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        ShareAccess::reset();
        parent::tearDown();
    }

    private function tenant(): string
    {
        return 'demo' . $this->suffix . '.onmicrosoft.com';
    }

    /**
     * @return array<string, mixed>
     */
    private function share(string $source, string $name): array
    {
        foreach (ShareAccess::shares() as $share) {
            if ($share['source'] === $source && $share['name'] === $name) {
                return $share;
            }
        }
        $this->fail('Site absent : ' . $name);
    }

    public function testCsvImportFlagsPublicLinksGuestsAndEveryone(): void
    {
        $raw = str_replace(
            'exemple.onmicrosoft.com',
            $this->tenant(),
            (string) file_get_contents(dirname(__DIR__, 2) . '/resources/m365-share-access-example.csv')
        );
        $result = ShareAccess::importM365Csv($raw);
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['shares']);
        $this->assertSame(7, $result['aces']);

        $finance = $this->share('sharepoint', 'Direction financiere');
        $this->assertSame('Direction financiere', $finance['label']);
        $this->assertSame(1, $finance['gaps'][Rules::GAP_ANONYMOUS_LINK] ?? 0);
        $this->assertSame(1, $finance['gaps'][Rules::GAP_EXTERNAL_GUEST] ?? 0);
        $this->assertGreaterThan(0, $finance['score']);
        $onedrive = $this->share('onedrive', 'OneDrive - Paul Martin');
        $this->assertSame(1, $onedrive['gaps'][Rules::GAP_BROAD_WRITE] ?? 0);
        $team = $this->share('teams', 'Projet Atlas');
        $this->assertSame(1, $team['gaps'][Rules::GAP_EXTERNAL_GUEST] ?? 0);
        $this->assertArrayNotHasKey(Rules::GAP_DIRECT_USER, $team['gaps'], 'Membre nommé : pas un écart.');

        $kinds = array_column(ShareAccess::aces((int) $finance['id']), 'kind');
        $this->assertContains(Rules::KIND_ANONYMOUS, $kinds);
        $this->assertContains(Rules::KIND_EXTERNAL, $kinds);

        // Même revue que les partages : lancée par le gestionnaire, décisions en attente.
        $this->assertSame('no_owner', ShareAccess::startReview((int) $finance['id']));
        global $DB;
        $owner = ['users_id_owner' => (int) \Session::getLoginUserID()];
        $DB->update(ShareAccessTables::SHARES, $owner, ['id' => $finance['id']]);
        ShareAccess::reset();
        $this->assertSame('', ShareAccess::startReview((int) $finance['id']));
        $pending = array_filter(
            ShareAccess::aces((int) $finance['id']),
            static fn (array $ace): bool => $ace['decision'] === 'pending'
        );
        $this->assertCount(4, $pending);

        // Rendu des pages.
        $renderer = \Glpi\Application\View\TemplateRenderer::getInstance();
        $html = $renderer->render('@grcmanager/share_access.html.twig', ShareAccess::pageData());
        $this->assertStringContainsString('id="grc-share-m365"', $html);
        $this->assertStringContainsString('name="import_m365_shares"', $html);
        $this->assertStringContainsString('?example=m365', $html);
        ShareAccess::reset();
        $html = $renderer->render(
            '@grcmanager/share_access_detail.html.twig',
            ShareAccess::shareData($this->share('sharepoint', 'Direction financiere'))
        );
        $this->assertStringContainsString('expert.comptable@cabinet-partenaire.test', $html);

        // Effacement par source : les partages Windows ne sont pas touchés.
        $this->assertFalse(ShareAccess::clear($this->tenant(), 'smb'));
        $this->assertTrue(ShareAccess::clear($this->tenant(), 'teams'));
        $this->assertTrue(ShareAccess::clear($this->tenant(), 'sharepoint'));
        $this->assertTrue(ShareAccess::clear($this->tenant(), 'onedrive'));
        $this->assertFalse(ShareAccess::clear($this->tenant(), 'ftp'));
        $this->assertSame(0, countElementsInTable(ShareAccessTables::SHARES, ['server' => $this->tenant()]));
    }

    public function testGraphReadReplacesOnlyWhenComplete(): void
    {
        $calls = [];
        $transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
            $calls[] = $method;
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{"access_token":"jeton-fictif"}', 'retry_after' => 0];
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            $team = ['id' => 't1', 'displayName' => 'Equipe demo', 'visibility' => 'Public'];
            $owner = ['id' => 'o1', 'userPrincipalName' => 'chef@exemple.test'];
            $value = match (true) {
                str_ends_with($path, '/groups')           => [$team],
                str_ends_with($path, '/groups/t1/owners') => [$owner],
                default                                   => [],
            };

            return ['status' => 200, 'body' => (string) json_encode(['value' => $value]), 'retry_after' => 0];
        };
        $client = new EntraGraphClient(
            'exemple.onmicrosoft.com',
            '11111111-2222-3333-4444-555555555555',
            'secret-fictif',
            $transport
        );
        $result = ShareAccess::importFromGraph('teams', $client);
        $this->assertSame('', $result['graph_error']);
        $this->assertSame(1, $result['shares']);
        $this->assertNotContains('PUT', $calls);
        $this->assertNotContains('PATCH', $calls);
        $this->assertNotContains('DELETE', $calls);
        $this->assertSame(['POST'], array_values(array_filter($calls, static fn (string $m): bool => $m !== 'GET')));
        $team = null;
        foreach (ShareAccess::shares() as $share) {
            $team = $share['source'] === 'teams' && $share['name'] === 'Equipe demo' ? $share : $team;
        }
        $this->assertNotNull($team);
        $this->assertSame(1, $team['gaps'][Rules::GAP_BROAD_WRITE] ?? 0, 'Équipe publique : écriture pour tous.');

        // Erreur de Graph : rien n'est remplacé.
        $denied = new EntraGraphClient(
            'exemple.onmicrosoft.com',
            '11111111-2222-3333-4444-555555555555',
            'secret-fictif',
            static fn (string $method): array => $method === 'POST'
                ? ['status' => 200, 'body' => '{"access_token":"jeton-fictif"}', 'retry_after' => 0]
                : ['status' => 403, 'body' => '', 'retry_after' => 0]
        );
        $result = ShareAccess::importFromGraph('teams', $denied);
        $this->assertSame(EntraGraphClient::ERROR_FORBIDDEN, $result['graph_error']);
        ShareAccess::reset();
        $this->assertSame(1, count(array_filter(
            ShareAccess::shares(),
            static fn (array $share): bool => $share['source'] === 'teams' && $share['is_present']
        )));
        ShareAccess::clear((string) $team['server'], 'teams');
    }
}
