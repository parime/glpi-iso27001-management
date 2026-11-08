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

use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;

/**
 * Issue #539 : « Mon espace GRC ». Ce qui est confié à la personne, trié par échéance, sans rien
 * de ce qu'elle ne peut pas voir.
 */
final class MySpaceTest extends GrcmanagerIntegrationTestCase
{
    private function day(int $offset): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%+d days', $offset)));
    }

    /**
     * @return list<string>
     */
    private function names(int $usersId): array
    {
        return array_column(MySpaceProvider::items($usersId, date('Y-m-d')), 'name');
    }

    public function testListsWhatIsAssignedSortedByDueDate(): void
    {
        $owner = $this->createTestUser('Mon', 'Espace');
        $other = $this->createTestUser('Autre', 'Personne');
        $riskId = (int) (new \GlpiPlugin\Grcmanager\Risk())->add([
            'title' => 'Risque 539 en retard', 'users_id' => $owner, 'review_date' => $this->day(-5),
        ]);
        (new \GlpiPlugin\Grcmanager\Risk())->add([
            'title' => 'Risque 539 lointain', 'users_id' => $owner, 'review_date' => $this->day(200),
        ]);
        (new \GlpiPlugin\Grcmanager\Risk())->add([
            'title' => 'Risque 539 d\'un autre', 'users_id' => $other, 'review_date' => $this->day(-1),
        ]);
        (new \GlpiPlugin\Grcmanager\RiskTreatmentAction())->add([
            'plugin_grcmanager_risks_id' => $riskId, 'description' => 'Action 539', 'users_id' => $owner,
            'due_date' => $this->day(10),
        ]);
        (new \GlpiPlugin\Grcmanager\Nonconformity())->add([
            'title' => 'Non-conformité 539', 'users_id' => $owner, 'due_date' => $this->day(-2), 'status' => 'open',
        ]);
        (new \GlpiPlugin\Grcmanager\Nonconformity())->add([
            'title' => 'Non-conformité 539 close', 'users_id' => $owner, 'due_date' => $this->day(-2),
            'status' => 'closed',
        ]);
        (new \GlpiPlugin\Grcmanager\Policy())->add([
            'title' => 'Politique 539', 'users_id' => $owner, 'status' => 'approved',
            'approval_date' => $this->day(-300), 'next_review_date' => $this->day(20),
        ]);
        (new \GlpiPlugin\Grcmanager\Training())->add([
            'title' => 'Formation 539', 'date_delivered' => $this->day(7), 'participants' => [$owner],
        ]);

        $items = MySpaceProvider::items($owner, date('Y-m-d'));
        $this->assertSame(
            ['Risque 539 en retard', 'Non-conformité 539', 'Formation 539', 'Action 539', 'Politique 539'],
            array_column($items, 'name'),
            'Échéance croissante ; revue lointaine, non-conformité close et éléments d\'autrui écartés.'
        );
        $this->assertSame(['overdue', 'overdue', 'soon', 'soon', 'soon'], array_column($items, 'urgency'));
        $this->assertSame(5, $items[0]['days_late']);
        $this->assertStringContainsString('risk.form.php?id=' . $riskId, $items[0]['url']);
        $this->assertStringContainsString('Risque 539 en retard', $items[3]['detail'], 'Le risque traité.');
        $this->assertSame(['Risque 539 d\'un autre'], $this->names($other));
    }

    public function testSensitiveRegistersNeedTheirRight(): void
    {
        $owner = $this->createTestUser('Sans', 'Sensible');
        $riskId = (int) (new \GlpiPlugin\Grcmanager\Risk())->add([
            'title' => 'Risque 539 sensible', 'users_id' => $owner, 'review_date' => $this->day(-1),
        ]);
        (new \GlpiPlugin\Grcmanager\RiskTreatmentAction())->add([
            'plugin_grcmanager_risks_id' => $riskId, 'description' => 'Action 539 sensible', 'users_id' => $owner,
        ]);
        (new \GlpiPlugin\Grcmanager\Nonconformity())->add([
            'title' => 'Non-conformité 539 visible', 'users_id' => $owner, 'status' => 'open',
        ]);
        $this->assertSame(
            ['Risque 539 sensible', 'Action 539 sensible', 'Non-conformité 539 visible'],
            $this->names($owner)
        );

        $saved = $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = 0;
        try {
            $this->assertSame(['Non-conformité 539 visible'], $this->names($owner));
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = $saved;
        }

        $savedGeneral = $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = 0;
        try {
            $this->assertSame(['Risque 539 sensible', 'Action 539 sensible'], $this->names($owner));
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = $savedGeneral;
        }
    }

    public function testTrainingsNeedNoRightAndRenewalsFollowTheLatestSession(): void
    {
        $owner = $this->createTestUser('Apprenant', 'Fictif');
        $trainingId = (int) (new \GlpiPlugin\Grcmanager\Training())->add([
            'title' => 'Formation 539 annuelle', 'renewal_period_months' => 12, 'participants' => [$owner],
        ]);
        global $DB;
        $DB->update('glpi_plugin_grcmanager_trainings_users', [
            'completion_status' => 'completed',
            'completion_date'   => date('Y-m-d', (int) strtotime('-13 months')),
        ], ['plugin_grcmanager_trainings_id' => $trainingId, 'users_id' => $owner]);

        $saved = $_SESSION['glpiactiveprofile'];
        $_SESSION['glpiactiveprofile'] = ['interface' => 'central'];
        try {
            $items = MySpaceProvider::items($owner, date('Y-m-d'));
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
        $this->assertSame(['Formation 539 annuelle'], array_column($items, 'name'));
        $this->assertSame('training_renewal', $items[0]['source']);
        $this->assertSame('overdue', $items[0]['urgency']);
        $this->assertStringContainsString('preference.php', $items[0]['url'], 'Sans droit : « Mes formations ».');
    }

    public function testDashboardCardsCountTheConnectedUserItems(): void
    {
        $owner = $this->createTestUser('Carte', 'Fictive');
        (new \GlpiPlugin\Grcmanager\Nonconformity())->add([
            'title' => 'Non-conformité 539 carte', 'users_id' => $owner, 'status' => 'open',
            'due_date' => $this->day(-1),
        ]);
        $saved = $_SESSION['glpiID'];
        $_SESSION['glpiID'] = $owner;
        try {
            $count = SecuredDashboardCards::mySpaceCount();
            $list = SecuredDashboardCards::mySpaceList();
        } finally {
            $_SESSION['glpiID'] = $saved;
        }
        $this->assertSame(1, $count['number']);
        $this->assertStringContainsString('myspace.php', (string) $count['url']);
        $this->assertCount(1, $list['data']);
        $this->assertStringContainsString('Non-conformité 539 carte', (string) $list['data'][0]['label']);
    }
}
