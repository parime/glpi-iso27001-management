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
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\KriAlert;
use GlpiPlugin\Grcmanager\NotificationSupport;
use GlpiPlugin\Grcmanager\NotificationTargetRiskIndicator;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use Group;
use Group_User;
use Profile;
use Profile_User;
use QueuedNotification;
use UserEmail;

/**
 * Issue #378, deuxième étape : alerte d'un KRI au rouge (ou à l'orange) et au retour au vert, une
 * fois par changement d'état, par le relevé manuel comme par la tâche planifiée ; destinataires
 * limités à qui lit le KRI et les données mesurées ; charte de Configuration GLPI Auto avec et sans
 * lui ; carte de tendance.
 */
final class KriAlertTest extends GrcmanagerIntegrationTestCase
{
    private const MAIL_SETTINGS = [
        'use_notifications'     => 1,
        'notifications_mailing' => 1,
        'admin_email'           => 'glpi@example.org',
        'from_email'            => 'glpi@example.org',
    ];

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    /** @var array<int, int|string> */
    private array $activeEntities = [];

    protected function setUp(): void
    {
        global $CFG_GLPI;

        parent::setUp();
        foreach (self::MAIL_SETTINGS as $key => $value) {
            $this->savedSettings[$key] = $CFG_GLPI[$key] ?? null;
            $CFG_GLPI[$key] = $value;
        }
        $this->activeEntities = $_SESSION['glpiactiveentities'];
        NotificationSupport::$forceCgaActive = false;
        KriAlert::saveLevel(KriRules::RED);
        AuditTeam::setCisoGroupId(0);
    }

    protected function tearDown(): void
    {
        global $CFG_GLPI;

        foreach ($this->savedSettings as $key => $value) {
            $CFG_GLPI[$key] = $value;
        }
        $_SESSION['glpiactiveentities'] = $this->activeEntities;
        NotificationSupport::$forceCgaActive = null;
        parent::tearDown();
    }

    public function testNotificationsAreInstalledWithOwnTargets(): void
    {
        global $DB;

        foreach ([KriAlert::EVENT_ALERT, KriAlert::EVENT_CLEARED] as $event) {
            $notification = $DB->request([
                'FROM'  => 'glpi_notifications',
                'WHERE' => ['itemtype' => RiskIndicator::class, 'event' => $event],
            ])->current();
            $this->assertNotNull($notification, 'Notification créée à l\'installation : ' . $event);
            $targets = array_map('intval', array_column(iterator_to_array($DB->request([
                'FROM'  => 'glpi_notificationtargets',
                'WHERE' => ['notifications_id' => $notification['id']],
            ]), false), 'items_id'));
            sort($targets);
            $this->assertSame(
                [NotificationTargetRiskIndicator::TARGET_OWNER, NotificationTargetRiskIndicator::TARGET_CISO_GROUP],
                $targets
            );
        }
        $this->assertGreaterThanOrEqual(1000, NotificationTargetRiskIndicator::TARGET_OWNER);
        $this->assertGreaterThanOrEqual(1000, NotificationTargetRiskIndicator::TARGET_CISO_GROUP);
    }

    public function testManualValuesAlertOncePerStateChangeAndWhenBackToGreen(): void
    {
        $owner = $this->user('Olga', 'Responsable', $this->profile([RiskIndicator::RIGHTNAME => READ]), 0);
        $kri = $this->kri(['users_id' => $owner]);

        $kri->recordValue(3, $this->day(-4));
        $this->assertSame([], $this->mailsFor($kri, 'olga@example.org'), 'Vert : rien.');

        $kri->recordValue(12, $this->day(-3));
        $mails = $this->mailsFor($kri, 'olga@example.org');
        $this->assertCount(1, $mails, 'Passage au rouge : une alerte.');
        $this->assertStringContainsString($kri->fields['name'], (string) $mails[0]['name']);
        $this->assertStringContainsString(
            __('Risk indicator (KRI) in alert', 'grcmanager'),
            (string) $mails[0]['name']
        );
        $this->assertStringNotContainsString('##', (string) $mails[0]['body_text'], 'Toutes les balises remplacées.');
        $this->assertStringNotContainsString('##', (string) $mails[0]['body_html']);
        $this->assertSame(KriRules::RED, $this->fresh($kri)->fields['alert_status']);

        $kri->recordValue(15, $this->day(-2));
        $this->assertCount(1, $this->mailsFor($kri, 'olga@example.org'), 'Toujours au rouge : pas de nouvelle alerte.');

        $kri->recordValue(2, $this->day(-1));
        $mails = $this->mailsFor($kri, 'olga@example.org');
        $this->assertCount(2, $mails, 'Retour au vert : un message.');
        $this->assertStringContainsString(
            __('Risk indicator (KRI) back to green', 'grcmanager'),
            (string) $mails[1]['name']
        );
        $this->assertSame('', $this->fresh($kri)->fields['alert_status']);

        $kri->recordValue(11, date('Y-m-d'));
        $this->assertCount(3, $this->mailsFor($kri, 'olga@example.org'), 'Nouveau passage au rouge : nouvelle alerte.');
    }

