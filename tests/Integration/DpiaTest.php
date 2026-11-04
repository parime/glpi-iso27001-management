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

use GlpiPlugin\Grcmanager\Dpia;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Privacy\DpiaRules;

/**
 * Issue #375 : analyse d'impact (AIPD, RGPD article 35). Données fictives.
 */
final class DpiaTest extends GrcmanagerIntegrationTestCase
{
    private function activity(array $input = []): ProcessingActivity
    {
        $activity = new ProcessingActivity();
        $id = (int) $activity->add($input + ['name' => 'PHPUnit traitement AIPD', 'entities_id' => 0]);
        $activity->getFromDB($id);

        return $activity;
    }

    /**
     * @return array<string, mixed>
     */
    private function completeForm(int $residualSeverity = 2, int $residualLikelihood = 2): array
    {
        $risks = [];
        foreach (DpiaRules::RISKS as $risk) {
            $risks[$risk] = [
                'severity' => 3, 'likelihood' => 3,
                'residual_severity' => $residualSeverity, 'residual_likelihood' => $residualLikelihood,
            ];
        }

        return [
            'criteria'    => ['large_scale', 'sensitive_data', 'inconnu'],
            'risks'       => $risks,
            'necessity'   => 'Finalité fictive justifiée',
            'measures'    => 'Chiffrement fictif',
            'dpo_opinion' => 'Avis fictif favorable',
        ];
    }

    public function testFormIsSavedThenApprovedAndFrozenUntilReopened(): void
    {
        $activity = $this->activity();
        $id = (int) $activity->getID();
        $this->assertNull(Dpia::forActivity($id));

        // Brouillon incomplet : enregistré, mais pas approuvable.
        $this->assertTrue(Dpia::saveForm($id, ['criteria' => ['large_scale']]));
        $dpia = Dpia::forActivity($id);
        $this->assertNotNull($dpia);
        $this->assertSame(DpiaRules::STATUS_DRAFT, $dpia->fields['status']);
        $this->assertFalse($dpia->approve());
        $this->assertSame(DpiaRules::STATUS_DRAFT, Dpia::forActivity($id)->fields['status']);

        // Une seule AIPD par fiche, les critères inconnus sont écartés, l'avis du DPO est daté.
        $this->assertTrue(Dpia::saveForm($id, $this->completeForm()));
        $this->assertSame(1, countElementsInTable(Dpia::getTable(), [Dpia::ACTIVITY_FK => $id]));
        $dpia = Dpia::forActivity($id);
        $this->assertSame(['sensitive_data', 'large_scale'], json_decode($dpia->fields['criteria'], true));
        $this->assertNotEmpty($dpia->fields['date_dpo_opinion']);

        $this->assertTrue($dpia->approve());
        $dpia = Dpia::forActivity($id);
        $this->assertSame(DpiaRules::STATUS_APPROVED, $dpia->fields['status']);
        $this->assertGreaterThan(0, (int) $dpia->fields['users_id_approval']);
        $this->assertNotEmpty($dpia->fields['date_approval']);

        // Figée : un nouvel envoi est refusé et ne change rien.
        $this->assertFalse(Dpia::saveForm($id, ['necessity' => 'Modifiée'] + $this->completeForm()));
        $this->assertSame('Finalité fictive justifiée', Dpia::forActivity($id)->fields['necessity']);

        // Rouvrir retire la signature.
        $this->assertTrue($dpia->reopen());
        $dpia = Dpia::forActivity($id);
        $this->assertSame(DpiaRules::STATUS_DRAFT, $dpia->fields['status']);
        $this->assertSame(0, (int) $dpia->fields['users_id_approval']);
        $this->assertEmpty($dpia->fields['date_approval']);

        // Supprimer définitivement la fiche supprime son AIPD.
        $activity->delete(['id' => $id], true);
        $this->assertNull(Dpia::forActivity($id));
    }

    public function testRecordStatusOnlyChangesOnRequest(): void
    {
        $activity = $this->activity();
        $id = (int) $activity->getID();
        Dpia::saveForm($id, $this->completeForm());
        $activity->getFromDB($id);
        $this->assertSame('not_assessed', $activity->fields['dpia_status'], 'Enregistrer ne change pas la fiche.');

        $this->assertTrue(Dpia::applyRecordStatus($activity));
        $activity->getFromDB($id);
        $this->assertSame('required', $activity->fields['dpia_status']);

        Dpia::forActivity($id)->approve();
        $activity->getFromDB($id);
        $this->assertSame('required', $activity->fields['dpia_status'], 'Approuver ne change pas la fiche.');
        $this->assertTrue(Dpia::applyRecordStatus($activity));
        $activity->getFromDB($id);
        $this->assertSame('done', $activity->fields['dpia_status']);
        $this->assertFalse(Dpia::applyRecordStatus($activity), 'Déjà cohérente : rien à appliquer.');

        $activity->delete(['id' => $id], true);
    }

    public function testHighestResidualRiskFeedsTheRegisterOnce(): void
    {
        $activity = $this->activity();
        $id = (int) $activity->getID();
        Dpia::saveForm($id, ['necessity' => 'Sans cotation']);
        $this->assertSame(0, Dpia::forActivity($id)->sendToRegister($activity), 'Aucun risque coté.');

        Dpia::saveForm($id, $this->completeForm(3, 4));
        $dpia = Dpia::forActivity($id);
        $riskId = $dpia->sendToRegister($activity);
        $this->assertGreaterThan(0, $riskId);
        $this->assertSame($riskId, $dpia->sendToRegister($activity), 'Une seule création.');
        $risk = new Risk();
        $risk->getFromDB($riskId);
        $this->assertSame('high', $risk->fields['impact']);
        $this->assertSame('certain', $risk->fields['probability']);
        $this->assertStringContainsString('Chiffrement fictif', (string) $risk->fields['description']);

        // Le risque survit à la fiche : le registre reste la référence.
        $activity->delete(['id' => $id], true);
        $this->assertTrue((new Risk())->getFromDB($riskId));
        (new Risk())->delete(['id' => $riskId], true);
    }

    public function testTabAndReportShowTheConsultationWarning(): void
    {
        $activity = $this->activity(['purpose' => 'Finalité fictive du rapport']);
        $id = (int) $activity->getID();

        // Sans AIPD : le critère des données sensibles reprend la fiche du registre.
        $this->assertSame([], Dpia::viewData($activity)['criteria']);

        Dpia::saveForm($id, $this->completeForm(4, 4));
        ob_start();
        Dpia::displayTabContentForItem($activity);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('name="save"', $html);

        $report = Dpia::renderReport($activity);
        $this->assertStringContainsString('Finalité fictive du rapport', $report);
        $this->assertStringContainsString('Chiffrement fictif', $report);

        // En lecture seule : les données restent visibles, sans bouton ni envoi.
        $saved = $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME] = READ;
            ob_start();
            Dpia::displayTabContentForItem($activity);
            $readOnly = (string) ob_get_clean();
            $this->assertStringContainsString('Chiffrement fictif', $readOnly);
            $this->assertStringNotContainsString('name="save"', $readOnly);
            $this->assertStringNotContainsString('name="approve"', $readOnly);
        } finally {
            $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME] = $saved;
        }

        // Sans droit : pas d'onglet, rien d'affiché.
        try {
            $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME] = 0;
            $this->assertSame('', (new Dpia())->getTabNameForItem($activity));
            $this->assertFalse(Dpia::displayTabContentForItem($activity));
        } finally {
            $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME] = $saved;
        }

        $activity->delete(['id' => $id], true);
    }
}
