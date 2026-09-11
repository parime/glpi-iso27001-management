<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use PluginGrcmanagerSecurityIncidentCve;

/**
 * Ported and extended from the absorbed glpi-security-incidents plugin's own
 * `SecurityIncidentTest` (ROADMAP.md "Version 2.0") — the base ITIL-object coverage (CRUD, item
 * links, notifications, CVE, rights) is unchanged; the ISO 27001 classification fields fused onto
 * this object (category/severity/cia_impact/root_cause/lessons_learned/plugin_grcmanager_risks_id,
 * absorbed from the plugin's former lightweight register, issue #29) get their own coverage below.
 */
final class SecurityIncidentTest extends GrcmanagerIntegrationTestCase
{
    public function testCanBeAddedAndReadBackFromItsOwnRealTable(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit SecurityIncident Entity');

        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add([
            'name'        => 'Test — phishing campaign',
            'entities_id' => $entityId,
            'content'     => 'A phishing email was reported by several users.',
        ]);

        $this->assertGreaterThan(0, $id);

        $reloaded = new PluginGrcmanagerSecurityIncident();
        $this->assertTrue($reloaded->getFromDB($id));
        $this->assertSame('Test — phishing campaign', $reloaded->fields['name']);
        $this->assertSame($entityId, (int) $reloaded->fields['entities_id']);
        $this->assertSame(PluginGrcmanagerSecurityIncident::INCOMING, (int) $reloaded->fields['status']);
        // ISO classification fields default to their canonical values even when not submitted.
        $this->assertSame('other', $reloaded->fields['category']);
        $this->assertSame('minor', $reloaded->fields['severity']);
    }

    public function testGetSectorizedDetailsPlacesItInTheHelpdeskSector(): void
    {
        $this->assertSame(
            ['helpdesk', PluginGrcmanagerSecurityIncident::class],
            PluginGrcmanagerSecurityIncident::getSectorizedDetails()
        );
    }

    public function testGetTypeNameUsesTheRealTranslationDomainForSingularAndPlural(): void
    {
        $this->assertSame(
            _n('Security incident', 'Security incidents', 1, 'grcmanager'),
            PluginGrcmanagerSecurityIncident::getTypeName(1)
        );
        $this->assertSame(
            _n('Security incident', 'Security incidents', 2, 'grcmanager'),
            PluginGrcmanagerSecurityIncident::getTypeName(2)
        );
        $this->assertNotSame(
            PluginGrcmanagerSecurityIncident::getTypeName(1),
            PluginGrcmanagerSecurityIncident::getTypeName(2)
        );
    }

    public function testGetItemLinkClassReturnsSecurityIncidentItem(): void
    {
        $this->assertSame(
            PluginGrcmanagerSecurityIncident_Item::class,
            PluginGrcmanagerSecurityIncident::getItemLinkClass()
        );
    }

