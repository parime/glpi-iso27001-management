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

use GlpiPlugin\Grcmanager\ReportedRisk;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Notification\NotificationTemplateDefaults;
use GlpiPlugin\Grcmanager\Services\Risk\ReportedRiskRules as Rules;

/**
 * Issue #872 : signalement d'un risque puis tri, sur des données fictives. Indépendant de la
 * langue : seuls les codes, les statuts et les valeurs enregistrées sont comparés.
 */
final class ReportedRiskTest extends GrcmanagerIntegrationTestCase
{
    private function report(string $name, int $usersId = 2): int
    {
        $result = ReportedRisk::submit($usersId, [
            'name' => $name, 'description' => 'Situation fictive 872', 'severity' => 'high',
        ]);
        $this->assertNull($result['error']);
        $this->assertGreaterThan(0, $result['id']);

        return $result['id'];
    }

    /**
     * @param callable(): void $test
     */
    private function withSensitiveRight(int $rights, callable $test): void
    {
        $saved = $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] ?? 0;
        $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = $rights;
        try {
            $test();
        } finally {
            $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = $saved;
        }
    }

    public function testAnyoneReportsAndOnlySeesTheirOwnReports(): void
    {
        $this->assertSame(
            Rules::ERROR_NAME_REQUIRED,
            ReportedRisk::submit(2, ['name' => ' ', 'description' => 'x'])['error']
        );
        $mineId = $this->report('Signalement fictif 872 A');
        $otherId = $this->report('Signalement fictif 872 B', 999999);

        $report = new ReportedRisk();
        $this->assertTrue($report->getFromDB($mineId));
        $this->assertSame(Rules::STATUS_NEW, $report->fields['status']);
        $this->assertSame(Rules::SEVERITY_HIGH, $report->fields['severity']);
        $this->assertSame((int) $_SESSION['glpiactive_entity'], (int) $report->fields['entities_id']);

        $mine = array_map('intval', array_column(ReportedRisk::mine(2), 'id'));
        $this->assertContains($mineId, $mine);
        $this->assertNotContains($otherId, $mine);
        $this->assertSame([], ReportedRisk::mine(0));
    }

    public function testAcceptCreatesAnIdentifiedRiskAndKeepsTheTrace(): void
    {
        $id = $this->report('Porte de la salle fictive 872');
        $result = ReportedRisk::decide($id, Rules::STATUS_ACCEPTED, 'Merci', 0, 'physical');
        $this->assertNull($result['error']);

        $risk = new Risk();
        $this->assertTrue($risk->getFromDB($result['risks_id']));
        $this->assertSame('Porte de la salle fictive 872', $risk->fields['title']);
        $this->assertSame('physical', $risk->fields['category']);
        $this->assertSame('identified', $risk->fields['status']);
        $this->assertStringContainsString('Situation fictive 872', (string) $risk->fields['description']);

        $report = new ReportedRisk();
        $report->getFromDB($id);
        $this->assertSame(Rules::STATUS_ACCEPTED, $report->fields['status']);
        $this->assertSame($result['risks_id'], (int) $report->fields['plugin_grcmanager_risks_id']);
        $this->assertSame(2, (int) $report->fields['users_id_decision']);
        $this->assertNotNull($report->fields['date_decision']);
        $this->assertSame([$id], array_map('intval', array_column(ReportedRisk::forRisk($result['risks_id']), 'id')));

        // Une décision est définitive.
        $this->assertSame(
            Rules::ERROR_ALREADY_DECIDED,
            ReportedRisk::decide($id, Rules::STATUS_REJECTED, 'Motif', 0)['error']
        );
    }

    public function testMergeLinksWithoutChangingTheRiskAndRejectNeedsAReason(): void
    {
        $risk = new Risk();
        $riskId = (int) $risk->add([
            'title' => 'Risque fictif existant 872', 'entities_id' => (int) $_SESSION['glpiactive_entity'],
        ]);
        $this->assertGreaterThan(0, $riskId);
        $before = $risk->fields;

        $merged = $this->report('Doublon fictif 872');
        $this->assertSame(
            Rules::ERROR_RISK_REQUIRED,
            ReportedRisk::decide($merged, Rules::STATUS_MERGED, '', 0)['error']
        );
        $this->assertNull(ReportedRisk::decide($merged, Rules::STATUS_MERGED, '', $riskId)['error']);
        $risk->getFromDB($riskId);
        $this->assertSame($before['title'], $risk->fields['title']);
        $this->assertSame($before['description'], $risk->fields['description']);

        $rejected = $this->report('Hors périmètre fictif 872');
        $this->assertSame(
            Rules::ERROR_REASON_REQUIRED,
            ReportedRisk::decide($rejected, Rules::STATUS_REJECTED, ' ', 0)['error']
        );
        $this->assertNull(
            ReportedRisk::decide($rejected, Rules::STATUS_REJECTED, 'Déjà traité ailleurs', 0)['error']
        );
        $report = new ReportedRisk();
        $report->getFromDB($rejected);
        $this->assertSame(Rules::STATUS_REJECTED, $report->fields['status']);
        $this->assertSame('Déjà traité ailleurs', $report->fields['decision_comment']);
        $this->assertSame(0, (int) $report->fields['plugin_grcmanager_risks_id']);
    }

    public function testTriageRequiresTheRiskRegisterRights(): void
    {
        $id = $this->report('Signalement fictif 872 droits');

        $this->withSensitiveRight(READ, function () use ($id): void {
            $this->assertTrue(ReportedRisk::canViewQueue());
            $this->assertFalse(ReportedRisk::canTriage());
            $this->assertContains($id, array_map('intval', array_column(ReportedRisk::queue(), 'id')));
            $this->assertSame('denied', ReportedRisk::decide($id, Rules::STATUS_REJECTED, 'Motif', 0)['error']);
        });
        $this->withSensitiveRight(0, function () use ($id): void {
            $this->assertFalse(ReportedRisk::canViewQueue());
            $this->assertSame([], ReportedRisk::queue());
            $this->assertFalse(ReportedRisk::getMenuContent());
            // Le signalement reste possible et visible par son auteur.
            $this->assertContains($id, array_map('intval', array_column(ReportedRisk::mine(2), 'id')));
        });
    }

    public function testTheAuthorNotificationTemplateIsInstalled(): void
    {
        global $DB;

        $definition = NotificationTemplateDefaults::definitions()['reported_risk_decided'];
        $this->assertSame(ReportedRisk::class, $definition['itemtype']);
        $this->assertSame(1, $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => ReportedRisk::class, 'event' => ReportedRisk::DECIDED_EVENT],
        ])->count());
    }
}
