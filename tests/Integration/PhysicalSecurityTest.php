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
use GlpiPlugin\Grcmanager\ComplianceEvent;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\PhysicalSecurity;
use GlpiPlugin\Grcmanager\PhysicalSecurityAssessment;
use GlpiPlugin\Grcmanager\PhysicalSecurityCheck;
use GlpiPlugin\Grcmanager\PhysicalSecurityZone;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Physical\PhysicalSecurityRules as Rules;
use Location;

/**
 * Issue #778 : évaluation de la sécurité physique des sites (A.7.1 à A.7.14) : grille, zones, actions
 * CAPA, rappel annuel, comparaison et preuve sur les mesures. Sites et adresses fictifs.
 */
final class PhysicalSecurityTest extends GrcmanagerIntegrationTestCase
{
    private function site(string $name, int $entityId = 0, int $recursive = 0): int
    {
        $id = (int) (new Location())->add([
            'name'         => $name,
            'entities_id'  => $entityId,
            'is_recursive' => $recursive,
            'address'      => '12 rue des Essais fictive',
            'town'         => 'Villefictive',
        ]);
        $this->assertGreaterThan(0, $id, 'Site fictif enregistré.');

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function assessment(int $siteId, array $input = []): PhysicalSecurityAssessment
    {
        $item = new PhysicalSecurityAssessment();
        $id = (int) $item->add($input + [
            'locations_id'    => $siteId,
            'entities_id'     => 0,
            'assessment_date' => '2026-03-02',
        ]);
        $this->assertGreaterThan(0, $id, 'Évaluation fictive enregistrée.');
        $item->getFromDB($id);

        return $item;
    }

    /** @return array<string, array<string, mixed>> code => ligne */
    private static function linesByCode(int $assessmentId): array
    {
        $lines = [];
        foreach (PhysicalSecurityCheck::getLines($assessmentId) as $line) {
            $lines[(string) $line['code']] = $line;
        }

        return $lines;
    }

    public function testCreationAddsOneLinePerControlAndExcludedControlsAreNotApplicable(): void
    {
        global $DB;

        $controls = EntityPerimeter::controlIdsByCode(0);
        $this->assertArrayHasKey('A.7.5', $controls);
        $DB->update(Control::getTable(), ['applicability' => 'no'], ['id' => $controls['A.7.5']]);

        $assessment = $this->assessment($this->site('PHPUnit Siège fictif'));
        $lines = self::linesByCode($assessment->getID());
        $this->assertSame(Rules::CONTROL_CODES, array_keys($lines), 'Une ligne par mesure, de A.7.1 à A.7.14.');
        $this->assertSame(Rules::STATE_NOT_APPLICABLE, $lines['A.7.5']['state'], 'Mesure exclue de la SoA.');
        $this->assertSame(Rules::STATE_NOT_ASSESSED, $lines['A.7.4']['state']);
        $this->assertSame(2, (int) $assessment->fields['users_id'], 'Évaluateur : la personne connectée.');
        $this->assertSame('PHPUnit Siège fictif', $assessment->getName(), 'Sans nom : celui du site.');
    }

    public function testSiteMustBeAVisibleLocationOfTheEntity(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale sites');
        $siblingId = $this->createTestEntity(0, 'PHPUnit autre filiale sites');
        $sibling = $this->site('PHPUnit Entrepôt autre filiale', $siblingId);
        $shared = $this->site('PHPUnit Campus partagé', 0, 1);

        $item = new PhysicalSecurityAssessment();
        $this->assertFalse($item->add(['locations_id' => $sibling, 'entities_id' => $childId]), 'Entité sœur.');
        $this->assertFalse($item->add(['locations_id' => 0, 'entities_id' => $childId]), 'Site obligatoire.');
        $ok = $this->assessment($shared, ['entities_id' => $childId]);
        $this->assertSame($childId, (int) $ok->fields['entities_id'], 'Lieu récursif du siège, visible de la filiale.');

        // Le site et l'entité ne changent plus.
        $this->assertTrue($ok->update(['id' => $ok->getID(), 'locations_id' => $sibling, 'entities_id' => $siblingId]));
        $ok->getFromDB($ok->getID());
        $this->assertSame($shared, (int) $ok->fields['locations_id']);
        $this->assertSame($childId, (int) $ok->fields['entities_id']);
    }

    public function testCompletedAssessmentIsDueAgainAfterTwelveMonths(): void
    {
        $assessment = $this->assessment($this->site('PHPUnit Agence fictive'));
        $this->assertNull($assessment->fields['next_assessment_date'], 'En cours : pas encore d\'échéance.');

        $this->assertTrue($assessment->update([
            'id' => $assessment->getID(), 'status' => Rules::STATUS_COMPLETED, 'users_id' => 999999,
            'plugin_grcmanager_policies_id' => 999999, 'reminder_date' => '2026-01-01 00:00:00',
        ]));
        $assessment->getFromDB($assessment->getID());
        $this->assertSame('2027-03-02', $assessment->fields['next_assessment_date']);
        $this->assertSame(0, (int) $assessment->fields['users_id'], 'Utilisateur inexistant refusé.');
        $this->assertSame(0, (int) $assessment->fields['plugin_grcmanager_policies_id'], 'Politique inexistante.');
        $this->assertNull($assessment->fields['reminder_date'], 'Date du rappel jamais reprise de la saisie.');
    }

    public function testReassessmentTakesOverTheZonesAndShowsThePreviousState(): void
    {
        $siteId = $this->site('PHPUnit Usine fictive');
        $first = $this->assessment($siteId, ['assessment_date' => '2025-03-02']);
        $this->assertTrue(PhysicalSecurityZone::addZone($first, [
            'name' => 'Salle serveurs', 'zone_type' => 'technical', 'access_means' => ['badge', 'nope'],
            'authorized_people' => 'Équipe infrastructure', 'instructions_posted' => 0,
        ]));
        $this->assertFalse(PhysicalSecurityZone::addZone($first, ['name' => '  ']), 'Zone sans nom refusée.');
        $zones = PhysicalSecurityZone::present($first->getID());
        $this->assertCount(1, $zones);
        $this->assertSame(['badge'], $zones[0]['means'], 'Moyen d\'accès inconnu ignoré.');
        $this->assertCount(1, $zones[0]['warnings'], 'Local technique sans consignes affichées.');

        $lineA71 = self::linesByCode($first->getID())['A.7.1'];
        $this->assertTrue(PhysicalSecurityCheck::updateLine($first, (int) $lineA71['id'], [
            'state' => Rules::STATE_PARTIAL, 'finding' => 'Porte de la baie non verrouillée',
        ]));

        $second = $this->assessment($siteId, ['assessment_date' => '2026-03-02']);
        $this->assertSame($first->getID(), PhysicalSecurityAssessment::previousAssessmentId($second));
        $copied = PhysicalSecurityZone::present($second->getID());
        $this->assertCount(1, $copied, 'Zones de la dernière évaluation du site reprises.');
        $this->assertSame('Salle serveurs', $copied[0]['name']);
        $rows = PhysicalSecurityCheck::present($second);
        $this->assertSame(PhysicalSecurity::stateBadges()[Rules::STATE_PARTIAL], $rows[0]['previous']);
        $this->assertSame(Rules::STATE_NOT_ASSESSED, $rows[0]['state'], 'La nouvelle grille repart de zéro.');

        // Une zone d'une autre évaluation ne se modifie pas par celle-ci.
        $otherZone = (int) PhysicalSecurityZone::getZones($first->getID())[0]['id'];
        $this->assertFalse(PhysicalSecurityZone::updateZone($second, $otherZone, ['name' => 'Piratée']));
        $this->assertFalse(PhysicalSecurityZone::deleteZone($second, $otherZone));
    }

    public function testLineKeepsOnlyTheDocumentsOfItsAssessment(): void
    {
        $assessment = $this->assessment($this->site('PHPUnit Antenne fictive'));
        $other = $this->assessment($this->site('PHPUnit Autre antenne fictive'));
        $doc = (int) (new Document())->add(['name' => 'PHPUnit photo baie', 'entities_id' => 0]);
        $foreign = (int) (new Document())->add(['name' => 'PHPUnit photo autre site', 'entities_id' => 0]);
        (new Document_Item())->add([
            'documents_id' => $doc, 'itemtype' => PhysicalSecurityAssessment::class,
            'items_id' => $assessment->getID(), 'entities_id' => 0,
        ]);
        (new Document_Item())->add([
            'documents_id' => $foreign, 'itemtype' => PhysicalSecurityAssessment::class,
            'items_id' => $other->getID(), 'entities_id' => 0,
        ]);
        $this->assertSame(
            ['itemtype' => PhysicalSecurityAssessment::class, 'items_id' => $assessment->getID()],
            RestrictedDocument::sourceOf($doc),
            'Photo lue avec les droits de l\'évaluation.'
        );

        $line = self::linesByCode($assessment->getID())['A.7.2'];
        $this->assertTrue(PhysicalSecurityCheck::updateLine($assessment, (int) $line['id'], [
            'state' => '<b>x</b>', 'finding' => ' Registre des visiteurs absent ', 'documents' => [$doc, $foreign],
        ]));
        $this->assertFalse(
            PhysicalSecurityCheck::updateLine($other, (int) $line['id'], ['state' => 'compliant']),
            'Ligne d\'une autre évaluation.'
        );
        $saved = PhysicalSecurityCheck::getLine((int) $line['id']);
        $this->assertNotNull($saved);
        $this->assertSame(Rules::STATE_NOT_ASSESSED, $saved['state'], 'État inconnu ramené à « non évalué ».');
        $this->assertSame('Registre des visiteurs absent', $saved['finding']);
        $this->assertSame([(int) $line['id'] => [$doc]], PhysicalSecurityCheck::lineDocuments([(int) $line['id']]));
        $this->assertSame(
            0,
            countElementsInTable(PhysicalSecurityCheck::DOCUMENTS_TABLE, ['documents_id' => $foreign])
        );
    }

    public function testNonCompliantLineProposesAnActionLinkedOnceSaved(): void
    {
        $siteId = $this->site('PHPUnit Data center fictif');
        $assessment = $this->assessment($siteId);
        $line = self::linesByCode($assessment->getID())['A.7.3'];
        $this->assertNull(PhysicalSecurityCheck::findingDraft((int) $line['id']), 'Non évaluée : aucune action.');
        PhysicalSecurityCheck::updateLine($assessment, (int) $line['id'], [
            'state' => Rules::STATE_NONCOMPLIANT, 'finding' => 'Local serveur encombré, consignes non affichées',
        ]);

        $draft = PhysicalSecurityCheck::findingDraft((int) $line['id']);
        $this->assertNotNull($draft);
        $this->assertSame('major', $draft['severity']);
        $this->assertSame('A.7.3', $draft['requirement_reference']);
        $this->assertSame($siteId, $draft['locations_id']);
        $this->assertStringContainsString('Local serveur encombré', $draft['description']);

        $finding = new Nonconformity();
        $findingId = (int) $finding->add([
            'title' => $draft['title'], 'description' => $draft['description'], 'finding_type' => 'nonconformity',
            'severity' => 'major', 'entities_id' => 0, 'locations_id' => $siteId,
            '_grcmanager_physicalsecuritycheck' => (int) $line['id'],
        ]);
        $this->assertGreaterThan(0, $findingId);
        $saved = PhysicalSecurityCheck::getLine((int) $line['id']);
        $this->assertNotNull($saved);
        $this->assertSame($findingId, (int) $saved['plugin_grcmanager_nonconformities_id']);
        $this->assertNull(PhysicalSecurityCheck::findingDraft((int) $line['id']), 'Déjà une action.');
        $this->assertCount(1, PhysicalSecurityCheck::linesForFinding($findingId));

        $finding->delete(['id' => $findingId], true);
        $saved = PhysicalSecurityCheck::getLine((int) $line['id']);
        $this->assertNotNull($saved);
        $this->assertSame(0, (int) $saved['plugin_grcmanager_nonconformities_id'], 'Action purgée : la ligne reste.');
    }

    public function testActionOfAnotherEntityIsNotLinked(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale actions');
        $assessment = $this->assessment($this->site('PHPUnit Agence actions'));
        $line = self::linesByCode($assessment->getID())['A.7.4'];
        PhysicalSecurityCheck::updateLine($assessment, (int) $line['id'], ['state' => Rules::STATE_PARTIAL]);
        $finding = new Nonconformity();
        $findingId = (int) $finding->add([
            'title' => 'PHPUnit action filiale', 'entities_id' => $childId,
            '_grcmanager_physicalsecuritycheck' => (int) $line['id'],
        ]);
        $this->assertGreaterThan(0, $findingId);
        $saved = PhysicalSecurityCheck::getLine((int) $line['id']);
        $this->assertNotNull($saved);
        $this->assertSame(0, (int) $saved['plugin_grcmanager_nonconformities_id']);
    }

    public function testComparisonEvidenceAndCalendarRespectTheSessionEntities(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale comparaison');
        $head = $this->assessment($this->site('PHPUnit Siège comparaison'), ['assessment_date' => '2025-01-10']);
        $old = $this->assessment($this->site('PHPUnit Filiale comparaison', $childId), [
            'entities_id' => $childId, 'assessment_date' => '2024-05-01', 'status' => Rules::STATUS_COMPLETED,
        ]);
        $newer = $this->assessment((int) $old->fields['locations_id'], [
            'entities_id' => $childId, 'assessment_date' => '2025-05-01', 'status' => Rules::STATUS_COMPLETED,
        ]);
        PhysicalSecurityCheck::updateLine($head, (int) self::linesByCode($head->getID())['A.7.1']['id'], [
            'state' => Rules::STATE_COMPLIANT,
        ]);

        $ids = array_map(static fn (array $row): int => (int) $row['id'], PhysicalSecurity::visibleLatest());
        $this->assertContains($head->getID(), $ids);
        $this->assertContains($newer->getID(), $ids, 'Dernière évaluation du site.');
        $this->assertNotContains($old->getID(), $ids, 'Évaluation remplacée par une plus récente.');

        $evidence = PhysicalSecurity::evidence('A.7.1');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(1, $evidence['counts'][Rules::STATE_COMPLIANT]);
        $this->assertNull(PhysicalSecurity::evidence('A.8.1'), 'Pas une mesure physique.');

        $events = ComplianceEvent::collect('2026-01-01 00:00:00', '2026-12-31 00:00:00');
        $uids = array_column($events, 'uid');
        $this->assertContains('physical_security-' . $newer->getID() . '@grcmanager', $uids);

        $saved = [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']];
        try {
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = "'0'";
            $ids = array_map(static fn (array $row): int => (int) $row['id'], PhysicalSecurity::visibleLatest());
            $this->assertNotContains($newer->getID(), $ids, 'Évaluation d\'une entité hors de la session.');
            $this->assertFalse($newer->can($newer->getID(), READ));
            ob_start();
            PhysicalSecurity::showPage();
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('PHPUnit Siège comparaison', $html);
            $this->assertStringNotContainsString('PHPUnit Filiale comparaison', $html);
        } finally {
            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']] = $saved;
        }
    }

    public function testNothingWithoutTheRightOfThePlugin(): void
    {
        $siteId = $this->site('PHPUnit Site sans droit');
        $assessment = $this->assessment($siteId);
        $saved = $_SESSION['glpiactiveprofile'][PhysicalSecurityAssessment::RIGHTNAME] ?? 0;
        try {
            $_SESSION['glpiactiveprofile'][PhysicalSecurityAssessment::RIGHTNAME] = 0;
            $this->assertSame([], PhysicalSecurity::visibleLatest());
            $this->assertNull(PhysicalSecurity::evidence('A.7.1'));
            $this->assertFalse(PhysicalSecurity::getMenuContent());
            $location = new Location();
            $location->getFromDB($siteId);
            $this->assertSame('', (new PhysicalSecurityAssessment())->getTabNameForItem($location));
            ob_start();
            $shown = PhysicalSecurityAssessment::displayTabContentForItem($assessment, 1);
            $html = (string) ob_get_clean();
            $this->assertFalse($shown);
            $this->assertSame('', $html);
        } finally {
            $_SESSION['glpiactiveprofile'][PhysicalSecurityAssessment::RIGHTNAME] = $saved;
        }
    }

    public function testReminderOncePerMonthForTheLastAssessmentOfEachSite(): void
    {
        $siteId = $this->site('PHPUnit Site à réévaluer');
        $late = $this->assessment($siteId, [
            'assessment_date' => '2024-01-15', 'status' => Rules::STATUS_COMPLETED,
        ]);
        $this->assertSame('2025-01-15', $late->fields['next_assessment_date']);

        $first = PhysicalSecurityAssessment::sendReminders(date('Y-m-d'));
        $this->assertGreaterThanOrEqual(1, $first['due']);
        $late->getFromDB($late->getID());
        $this->assertNotNull($late->fields['reminder_date']);
        $stamp = $late->fields['reminder_date'];
        PhysicalSecurityAssessment::sendReminders(date('Y-m-d'));
        $late->getFromDB($late->getID());
        $this->assertSame($stamp, $late->fields['reminder_date'], 'Un rappel par mois au plus.');

        // Nouvelle évaluation du site : l'ancienne n'est plus rappelée.
        $this->assessment($siteId, ['assessment_date' => date('Y-m-d')]);
        global $DB;
        $DB->update(PhysicalSecurityAssessment::getTable(), ['reminder_date' => null], ['id' => $late->getID()]);
        PhysicalSecurityAssessment::sendReminders(date('Y-m-d'));
        $late->getFromDB($late->getID());
        $this->assertNull($late->fields['reminder_date']);
    }

    public function testPurgeRemovesTheChecklistAndTheZones(): void
    {
        $assessment = $this->assessment($this->site('PHPUnit Site purgé'));
        PhysicalSecurityZone::addZone($assessment, ['name' => 'Accueil', 'zone_type' => 'public']);
        $id = $assessment->getID();
        $this->assertTrue($assessment->delete(['id' => $id], true));
        $this->assertSame([], PhysicalSecurityCheck::getLines($id));
        $this->assertSame([], PhysicalSecurityZone::getZones($id));
    }
}
