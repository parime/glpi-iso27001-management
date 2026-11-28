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

use Computer;
use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\SecurityIncidentTechnique;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Incident\WazuhAlertGrouping;
use GlpiPlugin\Grcmanager\WazuhAlertImport;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use PluginGrcmanagerSecurityIncidentTask;

/**
 * Issue #1185 : groupes d'alertes Wazuh transformés en incidents de sécurité (données fictives, sans le
 * plugin Wazuh : les groupes sont construits comme le ferait la revue). Une seule fois par alerte,
 * répétitions ajoutées à l'incident ouvert du groupe, droits respectés.
 */
final class WazuhAlertImportTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function group(int $computerId, array $keys, string $date): array
    {
        $alerts = [];
        foreach ($keys as $index => $key) {
            $alerts[] = [
                'id' => $index + 1, 'key' => $key, 'computers_id' => $computerId, 'agent' => 'agent-fictif',
                'date' => $date, 'level' => 12, 'description' => 'Echecs d\'authentification fictifs',
                'rule_id' => '5763', 'techniques' => ['T1110'],
            ];
        }

        return WazuhAlertGrouping::group($alerts, [], 0)[0];
    }

    public function testAGroupBecomesOneIncidentAndRepetitionsCompleteIt(): void
    {
        $computerId = (int) (new Computer())->add(['name' => 'PC-WAZUH-PHPUNIT', 'entities_id' => 0]);
        $suffix = bin2hex(random_bytes(4));

        $outcome = WazuhAlertImport::importGroup(
            $this->group($computerId, ["k-$suffix-1", "k-$suffix-2"], '2099-01-01 10:00:00')
        );
        $this->assertNotNull($outcome);
        $this->assertTrue($outcome['created']);
        $incident = new PluginGrcmanagerSecurityIncident();
        $this->assertTrue($incident->getFromDB($outcome['id']));
        $this->assertSame('critical', $incident->fields['severity']);
        $this->assertSame('siem', $incident->fields['source']);
        $this->assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $outcome['id'],
            'itemtype' => Computer::class, 'items_id' => $computerId,
        ]));
        $this->assertSame(1, countElementsInTable(SecurityIncidentTechnique::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $outcome['id'], 'technique' => 'T1110',
        ]));
        $this->assertSame(3, countElementsInTable(SecurityIncidentEdrImport::getAlertsTable(), [
            'plugin_grcmanager_securityincidents_id' => $outcome['id'],
        ]), 'Deux alertes et le repère du groupe.');

        // Même groupe relu : alertes déjà importées, rien de plus.
        $this->assertNull(WazuhAlertImport::importGroup(
            $this->group($computerId, ["k-$suffix-1", "k-$suffix-2"], '2099-01-01 10:00:00')
        ));

        // Répétition nouvelle : ajoutée à l'incident ouvert par une tâche, pas de nouvel incident.
        $again = WazuhAlertImport::importGroup(
            $this->group($computerId, ["k-$suffix-2", "k-$suffix-3"], '2099-01-02 10:00:00')
        );
        $this->assertSame(['id' => $outcome['id'], 'created' => false], $again);
        $this->assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncidentTask::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $outcome['id'],
        ]));
        $this->assertSame(1, countElementsInTable(SecurityIncidentEdrImport::getAlertsTable(), [
            'alert_id' => WazuhAlertGrouping::alertId("k-$suffix-3"),
        ]));

        // Incident clos : la répétition suivante ouvre un nouvel incident.
        global $DB;
        $DB->update(
            PluginGrcmanagerSecurityIncident::getTable(),
            ['status' => PluginGrcmanagerSecurityIncident::CLOSED],
            ['id' => $outcome['id']]
        );
        $later = WazuhAlertImport::importGroup(
            $this->group($computerId, ["k-$suffix-4"], '2099-01-03 10:00:00')
        );
        $this->assertNotNull($later);
        $this->assertTrue($later['created']);
        $this->assertNotSame($outcome['id'], $later['id']);
    }

    public function testNothingIsCreatedWithoutTheRightOrForAnInvisibleComputer(): void
    {
        $computerId = (int) (new Computer())->add(['name' => 'PC-WAZUH-PHPUNIT-2', 'entities_id' => 0]);
        $group = $this->group($computerId, ['k-' . bin2hex(random_bytes(4))], '2099-01-01 10:00:00');

        $saved = $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname]
            = PluginGrcmanagerSecurityIncident::READALL;
        try {
            $this->assertNull(WazuhAlertImport::importGroup($group), 'Lecture seule : aucun incident.');
            $this->assertSame(
                ['created' => [], 'updated' => [], 'skipped' => 0],
                WazuhAlertImport::importSelected([$group['key']])
            );
        } finally {
            $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::$rightname] = $saved;
        }

        $group['computers_id'] = 0;
        $this->assertNull(WazuhAlertImport::importGroup($group), 'Ordinateur inconnu : rien.');
    }

    public function testWithoutTheWazuhPluginTheReviewStaysSilent(): void
    {
        if (\Plugin::isPluginActive('wazuh')) {
            $this->markTestSkipped('Le plugin Wazuh est actif sur cette instance.');
        }
        $this->assertNull(WazuhAlertImport::reviewData(7));
        $this->assertNull(WazuhEvidenceProvider::untreatedCriticalComputers([0]));
        $this->assertSame(0, WazuhEvidenceProvider::criticalComputersCard()['number']);
        $this->assertSame(
            ['created' => [], 'updated' => [], 'skipped' => 1],
            WazuhAlertImport::importSelected(['1:5763', 'pas-une-cle'])
        );
    }
}
