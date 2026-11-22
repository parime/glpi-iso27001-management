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

use Appliance;
use Computer;
use GlpiPlugin\Grcmanager\ApprovedApplication;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\ApprovedApplication\ApprovedApplicationRules as Rules;
use Item_SoftwareVersion;
use Preference;
use Software;
use SoftwareVersion;
use Ticket;

/**
 * Issue #622 : applications et services en ligne approuvés (A.5.23). Fiche d'une application du cœur,
 * liste publiée aux utilisateurs, rapprochement avec l'inventaire, ticket de revue, droits et
 * entités. Données fictives.
 */
final class ApprovedApplicationTest extends GrcmanagerIntegrationTestCase
{
    private function appliance(string $name, int $entityId = 0): Appliance
    {
        $appliance = new Appliance();
        $id = (int) $appliance->add(['name' => $name, 'entities_id' => $entityId]);
        $this->assertGreaterThan(0, $id, 'Application fictive enregistrée.');
        $appliance->getFromDB($id);

        return $appliance;
    }

    private function installedSoftware(string $name, int $installs = 1): int
    {
        $softwareId = (int) (new Software())->add(['name' => $name, 'entities_id' => 0]);
        $this->assertGreaterThan(0, $softwareId);
        $versionId = (int) (new SoftwareVersion())->add([
            'softwares_id' => $softwareId, 'name' => '1.0', 'entities_id' => 0,
        ]);
        for ($i = 0; $i < $installs; $i++) {
            $computerId = (int) (new Computer())->add([
                'name' => 'PHPUnit poste ' . $name . ' ' . $i, 'entities_id' => 0,
            ]);
            $this->assertGreaterThan(0, (int) (new Item_SoftwareVersion())->add([
                'itemtype' => Computer::class, 'items_id' => $computerId,
                'softwareversions_id' => $versionId, 'entities_id' => 0,
            ]));
        }

        return $softwareId;
    }

    public function testSheetIsSanitizedAndForgottenWithTheApplication(): void
    {
        $appliance = $this->appliance('PHPUnit partage de fichiers fictif');
        $id = (int) $appliance->getID();

        $this->assertNull(ApprovedApplication::saveFor($id, [
            'appliances_id'  => 999999,
            'status'         => 'approved_by_me',
            'hosting'        => 'online',
            'max_data_level' => 'secret',
            'review_months'  => '600',
            'date_start'     => '2026-05-10',
            'date_end'       => '2026-01-01',
            'users_id_owner' => 99999999,
            'suppliers_id'   => 99999999,
            'softwares_id'   => 99999999,
            'waivers'        => ['99999999', 'x'],
            'restrictions'   => 'Pas de données clients',
        ]));
        $sheet = ApprovedApplication::getForAppliance($id);
        $this->assertNotNull($sheet);
        $this->assertSame($id, (int) $sheet['appliances_id'], 'Application du contrôleur, jamais du formulaire.');
        $this->assertSame(Rules::STATUS_EVALUATION, $sheet['status']);
        $this->assertSame(Rules::HOSTING_ONLINE, $sheet['hosting']);
        $this->assertSame('', $sheet['max_data_level']);
        $this->assertSame(60, (int) $sheet['review_months']);
        $this->assertSame('2026-05-10', $sheet['date_start']);
        $this->assertNull($sheet['date_end'], 'Fin avant le début : ignorée.');
        $this->assertSame(0, (int) $sheet['users_id_owner'], 'Responsable inexistant ignoré.');
        $this->assertSame(0, (int) $sheet['suppliers_id']);
        $this->assertSame(0, (int) $sheet['softwares_id']);
        $this->assertSame('', (string) $sheet['waivers'], 'Dérogation inexistante ignorée.');
        $this->assertSame('', (string) $sheet['restrictions'], 'Restrictions gardées seulement si restreinte.');

        $this->assertNull(ApprovedApplication::saveFor($id, [
            'status' => 'restricted', 'restrictions' => 'Liens limités à 30 jours', 'reviewed_today' => '1',
            'allowed_uses' => 'Partage avec les partenaires', 'max_data_level' => 'low',
        ]));
        $sheet = ApprovedApplication::getForAppliance($id);
        $this->assertSame(Rules::STATUS_RESTRICTED, $sheet['status']);
        $this->assertSame('Liens limités à 30 jours', $sheet['restrictions']);
        $this->assertSame(date('Y-m-d'), $sheet['last_review_date'], 'Revue faite aujourd\'hui.');

        $this->assertTrue($appliance->delete(['id' => $id], true));
        $this->assertNull(ApprovedApplication::getForAppliance($id), 'Fiche supprimée avec l\'application.');
    }

