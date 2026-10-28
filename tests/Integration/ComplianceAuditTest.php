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

use GlpiPlugin\Grcmanager\ComplianceAudit as Audit;

/**
 * Issue #162 : audit de conformité technique sur des éléments fictifs.
 */
final class ComplianceAuditTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return list<int> identifiants des éléments en écart pour un contrôle
     */
    private static function gapIds(array $results, string $check): array
    {
        return array_column($results[$check]['gaps'], 'id');
    }

    public function testChecksFindGapsAndTicketIsNotDuplicated(): void
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $bare = (int) (new \Computer())->add(['name' => 'PHPUnit poste sans antivirus', 'entities_id' => 0]);
        $safe = (int) (new \Computer())->add(['name' => 'PHPUnit poste protégé', 'entities_id' => 0]);
        $stale = (int) (new \Computer())->add(['name' => 'PHPUnit poste oublié', 'entities_id' => 0]);
        $DB->update(
            'glpi_computers',
            ['is_dynamic' => 1, 'last_inventory_update' => '2000-01-01 00:00:00'],
            ['id' => $stale]
        );
        $DB->update('glpi_computers', ['is_dynamic' => 1, 'last_inventory_update' => $now], ['id' => $safe]);
        $antivirus = ['itemtype' => \Computer::class, 'name' => 'Antivirus PHPUnit', 'is_deleted' => 0];
        $DB->insert('glpi_itemantiviruses', $antivirus + ['items_id' => $safe, 'is_active' => 1, 'is_uptodate' => 1]);
        $DB->insert('glpi_itemantiviruses', $antivirus + ['items_id' => $stale, 'is_active' => 1, 'is_uptodate' => 0]);
        $disk = ['itemtype' => \Computer::class, 'name' => 'Disque PHPUnit', 'is_deleted' => 0];
        $DB->insert('glpi_items_disks', $disk + ['items_id' => $bare, 'mountpoint' => 'C:', 'encryption_status' => 0]);
        $DB->insert('glpi_items_disks', $disk + ['items_id' => $safe, 'mountpoint' => 'C:', 'encryption_status' => 1]);
        // Issue #308 : la partition de récupération (sans lettre) d'un poste BitLocker n'est pas un écart.
        $DB->insert(
            'glpi_items_disks',
            $disk + ['items_id' => $safe, 'mountpoint' => 'Récupération', 'encryption_status' => 0]
        );
        $change = ['entities_id' => 0, 'status' => \CommonITILObject::CLOSED, 'date' => $now, 'closedate' => $now];
        $DB->insert('glpi_changes', $change + ['name' => 'PHPUnit changement sans validation']);
        $unvalidated = (int) $DB->insertId();
        $DB->insert('glpi_changes', $change + ['name' => 'PHPUnit changement validé une fois']);
        $validatedOnce = (int) $DB->insertId();
        $DB->insert('glpi_changevalidations', [
            'changes_id'      => $validatedOnce,
            'status'          => \CommonITILValidation::ACCEPTED,
            'entities_id'     => 0,
            'itemtype_target' => \User::class,
            'items_id_target' => 2,
        ]);

        $results = Audit::run(1);
        $this->assertContains($bare, self::gapIds($results, 'antivirus'));
        $this->assertContains($stale, self::gapIds($results, 'antivirus'), 'Antivirus pas à jour.');
        $this->assertNotContains($safe, self::gapIds($results, 'antivirus'));
        $this->assertContains($bare, self::gapIds($results, 'encryption'));
        $this->assertNotContains($safe, self::gapIds($results, 'encryption'));
        $this->assertNotContains($stale, self::gapIds($results, 'encryption'), 'Volumes non inventoriés.');
        $this->assertContains($stale, self::gapIds($results, 'inventory'));
        $this->assertNotContains($safe, self::gapIds($results, 'inventory'));
        $this->assertNotContains($bare, self::gapIds($results, 'inventory'), 'Poste saisi à la main.');
        $this->assertContains($unvalidated, self::gapIds($results, 'change_validation'));
        $this->assertNotContains($validatedOnce, self::gapIds($results, 'change_validation'));
        $this->assertContains($validatedOnce, self::gapIds(Audit::run(2), 'change_validation'), 'Double validation.');
        $this->assertTrue($results['accounts']['evaluated']);

        $ticketId = Audit::createTicket('antivirus');
        $this->assertGreaterThan(0, $ticketId);
        $this->assertSame(1, countElementsInTable('glpi_items_tickets', [
            'tickets_id' => $ticketId,
            'itemtype'   => \Computer::class,
            'items_id'   => $bare,
        ]));
        $this->assertSame($ticketId, Audit::createTicket('antivirus'), 'Le ticket ouvert est réutilisé.');
        $changeTicket = Audit::createTicket('change_validation');
        $this->assertSame(1, countElementsInTable('glpi_changes_tickets', [
            'tickets_id' => $changeTicket,
            'changes_id' => $unvalidated,
        ]));
        $this->assertSame(0, Audit::createTicket('accounts'), 'Les comptes se revoient dans l\'hygiène des comptes.');
        $this->assertSame(0, Audit::createTicket('inconnu'));

        // Ticket résolu : le suivant est un nouveau ticket.
        (new \Ticket())->update(['id' => $ticketId, 'status' => \CommonITILObject::SOLVED]);
        $this->assertNull(Audit::openTicket('antivirus'));
    }

    /**
     * Issue #305 : un poste au statut exclu (réformé) n'est compté dans aucun contrôle.
     */
    public function testExcludedStatesAreIgnored(): void
    {
        global $DB;

        $retired = (int) (new \State())->add(['name' => 'PHPUnit Réformé', 'entities_id' => 0, 'is_recursive' => 1]);
        $active = (int) (new \State())->add(['name' => 'PHPUnit En service', 'entities_id' => 0, 'is_recursive' => 1]);
        $computer = ['entities_id' => 0];
        $old = (int) (new \Computer())->add($computer + ['name' => 'PHPUnit poste réformé', 'states_id' => $retired]);
        $used = (int) (new \Computer())->add($computer + ['name' => 'PHPUnit poste actif', 'states_id' => $active]);
        $none = (int) (new \Computer())->add(['name' => 'PHPUnit poste sans statut', 'entities_id' => 0]);

        try {
            Audit::setExcludedStateIds([(string) $retired, 'x', -3, 999999999, $retired]);
            $this->assertSame([$retired], Audit::getExcludedStateIds(), 'Statuts inexistants et doublons écartés.');

            $gaps = self::gapIds(Audit::run(), 'antivirus');
            $this->assertNotContains($old, $gaps);
            $this->assertContains($used, $gaps);
            $this->assertContains($none, $gaps, 'Un poste sans statut reste contrôlé.');

            Audit::setExcludedStateIds([]);
            $this->assertContains($old, self::gapIds(Audit::run(), 'antivirus'));
        } finally {
            Audit::setExcludedStateIds([]);
            $DB->delete('glpi_computers', ['id' => [$old, $used, $none]]);
            $DB->delete('glpi_states', ['id' => [$retired, $active]]);
        }
    }

    public function testCheckIsNotEvaluatedWithoutNativeRight(): void
    {
        $computerRight = $_SESSION['glpiactiveprofile']['computer'] ?? 0;
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        try {
            $results = Audit::run();
            $this->assertFalse($results['antivirus']['evaluated']);
            $this->assertSame([], $results['antivirus']['gaps']);
            $this->assertTrue($results['change_validation']['evaluated']);
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $computerRight;
        }
    }
}
