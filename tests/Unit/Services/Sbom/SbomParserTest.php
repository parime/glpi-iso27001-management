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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Sbom;

use GlpiPlugin\Grcmanager\Services\Sbom\SbomParser;
use GlpiPlugin\Grcmanager\Services\Sbom\VexRules;
use PHPUnit\Framework\TestCase;

final class SbomParserTest extends TestCase
{
    private static function cycloneDx(): string
    {
        return (string) json_encode([
            'bomFormat'   => 'CycloneDX',
            'specVersion' => '1.5',
            'metadata'    => ['component' => ['name' => 'Example portal', 'version' => '2.4.0']],
            'components'  => [
                [
                    'bom-ref' => 'pkg:npm/lodash@4.17.20', 'type' => 'library', 'name' => 'lodash',
                    'version' => '4.17.20', 'purl' => 'pkg:npm/lodash@4.17.20',
                    'licenses' => [['license' => ['id' => 'MIT']]],
                    'components' => [['bom-ref' => 'inner', 'name' => 'inner-lib', 'version' => '1.0']],
                ],
                [
                    'bom-ref' => 'ossl', 'type' => 'library', 'group' => 'org.example', 'name' => '<b>openssl</b>',
                    'version' => '3.0.1', 'cpe' => 'cpe:2.3:a:openssl:openssl:3.0.1:*:*:*:*:*:*:*',
                    'supplier' => ['name' => 'Example supplier'],
                ],
            ],
            'vulnerabilities' => [
                [
                    'id' => 'cve-2021-23337', 'ratings' => [['score' => 7.2], ['score' => 6.5]],
                    'affects' => [['ref' => 'urn:cdx:3e671687/1#pkg:npm/lodash@4.17.20']],
                    'analysis' => [
                        'state' => 'not_affected', 'justification' => 'code_not_reachable',
                        'detail' => 'Template not used',
                    ],
                ],
                ['id' => 'CVE-2022-0778', 'affects' => [['ref' => 'ossl']]],
                ['id' => 'not an id', 'affects' => [['ref' => 'ossl']]],
            ],
        ]);
    }

    public function testReadsCycloneDxComponentsAndVulnerabilities(): void
    {
        $result = SbomParser::parseSbom(self::cycloneDx());

        $this->assertSame('', $result['error']);
        $this->assertSame(SbomParser::FORMAT_CYCLONEDX, $result['format']);
        $this->assertSame('1.5', $result['spec_version']);
        $this->assertSame('Example portal', $result['name']);
        $names = array_column($result['components'], 'name');
        $this->assertSame(
            ['lodash', 'org.example/openssl', 'inner-lib'],
            $names,
            'Nested components are read, tags removed'
        );
        $this->assertSame('MIT', $result['components'][0]['licenses']);
        $this->assertSame('Example supplier', $result['components'][1]['supplier']);

        $this->assertCount(2, $result['vulnerabilities'], 'An invalid identifier is ignored');
        $first = $result['vulnerabilities'][0];
        $this->assertSame('CVE-2021-23337', $first['id']);
        $this->assertSame(['pkg:npm/lodash@4.17.20'], $first['components'], 'The bom-ref is taken after the #');
        $this->assertSame(7.2, $first['cvss']);
        $this->assertSame(VexRules::STATUS_NOT_AFFECTED, $first['status']);
        $this->assertSame('vulnerable_code_not_in_execute_path', $first['justification']);
        $this->assertSame('', $result['vulnerabilities'][1]['status']);
    }

