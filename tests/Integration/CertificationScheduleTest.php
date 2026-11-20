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

use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\ComplianceEvent;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\ReadinessAlert;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as Rules;
use Profile_User;

/**
 * Issue #761 : date cible (saisie ou reprise du prochain audit de certification), échéances
 * conseillées dans le calendrier, cycle de maintien, seuils réglables et alerte au responsable,
 * sur des données fictives. Droits et entités compris. Indépendant de la langue.
 */
final class CertificationScheduleTest extends GrcmanagerIntegrationTestCase
{
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        global $DB;
        $this->today = date('Y-m-d');
        // Aucun audit de l'organisme ni événement de certification hérité d'un autre test.
        $DB->delete(Audit::getTable(), ['audit_type' => ['initial', 'surveillance_1', 'surveillance_2', 'renewal']]);
        $DB->delete(ComplianceEvent::getTable(), ['id' => ['>', 0]]);
        $DB->delete(ReadinessAlert::getTable(), ['id' => ['>', 0]]);
        CertificationReadiness::saveSettings([]);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int) strtotime($this->today . ' ' . sprintf('%+d', $offset) . ' days'));
    }

    private function addAudit(string $type, string $status, string $planned, int $owner = 0, int $entity = 0): int
    {
        global $DB;

        $DB->insert(Audit::getTable(), [
            'title'                => 'Audit fictif 761 ' . $type,
            'audit_type'           => $type,
            'status'               => $status,
            'planned_date'         => $planned,
            'actual_date'          => $status === 'completed' ? $planned : null,
            'users_id_responsible' => $owner,
            'entities_id'          => $entity,
        ]);

        return (int) $DB->insertId();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function steps(array $report): array
    {
        return array_column($report['steps'], null, 'key');
    }

    public function testNoTargetMeansNoDeadline(): void
    {
        $report = CertificationReadiness::report();
        $this->assertNull($report['plan']['target']);
        $this->assertSame(Rules::MODE_PREPARATION, $report['plan']['mode']);
        foreach ($report['steps'] as $step) {
            $this->assertNull($step['deadline'], $step['key']);
            $this->assertSame(Rules::DEADLINE_NONE, $step['deadline_state'], $step['key']);
        }
        $this->assertSame([], CertificationReadiness::calendarDeadlines());
    }

    public function testTheNextPlannedCertificationAuditIsTheTarget(): void
    {
        $owner = $this->createTestUser('Camille', 'Fictive761');
        $this->addAudit('internal', 'planned', $this->day(10), $owner);
        $this->addAudit('surveillance_1', 'planned', $this->day(90), $owner);
        $auditId = $this->addAudit('surveillance_1', 'planned', $this->day(13), $owner);
        $this->addAudit('surveillance_1', 'cancelled', $this->day(5), $owner);

        $report = CertificationReadiness::report();
        $target = $report['plan']['target'];
        $this->assertSame(Rules::TARGET_AUDIT, $target['source'], 'audit de l\'organisme, pas l\'audit interne');
        $this->assertSame($this->day(13), $target['date'], 'le plus proche des audits planifiés');
        $this->assertSame(Audit::getFormURLWithID($auditId), $target['url']);
        $this->assertSame($owner, $report['plan']['owner'], 'responsable de l\'audit cible');

        foreach ($report['steps'] as $step) {
            if (in_array($step['status'], [Rules::STATUS_DONE, Rules::STATUS_LOCKED], true)) {
                $this->assertNull($step['deadline'], $step['key'] . ' : pas d\'échéance pour une étape faite');
                continue;
            }
            $this->assertSame(Rules::advisedDeadline($this->day(13), $step['key']), $step['deadline'], $step['key']);
            $this->assertSame(
                Rules::deadlineState($step['deadline'], $step['status'], $this->today),
                $step['deadline_state']
            );
        }
        $this->assertGreaterThan(0, $report['plan']['late'] + $report['plan']['at_risk']);
    }

    public function testEnteredTargetWinsAndDeadlinesAreInTheCalendar(): void
    {
        $owner = $this->createTestUser('Dominique', 'Fictive761');
        $this->addAudit('renewal', 'planned', $this->day(20));
        CertificationReadiness::saveSettings(['target_date' => $this->day(200), 'owner' => $owner]);

        $report = CertificationReadiness::report();
        $this->assertSame(Rules::TARGET_MANUAL, $report['plan']['target']['source']);
        $this->assertSame($this->day(200), $report['plan']['target']['date']);
        $this->assertSame($owner, $report['plan']['owner'], 'responsable choisi');

        $pending = array_filter($report['steps'], static fn (array $s): bool => $s['deadline'] !== null);
        $this->assertNotEmpty($pending);
        $events = array_values(array_filter(
            ComplianceEvent::collect($this->day(-1), $this->day(400)),
            static fn (array $e): bool => $e['source'] === 'readiness'
        ));
        $this->assertCount(count($pending) + 1, $events, 'une échéance par étape non faite, plus la date cible');
        foreach ($events as $event) {
            $this->assertStringContainsString('certificationreadiness.php', $event['url']);
            $this->assertSame($owner, $event['users_id']);
            $this->assertFalse($event['done']);
        }
        $mine = array_filter(
            ComplianceEvent::collect($this->day(-1), $this->day(400), $owner),
            static fn (array $e): bool => $e['source'] === 'readiness'
        );
        $this->assertCount(count($events), $mine, 'planning du responsable');

        // Une date saisie passée n'est plus retenue : l'audit planifié reprend la main.
        CertificationReadiness::saveSettings(['target_date' => $this->day(-3)]);
        $plan = CertificationReadiness::report()['plan'];
        $this->assertTrue($plan['manual_past']);
        $this->assertSame([Rules::TARGET_AUDIT, $this->day(20)], [$plan['target']['source'], $plan['target']['date']]);
    }

    public function testCertifiedOrganisationFollowsTheMaintenanceCycle(): void
    {
        $this->addAudit('initial', 'completed', $this->day(-400));

        $plan = CertificationReadiness::report()['plan'];
        $this->assertSame(Rules::MODE_MAINTENANCE, $plan['mode'], 'date reprise de l\'audit initial terminé');
        $this->assertSame(Rules::TARGET_AUDIT, $plan['certified_source']);
        $this->assertSame(['surveillance_1', 'surveillance_2', 'renewal'], array_column($plan['cycle'], 'type'));
        $this->assertSame([true, false, false], array_column($plan['cycle'], 'past'));
        $this->assertSame([false, true, false], array_column($plan['cycle'], 'next'));
        $this->assertSame(Rules::TARGET_CYCLE, $plan['target']['source']);
        $this->assertSame('surveillance_2', $plan['target']['type']);
        $this->assertSame(Rules::ANNUAL_STEPS, array_column($plan['annual'], 'key'));

        // Un audit de surveillance planifié prend le pas sur la date anniversaire.
        $this->addAudit('surveillance_2', 'planned', $this->day(250));
        $plan = CertificationReadiness::report()['plan'];
        $this->assertSame([Rules::TARGET_AUDIT, $this->day(250)], [$plan['target']['source'], $plan['target']['date']]);

        // Date de certification saisie.
        CertificationReadiness::saveSettings(['certified_date' => $this->day(-30)]);
        $plan = CertificationReadiness::report()['plan'];
        $this->assertSame(Rules::TARGET_MANUAL, $plan['certified_source']);
        $this->assertSame([false, false, false], array_column($plan['cycle'], 'past'));
    }

    public function testThresholdsAreSettableAndCleaned(): void
    {
        $this->assertSame(Rules::COVERAGE_TARGET, CertificationReadiness::getSettings()['coverage_target']);
        $this->assertSame(Rules::TRAINING_RATE_TARGET, CertificationReadiness::getSettings()['training_target']);

        CertificationReadiness::saveSettings([
            'coverage_target' => '75', 'training_target' => '500', 'target_date' => 'pas une date',
            'owner' => 999999999,
        ]);
        $settings = CertificationReadiness::getSettings();
        $this->assertSame(75, $settings['coverage_target']);
        $this->assertSame(100, $settings['training_target'], 'borné à 100 %');
        $this->assertNull($settings['target_date']);
        $this->assertSame(0, $settings['owner'], 'compte inconnu refusé');

        $facts = $this->steps(CertificationReadiness::report())['internal_audit']['facts'];
        $this->assertContains(sprintf(__('Coverage target: %d %%', 'grcmanager'), 75), $facts);
    }

    public function testLeadAndAlertDaysMoveTheDeadlinesAndTheAlert(): void
    {
        $owner = $this->createTestUser('Morgane', 'Fictive761');
        (new Profile_User())->add(['users_id' => $owner, 'profiles_id' => 4, 'entities_id' => 0, 'is_recursive' => 1]);
        CertificationReadiness::saveSettings([
            'target_date' => $this->day(100),
            'owner'       => $owner,
            'alert_days'  => '500',
            'lead_days'   => ['internal_audit' => '70', 'management_review' => '80', 'scope' => 'abc'],
        ]);
        $settings = CertificationReadiness::getSettings();
        $this->assertSame(Rules::ALERT_DAYS_MAX, $settings['alert_days'], 'borné');
        $this->assertSame(70, $settings['lead_days']['internal_audit']);
        $this->assertSame(Rules::LEAD_DAYS['scope'], $settings['lead_days']['scope'], 'saisie invalide : défaut');

        $report = CertificationReadiness::report();
        $steps = $this->steps($report);
        foreach ($steps as $key => $step) {
            if ($step['deadline'] === null) {
                continue;
            }
            $this->assertSame(Rules::advisedDeadline($this->day(100), $key, $settings['lead_days']), $step['deadline']);
            // Délai d'alerte de 90 jours : toute échéance d'ici là est à risque.
            $this->assertContains($step['deadline_state'], [Rules::DEADLINE_AT_RISK, Rules::DEADLINE_LATE], $key);
        }
        $this->assertCount(1, $report['plan']['lead_warnings'], 'revue de direction réglée trop tôt');
        $rows = array_column($report['plan']['lead_rows'], null, 'key');
        $this->assertTrue($rows['internal_audit']['changed']);
        $this->assertFalse($rows['scope']['changed']);

        // Les échéances déplacées partent dans le calendrier et dans l'alerte, sans rien modifier.
        $alerted = ReadinessAlert::runAlerts($this->today);
        $this->assertSame(count(CertificationReadiness::stepsToAlert($report)), $alerted);

        // Délai d'alerte court : plus aucune étape à risque si les échéances sont lointaines.
        CertificationReadiness::saveSettings(['target_date' => $this->day(400), 'alert_days' => '1']);
        foreach (CertificationReadiness::report()['steps'] as $step) {
            $this->assertNotSame(Rules::DEADLINE_AT_RISK, $step['deadline_state'], $step['key']);
        }
    }

    public function testSettingsNeedTheRightToModifyThePlugin(): void
    {
        $saved = $_SESSION['glpiactiveprofile'][CertificationReadiness::RIGHTNAME];
        $_SESSION['glpiactiveprofile'][CertificationReadiness::RIGHTNAME] = READ;
        try {
            $this->assertTrue(CertificationReadiness::canView());
            $this->assertFalse(CertificationReadiness::canUpdate());
        } finally {
            $_SESSION['glpiactiveprofile'][CertificationReadiness::RIGHTNAME] = $saved;
        }
        $this->assertTrue(CertificationReadiness::canUpdate());
    }

    public function testCertificationAuditOfAnotherEntityIsNotTheTarget(): void
    {
        $entityA = $this->createTestEntity(0, 'Entité fictive A 761');
        $entityB = $this->createTestEntity(0, 'Entité fictive B 761');
        $this->addAudit('surveillance_1', 'planned', $this->day(40), 0, $entityB);

        $active = $_SESSION['glpiactiveentities'];
        $activeString = $_SESSION['glpiactiveentities_string'];
        $parents = $_SESSION['glpiparententities'] ?? [];
        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        $_SESSION['glpiparententities'] = [0 => 0];
        try {
            $this->assertNull(CertificationReadiness::report()['plan']['target'], 'audit de B ignoré');
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
            $_SESSION['glpiactiveentities_string'] = $activeString;
            $_SESSION['glpiparententities'] = $parents;
        }
        $this->assertSame($this->day(40), CertificationReadiness::report()['plan']['target']['date']);
    }

    public function testCalendarHasNoReadinessDeadlineWithoutTheRight(): void
    {
        CertificationReadiness::saveSettings(['target_date' => $this->day(100)]);
        $saved = $_SESSION['glpiactiveprofile'][CertificationReadiness::RIGHTNAME];
        $_SESSION['glpiactiveprofile'][CertificationReadiness::RIGHTNAME] = 0;
        try {
            $this->assertSame([], CertificationReadiness::calendarDeadlines());
        } finally {
            $_SESSION['glpiactiveprofile'][CertificationReadiness::RIGHTNAME] = $saved;
        }
    }

    public function testOwnerIsAlertedOnceAndNothingIsChanged(): void
    {
        global $DB;

        $owner = $this->createTestUser('Alex', 'Fictive761');
        (new Profile_User())->add(['users_id' => $owner, 'profiles_id' => 4, 'entities_id' => 0, 'is_recursive' => 1]);
        $outsider = $this->createTestUser('Sacha', 'Fictive761');
        $this->addAudit('surveillance_1', 'planned', $this->day(20), $outsider);

        // Responsable de l'audit sans profil : aucune alerte.
        $this->assertSame(0, ReadinessAlert::runAlerts($this->today));
        $this->assertSame(0, countElementsInTable(ReadinessAlert::getTable()));

        CertificationReadiness::saveSettings(['owner' => $owner]);
        $snapshot = static fn (): array => [
            iterator_to_array($DB->request(['FROM' => Audit::getTable(), 'ORDER' => 'id'])),
            iterator_to_array($DB->request(['FROM' => Risk::getTable(), 'ORDER' => 'id'])),
            iterator_to_array($DB->request(['FROM' => IsmsRequirement::getTable(), 'ORDER' => 'id'])),
        ];
        $before = $snapshot();
        $expected = CertificationReadiness::stepsToAlert(CertificationReadiness::report($this->today));
        $this->assertNotEmpty($expected, 'audit dans 20 jours : des étapes sont en retard');

        $this->assertSame(count($expected), ReadinessAlert::runAlerts($this->today));
        $this->assertSame($before, $snapshot(), 'aucune modification automatique');
        $rows = iterator_to_array($DB->request(['FROM' => ReadinessAlert::getTable()]));
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame($owner, (int) $row['users_id']);
        $this->assertSame($this->day(20), (string) $row['target_date']);
        $alert = new ReadinessAlert();
        $alert->getFromDB((int) $row['id']);
        $this->assertSame($expected, $alert->steps());

        $this->assertSame(0, ReadinessAlert::runAlerts($this->today), 'pas de deuxième alerte identique');

        // Contenu de la notification : étapes signalées, lien vers la page.
        $target = \NotificationTarget::getInstance($alert, ReadinessAlert::EVENT);
        $this->assertNotFalse($target);
        $target->addDataForTemplate(ReadinessAlert::EVENT);
        $this->assertStringContainsString('certificationreadiness.php', $target->data['##readinessalert.url##']);
        $this->assertSame((string) count($expected), $target->data['##readinessalert.count##']);
    }
}
