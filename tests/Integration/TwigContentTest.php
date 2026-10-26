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

/**
 * Issue #199 : contenus passés de PHP à Twig (ticket de revue périodique, onglet Risques).
 */
final class TwigContentTest extends GrcmanagerIntegrationTestCase
{
    public function testPeriodicReviewTicketContentListsTheChecklistAndTheScreen(): void
    {
        $review = \PluginGrcmanagerPeriodicReview::getByKey('risk_register');
        $this->assertNotNull($review);
        $content = $review->buildTicketContent();

        $this->assertStringContainsString('<ul><li>', $content);
        foreach (\PluginGrcmanagerPeriodicReview::getChecklist('risk_register') as $item) {
            $this->assertStringContainsString(htmlspecialchars($item, ENT_QUOTES), $content);
        }
        $this->assertStringContainsString('/plugins/grcmanager/front/risk.php</a>', $content);
    }

    public function testAssetRiskTabShowsLinkedRisks(): void
    {
        $computer = new \Computer();
        $computerId = (int) $computer->add(['name' => 'PHPUnit serveur de fichiers', 'entities_id' => 0]);
        $risk = new \PluginGrcmanagerRisk();
        $riskId = (int) $risk->add([
            'title'                 => 'PHPUnit panne du serveur de fichiers',
            'probability'           => 'certain',
            'impact'                => 'critical',
            'linked_items_Computer' => [$computerId],
        ]);
        try {
            $computer->getFromDB($computerId);
            ob_start();
            \PluginGrcmanagerRisk::displayTabContentForItem($computer);
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('PHPUnit panne du serveur de fichiers</a>', $html);
            $this->assertStringContainsString('ti-alert-triangle', $html, 'Badge de niveau rendu tel quel.');
        } finally {
            $risk->delete(['id' => $riskId], true);
            $computer->delete(['id' => $computerId], true);
        }
    }
}
