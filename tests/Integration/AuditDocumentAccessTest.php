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
use GlpiPlugin\Grcmanager\AuditTeam;
use GlpiPlugin\Grcmanager\DocumentAccess;
use GlpiPlugin\Grcmanager\RestrictedDocument;

/**
 * Issue #140 (phase 2) : documents d'audit réservés à l'équipe de l'audit et au groupe RSSI,
 * historique des téléchargements, notes de travail.
 */
final class AuditDocumentAccessTest extends GrcmanagerIntegrationTestCase
{
    private const SESSION_USER = 2;

    protected function setUp(): void
    {
        parent::setUp();
        // Indépendant du groupe RSSI de l'instance (annulé avec la transaction du test).
        \GlpiPlugin\Grcmanager\AuditTeam::setCisoGroupId(0);
    }

    private function audit(int $auditorId): int
    {
        $id = (int) (new \GlpiPlugin\Grcmanager\Audit())->add(['title' => 'Audit #140', 'users_id' => $auditorId]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function attach(string $itemtype, int $itemsId): int
    {
        $documentsId = (int) (new \Document())->add(['name' => 'Plan d\'audit', 'entities_id' => 0]);
        $this->assertGreaterThan(0, (int) (new \Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => $itemtype,
            'items_id'     => $itemsId,
        ]));

        return $documentsId;
    }

    public function testDocumentJoinedToAnAuditIsReservedToItsTeam(): void
    {
        $auditId = $this->audit($this->createTestUser('Alice', 'Auditrice'));
        $documentsId = $this->attach(\GlpiPlugin\Grcmanager\Audit::class, $auditId);

        $this->assertSame(
            ['itemtype' => \GlpiPlugin\Grcmanager\Audit::class, 'items_id' => $auditId],
            \GlpiPlugin\Grcmanager\RestrictedDocument::sourceOf($documentsId)
        );
        // Super-Admin hors équipe : refusé malgré tous ses droits.
        $this->assertFalse(\GlpiPlugin\Grcmanager\RestrictedDocument::canReadSource($documentsId));

        $this->assertTrue(
            \GlpiPlugin\Grcmanager\AuditTeam::addMember(
                $auditId,
                self::SESSION_USER,
                \GlpiPlugin\Grcmanager\AuditTeam::ROLE_AUDITEE
            )
        );
        $this->assertTrue(\GlpiPlugin\Grcmanager\RestrictedDocument::canReadSource($documentsId));
    }

    public function testAuditorAndResponsibleAreMembersFromTheForm(): void
    {
        $auditId = $this->audit(self::SESSION_USER);
        $this->assertTrue(\GlpiPlugin\Grcmanager\AuditTeam::canAccessDocuments($auditId, self::SESSION_USER));

        $responsible = $this->createTestUser('Rémi', 'Responsable');
        $this->assertFalse(\GlpiPlugin\Grcmanager\AuditTeam::canAccessDocuments($auditId, $responsible));
        (new \GlpiPlugin\Grcmanager\Audit())->update(['id' => $auditId, 'users_id_responsible' => $responsible]);
        $this->assertTrue(\GlpiPlugin\Grcmanager\AuditTeam::canAccessDocuments($auditId, $responsible));
    }

    public function testFindingDocumentTakesTheAudienceOfItsAudit(): void
    {
        $auditId = $this->audit($this->createTestUser('Alice', 'Auditrice'));
        $findingId = (int) (new \GlpiPlugin\Grcmanager\Nonconformity())->add([
            'title'                       => 'Constat #140',
            'plugin_grcmanager_audits_id' => $auditId,
        ]);
        $documentsId = $this->attach(\GlpiPlugin\Grcmanager\Nonconformity::class, $findingId);

        $this->assertSame(
            ['itemtype' => \GlpiPlugin\Grcmanager\Audit::class, 'items_id' => $auditId],
            \GlpiPlugin\Grcmanager\RestrictedDocument::sourceOf($documentsId)
        );
    }

    public function testCisoGroupReadsTheDocumentsOfEveryAudit(): void
    {
        $auditId = $this->audit($this->createTestUser('Alice', 'Auditrice'));
        $documentsId = $this->attach(\GlpiPlugin\Grcmanager\Audit::class, $auditId);
        $this->assertFalse(\GlpiPlugin\Grcmanager\RestrictedDocument::canReadSource($documentsId));

        $groupsId = (int) (new \Group())->add(['name' => 'RSSI #140', 'entities_id' => 0]);
        (new \Group_User())->add(['groups_id' => $groupsId, 'users_id' => self::SESSION_USER]);
        \GlpiPlugin\Grcmanager\AuditTeam::setCisoGroupId($groupsId);

        $this->assertTrue(\GlpiPlugin\Grcmanager\RestrictedDocument::canReadSource($documentsId));
    }

    public function testAlreadyRestrictedDocumentKeepsItsSourceWhenJoinedToAnAudit(): void
    {
        $auditId = $this->audit(self::SESSION_USER);
        $review = new \GlpiPlugin\Grcmanager\AccessReview();
        $reviewId = (int) $review->add(['name' => 'Revue #140', 'entities_id' => 0]);
        $documentsId = (int) (new \Document())->add(['name' => 'Rapport de revue', 'entities_id' => 0]);
        $this->assertTrue($review->getFromDB($reviewId));
        \GlpiPlugin\Grcmanager\RestrictedDocument::register($documentsId, $review);

        (new \Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => \GlpiPlugin\Grcmanager\Audit::class,
            'items_id'     => $auditId,
        ]);

        $source = \GlpiPlugin\Grcmanager\RestrictedDocument::sourceOf($documentsId);
        $this->assertSame(\GlpiPlugin\Grcmanager\AccessReview::class, $source['itemtype'] ?? null);
    }

