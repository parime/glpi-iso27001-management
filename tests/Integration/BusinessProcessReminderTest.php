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

use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessItem;
use GlpiPlugin\Grcmanager\BusinessProcessLink;
use GlpiPlugin\Grcmanager\BusinessProcessRecovery;
use GlpiPlugin\Grcmanager\BusinessProcessReminder;
use GlpiPlugin\Grcmanager\NotificationSupport;
use GlpiPlugin\Grcmanager\NotificationTargetBusinessProcess;
use GlpiPlugin\Grcmanager\PeriodicReview;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use Group;
use Group_User;
use Profile;
use Profile_User;
use QueuedNotification;
use UserEmail;

/**
 * Issue #377, quatrième étape : rappels des BIA à revoir et des tests de reprise à refaire (un seul
 * par échéance et par état, destinataires limités à ceux qui peuvent lire le processus dans son
 * entité), indicateurs du tableau de bord et carte des dépendances. Données fictives.
 */
final class BusinessProcessReminderTest extends GrcmanagerIntegrationTestCase
{
    private const MAIL_SETTINGS = [
        'use_notifications'     => 1,
        'notifications_mailing' => 1,
        'admin_email'           => 'glpi@example.org',
        'from_email'            => 'glpi@example.org',
    ];

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        global $CFG_GLPI;

        parent::setUp();
        foreach (self::MAIL_SETTINGS as $key => $value) {
            $this->savedSettings[$key] = $CFG_GLPI[$key] ?? null;
            $CFG_GLPI[$key] = $value;
        }
        NotificationSupport::$forceCgaActive = false;
        AuditTeam::setCisoGroupId(0);
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

    public function testNotificationsAndTaskAreInstalledWithOwnTargets(): void
    {
        global $DB;

        foreach ([BusinessProcessReminder::EVENT_BIA, BusinessProcessReminder::EVENT_RECOVERY] as $event) {
            $notification = $DB->request([
                'FROM'  => 'glpi_notifications',
                'WHERE' => ['itemtype' => BusinessProcess::class, 'event' => $event],
            ])->current();
            $this->assertNotNull($notification, 'Notification créée à l\'installation : ' . $event);
            $targets = array_map('intval', array_column(iterator_to_array($DB->request([
                'FROM'  => 'glpi_notificationtargets',
                'WHERE' => ['notifications_id' => $notification['id']],
            ]), false), 'items_id'));
            sort($targets);
            $this->assertSame(
                [NotificationTargetBusinessProcess::TARGET_OWNER, NotificationTargetBusinessProcess::TARGET_CISO_GROUP],
                $targets
            );
        }
        $this->assertGreaterThanOrEqual(1000, NotificationTargetBusinessProcess::TARGET_OWNER);
        $this->assertGreaterThanOrEqual(1000, NotificationTargetBusinessProcess::TARGET_CISO_GROUP);
        $this->assertSame(1, countElementsInTable('glpi_crontasks', [
            'itemtype' => BusinessProcess::class,
            'name'     => BusinessProcessReminder::CRON_NAME,
        ]));
    }

    public function testDueDatesFollowThePeriodicReviewFrequency(): void
    {
        global $DB;

        $process = $this->process('Paie DEMO echeances', 0, ['bia_date' => '2026-01-31']);
        $this->assertSame('2027-01-31', BusinessProcessReminder::biaDueDate($process->fields), 'Revue annuelle.');

        $DB->update(PeriodicReview::getTable(), ['frequency_months' => 6], ['review_key' => BusinessProcessReminder::BIA_REVIEW]);
        $this->assertSame(6, BusinessProcessReminder::frequency(BusinessProcessReminder::BIA_REVIEW));
        // Fin de mois : le 31 janvier plus six mois donne le 31 juillet.
        $this->assertSame('2026-07-31', BusinessProcessReminder::biaDueDate($process->fields));
        $this->assertNull(BusinessProcessReminder::biaDueDate(['bia_date' => null]), 'Sans BIA, aucune échéance.');

        $this->assertSame('2027-03-15', BusinessProcessReminder::recoveryDueDate('2026-03-15'));
        $this->assertNull(BusinessProcessReminder::recoveryDueDate(null), 'Jamais testé, aucune échéance.');
    }