    public function testOrangeAtRedLevelDoesNotAlertButOrangeLevelDoes(): void
    {
        $owner = $this->user('Oriane', 'Orange', $this->profile([RiskIndicator::RIGHTNAME => READ]), 0);
        $kri = $this->kri(['users_id' => $owner]);
        $kri->recordValue(6, $this->day(-2));
        $this->assertSame([], $this->mailsFor($kri, 'oriane@example.org'), 'Orange au niveau rouge : rien.');

        KriAlert::saveLevel(KriRules::ORANGE);
        $other = $this->kri(['users_id' => $owner]);
        $other->recordValue(6, $this->day(-2));
        $this->assertCount(1, $this->mailsFor($other, 'oriane@example.org'), 'Niveau orange : alerte.');
        $other->recordValue(12, $this->day(-1));
        $this->assertCount(2, $this->mailsFor($other, 'oriane@example.org'), 'Orange puis rouge : nouvelle alerte.');

        KriAlert::saveLevel('inconnu');
        $this->assertSame(KriRules::RED, KriAlert::level(), 'Valeur inconnue : niveau rouge.');
    }

    public function testScheduledTaskAlertsOnce(): void
    {
        $owner = $this->user('Tania', 'Tache', $this->profile([RiskIndicator::RIGHTNAME => READ]), 0);
        // Source globale : actions de traitement en retard, au rouge dès 0.
        $kri = $this->kri([
            'users_id' => $owner, 'source' => 'overdue_treatment_actions',
            'threshold_warning' => '0', 'threshold_critical' => '0',
        ]);
        RiskIndicator::cronRiskindicatormeasure(new \CronTask());
        $this->assertCount(1, $this->mailsFor($kri, 'tania@example.org'));
        // Second relevé du jour : il remplace le premier, le statut ne change pas.
        RiskIndicator::cronRiskindicatormeasure(new \CronTask());
        $this->assertCount(1, $this->mailsFor($kri, 'tania@example.org'));
    }