    public function testDownloadsAreListedAndPurgedWithTheAudit(): void
    {
        $auditId = $this->audit(self::SESSION_USER);
        $documentsId = $this->attach(\GlpiPlugin\Grcmanager\Audit::class, $auditId);
        \GlpiPlugin\Grcmanager\DocumentAccess::log(
            $documentsId,
            \GlpiPlugin\Grcmanager\Audit::class,
            $auditId,
            self::SESSION_USER
        );

        $accesses = \GlpiPlugin\Grcmanager\DocumentAccess::getAccesses(\GlpiPlugin\Grcmanager\Audit::class, $auditId);
        $this->assertCount(1, $accesses);
        $this->assertSame($documentsId, $accesses[0]['documents_id']);

        $auditee = $this->createTestUser('Paul', 'Audite');
        \GlpiPlugin\Grcmanager\AuditTeam::addMember($auditId, $auditee, \GlpiPlugin\Grcmanager\AuditTeam::ROLE_AUDITEE);
        (new \GlpiPlugin\Grcmanager\Audit())->delete(['id' => $auditId], true);
        $this->assertSame(
            [],
            \GlpiPlugin\Grcmanager\DocumentAccess::getAccesses(\GlpiPlugin\Grcmanager\Audit::class, $auditId)
        );
        $this->assertSame([], \GlpiPlugin\Grcmanager\AuditTeam::getMembers($auditId));
    }

    public function testOnlyDownloadsOfTheRequestedDocumentAreDetected(): void
    {
        $saved = [$_SERVER['REQUEST_URI'] ?? null, $_GET['docid'] ?? null];
        try {
            $_SERVER['REQUEST_URI'] = '/front/document.send.php?docid=42';
            $_GET['docid'] = '42';
            $this->assertTrue(\GlpiPlugin\Grcmanager\DocumentAccess::isDownloadOf(42));
            $this->assertFalse(\GlpiPlugin\Grcmanager\DocumentAccess::isDownloadOf(43));
            $_SERVER['REQUEST_URI'] = '/front/document.form.php?id=42';
            $this->assertFalse(\GlpiPlugin\Grcmanager\DocumentAccess::isDownloadOf(42));
        } finally {
            [$_SERVER['REQUEST_URI'], $_GET['docid']] = $saved;
        }
    }

    public function testAuditHasNotesAndTheProfileTabOffersNoteRights(): void
    {
        $this->assertArrayHasKey(READNOTE, (new \GlpiPlugin\Grcmanager\Audit())->getRights());
        $rights = \GlpiPlugin\Grcmanager\Profile::getRightsMatrix()[0]['rights'];
        $this->assertArrayHasKey(READNOTE, $rights);
        $this->assertArrayHasKey(UPDATENOTE, $rights);
    }
}
