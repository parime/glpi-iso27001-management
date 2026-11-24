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

use Config;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\BreachExposure;
use GlpiPlugin\Grcmanager\Config\Block\IsmsRolesBlock;
use GlpiPlugin\Grcmanager\IsmsRoles;
use GlpiPlugin\Grcmanager\NotificationTargetBreachExposure;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use Group;
use Group_User;
use NotificationEventMailing;
use Notification_NotificationTemplate;

/**
 * Issue #1054 : rôles du SMSI (RSSI et DPO) : groupe GLPI et adresse générique de chacun, reprise de
 * l'ancien champ texte du DPO, destinataires de notification et documents. Données fictives.
 */
final class IsmsRolesTest extends GrcmanagerIntegrationTestCase
{
    private const KEYS = [
        IsmsRoles::LEGACY_DPO_KEY, IsmsRoles::DPO_EMAIL_KEY, IsmsRoles::DPO_NOTE_KEY,
        IsmsRoles::DPO_GROUP_KEY, IsmsRoles::CISO_EMAIL_KEY,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Config::deleteConfigurationValues(IsmsRoles::CONFIG_CONTEXT, self::KEYS);
        AuditTeam::setCisoGroupId(0);
    }

    /** @param array<string, string> $values */
    private function legacy(string $value, array $values = []): void
    {
        Config::setConfigurationValues(IsmsRoles::CONFIG_CONTEXT, [IsmsRoles::LEGACY_DPO_KEY => $value] + $values);
    }

    private function legacyExists(): bool
    {
        return Config::getConfigurationValues(IsmsRoles::CONFIG_CONTEXT, [IsmsRoles::LEGACY_DPO_KEY]) !== [];
    }

    public function testMigrationTurnsALegacyAddressIntoTheGenericAddress(): void
    {
        $this->legacy('dpo@example.org');
        $this->assertTrue(IsmsRoles::migrateLegacyDpoContact());

        $this->assertSame('dpo@example.org', IsmsRoles::email(IsmsRoles::ROLE_DPO));
        $this->assertSame('', IsmsRoles::dpoNote());
        $this->assertFalse($this->legacyExists(), 'Ancien champ supprimé une fois repris.');
        $this->assertFalse(IsmsRoles::migrateLegacyDpoContact(), 'Rejouable : plus rien à reprendre.');
    }

    public function testMigrationKeepsTheRestOfTheLegacyTextAsANoteToRework(): void
    {
        $this->legacy('Alice Martin, 01 23 45 67 89');
        IsmsRoles::migrateLegacyDpoContact();

        $this->assertSame('', IsmsRoles::email(IsmsRoles::ROLE_DPO));
        $this->assertSame('Alice Martin, 01 23 45 67 89', IsmsRoles::dpoNote());
        $this->assertSame('Alice Martin, 01 23 45 67 89', ProcessingActivity::dpoContact(), 'Note sur le registre.');

        $this->legacy('Bob Durand, dpo@example.org');
        IsmsRoles::migrateLegacyDpoContact();
        $this->assertSame('dpo@example.org', IsmsRoles::email(IsmsRoles::ROLE_DPO));
        $this->assertSame('Alice Martin, 01 23 45 67 89 Bob Durand', IsmsRoles::dpoNote(), 'Rien n\'est perdu.');
    }

    public function testMigrationNeverOverwritesAGenericAddressAlreadySet(): void
    {
        $this->legacy('ancien@example.org', [IsmsRoles::DPO_EMAIL_KEY => 'dpo@example.org']);
        IsmsRoles::migrateLegacyDpoContact();

        $this->assertSame('dpo@example.org', IsmsRoles::email(IsmsRoles::ROLE_DPO));
        $this->assertSame('ancien@example.org', IsmsRoles::dpoNote(), 'L\'ancienne adresse reste à reprendre.');
    }

    public function testSaveRefusesAnInvalidAddressAndKeepsThePreviousOne(): void
    {
        $group = (int) (new Group())->add(['name' => 'DPO fictif ' . bin2hex(random_bytes(3)), 'entities_id' => 0]);
        $result = IsmsRoles::save([
            'ciso_groups_id' => $group, 'ciso_email' => 'RSSI@example.org',
            'dpo_groups_id' => $group, 'dpo_email' => 'dpo@example.org',
        ]);
        $this->assertSame([], $result['invalid']);
        $this->assertSame($group, AuditTeam::getCisoGroupId(), 'Le groupe RSSI reste celui des audits.');
        $this->assertSame($group, IsmsRoles::groupId(IsmsRoles::ROLE_DPO));
        $this->assertSame('rssi@example.org', IsmsRoles::email(IsmsRoles::ROLE_CISO));

        $result = IsmsRoles::save([
            'ciso_groups_id' => $group, 'ciso_email' => 'not an address',
            'dpo_groups_id' => $group, 'dpo_email' => 'dpo@example.org',
        ]);
        $this->assertCount(1, $result['invalid']);
        $this->assertSame('rssi@example.org', IsmsRoles::email(IsmsRoles::ROLE_CISO), 'Adresse invalide refusée.');
        $this->assertSame([], $result['changes']);
    }