    public function testOverdueBiaIsRemindedOnceThenRearmedByANewBia(): void
    {
        $owner = $this->user('Paula', 'Proprietaire', $this->profile(READ), 0);
        $process = $this->process('Paie DEMO BIA', $owner, ['bia_date' => $this->day(-400)]);
        $due = BusinessProcessReminder::biaDueDate($process->fields);

        $result = BusinessProcessReminder::run(date('Y-m-d'));

        $this->assertSame(1, $result[BusinessProcessReminder::EVENT_BIA]);
        $process->getFromDB($process->getID());
        $this->assertSame($due . '|overdue', $process->fields['bia_reminder_key']);
        $mails = $this->mailsFor($process, 'paula@example.org');
        $this->assertCount(1, $mails, 'Rappel au responsable du processus.');
        $this->assertStringContainsString('Paie DEMO BIA', (string) $mails[0]['name']);
        $this->assertStringNotContainsString('##', (string) $mails[0]['body_text'], 'Toutes les balises remplacées.');
        $this->assertStringNotContainsString('##', (string) $mails[0]['body_html']);
        $this->assertStringContainsString('BusinessImpact', (string) $mails[0]['body_text'], 'Lien vers l\'onglet de la BIA.');
        $this->assertSame(0, countElementsInTable('glpi_logs', [
            'itemtype'      => BusinessProcess::class,
            'items_id'      => $process->getID(),
            'id_search_option' => ['>', 0],
        ]), 'Le rappel ne modifie pas le processus.');

        // Deuxième passage : rien de nouveau.
        BusinessProcessReminder::run(date('Y-m-d'));
        $this->assertCount(1, $this->mailsFor($process, 'paula@example.org'), 'Un seul rappel par échéance.');

        // Nouvelle BIA : échéance repoussée, rappel réarmé sans envoi.
        $process->update(['id' => $process->getID(), 'bia_date' => date('Y-m-d')]);
        BusinessProcessReminder::run(date('Y-m-d'));
        $process->getFromDB($process->getID());
        $this->assertSame('', $process->fields['bia_reminder_key']);
        $this->assertCount(1, $this->mailsFor($process, 'paula@example.org'));
    }

    public function testRecoveryTestDueSoonThenOverdueGivesOneReminderPerState(): void
    {
        global $DB;

        $owner = $this->user('Remi', 'Reprise', $this->profile(READ), 0);
        $process = $this->process('Paie DEMO reprise', $owner, ['bia_date' => date('Y-m-d'), 'rto_hours' => 4]);
        $DB->insert(BusinessProcessRecovery::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'test_date'        => date('Y-m-d', strtotime('-12 months +10 days')),
            'measured_minutes' => 180,
        ]);

        BusinessProcessReminder::run(date('Y-m-d'));
        BusinessProcessReminder::run(date('Y-m-d'));

        $mails = $this->mailsFor($process, 'remi@example.org');
        $this->assertCount(1, $mails, 'Un seul rappel « à venir ».');
        $this->assertStringContainsString('3 h 00 min', (string) $mails[0]['body_text'], 'Temps mesuré au dernier test.');
        $this->assertStringContainsString('BusinessProcessRecovery', (string) $mails[0]['body_text']);
        $process->getFromDB($process->getID());
        $this->assertStringEndsWith('|due_soon', (string) $process->fields['recovery_reminder_key']);
        $this->assertSame('', (string) $process->fields['bia_reminder_key'], 'BIA récente : aucun rappel.');

