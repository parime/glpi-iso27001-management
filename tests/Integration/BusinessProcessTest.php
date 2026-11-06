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

use GlpiPlugin\Grcmanager\AssetClassification;
use GlpiPlugin\Grcmanager\BusinessImpact;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessItem;
use GlpiPlugin\Grcmanager\BusinessProcessLink;
use GlpiPlugin\Grcmanager\BusinessProcessRecovery;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\BusinessProcessRisk;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Risk;

/**
 * Issue #377 : registre des processus métier fictifs, BIA, dépendances GLPI, cloisonnement.
 */
final class BusinessProcessTest extends GrcmanagerIntegrationTestCase
{
    private function process(array $input = []): BusinessProcess
    {
        $process = new BusinessProcess();
        $id = (int) $process->add($input + ['name' => 'Paie DEMO', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $id);
        $process->getFromDB($id);

        return $process;
    }

    public function testBiaObjectivesAndDependencies(): void
    {
        global $DB;

        // Processus « Paie » : RTO 24 h, RPO 4 h, DMIA 72 h ; il dépend de l'application de paie,
        // hébergée sur une VM, elle-même sur un hyperviseur (analyse d'impact de GLPI).
        $process = $this->process(['mtpd_hours' => 72, 'rto_hours' => 24, 'rpo_hours' => 4]);
        $appliance = (int) (new \Appliance())->add(['name' => 'APP-DEMO-PAIE', 'entities_id' => 0]);
        $vm = (int) (new \Computer())->add(['name' => 'VM-DEMO-PAIE', 'entities_id' => 0]);
        $hypervisor = (int) (new \Computer())->add(['name' => 'HV-DEMO-01', 'entities_id' => 0]);
        $relations = [[$hypervisor, 'Computer', $vm, 'Computer'], [$vm, 'Computer', $appliance, 'Appliance']];
        foreach ($relations as [$s, $st, $i, $it]) {
            $DB->insert('glpi_impactrelations', [
                'itemtype_source'   => $st,
                'items_id_source'   => $s,
                'itemtype_impacted' => $it,
                'items_id_impacted' => $i,
            ]);
        }
        $DB->insert(\GlpiPlugin\Grcmanager\AssetClassification::getTable(), [
            'itemtype' => 'Computer', 'items_id' => $vm, 'availability' => 'high', 'rto_hours' => 8, 'rpo_hours' => 1,
        ]);
        $DB->insert(\GlpiPlugin\Grcmanager\AssetClassification::getTable(), [
            'itemtype' => 'Computer', 'items_id' => $hypervisor, 'availability' => 'high',
            'rto_hours' => 48, 'rpo_hours' => 24,
        ]);

        $link = new BusinessProcessItem();
        $linkId = (int) $link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'itemtype' => 'Appliance', 'items_id' => $appliance, 'comment' => 'Application de paie',
        ]);
        $this->assertGreaterThan(0, $linkId);
        // Doublon et type non liable refusés.
        $this->assertFalse($link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'itemtype' => 'Appliance', 'items_id' => $appliance,
        ]));
        $this->assertFalse($link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(), 'itemtype' => 'User', 'items_id' => 2,
        ]));

        BusinessImpact::saveGrid($process, [
            4   => ['financial' => 1, 'legal' => 1, 'reputation' => 1, 'operational' => 2],
            24  => ['financial' => 2, 'legal' => 2, 'reputation' => 2, 'operational' => 3],
            72  => ['financial' => 3, 'legal' => 4, 'reputation' => 3, 'operational' => 4],
            168 => ['financial' => 9, 'legal' => 'x'],
        ]);
        $process->getFromDB($process->getID());
        $this->assertNotEmpty($process->fields['bia_date'], 'BIA datée.');
        $this->assertSame(5, countElementsInTable(BusinessImpact::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
        ]));

        $analysis = BusinessProcess::analyze($process);
        $this->assertSame(24, $analysis['first_unacceptable']);
        // DMIA de 72 h alors que l'impact est grave dès 24 h.
        $this->assertSame(['mtpd_above_impact'], $analysis['issues']);
        $this->assertSame(0, $analysis['grid'][168]['financial'], 'Niveau hors échelle ignoré.');

        $byLabel = array_column($analysis['dependencies'], null, 'label');
        $this->assertSame(0, $byLabel[\Appliance::getTypeName(1) . ' APP-DEMO-PAIE']['depth']);
        // Issue #377, troisième étape : l'application est classable, son RTO manquant est signalé.
        $this->assertSame(['asset_rto_missing'], $byLabel[\Appliance::getTypeName(1) . ' APP-DEMO-PAIE']['issues']);
        $this->assertSame([], $byLabel[\Computer::getTypeName(1) . ' VM-DEMO-PAIE']['issues']);
        $this->assertSame(2, $byLabel[\Computer::getTypeName(1) . ' HV-DEMO-01']['depth']);
        $this->assertSame(
            ['rto_slower', 'rpo_larger'],
            $byLabel[\Computer::getTypeName(1) . ' HV-DEMO-01']['issues'],
            'Hyperviseur plus lent que le processus qui en dépend.'
        );

        // Onglet sur l'application : le processus qui en dépend.
        $processes = BusinessProcessItem::getProcessesForItem('Appliance', $appliance);
        $this->assertSame(['Paie DEMO'], array_column($processes, 'name'));

        // Export pour le PCA : en-tête, puis le processus avec l'hyperviseur en écart.
        $rows = BusinessProcess::continuityRows();
        $line = array_values(array_filter($rows, static fn (array $row): bool => $row[0] === 'Paie DEMO'))[0];
        $this->assertStringContainsString('APP-DEMO-PAIE', implode(';', $line));
        $this->assertStringContainsString('HV-DEMO-01', implode(';', $line));

        // Purge de l'application : sa dépendance disparaît (hook ITEM_PURGE).
        (new \Appliance())->delete(['id' => $appliance], true);
        $this->assertSame(0, countElementsInTable(BusinessProcessItem::getTable(), ['id' => $linkId]));

        // Purge du processus : sa BIA disparaît.
        $process->delete(['id' => $process->getID()], true);
        $this->assertSame(0, countElementsInTable(BusinessImpact::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
        ]));
    }

    public function testDurationsAreSanitizedAndNameRequired(): void
    {
        $process = $this->process(['rto_hours' => 5, 'rpo_hours' => '336']);
        $this->assertSame(0, (int) $process->fields['rto_hours']);
        $this->assertSame(336, (int) $process->fields['rpo_hours']);
        $this->assertFalse((new BusinessProcess())->add(['name' => '  ', 'entities_id' => 0]));
    }

    public function testUpdateCannotMoveTheProcessToAnotherEntity(): void
    {
        $other = $this->createTestEntity(0, 'BIA DEMO C ' . random_int(1000, 9999));
        $process = $this->process();

        $process->update(['id' => $process->getID(), 'name' => 'Paie DEMO 2', 'entities_id' => $other]);
        $process->getFromDB($process->getID());

        $this->assertSame('Paie DEMO 2', $process->fields['name']);
        $this->assertSame(0, (int) $process->fields['entities_id']);
    }

    public function testOtherEntityIsNeitherShownNorLinkable(): void
    {
        $other = $this->createTestEntity(0, 'BIA DEMO B ' . random_int(1000, 9999));
        $hidden = (int) (new \Computer())->add(['name' => 'SRV-DEMO-B', 'entities_id' => $other]);
        $processB = $this->process(['name' => 'Processus DEMO B', 'entities_id' => $other]);
        $processA = $this->process(['name' => 'Processus DEMO A']);
        foreach ([$processA, $processB] as $process) {
            $this->assertGreaterThan(0, (int) (new BusinessProcessItem())->add([
                'plugin_grcmanager_businessprocesses_id' => $process->getID(),
                'itemtype' => 'Computer', 'items_id' => $hidden,
            ]));
        }

        // La personne perd l'accès à l'entité B.
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        $analysis = BusinessProcess::analyze($processA);
        $this->assertSame([], $analysis['dependencies'], 'Élément de B jamais nommé.');
        $this->assertSame(1, $analysis['hidden']);
        $this->assertSame(
            ['Processus DEMO A'],
            array_column(BusinessProcessItem::getProcessesForItem('Computer', $hidden), 'name'),
            'Processus de B non listé.'
        );
        $this->assertFalse($processB->can($processB->getID(), READ));
        $this->assertFalse((new BusinessProcessItem())->add([
            'plugin_grcmanager_businessprocesses_id' => $processA->getID(),
            'itemtype' => 'Computer', 'items_id' => $hidden,

        ]), 'Élément illisible : lien refusé.');
        $names = array_column(BusinessProcess::continuityRows(), 0);
        $this->assertContains('Processus DEMO A', $names);
        $this->assertNotContains('Processus DEMO B', $names);
    }

    public function testProcessDependenciesPropagateObjectives(): void
    {
        // La paie (RTO 24 h, RPO 4 h) a besoin de la gestion des temps (RTO 72 h, RPO 24 h),
        // elle-même alimentée par l'annuaire RH (aucun objectif).
        $payroll = $this->process(['name' => 'Paie DEMO', 'rto_hours' => 24, 'rpo_hours' => 4]);
        $time = $this->process(['name' => 'Temps DEMO', 'rto_hours' => 72, 'rpo_hours' => 24]);
        $directory = $this->process(['name' => 'Annuaire DEMO']);
        $link = new BusinessProcessLink();
        $this->assertGreaterThan(0, (int) $link->add([
            'plugin_grcmanager_businessprocesses_id' => $payroll->getID(),
            BusinessProcessLink::UPSTREAM_FIELD      => $time->getID(),
            'comment'                                => 'Feuilles de temps',
        ]));
        $this->assertGreaterThan(0, (int) $link->add([
            'plugin_grcmanager_businessprocesses_id' => $time->getID(),
            BusinessProcessLink::UPSTREAM_FIELD      => $directory->getID(),
        ]));

        // Boucle, lien vers soi-même et doublon refusés.
        foreach (
            [
                [$directory, $payroll],
                [$payroll, $payroll],
                [$payroll, $time],
            ] as [$dependent, $upstream]
        ) {
            $this->assertFalse($link->add([
                'plugin_grcmanager_businessprocesses_id' => $dependent->getID(),
                BusinessProcessLink::UPSTREAM_FIELD      => $upstream->getID(),
            ]));
        }

        $analysis = BusinessProcessLink::analyze($payroll);
        $byName = array_column($analysis['upstream'], null, 'name');
        $this->assertSame(['Temps DEMO', 'Annuaire DEMO'], array_column($analysis['upstream'], 'name'));
        $this->assertSame(1, $byName['Temps DEMO']['depth']);
        $this->assertSame('Feuilles de temps', $byName['Temps DEMO']['comment']);
        $this->assertSame(['rto_slower', 'rpo_larger'], $byName['Temps DEMO']['issues']);
        $this->assertSame(2, $byName['Annuaire DEMO']['depth']);
        $this->assertSame(0, $byName['Annuaire DEMO']['link_id'], 'Dépendance indirecte : pas de lien à retirer.');
        $this->assertSame(['process_rto_missing'], $byName['Annuaire DEMO']['issues']);

        // L'annuaire doit reprendre aussi vite que le plus exigeant des processus qui en dépendent.
        $required = BusinessProcessLink::analyze($directory);
        $this->assertSame(['Temps DEMO', 'Paie DEMO'], array_column($required['downstream'], 'name'));
        $this->assertSame(24, $required['required']['rto']);
        $this->assertSame('Paie DEMO', $required['required']['rto_from']);
        $this->assertSame(4, $required['required']['rpo']);

        // Purge du processus intermédiaire : ses liens disparaissent dans les deux sens.
        $time->delete(['id' => $time->getID()], true);
        $this->assertSame(0, countElementsInTable(BusinessProcessLink::getTable(), ['OR' => [
            'plugin_grcmanager_businessprocesses_id' => $time->getID(),
            BusinessProcessLink::UPSTREAM_FIELD      => $time->getID(),
        ]]));
        $this->assertSame([], BusinessProcessLink::analyze($payroll)['upstream']);
    }

    public function testProcessOfAnotherEntityIsNeitherNamedNorLinkable(): void
    {
        $other = $this->createTestEntity(0, 'BIA DEMO D ' . random_int(1000, 9999));
        $processB = $this->process(['name' => 'Processus amont DEMO B', 'entities_id' => $other, 'rto_hours' => 72]);
        $processA = $this->process(['name' => 'Processus aval DEMO A', 'rto_hours' => 4]);
        $this->assertGreaterThan(0, (int) (new BusinessProcessLink())->add([
            'plugin_grcmanager_businessprocesses_id' => $processA->getID(),
            BusinessProcessLink::UPSTREAM_FIELD      => $processB->getID(),
        ]));
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        $analysis = BusinessProcessLink::analyze($processA);
        $this->assertSame([], $analysis['upstream'], 'Processus de B jamais nommé.');
        $this->assertSame(1, $analysis['hidden']);
        $other2 = $this->process(['name' => 'Autre DEMO A']);
        $this->assertFalse((new BusinessProcessLink())->add([
            'plugin_grcmanager_businessprocesses_id' => $other2->getID(),
            BusinessProcessLink::UPSTREAM_FIELD      => $processB->getID(),
        ]), 'Processus illisible : lien refusé.');

        $html = BusinessProcess::renderContinuityReport();
        $this->assertStringContainsString('Processus aval DEMO A', $html);
        $this->assertStringNotContainsString('Processus amont DEMO B', $html);
    }

    public function testRiskLinksRequireBothRights(): void
    {
        $process = $this->process(['name' => 'Commandes DEMO']);
        $riskId = (int) (new Risk())->add([
            'title' => 'Panne du site DEMO', 'category' => 'technical', 'probability' => 'possible',
            'impact' => 'high', 'status' => 'identified',
        ]);
        $this->assertGreaterThan(0, $riskId);
        $link = new BusinessProcessRisk();
        $linkId = (int) $link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'plugin_grcmanager_risks_id'             => $riskId,
        ]);
        $this->assertGreaterThan(0, $linkId);
        $this->assertFalse($link->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'plugin_grcmanager_risks_id'             => $riskId,
        ]), 'Doublon refusé.');
        $this->assertSame(['Panne du site DEMO'], array_column(
            BusinessProcessRisk::getRisksForProcess((int) $process->getID()),
            'title'
        ));
        $this->assertSame(['Commandes DEMO'], array_column(BusinessProcessRisk::getProcessesForRisk($riskId), 'name'));
        $this->assertStringContainsString('Panne du site DEMO', BusinessProcess::renderContinuityReport($process));

        // Responsable de continuité sans le droit du registre des risques : ni onglet, ni risque dans
        // le PCA, ni lien possible.
        $saved = $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = 0;
        try {
            $risk = new Risk();
            $risk->getFromDB($riskId);
            $this->assertSame('', (new BusinessProcessRisk())->getTabNameForItem($process));
            ob_start();
            $shown = BusinessProcessRisk::displayTabContentForItem($process);
            $shownOnRisk = BusinessProcessRisk::displayTabContentForItem($risk);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
            $this->assertFalse($shownOnRisk);
            $this->assertStringNotContainsString(
                'Panne du site DEMO',
                BusinessProcess::renderContinuityReport($process)
            );
            $this->assertFalse((new BusinessProcessRisk())->add([
                'plugin_grcmanager_businessprocesses_id' => $this->process(['name' => 'Autre DEMO'])->getID(),
                'plugin_grcmanager_risks_id'             => $riskId,
            ]));
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = $saved;
        }

        // Purge du risque : il n'est plus lié au processus.
        (new Risk())->delete(['id' => $riskId], true);
        $this->assertSame(0, countElementsInTable(BusinessProcessRisk::getTable(), ['id' => $linkId]));
    }

    public function testRiskProposedForAnItemTooSlowForTheProcess(): void
    {
        global $DB;

        $process = $this->process(['name' => 'Expedition DEMO', 'rto_hours' => 4, 'rpo_hours' => 1]);
        $computer = new \Computer();
        $computer->getFromDB((int) $computer->add(['name' => 'SRV-DEMO-EXP', 'entities_id' => 0]));
        $DB->insert(\GlpiPlugin\Grcmanager\AssetClassification::getTable(), [
            'itemtype' => 'Computer', 'items_id' => $computer->getID(), 'availability' => 'high',
            'rto_hours' => 24, 'rpo_hours' => 24,
        ]);
        (new BusinessProcessItem())->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'itemtype' => 'Computer', 'items_id' => $computer->getID(),
        ]);
        $issues = BusinessProcess::analyze($process)['dependencies'][0]['issues'];
        $this->assertSame(['rto_slower', 'rpo_larger'], $issues);

        $riskId = BusinessProcessRisk::createRecoveryRisk($process, $computer, $issues);
        $this->assertGreaterThan(0, $riskId);
        $this->assertSame(
            $riskId,
            BusinessProcessRisk::createRecoveryRisk($process, $computer, $issues),
            'Pas de doublon.'
        );
        $risk = new Risk();
        $risk->getFromDB($riskId);
        $this->assertStringContainsString('SRV-DEMO-EXP', (string) $risk->fields['title']);
        $this->assertSame([$computer->getID()], array_column(Risk::getLinkedAssets($riskId), 'items_id'));
        $this->assertSame(1, countElementsInTable(BusinessProcessRisk::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $process->getID(), 'plugin_grcmanager_risks_id' => $riskId,
        ]));
    }

    public function testContinuityReportIsARealPdf(): void
    {
        $process = $this->process([
            'name' => 'Support client DEMO', 'mtpd_hours' => 72, 'rto_hours' => 24, 'rpo_hours' => 4,
            'description' => "Prise d'appels\net tickets",
        ]);
        BusinessImpact::saveGrid($process, [
            4  => ['financial' => 1, 'legal' => 1, 'reputation' => 2, 'operational' => 2],
            24 => ['financial' => 2, 'legal' => 2, 'reputation' => 3, 'operational' => 3],
        ]);
        $process->getFromDB($process->getID());
        $html = BusinessProcess::renderContinuityReport($process);
        $this->assertStringContainsString('Support client DEMO', $html);
        $this->assertStringContainsString(BusinessImpact::getLevelLabels()[3], $html);
        $this->assertStringContainsString('grc-level-3', $html);

        $pdf = \GlpiPlugin\Grcmanager\Pdf\PdfRenderer::renderHtmlToPdf(BusinessProcess::renderContinuityReport());
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testTabsRequireTheContinuityRight(): void
    {
        $process = $this->process();
        $computer = new \Computer();
        $computer->getFromDB((int) $computer->add(['name' => 'PC-DEMO-BIA', 'entities_id' => 0]));
        $saved = $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = 0;
        try {
            $this->assertSame('', (new BusinessProcessItem())->getTabNameForItem($computer));
            // ajax/common.tabs.php appelle directement le contenu : il doit refuser lui aussi.
            ob_start();
            $shown = BusinessProcessItem::displayTabContentForItem($computer);
            $shownImpact = BusinessImpact::displayTabContentForItem($process);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
            $this->assertFalse($shownImpact);
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = $saved;
        }

        ob_start();
        $this->assertTrue(BusinessImpact::displayTabContentForItem($process));
        $this->assertTrue(BusinessProcessItem::displayTabContentForItem($process));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('levels[4][financial]', $html);
        $this->assertStringContainsString('_glpi_csrf_token', $html);
    }

    /**
     * Issue #377, troisième étape : applications, instances de bases de données et fournisseurs ont
     * une classification C/I/D (RTO, RPO), contrôlée par la BIA.
     */
    public function testApplianceDatabaseAndSupplierHaveRecoveryObjectives(): void
    {
        $process = $this->process(['rto_hours' => 4, 'rpo_hours' => 1]);
        $items = [
            'Appliance'        => (int) (new \Appliance())->add(['name' => 'APP-DEMO-RTO', 'entities_id' => 0]),
            'DatabaseInstance' => (int) (new \DatabaseInstance())->add(['name' => 'DB-DEMO-RTO', 'entities_id' => 0]),
            'Supplier'         => (int) (new \Supplier())->add(['name' => 'FOURN-DEMO-RTO', 'entities_id' => 0]),
        ];
        $objectives = ['Appliance' => [24, 1], 'DatabaseInstance' => [4, 8], 'Supplier' => [1, 1]];
        foreach ($items as $itemtype => $id) {
            $this->assertContains($itemtype, AssetClassification::getItemtypes());
            $this->assertGreaterThan(0, (int) (new BusinessProcessItem())->add([
                'plugin_grcmanager_businessprocesses_id' => $process->getID(),
                'itemtype' => $itemtype, 'items_id' => $id,
            ]));
            $this->assertGreaterThan(0, (int) (new AssetClassification())->add([
                'itemtype' => $itemtype, 'items_id' => $id, 'availability' => 'high',
                'rto_hours' => $objectives[$itemtype][0], 'rpo_hours' => $objectives[$itemtype][1],
            ]));
        }

        $issues = array_column(BusinessProcess::analyze($process)['dependencies'], 'issues', 'itemtype');
        $this->assertSame(['rto_slower'], $issues['Appliance'], 'Application plus lente que le processus.');
        $this->assertSame(['rpo_larger'], $issues['DatabaseInstance'], 'Base qui perd trop de données.');
        $this->assertSame([], $issues['Supplier']);

        // Onglet de classification sur le fournisseur, contenu refusé sans le droit du SMSI.
        $supplier = new \Supplier();
        $supplier->getFromDB($items['Supplier']);
        $this->assertNotSame('', (new AssetClassification())->getTabNameForItem($supplier));
        $saved = $_SESSION['glpiactiveprofile'][AssetClassification::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][AssetClassification::$rightname] = 0;
        try {
            ob_start();
            $shown = AssetClassification::displayTabContentForItem($supplier);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
        } finally {
            $_SESSION['glpiactiveprofile'][AssetClassification::$rightname] = $saved;
        }
        // Un utilisateur n'est pas classable.
        $user = new \User();
        $user->getFromDB(2);
        $this->assertSame('', (new AssetClassification())->getTabNameForItem($user));

        // Purge de l'application : sa classification disparaît (rappel ITEM_PURGE existant).
        (new \Appliance())->delete(['id' => $items['Appliance']], true);
        $this->assertSame(0, countElementsInTable(AssetClassification::getTable(), [
            'itemtype' => 'Appliance', 'items_id' => $items['Appliance'],
        ]));
        $this->assertSame(1, countElementsInTable(AssetClassification::getTable(), [
            'itemtype' => 'Supplier', 'items_id' => $items['Supplier'],
        ]));
    }

    /**
     * Issue #377, troisième étape : stratégie de reprise, temps mesurés et lien avec les exercices.
     */
    public function testRecoveryStrategyAndMeasuredTimes(): void
    {
        $process = $this->process(['name' => 'Facturation DEMO', 'rto_hours' => 4]);
        $strategy = BusinessProcessRecovery::strategy($process);
        $this->assertSame(['strategy_missing', 'recovery_untested'], $strategy['issues']);

        $this->assertTrue($process->update([
            'id' => $process->getID(), 'degraded_mode' => 'Factures saisies sur papier',
            'backup_solution' => 'Serveur de secours DEMO',
        ]));
        $process->getFromDB($process->getID());

        // Test d'exercice : rattaché à un exercice de crise lancé pour l'occasion.
        $exerciseId = CrisisExercise::launch('outage');
        $this->assertGreaterThan(0, $exerciseId);
        $recovery = new BusinessProcessRecovery();
        $this->assertGreaterThan(0, (int) $recovery->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            BusinessProcessRecovery::EXERCISE_FIELD   => $exerciseId,
            'test_date' => '2026-03-02', 'measured_minutes' => 180, 'comment' => 'Bascule DEMO',
        ]));
        $this->assertGreaterThan(0, (int) $recovery->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
            'test_date' => '2026-06-15', 'measured_minutes' => 300,
        ]));
        // Saisies refusées : durée absente, exercice inexistant.
        $this->assertFalse($recovery->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(), 'measured_minutes' => 'x',
        ]));
        $this->assertFalse($recovery->add([
            'plugin_grcmanager_businessprocesses_id' => $process->getID(), 'measured_minutes' => 30,
            BusinessProcessRecovery::EXERCISE_FIELD => 999999999,
        ]));

        $strategy = BusinessProcessRecovery::strategy($process);
        $this->assertSame(['recovery_above_rto'], $strategy['issues'], 'Dernier test : 5 h pour un RTO de 4 h.');
        $this->assertSame(300, $strategy['last']['minutes']);
        $this->assertSame(BusinessProcessRecovery::formatMinutes(300), $strategy['last']['duration']);
        $this->assertContains('recovery_above_rto', BusinessProcess::analyze($process)['issues']);
        $this->assertNotSame('', $strategy['tests'][1]['exercise'], 'Exercice lisible nommé.');

        // Salle de crise : le processus testé pendant l'exercice.
        $exercise = new CrisisExercise();
        $exercise->getFromDB($exerciseId);
        $rows = BusinessProcessRecovery::forExercise($exercise)['rows'];
        $this->assertSame(['Facturation DEMO'], array_column($rows, 'name'));
        $this->assertFalse($rows[0]['exceeds'], '3 h pour un RTO de 4 h.');

        // Sans le droit des incidents, l'exercice n'est plus nommé ni rattachable.
        $saved = $_SESSION['glpiactiveprofile'][CrisisExercise::RIGHTNAME] ?? 0;
        $_SESSION['glpiactiveprofile'][CrisisExercise::RIGHTNAME] = 0;
        try {
            $tests = BusinessProcessRecovery::strategy($process)['tests'];
            $this->assertSame('', $tests[1]['exercise']);
            $this->assertTrue($tests[1]['exercise_hidden']);
            $this->assertSame([], BusinessProcessRecovery::exerciseChoices());
            $this->assertFalse($recovery->add([
                'plugin_grcmanager_businessprocesses_id' => $process->getID(), 'measured_minutes' => 30,
                BusinessProcessRecovery::EXERCISE_FIELD   => $exerciseId,
            ]));
        } finally {
            $_SESSION['glpiactiveprofile'][CrisisExercise::RIGHTNAME] = $saved;
        }

        // Onglet : refusé sans le droit de la continuité.
        $savedContinuity = $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] ?? 0;
        $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = 0;
        try {
            ob_start();
            $shown = BusinessProcessRecovery::displayTabContentForItem($process);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
            $this->assertSame(['rows' => [], 'hidden' => 0], BusinessProcessRecovery::forExercise($exercise));
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = $savedContinuity;
        }
        ob_start();
        $this->assertTrue(BusinessProcessRecovery::displayTabContentForItem($process));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Factures saisies sur papier', $html);
        $this->assertStringContainsString('measured_minutes', $html);

        // Plan de continuité : stratégie, dernier test et écart.
        $report = BusinessProcess::renderContinuityReport($process);
        $this->assertStringContainsString('Serveur de secours DEMO', $report);
        $this->assertStringContainsString(BusinessProcessRecovery::formatMinutes(300), $report);
        $this->assertStringContainsString(BusinessProcess::getIssueLabels()['recovery_above_rto'], $report);

        // Purge du processus : ses tests disparaissent.
        $process->delete(['id' => $process->getID()], true);
        $this->assertSame(0, countElementsInTable(BusinessProcessRecovery::getTable(), [
            'plugin_grcmanager_businessprocesses_id' => $process->getID(),
        ]));
    }

    public function testRecoveryOfAnotherEntityProcessIsRefused(): void
    {
        $other = $this->createTestEntity(0, 'BIA DEMO R ' . random_int(1000, 9999));
        $processB = $this->process(['name' => 'Processus DEMO RB', 'entities_id' => $other]);
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        $this->assertFalse((new BusinessProcessRecovery())->add([
            'plugin_grcmanager_businessprocesses_id' => $processB->getID(), 'measured_minutes' => 60,
        ]));
        $this->assertArrayNotHasKey($processB->getID(), BusinessProcessRecovery::processChoices());
    }
}
