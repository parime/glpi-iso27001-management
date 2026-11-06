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
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\ControlTestReminder;
use GlpiPlugin\Grcmanager\NotificationSupport;
use GlpiPlugin\Grcmanager\NotificationTargetControl;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use Group;
use Group_User;
use Profile;
use Profile_User;
use QueuedNotification;
use UserEmail;

/**
 * Issue #380, deuxième étape : rappel des tests d'efficacité par la tâche planifiée, retrait de
 * l'état « Vérifié » échu, destinataires limités à ceux qui peuvent lire la mesure, groupe RSSI
 * selon le réglage, charte de Configuration GLPI Auto avec et sans lui, colonnes de la SoA.
 */
final class ControlTestReminderTest extends GrcmanagerIntegrationTestCase
{
    private const MAIL_SETTINGS = [
        'use_notifications'     => 1,
        'notifications_mailing' => 1,
        'admin_email'           => 'glpi@example.org',
        'from_email'            => 'glpi@example.org',
    ];

    /**
     * Réglages remplacés, restaurés un par un : $CFG_GLPI est un objet sur GLPI 12.
     *
     * @var array<string, mixed>
     */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        global $CFG_GLPI;

        parent::setUp();
        // Envoi par e-mail actif : les rappels sont mis en file d'attente (glpi_queuednotifications).
        foreach (self::MAIL_SETTINGS as $key => $value) {
            $this->savedSettings[$key] = $CFG_GLPI[$key] ?? null;
            $CFG_GLPI[$key] = $value;
        }
        NotificationSupport::$forceCgaActive = false;
    }

    protected function tearDown(): void
    {
        global $CFG_GLPI;

        foreach ($this->savedSettings as $key => $value) {
            $CFG_GLPI[$key] = $value;
        }
        NotificationSupport::$forceCgaActive = null;
        parent::tearDown();
    }

    public function testNotificationAndTaskAreInstalledWithOwnTargets(): void
    {
        global $DB;

        $notification = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => Control::class, 'event' => ControlTestReminder::EVENT],
        ])->current();
        $this->assertNotNull($notification, 'Notification créée à l\'installation.');
        $targets = array_map('intval', array_column(iterator_to_array($DB->request([
            'FROM'  => 'glpi_notificationtargets',
            'WHERE' => ['notifications_id' => $notification['id']],
        ]), false), 'items_id'));
        sort($targets);
        $this->assertSame(
            [NotificationTargetControl::TARGET_TESTER, NotificationTargetControl::TARGET_CISO_GROUP],
            $targets
        );
        $this->assertGreaterThanOrEqual(1000, NotificationTargetControl::TARGET_TESTER);
        $this->assertGreaterThanOrEqual(1000, NotificationTargetControl::TARGET_CISO_GROUP);
        $this->assertSame(1, countElementsInTable('glpi_crontasks', [
            'itemtype' => ControlTest::class,
            'name'     => ControlTestReminder::CRON_NAME,
        ]));
    }

    public function testOverdueVerifiedControlIsWithdrawnTracedAndRemindedOnce(): void
    {
        $tester = $this->user('Ines', 'Testeuse', $this->readerProfile(), 0);
        $control = $this->control('A.5.15', 'verified', $tester);
        $this->addTest($control, $this->day(-400), 'effective', $this->day(-1));

        ControlTestReminder::run(date('Y-m-d'));

        $control->getFromDB($control->getID());
        $this->assertSame('implemented', $control->fields['implementation_status'], 'État « Vérifié » retiré.');
        $this->assertSame(0, (int) $control->fields['is_reviewed'], 'Le retrait n\'est pas une revue de la SoA.');
        $this->assertSame($this->day(-1) . '|overdue', $control->fields['test_reminder_key']);
        $this->assertSame(1, countElementsInTable(AuditLog::getTable(), [
            'action'   => 'update',
            'itemtype' => Control::class,
            'items_id' => $control->getID(),
            'details'  => ['LIKE', '%effectiveness_test_overdue%'],
        ]), 'Retrait tracé dans le journal d\'audit.');
        $mails = $this->mailsFor($control, 'ines@example.org');
        $this->assertCount(1, $mails, 'Rappel au responsable du plan de test.');
        $this->assertStringContainsString('A.5.15', $mails[0]['name']);
        $withdrawn = __('The "Verified" status was withdrawn: the last effectiveness test has expired.', 'grcmanager');
        $this->assertStringContainsString($withdrawn, (string) $mails[0]['body_text']);
        $this->assertStringNotContainsString('##', (string) $mails[0]['body_text'], 'Toutes les balises remplacées.');
        $this->assertStringNotContainsString('##', (string) $mails[0]['body_html']);

        // Deuxième passage : rien de nouveau, ni rappel ni trace.
        ControlTestReminder::run(date('Y-m-d'));
        $this->assertCount(1, $this->mailsFor($control, 'ines@example.org'));
        $this->assertSame(1, countElementsInTable(AuditLog::getTable(), [
            'action'   => 'update',
            'itemtype' => Control::class,
            'items_id' => $control->getID(),
        ]));
    }

    public function testDueSoonReminderKeepsTheVerifiedStatus(): void
    {
        $tester = $this->user('Hugo', 'Proche', $this->readerProfile(), 0);
        $control = $this->control('A.5.16', 'verified', $tester);
        $this->addTest($control, $this->day(-350), 'effective', $this->day(10));

        ControlTestReminder::run(date('Y-m-d'));

        $control->getFromDB($control->getID());
        $this->assertSame('verified', $control->fields['implementation_status']);
        $this->assertSame($this->day(10) . '|due_soon', $control->fields['test_reminder_key']);
        $mails = $this->mailsFor($control, 'hugo@example.org');
        $this->assertCount(1, $mails);
        $this->assertStringNotContainsString(
            __('The "Verified" status was withdrawn: the last effectiveness test has expired.', 'grcmanager'),
            (string) $mails[0]['body_text'],
            'Encadré du retrait masqué quand l\'état n\'a pas changé.'
        );

        // Un nouveau test efficace repousse l'échéance : plus de rappel.
        $this->addTest($control, date('Y-m-d'), 'effective', $this->day(365));
        ControlTestReminder::run(date('Y-m-d'));
        $this->assertCount(1, $this->mailsFor($control, 'hugo@example.org'));
    }

    public function testNoContentForARecipientWhoCannotReadTheControl(): void
    {
        global $DB;

        $noRight = $this->user('Sans', 'Droit', $this->profile(0), 0);
        $control = $this->control('A.5.17', 'implemented', $noRight);
        $this->addTest($control, $this->day(-400), 'effective', $this->day(-2));
        // Cible ajoutée à la main par un administrateur : l'adresse de l'administrateur GLPI, sans
        // compte derrière elle.
        $notification = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => Control::class, 'event' => ControlTestReminder::EVENT],
        ])->current();
        $DB->insert('glpi_notificationtargets', [
            'notifications_id' => $notification['id'],
            'type'             => \Notification::USER_TYPE,
            'items_id'         => \Notification::GLOBAL_ADMINISTRATOR,
        ]);

        ControlTestReminder::run(date('Y-m-d'));

        $this->assertSame([], $this->mailsFor($control, 'sans@example.org'), 'Aucun e-mail sans le droit de lecture.');
        $this->assertSame([], $this->mailsFor($control, 'glpi@example.org'), 'Adresse sans compte GLPI.');
        $this->assertFalse(ControlTestReminder::canReceive($noRight, 0));
        $this->assertFalse(ControlTestReminder::canReceive(0, 0), 'Adresse sans compte GLPI.');

        // Droit de lecture, mais seulement dans une entité fille : rien pour la mesure (entité racine).
        $child = $this->createTestEntity(0, 'PHPUnit entité fille ' . bin2hex(random_bytes(3)));
        $elsewhere = $this->user('Ailleurs', 'Fille', $this->readerProfile(), $child);
        $this->assertFalse(ControlTestReminder::canReceive($elsewhere, 0));
        $this->assertTrue(ControlTestReminder::canReceive($elsewhere, $child));
    }

    public function testCisoGroupFollowsTheSetting(): void
    {
        $reader = $this->readerProfile();
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit RSSI ' . bin2hex(random_bytes(3)), 'entities_id' => 0]);
        $ciso = $this->user('Rachel', 'Rssi', $reader, 0);
        $outsider = $this->user('Oscar', 'Sansdroit', $this->profile(0), 0);
        foreach ([$ciso, $outsider] as $member) {
            (new Group_User())->add(['groups_id' => $groupId, 'users_id' => $member]);
        }
        // Groupe RSSI tel que l'enregistre Configuration GLPI Auto (même clé que l'onglet Audits).
        AuditTeam::setCisoGroupId($groupId);

        $overdue = $this->control('A.5.18', 'implemented', 0);
        $this->addTest($overdue, $this->day(-400), 'effective', $this->day(-3));
        $soon = $this->control('A.5.19', 'implemented', 0);
        $this->addTest($soon, $this->day(-350), 'effective', $this->day(5));

        ControlTestReminder::saveCisoMode(ControlTestRules::CISO_NEVER);
        ControlTestReminder::run(date('Y-m-d'));
        $this->assertSame([], $this->mailsFor($overdue, 'rachel@example.org'), 'Réglage « jamais ».');

        $this->rearm([$overdue, $soon]);
        ControlTestReminder::saveCisoMode(ControlTestRules::CISO_OVERDUE);
        ControlTestReminder::run(date('Y-m-d'));
        $this->assertCount(1, $this->mailsFor($overdue, 'rachel@example.org'), 'Test en retard.');
        $this->assertSame([], $this->mailsFor($soon, 'rachel@example.org'), 'Pas pour un test à venir.');
        $this->assertSame([], $this->mailsFor($overdue, 'oscar@example.org'), 'Membre du groupe sans droit.');

        $this->rearm([$overdue, $soon]);
        ControlTestReminder::saveCisoMode(ControlTestRules::CISO_ALWAYS);
        ControlTestReminder::run(date('Y-m-d'));
        $this->assertCount(1, $this->mailsFor($soon, 'rachel@example.org'), 'Réglage « toujours ».');
        $this->assertSame([], $this->mailsFor($soon, 'oscar@example.org'));
    }

    public function testBrandColourWithAndWithoutConfigurationGlpiAuto(): void
    {
        Config::setConfigurationValues(ControlTestReminder::CGA_CONTEXT, [
            ControlTestReminder::CGA_BRAND_COLOR_KEY => '#AA3366',
        ]);

        // Sans Configuration GLPI Auto actif, sa clé est ignorée.
        NotificationSupport::$forceCgaActive = false;
        $this->assertSame(ControlTestRules::DEFAULT_BRAND_COLOR, ControlTestReminder::brandColor());

        NotificationSupport::$forceCgaActive = true;
        $this->assertSame('#aa3366', ControlTestReminder::brandColor());

        $tester = $this->user('Clara', 'Charte', $this->readerProfile(), 0);
        $control = $this->control('A.5.20', 'implemented', $tester);
        $this->addTest($control, $this->day(-400), 'effective', $this->day(-1));
        ControlTestReminder::run(date('Y-m-d'));
        $mails = $this->mailsFor($control, 'clara@example.org');
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('#aa3366', (string) $mails[0]['body_html']);

        // Valeur publiée invalide : couleur par défaut, rien d'injecté.
        Config::setConfigurationValues(ControlTestReminder::CGA_CONTEXT, [
            ControlTestReminder::CGA_BRAND_COLOR_KEY => 'red;background:url(x)',
        ]);
        $this->assertSame(ControlTestRules::DEFAULT_BRAND_COLOR, ControlTestReminder::brandColor());

        // Sans clé publiée (Configuration GLPI Auto plus ancien) : couleur par défaut.
        Config::deleteConfigurationValues(ControlTestReminder::CGA_CONTEXT, [ControlTestReminder::CGA_BRAND_COLOR_KEY]);
        $this->assertSame(ControlTestRules::DEFAULT_BRAND_COLOR, ControlTestReminder::brandColor());
    }

    public function testSoaListShowsLastTestAndNextDueDate(): void
    {
        $control = $this->control('A.5.21', 'implemented', 0, 6);
        $this->addTest($control, '2026-01-31', 'effective', null);
        $other = $this->control('A.5.22', 'implemented', 0, 12);
        $this->addTest($other, '2026-03-01', 'partial', '2026-09-15');

        $data = \Search::getDatas(Control::class, [
            'criteria' => [['field' => 1, 'searchtype' => 'contains', 'value' => 'A.5.2']],
        ], [1, 9, 10]);
        $rows = [];
        foreach ($data['data']['rows'] as $row) {
            $rows[(string) $row['raw']['ITEM_' . Control::class . '_1']] = [
                (string) $row['raw']['ITEM_' . Control::class . '_9'],
                (string) $row['raw']['ITEM_' . Control::class . '_10'],
            ];
        }
        // Fin de mois : le 31 janvier plus six mois donne le 31 juillet ; sans plan ni test, rien.
        $this->assertSame(['2026-01-31', '2026-07-31'], $rows['A.5.21'] ?? null);
        $this->assertSame(['2026-03-01', '2026-09-15'], $rows['A.5.22'] ?? null);
        $this->assertSame(['', ''], $rows['A.5.23'] ?? null);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    private function control(string $code, string $status, int $tester, int $frequency = 12): Control
    {
        global $DB;

        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code]));
        $DB->update(Control::getTable(), [
            'implementation_status' => $status,
            'applicability'         => 'yes',
            'is_reviewed'           => 0,
            'test_frequency_months' => $frequency,
            'users_id_tester'       => $tester,
            'test_reminder_key'     => '',
        ], ['id' => $control->getID()]);
        $DB->delete(ControlTest::getTable(), ['plugin_grcmanager_controls_id' => $control->getID()]);
        $control->getFromDB($control->getID());

        return $control;
    }

    private function addTest(Control $control, string $date, string $result, ?string $next): void
    {
        global $DB;

        // Insertion directe : un test daté de plus d'un an, sans effet sur l'état de la mesure.
        $DB->insert(ControlTest::getTable(), [
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => $date,
            'method'                        => 'inspection',
            'result'                        => $result,
            'users_id'                      => 2,
            'comment'                       => 'PHPUnit',
            'next_test_date'                => $next,
        ]);
    }

    /**
     * @param list<Control> $controls
     */
    private function rearm(array $controls): void
    {
        global $DB;

        foreach ($controls as $control) {
            $DB->update(Control::getTable(), ['test_reminder_key' => ''], ['id' => $control->getID()]);
            $DB->delete(QueuedNotification::getTable(), [
                'itemtype' => Control::class,
                'items_id' => $control->getID(),
            ]);
        }
    }

    private function profile(int $rights): int
    {
        global $DB;

        $profileId = (int) (new Profile())->add([
            'name'      => 'PHPUnit rappel ' . bin2hex(random_bytes(3)),
            'interface' => 'central',
        ]);
        $DB->updateOrInsert('glpi_profilerights', ['rights' => $rights], [
            'profiles_id' => $profileId,
            'name'        => Control::RIGHTNAME,
        ]);

        return $profileId;
    }

    private function readerProfile(): int
    {
        return $this->profile(READ);
    }

    private function user(string $firstname, string $realname, int $profileId, int $entitiesId): int
    {
        $usersId = $this->createTestUser($firstname, $realname);
        (new UserEmail())->add([
            'users_id'   => $usersId,
            'email'      => strtolower($firstname) . '@example.org',
            'is_default' => 1,
        ]);
        // Seule habilitation de l'utilisateur : le profil donné, dans l'entité donnée.
        global $DB;
        $DB->delete(Profile_User::getTable(), ['users_id' => $usersId]);
        (new Profile_User())->add(['users_id' => $usersId, 'profiles_id' => $profileId, 'entities_id' => $entitiesId]);

        return $usersId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mailsFor(Control $control, string $email): array
    {
        return array_values((new QueuedNotification())->find([
            'itemtype'  => Control::class,
            'items_id'  => $control->getID(),
            'recipient' => $email,
        ]));
    }
}
