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
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\CertificationFinding;
use GlpiPlugin\Grcmanager\CertificationSampling;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\ResponsibilityActivity;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules as Rules;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

/**
 * Issue #772, lot 2 : plan d'audit de l'organisme de certification sur des données fictives
 * (« société Exemple ») : matrice processus x années du cycle rattachée aux audits de
 * certification, réutilisation des processus du programme d'audit interne, constats précédents
 * d'un processus, écrans qui y répondent, droits et entités.
 */
final class CertificationSamplingTest extends GrcmanagerIntegrationTestCase
{
    private function activity(string $name, string $codes = '', int $entityId = 0): int
    {
        $id = (int) (new ResponsibilityActivity())->add([
            'name' => $name, 'entities_id' => $entityId, 'is_recursive' => 1, 'control_codes' => $codes,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function audit(string $title, string $type, string $date, array $extra = []): Audit
    {
        $audit = new Audit();
        $id = (int) $audit->add($extra + [
            'title' => $title, 'audit_type' => $type, 'status' => 'planned', 'planned_date' => $date,
        ]);
        $this->assertGreaterThan(0, $id);
        $audit->getFromDB($id);

        return $audit;
    }

    private function finding(
        Audit $audit,
        string $reference,
        string $process,
        string $requirement,
        string $status
    ): void {
        $id = (int) (new Nonconformity())->add([
            'title'                       => 'Constat fictif ' . $reference,
            'plugin_grcmanager_audits_id' => $audit->getID(),
            '_certification_category'     => Rules::CATEGORY_SENSITIVE_POINT,
            'body_reference'              => $reference,
            'audited_process'             => $process,
            'requirement_reference'       => $requirement,
            'status'                      => $status,
        ]);
        $this->assertGreaterThan(0, $id, $reference);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function row(array $plan, int $processId): array
    {
        foreach ($plan['rows'] as $row) {
            if ($row['id'] === $processId) {
                return $row;
            }
        }
        $this->fail('Processus absent du plan.');
    }

    public function testSamplingProgrammeReusesTheProcessesOfTheInternalProgramme(): void
    {
        $context = $this->activity('Contexte et domaine d\'application Exemple');
        $access = $this->activity('Accès logiques et physiques Exemple', 'A.5.15,A.5.18');
        $suppliers = $this->activity('Fournisseurs Exemple', 'A.5.19');

        $initial = $this->audit('Certification initiale Exemple', 'initial', '2025-03-10', [
            'status' => 'completed', 'actual_date' => '2025-03-10',
        ]);
        $surv1 = $this->audit('Surveillance 1 Exemple', 'surveillance_1', '2026-12-03');
        $cancelled = $this->audit('Surveillance 2 Exemple', 'surveillance_2', '2027-11-15', ['status' => 'cancelled']);
        $internal = $this->audit('Audit interne Exemple', 'internal', '2026-05-01');
        AuditProgramme::setProcesses($internal, [$suppliers]);

        $this->assertSame(2, CertificationSampling::saveMatrix(
            [$initial->getID(), $surv1->getID()],
            [$initial->getID() => [$context, $access], $surv1->getID() => [(string) $access, 999999]]
        ));
        // Les cases cochées sont les processus visés par l'audit : aucune autre table.
        $this->assertSame([$context, $access], AuditProgramme::processIds($initial->getID()));
        $this->assertSame([$access], AuditProgramme::processIds($surv1->getID()), 'processus inconnu ignoré');

        $plan = CertificationSampling::plan(2025, '2026-11-20');
        $this->assertSame([2025, 2026, 2027], $plan['years']);
        // Seuls les audits de ce test sont comparés (la base peut en contenir d'autres).
        $mine = [$initial->getID(), $surv1->getID(), $cancelled->getID(), $internal->getID()];
        $columns = array_values(array_filter(
            $plan['columns'],
            static fn (array $c): bool => in_array($c['id'], $mine, true)
        ));
        $this->assertSame([$initial->getID(), $surv1->getID()], array_column($columns, 'id'), 'annulé, interne');
        $this->assertSame([2025, 2026], array_column($columns, 'year'));
        $cells = $this->row($plan, $access)['cells'];
        $this->assertTrue($cells[$initial->getID()]);
        $this->assertTrue($cells[$surv1->getID()]);
        $this->assertFalse($this->row($plan, $context)['cells'][$surv1->getID()]);
        $this->assertSame(0, $this->row($plan, $suppliers)['count'], 'l\'audit interne ne compte pas');
        $this->assertGreaterThanOrEqual(1, $plan['never']);
        $this->assertTrue($plan['can_edit_any']);
        $this->assertTrue($this->row($plan, $access)['editable'][$surv1->getID()]);

        // Le programme interne ne retient toujours que l'audit interne.
        $programme = AuditProgramme::programme(2025, '2026-11-20');
        foreach ($programme['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                $this->assertNotContains($surv1->getID(), array_column($cell['audits'], 'id'));
            }
        }

        // Décocher tout vide la colonne.
        CertificationSampling::saveMatrix([$surv1->getID()], []);
        $this->assertSame([], AuditProgramme::processIds($surv1->getID()));
    }

    public function testSampledProcessesShowPreviousFindingsAndScreens(): void
    {
        // Mesure peu courante (A.7.4) : aucun constat d'autres données ne s'y rapporte.
        $access = $this->activity('Accès logiques et physiques Exemple', 'A.7.4');
        $incidents = $this->activity('Incidents Exemple', 'A.5.24');

        $initial = $this->audit('Certification initiale Exemple', 'initial', '2025-03-10', [
            'status' => 'completed', 'actual_date' => '2025-03-10',
        ]);
        $this->finding($initial, 'EX-01', '  accès LOGIQUES et physiques exemple ', '', 'open');
        $this->finding($initial, 'EX-02', 'Autre processus', 'a.7.4', 'verified');
        $this->finding($initial, 'EX-03', 'Autre processus', 'A.8.8', 'open');
        $surv1 = $this->audit('Surveillance 1 Exemple', 'surveillance_1', '2026-12-03');
        $this->finding($surv1, 'EX-04', 'Accès logiques et physiques Exemple', '', 'open');
        AuditProgramme::setProcesses($surv1, [$access, $incidents]);

        $rows = CertificationSampling::sampledProcesses($surv1);
        $this->assertSame([$access, $incidents], array_column($rows, 'id'));
        $this->assertSame(['EX-01', 'EX-02'], array_column($rows[0]['findings'], 'reference'), 'antérieurs');
        $this->assertSame(1, $rows[0]['open_findings']);
        $this->assertSame([], $rows[1]['findings']);
        $this->assertSame(['A.7.4'], array_column($rows[0]['controls'], 'code'));
        $this->assertStringContainsString('process=' . $access, $rows[0]['findings_url']);

        // Écrans d'une activité proposée par défaut (gestion des incidents).
        $default = (int) (new ResponsibilityActivity())->add([
            'default_key' => 'incident_management', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $process = CertificationSampling::process($default);
        $this->assertNotNull($process);
        $screens = CertificationSampling::screens($process, []);
        $this->assertNotEmpty($screens['screens']);

        // Vue des constats filtrée sur le processus.
        $report = CertificationFinding::report(0, false, $access);
        $this->assertSame($access, $report['process']['id']);
        $references = [];
        foreach ($report['audits'] as $audit) {
            $references = array_merge($references, array_column($audit['findings'], 'reference'));
        }
        sort($references);
        $this->assertSame(['EX-01', 'EX-02', 'EX-04'], $references);

        $plan = CertificationSampling::plan(2025, '2026-11-20');
        $this->assertSame(2, $this->row($plan, $access)['open_findings']);
    }

    public function testRightsAndEntities(): void
    {
        $entityA = $this->createTestEntity(0, 'Entité Exemple A 772');
        $entityB = $this->createTestEntity(0, 'Entité Exemple B 772');
        EntityPerimeter::reset();
        $processA = $this->activity('Processus Exemple A', '', $entityA);
        $processB = $this->activity('Processus Exemple B', '', $entityB);
        $auditA = $this->audit('Surveillance Exemple A', 'surveillance_1', '2026-12-03', ['entities_id' => $entityA]);
        $auditB = $this->audit('Surveillance Exemple B', 'surveillance_1', '2026-12-04', ['entities_id' => $entityB]);

        // Un processus d'une autre entité n'est jamais rattaché à l'audit.
        CertificationSampling::saveMatrix([$auditA->getID()], [$auditA->getID() => [$processA, $processB]]);
        $this->assertSame([$processA], AuditProgramme::processIds($auditA->getID()));

        $saved = [
            $_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string'],
            $_SESSION['glpiparententities'] ?? [],
        ];
        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        $_SESSION['glpiparententities'] = [0 => 0];
        try {
            $plan = CertificationSampling::plan(2025, '2026-11-20');
            $this->assertSame([$auditA->getID()], array_column($plan['columns'], 'id'), 'audit de B invisible');
            $this->assertNull(CertificationSampling::process($processB), 'processus de B invisible');
            $this->assertNotContains($processB, array_column($plan['rows'], 'id'));
            $this->assertFalse($auditB->can($auditB->getID(), UPDATE), 'audit de B non modifiable');
        } finally {
            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string'], $_SESSION['glpiparententities']]
                = $saved;
        }

        // Sans droit de lecture des audits : ni page ni onglet.
        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        try {
            $this->assertFalse(CertificationSampling::canView());
            $this->assertFalse(CertificationSampling::getMenuContent());
            $this->assertSame('', (new CertificationSampling())->getTabNameForItem($auditA));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $rights;
        }
        $this->assertNotSame('', (new CertificationSampling())->getTabNameForItem($auditA));
        $internal = $this->audit('Audit interne Exemple', 'internal', '2026-05-01');
        $this->assertSame('', (new CertificationSampling())->getTabNameForItem($internal), 'interne');
    }

    public function testAuditTypeColumnIsShownByDefault(): void
    {
        global $DB;

        $columns = DefaultSearchColumns::COLUMNS[Audit::class];
        $this->assertSame(30, $columns[2], 'type d\'audit juste après le titre');
        $nums = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_displaypreferences',
                'WHERE' => ['itemtype' => Audit::class, 'users_id' => 0],
                'ORDER' => 'rank',
            ]) as $row
        ) {
            $nums[] = (int) $row['num'];
        }
        $this->assertContains(30, $nums);
    }
}