    public function testNoContentForRecipientsWithoutTheRights(): void
    {
        global $DB;

        $noRight = $this->user('Sans', 'Droit', $this->profile([]), 0);
        $kri = $this->kri(['users_id' => $noRight]);
        $notification = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => RiskIndicator::class, 'event' => KriAlert::EVENT_ALERT],
        ])->current();
        $DB->insert('glpi_notificationtargets', [
            'notifications_id' => $notification['id'],
            'type'             => \Notification::USER_TYPE,
            'items_id'         => \Notification::GLOBAL_ADMINISTRATOR,
        ]);
        $kri->recordValue(12, date('Y-m-d'));
        $this->assertSame([], $this->mailsFor($kri, 'sans@example.org'), 'Responsable sans droit : rien.');
        $this->assertSame([], $this->mailsFor($kri, 'glpi@example.org'), 'Adresse sans compte GLPI : rien.');

        // Source automatique : il faut aussi lire les données mesurées (ordinateurs).
        $_SESSION['glpiactiveentities'] = array_values(array_map('intval', getSonsOf('glpi_entities', 0)));
        $group = (int) (new Group())->add(['name' => 'RSSI fictif ' . bin2hex(random_bytes(3)), 'entities_id' => 0]);
        $sensitiveOnly = $this->user('Simon', 'Sensible', $this->profile([RiskIndicator::RIGHTNAME => READ]), 0);
        $full = $this->user('Rachel', 'Rssi', $this->profile([
            RiskIndicator::RIGHTNAME => READ, 'computer' => READ,
        ]), 0, true);
        foreach ([$sensitiveOnly, $full] as $member) {
            (new Group_User())->add(['groups_id' => $group, 'users_id' => $member]);
        }
        AuditTeam::setCisoGroupId($group);
        $automatic = $this->kri([
            'source' => 'unencrypted_computers', 'threshold_warning' => '0', 'threshold_critical' => '0',
        ]);
        $automatic->recordValue(50, date('Y-m-d'), '', true);
        $this->assertSame([], $this->mailsFor($automatic, 'simon@example.org'), 'Sans le droit sur les ordinateurs.');
        $this->assertCount(1, $this->mailsFor($automatic, 'rachel@example.org'), 'Groupe RSSI avec les droits.');

        // Périmètre : le droit doit couvrir chaque sous-entité mesurée.
        $child = $this->createTestEntity(0, 'PHPUnit entité fille ' . bin2hex(random_bytes(3)));
        $rights = [RiskIndicator::RIGHTNAME => READ, 'computer' => READ];
        $rootOnly = $this->user('Rita', 'Racine', $this->profile($rights), 0);
        $this->assertTrue(NotificationSupport::canReceive($rootOnly, 0, false, $rights, [0]));
        $this->assertFalse(NotificationSupport::canReceive($rootOnly, 0, false, $rights, [0, $child]));
        $this->assertTrue(NotificationSupport::canReceive($full, 0, false, $rights, [0, $child]));
        $this->assertFalse(NotificationSupport::canReceive(0, 0, false, $rights), 'Adresse sans compte GLPI.');
    }

    public function testBrandColourWithAndWithoutConfigurationGlpiAuto(): void
    {
        Config::setConfigurationValues(NotificationSupport::CGA_CONTEXT, [
            NotificationSupport::CGA_BRAND_COLOR_KEY => '#AA3366',
        ]);
        $owner = $this->user('Clara', 'Charte', $this->profile([RiskIndicator::RIGHTNAME => READ]), 0);

        NotificationSupport::$forceCgaActive = false;
        $without = $this->kri(['users_id' => $owner]);
        $without->recordValue(12, date('Y-m-d'));
        $mails = $this->mailsFor($without, 'clara@example.org');
        $this->assertCount(1, $mails);
        $this->assertStringContainsString(ControlTestRules::DEFAULT_BRAND_COLOR, (string) $mails[0]['body_html']);
        $this->assertStringNotContainsString('#aa3366', (string) $mails[0]['body_html']);

        NotificationSupport::$forceCgaActive = true;
        $with = $this->kri(['users_id' => $owner]);
        $with->recordValue(12, date('Y-m-d'));
        $mails = $this->mailsFor($with, 'clara@example.org');
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('#aa3366', (string) $mails[0]['body_html']);
    }

    public function testTrendCardCountsVisibleIndicatorsAndIsEmptyWithoutTheRight(): void
    {
        $kri = $this->kri();
        $kri->recordValue(12, date('Y-m-d'));
        $card = SecuredDashboardCards::riskIndicatorsTrend();
        $this->assertCount(12, $card['data']['labels']);
        $this->assertSame(date('Y-m'), $card['data']['labels'][11]);
        $this->assertGreaterThanOrEqual(1, $card['data']['series'][0]['data'][11], 'KRI au rouge ce mois-ci.');

        $rights = $_SESSION['glpiactiveprofile'][RiskIndicator::RIGHTNAME] ?? 0;
        $_SESSION['glpiactiveprofile'][RiskIndicator::RIGHTNAME] = 0;
        try {
            $this->assertSame(['labels' => [], 'series' => []], SecuredDashboardCards::riskIndicatorsTrend()['data']);
        } finally {
            $_SESSION['glpiactiveprofile'][RiskIndicator::RIGHTNAME] = $rights;
        }
    }

    public function testTrendChartOfTheFormHasValuesAndThresholds(): void
    {
        $kri = $this->kri();
        $kri->recordValue(3, $this->day(-1));
        $kri->recordValue(7, date('Y-m-d'));
        $chart = $kri->trendChart(\GlpiPlugin\Grcmanager\RiskIndicatorValue::getValues($kri->getID()));
        $this->assertSame([3.0, 7.0], array_column($chart['points'], 'value'));
        $this->assertSame(5.0, $chart['warning']);
        $this->assertSame(10.0, $chart['critical']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function kri(array $input = []): RiskIndicator
    {
        $kri = new RiskIndicator();
        $id = (int) $kri->add($input + [
            'name' => 'KRI fictif ' . bin2hex(random_bytes(3)), 'entities_id' => 0,
            'threshold_warning' => '5', 'threshold_critical' => '10',
        ]);
        $this->assertGreaterThan(0, $id);
        $kri->getFromDB($id);

        return $kri;
    }

    private function fresh(RiskIndicator $kri): RiskIndicator
    {
        $copy = new RiskIndicator();
        $copy->getFromDB($kri->getID());

        return $copy;
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    /**
     * @param array<string, int> $rights
     */
    private function profile(array $rights): int
    {
        global $DB;

        $profileId = (int) (new Profile())->add([
            'name'      => 'PHPUnit KRI ' . bin2hex(random_bytes(3)),
            'interface' => 'central',
        ]);
        foreach ($rights as $name => $value) {
            $DB->updateOrInsert('glpi_profilerights', ['rights' => $value], [
                'profiles_id' => $profileId,
                'name'        => $name,
            ]);
        }

        return $profileId;
    }

    private function user(
        string $firstname,
        string $realname,
        int $profileId,
        int $entitiesId,
        bool $recursive = false
    ): int {
        global $DB;

        $usersId = $this->createTestUser($firstname, $realname);
        (new UserEmail())->add([
            'users_id'   => $usersId,
            'email'      => strtolower($firstname) . '@example.org',
            'is_default' => 1,
        ]);
        $DB->delete(Profile_User::getTable(), ['users_id' => $usersId]);
        (new Profile_User())->add([
            'users_id'     => $usersId,
            'profiles_id'  => $profileId,
            'entities_id'  => $entitiesId,
            'is_recursive' => $recursive ? 1 : 0,
        ]);

        return $usersId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mailsFor(RiskIndicator $kri, string $email): array
    {
        return array_values((new QueuedNotification())->find([
            'itemtype'  => RiskIndicator::class,
            'items_id'  => $kri->getID(),
            'recipient' => $email,
        ], ['id ASC']));
    }
}
