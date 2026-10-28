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
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;

/**
 * Registres sensibles (risques, risques fournisseurs, plans de traitement, analyse d'impact, effet
 * domino, vulnérabilités du parc, matrice des flux) : droit dédié, aucune donnée de ces registres
 * dans les écrans, cartes et e-mails accessibles avec le seul droit général du SMSI.
 */
final class SensitiveRegistersTest extends GrcmanagerIntegrationTestCase
{
    private function withoutSensitiveRight(callable $test): void
    {
        $saved = $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = 0;
        try {
            $test();
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = $saved;
        }
    }

    public function testSensitiveRegistersHaveTheirOwnRight(): void
    {
        foreach (
            [
                \GlpiPlugin\Grcmanager\Risk::class, \GlpiPlugin\Grcmanager\SupplierRisk::class,
                \GlpiPlugin\Grcmanager\RiskTreatmentAction::class, \GlpiPlugin\Grcmanager\ImpactAnalysis::class,
                \GlpiPlugin\Grcmanager\CausalLink::class, \PluginGrcmanagerParkVulnerability::class,
                \PluginGrcmanagerNetworkFlow::class,
            ] as $class
        ) {
            $this->assertSame(Installer::SENSITIVE_RIGHT, constant($class . '::RIGHTNAME'), $class);
        }
        $this->assertSame(Installer::RIGHT_NAME, \PluginGrcmanagerControl::RIGHTNAME);
        $this->withoutSensitiveRight(function (): void {
            $this->assertFalse(\GlpiPlugin\Grcmanager\ImpactAnalysis::canView());
            $this->assertFalse(\PluginGrcmanagerNetworkFlow::canView());
        });
        $this->withoutSensitiveRight(function (): void {
            $this->assertFalse(\GlpiPlugin\Grcmanager\Risk::canView());
            $this->assertTrue(\PluginGrcmanagerControl::canView());
        });
    }

    public function testControlFormNeitherShowsNorChangesRiskLinksWithoutTheRight(): void
    {
        $riskId = (int) (new \GlpiPlugin\Grcmanager\Risk())->add(['title' => 'Risque tres sensible']);
        global $DB;

        $control = new \PluginGrcmanagerControl();
        $row = $DB->request(['SELECT' => 'id', 'FROM' => $control->getTable(), 'LIMIT' => 1])->current();
        $controlId = (int) $row['id'];
        $this->assertTrue($control->getFromDB($controlId));
        $control->update(['id' => $controlId, 'linked_risks' => [$riskId]]);
        $this->assertSame([$riskId], \PluginGrcmanagerControl::getLinkedRiskIds($controlId));

        $this->withoutSensitiveRight(function () use ($control, $controlId): void {
            ob_start();
            $control->showForm($controlId);
            $html = (string) ob_get_clean();
            $this->assertStringNotContainsString('Risque tres sensible', $html);
            $this->assertStringNotContainsString('name="linked_risks', $html);

            // Formulaire forgé : les liens existants ne bougent pas.
            $control->update(['id' => $controlId, 'linked_risks' => []]);
        });
        $this->assertSame([$riskId], \PluginGrcmanagerControl::getLinkedRiskIds($controlId));
    }

    public function testRiskDashboardCardsAreEmptyWithoutTheRight(): void
    {
        (new \GlpiPlugin\Grcmanager\Risk())->add(['title' => 'Risque compte', 'status' => 'identified']);
        $this->assertTrue(SecuredDashboardCards::isSensitiveCard('openRisksCount'));
        $this->assertTrue(SecuredDashboardCards::isSensitiveCard('supplierRisksByLevel'));
        $this->assertTrue(SecuredDashboardCards::isSensitiveCard('monthlyTrend'));
        $this->assertFalse(SecuredDashboardCards::isSensitiveCard('soaReviewedCount'));

        $this->withoutSensitiveRight(function (): void {
            $this->assertSame(0, SecuredDashboardCards::openRisksCount()['number']);
        });
    }

    public function testNotificationsCarryNoRiskDetailByDefault(): void
    {
        $this->assertTrue(SensitiveNotification::isMinimal());
        $riskId = (int) (new \GlpiPlugin\Grcmanager\Risk())->add([
            'title' => 'Faille du VPN', 'category' => 'technical', 'risk_level' => 'critical',
        ]);
        $risk = new \GlpiPlugin\Grcmanager\Risk();
        $this->assertTrue($risk->getFromDB($riskId));

        $target = new \GlpiPlugin\Grcmanager\NotificationTargetRisk(0, 'review_due', $risk);
        $target->addDataForTemplate('review_due');
        $this->assertSame(sprintf(__('Risk no. %d', 'grcmanager'), $riskId), $target->data['##risk.title##']);
        $this->assertSame('', $target->data['##risk.risklevel##']);
        $this->assertStringNotContainsString('Faille du VPN', implode(' ', array_map('strval', $target->data)));

        SensitiveNotification::setMinimal(false);
        $target = new \GlpiPlugin\Grcmanager\NotificationTargetRisk(0, 'review_due', $risk);
        $target->addDataForTemplate('review_due');
        $this->assertSame('Faille du VPN', $target->data['##risk.title##']);
    }
}
