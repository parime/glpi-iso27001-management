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

use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Install\PrivilegedVaultTables;
use GlpiPlugin\Grcmanager\Install\ServerManagementTables;
use GlpiPlugin\Grcmanager\PrivilegedVault;
use GlpiPlugin\Grcmanager\Services\Access\BitwardenPublicClient;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultConfig;
use GlpiPlugin\Grcmanager\Services\Access\PrivilegedVaultRules as Rules;

/**
 * Issue #859 : coffre-fort de mots de passe sur des comptes et un coffre fictifs, dans une entité de
 * test (seule entité active) et une entité cachée. Assertions sur des codes et des nombres, jamais
 * sur des textes traduits.
 */
final class PrivilegedVaultTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private int $hiddenEntity;

    private string $suffix;

    /** @var array<string, int> */
    private array $users = [];

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
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo coffre ' . $this->suffix);
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo coffre caché ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;

        global $DB;
        foreach (['present' => 1, 'parti' => 0] as $key => $active) {
            $id = $this->createTestUser('Demo', $key . $this->suffix, [
                'name'      => 'phpunitdemo.' . $key . '.' . $this->suffix,
                'is_active' => $active,
            ]);
            $this->assertGreaterThan(0, $id);
            $DB->insert('glpi_useremails', ['users_id' => $id, 'email' => $this->email($key), 'is_default' => 1]);
            $this->users[$key] = $id;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        parent::tearDown();
    }

    private function email(string $key): string
    {
        return $key . '.demo' . $this->suffix . '@exemple.test';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function find(array $rows, string $field, string $value): array
    {
        foreach ($rows as $row) {
            if ($row[$field] === $value) {
                return $row;
            }
        }
        self::fail('Ligne absente : ' . $value);
    }

    private function importFixtures(): void
    {
        $members = "email,name,type,status,collections,twoFactorEnabled\n"
            . strtoupper($this->email('present')) . ",Present,1,2,c-serveurs,false\n"
            . $this->email('parti') . ",Parti,2,2,c-serveurs,true\n"
            . "inconnu.demo" . $this->suffix . "@exemple.test,Inconnu,2,2,,true\n"
            . "revoque.demo" . $this->suffix . "@exemple.test,Revoque,0,-1,c-serveurs,false\n";
        $result = PrivilegedVault::import('bitwarden', Rules::KIND_MEMBERS, $members);
        $this->assertSame([], $result['errors']);
        $this->assertSame(4, $result['imported']);

        $entries = "id,name,collectionIds,username,uri,passwordRevisionDate,revisionDate\n"
            . "e1,root srv-db" . $this->suffix . ",c-serveurs,root,ssh://srv-db,2020-01-01T00:00:00Z,\n"
            . "e2,iLO ilo-" . $this->suffix . ",c-autres,Administrator,https://ilo-" . $this->suffix . ".exemple.test,"
            . date('Y-m-d') . ",\n"
            . "e3,Compte sans date,,svc,,,\n";
        $result = PrivilegedVault::import('pam', Rules::KIND_ENTRIES, $entries);
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['imported']);
    }

    public function testMembersAreMatchedAndGapsFlagged(): void
    {
        $this->importFixtures();
        $members = PrivilegedVault::members();

        $present = self::find($members, 'email', $this->email('present'));
        $this->assertSame($this->users['present'], $present['users_id']);
        $this->assertSame([Rules::GAP_ADMIN_NO_2FA], $present['gaps']);
        $this->assertSame([Rules::GAP_DEPARTED], self::find($members, 'email', $this->email('parti'))['gaps']);
        $this->assertSame(
            [Rules::GAP_UNKNOWN],
            self::find($members, 'email', 'inconnu.demo' . $this->suffix . '@exemple.test')['gaps']
        );
        $this->assertSame([], self::find($members, 'email', 'revoque.demo' . $this->suffix . '@exemple.test')['gaps']);
    }

    public function testEntriesStaleAndReachableByAPersonWhoLeft(): void
    {
        $this->importFixtures();
        $entries = PrivilegedVault::entries();

        $db = self::find($entries, 'name', 'root srv-db' . $this->suffix);
        $this->assertContains(Rules::GAP_STALE, $db['gaps']);
        $this->assertContains(Rules::GAP_DEPARTED_HOLDER, $db['gaps']);
        $this->assertSame([$this->email('parti')], $db['departed']);
        $this->assertSame([], self::find($entries, 'name', 'iLO ilo-' . $this->suffix)['gaps']);
        $this->assertSame([Rules::GAP_NO_DATE], self::find($entries, 'name', 'Compte sans date')['gaps']);

        $summary = PrivilegedVault::summary($entries, PrivilegedVault::members(), []);
        $this->assertSame(3, $summary['entries']);
        $this->assertSame(3, $summary['members'], 'Le membre révoqué n\'est pas compté.');
        $this->assertSame(1, $summary['stale']);
        $this->assertSame(1, $summary['departed']);
        $this->assertSame(1, $summary['admin_no_2fa']);

        // KRI sans session, et rien de l'entité cachée.
        $this->assertSame(1.0, PrivilegedVault::staleSecrets([$this->entity]));
        $this->assertSame(0.0, PrivilegedVault::staleSecrets([$this->hiddenEntity]));
        $this->assertNotNull(PrivilegedVault::evidence('A.8.2'));
        $this->assertNull(PrivilegedVault::evidence('A.5.1'));
    }

    public function testImportsAreLimitedToTheActiveEntity(): void
    {
        $this->importFixtures();
        $_SESSION['glpiactiveentities'] = [$this->hiddenEntity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->hiddenEntity;
        $_SESSION['glpiactive_entity'] = $this->hiddenEntity;
        $this->assertSame([], PrivilegedVault::entries());
        $this->assertSame([], PrivilegedVault::members());
        $this->assertSame([], PrivilegedVault::imports());
    }

    public function testSecretColumnRefusesTheWholeFileAndKeepsThePreviousImport(): void
    {
        $this->importFixtures();
        $result = PrivilegedVault::import(
            'pam',
            Rules::KIND_ENTRIES,
            "name,login_username,login_password\nroot,root,motdepasse-fictif\n"
        );
        $this->assertSame(['secret_column'], $result['errors']);
        $this->assertSame('login_password', $result['secret']);
        $this->assertSame(3, countElementsInTable(PrivilegedVaultTables::ENTRIES, [
            'entities_id' => $this->entity, 'source' => 'pam',
        ]));
        global $DB;
        foreach ($DB->request(['FROM' => PrivilegedVaultTables::ENTRIES]) as $row) {
            $this->assertStringNotContainsString('motdepasse-fictif', (string) json_encode($row));
        }

        $this->assertTrue(PrivilegedVault::clear('pam', Rules::KIND_ENTRIES));
        $this->assertSame(0, countElementsInTable(PrivilegedVaultTables::ENTRIES, ['entities_id' => $this->entity]));
        $this->assertSame(4, countElementsInTable(PrivilegedVaultTables::MEMBERS, ['entities_id' => $this->entity]));
    }

    public function testBitwardenReadReplacesTheBitwardenMembersImport(): void
    {
        $this->importFixtures();
        $present = $this->email('present');
        $transport = static function (string $method) use ($present): array {
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{"access_token":"fictif"}', 'retry_after' => 0];
            }

            return ['status' => 200, 'retry_after' => 0, 'body' => (string) json_encode([
                'object' => 'list',
                'data'   => [[
                    'object' => 'member', 'id' => '00000000-0000-0000-0000-000000000001', 'email' => $present,
                    'twoFactorEnabled' => true, 'status' => 2, 'type' => 0, 'collections' => [],
                ]],
            ])];
        };
        $client = new BitwardenPublicClient(
            'https://api.bitwarden.com',
            'https://identity.bitwarden.com',
            'organization.11111111-2222-3333-4444-555555555555',
            'secret-fictif',
            $transport
        );
        $result = PrivilegedVault::fetchFromBitwarden($client);
        $this->assertSame('', $result['error']);
        $this->assertSame(1, $result['imported']);
        $members = PrivilegedVault::members();
        $this->assertCount(1, $members);
        $this->assertSame(Rules::ROLE_OWNER, $members[0]['role']);
        $this->assertSame([], $members[0]['gaps']);

        // Une erreur ne remplace rien.
        $failing = new BitwardenPublicClient(
            'https://api.bitwarden.com',
            'https://identity.bitwarden.com',
            'organization.11111111-2222-3333-4444-555555555555',
            'secret-fictif',
            static fn (): array => ['status' => 401, 'body' => '', 'retry_after' => 0]
        );
        $this->assertSame(
            BitwardenPublicClient::ERROR_UNAUTHORIZED,
            PrivilegedVault::fetchFromBitwarden($failing)['error']
        );
        $this->assertCount(1, PrivilegedVault::members());
    }

    public function testServerControllerAdministratorWithoutVaultEntry(): void
    {
        global $DB;

        $this->importFixtures();
        $controllers = [];
        foreach (['ilo-' . $this->suffix, 'idrac-' . $this->suffix] as $name) {
            $DB->insert(ServerManagementTables::CONTROLLERS, [
                'entities_id' => $this->entity, 'name' => $name, 'source' => 'csv',
                'url' => 'https://' . $name . '.exemple.test', 'date_creation' => date('Y-m-d H:i:s'),
            ]);
            $controllers[$name] = (int) $DB->insertId();
        }
        foreach (
            [
                ['ilo-' . $this->suffix, 'Administrator', 'Administrator', 1],
                ['idrac-' . $this->suffix, 'root', 'Administrator', 1],
                ['idrac-' . $this->suffix, 'operator', 'Operator', 1],
                ['idrac-' . $this->suffix, 'old', 'Administrator', 0],
            ] as [$controller, $username, $role, $enabled]
        ) {
            $DB->insert(ServerManagementTables::ACCOUNTS, [
                'plugin_grcmanager_bmccontrollers_id' => $controllers[$controller],
                'username' => $username, 'role' => $role, 'is_enabled' => $enabled,
            ]);
        }
        $missing = PrivilegedVault::equipmentWithoutEntry(PrivilegedVault::entries(), [$this->entity]);
        $this->assertSame(['root'], array_column($missing, 'username'));
        $this->assertSame([], PrivilegedVault::equipmentWithoutEntry([], [$this->hiddenEntity]));
    }

    public function testRotationSettingAndFeatureDeclarations(): void
    {
        $saved = PrivilegedVaultConfig::rotationMonths();
        $this->assertSame(6, PrivilegedVaultConfig::saveRotationMonths('6'));
        $this->assertSame(60, PrivilegedVaultConfig::saveRotationMonths('999'));
        PrivilegedVaultConfig::saveRotationMonths($saved);

        $this->assertArrayHasKey('vault_stale_secrets', FeatureRegistry::riskIndicatorSources());
        $this->assertFalse(PrivilegedVaultConfig::save('self', 'ftp://x', '', '', false)['saved']);
        $this->assertFalse(PrivilegedVaultConfig::save('us', '', 'pas-un-identifiant', '', false)['saved']);
    }
}