    /**
     * Regression guard: `CommonItilObject_Item::prepareInputForAdd()` unconditionally calls
     * `CommonITILObject::getRuleCollectionClassInstance()`, which builds
     * `'Rule' . static::getType() . 'Collection'` and throws a `RuntimeException` if that class
     * doesn't exist or isn't loadable — confirmed live on the absorbed plugin before the merge,
     * linking an asset via the real "Item" tab fataled without
     * `RulePluginGrcmanagerSecurityIncident(Collection)`.
     */
    public function testAnAssetCanBeLinkedToAnIncident(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Item Link Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = $incident->add(['name' => 'Test — item link', 'entities_id' => $entityId, 'content' => 'x']);

        $computer = new \Computer();
        $computerId = $computer->add(['name' => 'PHPUnit test computer', 'entities_id' => $entityId]);
        $this->assertGreaterThan(0, $computerId);

        $link = new PluginGrcmanagerSecurityIncident_Item();
        $linkId = $link->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'itemtype'                                => 'Computer',
            'items_id'                                 => $computerId,
        ]);

        $this->assertGreaterThan(0, $linkId);
    }

    public function testAnalysisFieldsCanBeUpdated(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Analysis Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add([
            'name'        => 'Test — analysis fields',
            'entities_id' => $entityId,
            'content'     => 'Incident content.',
        ]);

        $incident->update([
            'id'                    => $id,
            'impact_content'        => 'Two workstations compromised.',
            'control_list_content'  => 'Reset credentials, isolate hosts.',
            'rollback_plan_content' => 'Restore from backup if needed.',
        ]);

        $reloaded = new PluginGrcmanagerSecurityIncident();
        $reloaded->getFromDB($id);
        $this->assertSame('Two workstations compromised.', $reloaded->fields['impact_content']);
        $this->assertSame('Reset credentials, isolate hosts.', $reloaded->fields['control_list_content']);
        $this->assertSame('Restore from backup if needed.', $reloaded->fields['rollback_plan_content']);
    }

    // --- ISO 27001 classification fields (absorbed from the former lightweight register) -------

    public function testIsoClassificationFieldsAreNormalizedOnAdd(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ISO Classification Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add([
            'name'        => 'Test — ISO classification',
            'entities_id' => $entityId,
            'content'     => 'x',
            'category'    => 'malware',
            'severity'    => 'critical',
            'cia_impact'  => ['availability', 'confidentiality'],
        ]);

        $reloaded = new PluginGrcmanagerSecurityIncident();
        $reloaded->getFromDB($id);
        $this->assertSame('malware', $reloaded->fields['category']);
        $this->assertSame('critical', $reloaded->fields['severity']);
        $this->assertSame('confidentiality,availability', $reloaded->fields['cia_impact']);
    }

    public function testUnknownCategoryFallsBackToOtherOnAdd(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Bad Category Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add([
            'name'        => 'Test — bad category',
            'entities_id' => $entityId,
            'content'     => 'x',
            'category'    => 'not_a_real_category',
        ]);

        $reloaded = new PluginGrcmanagerSecurityIncident();
        $reloaded->getFromDB($id);
        $this->assertSame('other', $reloaded->fields['category']);
    }

    /**
     * Enforces ISO/IEC 27001:2022 clause A.5.27: an incident cannot be closed without a documented
     * root cause AND documented lessons learned. Ported from the former lightweight register's own
     * validation (issue #29), now applied on the full ITIL object's `status` transition instead of
     * a string enum.
     */
    public function testClosingAnIncidentWithoutDocumentationIsRejected(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Closure Blocked Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add(['name' => 'Test — closure blocked', 'entities_id' => $entityId, 'content' => 'x']);

        $result = $incident->update(['id' => $id, 'status' => PluginGrcmanagerSecurityIncident::CLOSED]);

        $this->assertFalse($result);
        $reloaded = new PluginGrcmanagerSecurityIncident();
        $reloaded->getFromDB($id);
        $this->assertNotSame(PluginGrcmanagerSecurityIncident::CLOSED, (int) $reloaded->fields['status']);
    }

    public function testClosingAnIncidentWithBothDocumentedIsAccepted(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Closure Allowed Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add(['name' => 'Test — closure allowed', 'entities_id' => $entityId, 'content' => 'x']);

        $result = $incident->update([
            'id'              => $id,
            'status'          => PluginGrcmanagerSecurityIncident::CLOSED,
            'root_cause'      => 'Unpatched VPN gateway.',
            'lessons_learned' => 'Enforce MFA on all remote access.',
        ]);

        $this->assertNotFalse($result);
        $reloaded = new PluginGrcmanagerSecurityIncident();
        $reloaded->getFromDB($id);
        $this->assertSame(PluginGrcmanagerSecurityIncident::CLOSED, (int) $reloaded->fields['status']);
    }

    public function testLinkedRiskIdCanBeSetAndCleared(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Risk Link Entity');
        $risk = new \PluginGrcmanagerRisk();
        $riskId = $risk->add(['title' => 'PHPUnit test risk', 'entities_id' => $entityId]);
        $this->assertGreaterThan(0, $riskId);

        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add([
            'name'                       => 'Test — risk link',
            'entities_id'                => $entityId,
            'content'                    => 'x',
            'plugin_grcmanager_risks_id' => $riskId,
        ]);

        $reloaded = new PluginGrcmanagerSecurityIncident();
        $reloaded->getFromDB($id);
        $this->assertSame($riskId, (int) $reloaded->fields['plugin_grcmanager_risks_id']);

        $incident->update(['id' => $id, 'plugin_grcmanager_risks_id' => -1]);
        $reloaded->getFromDB($id);
        $this->assertSame(0, (int) $reloaded->fields['plugin_grcmanager_risks_id']);
    }

    // --- CVE tracking -----------------------------------------------------------------------

    public function testCveReferenceCanBeAddedAndIsNormalizedToUppercase(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CVE Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add(['name' => 'Test — CVE', 'entities_id' => $entityId, 'content' => 'x']);

        $cve = new PluginGrcmanagerSecurityIncidentCve();
        $cveId = $cve->add(['plugin_grcmanager_securityincidents_id' => $id, 'cve_id' => 'cve-2026-12345']);

        $this->assertGreaterThan(0, $cveId);
        $reloaded = new PluginGrcmanagerSecurityIncidentCve();
        $reloaded->getFromDB($cveId);
        $this->assertSame('CVE-2026-12345', $reloaded->fields['cve_id']);
    }

    public function testCveReferenceRejectsAMalformedIdentifier(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CVE Invalid Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add(['name' => 'Test — bad CVE', 'entities_id' => $entityId, 'content' => 'x']);

        $cve = new PluginGrcmanagerSecurityIncidentCve();
        $cveId = $cve->add(['plugin_grcmanager_securityincidents_id' => $id, 'cve_id' => 'not-a-cve']);

        $this->assertFalse($cveId);
    }

    public function testSplitIdentifiersHandlesNewlinesCommasAndDuplicates(): void
    {
        $raw = "cve-2026-11111\nCVE-2026-22222, cve-2026-11111\n\nCVE-2026-33333";

        $this->assertSame(
            ['CVE-2026-11111', 'CVE-2026-22222', 'CVE-2026-33333'],
            PluginGrcmanagerSecurityIncidentCve::splitIdentifiers($raw)
        );
    }

    public function testSplitIdentifiersOnBlankInputReturnsEmptyArray(): void
    {
        $this->assertSame([], PluginGrcmanagerSecurityIncidentCve::splitIdentifiers("  \n \t "));
    }

    /**
     * Regression guard: `CommonITILObject::getSolvedStatusArray()`/`getClosedStatusArray()` both
     * default to an empty array ("to be overridden by class") — left unoverridden, any code path
     * that merges them into a SQL `NOT IN (...)` clause fatals with "Empty IN are not allowed".
     */
    public function testStatusArraysAreNeverEmpty(): void
    {
        $this->assertNotEmpty(PluginGrcmanagerSecurityIncident::getSolvedStatusArray());
        $this->assertNotEmpty(PluginGrcmanagerSecurityIncident::getClosedStatusArray());
        $this->assertContains(
            PluginGrcmanagerSecurityIncident::SOLVED,
            PluginGrcmanagerSecurityIncident::getSolvedStatusArray()
        );
        $this->assertContains(
            PluginGrcmanagerSecurityIncident::CLOSED,
            PluginGrcmanagerSecurityIncident::getClosedStatusArray()
        );
    }

    /**
     * End-to-end regression guard for the notification seeding ported from the absorbed plugin's
     * `Install\Installer::seedSecurityIncidentNotifications()`: without an active `Notification`
     * row for the itemtype/event pair, `NotificationEvent::raiseEvent('new', $this)` (called from
     * `post_addItem()`) silently does nothing.
     */
    public function testCreatingAnIncidentQueuesANewNotification(): void
    {
        global $DB;

        \Config::setConfigurationValues('core', ['use_notifications' => 1, 'notifications_mailing' => 1]);
        $requesterId = $this->createTestUser('Notif', 'Requester', ['_useremails' => ['notif.requester@example.test']]);

        $entityId = $this->createTestEntity(0, 'PHPUnit Notification Entity');
        $countBefore = $DB->request(['FROM' => 'glpi_queuednotifications'])->count();

        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add([
            'name'                => 'Test — notification',
            'entities_id'         => $entityId,
            'content'             => 'x',
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertGreaterThan(0, $id);

        $countAfter = $DB->request(['FROM' => 'glpi_queuednotifications'])->count();
        if ($countAfter <= $countBefore) {
            global $CFG_GLPI;
            $notif = $DB->request([
                'FROM'  => 'glpi_notifications',
                'WHERE' => ['itemtype' => PluginGrcmanagerSecurityIncident::class, 'event' => 'new'],
            ])->current();
            $targetCount = $notif
                ? $DB->request([
                    'FROM'  => 'glpi_notificationtargets',
                    'WHERE' => ['notifications_id' => $notif['id']],
                ])->count()
                : null;
            $requesterEmail = $DB->request([
                'FROM'  => 'glpi_useremails',
                'WHERE' => ['users_id' => $requesterId],
            ])->current();
            $this->fail(sprintf(
                "No notification queued. Diagnostics: CFG use_notifications=%s "
                    . "notifications_mailing=%s | notif row=%s is_active=%s | target rows=%s | "
                    . "requester id=%d email=%s",
                var_export($CFG_GLPI['use_notifications'] ?? null, true),
                var_export($CFG_GLPI['notifications_mailing'] ?? null, true),
                $notif ? 'yes(id=' . $notif['id'] . ')' : 'NONE',
                $notif['is_active'] ?? 'n/a',
                var_export($targetCount, true),
                $requesterId,
                $requesterEmail['email'] ?? 'NONE'
            ));
        }

        $latest = $DB->request(['FROM' => 'glpi_queuednotifications', 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        $this->assertStringContainsString(__('New security incident', 'grcmanager'), $latest['name']);
        $this->assertStringContainsString('Test — notification', $latest['name']);
    }

    public function testSolvingAnIncidentQueuesASolvedNotification(): void
    {
        global $DB;

        \Config::setConfigurationValues('core', ['use_notifications' => 1, 'notifications_mailing' => 1]);
        $requesterId = $this->createTestUser('Notif', 'SolvedRequester', [
            '_useremails' => ['notif.solved.requester@example.test'],
        ]);

        $entityId = $this->createTestEntity(0, 'PHPUnit Solved Notification Entity');
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = $incident->add([
            'name'                => 'Test — solved notification',
            'entities_id'         => $entityId,
            'content'             => 'x',
            '_users_id_requester' => $requesterId,
        ]);

        $incident->update(['id' => $id, 'status' => PluginGrcmanagerSecurityIncident::SOLVED]);

        $latest = $DB->request(['FROM' => 'glpi_queuednotifications', 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        $this->assertStringContainsString(__('Security incident solved', 'grcmanager'), $latest['name']);
    }

    /**
     * Regression guard: `ALLSTANDARDRIGHT` alone (READ/UPDATE/CREATE/DELETE/PURGE) does NOT
     * include `self::READALL` (a separate bit added by this class's own `getRights()` override) —
     * confirmed the hard way on the absorbed plugin before the merge, Super-Admin got a real 403
     * viewing an incident they hadn't personally created after install granted only
     * `ALLSTANDARDRIGHT`.
     */
    public function testSuperAdminProfileRightIncludesReadAll(): void
    {
        global $DB;

        $superAdmin = $DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Super-Admin']])->current();
        $right = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['name' => 'plugin_grcmanager_securityincident', 'profiles_id' => $superAdmin['id']],
        ])->current();

        $this->assertNotNull($right);
        $this->assertSame(
            PluginGrcmanagerSecurityIncident::READALL,
            (int) $right['rights'] & PluginGrcmanagerSecurityIncident::READALL,
            'Super-Admin must hold READALL, not just the standard CRUD bits.'
        );
    }
}
