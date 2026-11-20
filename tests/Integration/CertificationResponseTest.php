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
use GlpiPlugin\Grcmanager\CertificationReadiness;
use GlpiPlugin\Grcmanager\CertificationResponse;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\BodyResponseRules as Rules;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules as AuditRules;

/**
 * Issue #772, lot 3 : réponse aux constats de l'organisme de certification sur des données
 * fictives : délais de l'audit, dates d'envoi et décision saisies sur le constat, onglet, plan
 * d'actions PDF, droits, entités, préparation à la certification. Indépendant de la langue.
 */
final class CertificationResponseTest extends GrcmanagerIntegrationTestCase
{
    private const TODAY = '2026-11-20';

    /**
     * @param array<string, mixed> $fields
     */
    private function audit(array $fields = [], int $entityId = 0): Audit
    {
        $audit = new Audit();
        $id = (int) $audit->add($fields + [
            'title'                  => 'Audit de surveillance fictif 772 lot 3',
            'audit_type'             => 'surveillance_1',
            'status'                 => 'completed',
            'actual_date'            => '2026-10-15',
            'body_report_date'       => '2026-10-25',
            'body_response_due_date' => '2026-11-24',
            'body_evidence_due_date' => '2027-01-15',
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
     * @param array<string, mixed> $report
     * @return array<string, array<string, mixed>>
     */
    private function byReference(array $report): array
    {
        return array_column($report['findings'], null, 'reference');
    }

    public function testDeadlinesAndResponseFieldsAreStored(): void
    {
        $audit = $this->audit();
        $this->assertSame('2026-11-24', $audit->fields['body_response_due_date']);
        $this->assertTrue($audit->update(['id' => $audit->getID(), 'body_evidence_due_date' => '']));
        $audit->getFromDB($audit->getID());
        $this->assertNull($audit->fields['body_evidence_due_date'], 'date vidée');
        $this->assertTrue($audit->update(['id' => $audit->getID(), 'body_report_date' => '2026-13-40']));
        $audit->getFromDB($audit->getID());
        $this->assertNull($audit->fields['body_report_date'], 'date invalide');

        $finding = $this->finding($audit, AuditRules::CATEGORY_MAJOR_NC, 'GVE-70', [
            'correction'         => 'Correction fictive',
            'body_response_date' => '2026-11-18',
            'body_decision'      => 'approuve',
        ]);
        $this->assertSame('Correction fictive', $finding->fields['correction']);
        $this->assertSame('2026-11-18', $finding->fields['body_response_date']);
        $this->assertSame('', $finding->fields['body_decision'], 'décision inconnue : en attente');
        $this->assertSame('open', $finding->fields['status'], 'statut jamais modifié');
        $this->assertTrue($finding->update(['id' => $finding->getID(), 'body_decision' => 'accepted']));
        $finding->getFromDB($finding->getID());
        $this->assertSame('accepted', $finding->fields['body_decision']);
        $this->assertSame('open', $finding->fields['status']);
    }

    public function testReportShowsWhatIsStillToSend(): void
    {
        $audit = $this->audit();
        $this->finding($audit, AuditRules::CATEGORY_MAJOR_NC, 'GVE-71');
        $this->finding($audit, AuditRules::CATEGORY_MINOR_NC, 'GVE-72', [
            'correction'         => 'Correction fictive',
            'root_cause'         => 'Cause fictive',
            'corrective_action'  => 'Action fictive',
            'users_id'           => 2,
            'due_date'           => '2026-12-15',
            'body_response_date' => '2026-11-10',
            'body_decision'      => 'to_complete',
            'body_feedback'      => 'Retour fictif',
        ]);
        $this->finding($audit, AuditRules::CATEGORY_SENSITIVE_POINT, 'GVE-73');

        $report = CertificationResponse::report($audit->getID(), self::TODAY);
        $this->assertNotNull($report);
        $findings = $this->byReference($report);
        $this->assertSame(Rules::RESPONSE_DUE_SOON, $findings['GVE-71']['response_state'], '4 jours avant le délai');
        $this->assertCount(5, $findings['GVE-71']['missing'], 'correction, cause, action, responsable, échéance');
        $this->assertSame(Rules::RESPONSE_TO_COMPLETE, $findings['GVE-72']['response_state']);
        $this->assertSame([], $findings['GVE-72']['missing']);
        $this->assertSame(Rules::EVIDENCE_TO_SEND, $findings['GVE-72']['evidence_state']);
        $this->assertSame(Rules::RESPONSE_NOT_REQUIRED, $findings['GVE-73']['response_state']);
        $this->assertSame([], $findings['GVE-73']['missing']);
        $this->assertSame(2, $report['summary']['responses_to_send']);
        $this->assertSame(1, $report['to_complete']);
        $this->assertSame('soon', $report['deadlines']['response']['state']);
        $this->assertSame(4, $report['deadlines']['response']['days']);
        $this->assertSame('upcoming', $report['deadlines']['evidence']['state']);

        // Délai dépassé : en retard, compté dans la préparation à la certification.
        $late = CertificationResponse::report($audit->getID(), '2026-11-30');
        $this->assertNotNull($late);
        $this->assertSame(Rules::RESPONSE_OVERDUE, $this->byReference($late)['GVE-71']['response_state']);
        $this->assertSame('overdue', $late['deadlines']['response']['state']);
        $pending = CertificationResponse::pendingCounts('2026-11-30');
        $this->assertGreaterThanOrEqual(2, $pending['responses']);
        $this->assertGreaterThanOrEqual(1, $pending['late']);

        // Plan d'actions : les non-conformités, pas le point sensible sans réponse.
        $html = CertificationResponse::renderPdf($report);
        $this->assertStringContainsString('GVE-71', $html);
        $this->assertStringContainsString('Correction fictive', $html);
        $this->assertStringNotContainsString('GVE-73', $html);
        $this->assertStringStartsWith('%PDF', PdfRenderer::renderHtmlToPdf($html));
    }

    public function testTabIsShownOnCertificationAuditsOnly(): void
    {
        $audit = $this->audit();
        $this->finding($audit, AuditRules::CATEGORY_MINOR_NC, 'GVE-74');
        $tab = new CertificationResponse();
        $this->assertNotSame('', $tab->getTabNameForItem($audit));

        ob_start();
        $shown = CertificationResponse::displayTabContentForItem($audit);
        $html = (string) ob_get_clean();
        $this->assertTrue($shown);
        $this->assertStringContainsString('GVE-74', $html);
        $this->assertStringContainsString('grc-response-pdf', $html);
        $this->assertStringContainsString('front/certificationresponse.php?audit=' . $audit->getID(), $html);

        $internal = new Audit();
        $internal->getFromDB((int) $internal->add(['title' => 'Audit interne fictif 772 lot 3']));
        $this->assertSame('', $tab->getTabNameForItem($internal));
        ob_start();
        $this->assertFalse(CertificationResponse::displayTabContentForItem($internal));
        ob_end_clean();
        $this->assertNull(CertificationResponse::report($internal->getID()));
    }

    public function testAuditsOfAnotherEntityAndWithoutRightStayHidden(): void
    {
        $entityA = $this->createTestEntity(0, 'Entité fictive A 772 lot 3');
        $entityB = $this->createTestEntity(0, 'Entité fictive B 772 lot 3');
        $auditA = $this->audit([], $entityA);
        $auditB = $this->audit([], $entityB);
        $this->finding($auditA, AuditRules::CATEGORY_MINOR_NC, 'A-3');
        $this->finding($auditB, AuditRules::CATEGORY_MAJOR_NC, 'B-3');

        $active = $_SESSION['glpiactiveentities'];
        $activeString = $_SESSION['glpiactiveentities_string'];
        $parents = $_SESSION['glpiparententities'] ?? [];
        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        $_SESSION['glpiparententities'] = [0 => 0];
        try {
            $this->assertNotNull(CertificationResponse::report($auditA->getID()));
            $this->assertNull(CertificationResponse::report($auditB->getID()), 'audit d\'une autre entité');
            $this->assertSame(1, CertificationResponse::pendingCounts(self::TODAY)['responses']);
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
            $_SESSION['glpiactiveentities_string'] = $activeString;
            $_SESSION['glpiparententities'] = $parents;
        }

        $rights = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        try {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
            $this->assertFalse(CertificationResponse::canView());
            $this->assertSame('', (new CertificationResponse())->getTabNameForItem($auditA));
            ob_start();
            $this->assertFalse(CertificationResponse::displayTabContentForItem($auditA));
            ob_end_clean();
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $rights;
        }
    }

    public function testReadinessShowsWhatIsStillToSend(): void
    {
        $audit = $this->audit(['body_response_due_date' => '2020-01-01']);
        $this->finding($audit, AuditRules::CATEGORY_MAJOR_NC, 'GVE-75');

        $step = null;
        foreach (CertificationReadiness::report()['steps'] as $candidate) {
            if ($candidate['key'] === 'nonconformities') {
                $step = $candidate;
            }
        }
        $this->assertNotNull($step);
        $this->assertCount(5, $step['facts'], 'fait des réponses à envoyer ajouté');
        $this->assertGreaterThanOrEqual(1, CertificationResponse::pendingCounts()['late']);
    }
}
