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

use GlpiPlugin\Grcmanager\Install\PersonalDigestTables;
use GlpiPlugin\Grcmanager\NotificationTargetPersonalDigest;
use GlpiPlugin\Grcmanager\PersonalDigest;
use GlpiPlugin\Grcmanager\Services\Workspace\PersonalDigestRules as Rules;
use NotificationTarget;

/**
 * Issue #877 : récapitulatif personnel par e-mail, sur des données fictives. Indépendant de la
 * langue : seuls les noms des éléments, les codes et les valeurs enregistrées sont comparés.
 */
final class PersonalDigestTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $savedConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $CFG_GLPI;
        $this->savedConfig = [
            'use_notifications'     => $CFG_GLPI['use_notifications'] ?? 0,
            'notifications_mailing' => $CFG_GLPI['notifications_mailing'] ?? 0,
        ];
    }

    protected function tearDown(): void
    {
        global $CFG_GLPI;
        foreach ($this->savedConfig as $key => $value) {
            $CFG_GLPI[$key] = $value;
        }
        parent::tearDown();
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%+d days', $offset)));
    }

    /**
     * Compte fictif avec un seul profil : Super-Admin (4) ou un profil sans aucun droit.
     */
    private function person(string $firstname, bool $admin, string $email = ''): int
    {
        global $DB;

        $usersId = $this->createTestUser($firstname, 'Recap877');
        $DB->delete('glpi_profiles_users', ['users_id' => $usersId]);
        $profilesId = 4;
        if (!$admin) {
            $profilesId = (int) (new \Profile())->add(['name' => 'Profil 877 ' . $firstname, 'interface' => 'central']);
        }
        $DB->insert('glpi_profiles_users', [
            'users_id' => $usersId, 'profiles_id' => $profilesId, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        if ($email !== '') {
            $DB->insert('glpi_useremails', ['users_id' => $usersId, 'email' => $email, 'is_default' => 1]);
        }

        return $usersId;
    }

    public function testTaskIsRegisteredDisabledAndNotificationIsSeeded(): void
    {
        $task = new \CronTask();
        $this->assertTrue($task->getFromDBbyName(PersonalDigest::class, PersonalDigest::CRON_NAME));
        $this->assertSame(\CronTask::STATE_DISABLE, (int) $task->fields['state']);
        $this->assertFalse(PersonalDigest::isTaskActive());

        $notification = new \Notification();
        $this->assertTrue($notification->getFromDBByCrit([
            'itemtype' => PersonalDigest::class, 'event' => PersonalDigest::EVENT,
        ]));
        $this->assertSame(1, countElementsInTable('glpi_notificationtargets', [
            'notifications_id' => $notification->getID(),
            'items_id'         => NotificationTargetPersonalDigest::TARGET_PERSON,
        ]));
        $this->assertGreaterThan(0, countElementsInTable('glpi_notificationtemplates', [
            'itemtype' => PersonalDigest::class,
        ]));
    }

    public function testEachPersonChoosesTheFrequency(): void
    {
        $usersId = $this->person('Choix', false);
        $this->assertSame(Rules::FREQUENCY_WEEKLY, PersonalDigest::frequencyFor($usersId), 'Par défaut.');
        $this->assertTrue(PersonalDigest::saveFrequency($usersId, Rules::FREQUENCY_DAILY));
        $this->assertSame(Rules::FREQUENCY_DAILY, PersonalDigest::frequencyFor($usersId));
        $this->assertFalse(PersonalDigest::saveFrequency($usersId, 'hourly'));
        $this->assertFalse(PersonalDigest::saveFrequency(0, Rules::FREQUENCY_DAILY));
        $this->assertTrue(PersonalDigest::saveFrequency($usersId, Rules::FREQUENCY_NEVER));
        $this->assertSame(Rules::FREQUENCY_NEVER, PersonalDigest::frequencyFor($usersId));
    }

    public function testContentFollowsTheRightsOfThePersonNotThoseOfTheTask(): void
    {
        $admin = $this->person('Admin', true);
        $plain = $this->person('Simple', false);
        foreach ([$admin, $plain] as $usersId) {
            (new \GlpiPlugin\Grcmanager\Risk())->add([
                'title' => 'Risque 877 de ' . $usersId, 'users_id' => $usersId, 'review_date' => $this->day(-3),
            ]);
        }
        (new \GlpiPlugin\Grcmanager\Training())->add([
            'title' => 'Formation 877', 'date_delivered' => $this->day(5), 'participants' => [$plain],
        ]);
        $savedId = $_SESSION['glpiID'];
        $savedProfile = $_SESSION['glpiactiveprofile'];

        $adminItems = PersonalDigest::collect($admin, date('Y-m-d'));
        $this->assertContains('Risque 877 de ' . $admin, array_column($adminItems, 'name'));
        $this->assertSame('overdue', $adminItems[0]['urgency']);

        // Sans droit sur le plugin : seulement ce qui ne demande aucun droit (ses formations).
        $this->assertSame(['Formation 877'], array_column(PersonalDigest::collect($plain, date('Y-m-d')), 'name'));

        $this->assertSame($savedId, $_SESSION['glpiID'], 'La session d\'origine est rétablie.');
        $this->assertSame($savedProfile, $_SESSION['glpiactiveprofile']);
        $this->assertSame([], PersonalDigest::collect(0, date('Y-m-d')));
    }

    public function testEmptySummaryIsNotSentAndAFullOneIsQueuedForThePersonOnly(): void
    {
        global $CFG_GLPI;
        $CFG_GLPI['use_notifications'] = 1;
        $CFG_GLPI['notifications_mailing'] = 1;

        $idle = $this->person('Rien', true, 'rien.877@example.org');
        $this->assertSame(PersonalDigest::NOTHING_WAITING, PersonalDigest::send($idle, date('Y-m-d')));
        $this->assertNull(PersonalDigest::rowFor($idle), 'Aucune trace pour un récapitulatif vide.');

        $busy = $this->person('Occupe', true, 'occupe.877@example.org');
        (new \GlpiPlugin\Grcmanager\Nonconformity())->add([
            'title' => 'Non-conformité 877', 'users_id' => $busy, 'due_date' => $this->day(-1), 'status' => 'open',
        ]);
        $this->assertSame(PersonalDigest::SENT, PersonalDigest::send($busy, date('Y-m-d')));
        $row = PersonalDigest::rowFor($busy);
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['date_last_sent']);
        $this->assertGreaterThan(0, (int) $row['items_count']);
        $this->assertSame(1, countElementsInTable('glpi_queuednotifications', [
            'itemtype' => PersonalDigest::class, 'recipient' => 'occupe.877@example.org',
        ]));
        $this->assertSame(0, countElementsInTable('glpi_queuednotifications', [
            'itemtype' => PersonalDigest::class, 'recipient' => 'rien.877@example.org',
        ]));
    }

    public function testPersonOutsideTheRootEntityAlsoReceivesTheSummary(): void
    {
        global $CFG_GLPI, $DB;
        $CFG_GLPI['use_notifications'] = 1;
        $CFG_GLPI['notifications_mailing'] = 1;

        $entityId = (int) (new \Entity())->add(['name' => 'Entité fictive 877', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $entityId);
        $usersId = $this->person('Filiale', false, 'filiale.877@example.org');
        $DB->update('glpi_profiles_users', ['entities_id' => $entityId, 'is_recursive' => 0], ['users_id' => $usersId]);
        (new \GlpiPlugin\Grcmanager\Training())->add([
            'title' => 'Formation 877 filiale', 'date_delivered' => $this->day(3), 'participants' => [$usersId],
        ]);

        $this->assertSame(PersonalDigest::SENT, PersonalDigest::send($usersId, date('Y-m-d')));
        $this->assertSame($entityId, (int) PersonalDigest::rowFor($usersId)['entities_id']);
        $this->assertSame(1, countElementsInTable('glpi_queuednotifications', [
            'itemtype' => PersonalDigest::class, 'recipient' => 'filiale.877@example.org',
        ]));
    }

    public function testOnlyTheOwnerReceivesTheSummary(): void
    {
        $owner = $this->person('Proprio', true);
        $other = $this->person('Tiers', true);
        PersonalDigest::saveFrequency($owner, Rules::FREQUENCY_DAILY);
        $digest = new PersonalDigest();
        $this->assertTrue($digest->getFromDB((int) PersonalDigest::rowFor($owner)['id']));
        $target = NotificationTarget::getInstance($digest, PersonalDigest::EVENT, []);
        $this->assertInstanceOf(NotificationTargetPersonalDigest::class, $target);

        $infos = static fn (int $usersId): array => ['users_id' => $usersId, 'email' => 'x@example.org'];
        $this->assertTrue($target->validateSendTo(PersonalDigest::EVENT, $infos($owner)));
        $this->assertFalse($target->validateSendTo(PersonalDigest::EVENT, $infos($other)));
        $this->assertFalse($target->validateSendTo(PersonalDigest::EVENT, $infos(0)));
    }

    public function testEmailListsTheItemsWithAbsoluteLinks(): void
    {
        global $CFG_GLPI;

        $owner = $this->person('Lien', true);
        PersonalDigest::saveFrequency($owner, Rules::FREQUENCY_DAILY);
        $digest = new PersonalDigest();
        $digest->getFromDB((int) PersonalDigest::rowFor($owner)['id']);
        $items = [[
            'source' => 'nonconformity', 'id' => 7, 'name' => 'Non-conformité 877 <b>', 'due' => $this->day(-2),
            'url' => '/plugins/grcmanager/front/nonconformity.form.php?id=7', 'detail' => '',
            'urgency' => 'overdue', 'days_late' => 2,
        ]];
        $options = ['items' => $items, 'frequency' => Rules::FREQUENCY_DAILY];
        $target = NotificationTarget::getInstance($digest, PersonalDigest::EVENT, $options);
        $target->addDataForTemplate(PersonalDigest::EVENT, $options);
        $data = $target->data;

        $this->assertNotSame('', $data['##personaldigest.subject##']);
        $this->assertSame('1', $data['##personaldigest.total##']);
        $this->assertSame('1', $data['##personaldigest.overdue##']);
        $this->assertSame('', $data['##personaldigest.more##']);
        $this->assertCount(1, $data['digestitems']);
        $line = $data['digestitems'][0];
        $this->assertSame('Non-conformité 877 <b>', $line['##digestitem.name##'], 'Texte brut : GLPI échappe le HTML.');
        $this->assertNotSame('', $line['##digestitem.late##']);
        $base = rtrim((string) $CFG_GLPI['url_base'], '/');
        $this->assertStringStartsWith($base . '/', $line['##digestitem.url##']);
        $this->assertStringEndsWith('nonconformity.form.php?id=7', $line['##digestitem.url##']);
        $this->assertStringStartsWith($base . '/', $data['##personaldigest.url##']);
    }

    public function testTaskSkipsPeopleWhoRefusedOrWereAlreadyChecked(): void
    {
        $refused = $this->person('Refus', true, 'refus.877@example.org');
        $checked = $this->person('Vu', true, 'vu.877@example.org');
        $fresh = $this->person('Nouveau', true, 'nouveau.877@example.org');
        $noMail = $this->person('Sansmail', true);
        PersonalDigest::saveFrequency($refused, Rules::FREQUENCY_NEVER);
        PersonalDigest::saveFrequency($checked, Rules::FREQUENCY_DAILY);
        global $DB;
        $DB->update(PersonalDigestTables::DIGESTS, ['date_last_check' => date('Y-m-d')], ['users_id' => $checked]);

        $candidates = PersonalDigest::candidates(date('Y-m-d'), 100000);
        $this->assertContains($fresh, $candidates);
        $this->assertNotContains($refused, $candidates);
        $this->assertNotContains($checked, $candidates);
        $this->assertNotContains($noMail, $candidates, 'Sans adresse e-mail, rien à envoyer.');
    }

    public function testUninstallRemovesTheNotificationAndItsTemplate(): void
    {
        PersonalDigestTables::removeNotification();
        $this->assertSame(0, countElementsInTable('glpi_notifications', ['itemtype' => PersonalDigest::class]));
        $this->assertSame(0, countElementsInTable('glpi_notificationtemplates', ['itemtype' => PersonalDigest::class]));
    }
}
