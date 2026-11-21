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

use GlpiPlugin\Grcmanager\Install\MfaCoverageTables;
use GlpiPlugin\Grcmanager\MfaCoverage;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Access\EntraGraphClient;
use GlpiPlugin\Grcmanager\Services\Access\EntraGraphConfig;
use GlpiPlugin\Grcmanager\Services\Access\MfaCoverageRules as Rules;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #852 : couverture MFA par compte, sur des comptes fictifs d'une entité de test (seule
 * entité active) et d'une entité cachée.
 */
final class MfaCoverageTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private int $hiddenEntity;

    /** @var array<string, int> */
    private array $users = [];

    /** @var array<string, string> */
    private array $logins = [];

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        MfaCoverage::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo MFA ' . $suffix);
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo MFA cachée ' . $suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;

        global $DB;
        // Profils : 4 = Super-Admin (modifie la configuration), 1 = Self-Service.
        $add = function (string $key, int $profile, int $entity = 0) use ($DB, $suffix): void {
            $login = 'phpunitdemo.' . $key . '.' . $suffix;
            $id = $this->createTestUser('Demo', $key . $suffix, ['name' => $login]);
            $this->assertGreaterThan(0, $id);
            $DB->delete('glpi_profiles_users', ['users_id' => $id]);
            $DB->insert('glpi_profiles_users', [
                'users_id' => $id, 'profiles_id' => $profile,
                'entities_id' => $entity > 0 ? $entity : $this->entity, 'is_recursive' => 0,
            ]);
            $this->users[$key] = $id;
            $this->logins[$key] = $login;
        };
        $add('admin_totp', 4);
        $add('admin_none', 4);
        $add('user_sms', 1);
        $add('user_none', 1);
        $add('service', 1);
        $add('hidden_admin', 4, $this->hiddenEntity);
        // Double authentification native activée : seul le fait qu'elle existe compte.
        $DB->update(
            'glpi_users',
            ['2fa' => '{"algorithm":"sha1","secret":"fictif"}'],
            ['id' => $this->users['admin_totp']]
        );
        $DB->insert('glpi_useremails', [
            'users_id' => $this->users['user_none'], 'email' => 'remote.demo' . $suffix . '@exemple.test',
            'is_default' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        MfaCoverage::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $key): array
    {
        foreach (MfaCoverage::accounts() as $row) {
            if ($row['id'] === $this->users[$key]) {
                return $row;
            }
        }
        $this->fail('Compte absent : ' . $key);
    }

    private function csv(): string
    {
        $email = (string) array_column(
            iterator_to_array($GLOBALS['DB']->request([
                'FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $this->users['user_none']],
            ])),
            'email'
        )[0];

        return "login,email,methods,remote_access,excluded,exclusion_reason\n"
            . strtoupper($this->logins['user_sms']) . ",,sms,,,\n"
            . "," . $email . ",,yes,,\n"
            . $this->logins['service'] . ",,,,yes,\n"
            . "unknown.demo@exemple.test,,fido2,,,\n";
    }

    public function testGlpiTwoFactorAndAdministrators(): void
    {
        $this->assertSame(Rules::LEVEL_STRONG, $this->row('admin_totp')['level']);
        $this->assertTrue($this->row('admin_totp')['glpi_totp']);
        $this->assertTrue($this->row('admin_none')['is_admin']);
        $this->assertSame([Rules::GAP_ADMIN_NO_MFA], $this->row('admin_none')['gaps']);
        $this->assertFalse($this->row('user_none')['is_admin']);
        $this->assertNotContains(
            $this->users['hidden_admin'],
            array_column(MfaCoverage::accounts(), 'id'),
            'Entité hors de la session.'
        );
        $summary = MfaCoverage::summary();
        $this->assertSame(2, $summary['admins']['total']);
        $this->assertSame(50, $summary['admins']['rate']);
        $this->assertSame(1, $summary['admins_without_mfa']);
        $this->assertSame(1.0, MfaCoverage::adminsWithoutMfa([$this->hiddenEntity]), 'KRI sans session.');
    }

    public function testCsvImportMatchesAccountsAndFlagsGaps(): void
    {
        $result = MfaCoverage::import('okta', $this->csv());
        $this->assertSame([], $result['errors']);
        $this->assertSame(4, $result['imported']);
        $this->assertSame(3, $result['matched']);
        $this->assertSame(1, $result['unmatched']);

        $this->assertSame([Rules::GAP_WEAK_ONLY], $this->row('user_sms')['gaps']);
        $this->assertSame(['Okta'], $this->row('user_sms')['sources']);
        $this->assertTrue($this->row('user_none')['is_remote'], 'Rapproché par l\'adresse e-mail.');
        $this->assertSame([Rules::GAP_REMOTE_NO_MFA], $this->row('user_none')['gaps']);
        $this->assertSame([Rules::GAP_EXCLUDED_NO_REASON], $this->row('service')['gaps']);
        $this->assertSame(['unknown.demo@exemple.test'], array_column(MfaCoverage::unmatched(), 'account'));
        $this->assertSame(0, MfaCoverage::summary()['remote']['rate']);

        // Un nouvel import du même fournisseur remplace le précédent.
        MfaCoverage::import('okta', "login,methods\n" . $this->logins['user_sms'] . ",Okta Verify\n");
        $this->assertSame(1, countElementsInTable(
            MfaCoverageTables::ACCOUNTS,
            ['provider' => 'okta', 'entities_id' => $this->entity]
        ));
        $this->assertSame(Rules::LEVEL_STRONG, $this->row('user_sms')['level']);
        $this->assertSame([], $this->row('service')['gaps']);

        $this->assertTrue(MfaCoverage::clear('okta'));
        $this->assertSame(0, countElementsInTable(MfaCoverageTables::ACCOUNTS, ['entities_id' => $this->entity]));
        $this->assertSame(['missing_account'], MfaCoverage::import('okta', "foo,methods\nx,sms\n")['errors']);
    }

    /**
     * Lecture d'Entra ID par un faux Microsoft Graph (aucun appel réseau) : rangée comme un import
     * Entra ID, rapprochée par l'UPN ; une erreur ne remplace rien.
     */
    public function testGraphReadIsStoredAsTheEntraImport(): void
    {
        $upn = $this->logins['admin_none'] . '@exemple.test';
        $transport = static function (string $method, string $url) use ($upn): array {
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{"access_token":"fictif"}', 'retry_after' => 0];
            }

            return ['status' => 200, 'retry_after' => 0, 'body' => (string) json_encode(['value' => [
                ['userPrincipalName' => $upn, 'methodsRegistered' => ['mobilePhone'], 'isAdmin' => true],
                ['userPrincipalName' => 'nobody.demo@exemple.test', 'methodsRegistered' => ['fido2']],
            ]])];
        };
        $client = new EntraGraphClient(
            'exemple.onmicrosoft.com',
            '11111111-2222-3333-4444-555555555555',
            'fictif',
            \Closure::fromCallable($transport)
        );
        $result = MfaCoverage::importFromGraph($client);
        $this->assertSame('', $result['graph_error']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(['Microsoft Entra ID'], $this->row('admin_none')['sources']);
        $this->assertSame([Rules::GAP_WEAK_ONLY], $this->row('admin_none')['gaps']);

        $refused = new EntraGraphClient(
            'exemple.onmicrosoft.com',
            '11111111-2222-3333-4444-555555555555',
            'fictif',
            static fn (): array => ['status' => 403, 'body' => '', 'retry_after' => 0]
        );
        $this->assertSame(EntraGraphClient::ERROR_FORBIDDEN, MfaCoverage::importFromGraph($refused)['graph_error']);
        $this->assertSame(2, countElementsInTable(
            MfaCoverageTables::ACCOUNTS,
            ['provider' => 'entra', 'entities_id' => $this->entity]
        ), 'Une lecture refusée ne remplace rien.');
        MfaCoverage::clear('entra');

        // Réglages : identifiants invalides refusés, secret jamais renvoyé.
        $this->assertFalse(EntraGraphConfig::save('not a tenant', '', '', false)['saved']);
        $saved = EntraGraphConfig::save('exemple.onmicrosoft.com', '11111111-2222-3333-4444-555555555555', 'fictif', false);
        $this->assertTrue($saved['saved']);
        $this->assertSame('changed', $saved['changes'][EntraGraphConfig::SECRET_KEY]);
        $this->assertTrue(EntraGraphConfig::isConfigured());
        $graph = MfaCoverage::pageData()['graph'];
        $this->assertTrue($graph['has_secret']);
        $this->assertArrayNotHasKey('secret', $graph);
        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
            '@grcmanager/mfa_coverage.html.twig',
            MfaCoverage::pageData()
        );
        $this->assertStringContainsString('name="fetch_mfa_graph"', $html);
        $this->assertStringNotContainsString('value="fictif"', $html);
        EntraGraphConfig::save('', '', '', true);
        $this->assertFalse(EntraGraphConfig::isConfigured());
    }

    public function testRemoteGroups(): void
    {
        $group = (int) (new \Group())->add(['name' => 'PhpUnitDemo VPN', 'entities_id' => $this->entity]);
        (new \Group_User())->add(['groups_id' => $group, 'users_id' => $this->users['admin_none']]);
        $this->assertSame([$group], MfaCoverage::setRemoteGroups([(string) $group, '999999999', 'x']));
        $this->assertSame([$group], MfaCoverage::getRemoteGroups());
        $this->assertTrue($this->row('admin_none')['is_remote']);
        $this->assertSame([Rules::GAP_ADMIN_NO_MFA, Rules::GAP_REMOTE_NO_MFA], $this->row('admin_none')['gaps']);
        MfaCoverage::setRemoteGroups([]);
    }

    public function testEvidenceDashboardKriAndRights(): void
    {
        $evidence = MfaCoverage::evidence('A.8.5');
        $this->assertNotNull($evidence);
        $this->assertSame(1, $evidence['admins_without_mfa']);
        $this->assertSame(50, $evidence['admin_rate']);
        $this->assertNotNull(MfaCoverage::evidence('A.5.17'));
        $this->assertNull(MfaCoverage::evidence('A.5.1'));
        $this->assertNotNull(MfaCoverage::readinessFact());
        $this->assertArrayHasKey('admins_without_mfa', RiskIndicator::getSources());
        $this->assertTrue(SecuredDashboardCards::canShow('mfaAdminRate'));
        $this->assertSame(50, SecuredDashboardCards::mfaAdminRate()['number']);
        $this->assertNotEmpty(MfaCoverage::pageData()['rows']);
        MfaCoverage::import('okta', $this->csv());
        $html = \Glpi\Application\View\TemplateRenderer::getInstance()->render(
            '@grcmanager/mfa_coverage.html.twig',
            MfaCoverage::pageData()
        );
        $this->assertStringContainsString('grc-mfa-admin-rate', $html);
        $this->assertStringContainsString($this->logins['admin_none'], $html);
        $this->assertStringContainsString('unknown.demo@exemple.test', $html, 'Compte non rapproché listé.');
        $this->assertStringContainsString('name="import_mfa"', $html);
        MfaCoverage::clear('okta');

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertFalse(MfaCoverage::canManage());
            $this->assertSame(['rights'], MfaCoverage::import('okta', $this->csv())['errors'], 'Lecture seule.');
            $this->assertFalse(MfaCoverage::clear('okta'));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }

        $userRight = $_SESSION['glpiactiveprofile']['user'];
        $_SESSION['glpiactiveprofile']['user'] = 0;
        MfaCoverage::reset();
        try {
            $this->assertFalse(MfaCoverage::canView());
            $this->assertSame([], MfaCoverage::accounts());
            $this->assertNull(MfaCoverage::evidence('A.8.5'));
            $this->assertFalse(SecuredDashboardCards::canShow('mfaAdminRate'));
        } finally {
            $_SESSION['glpiactiveprofile']['user'] = $userRight;
        }
    }

    public function testNoSecretColumn(): void
    {
        global $DB;
        $columns = array_keys($DB->listFields(MfaCoverageTables::ACCOUNTS));
        foreach ($columns as $column) {
            $this->assertStringNotContainsString('secret', $column);
        }
    }
}
