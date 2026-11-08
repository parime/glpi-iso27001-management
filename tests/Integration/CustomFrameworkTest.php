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

use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\CustomRequirement;
use GlpiPlugin\Grcmanager\FrameworkAssessment;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkSync;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #537 : import, activation et réimport d'un référentiel personnalisé (données fictives).
 */
final class CustomFrameworkTest extends GrcmanagerIntegrationTestCase
{
    private const VERSION_1 = "code;theme;title;description;annex_a\n"
        . "PHPU-01;Gouvernance;Politique de sécurité du groupe;Revue annuelle;A.5.1\n"
        . "PHPU-02;Accès;Double authentification;;A.8.5, A.5.17\n"
        . "PHPU-03;Fournisseurs;Clause de sécurité;;A.5.20\n"
        . ";Accès;Sans code;;\n"
        . "PHPU-04;Accès;Mesure inconnue;;A.42.1\n";

    private const VERSION_2 = "code;theme;title;description;annex_a\n"
        . "PHPU-01;Gouvernance;Politique de sécurité du groupe, version 2;Revue annuelle;A.5.1, A.5.4\n"
        . "PHPU-02;Accès;Double authentification;;A.8.5, A.5.17\n"
        . "PHPU-05;Continuité;Plan de reprise testé;;A.5.30\n";

    protected function tearDown(): void
    {
        FrameworkConfig::save(array_keys(FrameworkSelection::FRAMEWORKS));
        FrameworkConfig::reset();

        parent::tearDown();
    }

    private function import(int $frameworkId, string $csv): array
    {
        $parsed = CustomFrameworkCsv::parse($csv, CustomFramework::annexACodes());
        $plan = CustomFrameworkSync::plan(CustomFramework::getExistingForSync($frameworkId), $parsed['rows']);
        CustomFramework::applyPlan($frameworkId, $plan);

        return ['errors' => $parsed['errors'], 'summary' => CustomFrameworkSync::summary($plan)];
    }

    /**
     * @return array<string, array<string, mixed>> code => exigence enregistrée
     */
    private function requirements(int $frameworkId): array
    {
        return array_column(CustomFramework::getRequirements($frameworkId), null, 'code');
    }

