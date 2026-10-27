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

use PluginGrcmanagerAudit;
use PluginGrcmanagerAuditTeam;
use PluginGrcmanagerDocumentAccess;
use PluginGrcmanagerRestrictedDocument;

/**
 * Issue #140 (phase 2) : documents d'audit réservés à l'équipe de l'audit et au groupe RSSI,
 * historique des téléchargements, notes de travail.
 */
final class AuditDocumentAccessTest extends GrcmanagerIntegrationTestCase
{
    private const SESSION_USER = 2;

    private function audit(int $auditorId): int
    {
        $id = (int) (new PluginGrcmanagerAudit())->add(['title' => 'Audit #140', 'users_id' => $auditorId]);
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
        $documentsId = $this->attach(PluginGrcmanagerAudit::class, $auditId);

        $this->assertSame(
            ['itemtype' => PluginGrcmanagerAudit::class, 'items_id' => $auditId],
            PluginGrcmanagerRestrictedDocument::sourceOf($documentsId)
        );
        // Super-Admin hors équipe : refusé malgré tous ses droits.
        $this->assertFalse(PluginGrcmanagerRestrictedDocument::canReadSource($documentsId));

        $this->assertTrue(
            PluginGrcmanagerAuditTeam::addMember($auditId, self::SESSION_USER, PluginGrcmanagerAuditTeam::ROLE_AUDITEE)
        );
        $this->assertTrue(PluginGrcmanagerRestrictedDocument::canReadSource($documentsId));
    }

    public function testAuditorAndResponsibleAreMembersFromTheForm(): void
    {
        $auditId = $this->audit(self::SESSION_USER);
        $this->assertTrue(PluginGrcmanagerAuditTeam::canAccessDocuments($auditId, self::SESSION_USER));

        $responsible = $this->createTestUser('Rémi', 'Responsable');
        $this->assertFalse(PluginGrcmanagerAuditTeam::canAccessDocuments($auditId, $responsible));
        (new PluginGrcmanagerAudit())->update(['id' => $auditId, 'users_id_responsible' => $responsible]);
        $this->assertTrue(PluginGrcmanagerAuditTeam::canAccessDocuments($auditId, $responsible));
    }

    public function testFindingDocumentTakesTheAudienceOfItsAudit(): void
    {
        $auditId = $this->audit($this->createTestUser('Alice', 'Auditrice'));
        $findingId = (int) (new \PluginGrcmanagerNonconformity())->add([
            'title'                       => 'Constat #140',
            'plugin_grcmanager_audits_id' => $auditId,
        ]);
        $documentsId = $this->attach(\PluginGrcmanagerNonconformity::class, $findingId);

        $this->assertSame(
            ['itemtype' => PluginGrcmanagerAudit::class, 'items_id' => $auditId],
            PluginGrcmanagerRestrictedDocument::sourceOf($documentsId)
        );
    }

    public function testCisoGroupReadsTheDocumentsOfEveryAudit(): void
    {
        $auditId = $this->audit($this->createTestUser('Alice', 'Auditrice'));
        $documentsId = $this->attach(PluginGrcmanagerAudit::class, $auditId);
        $this->assertFalse(PluginGrcmanagerRestrictedDocument::canReadSource($documentsId));

        $groupsId = (int) (new \Group())->add(['name' => 'RSSI #140', 'entities_id' => 0]);
        (new \Group_User())->add(['groups_id' => $groupsId, 'users_id' => self::SESSION_USER]);
        PluginGrcmanagerAuditTeam::setCisoGroupId($groupsId);

        $this->assertTrue(PluginGrcmanagerRestrictedDocument::canReadSource($documentsId));
    }

    public function testAlreadyRestrictedDocumentKeepsItsSourceWhenJoinedToAnAudit(): void
    {
        $auditId = $this->audit(self::SESSION_USER);
        $review = new \PluginGrcmanagerAccessReview();
        $reviewId = (int) $review->add(['name' => 'Revue #140', 'entities_id' => 0]);
        $documentsId = (int) (new \Document())->add(['name' => 'Rapport de revue', 'entities_id' => 0]);
        $this->assertTrue($review->getFromDB($reviewId));
        PluginGrcmanagerRestrictedDocument::register($documentsId, $review);

        (new \Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => PluginGrcmanagerAudit::class,
            'items_id'     => $auditId,
        ]);

        $source = PluginGrcmanagerRestrictedDocument::sourceOf($documentsId);
        $this->assertSame(\PluginGrcmanagerAccessReview::class, $source['itemtype'] ?? null);
    }

    public function testDownloadsAreListedAndPurgedWithTheAudit(): void
    {
        $auditId = $this->audit(self::SESSION_USER);
        $documentsId = $this->attach(PluginGrcmanagerAudit::class, $auditId);
        PluginGrcmanagerDocumentAccess::log($documentsId, PluginGrcmanagerAudit::class, $auditId, self::SESSION_USER);

        $accesses = PluginGrcmanagerDocumentAccess::getAccesses(PluginGrcmanagerAudit::class, $auditId);
        $this->assertCount(1, $accesses);
        $this->assertSame($documentsId, $accesses[0]['documents_id']);

        $auditee = $this->createTestUser('Paul', 'Audite');
        PluginGrcmanagerAuditTeam::addMember($auditId, $auditee, PluginGrcmanagerAuditTeam::ROLE_AUDITEE);
        (new PluginGrcmanagerAudit())->delete(['id' => $auditId], true);
        $this->assertSame([], PluginGrcmanagerDocumentAccess::getAccesses(PluginGrcmanagerAudit::class, $auditId));
        $this->assertSame([], PluginGrcmanagerAuditTeam::getMembers($auditId));
    }

    public function testOnlyDownloadsOfTheRequestedDocumentAreDetected(): void
    {
        $saved = [$_SERVER['REQUEST_URI'] ?? null, $_GET['docid'] ?? null];
        try {
            $_SERVER['REQUEST_URI'] = '/front/document.send.php?docid=42';
            $_GET['docid'] = '42';
            $this->assertTrue(PluginGrcmanagerDocumentAccess::isDownloadOf(42));
            $this->assertFalse(PluginGrcmanagerDocumentAccess::isDownloadOf(43));
            $_SERVER['REQUEST_URI'] = '/front/document.form.php?id=42';
            $this->assertFalse(PluginGrcmanagerDocumentAccess::isDownloadOf(42));
        } finally {
            [$_SERVER['REQUEST_URI'], $_GET['docid']] = $saved;
        }
    }

    public function testAuditHasNotesAndTheProfileTabOffersNoteRights(): void
    {
        $this->assertArrayHasKey(READNOTE, (new PluginGrcmanagerAudit())->getRights());
        $rights = \PluginGrcmanagerProfile::getRightsMatrix()[0]['rights'];
        $this->assertArrayHasKey(READNOTE, $rights);
        $this->assertArrayHasKey(UPDATENOTE, $rights);
    }
}
