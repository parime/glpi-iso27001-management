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
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use GlpiPlugin\Grcmanager\Services\Access\ShareAccessRules as Rules;
use GlpiPlugin\Grcmanager\ShareAccess;

/**
 * Issue #846 : droits sur les partages de fichiers Windows, sur des exports fictifs dans une entité
 * de test (seule entité active) et une entité cachée.
 */
final class ShareAccessTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private int $hiddenEntity;

    private string $suffix;

    /** @var array<string, int> */
    private array $users = [];

    private int $group;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        ShareAccess::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
            'glpi_currenttime'          => $_SESSION['glpi_currenttime'] ?? null,
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Partages ' . $this->suffix);
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo Partages cachée ' . $this->suffix);
        $this->activate($this->entity);
        $_SESSION['glpi_currenttime'] = '2026-03-02 09:00:00';

        global $DB;
        foreach (['owner', 'alice', 'gone'] as $key) {
            $id = $this->createTestUser('Demo', $key . $this->suffix, ['name' => $key . '.' . $this->suffix]);
            $this->assertGreaterThan(0, $id);
            $DB->delete('glpi_profiles_users', ['users_id' => $id]);
            $DB->insert('glpi_profiles_users', [
                'users_id' => $id, 'profiles_id' => 1, 'entities_id' => $this->entity, 'is_recursive' => 0,
            ]);
            $this->users[$key] = $id;
        }
        // Compte désactivé : la personne est partie, ses droits restent.
        $DB->update('glpi_users', ['is_active' => 0], ['id' => $this->users['gone']]);
        $this->group = (int) (new \Group())->add([
            'name' => 'GG_Compta_' . $this->suffix, 'entities_id' => $this->entity, 'is_recursive' => 0,
        ]);
        $this->assertGreaterThan(0, $this->group);
        $DB->insert('glpi_groups_users', ['users_id' => $this->users['owner'], 'groups_id' => $this->group]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        ShareAccess::reset();
        parent::tearDown();
    }

    private function activate(int $entity): void
    {
        $_SESSION['glpiactiveentities'] = [$entity];
        $_SESSION['glpiactiveentities_string'] = (string) $entity;
        $_SESSION['glpiactive_entity'] = $entity;
        ShareAccess::reset();
    }

    private function server(): string
    {
        return 'fs' . $this->suffix . '.exemple.test';
    }

    private function csv(bool $second = false): string
    {
        $s = $this->server();
        $root = '\\\\' . $s . '\\Compta';
        $lines = [
            'Server,Share,Folder,Depth,Scope,Identity,Sid,PrincipalType,Rights,AccessType,IsInherited,'
                . 'InheritanceProtected,Owner',
            "$s,Compta,$root,0,Share,Everyone,S-1-1-0,,Change,Allow,False,False,",
            "$s,Compta,$root,0,NTFS,EXEMPLE\\GG_Compta_{$this->suffix},,,\"Modify, Synchronize\",Allow,False,False,"
                . 'BUILTIN\\Administrators',
            "$s,Compta,$root,0,NTFS,NT AUTHORITY\\SYSTEM,S-1-5-18,,FullControl,Allow,False,False,"
                . 'BUILTIN\\Administrators',
            "$s,Compta,$root\\Paie,1,NTFS,EXEMPLE\\alice.{$this->suffix},,,FullControl,Allow,False,True,",
            "$s,Compta,$root\\Paie,1,NTFS,EXEMPLE\\gone.{$this->suffix},,,Read,Allow,False,True,",
            "$s,Compta,$root\\Paie,1,NTFS,S-1-5-21-1111-2222-3333-1188,,,Read,Allow,False,True,",
            "$s,Public,\\\\$s\\Public,0,NTFS,EXEMPLE\\GG_Compta_{$this->suffix},,,Read,Allow,False,False,",
        ];
        if ($second) {
            // Le droit de « Tout le monde » a été retiré, un nouveau droit nominatif est apparu ; le
            // partage Public a disparu.
            unset($lines[1], $lines[7]);
            $lines[] = "$s,Compta,$root,0,NTFS,EXEMPLE\\owner.{$this->suffix},,,Read,Allow,False,False,";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function share(string $name): array
    {
        foreach (ShareAccess::shares() as $share) {
            if ($share['name'] === $name && $share['server'] === $this->server()) {
                return $share;
            }
        }
        $this->fail('Partage absent : ' . $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function ace(int $shareId, string $identityEnd): array
    {
        foreach (ShareAccess::aces($shareId) as $ace) {
            if (!$ace['is_removed'] && str_ends_with($ace['identity'], $identityEnd)) {
                return $ace;
            }
        }
        $this->fail('Droit absent : ' . $identityEnd);
    }

    public function testImportMatchesAccountsAndFlagsGaps(): void
    {
        $result = ShareAccess::import($this->csv());
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['shares']);
        $this->assertSame(7, $result['aces']);
        $this->assertSame(7, $result['added'], 'Premier import : tout est nouveau.');
        $this->assertSame(4, $result['matched'], 'Le groupe (deux fois), alice et le compte désactivé.');

        $compta = $this->share('Compta');
        $this->assertSame(6, $compta['aces']);
        $this->assertSame(1, $compta['gaps'][Rules::GAP_BROAD_WRITE]);
        $this->assertSame(1, $compta['gaps'][Rules::GAP_DELETED_ACCOUNT], 'SID non résolu.');
        $this->assertSame(1, $compta['gaps'][Rules::GAP_DISABLED_ACCOUNT]);
        $this->assertSame(2, $compta['gaps'][Rules::GAP_DIRECT_USER], 'alice et le compte désactivé.');
        $this->assertSame(1, $compta['gaps'][Rules::GAP_NO_OWNER]);
        $this->assertArrayNotHasKey(Rules::GAP_BROKEN_INHERITANCE, $compta['gaps'], 'Partage non classé.');

        $group = $this->ace($compta['id'], 'GG_Compta_' . $this->suffix);
        $this->assertSame(Rules::KIND_GROUP, $group['kind']);
        $this->assertSame([], $group['gaps']);
        $alice = $this->ace($compta['id'], 'alice.' . $this->suffix);
        $this->assertSame(Rules::KIND_USER, $alice['kind']);
        $this->assertNotNull($alice['user']);
        $this->assertSame(Rules::KIND_SYSTEM, $this->ace($compta['id'], 'SYSTEM')['kind']);
        $this->assertFalse($alice['is_new'], 'Premier import : rien n\'est signalé comme nouveau.');

        // Classification élevée : héritage cassé signalé, score pondéré multiplié par 3.
        $before = $compta['score'];
        $this->assertTrue(ShareAccess::updateShare($compta['id'], [
            'confidentiality' => 'high', 'integrity' => 'bogus', 'users_id_owner' => (string) $this->users['owner'],
            'comment' => 'Données de paie fictives',
        ]));
        $compta = $this->share('Compta');
        $this->assertSame('high', $compta['classification']['confidentiality']);
        $this->assertSame('', $compta['classification']['integrity'], 'Valeur inconnue ignorée.');
        $this->assertSame(1, $compta['gaps'][Rules::GAP_BROKEN_INHERITANCE]);
        $this->assertArrayNotHasKey(Rules::GAP_NO_OWNER, $compta['gaps']);
        $this->assertGreaterThan($before, $compta['score']);
        $this->assertSame('high', $compta['score_level']);

        $data = ShareAccess::pageData();
        $this->assertSame($compta['id'], $data['rows'][0]['id'], 'Le partage le plus exposé d\'abord.');
        $this->assertSame(2, $data['summary']['shares']);
        $this->assertSame([$this->server()], $data['servers']);

        // Entité cachée : rien de visible.
        $this->activate($this->hiddenEntity);
        $this->assertSame([], ShareAccess::shares());
        $this->assertNull(ShareAccess::getShare($compta['id']));
        $this->assertSame([], ShareAccess::aces($compta['id']));
        $this->assertFalse(ShareAccess::updateShare($compta['id'], ['confidentiality' => 'low']));
    }

    public function testSecondImportTracksChangesAndKeepsDecisions(): void
    {
        ShareAccess::import($this->csv());
        $compta = $this->share('Compta');
        ShareAccess::updateShare(
            $compta['id'],
            ['users_id_owner' => $this->users['owner'], 'confidentiality' => 'medium']
        );
        $this->assertSame('', ShareAccess::startReview($compta['id']));
        $group = $this->ace($compta['id'], 'GG_Compta_' . $this->suffix);
        $this->assertSame('', ShareAccess::decide($group['id'], AccessReviewLogic::DECISION_KEEP, ''));

        $_SESSION['glpi_currenttime'] = '2026-04-02 09:00:00';
        $result = ShareAccess::import($this->csv(true));
        $this->assertSame(1, $result['added']);
        $this->assertSame(2, $result['removed'], 'Le droit de Tout le monde et le droit du partage disparu.');

        $compta = $this->share('Compta');
        $this->assertSame('medium', $compta['classification']['confidentiality'], 'Classification gardée.');
        $this->assertSame($this->users['owner'], $compta['users_id_owner']);
        $this->assertArrayNotHasKey(Rules::GAP_BROAD_WRITE, $compta['gaps']);
        $this->assertSame(1, $compta['removed']);
        $kept = $this->ace($compta['id'], 'GG_Compta_' . $this->suffix);
        $this->assertSame(AccessReviewLogic::DECISION_KEEP, $kept['decision']);
        $new = $this->ace($compta['id'], 'owner.' . $this->suffix);
        $this->assertTrue($new['is_new']);
        $this->assertSame(AccessReviewLogic::DECISION_PENDING, $new['decision'], 'Revue ouverte : à revoir.');
        $this->assertFalse($this->share('Public')['is_present']);
        $this->assertSame([], $this->share('Public')['gaps']);

        $imports = ShareAccess::imports();
        $this->assertCount(2, $imports);
        $this->assertSame(1, $imports[0]['added']);
        $this->assertSame(2, $imports[0]['removed']);

        // Troisième import identique : les droits retirés la fois précédente sont effacés.
        $_SESSION['glpi_currenttime'] = '2026-05-02 09:00:00';
        $result = ShareAccess::import($this->csv(true));
        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(0, $this->share('Compta')['removed']);
    }

    public function testReviewByTheOwner(): void
    {
        ShareAccess::import($this->csv());
        $compta = $this->share('Compta');
        $this->assertSame('no_owner', ShareAccess::startReview($compta['id']));
        ShareAccess::updateShare($compta['id'], ['users_id_owner' => $this->users['owner']]);
        $ace = $this->ace($compta['id'], 'alice.' . $this->suffix);
        $this->assertSame(
            'rights',
            ShareAccess::decide($ace['id'], AccessReviewLogic::DECISION_KEEP, ''),
            'Pas de revue.'
        );

        $this->assertSame('', ShareAccess::startReview($compta['id']));
        $this->assertSame(6, $this->share('Compta')['pending']);
        $this->assertSame('decision', ShareAccess::decide($ace['id'], 'bogus', ''));
        $this->assertSame('comment', ShareAccess::decide($ace['id'], AccessReviewLogic::DECISION_REMOVE, ' '));
        $this->assertSame('pending', ShareAccess::closeReview($compta['id']));

        // Le propriétaire désigné décide, même sans le droit de modifier.
        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $savedId = $_SESSION['glpiID'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        $_SESSION['glpiID'] = $this->users['owner'];
        try {
            $decision = ShareAccess::decide($ace['id'], AccessReviewLogic::DECISION_REMOVE, 'Passer par le groupe');
            $this->assertSame('', $decision);
            foreach (ShareAccess::aces($compta['id']) as $other) {
                if ($other['decision'] === AccessReviewLogic::DECISION_PENDING) {
                    $this->assertSame('', ShareAccess::decide($other['id'], AccessReviewLogic::DECISION_KEEP, ''));
                }
            }
            $this->assertSame('', ShareAccess::closeReview($compta['id']));
            $this->assertSame('rights', ShareAccess::startReview($compta['id']), 'Lancer exige le droit de modifier.');
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
            $_SESSION['glpiID'] = $savedId;
        }
        $compta = $this->share('Compta');
        $this->assertFalse($compta['review_open']);
        $this->assertNotSame('', $compta['date_last_review']);
        $removed = $this->ace($compta['id'], 'alice.' . $this->suffix);
        $this->assertSame(AccessReviewLogic::DECISION_REMOVE, $removed['decision']);
        $this->assertSame('Passer par le groupe', $removed['comment']);
        $this->assertNotSame('', $removed['decided_by']);
        $this->assertSame(1, ShareAccess::summary()['reviewed']);

        // Une autre personne sans droit de modifier ne décide pas.
        ShareAccess::startReview($compta['id']);
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        $_SESSION['glpiID'] = $this->users['alice'];
        try {
            $this->assertSame('rights', ShareAccess::decide($ace['id'], AccessReviewLogic::DECISION_KEEP, ''));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
            $_SESSION['glpiID'] = $savedId;
        }
    }

    public function testExtensionPointEvidenceAndRights(): void
    {
        ShareAccess::import($this->csv());
        // Droits de l'utilisateur : directement ou par son groupe GLPI.
        $accesses = ShareAccess::accessesForUser($this->users['owner']);
        $this->assertCount(2, $accesses, 'Par le groupe, sur Compta et Public.');
        $this->assertSame('GG_Compta_' . $this->suffix, $accesses[0]['via']);
        $direct = ShareAccess::accessesForUser($this->users['alice']);
        $this->assertCount(1, $direct);
        $this->assertSame('', $direct[0]['via']);
        $this->assertSame(Rules::LEVEL_FULL, $direct[0]['level']);

        $evidence = ShareAccess::evidence('A.5.18');
        $this->assertNotNull($evidence);
        $this->assertSame(2, $evidence['shares']);
        $this->assertSame(1, $evidence['broad_write']);
        $this->assertSame(2, $evidence['stale_accounts']);
        $this->assertSame(0, $evidence['reviewed']);
        $this->assertNotNull(ShareAccess::evidence('A.5.15'));
        $this->assertNotNull(ShareAccess::evidence('A.8.3'));
        $this->assertNull(ShareAccess::evidence('A.5.1'));

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertTrue(ShareAccess::canView());
            $this->assertSame(['rights'], ShareAccess::import($this->csv())['errors'], 'Lecture seule.');
            $this->assertFalse(ShareAccess::clear($this->server()));
            $this->assertFalse(ShareAccess::updateShare($this->share('Compta')['id'], ['confidentiality' => 'low']));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }

        // Sans le droit des registres sensibles : rien.
        $sensitive = $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        ShareAccess::reset();
        try {
            $this->assertFalse(ShareAccess::canView());
            $this->assertSame([], ShareAccess::shares());
            $this->assertSame([], ShareAccess::accessesForUser($this->users['owner']));
            $this->assertNull(ShareAccess::evidence('A.5.18'));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = $sensitive;
        }

        $this->assertTrue(ShareAccess::clear($this->server()));
        $this->assertSame(0, countElementsInTable(ShareAccessTables::SHARES, ['server' => $this->server()]));
        $this->assertSame(0, countElementsInTable(ShareAccessTables::IMPORTS, ['server' => $this->server()]));
        $this->assertSame(['missing_rights'], ShareAccess::import("Share,Identity\nCompta,Everyone\n")['errors']);
    }

    public function testRenderedPages(): void
    {
        ShareAccess::import($this->csv());
        $renderer = \Glpi\Application\View\TemplateRenderer::getInstance();
        $html = $renderer->render('@grcmanager/share_access.html.twig', ShareAccess::pageData());
        $this->assertStringContainsString('id="grc-share-list"', $html);
        $this->assertStringContainsString('\\\\' . $this->server() . '\\Compta', $html);
        $this->assertStringContainsString('name="import_shares"', $html);
        $this->assertStringContainsString('?script=1', $html);

        $compta = $this->share('Compta');
        $html = $renderer->render('@grcmanager/share_access_detail.html.twig', ShareAccess::shareData($compta));
        $this->assertStringContainsString('id="grc-share-detail"', $html);
        $this->assertStringContainsString('alice.' . $this->suffix, $html);
        $this->assertStringContainsString('name="save_share"', $html);
        $this->assertStringContainsString('name="start_review"', $html);
        $this->assertStringNotContainsString('name="decide"', $html, 'Pas de revue en cours.');

        global $DB;
        $columns = array_keys($DB->listFields(ShareAccessTables::ACES));
        $this->assertNotContains('content', $columns, 'Aucun contenu de fichier stocké.');
    }
}