    public function testImportEnableAndReimportKeepTheStatuses(): void
    {
        $framework = new CustomFramework();
        $id = (int) $framework->add(['name' => 'PHPUnit exigences Démo', 'version' => 'v1']);
        $this->assertGreaterThan(0, $id);
        $key = CustomFrameworkCatalog::key($id);

        $first = $this->import($id, self::VERSION_1);
        $this->assertSame(3, $first['summary'][CustomFrameworkSync::CREATE]);
        $this->assertSame([5, 6], array_column($first['errors'], 'line'));
        $requirements = $this->requirements($id);
        $this->assertSame(['PHPU-01', 'PHPU-02', 'PHPU-03'], array_keys($requirements));

        // Un référentiel importé reste désactivé tant que personne ne l'a activé.
        $this->assertArrayHasKey($key, FrameworkConfig::frameworks());
        $this->assertFalse(FrameworkConfig::isEnabled($key));
        $this->assertArrayNotHasKey('PHPUnit exigences Démo v1', FrameworkAssessment::assessAll(
            FrameworkAssessment::controls(date('Y-m-d'))
        ));

        FrameworkConfig::setEnabled($key, true);
        $this->assertTrue(FrameworkConfig::isEnabled($key));
        $this->assertTrue(SecuredDashboardCards::frameworkAvailable('complianceByFramework', [$key]));
        $assessments = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $this->assertArrayHasKey('PHPUnit exigences Démo v1', $assessments);
        $this->assertSame($key, $assessments['PHPUnit exigences Démo v1']['key']);
        // A.5.1, A.8.5, A.5.17 et A.5.20, moins celles déclarées non applicables sur cette instance.
        $this->assertLessThanOrEqual(4, $assessments['PHPUnit exigences Démo v1']['applicable']);
        $labels = array_column(PilotageCards::complianceByFramework()['data'], 'label');
        $this->assertContains('PHPUnit exigences Démo v1', $labels);
        $this->assertSame(['PHPU-01'], CustomFrameworkCatalog::requirementsForControl($key, 'A.5.1'));

        // Statuts et commentaires saisis.
        $requirement = new CustomRequirement();
        $this->assertTrue($requirement->update([
            'id' => (int) $requirements['PHPU-01']['id'], 'status' => CustomRequirement::STATUS_COMPLIANT,
            'comment' => 'Vu en revue de direction',
        ]));
        $this->assertTrue($requirement->update([
            'id' => (int) $requirements['PHPU-03']['id'], 'status' => CustomRequirement::STATUS_PARTIAL,
        ]));
        // Énumération fermée.
        $this->assertTrue($requirement->update(['id' => (int) $requirements['PHPU-02']['id'], 'status' => '<b>x</b>']));
        $this->assertSame('', $this->requirements($id)['PHPU-02']['status']);

        // Nouvelle version : PHPU-01 modifiée, PHPU-03 retirée, PHPU-05 ajoutée.
        $second = $this->import($id, self::VERSION_2);
        $this->assertSame(
            ['create' => 1, 'update' => 1, 'restore' => 0, 'unchanged' => 1, 'remove' => 1],
            $second['summary']
        );
        $requirements = $this->requirements($id);
        $this->assertSame('Politique de sécurité du groupe, version 2', $requirements['PHPU-01']['title']);
        $this->assertSame(CustomRequirement::STATUS_COMPLIANT, $requirements['PHPU-01']['status']);
        $this->assertSame('Vu en revue de direction', $requirements['PHPU-01']['comment']);
        $this->assertSame(1, (int) $requirements['PHPU-03']['is_removed']);
        $this->assertSame(CustomRequirement::STATUS_PARTIAL, $requirements['PHPU-03']['status']);
        $this->assertNotNull($requirements['PHPU-03']['date_removed']);
        $this->assertSame([], CustomFrameworkCatalog::requirementsForControl($key, 'A.5.20'));
        $this->assertSame(['PHPU-01'], CustomFrameworkCatalog::requirementsForControl($key, 'A.5.4'));

        // L'exigence retirée revient avec son statut.
        $third = $this->import($id, self::VERSION_1);
        $this->assertSame(1, $third['summary'][CustomFrameworkSync::RESTORE]);
        $restored = $this->requirements($id)['PHPU-03'];
        $this->assertSame(0, (int) $restored['is_removed']);
        $this->assertSame(CustomRequirement::STATUS_PARTIAL, $restored['status']);

        // Suppression du référentiel : ses exigences partent avec lui, la clé n'est plus connue.
        $this->assertTrue($framework->delete(['id' => $id], true));
        $this->assertSame(0, countElementsInTable(
            CustomRequirement::getTable(),
            ['plugin_grcmanager_customframeworks_id' => $id]
        ));
        $this->assertArrayNotHasKey($key, FrameworkConfig::frameworks());
        $this->assertNotContains($key, FrameworkConfig::enabled());
    }

    public function testFrameworkNeedsAName(): void
    {
        $this->assertFalse((new CustomFramework())->add(['name' => '   ']));
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    public function testListCountsActiveRemovedAndAssessedRequirements(): void
    {
        $id = (int) (new CustomFramework())->add(['name' => 'PHPUnit liste Démo']);
        $this->import($id, self::VERSION_1);
        $this->import($id, self::VERSION_2);
        $first = $this->requirements($id)['PHPU-02'];
        (new CustomRequirement())->update([
            'id' => (int) $first['id'], 'status' => CustomRequirement::STATUS_NON_COMPLIANT,
        ]);

        $rows = array_column(CustomFramework::getList(), null, 'id');
        $this->assertSame(3, $rows[$id]['active']);
        $this->assertSame(1, $rows[$id]['removed']);
        $this->assertSame(1, $rows[$id]['assessed']);
        $this->assertFalse($rows[$id]['enabled']);
    }
}
