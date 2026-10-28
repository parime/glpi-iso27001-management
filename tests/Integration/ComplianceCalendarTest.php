<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\ComplianceEvent;

/**
 * Issue #142 : calendrier de conformité — événements saisis et échéances des autres registres,
 * planning GLPI du responsable, export iCal.
 */
final class ComplianceCalendarTest extends GrcmanagerIntegrationTestCase
{
    private const BEGIN = '2031-03-01 00:00:00';
    private const END = '2031-04-01 00:00:00';

    public function testEventsAndDeadlinesAreCollectedForTheResponsible(): void
    {
        global $DB;

        $owner = $this->createTestUser('Rssi', 'Calendrier');
        $other = $this->createTestUser('Autre', 'Calendrier');

        $event = new \GlpiPlugin\Grcmanager\ComplianceEvent();
        $eventsId = (int) $event->add([
            'event_type' => 'certification_audit', 'date_begin' => '2031-03-10 09:00:00', 'users_id' => $owner,
        ]);
        $this->assertGreaterThan(0, $eventsId);
        $event->getFromDB($eventsId);
        $types = \GlpiPlugin\Grcmanager\ComplianceEvent::getEventTypes();
        $this->assertSame($types['certification_audit'], $event->fields['name'], 'Le type sert de nom.');
        $this->assertSame('2031-03-11 00:00:00', $event->fields['date_end'], 'Fin : la journée du début.');

        $auditsId = (int) (new \PluginGrcmanagerAudit())->add([
            'title' => 'PHPUnit audit interne', 'planned_date' => '2031-03-20', 'users_id' => $owner,
        ]);
        $reviewsId = (int) (new \PluginGrcmanagerManagementReview())->add([
            'title' => 'PHPUnit revue de direction', 'review_date' => '2031-03-25',
        ]);
        $DB->insert('glpi_plugin_grcmanager_managementreviews_users', [
            'plugin_grcmanager_managementreviews_id' => $reviewsId, 'users_id' => $owner,
        ]);
        (new \GlpiPlugin\Grcmanager\ComplianceEvent())->add([
            'event_type' => 'pentest', 'date_begin' => '2031-03-15 09:00:00', 'users_id' => $other,
        ]);
        (new \GlpiPlugin\Grcmanager\ComplianceEvent())->add([
            'event_type' => 'pentest', 'date_begin' => '2031-05-15 09:00:00', 'users_id' => $owner,
        ]);

        $mine = \GlpiPlugin\Grcmanager\ComplianceEvent::collect(self::BEGIN, self::END, $owner);
        $this->assertSame(
            ['event-' . $eventsId . '@grcmanager', 'audit-' . $auditsId . '@grcmanager',
                'management_review-' . $reviewsId . '@grcmanager'],
            array_column($mine, 'uid'),
            'Ses échéances de la période, triées ; ni celles d\'un autre, ni hors période.'
        );
        $all = array_column(\GlpiPlugin\Grcmanager\ComplianceEvent::collect(self::BEGIN, self::END), 'name');
        $this->assertContains($types['pentest'], $all, 'Vue d\'ensemble : toutes les échéances.');

        $planning = \GlpiPlugin\Grcmanager\ComplianceEvent::populatePlanning([
            'who' => $owner, 'begin' => self::BEGIN, 'end' => self::END, 'state_done' => true,
        ]);
        $this->assertCount(3, $planning);
        $first = reset($planning);
        $this->assertSame(\GlpiPlugin\Grcmanager\ComplianceEvent::class, $first['itemtype']);
        $this->assertFalse($first['editable'], 'Lecture seule dans le planning.');
        $this->assertSame([], \GlpiPlugin\Grcmanager\ComplianceEvent::populatePlanning([
            'who' => 0, 'begin' => self::BEGIN, 'end' => self::END,
        ]));
    }

    public function testCancelledEventsStayOutAndAStartDateIsRequired(): void
    {
        $owner = $this->createTestUser('Rssi', 'Annule');
        (new \GlpiPlugin\Grcmanager\ComplianceEvent())->add([
            'event_type' => 'crisis_exercise', 'date_begin' => '2031-03-05 09:00:00', 'users_id' => $owner,
            'status' => 'cancelled',
        ]);
        $this->assertSame([], \GlpiPlugin\Grcmanager\ComplianceEvent::collect(self::BEGIN, self::END, $owner));
        $refused = (new \GlpiPlugin\Grcmanager\ComplianceEvent())->add(['event_type' => 'pentest', 'date_begin' => '']);
        $this->assertFalse($refused);
    }

    public function testIcsExportContainsTheDeadlines(): void
    {
        $owner = $this->createTestUser('Rssi', 'Ical');
        $_SESSION['glpiID'] = $owner;
        try {
            (new \GlpiPlugin\Grcmanager\ComplianceEvent())->add([
                'name' => 'PHPUnit pentest annuel', 'event_type' => 'pentest',
                'date_begin' => date('Y-m-d 09:00:00', strtotime('+10 days')),
                'date_end' => date('Y-m-d 17:00:00', strtotime('+10 days')), 'users_id' => $owner,
            ]);
            $ics = \GlpiPlugin\Grcmanager\ComplianceEvent::exportIcs(true);
        } finally {
            $_SESSION['glpiID'] = 2;
        }
        $this->assertStringStartsWith('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('PHPUnit pentest annuel', $ics);
        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'), 'Seulement ses échéances.');
    }

    /** Issue #142, phase 2 : lien d'abonnement avec le jeton personnel de l'utilisateur. */
    public function testSubscriptionUrlCarriesThePersonalToken(): void
    {
        $user = new \User();
        $user->getFromDB(2);
        $token = (string) $user->getAuthToken('personal_token');

        $all = \GlpiPlugin\Grcmanager\ComplianceEvent::getSubscriptionURL(false);
        $mine = \GlpiPlugin\Grcmanager\ComplianceEvent::getSubscriptionURL(true);
        $this->assertStringContainsString('/plugins/grcmanager/front/compliancecalendar.ics.php?', $all);
        $this->assertStringContainsString('token=' . $token, $all);
        $this->assertStringNotContainsString('mine=', $all);
        $this->assertStringContainsString('mine=1', $mine);
    }
}
