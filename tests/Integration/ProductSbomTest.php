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
use GlpiPlugin\Grcmanager\Install\SbomTables;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\ProductSbom;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use GlpiPlugin\Grcmanager\Services\Sbom\SbomParser;
use GlpiPlugin\Grcmanager\Services\Sbom\VexRules;

/**
 * Issue #869 : import d'une nomenclature CycloneDX pour une version d'une application, vulnérabilités
 * citées par le fichier et connues de la veille du parc (données fictives, aucun appel réseau),
 * déclarations VEX importées et saisies, preuves des mesures.
 */
final class ProductSbomTest extends GrcmanagerIntegrationTestCase
{
    public function testImportMatchAndDeclare(): void
    {
        global $DB;

        $suffix = (string) random_int(1000, 9999);
        $appliance = (int) (new Appliance())->add(['name' => 'PhpUnitDemo product ' . $suffix, 'entities_id' => 0]);
        $this->assertGreaterThan(0, $appliance);

        // Produit surveillé par la veille du parc : CVE connue pour la version 3.0.1.
        $library = 'phpunitdemo-ssl-' . $suffix;
        $canonical = (int) ParkVulnerability::confirm($library, 'cpe:2.3:a:phpunitdemo:ssl' . $suffix);
        $this->assertGreaterThan(0, $canonical);
        $parkCve = 'CVE-2099-8' . random_int(10000, 99999);
        ParkVulnerabilitySync::replaceParkCves($canonical, '3.0.1', [$parkCve]);

        $sbom = (string) json_encode([
            'bomFormat' => 'CycloneDX', 'specVersion' => '1.6',
            'components' => [
                ['bom-ref' => 'lib-1', 'name' => $library, 'version' => '3.0.1'],
                ['bom-ref' => 'lib-2', 'name' => 'phpunitdemo-json', 'version' => '1.2.0', 'purl' => 'pkg:npm/phpunitdemo-json@1.2.0'],
            ],
            'vulnerabilities' => [[
                'id' => 'CVE-2099-0001', 'ratings' => [['score' => 9.8]], 'affects' => [['ref' => 'lib-2']],
                'analysis' => ['state' => 'exploitable'],
            ]],
        ]);
        $result = ProductSbom::importSbom($appliance, '2.4.0', $sbom, 'portal-2.4.0.cdx.json');
        $DB->delete(ParkVulnerabilitySync::PARK_CVES_TABLE, ['cve_id' => $parkCve]);
        $this->assertSame('', $result['error']);
        $this->assertSame(2, $result['components']);
        $this->assertSame(1, $result['vulnerabilities']);
        $this->assertSame(1, $result['statements'], 'The analysis of the file is a VEX statement');
        $this->assertSame('version', ProductSbom::importSbom($appliance, ' ', $sbom, 'x.json')['error']);
        $this->assertSame(SbomParser::ERROR_NOT_SBOM, ProductSbom::importSbom($appliance, '1', '{"a":1}', 'x.json')['error']);

        ParkVulnerabilitySync::replaceParkCves($canonical, '3.0.1', [$parkCve]);
        $row = ProductSbom::sboms($appliance)[0];
        $findings = ProductSbom::findings($row, ProductSbom::components((int) $row['id']), ProductSbom::statements($appliance));
        $DB->delete(ParkVulnerabilitySync::PARK_CVES_TABLE, ['cve_id' => $parkCve]);
        $byId = array_column($findings, null, 'vulnerability_id');
        $this->assertCount(2, $findings);
        $this->assertSame('sbom', $byId['CVE-2099-0001']['source']);
        $this->assertSame(VexRules::STATUS_AFFECTED, $byId['CVE-2099-0001']['status']);
        $this->assertSame(9.8, $byId['CVE-2099-0001']['cvss']);
        $this->assertSame('park', $byId[$parkCve]['source'], 'Known by the vulnerability watch for the same version');
        $this->assertSame('', $byId[$parkCve]['status']);

        // OpenVEX : le composant de la veille n'est pas affecté.
        $vex = (string) json_encode([
            '@context' => 'https://openvex.dev/ns/v0.2.0', '@id' => 'https://example.com/vex/1',
            'statements' => [[
                'vulnerability' => ['name' => $parkCve], 'status' => 'not_affected',
                'justification' => 'vulnerable_code_not_present',
                'products' => [['@id' => 'pkg:generic/portal', 'subcomponents' => [['@id' => 'lib-1']]]],
            ]],
        ]);
        $this->assertSame(['error' => '', 'statements' => 1], ProductSbom::importVex($appliance, '2.4.0', $vex));
        // Déclaration saisie : la vulnérabilité du fichier est corrigée.
        $this->assertSame('', ProductSbom::declare($appliance, [
            'vulnerability_id' => 'cve-2099-0001', 'status' => 'fixed', 'product_version' => '2.4.0',
            'component' => 'pkg:npm/phpunitdemo-json@1.2.0',
        ]));
        $this->assertSame('not_affected_without_reason', ProductSbom::declare($appliance, [
            'vulnerability_id' => 'CVE-2099-0002', 'status' => 'not_affected',
        ]));

        ParkVulnerabilitySync::replaceParkCves($canonical, '3.0.1', [$parkCve]);
        $findings = ProductSbom::findings($row, ProductSbom::components((int) $row['id']), ProductSbom::statements($appliance));
        $evidence = ProductSbom::evidence('A.8.8');
        $DB->delete(ParkVulnerabilitySync::PARK_CVES_TABLE, ['cve_id' => $parkCve]);
        $byId = array_column($findings, null, 'vulnerability_id');
        $this->assertSame(VexRules::STATUS_NOT_AFFECTED, $byId[$parkCve]['status']);
        $this->assertSame('vulnerable_code_not_present', $byId[$parkCve]['justification']);
        $this->assertSame(VexRules::STATUS_FIXED, $byId['CVE-2099-0001']['status'], 'The newer, more precise statement wins');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(1, $evidence['products']);
        $this->assertNull(ProductSbom::evidence('A.5.1'));

        ProductSbom::deleteSbom((int) $row['id']);
        $this->assertSame(0, countElementsInTable(SbomTables::COMPONENTS, ['plugin_grcmanager_sboms_id' => (int) $row['id']]));
        $this->assertSame(0, countElementsInTable(SbomTables::VULNERABILITIES, ['plugin_grcmanager_sboms_id' => (int) $row['id']]));
    }
}
