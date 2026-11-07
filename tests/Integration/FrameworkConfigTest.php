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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\Referentials;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;

/**
 * Issue #403 : référentiels de conformité activables un par un.
 */
final class FrameworkConfigTest extends GrcmanagerIntegrationTestCase
{
    protected function tearDown(): void
    {
        // Le cache de FrameworkConfig survit au rollback : il est remis à l'état par défaut.
        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS));

        parent::tearDown();
    }

    public function testEveryFrameworkIsEnabledByDefault(): void
    {
        global $DB;
        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS));
        $DB->delete('glpi_configs', [
            'context' => FrameworkConfig::CONFIG_CONTEXT,
            'name'    => FrameworkConfig::CONFIG_KEY,
        ]);

        // Réglage absent (installation mise à jour) : tout reste actif, comme avant.
        $this->assertSame(array_keys(FrameworkSelection::FRAMEWORKS), FrameworkConfig::enabled());
        $this->assertTrue(IsmsRequirement::canView());
        $this->assertNotFalse(Referentials::getMenuContent());
    }

    public function testOptionalFrameworksStayDisabledUntilSomeoneEnablesThem(): void
    {
        global $DB;
        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS));
        $DB->delete('glpi_configs', [
            'context' => FrameworkConfig::CONFIG_CONTEXT,
            'name'    => [FrameworkConfig::CONFIG_KEY, FrameworkConfig::KNOWN_KEY],
        ]);

        // Sans réglage (installation antérieure à DORA) : tout reste actif, sauf les référentiels optionnels.
        $expected = array_values(array_diff(array_keys(FrameworkSelection::FRAMEWORKS), FrameworkSelection::OPTIONAL));
        $this->assertSame($expected, FrameworkConfig::enabled());

        // Réglage enregistré avant ISO 27701 (DORA connu et activé) : DORA reste actif, ISO 27701 non.
        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS)); // vide le cache de la requête
        \Config::setConfigurationValues(FrameworkConfig::CONFIG_CONTEXT, [
            FrameworkConfig::CONFIG_KEY => '[]',
            FrameworkConfig::KNOWN_KEY  => (string) json_encode(array_values(array_diff(
                array_keys(FrameworkSelection::FRAMEWORKS),
                [FrameworkSelection::ISO27701]
            ))),
        ]);
        $this->assertTrue(FrameworkConfig::isEnabled(FrameworkSelection::DORA));
        $this->assertFalse(FrameworkConfig::isEnabled(FrameworkSelection::ISO27701));

        // Activé une fois, il le reste ; sa correspondance entre dans le score.
        FrameworkConfig::save([FrameworkSelection::ISO27001, FrameworkSelection::DORA]);
        $this->assertTrue(FrameworkConfig::isEnabled(FrameworkSelection::DORA));
        $controls = ['A.5.19' => ['applicability' => 'yes', 'implementation_status' => 'implemented']];
        $this->assertArrayHasKey('DORA', PilotageCards::frameworkRates($controls, FrameworkConfig::enabled()));

        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS));
        $controls['A.5.30'] = ['applicability' => 'yes', 'implementation_status' => 'not_started'];
        $rates = PilotageCards::frameworkRates($controls, FrameworkConfig::enabled());
        $this->assertSame(100.0, $rates['ISO 27701'], 'A.5.19 relève de l\'objectif A.1.5.');
        $this->assertSame(0.0, $rates['ISO 22301'], 'A.5.30 (non démarrée) seule relève d\'ISO 22301.');
    }

    public function testDisablingIso27001HidesIsmsRequirementsAndKeepsTheirData(): void
    {
        $requirement = new IsmsRequirement();
        $this->assertTrue($requirement->getFromDBByCrit(['code' => '6.1.2']));
        $requirement->update([
            'id'      => $requirement->getID(),
            'status'  => IsmsClauseCatalog::STATUS_COMPLIANT,
            'comment' => 'Revue fictive en comité.',
        ]);

        FrameworkConfig::save([FrameworkSelection::NIS2, FrameworkSelection::ANSSI]);

        $this->assertFalse(FrameworkConfig::isEnabled(FrameworkSelection::ISO27001));
        $this->assertFalse(IsmsRequirement::canView(), 'exigences du SMSI masquées (menu, recherche, fiche)');
        $this->assertFalse(IsmsRequirement::canUpdate());
        try {
            FrameworkConfig::checkEnabled(FrameworkSelection::ISO27001);
            $this->fail('écran ISO 27001 refusé par son adresse');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        FrameworkConfig::checkEnabled(FrameworkSelection::NIS2);

        // Réactivation : rien n'a été supprimé.
        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS));
        $this->assertTrue(IsmsRequirement::canView());
        $this->assertSame(30, countElementsInTable(IsmsRequirement::getTable()));
        $requirement->getFromDB($requirement->getID());
        $this->assertSame(IsmsClauseCatalog::STATUS_COMPLIANT, $requirement->fields['status']);
        $this->assertSame('Revue fictive en comité.', $requirement->fields['comment']);
    }

    public function testDisabledFrameworksLeaveScoresAndControlForm(): void
    {
        FrameworkConfig::save([FrameworkSelection::ANSSI, FrameworkSelection::NIST_CSF]);

        $labels = ComplianceAudit::getFrameworkLabels();
        $this->assertSame([ComplianceAuditScore::GDPR], array_keys($labels), 'le RGPD reste, ISO 27001 et NIS2 non');
        foreach (ComplianceAudit::getCheckReferences('antivirus') as $reference) {
            $this->assertStringNotContainsString('NIS2', $reference);
            $this->assertStringNotContainsString('27001', $reference);
        }

        $card = PilotageCards::complianceByFramework();
        $this->assertSame(['ANSSI', 'NIST CSF 2.0'], array_column($card['data'], 'label'));

        // Fiche d'une mesure liée à NIS2 : plus de correspondance NIS2 affichée.
        $code = null;
        foreach (array_keys(\GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults::CONTROLS) as $candidate) {
            if (Nis2CatalogDefaults::requirementsForControl($candidate) !== []) {
                $code = $candidate;
                break;
            }
        }
        $this->assertNotNull($code);
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code]));
        ob_start();
        $control->showForm($control->getID());
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('NIS2 ', $html);

        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS));
        ob_start();
        $control->showForm($control->getID());
        $this->assertStringContainsString('NIS2 ', (string) ob_get_clean(), 'réactivé : la correspondance revient');
    }

    public function testCisScoreAndMappingFollowTheCisFramework(): void
    {
        // Issue #143 : A.8.13 (sauvegarde) relève du contrôle CIS 11.
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => 'A.8.13']));
        $render = static function () use ($control): string {
            ob_start();
            $control->showForm($control->getID());

            return (string) ob_get_clean();
        };

        $this->assertStringContainsString('CIS 11', $render());
        $this->assertContains('CIS Controls v8', array_column(PilotageCards::complianceByFramework()['data'], 'label'));

        // Issue #418 : désactivé, ni score ni correspondance.
        FrameworkConfig::save([FrameworkSelection::ISO27001, FrameworkSelection::NIS2]);
        $this->assertStringNotContainsString('CIS 11', $render());
        $this->assertNotContains(
            'CIS Controls v8',
            array_column(PilotageCards::complianceByFramework()['data'], 'label')
        );
    }

    public function testNoFrameworkHidesTheReferentialsPage(): void
    {
        FrameworkConfig::save([]);

        $this->assertSame([], FrameworkConfig::enabled());
        $this->assertFalse(Referentials::getMenuContent());
    }
}
