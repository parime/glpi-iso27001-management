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

use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\CertificationFinding;
use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules as Rules;
use Location;
use Supplier;

/**
 * Issue #772 : audits de certification sur des données fictives : type d'audit (tout audit
 * existant reste interne), constats saisis avec le vocabulaire de l'organisme et traités par le
 * workflow CAPA, vue et export de l'état de levée, droits, entités, préparation à la certification.
 * Indépendant de la langue : seuls les clés, valeurs et références sont comparées.
 */
final class CertificationFindingTest extends GrcmanagerIntegrationTestCase
{
    private function externalAudit(string $type = 'surveillance_1', int $entityId = 0): Audit
    {
        $supplierId = (int) (new Supplier())->add([
            'name' => 'Organisme fictif 772', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $audit = new Audit();
        $id = (int) $audit->add([
            'title'                  => 'Audit de surveillance fictif 772',
            'audit_type'             => $type,
            'suppliers_id'           => $supplierId,
            'lead_auditor'           => '  Camille Exemple  ',
            'certification_standard' => 'ISO/IEC 27001:2022',
            'status'                 => 'completed',
            'actual_date'            => '2026-03-10',
            'entities_id'            => $entityId,
        ]);
        $this->assertGreaterThan(0, $id);
        $audit->getFromDB($id);

        return $audit;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function finding(Audit $audit, string $category, string $reference, array $fields = []): Nonconformity
    {
        $finding = new Nonconformity();
        $id = (int) $finding->add($fields + [
            'title'                       => 'Constat fictif ' . $reference,
            'plugin_grcmanager_audits_id' => $audit->getID(),
            '_certification_category'     => $category,
            'body_reference'              => $reference,
            'status'                      => 'open',
        ]);
        $this->assertGreaterThan(0, $id, $reference);
        $finding->getFromDB($id);

        return $finding;
    }

    /**
     * @return array<string, array<string, mixed>> référence => constat de la vue
     */
    private function findingsByReference(int $auditId = 0, bool $openOnly = false): array
    {
        $byReference = [];
        foreach (CertificationFinding::report($auditId, $openOnly)['audits'] as $audit) {
            foreach ($audit['findings'] as $finding) {
                $byReference[$finding['reference']] = $finding;
            }
        }

        return $byReference;
    }

    public function testAnAuditIsInternalUnlessACertificationTypeIsChosen(): void
    {
        global $DB;

        $audit = new Audit();
        $id = (int) $audit->add(['title' => 'Audit interne fictif 772']);
        $audit->getFromDB($id);
        $this->assertSame('internal', $audit->fields['audit_type'], 'valeur par défaut, comme les audits migrés');
        $this->assertTrue($audit->update(['id' => $id, 'audit_type' => 'external']));
        $audit->getFromDB($id);
        $this->assertSame('internal', $audit->fields['audit_type'], 'type inconnu');

        // Un audit enregistré sans type (installation antérieure) relu comme interne.
        $DB->update(Audit::getTable(), ['audit_type' => 'internal'], ['id' => $id]);
        $this->assertFalse(CertificationFinding::isExternalAudit($id));

        $external = $this->externalAudit('renewal');
        $this->assertSame('renewal', $external->fields['audit_type']);
        $this->assertSame('Camille Exemple', $external->fields['lead_auditor']);
        $this->assertTrue(CertificationFinding::isExternalAudit($external->getID()));
        $this->assertStringContainsString('ti-certificate', Audit::getSpecificValueToDisplay('audit_type', 'renewal'));
        $this->assertSame(Rules::AUDIT_TYPES, array_keys(CertificationFinding::getAuditTypes()));
    }

    public function testBodyFindingsUseTheCapaWorkflow(): void
    {
        $audit = $this->externalAudit();
        $locationId = (int) (new Location())->add([
            'name' => 'Site fictif 772', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        $major = $this->finding($audit, Rules::CATEGORY_MAJOR_NC, 'GVE-36', [
            'audited_process'       => '  Gestion des accès ',
            'requirement_reference' => 'A.5.15',
            'locations_id'          => $locationId,
        ]);
        $this->assertSame('nonconformity', $major->fields['finding_type']);
        $this->assertSame('major', $major->fields['severity']);
        $this->assertSame('Gestion des accès', $major->fields['audited_process']);
        $this->assertSame($locationId, (int) $major->fields['locations_id']);
        $this->assertSame((int) $audit->fields['entities_id'], (int) $major->fields['entities_id']);

        // Une vraie NC ne se clôt pas sans action corrective.
        $this->assertFalse($major->update(['id' => $major->getID(), 'status' => 'closed']));
        $this->assertTrue($major->update([
            'id' => $major->getID(), 'status' => 'closed', 'corrective_action' => 'Revue des accès fictive',
        ]));

        // Point sensible et piste de progrès : action facultative.
        $optional = [Rules::CATEGORY_SENSITIVE_POINT => 'GVE-37', Rules::CATEGORY_IMPROVEMENT => 'GVE-38'];
        foreach ($optional as $category => $ref) {
            $finding = $this->finding($audit, $category, $ref);
            $expected = (Rules::fieldsFor($category) ?? [])['finding_type'] ?? '';
            $this->assertSame($expected, $finding->fields['finding_type']);
            $this->assertTrue($finding->update(['id' => $finding->getID(), 'status' => 'verified']), $ref);
        }

        // Valeur inconnue : axes inchangés.
        $minor = $this->finding($audit, Rules::CATEGORY_MINOR_NC, 'GVE-39');
        $minor->update(['id' => $minor->getID(), '_certification_category' => 'critical']);
        $minor->getFromDB($minor->getID());
        $this->assertSame('minor', $minor->fields['severity']);
    }

    public function testViewShowsTheLiftingStatusOfEachFinding(): void
    {
        global $DB;

        $audit = $this->externalAudit();
        $other = $this->externalAudit('initial');
        $internal = new Audit();
        $internalId = (int) $internal->add(['title' => 'Audit interne fictif 772']);
        $internal->getFromDB($internalId);

        $open = $this->finding($audit, Rules::CATEGORY_MAJOR_NC, 'GVE-40');
        $this->finding($audit, Rules::CATEGORY_MINOR_NC, 'GVE-41', [
            'status' => 'verified', 'corrective_action' => 'Action fictive',
        ]);
        $this->finding($audit, Rules::CATEGORY_SENSITIVE_POINT, 'GVE-42', ['status' => 'in_progress']);
        $this->finding($other, Rules::CATEGORY_IMPROVEMENT, 'GVE-43', ['status' => 'closed']);
        $this->finding($internal, Rules::CATEGORY_MINOR_NC, 'INT-1');

        $DB->insert(Document::getTable(), ['name' => 'Preuve fictive 772', 'entities_id' => 0, 'is_recursive' => 1]);
        $DB->insert(Document_Item::getTable(), [
            'documents_id' => (int) $DB->insertId(),
            'itemtype'     => Nonconformity::class,
            'items_id'     => $open->getID(),
        ]);

        $findings = $this->findingsByReference();
        $this->assertArrayNotHasKey('INT-1', $findings, 'constat d\'un audit interne');
        $this->assertSame(Rules::LIFT_NOT_LIFTED, $findings['GVE-40']['lift_state']);
        $this->assertSame(Rules::CATEGORY_MAJOR_NC, $findings['GVE-40']['category']);
        $this->assertSame(['Preuve fictive 772'], $findings['GVE-40']['documents']);
        $this->assertSame(Rules::LIFT_LIFTED, $findings['GVE-41']['lift_state']);
        $this->assertNotSame('', $findings['GVE-41']['verification_date']);
        $this->assertSame(Rules::LIFT_IN_PROGRESS, $findings['GVE-42']['lift_state']);
        $this->assertSame(Rules::LIFT_AWAITING_VERIFICATION, $findings['GVE-43']['lift_state']);

        $this->assertSame(['GVE-40', 'GVE-42'], array_keys($this->findingsByReference(0, true)), 'non levés');
        $this->assertSame(['GVE-43'], array_keys($this->findingsByReference($other->getID())), 'un seul audit');

        $report = CertificationFinding::report($audit->getID());
        $this->assertSame($audit->getID(), $report['selected']);
        $this->assertSame(3, $report['summary']['total']);
        $this->assertSame(2, $report['summary']['open']);
        $this->assertSame(1, $report['summary']['open_nc']);
        $this->assertSame('Camille Exemple', $report['audits'][0]['lead_auditor']);
        $this->assertNotSame('', $report['audits'][0]['body']);

        $html = CertificationFinding::renderPdf($report);
        $this->assertStringContainsString('GVE-40', $html);
        $this->assertStringContainsString('Preuve fictive 772', $html);
        $this->assertStringStartsWith('%PDF', PdfRenderer::renderHtmlToPdf($html));
    }

    public function testFindingsOfAnotherEntityAndWithoutRightStayHidden(): void
    {
        $entityA = $this->createTestEntity(0, 'Entité fictive A 772');
        $entityB = $this->createTestEntity(0, 'Entité fictive B 772');
        $auditA = $this->externalAudit('surveillance_1', $entityA);
        $auditB = $this->externalAudit('surveillance_2', $entityB);
        $this->finding($auditA, Rules::CATEGORY_MINOR_NC, 'A-1');
        $this->finding($auditB, Rules::CATEGORY_MAJOR_NC, 'B-1');

        $active = $_SESSION['glpiactiveentities'];
        $activeString = $_SESSION['glpiactiveentities_string'];
        $parents = $_SESSION['glpiparententities'] ?? [];
        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        $_SESSION['glpiparententities'] = [0 => 0];
        try {
            $this->assertSame(['A-1'], array_keys($this->findingsByReference()));
            $this->assertSame([], $this->findingsByReference($auditB->getID()), 'audit d\'une autre entité demandé');
            $this->assertSame(1, CertificationFinding::openCounts()['open']);
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
            $_SESSION['glpiactiveentities_string'] = $activeString;
            $_SESSION['glpiparententities'] = $parents;
        }

        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        try {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
            $this->assertTrue(CertificationFinding::canView());
            $this->assertFalse(Audit::canCreate(), 'lecture seule : aucun audit créé depuis la vue');
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
            $this->assertFalse(CertificationFinding::canView());
            $this->assertFalse(CertificationFinding::getMenuContent());
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $rights;
        }
        $this->assertContains(CertificationFinding::class, Menu::sections()[Audit::class]);
    }

    public function testReadinessShowsTheFindingsNotLifted(): void
    {
        $audit = $this->externalAudit();
        $this->finding($audit, Rules::CATEGORY_MAJOR_NC, 'GVE-50');
        $this->finding($audit, Rules::CATEGORY_SENSITIVE_POINT, 'GVE-51');

        $counts = CertificationFinding::openCounts();
        $this->assertGreaterThanOrEqual(2, $counts['open']);
        $this->assertGreaterThanOrEqual(1, $counts['open_nc']);

        $step = null;
        foreach (CertificationReadiness::report()['steps'] as $candidate) {
            if ($candidate['key'] === 'nonconformities') {
                $step = $candidate;
            }
        }
        $this->assertNotNull($step);
        $urls = array_column($step['links'], 'url');
        $this->assertContains(CertificationFinding::getSearchURL() . '?open=1', $urls);
        $this->assertCount(4, $step['facts']);
    }

    public function testFindingsTabOfACertificationAudit(): void
    {
        $audit = $this->externalAudit();
        $this->finding($audit, Rules::CATEGORY_MINOR_NC, 'GVE-60');

        ob_start();
        Nonconformity::displayTabContentForItem($audit);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('GVE-60', $html);
        $this->assertStringContainsString('grc-certification-findings-link', $html);
    }
}