    public function testReadsSpdxWithoutTheDescribedPackage(): void
    {
        $content = (string) json_encode([
            'spdxVersion' => 'SPDX-2.3',
            'name'        => 'Example portal 2.4.0',
            'documentDescribes' => ['SPDXRef-Product'],
            'packages'    => [
                ['SPDXID' => 'SPDXRef-Product', 'name' => 'Example portal', 'versionInfo' => '2.4.0'],
                [
                    'SPDXID' => 'SPDXRef-Pkg1', 'name' => 'log4j-core', 'versionInfo' => '2.14.1',
                    'supplier' => 'Organization: Example Foundation', 'licenseConcluded' => 'NOASSERTION',
                    'licenseDeclared' => 'Apache-2.0',
                    'externalRefs' => [
                        ['referenceCategory' => 'PACKAGE-MANAGER', 'referenceType' => 'purl',
                            'referenceLocator' => 'pkg:maven/org.apache.logging.log4j/log4j-core@2.14.1'],
                        ['referenceCategory' => 'SECURITY', 'referenceType' => 'cpe23Type',
                            'referenceLocator' => 'cpe:2.3:a:apache:log4j:2.14.1:*:*:*:*:*:*:*'],
                    ],
                ],
            ],
        ]);
        $result = SbomParser::parseSbom($content);

        $this->assertSame('', $result['error']);
        $this->assertSame(SbomParser::FORMAT_SPDX, $result['format']);
        $this->assertSame('2.3', $result['spec_version']);
        $this->assertCount(1, $result['components']);
        $component = $result['components'][0];
        $this->assertSame('log4j-core', $component['name']);
        $this->assertSame('Example Foundation', $component['supplier']);
        $this->assertSame('Apache-2.0', $component['licenses']);
        $this->assertStringStartsWith('pkg:maven/', $component['purl']);
        $this->assertSame(['apache', 'log4j'], VexRules::cpeVendorProduct($component['cpe']));
    }

    public function testRejectsTrappedFiles(): void
    {
        $this->assertSame(SbomParser::ERROR_EMPTY, SbomParser::parseSbom("  \n")['error']);
        $this->assertSame(SbomParser::ERROR_INVALID, SbomParser::parseSbom('{"bomFormat": "CycloneDX",')['error']);
        $this->assertSame(SbomParser::ERROR_NOT_SBOM, SbomParser::parseSbom('[1, 2, 3]')['error']);
        $this->assertSame(SbomParser::ERROR_NOT_SBOM, SbomParser::parseSbom('{"spdxVersion": "SPDX-3.0"}')['error']);

        // Fichier trop gros : refusé avant tout décodage.
        $large = '{"bomFormat":"CycloneDX","x":"' . str_repeat('a', SbomParser::MAX_BYTES) . '"}';
        $this->assertSame(SbomParser::ERROR_TOO_LARGE, SbomParser::parseSbom($large)['error']);

        // Imbrication profonde (bombe de profondeur) : refusée par json_decode.
        $deep = '{"bomFormat":"CycloneDX","components":' . str_repeat('[', 5000) . str_repeat(']', 5000) . '}';
        $this->assertSame(SbomParser::ERROR_INVALID, SbomParser::parseSbom($deep)['error']);

        // Trop de composants, y compris répartis dans des composants imbriqués.
        $many = array_fill(0, SbomParser::MAX_COMPONENTS / 2 + 1, ['name' => 'a', 'components' => [['name' => 'b']]]);
        $content = (string) json_encode(['bomFormat' => 'CycloneDX', 'components' => $many]);
        $this->assertSame(SbomParser::ERROR_TOO_MANY, SbomParser::parseSbom($content)['error']);

        // Champs piégés : balises et caractères de contrôle retirés, longueur coupée.
        $content = (string) json_encode(['bomFormat' => 'CycloneDX', 'components' => [[
            'name' => "<script>alert(1)</script>lib\x01", 'version' => str_repeat('9', 500),
        ]]]);
        $component = SbomParser::parseSbom($content)['components'][0];
        $this->assertSame('alert(1)lib', $component['name']);
        $this->assertSame(100, strlen($component['version']));
    }

