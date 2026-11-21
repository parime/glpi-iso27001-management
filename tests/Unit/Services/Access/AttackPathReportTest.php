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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\AdAuditReports;
use GlpiPlugin\Grcmanager\Services\Access\AttackPathReport;
use PHPUnit\Framework\TestCase;

/**
 * Issue #835, lot 3 : lecture des graphes de chemins d'attaque de BloodHound CE et d'Adalanche
 * (exemples fictifs, fichiers piégés).
 */
final class AttackPathReportTest extends TestCase
{
    private static function sample(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/resources/' . $name);
    }

    /**
     * @param array<mixed> $graph
     */
    private static function json(array $graph): string
    {
        return (string) json_encode($graph);
    }

    public function testParsesBloodHoundExport(): void
    {
        $report = AttackPathReport::parse(self::sample('bloodhound-example.json'));

        $this->assertSame('', $report['error']);
        $this->assertSame(AttackPathReport::TOOL_BLOODHOUND, $report['tool']);
        $this->assertSame('exemple.test', $report['domain']);
        $this->assertSame(1, $report['targets']);
        $this->assertSame(6, $report['paths'], 'The tier zero administrator is not counted.');
        $this->assertSame(5, $report['users']);
        $this->assertSame(1, $report['computers']);
        $this->assertSame(1, $report['shortest']);
        $this->assertSame(
            ['SVC.SAUVEGARDE@EXEMPLE.TEST', 'POSTE-042.EXEMPLE.TEST', 'J.MARTIN@EXEMPLE.TEST',
                'A.BERNARD@EXEMPLE.TEST', 'P.LEROY@EXEMPLE.TEST', 'C.PETIT@EXEMPLE.TEST'],
            array_column($report['accounts'], 'name')
        );
        $this->assertSame(['name' => 'J.MARTIN@EXEMPLE.TEST', 'kind' => 'user', 'hops' => 3, 'routes' => 2], $report['accounts'][2]);
        $this->assertSame('computer', $report['accounts'][1]['kind']);
        $this->assertFalse($report['truncated']);
    }

    public function testParsesAdalancheExport(): void
    {
        $report = AttackPathReport::parse(self::sample('adalanche-example.json'));

        $this->assertSame('', $report['error']);
        $this->assertSame(AttackPathReport::TOOL_ADALANCHE, $report['tool']);
        $this->assertSame('exemple.test', $report['domain'], 'Domain read from the distinguished name.');
        $this->assertSame(3, $report['paths']);
        $this->assertSame(2, $report['users']);
        $this->assertSame(1, $report['computers']);
        $this->assertSame(['svc.supervision', 'SRV-FICHIERS$', 'm.durand'], array_column($report['accounts'], 'name'));
    }

    public function testAcceptsApiResponseAndNodeList(): void
    {
        $report = AttackPathReport::parse(self::json(['data' => [
            'nodes' => [
                ['id' => 'a', 'label' => 'ADMINS DU DOMAINE@EXEMPLE.TEST', 'kind' => 'Group'],
                ['id' => 'b', 'label' => 'U1@EXEMPLE.TEST', 'kinds' => ['Base', 'User']],
            ],
            'edges' => [['source' => 'b', 'target' => 'a', 'kind' => 'MemberOf']],
        ]]));

        $this->assertSame('', $report['error']);
        $this->assertSame(1, $report['paths'], 'French name of the group recognised.');
    }

    public function testAdalancheElementsAsObject(): void
    {
        $report = AttackPathReport::parse(self::json(['elements' => [
            'nodes' => [
                ['data' => ['id' => '1', 'label' => 'Domain Admins', 'type' => 'Group', 'objectSid' => 'S-1-5-21-9-512']],
                ['data' => ['id' => '2', 'label' => 'x', 'type' => 'Person']],
            ],
            'edges' => [['data' => ['source' => '2', 'target' => '1']]],
        ]]));

        $this->assertSame(1, $report['paths']);
        $this->assertSame('', $report['domain'], 'No domain in the file: the import form asks for it.');
    }

    public function testRefusesOversizedFile(): void
    {
        $report = AttackPathReport::parse(str_repeat(' ', AttackPathReport::MAX_BYTES + 1));

        $this->assertSame(AttackPathReport::ERROR_TOO_LARGE, $report['error']);
    }

