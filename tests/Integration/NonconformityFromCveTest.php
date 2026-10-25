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

use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use PluginGrcmanagerNonconformity;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncidentCve;

/**
 * Issue #163 : plan d'action (CAPA) proposé à partir d'une CVE d'incident, sans appel réseau.
 */
final class NonconformityFromCveTest extends GrcmanagerIntegrationTestCase
{
    public function testPresetIsBuiltFromNvdKevAndReviewedBeforeCreation(): void
    {
        global $DB;

        $cve = 'CVE-2099-7' . random_int(10000, 99999);
        $incidentId = (int) (new PluginGrcmanagerSecurityIncident())->add([
            'name'        => 'PHPUnit incident navigateur',
            'entities_id' => 0,
            'content'     => 'x',
        ]);
        $DB->insert(PluginGrcmanagerSecurityIncidentCve::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'cve_id'                                 => $cve,
        ]);
        $DB->insert('glpi_plugin_grcmanager_cveenrichments', [
            'cve_id'       => $cve,
            'cvss_score'   => 8.1,
            'severity'     => 'HIGH',
            'description'  => 'Vulnérabilité fictive de démonstration.',
            'patch_links'  => json_encode([
                ['url' => 'https://editeur.example/correctif', 'tag' => 'Patch'],
                ['url' => 'https://editeur.example/avis', 'tag' => 'Vendor Advisory'],
            ]),
            'affected_cpes' => '[]',
            'fetch_status' => 'ok',
        ]);
        $due = date('Y-m-d', strtotime('+3 days'));
        ParkVulnerabilitySync::storeKev([
            $cve => ['date_added' => date('Y-m-d'), 'due_date' => $due, 'ransomware' => false],
        ]);

        try {
            $unrelated = PluginGrcmanagerNonconformity::presetFromCve($incidentId, 'CVE-2099-0000');
            $this->assertSame([], $unrelated, 'CVE non rattachée.');

            $preset = PluginGrcmanagerNonconformity::presetFromCve($incidentId, $cve);
            $this->assertSame('critical', $preset['severity'], 'Activement exploitée.');
            $this->assertSame($due, $preset['due_date'], 'Échéance CISA plus proche que 7 jours.');
            $this->assertStringContainsString('https://editeur.example/correctif', $preset['corrective_action']);
            $this->assertStringContainsString('https://editeur.example/avis', $preset['corrective_action']);
            $this->assertStringContainsString($cve, $preset['title']);
            $this->assertSame($incidentId, $preset['plugin_grcmanager_securityincidents_id']);

            // Rien n'est créé tant que l'utilisateur n'a pas validé le formulaire pré-rempli.
            $this->assertSame(0, PluginGrcmanagerNonconformity::findForIncidentCve($incidentId, $cve));
            ob_start();
            (new PluginGrcmanagerNonconformity())->showForm(0, ['from_incident' => $incidentId, 'cve' => $cve]);
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('PHPUnit incident navigateur', $html);
            $this->assertStringContainsString('name="cve_id" value="' . $cve . '"', $html);
            $this->assertStringContainsString('editeur.example/correctif', $html);

            $ncId = (int) (new PluginGrcmanagerNonconformity())->add($preset);
            $this->assertSame($ncId, PluginGrcmanagerNonconformity::findForIncidentCve($incidentId, $cve));

            // L'onglet CVE de l'incident montre le lien vers le plan d'action.
            $incident = new PluginGrcmanagerSecurityIncident();
            $incident->getFromDB($incidentId);
            ob_start();
            PluginGrcmanagerSecurityIncidentCve::displayTabContentForItem($incident);
            $this->assertStringContainsString('nonconformity.form.php?id=' . $ncId, (string) ob_get_clean());
        } finally {
            ParkVulnerabilitySync::storeKev([]);
        }
    }

    public function testOriginIsNormalized(): void
    {
        $nc = new PluginGrcmanagerNonconformity();
        $id = (int) $nc->add([
            'title'                                  => 'PHPUnit origine',
            'plugin_grcmanager_securityincidents_id' => '-4',
            'cve_id'                                 => '<script>',
        ]);
        $nc->getFromDB($id);
        $this->assertSame(0, (int) $nc->fields['plugin_grcmanager_securityincidents_id']);
        $this->assertSame('', $nc->fields['cve_id']);
    }
}