    public function testClearingTheNoteNeedsTheCheckbox(): void
    {
        $this->legacy('Cabinet externe');
        IsmsRoles::migrateLegacyDpoContact();
        IsmsRoles::save([]);
        $this->assertSame('Cabinet externe', IsmsRoles::dpoNote());
        $result = IsmsRoles::save(['clear_dpo_note' => '1']);
        $this->assertSame('', IsmsRoles::dpoNote());
        $this->assertArrayHasKey(IsmsRoles::DPO_NOTE_KEY, $result['changes']);
    }

    public function testRegistersShowTheDpoGroupMembersAndGenericAddress(): void
    {
        $group = (int) (new Group())->add(['name' => 'DPO fictif', 'entities_id' => 0]);
        $alice = $this->createTestUser('Alice', 'Martin');
        $inactive = $this->createTestUser('Paul', 'Parti', ['is_active' => 0]);
        foreach ([$alice, $inactive] as $member) {
            (new Group_User())->add(['groups_id' => $group, 'users_id' => $member]);
        }
        IsmsRoles::save(['dpo_groups_id' => $group, 'dpo_email' => 'dpo@example.org']);

        $this->assertCount(1, IsmsRoles::members($group), 'Membres actifs seulement.');
        $contact = ProcessingActivity::dpoContact();
        $this->assertStringContainsString('DPO fictif', $contact);
        $this->assertStringContainsString('Alice', $contact);
        $this->assertStringNotContainsString('Parti', $contact);
        $this->assertStringContainsString('dpo@example.org', $contact);

        $html = TemplateRenderer::getInstance()->render('@grcmanager/pdf/processing_register_report.html.twig', [
            'report_title' => 'Record', 'generated_at_label' => '', 'columns' => [], 'rows' => [],
            'organisation' => ['name' => 'X', 'address' => '', 'email' => '', 'phone' => '', 'dpo' => $contact],
        ]);
        $this->assertStringContainsString('dpo@example.org', $html, 'Adresse générique sur le registre (PDF).');

        $matrix = ResponsibilityActivity::renderPdf();
        $this->assertStringContainsString('dpo@example.org', $matrix, 'Rôles sur la matrice des responsabilités.');
        $this->assertStringContainsString('DPO fictif', $matrix);
    }

    public function testBreachExposureNotificationReachesTheDpoGroupAndTheGenericAddresses(): void
    {
        global $DB;

        $group = (int) (new Group())->add(['name' => 'DPO fictif', 'entities_id' => 0, 'is_notify' => 1]);
        $alice = $this->createTestUser('Alice', 'Martin');
        (new \UserEmail())->add(['users_id' => $alice, 'email' => 'alice.martin@example.org', 'is_default' => 1]);
        (new Group_User())->add(['groups_id' => $group, 'users_id' => $alice]);
        $DB->insert('glpi_profiles_users', [
            'users_id' => $alice, 'profiles_id' => 1, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        IsmsRoles::save([
            'dpo_groups_id' => $group, 'dpo_email' => 'dpo@example.org', 'ciso_email' => 'rssi@example.org',
        ]);

        $recipients = static function (int $targetId): array {
            $event = BreachExposure::NEW_EXPOSURES_EVENT;
            $target = new NotificationTargetBreachExposure(0, $event, new BreachExposure());
            $target->setMode(Notification_NotificationTemplate::MODE_MAIL)->setEvent(NotificationEventMailing::class);
            $target->addSpecificTargets(['items_id' => $targetId], []);

            return array_values(array_map('strval', array_column($target->getTargets(), 'email')));
        };
        $this->assertSame(['alice.martin@example.org'], $recipients(IsmsRoles::TARGET_DPO_GROUP));
        $this->assertSame(['dpo@example.org'], $recipients(IsmsRoles::TARGET_DPO_EMAIL));
        $this->assertSame(['rssi@example.org'], $recipients(IsmsRoles::TARGET_CISO_EMAIL));

        $target = new NotificationTargetBreachExposure(0, BreachExposure::NEW_EXPOSURES_EVENT, new BreachExposure());
        $target->addAdditionalTargets(BreachExposure::NEW_EXPOSURES_EVENT);
        $labels = $target->notification_targets_labels[\Notification::USER_TYPE] ?? [];
        foreach ([IsmsRoles::TARGET_DPO_GROUP, IsmsRoles::TARGET_DPO_EMAIL, IsmsRoles::TARGET_CISO_EMAIL] as $id) {
            $this->assertArrayHasKey($id, $labels, 'Cible proposée dans la notification.');
        }

        IsmsRoles::save(['dpo_groups_id' => 0, 'dpo_email' => '', 'ciso_email' => '']);
        $this->assertSame([], $recipients(IsmsRoles::TARGET_DPO_GROUP), 'Sans groupe : personne.');
        $this->assertSame([], $recipients(IsmsRoles::TARGET_DPO_EMAIL), 'Sans adresse : personne.');
    }

    public function testBlockIsOnThePeopleTabAndKeepsTheFormerDpoAnchor(): void
    {
        $this->assertSame('people', IsmsRolesBlock::tab());
        $this->assertContains('grc-dpo-config', IsmsRolesBlock::anchors());
        $this->assertSame(
            [IsmsRoles::ROLE_CISO, IsmsRoles::ROLE_DPO],
            array_column(IsmsRolesBlock::data()['isms_roles'], 'key')
        );
    }
}
