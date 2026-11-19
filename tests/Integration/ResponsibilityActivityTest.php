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

use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\ResponsibilityExport;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Responsibility\DefaultActivities;
use GlpiPlugin\Grcmanager\Services\Responsibility\ResponsibilityRules as Rules;
use Group;
use Group_User;
use Profile_User;
use User;

/**
 * Issue #612 : matrice des responsabilités du SMSI, sur une matrice fictive.
 */
final class ResponsibilityActivityTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function activity(array $input): ResponsibilityActivity
    {
        $activity = new ResponsibilityActivity();
        $id = (int) $activity->add($input + ['entities_id' => 0, 'is_recursive' => 1]);
        $this->assertGreaterThan(0, $id, 'Activité fictive enregistrée.');
        $activity->getFromDB($id);

        return $activity;
    }

    private function assign(ResponsibilityActivity $activity, string $role, int $userId = 0, int $groupId = 0): void
    {
        $activity->update([
            'id' => $activity->getID(), '_add_role' => $role,
            '_add_users_id' => $userId, '_add_groups_id' => $groupId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $activityId): array
    {
        foreach (ResponsibilityActivity::matrix()['rows'] as $row) {
            if ($row['id'] === $activityId) {
                return $row;
            }
        }
        $this->fail('Activité absente de la matrice.');
    }

    /**
     * Utilisateur fictif avec un profil dans l'entité racine (et ses sous-entités).
     */
    private function member(string $firstname): int
    {
        $id = $this->createTestUser($firstname, 'Exemple');
        (new Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => 0, 'is_recursive' => 1]);

        return $id;
    }

    public function testDefaultActivitiesAreTranslatedAndKeptOnUpdate(): void
    {
        global $DB;

        $keys = array_column(iterator_to_array($DB->request([
            'SELECT' => ['default_key'],
            'FROM'   => ResponsibilityActivity::getTable(),
            'WHERE'  => ['NOT' => ['default_key' => '']],
        ]), false), 'default_key');
        $this->assertSame([], array_diff(array_keys(DefaultActivities::CATALOG), $keys), 'Liste par défaut installée.');

        $activity = new ResponsibilityActivity();
        $this->assertTrue($activity->getFromDBByCrit(['default_key' => 'access_review']));
        $this->assertSame('', $activity->fields['name']);
        $this->assertSame(ResponsibilityActivity::defaultLabels()['access_review'], $activity->getName());
        $this->assertSame(1, (int) $activity->fields['is_sensitive']);

        // Renommée par l'organisation : le nom est conservé, et rien n'est ajouté une seconde fois.
        $activity->update(['id' => $activity->getID(), 'name' => 'Revue trimestrielle des accès Exemple']);
        $this->assertSame(0, ResponsibilityActivity::addDefaults(0));
        $activity->getFromDB($activity->getID());
        $this->assertSame('Revue trimestrielle des accès Exemple', $activity->getName());
        $this->assertSame('access_review', $activity->fields['default_key']);
    }

    public function testInputIsValidated(): void
    {
        $this->assertFalse((new ResponsibilityActivity())->add(['name' => ' ', 'entities_id' => 0]));
        $activity = $this->activity([
            'name' => 'PHPUnit revue des pare-feux', 'default_key' => 'bogus',
            '_control_codes' => ['A.8.20', '<b>', 'A.5.3'], 'is_sensitive' => 'yes',
        ]);
        $this->assertSame('', $activity->fields['default_key']);
        $this->assertSame('A.5.3,A.8.20', $activity->fields['control_codes']);
        $this->assertSame(1, (int) $activity->fields['is_sensitive']);
    }

    public function testAlertsReportWithoutChangingAnything(): void
    {
        $alice = $this->member('Alice');
        $bob = $this->member('Bob');
        $groupId = (int) (new Group())->add([
            'name' => 'PHPUnit équipe sauvegardes', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        (new Group_User())->add(['groups_id' => $groupId, 'users_id' => $alice]);
        $emptyGroup = (int) (new Group())->add([
            'name' => 'PHPUnit groupe vide', 'entities_id' => 0, 'is_recursive' => 1,
        ]);

        $activity = $this->activity(['name' => 'PHPUnit administration des sauvegardes', 'is_sensitive' => 1]);
        $types = array_column($this->row($activity->getID())['alerts'], 'type');
        $this->assertSame([Rules::ALERT_NO_ACCOUNTABLE, Rules::ALERT_NO_RESPONSIBLE], $types);

        $this->assign($activity, Rules::ROLE_RESPONSIBLE, 0, $groupId);
        $this->assign($activity, Rules::ROLE_ACCOUNTABLE, $alice);
        // Un seul approbateur : le second est refusé.
        $this->assign($activity, Rules::ROLE_ACCOUNTABLE, $bob);
        $this->assign($activity, Rules::ROLE_INFORMED, 0, $emptyGroup);

        $row = $this->row($activity->getID());
        $this->assertSame(['Group:' . $groupId => ['responsible'], 'User:' . $alice => ['accountable'],
            'Group:' . $emptyGroup => ['informed']], $row['cells']);
        $types = array_column($row['alerts'], 'type');
        $this->assertSame([Rules::ALERT_SEGREGATION, Rules::ALERT_EMPTY_GROUP], $types);
        $this->assertSame([$alice], $row['alerts'][0]['users']);
        $this->assertStringContainsString('Alice', $row['alerts'][0]['message']);

        // Rien n'a été modifié par l'alerte : Alice approuve toujours, son compte est intact.
        $user = new User();
        $user->getFromDB($alice);
        $this->assertSame(1, (int) $user->fields['is_active']);
        $assignments = ResponsibilityActivity::assignmentsFor([$activity->getID()])[$activity->getID()];
        $this->assertCount(3, $assignments);

        // Bob approuve à la place d'Alice : plus de conflit. Puis Bob est désactivé.
        $approvers = array_filter($assignments, static fn (array $a): bool => $a['role'] === 'accountable');
        $aliceRow = array_values($approvers)[0];
        $activity->update(['id' => $activity->getID(), '_remove_assignments' => [$aliceRow['id']]]);
        $this->assign($activity, Rules::ROLE_ACCOUNTABLE, $bob);
        (new User())->update(['id' => $bob, 'is_active' => 0]);
        $types = array_column($this->row($activity->getID())['alerts'], 'type');
        $this->assertSame([Rules::ALERT_EMPTY_GROUP, Rules::ALERT_INACTIVE_USER], $types);

        // Purge : les affectations partent avec l'activité.
        $activity->delete(['id' => $activity->getID()], true);
        $this->assertSame([], ResponsibilityActivity::assignmentsFor([$activity->getID()]));
    }

    public function testRequirementLinksToTheMatrix(): void
    {
        $module = IsmsRequirement::getLinkedModule('5.3');
        $this->assertNotNull($module);
        $this->assertStringContainsString('responsibilityactivity.php', $module['url']);
        $this->assertNotNull(ResponsibilityActivity::clauseReadiness('5.3'));
        $this->assertNull(ResponsibilityActivity::clauseReadiness('5.2'));
    }

    public function testArchiveAttachesARestrictedPdfAsEvidence(): void
    {
        global $DB;

        $activity = $this->activity(['name' => 'PHPUnit approbation des changements', 'is_sensitive' => 1]);
        $this->assertStringContainsString('PHPUnit approbation des changements', ResponsibilityActivity::renderPdf());

        $documentId = ResponsibilityExport::archive();
        try {
            $this->assertGreaterThan(0, $documentId);
            $source = RestrictedDocument::sourceOf($documentId);
            $this->assertSame(ResponsibilityExport::class, $source['itemtype'] ?? null);
            $linked = array_column(iterator_to_array($DB->request([
                'SELECT' => ['itemtype'],
                'FROM'   => Document_Item::getTable(),
                'WHERE'  => ['documents_id' => $documentId],
            ]), false), 'itemtype');
            $this->assertContains(IsmsRequirement::class, $linked);
            $this->assertNotEmpty(ResponsibilityExport::recent());
        } finally {
            if ($documentId > 0) {
                (new Document())->delete(['id' => $documentId], true);
            }
        }
    }
}