    public function testReadsOpenVexAndCycloneDxVex(): void
    {
        $openVex = (string) json_encode([
            '@context'   => 'https://openvex.dev/ns/v0.2.0',
            '@id'        => 'https://example.com/vex/2024-001',
            'version'    => 1,
            'statements' => [
                [
                    'vulnerability' => ['name' => 'CVE-2023-44487'],
                    'products' => [[
                        '@id' => 'pkg:oci/portal',
                        'subcomponents' => [['@id' => 'pkg:golang/golang.org/x/net@0.7.0']],
                    ]],
                    'status' => 'not_affected', 'justification' => 'vulnerable_code_not_in_execute_path',
                    'impact_statement' => 'HTTP/2 is disabled',
                ],
                ['vulnerability' => 'CVE-2024-0001', 'products' => ['pkg:oci/portal'], 'status' => 'fixed'],
                ['vulnerability' => 'CVE-2024-0002', 'status' => 'unknown'],
            ],
        ]);
        $result = SbomParser::parseVex($openVex);
        $this->assertSame('', $result['error']);
        $this->assertSame(SbomParser::FORMAT_OPENVEX, $result['format']);
        $this->assertCount(2, $result['vulnerabilities'], 'An unknown status is ignored');
        $this->assertSame(['pkg:golang/golang.org/x/net@0.7.0'], $result['vulnerabilities'][0]['components']);
        $this->assertSame('HTTP/2 is disabled', $result['vulnerabilities'][0]['detail']);
        $this->assertSame([], $result['vulnerabilities'][1]['components']);
        $this->assertSame(VexRules::STATUS_FIXED, $result['vulnerabilities'][1]['status']);

        $cycloneVex = SbomParser::parseVex(self::cycloneDx());
        $this->assertSame('', $cycloneVex['error']);
        $this->assertCount(1, $cycloneVex['vulnerabilities'], 'Only analysed vulnerabilities are statements');

        $empty = SbomParser::parseVex('{"bomFormat":"CycloneDX","components":[]}');
        $this->assertSame(SbomParser::ERROR_NOT_VEX, $empty['error']);
        $this->assertSame(SbomParser::ERROR_NOT_VEX, SbomParser::parseVex('{"hello":"world"}')['error']);
    }

    public function testStatementPrecedence(): void
    {
        $statement = static fn (string $version, string $component, string $status): array => [
            'vulnerability_id' => 'CVE-2024-1', 'product_version' => $version,
            'component' => $component, 'status' => $status,
        ];
        $component = ['bom_ref' => 'ref-1', 'purl' => 'pkg:npm/lodash@4.17.20', 'name' => 'lodash'];
        $statements = [
            $statement('', 'pkg:npm/lodash', VexRules::STATUS_AFFECTED),
            $statement('2.4.0', '', VexRules::STATUS_UNDER_INVESTIGATION),
            $statement('3.0.0', 'lodash', VexRules::STATUS_FIXED),
        ];

        $found = VexRules::statementFor($statements, 'CVE-2024-1', $component, '2.4.0');
        $this->assertSame(
            VexRules::STATUS_AFFECTED,
            $found['status'] ?? null,
            'A named component wins over the whole product'
        );
        $this->assertNull(VexRules::statementFor($statements, 'CVE-2024-2', $component, '2.4.0'));
        $this->assertSame(
            VexRules::STATUS_FIXED,
            VexRules::statementFor($statements, 'CVE-2024-1', $component, '3.0.0')['status'] ?? null
        );
    }

    public function testValidationAndIndicators(): void
    {
        $this->assertSame('vulnerability', VexRules::validate('hello', 'affected', '', ''));
        $this->assertSame('status', VexRules::validate('CVE-2024-1234', 'maybe', '', ''));
        $this->assertSame('justification', VexRules::validate('CVE-2024-1234', 'not_affected', 'because', ''));
        $this->assertSame('not_affected_without_reason', VexRules::validate('CVE-2024-1234', 'not_affected', '', ' '));
        $this->assertSame('', VexRules::validate('CVE-2024-1234', 'not_affected', '', 'Feature disabled'));
        $this->assertSame('', VexRules::validate('GHSA-xxxx-yyyy-zzzz', 'affected', '', ''));
        $this->assertNull(VexRules::cpeVendorProduct('cpe:2.3:a:*:openssl:3.0.1'));
        $this->assertSame(['openssl', 'openssl'], VexRules::cpeVendorProduct('cpe:/a:openssl:openssl:3.0.1'));

        $counts = VexRules::indicators([
            ['status' => ''], ['status' => 'affected'], ['status' => 'fixed'], ['status' => 'not_affected'],
        ]);
        $this->assertSame(4, $counts['total']);
        $this->assertSame(1, $counts['without_statement']);
        $this->assertSame(1, $counts['affected']);
    }
}