    public function testPublishedListIsReadableWithoutAnyPluginRightAndLimitedToEntities(): void
    {
        $other = $this->createTestEntity(0, 'PHPUnit entité applications B');
        $allowed = $this->appliance('PHPUnit messagerie approuvée fictive');
        $refused = $this->appliance('PHPUnit stockage refusé fictif');
        $pending = $this->appliance('PHPUnit outil en évaluation fictif');
        $ended = $this->appliance('PHPUnit outil arrêté fictif');
        $foreign = $this->appliance('PHPUnit outil entité B fictif', $other);
        $this->assertNull(ApprovedApplication::saveFor((int) $allowed->getID(), [
            'status' => 'approved', 'allowed_uses' => 'Messagerie de l\'équipe', 'max_data_level' => 'medium',
        ]));
        $this->assertNull(ApprovedApplication::saveFor((int) $refused->getID(), ['status' => 'refused']));
        $this->assertNull(ApprovedApplication::saveFor((int) $pending->getID(), ['status' => 'evaluation']));
        $this->assertNull(ApprovedApplication::saveFor((int) $ended->getID(), [
            'status' => 'approved', 'date_start' => '2020-01-01', 'date_end' => '2021-01-01',
        ]));
        $this->assertNull(ApprovedApplication::saveFor((int) $foreign->getID(), ['status' => 'approved']));

        $saved = $_SESSION['glpiactiveprofile'];
        $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = 0;
        $_SESSION['glpiactiveprofile'][Appliance::$rightname] = 0;
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = self::entitiesString($_SESSION['glpiactiveentities']);
        try {
            $published = ApprovedApplication::published(date('Y-m-d'));
            $allowedNames = array_column($published['allowed'], 'name');
            $refusedNames = array_column($published['refused'], 'name');
            $this->assertContains('PHPUnit messagerie approuvée fictive', $allowedNames);
            $this->assertNotContains('PHPUnit outil en évaluation fictif', $allowedNames);
            $this->assertNotContains('PHPUnit outil arrêté fictif', $allowedNames, 'Usage terminé.');
            $this->assertNotContains('PHPUnit outil entité B fictif', $allowedNames, 'Hors des entités.');
            $this->assertContains('PHPUnit stockage refusé fictif', $refusedNames);

            $preference = new Preference();
            $this->assertNotSame('', (new ApprovedApplication())->getTabNameForItem($preference));
            ob_start();
            $this->assertTrue(ApprovedApplication::displayTabContentForItem($preference));
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('PHPUnit messagerie approuvée fictive', $html);
            $this->assertStringNotContainsString('PHPUnit outil entité B fictif', $html);

            // Sans droit sur le plugin : ni l'onglet de l'application ni la page.
            $this->assertSame('', (new ApprovedApplication())->getTabNameForItem($allowed));
            ob_start();
            $shown = ApprovedApplication::displayTabContentForItem($allowed);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shown);
            $this->assertNull(ApprovedApplication::evidence('A.5.23'));
        } finally {
            $_SESSION['glpiactiveprofile'] = $saved;
        }
    }

    public function testApplianceTabShowsTheFormOnlyWithTheRightToEdit(): void
    {
        $appliance = $this->appliance('PHPUnit application onglet usage fictive');
        $this->assertNotSame('', (new ApprovedApplication())->getTabNameForItem($appliance));
        ob_start();
        $this->assertTrue(ApprovedApplication::displayTabContentForItem($appliance));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('_glpi_csrf_token', $html);
        $this->assertStringContainsString("name='status'", $html);

        $saved = $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME];
        $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = READ;
        try {
            ob_start();
            ApprovedApplication::displayTabContentForItem($appliance);
            $html = (string) ob_get_clean();
            $this->assertStringNotContainsString("name='status'", $html);
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = $saved;
        }
    }

    public function testInventoryFlagsRefusedAndUnlistedSoftwareWithoutChangingAnything(): void
    {
        $refused = $this->appliance('PHPUnitStockageNuage');
        $linked = $this->appliance('PHPUnit outil lié fictif');
        $softwareLinked = $this->installedSoftware('PHPUnit logiciel lié fictif', 2);
        $this->installedSoftware('PHPUnitStockageNuage Desktop 3.2', 3);
        $this->installedSoftware('PHPUnit logiciel inconnu fictif');
        $this->assertNull(ApprovedApplication::saveFor((int) $refused->getID(), ['status' => 'refused']));
        $this->assertNull(ApprovedApplication::saveFor((int) $linked->getID(), [
            'status' => 'evaluation', 'softwares_id' => $softwareLinked,
        ]));

        $inventory = ApprovedApplication::inventory(ApprovedApplication::sheets());
        $this->assertTrue($inventory['available']);
        $flagged = array_column($inventory['not_approved'], null, 'name');
        $this->assertArrayHasKey('PHPUnitStockageNuage Desktop 3.2', $flagged, 'Rapproché par le nom.');
        $this->assertSame(Rules::STATUS_REFUSED, $flagged['PHPUnitStockageNuage Desktop 3.2']['status']);
        $this->assertTrue($flagged['PHPUnitStockageNuage Desktop 3.2']['by_name']);
        $this->assertSame(3, $flagged['PHPUnitStockageNuage Desktop 3.2']['installs']);
        $this->assertArrayHasKey('PHPUnit logiciel lié fictif', $flagged, 'Rapproché par le logiciel lié.');
        $this->assertFalse($flagged['PHPUnit logiciel lié fictif']['by_name']);
        $this->assertGreaterThanOrEqual(1, $inventory['unlisted_total']);

        $sheet = ApprovedApplication::getForAppliance((int) $refused->getID());
        $this->assertSame(Rules::STATUS_REFUSED, $sheet['status'], 'Aucune modification automatique.');
    }

    public function testOverdueReviewOpensOneTicketForTheOwner(): void
    {
        $owner = $this->createTestUser('Paula', 'Responsable');
        $appliance = $this->appliance('PHPUnit outil à revoir fictif');
        $this->assertNull(ApprovedApplication::saveFor((int) $appliance->getID(), [
            'status' => 'approved', 'review_months' => '12', 'last_review_date' => '2020-03-01',
            'users_id_owner' => $owner,
        ]));
        $refused = $this->appliance('PHPUnit outil refusé ancien fictif');
        $this->assertNull(ApprovedApplication::saveFor((int) $refused->getID(), [
            'status' => 'refused', 'last_review_date' => '2020-03-01',
        ]));

        $first = ApprovedApplication::openReviewTickets(date('Y-m-d'));
        $this->assertGreaterThanOrEqual(1, $first['opened']);
        $sheet = ApprovedApplication::getForAppliance((int) $appliance->getID());
        $ticketId = (int) $sheet['review_tickets_id'];
        $this->assertGreaterThan(0, $ticketId);
        $ticket = new Ticket();
        $this->assertTrue($ticket->getFromDB($ticketId));
        $this->assertStringContainsString('PHPUnit outil à revoir fictif', (string) $ticket->fields['name']);
        $this->assertSame(Rules::STATUS_APPROVED, $sheet['status'], 'Statut jamais changé automatiquement.');
        $this->assertSame(0, (int) ApprovedApplication::getForAppliance((int) $refused->getID())['review_tickets_id']);

        ApprovedApplication::openReviewTickets(date('Y-m-d'));
        $sheet = ApprovedApplication::getForAppliance((int) $appliance->getID());
        $this->assertSame($ticketId, (int) $sheet['review_tickets_id'], 'Un seul ticket tant qu\'il reste ouvert.');
        $this->assertSame(1, countElementsInTable(Ticket::getTable(), ['id' => $ticketId, 'is_deleted' => 0]));
    }

    public function testRegisterAndEvidenceRespectEntities(): void
    {
        $other = $this->createTestEntity(0, 'PHPUnit entité registre B');
        $mine = $this->appliance('PHPUnit registre entité A fictif');
        $foreign = $this->appliance('PHPUnit registre entité B fictif', $other);
        $this->assertNull(ApprovedApplication::saveFor((int) $mine->getID(), ['status' => 'approved']));
        $this->assertNull(ApprovedApplication::saveFor((int) $foreign->getID(), ['status' => 'approved']));

        $names = array_column(ApprovedApplication::sheets(), 'appliance_name');
        $this->assertContains('PHPUnit registre entité B fictif', $names);

        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = self::entitiesString($_SESSION['glpiactiveentities']);
        $names = array_column(ApprovedApplication::sheets(), 'appliance_name');
        $this->assertContains('PHPUnit registre entité A fictif', $names);
        $this->assertNotContains('PHPUnit registre entité B fictif', $names);

        $rows = ApprovedApplication::registerRows(ApprovedApplication::sheets(), date('Y-m-d'));
        $row = array_column($rows, null, 'name')['PHPUnit registre entité A fictif'];
        $this->assertSame(
            [Rules::GAP_NO_OWNER, Rules::GAP_NO_USES, Rules::GAP_NO_LEVEL],
            $row['gaps'],
            'Approuvée sans responsable, cas d\'usage ni niveau de données.'
        );

        $evidence = ApprovedApplication::evidence('A.5.23');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(1, $evidence['indicators']['approved']);
        $this->assertNull(ApprovedApplication::evidence('A.5.1'));

        ob_start();
        ApprovedApplication::showPage();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-aa-register', $html);
        $this->assertStringContainsString('PHPUnit registre entité A fictif', $html);
        $this->assertStringNotContainsString('PHPUnit registre entité B fictif', $html);
    }
}