        // Le lendemain de l'échéance : un rappel « en retard », un seul.
        $after = date('Y-m-d', strtotime('+11 days'));
        BusinessProcessReminder::run($after);
        BusinessProcessReminder::run($after);
        $this->assertCount(2, $this->mailsFor($process, 'remi@example.org'));
    }

    public function testNoContentForARecipientWhoCannotReadTheProcess(): void
    {
        global $DB;

        // Responsable sans le droit de la continuité, et l'adresse de l'administrateur GLPI ajoutée
        // à la main comme cible (sans compte GLPI derrière elle).
        $noRight = $this->user('Sans', 'Continuite', $this->profile(0), 0);
        $process = $this->process('Paie DEMO sans droit', $noRight, ['bia_date' => $this->day(-400)]);
        $notification = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => BusinessProcess::class, 'event' => BusinessProcessReminder::EVENT_BIA],
        ])->current();
        $DB->insert('glpi_notificationtargets', [
            'notifications_id' => $notification['id'],
            'type'             => \Notification::USER_TYPE,
            'items_id'         => \Notification::GLOBAL_ADMINISTRATOR,
        ]);

        BusinessProcessReminder::run(date('Y-m-d'));

        $this->assertSame([], $this->mailsFor($process, 'sans@example.org'), 'Aucun e-mail sans le droit.');
        $this->assertSame([], $this->mailsFor($process, 'glpi@example.org'), 'Adresse sans compte GLPI.');
        $this->assertFalse(BusinessProcessReminder::canReceive($noRight, $process));
        $this->assertFalse(BusinessProcessReminder::canReceive(0, $process));
        $process->getFromDB($process->getID());
        $this->assertStringEndsWith('|overdue', (string) $process->fields['bia_reminder_key'], 'Rappel tout de même noté.');
    }

    public function testRecipientNeedsTheRightInTheEntityOfTheProcess(): void
    {
        $entityA = $this->createTestEntity(0, 'BIA DEMO rappel A ' . bin2hex(random_bytes(3)));
        $entityB = $this->createTestEntity(0, 'BIA DEMO rappel B ' . bin2hex(random_bytes(3)));
        $reader = $this->profile(READ);
        // Droit de la continuité, mais seulement dans l'entité B.
        $elsewhere = $this->user('Bruno', 'Ailleurs', $reader, $entityB);
        $process = $this->process('Paie DEMO entite A', $elsewhere, [
            'entities_id' => $entityA,
            'bia_date'    => $this->day(-400),
        ]);
        $this->assertFalse(BusinessProcessReminder::canReceive($elsewhere, $process));

        // Groupe RSSI : un membre avec le droit dans A, un autre dans B seulement.
        $groupId = (int) (new Group())->add(['name' => 'PHPUnit RSSI ' . bin2hex(random_bytes(3)), 'entities_id' => 0]);
        $ciso = $this->user('Carla', 'Rssi', $reader, $entityA);
        foreach ([$ciso, $elsewhere] as $member) {
            (new Group_User())->add(['groups_id' => $groupId, 'users_id' => $member]);
        }
        AuditTeam::setCisoGroupId($groupId);

        BusinessProcessReminder::run(date('Y-m-d'));

        $this->assertCount(1, $this->mailsFor($process, 'carla@example.org'), 'Groupe RSSI, droit dans l\'entité.');
        $this->assertSame([], $this->mailsFor($process, 'bruno@example.org'), 'Droit dans une autre entité seulement.');

        // Droit dans une entité fille de A : processus de A rappelé seulement s'il est récursif.
        $child = (int) (new \Entity())->add(['name' => 'BIA DEMO fille ' . bin2hex(random_bytes(3)), 'entities_id' => $entityA]);
        $this->assertGreaterThan(0, $child);
        $inChild = $this->user('Fanny', 'Fille', $reader, $child);
        $this->assertFalse(BusinessProcessReminder::canReceive($inChild, $process));
        $recursive = $this->process('Paie DEMO recursif', $inChild, [
            'entities_id'  => $entityA,
            'is_recursive' => 1,
            'bia_date'     => $this->day(-400),
        ]);
        $this->assertTrue(BusinessProcessReminder::canReceive($inChild, $recursive));
    }

    public function testUpgradeRecordsCurrentDeadlinesWithoutSendingReminders(): void
    {
        $owner = $this->user('Ugo', 'Upgrade', $this->profile(READ), 0);
        $process = $this->process('Paie DEMO mise a jour', $owner, ['bia_date' => $this->day(-400)]);

        BusinessProcessReminder::run(date('Y-m-d'), false);
        BusinessProcessReminder::run(date('Y-m-d'));

        $this->assertSame([], $this->mailsFor($process, 'ugo@example.org'), 'Pas de rafale à la mise à jour.');
        $process->getFromDB($process->getID());
        $this->assertStringEndsWith('|overdue', (string) $process->fields['bia_reminder_key']);
    }

    public function testReminderKeysCannotBeWrittenFromTheForm(): void
    {
        $process = $this->process('Paie DEMO cle', 0, []);
        $process->update(['id' => $process->getID(), 'bia_reminder_key' => '2099-01-01|overdue']);
        $process->getFromDB($process->getID());
        $this->assertSame('', (string) $process->fields['bia_reminder_key']);
    }

    public function testDashboardCountsFollowEntitiesAndTheContinuityRight(): void
    {
        global $DB;

        $before = BusinessProcessReminder::dashboardCounts(date('Y-m-d'));
        $other = $this->createTestEntity(0, 'BIA DEMO tableau B ' . bin2hex(random_bytes(3)));
        $this->process('Paie DEMO sans BIA', 0, []);
        $this->process('Paie DEMO BIA echue', 0, ['bia_date' => $this->day(-400)]);
        $this->process('Paie DEMO BIA a jour', 0, ['bia_date' => date('Y-m-d')]);
        $slow = $this->process('Paie DEMO trop lente', 0, ['bia_date' => date('Y-m-d'), 'rto_hours' => 4]);
        $DB->insert(BusinessProcessRecovery::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $slow->getID(),
            'test_date' => date('Y-m-d'), 'measured_minutes' => 300,
        ]);
        // Un test plus ancien, plus rapide, ne compte pas : seul le dernier test est comparé.
        $DB->insert(BusinessProcessRecovery::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $slow->getID(),
            'test_date' => $this->day(-30), 'measured_minutes' => 60,
        ]);
        $hiddenSlow = $this->process('Paie DEMO B', 0, ['entities_id' => $other, 'rto_hours' => 1]);
        $DB->insert(BusinessProcessRecovery::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $hiddenSlow->getID(),
            'test_date' => date('Y-m-d'), 'measured_minutes' => 300,
        ]);

        $counts = BusinessProcessReminder::dashboardCounts(date('Y-m-d'));
        $this->assertSame($before['bia_due'] + 3, $counts['bia_due']);
        $this->assertSame($before['recovery_above_rto'] + 2, $counts['recovery_above_rto']);

        // La personne perd l'accès à l'entité B : ses processus ne sont plus comptés.
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
        $counts = BusinessProcessReminder::dashboardCounts(date('Y-m-d'));
        $this->assertSame($before['bia_due'] + 2, $counts['bia_due']);
        $this->assertSame($before['recovery_above_rto'] + 1, $counts['recovery_above_rto']);
        $card = SecuredDashboardCards::businessProcessesBiaDueCount();
        $this->assertSame($counts['bia_due'], $card['number']);
        $this->assertArrayHasKey('url', $card);

        // Sans le droit de la continuité (même avec tout le reste) : carte vide et hors catalogue.
        $catalog = [
            'grcmanager_business_processes_bia_due' => [
                'provider' => SecuredDashboardCards::class . '::businessProcessesBiaDueCount',
            ],
            'grcmanager_business_processes_recovery_above_rto' => [
                'provider' => SecuredDashboardCards::class . '::businessProcessesRecoveryAboveRtoCount',
            ],
        ];
        $saved = $_SESSION['glpiactiveprofile'][BusinessProcess::RIGHTNAME];
        $_SESSION['glpiactiveprofile'][BusinessProcess::RIGHTNAME] = 0;
        try {
            $this->assertSame(['bia_due' => 0, 'recovery_above_rto' => 0], BusinessProcessReminder::dashboardCounts(date('Y-m-d')));
            $card = SecuredDashboardCards::businessProcessesRecoveryAboveRtoCount();
            $this->assertSame(0, $card['number']);
            $this->assertArrayNotHasKey('url', $card);
            $this->assertFalse(SecuredDashboardCards::canShow('businessProcessesBiaDueCount'));
            $this->assertSame([], SecuredDashboardCards::filterCatalog($catalog), 'Carte retirée du catalogue.');
        } finally {
            $_SESSION['glpiactiveprofile'][BusinessProcess::RIGHTNAME] = $saved;
        }
        $this->assertSame($catalog, SecuredDashboardCards::filterCatalog($catalog));
    }

    public function testDependencyMapNeverNamesWhatCannotBeRead(): void
    {
        global $DB;

        $other = $this->createTestEntity(0, 'BIA DEMO carte B ' . bin2hex(random_bytes(3)));
        $process = $this->process('Paie DEMO carte', 0, ['rto_hours' => 24]);
        $upstream = $this->process('Temps DEMO carte', 0, ['rto_hours' => 72]);
        $hiddenProcess = $this->process('Annuaire DEMO B', 0, ['entities_id' => $other]);
        $downstream = $this->process('Reporting DEMO carte', 0, []);
        foreach ([[$process, $upstream], [$process, $hiddenProcess], [$downstream, $process]] as [$down, $up]) {
            $this->assertGreaterThan(0, (int) (new BusinessProcessLink())->add([
                'plugin_grcmanager_businessprocesses_id' => $down->getID(),
                BusinessProcessLink::UPSTREAM_FIELD      => $up->getID(),
            ]));
        }
        $vm = (int) (new \Computer())->add(['name' => 'VM-DEMO-CARTE', 'entities_id' => 0]);
        $host = (int) (new \Computer())->add(['name' => 'HV-DEMO-CARTE', 'entities_id' => 0]);
        $hiddenItem = (int) (new \Computer())->add(['name' => 'SRV-DEMO-CARTE-B', 'entities_id' => $other]);
        $DB->insert('glpi_impactrelations', [
            'itemtype_source' => 'Computer', 'items_id_source' => $host,
            'itemtype_impacted' => 'Computer', 'items_id_impacted' => $vm,
        ]);
        foreach ([$vm, $hiddenItem] as $itemsId) {
            $this->assertGreaterThan(0, (int) (new BusinessProcessItem())->add([
                'plugin_grcmanager_businessprocesses_id' => $process->getID(),
                'itemtype' => 'Computer', 'items_id' => $itemsId,
            ]));
        }

        // La personne perd l'accès à l'entité B.
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        $map = BusinessProcessLink::mapElements($process);
        $json = (string) json_encode($map['elements']);
        foreach (['Paie DEMO carte', 'Temps DEMO carte', 'Reporting DEMO carte', 'VM-DEMO-CARTE', 'HV-DEMO-CARTE'] as $name) {
            $this->assertStringContainsString($name, $json);
        }
        $this->assertStringNotContainsString('Annuaire DEMO B', $json, 'Processus illisible jamais nommé.');
        $this->assertStringNotContainsString('SRV-DEMO-CARTE-B', $json, 'Élément illisible jamais nommé.');
        $this->assertStringNotContainsString('Computer:' . $hiddenItem, $json, 'Ni même désigné.');
        $this->assertSame(2, $map['hidden']);

        $edges = [];
        foreach ($map['elements'] as $element) {
            if (isset($element['data']['source'])) {
                $edges[] = $element['data']['source'] . '>' . $element['data']['target'];
            }
        }
        $key = static fn (BusinessProcess $p): string => BusinessProcess::class . ':' . $p->getID();
        $this->assertContains($key($upstream) . '>' . $key($process), $edges, 'Processus amont vers le processus.');
        $this->assertContains($key($process) . '>' . $key($downstream), $edges, 'Processus vers le processus aval.');
        $this->assertContains('Computer:' . $vm . '>' . $key($process), $edges, 'Élément lié vers le processus.');
        $this->assertContains('Computer:' . $host . '>Computer:' . $vm, $edges, 'Analyse d\'impact de GLPI.');
        $this->assertCount(4, $edges);

        // Onglet rendu : nœuds en attribut échappé, mention de ce qui est caché, sans le nommer.
        ob_start();
        BusinessProcessLink::displayTabContentForItem($process, 2);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('data-elements=', $html);
        $this->assertStringNotContainsString('Annuaire DEMO B', $html);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function process(string $name, int $owner, array $input): BusinessProcess
    {
        $process = new BusinessProcess();
        $id = (int) $process->add($input + ['name' => $name, 'entities_id' => 0, 'users_id' => $owner]);
        $this->assertGreaterThan(0, $id);
        $process->getFromDB($id);

        return $process;
    }

    private function profile(int $rights): int
    {
        global $DB;

        $profileId = (int) (new Profile())->add([
            'name'      => 'PHPUnit continuite ' . bin2hex(random_bytes(3)),
            'interface' => 'central',
        ]);
        $DB->updateOrInsert('glpi_profilerights', ['rights' => $rights], [
            'profiles_id' => $profileId,
            'name'        => BusinessProcess::RIGHTNAME,
        ]);

        return $profileId;
    }

    private function user(string $firstname, string $realname, int $profileId, int $entitiesId): int
    {
        global $DB;

        $usersId = $this->createTestUser($firstname, $realname);
        (new UserEmail())->add([
            'users_id'   => $usersId,
            'email'      => strtolower($firstname) . '@example.org',
            'is_default' => 1,
        ]);
        $DB->delete(Profile_User::getTable(), ['users_id' => $usersId]);
        (new Profile_User())->add(['users_id' => $usersId, 'profiles_id' => $profileId, 'entities_id' => $entitiesId]);

        return $usersId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mailsFor(BusinessProcess $process, string $email): array
    {
        return array_values((new QueuedNotification())->find([
            'itemtype'  => BusinessProcess::class,
            'items_id'  => $process->getID(),
            'recipient' => $email,
        ]));
    }
}
