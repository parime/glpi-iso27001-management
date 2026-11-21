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
use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\EbiosStudy;
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\NetworkFlow;
use GlpiPlugin\Grcmanager\Objective;
use GlpiPlugin\Grcmanager\ObjectiveMeasurement;
use GlpiPlugin\Grcmanager\SupplierRisk;
use GlpiPlugin\Grcmanager\Training;
use GlpiPlugin\Grcmanager\Waiver;
use GlpiPlugin\Grcmanager\AuditEvidenceRequest;
use GlpiPlugin\Grcmanager\AuditorAccess;
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskTreatmentAction;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;
use GlpiPlugin\Grcmanager\SoaVersion;
use Migration;

/**
 * Issue #536 : périmètres multiples. Deux filiales fictives sous l'entité racine ; les risques, les
 * mesures de la SoA et les versions approuvées suivent les droits d'entité de GLPI, et le
 * consolidé de la racine voit les deux filiales. Assertions sur les identifiants et les codes,
 * jamais sur des libellés traduits (la CI tourne en anglais, l'instance de dev en français).
 */
final class EntityPerimeterTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $savedSession = [];

    private int $north = 0;

    private int $south = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $keys = ['glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiparententities'];
        foreach ($keys as $key) {
            $this->savedSession[$key] = $_SESSION[$key] ?? null;
        }
        $_SESSION['glpiactive_entity'] = 0;
        $this->north = $this->createTestEntity(0, 'PHPUnit Filiale Nord');
        $this->south = $this->createTestEntity(0, 'PHPUnit Filiale Sud');
        EntityPerimeter::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        EntityPerimeter::reset();

        parent::tearDown();
    }

    /**
     * Session d'un profil limité à une seule entité (sans sous-entité).
     */
    private function limitTo(int $entityId): void
    {
        $_SESSION['glpiactive_entity'] = $entityId;
        $_SESSION['glpiactiveentities'] = [$entityId];
        $_SESSION['glpiactiveentities_string'] = (string) $entityId;
        $_SESSION['glpiparententities'] = $entityId === 0 ? [] : [0];
        EntityPerimeter::reset();
    }

    /**
     * Session de la racine en vue arborescente (les deux filiales comprises).
     */
    private function rootTree(): void
    {
        $entities = [0, $this->north, $this->south];
        $_SESSION['glpiactive_entity'] = 0;
        $_SESSION['glpiactiveentities'] = $entities;
        $_SESSION['glpiactiveentities_string'] = self::entitiesString($entities);
        $_SESSION['glpiparententities'] = [];
        EntityPerimeter::reset();
    }

    private function risk(int $entityId, string $title, string $level = 'high'): int
    {
        $risk = new Risk();
        $id = (int) $risk->add([
            'title'       => $title,
            'entities_id' => $entityId,
            'probability' => 'possible',
            'impact'      => $level === 'critical' ? 'critical' : 'high',
            'status'      => 'identified',
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @return list<int>
     */
    private function visibleRiskIds(): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Risk::getTable(),
                'WHERE'  => EntityPerimeter::criteria(Risk::getTable()),
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function testARiskBelongsToItsEntityAndIsHiddenFromTheOther(): void
    {
        $this->rootTree();
        $north = $this->risk($this->north, 'PHPUnit risque Nord');
        $south = $this->risk($this->south, 'PHPUnit risque Sud');

        $this->limitTo($this->north);
        $visible = $this->visibleRiskIds();
        $this->assertContains($north, $visible);
        $this->assertNotContains($south, $visible);
        $this->assertTrue((new Risk())->can($north, READ));
        $this->assertFalse((new Risk())->can($south, READ), 'fiche du risque de l\'autre filiale');

        // Création forcée dans l'autre filiale : refusée.
        $this->assertFalse((new Risk())->add([
            'title' => 'PHPUnit risque forcé', 'entities_id' => $this->south, 'probability' => 'possible',
            'impact' => 'low',
        ]));

        // Cartes du tableau de bord : seuls les risques de la filiale comptent.
        $this->limitTo($this->south);
        $before = DashboardCardService::openRisksCount()['number'];
        $this->rootTree();
        $this->risk($this->north, 'PHPUnit autre risque Nord');
        $this->limitTo($this->south);
        $this->assertSame($before, DashboardCardService::openRisksCount()['number']);
    }

    public function testAnEntityFollowsTheCommonSoaUntilItCreatesItsOwn(): void
    {
        global $DB;

        $this->limitTo($this->north);
        $this->assertSame(0, EntityPerimeter::soaEntity());
        $this->assertTrue(EntityPerimeter::canCreateSoa($this->north));
        $common = EntityPerimeter::controlIdsByCode();
        $this->assertCount(count(ControlCatalogDefaults::CONTROLS), $common);

        // Une mesure non applicable de la SoA commune garde sa justification dans la copie.
        $DB->update(Control::getTable(), [
            'applicability' => 'no', 'justification' => 'PHPUnit justification commune',
        ], ['id' => $common['A.7.4']]);

        $this->assertSame(count(ControlCatalogDefaults::CONTROLS), EntityPerimeter::createSoa($this->north));
        $this->assertSame($this->north, EntityPerimeter::soaEntity());
        $this->assertFalse(EntityPerimeter::canCreateSoa($this->north), 'une seule SoA par entité');

        $own = EntityPerimeter::controlIdsByCode();
        $this->assertCount(count(ControlCatalogDefaults::CONTROLS), $own);
        $this->assertSame([], array_intersect($own, $common));
        $copy = new Control();
        $this->assertTrue($copy->getFromDB($own['A.7.4']));
        $this->assertSame('no', $copy->fields['applicability']);
        $this->assertSame('PHPUnit justification commune', $copy->fields['justification']);
        $this->assertSame('not_started', $copy->fields['implementation_status']);
        $this->assertSame(0, (int) $copy->fields['is_reviewed'], 'chaque mesure reste à revoir');
        $this->assertSame(1, (int) $copy->fields['is_recursive']);

        // Recherche, listes déroulantes et API : la SoA propre seulement, jamais la commune en double.
        $this->assertSame(
            ['glpi_plugin_grcmanager_controls.entities_id' => [$this->north]],
            Control::getSystemSQLCriteria(Control::getTable())
        );

        // La filiale Sud suit toujours la SoA commune, qu'elle lit sans pouvoir la modifier.
        $this->limitTo($this->south);
        $this->assertSame(0, EntityPerimeter::soaEntity());
        $this->assertSame($common, EntityPerimeter::controlIdsByCode());
        $this->assertTrue((new Control())->can($common['A.5.1'], READ));
        $this->assertFalse((new Control())->can($common['A.5.1'], UPDATE), 'SoA commune en lecture seule');
        $this->assertFalse((new Control())->can($own['A.5.1'], READ), 'SoA de la filiale Nord');

        // La racine en vue arborescente consolide les deux SoA.
        $this->rootTree();
        $this->assertEqualsCanonicalizing([0, $this->north], EntityPerimeter::activeSoaEntities());
    }

    public function testSoaVersionsAndFingerprintsArePerEntity(): void
    {
        global $DB;

        $this->limitTo($this->north);
        EntityPerimeter::createSoa($this->north);
        $northPrint = SoaVersion::currentFingerprint();

        foreach ([[0, 7], [$this->north, 1]] as [$entityId, $number]) {
            $DB->insert(SoaVersion::getTable(), [
                'entities_id'   => $entityId,
                'is_recursive'  => 1,
                'version'       => $number,
                'status'        => SoaApprovalLogic::VERSION_APPROVED,
                'fingerprint'   => SoaVersion::currentFingerprint($entityId),
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->assertSame(1, (int) SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED)['version']);
        $this->limitTo($this->south);
        $this->assertSame(7, (int) SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED)['version']);

        // Une modification de la SoA de Nord ne change pas l'empreinte de la SoA commune.
        $common = SoaVersion::currentFingerprint(0);
        $this->limitTo($this->north);
        $control = new Control();
        $control->getFromDB(EntityPerimeter::controlIdsByCode()['A.5.1']);
        $control->update(['id' => $control->getID(), 'implementation_status' => 'verified']);
        $this->assertNotSame($northPrint, SoaVersion::currentFingerprint());
        $this->assertSame($common, SoaVersion::currentFingerprint(0));
    }

    public function testTheParentConsolidationSeesBothChildren(): void
    {
        $this->rootTree();
        $critical = $this->risk($this->north, 'PHPUnit risque Nord 1', 'critical');
        $this->risk($this->north, 'PHPUnit risque Nord 2');
        $level = (string) Risk::getById($critical)->fields['risk_level'];
        $this->risk($this->south, 'PHPUnit risque Sud');
        $this->limitTo($this->north);
        EntityPerimeter::createSoa($this->north);
        $control = new Control();
        $control->getFromDB(EntityPerimeter::controlIdsByCode()['A.5.1']);
        $control->update(['id' => $control->getID(), 'implementation_status' => 'implemented']);

        $this->rootTree();
        $rows = [];
        foreach (EntityPerimeter::consolidation() as $row) {
            $rows[(int) $row['entities_id']] = $row;
        }
        $this->assertArrayHasKey(0, $rows);
        $this->assertTrue($rows[0]['is_self']);
        $this->assertArrayHasKey($this->north, $rows);
        $this->assertArrayHasKey($this->south, $rows);

        $this->assertTrue($rows[$this->north]['own_soa']);
        $this->assertFalse($rows[$this->south]['own_soa']);
        $this->assertSame(0, $rows[$this->south]['soa_entities_id']);
        $this->assertGreaterThan(0.0, (float) $rows[$this->north]['rate']);
        $this->assertSame(2, $rows[$this->north]['risks']['open']);
        $this->assertGreaterThanOrEqual(1, $rows[$this->north]['risks'][$level]);
        $this->assertSame(1, $rows[$this->south]['risks']['open']);

        // Une filiale seule ne voit pas la ligne de l'autre.
        $this->limitTo($this->north);
        $ids = array_map(static fn (array $row): int => (int) $row['entities_id'], EntityPerimeter::consolidation());
        $this->assertSame([$this->north], $ids);
    }

    private function audit(int $entityId, string $title): int
    {
        $id = (int) (new Audit())->add(['title' => $title, 'entities_id' => $entityId, 'status' => 'in_progress']);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function finding(int $auditId, string $title, array $extra = []): int
    {
        $id = (int) (new Nonconformity())->add($extra + [
            'title'                       => $title,
            'plugin_grcmanager_audits_id' => $auditId,
            'finding_type'                => 'nonconformity',
            'severity'                    => 'major',
            'status'                      => 'open',
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * Compte dont le seul profil est dans l'entité donnée (sans sous-entité).
     */
    private function userOnlyIn(int $entityId): int
    {
        global $DB;

        $usersId = $this->createTestUser('PHPUnit', 'Auditeur' . $entityId);
        $DB->delete('glpi_profiles_users', ['users_id' => $usersId]);
        $DB->insert('glpi_profiles_users', [
            'users_id' => $usersId, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => 0,
        ]);

        return $usersId;
    }

    /**
     * @return list<int>
     */
    private function visibleIds(string $table): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => EntityPerimeter::criteria($table)]);
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function testATreatmentActionFollowsTheEntityOfItsRisk(): void
    {
        $this->limitTo($this->south);
        $southOverdue = DashboardCardService::overdueTreatmentActionsCount()['number'];
        $this->rootTree();
        $riskId = $this->risk($this->north, 'PHPUnit risque Nord avec plan');
        $actionId = (int) (new RiskTreatmentAction())->add([
            'plugin_grcmanager_risks_id' => $riskId,
            'description'                => 'PHPUnit action Nord',
            'due_date'                   => date('Y-m-d', strtotime('-5 days')),
        ]);
        $this->assertGreaterThan(0, $actionId);
        $action = RiskTreatmentAction::getById($actionId);
        $this->assertSame($this->north, (int) $action->fields['entities_id']);

        // L'autre filiale ne voit ni l'action ni son retard, et ne peut pas en ajouter.
        $this->limitTo($this->south);
        $this->assertNotContains($actionId, $this->visibleIds(RiskTreatmentAction::getTable()));
        $this->assertFalse((new RiskTreatmentAction())->can($actionId, READ));
        $this->assertSame($southOverdue, DashboardCardService::overdueTreatmentActionsCount()['number']);
        $this->assertFalse((new RiskTreatmentAction())->add([
            'plugin_grcmanager_risks_id' => $riskId,
            'description'                => 'PHPUnit action forcée',
        ]));
        $this->assertFalse((new RiskTreatmentAction())->update(['id' => $actionId, 'status' => 'done']));

        $this->limitTo($this->north);
        $this->assertContains($actionId, $this->visibleIds(RiskTreatmentAction::getTable()));
        $this->assertTrue((new RiskTreatmentAction())->can($actionId, UPDATE));
        $this->assertGreaterThanOrEqual(1, DashboardCardService::overdueTreatmentActionsCount()['number']);

        // Le risque change d'entité : son action le suit, sans rien changer d'autre.
        $this->rootTree();
        $this->assertTrue((new Risk())->update(['id' => $riskId, 'entities_id' => $this->south]));
        $action = RiskTreatmentAction::getById($actionId);
        $this->assertSame($this->south, (int) $action->fields['entities_id']);
        $this->assertSame('planned', (string) $action->fields['status']);
    }

    public function testAuditsAndFindingsBelongToAnEntity(): void
    {
        $this->rootTree();
        $northAudit = $this->audit($this->north, 'PHPUnit audit Nord');
        $southAudit = $this->audit($this->south, 'PHPUnit audit Sud');
        $northFinding = $this->finding($northAudit, 'PHPUnit constat Nord');
        $southFinding = $this->finding($southAudit, 'PHPUnit constat Sud');
        // Le constat prend l'entité de son audit, même si le formulaire en annonçait une autre.
        $this->assertSame($this->north, (int) Nonconformity::getById($northFinding)->fields['entities_id']);
        $this->assertSame(
            $this->south,
            (int) Nonconformity::getById($this->finding($southAudit, 'PHPUnit constat', ['entities_id' => 0]))
                ->fields['entities_id']
        );

        $this->limitTo($this->north);
        $this->assertTrue((new Audit())->can($northAudit, READ));
        $this->assertFalse((new Audit())->can($southAudit, READ), 'fiche de l\'audit de l\'autre filiale');
        $this->assertFalse((new Nonconformity())->can($southFinding, READ));
        $this->assertNotContains($southAudit, $this->visibleIds(Audit::getTable()));
        $this->assertNotContains($southFinding, $this->visibleIds(Nonconformity::getTable()));
        $this->assertSame([], Audit::getFindings($southAudit));
        $this->assertSame([$northFinding], array_column(Audit::getFindings($northAudit), 'id'));

        // Créations forcées dans l'autre filiale : refusées.
        $this->assertFalse((new Audit())->add(['title' => 'PHPUnit audit forcé', 'entities_id' => $this->south]));
        $this->assertFalse((new Nonconformity())->add([
            'title' => 'PHPUnit constat forcé', 'plugin_grcmanager_audits_id' => $southAudit,
        ]));
        $this->assertFalse((new Nonconformity())->add([
            'title' => 'PHPUnit constat forcé', 'entities_id' => $this->south,
        ]));
        // Modification en masse vers l'autre filiale : l'entité ne change pas.
        (new Audit())->update(['id' => $northAudit, 'entities_id' => $this->south]);
        $this->assertSame($this->north, (int) Audit::getById($northAudit)->fields['entities_id']);

        // Cartes : le constat de l'autre filiale ne compte pas.
        $open = DashboardCardService::openNonconformitiesCount()['number'];
        $this->rootTree();
        $this->finding($southAudit, 'PHPUnit autre constat Sud');
        $this->limitTo($this->north);
        $this->assertSame($open, DashboardCardService::openNonconformitiesCount()['number']);

        // L'audit change d'entité : ses constats le suivent ; statut et gravité ne changent pas.
        $this->rootTree();
        $this->assertTrue((new Audit())->update(['id' => $northAudit, 'entities_id' => $this->south]));
        $moved = Nonconformity::getById($northFinding);
        $this->assertSame($this->south, (int) $moved->fields['entities_id']);
        $this->assertSame('open', (string) $moved->fields['status']);
        $this->assertSame('major', (string) $moved->fields['severity']);
    }

    public function testAnAuditorLimitedToOneEntitySeesNothingOfAnother(): void
    {
        global $DB;

        $this->rootTree();
        $northAudit = $this->audit($this->north, 'PHPUnit audit Nord auditeur');
        $southAudit = $this->audit($this->south, 'PHPUnit audit Sud auditeur');
        $auditor = $this->userOnlyIn($this->north);
        $end = date('Y-m-d', strtotime('+10 days'));

        // Personnes désignées : profil exigé dans l'entité de l'audit.
        $this->assertNotSame('', AuditorAccess::grant($southAudit, $auditor, $end, 2));
        $this->assertSame('', AuditorAccess::grant($northAudit, $auditor, $end, 2));
        $this->assertFalse(AuditTeam::addMember($southAudit, $auditor, AuditTeam::ROLE_AUDITEE));
        $this->assertTrue(AuditTeam::addMember($northAudit, $auditor, AuditTeam::ROLE_AUDITEE));
        $this->assertFalse((new AuditEvidenceRequest())->add([
            'plugin_grcmanager_audits_id' => $southAudit, 'reference' => 'PHPUnit REQ Sud', 'users_id' => $auditor,
        ]));
        $this->assertGreaterThan(0, (int) (new AuditEvidenceRequest())->add([
            'plugin_grcmanager_audits_id' => $northAudit, 'reference' => 'PHPUnit REQ Nord', 'users_id' => $auditor,
        ]));

        // Accès et demande donnés avant les périmètres sur l'audit de Sud : rien ne s'ouvre.
        $DB->insert(AuditorAccess::TABLE, [
            'plugin_grcmanager_audits_id' => $southAudit, 'users_id' => $auditor,
            'date_begin' => date('Y-m-d'), 'date_end' => $end, 'users_id_grantor' => 2,
        ]);
        $DB->insert(AuditEvidenceRequest::getTable(), [
            'plugin_grcmanager_audits_id' => $southAudit, 'reference' => 'PHPUnit REQ Sud héritée',
            'users_id' => $auditor, 'status' => 'requested',
        ]);
        $this->limitTo($this->north);
        $this->assertTrue(AuditorAccess::isActive($northAudit, $auditor));
        $this->assertFalse(AuditorAccess::isActive($southAudit, $auditor));
        $this->assertSame([$northAudit], array_column(AuditorAccess::getActiveAudits($auditor), 'id'));
        $references = array_column(AuditEvidenceRequest::getAssignedTo($auditor), 'reference');
        $this->assertContains('PHPUnit REQ Nord', $references);
        $this->assertNotContains('PHPUnit REQ Sud héritée', $references);
    }

    public function testTheConsolidationCountsOpenFindingsPerChild(): void
    {
        $this->rootTree();
        $audit = $this->audit($this->north, 'PHPUnit audit Nord consolidé');
        $this->finding($audit, 'PHPUnit constat majeur', ['severity' => 'major']);
        $this->finding($audit, 'PHPUnit constat critique', ['severity' => 'critical']);
        $this->finding($audit, 'PHPUnit constat clos', ['status' => 'closed', 'corrective_action' => 'Corrigé']);
        $this->finding($audit, 'PHPUnit observation', ['finding_type' => 'observation']);

        $rows = [];
        foreach (EntityPerimeter::consolidation() as $row) {
            $rows[(int) $row['entities_id']] = $row;
        }
        $this->assertSame(2, $rows[$this->north]['nonconformities']['open']);
        $this->assertSame(1, $rows[$this->north]['nonconformities']['critical']);
        $this->assertSame(0, $rows[$this->south]['nonconformities']['open']);
    }

    /**
     * Lot 3 : saisie minimale de chaque registre (sans l'entité).
     *
     * @return array<class-string<\CommonDBTM>, array<string, mixed>>
     */
    private function registerInputs(): array
    {
        $supplier = (int) (new \Supplier())->add([
            'name' => 'PHPUnit fournisseur périmètre', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $this->assertGreaterThan(0, $supplier);

        return [
            SupplierRisk::class         => [
                'title' => 'PHPUnit risque fournisseur', 'suppliers_id' => $supplier,
                'probability' => 'rare', 'impact' => 'high',
            ],
            Training::class             => ['title' => 'PHPUnit formation'],
            ManagementReview::class     => ['title' => 'PHPUnit revue de direction'],
            Objective::class            => ['title' => 'PHPUnit objectif'],
            ComplianceObligation::class => ['title' => 'PHPUnit obligation', 'type' => 'contractual'],
            Waiver::class               => [
                'name' => 'PHPUnit dérogation', 'date_end' => date('Y-m-d', strtotime('+30 days')),
            ],
            NetworkFlow::class          => [
                'name' => 'PHPUnit flux', 'source_label' => '10.0.0.0/24', 'destination_label' => '10.0.1.0/24',
            ],
            EbiosStudy::class           => ['name' => 'PHPUnit étude EBIOS'],
        ];
    }

    public function testEachLot3RegisterBelongsToItsEntity(): void
    {
        $this->rootTree();
        foreach ($this->registerInputs() as $class => $input) {
            $north = (int) (new $class())->add($input + ['entities_id' => $this->north]);
            $south = (int) (new $class())->add($input + ['entities_id' => $this->south]);
            $this->assertGreaterThan(0, $north, $class);
            $this->assertGreaterThan(0, $south, $class);
            $this->assertSame($this->north, (int) $class::getById($north)->fields['entities_id'], $class);
            $this->assertSame(0, (int) $class::getById($north)->fields['is_recursive'], $class);

            $this->limitTo($this->north);
            $this->assertTrue((new $class())->can($north, READ), $class);
            $this->assertFalse((new $class())->can($south, READ), $class);
            $this->assertContains($north, $this->visibleIds($class::getTable()), $class);
            $this->assertNotContains($south, $this->visibleIds($class::getTable()), $class);
            // Création forcée dans l'autre filiale : refusée ; sans entité : l'entité active.
            $this->assertFalse((new $class())->add($input + ['entities_id' => $this->south]), $class);
            $default = (int) (new $class())->add($input);
            $this->assertSame($this->north, (int) $class::getById($default)->fields['entities_id'], $class);
            // Modification en masse vers l'autre filiale : l'entité ne change pas.
            (new $class())->update(['id' => $north, 'entities_id' => $this->south]);
            $this->assertSame($this->north, (int) $class::getById($north)->fields['entities_id'], $class);
            $this->rootTree();
        }
    }

    public function testLot3SearchHasEntityColumns(): void
    {
        foreach (array_keys($this->registerInputs()) as $class) {
            $ids = array_column((new $class())->rawSearchOptions(), 'id');
            $this->assertContains(80, $ids, $class);
            $this->assertContains(86, $ids, $class);
        }
    }

    public function testRelatedItemsFollowTheirParentEntity(): void
    {
        $this->rootTree();
        $southObjective = (int) (new Objective())->add([
            'title' => 'PHPUnit objectif Sud', 'entities_id' => $this->south,
        ]);
        $northObjective = (int) (new Objective())->add([
            'title' => 'PHPUnit objectif Nord', 'entities_id' => $this->north,
        ]);
        $review = (int) (new ManagementReview())->add([
            'title' => 'PHPUnit revue Nord', 'entities_id' => $this->north,
            'linked_objectives' => [$southObjective, $northObjective],
        ]);
        $this->assertGreaterThan(0, $review);

        $this->limitTo($this->north);
        // Mesure d'un objectif d'une autre entité : refusée.
        $this->assertFalse((new ObjectiveMeasurement())->add([
            'plugin_grcmanager_objectives_id' => $southObjective, 'comment' => 'PHPUnit mesure Sud',
        ]));
        $this->assertGreaterThan(0, (int) (new ObjectiveMeasurement())->add([
            'plugin_grcmanager_objectives_id' => $northObjective, 'comment' => 'PHPUnit mesure Nord',
        ]));
        // Objectifs discutés : seul celui de Nord se choisit, le lien vers Sud est conservé.
        (new ManagementReview())->update(['id' => $review, 'linked_objectives' => []]);
        $this->assertSame([$southObjective], ManagementReview::getLinkedObjectiveIds($review));

        // Formations qualifiantes des auditeurs : celles hors de la session restent choisies.
        $this->rootTree();
        $southTraining = (int) (new Training())->add([
            'title' => 'PHPUnit formation Sud', 'entities_id' => $this->south,
        ]);
        $saved = AuditProgramme::qualifyingTrainingIds();
        AuditProgramme::setQualifyingTrainingIds([$southTraining]);
        $this->limitTo($this->north);
        AuditProgramme::setQualifyingTrainingIds([]);
        $this->assertSame([$southTraining], AuditProgramme::qualifyingTrainingIds());
        $this->rootTree();
        AuditProgramme::setQualifyingTrainingIds($saved);
    }


    /**
     * Données d'une version 2.53.0 (sans entité sur les actions, audits et constats) : chaque
     * action prend l'entité de son risque ; un constat celle de son audit, ou reste à la racine.
     */
    public function testLegacyRowsAreAssignedWithoutAmbiguity(): void
    {
        global $DB;

        $this->rootTree();
        $riskId = $this->risk($this->north, 'PHPUnit risque Nord migré');
        $auditId = $this->audit($this->north, 'PHPUnit audit Nord migré');
        $DB->insert(RiskTreatmentAction::getTable(), [
            'plugin_grcmanager_risks_id' => $riskId, 'description' => 'PHPUnit action migrée', 'status' => 'planned',
        ]);
        $actionId = (int) $DB->insertId();
        $DB->insert(Nonconformity::getTable(), [
            'title' => 'PHPUnit constat migré', 'plugin_grcmanager_audits_id' => $auditId, 'status' => 'in_progress',
        ]);
        $linked = (int) $DB->insertId();
        $DB->insert(Nonconformity::getTable(), ['title' => 'PHPUnit constat sans origine', 'status' => 'open']);
        $orphan = (int) $DB->insertId();

        $installer = new Installer();
        foreach (['alignTreatmentActions', 'assignLegacyFindings'] as $step) {
            $method = new \ReflectionMethod($installer, $step);
            $method->invoke($installer);
            $method->invoke($installer);
        }

        $this->assertSame($this->north, (int) RiskTreatmentAction::getById($actionId)->fields['entities_id']);
        $finding = Nonconformity::getById($linked);
        $this->assertSame($this->north, (int) $finding->fields['entities_id']);
        $this->assertSame('in_progress', (string) $finding->fields['status']);
        $this->assertSame(0, (int) Nonconformity::getById($orphan)->fields['entities_id']);
    }

    /**
     * Migration rejouée : colonnes et clés uniques par entité en place, aucune ligne perdue.
     */
    public function testTheMigrationCanRunTwice(): void
    {
        global $DB;

        $tables = [
            Risk::getTable(), Control::getTable(), SoaVersion::getTable(),
            RiskTreatmentAction::getTable(), Audit::getTable(), Nonconformity::getTable(),
            SupplierRisk::getTable(), Training::getTable(), ManagementReview::getTable(), Objective::getTable(),
            ComplianceObligation::getTable(), Waiver::getTable(), NetworkFlow::getTable(), EbiosStudy::getTable(),
        ];
        $counts = static fn (): array => array_map(
            static fn (string $table): int => countElementsInTable($table),
            $tables
        );
        $before = $counts();

        $installer = new Installer();
        $method = new \ReflectionMethod($installer, 'migrateEntityPerimeters');
        $method->invoke($installer, new Migration(PLUGIN_GRCMANAGER_VERSION));
        $method->invoke($installer, new Migration(PLUGIN_GRCMANAGER_VERSION));

        $this->assertSame($before, $counts());
        foreach ($tables as $table) {
            $this->assertTrue($DB->fieldExists($table, 'entities_id'), $table);
            $this->assertTrue($DB->fieldExists($table, 'is_recursive'), $table);
        }
        $columns = new \ReflectionMethod($installer, 'indexColumns');
        $this->assertSame(['code', 'entities_id'], $columns->invoke($installer, Control::getTable(), 'unicity_code'));
        $this->assertSame(
            ['entities_id', 'version'],
            $columns->invoke($installer, SoaVersion::getTable(), 'unicity_version')
        );
        // La SoA commune reste dans l'entité racine, visible des sous-entités.
        $this->assertSame(0, countElementsInTable(Control::getTable(), ['entities_id' => 0, 'is_recursive' => 0]));
    }
}