    public function testRefusesDeeplyNestedJson(): void
    {
        $deep = str_repeat('[', 5000) . str_repeat(']', 5000);

        $this->assertSame(AttackPathReport::ERROR_INVALID, AttackPathReport::parse($deep)['error']);
        $this->assertSame(AttackPathReport::ERROR_INVALID, AttackPathReport::parse('{"nodes": ')['error']);
        $this->assertSame(AttackPathReport::ERROR_EMPTY, AttackPathReport::parse("  \n")['error']);
    }

    public function testRefusesOtherJsonAndGraphWithoutTarget(): void
    {
        $this->assertSame(AttackPathReport::ERROR_NOT_GRAPH, AttackPathReport::parse('{"Result": "Failed"}')['error']);
        $this->assertSame(AttackPathReport::ERROR_NOT_GRAPH, AttackPathReport::parse('"text"')['error']);
        $this->assertSame(AttackPathReport::ERROR_NOT_GRAPH, AttackPathReport::parse('{"nodes": 3, "edges": "x"}')['error']);
        $this->assertSame(AttackPathReport::ERROR_NO_TARGET, AttackPathReport::parse(self::json([
            'nodes' => ['1' => ['label' => 'U@EXEMPLE.TEST', 'kind' => 'User']],
            'edges' => [],
        ]))['error']);
    }

    public function testTrappedGraphIsReadSafely(): void
    {
        $report = AttackPathReport::parse(self::json([
            'nodes' => [
                '1' => ['label' => 'DOMAIN ADMINS@EXEMPLE.TEST', 'kind' => 'Group'],
                '2' => [
                    'label' => '<script>alert(1)</script>EVIL' . "\x07" . '@EXEMPLE.TEST', 'kind' => 'User',
                    'properties' => ['userpassword' => 'Secret-835', 'description' => 'pwd Secret-835'],
                ],
                '3' => ['label' => ['not', 'a', 'string'], 'kind' => 'User'],
                '4' => 'not an object',
                '5' => ['label' => str_repeat('A', 5000), 'kind' => 'User'],
            ],
            'edges' => [
                ['source' => '2', 'target' => '1'],
                ['source' => '3', 'target' => '2'],
                ['source' => '2', 'target' => '3'],
                ['source' => '1', 'target' => '1'],
                ['source' => '5', 'target' => '99'],
                ['source' => ['x'], 'target' => '1'],
                'junk',
            ],
        ]));

        $this->assertSame('', $report['error']);
        $this->assertSame(2, $report['paths'], 'Cycle followed once, unknown node ignored.');
        $this->assertSame('alert(1)EVIL @EXEMPLE.TEST', $report['accounts'][0]['name']);
        $this->assertSame('?', $report['accounts'][1]['name']);
        $this->assertStringNotContainsString('Secret-835', (string) json_encode($report));
        $this->assertStringNotContainsString('<', (string) json_encode($report['accounts']));
    }

    public function testLargeGraphIsTruncated(): void
    {
        $nodes = ['t' => ['label' => 'DOMAIN ADMINS@EXEMPLE.TEST', 'kind' => 'Group']];
        $edges = [];
        for ($i = 0; $i < AttackPathReport::MAX_NODES; $i++) {
            $nodes['u' . $i] = ['label' => 'U' . $i, 'kind' => 'User'];
            $edges[] = ['source' => 'u' . $i, 'target' => 't'];
        }
        $report = AttackPathReport::parse(self::json(['nodes' => $nodes, 'edges' => $edges]));

        $this->assertSame('', $report['error']);
        $this->assertTrue($report['truncated']);
        $this->assertSame(AttackPathReport::MAX_NODES, $report['nodes']);
        $this->assertCount(AttackPathReport::MAX_ACCOUNTS, $report['accounts']);
    }

    public function testMainImportPointsToAttackPathPart(): void
    {
        $this->assertSame(
            AdAuditReports::ERROR_ATTACK_PATH,
            AdAuditReports::parse(self::sample('bloodhound-example.json'))['error']
        );
        $this->assertSame(
            AdAuditReports::ERROR_ATTACK_PATH,
            AdAuditReports::parse(self::sample('adalanche-example.json'))['error']
        );
        $this->assertNotSame(
            AdAuditReports::ERROR_ATTACK_PATH,
            AdAuditReports::parse(self::sample('maester-example.json'))['error']
        );
    }
}
