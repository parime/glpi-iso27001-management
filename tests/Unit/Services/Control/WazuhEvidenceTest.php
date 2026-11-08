<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidence;
use PHPUnit\Framework\TestCase;

final class WazuhEvidenceTest extends TestCase
{
    /**
     * @param array<string, list<string>> $schema table => colonnes
     */
    private function resolve(array $schema): ?string
    {
        return WazuhEvidence::resolveTable(
            WazuhEvidence::AGENT_TABLES,
            WazuhEvidence::AGENT_COLUMNS,
            static fn (string $table): bool => isset($schema[$table]),
            static fn (string $table, string $column): bool => in_array($column, $schema[$table] ?? [], true)
        );
    }

    public function testTheAgentTableIsFoundUnderEitherNameOfThePlugin(): void
    {
        $columns = ['id', 'status', 'itemtype', 'item_id', 'is_deleted'];
        $this->assertSame(
            'glpi_plugin_wazuh_wazuhagents',
            $this->resolve(['glpi_plugin_wazuh_wazuhagents' => $columns])
        );
        $this->assertSame(
            'glpi_plugin_wazuh_pluginwazuhagents',
            $this->resolve(['glpi_plugin_wazuh_pluginwazuhagents' => $columns])
        );
    }

    public function testTheBridgeStaysSilentWhenTheSchemaIsMissingOrChanged(): void
    {
        $this->assertNull($this->resolve([]), 'Plugin Wazuh absent.');
        $this->assertNull(
            $this->resolve(['glpi_plugin_wazuh_wazuhagents' => ['id', 'status', 'itemtype', 'is_deleted']]),
            'Colonne item_id renommée dans une future version : rien n\'est lu.'
        );
    }

    public function testCoverageRateIsComputedOnRealComputersOnly(): void
    {
        $this->assertSame(
            ['agents' => 5, 'active' => 4, 'covered' => 3, 'computers' => 4, 'rate' => 75.0],
            WazuhEvidence::coverage(5, 4, 3, 4)
        );
        $this->assertSame(100.0, WazuhEvidence::coverage(2, 2, 5, 4)['rate'], 'Jamais plus de 100 %.');
        $this->assertNull(WazuhEvidence::coverage(1, 1, 0, 0)['rate'], 'Aucun ordinateur : pas de taux.');
    }

    public function testWazuhVulnerabilitiesAreMergedWithoutDuplicatingTheSoftwareWatch(): void
    {
        $finding = static fn (string $cve, int $computer, ?float $score, string $package): array => [
            'cve' => $cve, 'computers_id' => $computer, 'score' => $score, 'severity' => 'High',
            'package' => $package, 'version' => '1.0',
        ];
        $result = WazuhEvidence::aggregateVulnerabilities([
            $finding('CVE-2026-0001', 41, 9.8, 'openssl'),
            $finding('CVE-2026-0001', 42, 9.8, 'openssl'),
            $finding('cve-2026-0002', 41, 5.0, 'curl'),
            $finding('CVE-2026-0002', 41, 7.5, 'libcurl'),
            $finding('CVE-2026-0003', 43, null, 'zlib'),
            $finding('CVE-2026-0009', 44, 8.1, 'nginx'),
            $finding('not-a-cve', 44, 9.0, 'x'),
            $finding('CVE-2026-0004', 0, 9.0, 'y'),
        ], ['CVE-2026-0009' => true]);

        $this->assertSame(['CVE-2026-0009' => 1], $result['also'], 'CVE déjà dans la veille : pas de ligne en plus.');
        $this->assertSame(
            ['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003'],
            array_column($result['only'], 'cve_id'),
            'Tri par score décroissant, score inconnu en dernier.'
        );
        $this->assertSame(2, $result['only'][0]['machines']);
        $this->assertSame(1, $result['only'][1]['machines'], 'Deux paquets sur le même ordinateur : un seul.');
        $this->assertSame(7.5, $result['only'][1]['score']);
        $this->assertSame(['curl 1.0', 'libcurl 1.0'], $result['only'][1]['packages']);
        $this->assertNull($result['only'][2]['score']);
    }

    public function testAlertRuleIsReadFromTheStoredJsonWithoutFailingOnBadContent(): void
    {
        $this->assertSame(
            ['level' => 12, 'description' => 'Multiple authentication failures.', 'id' => '5763'],
            WazuhEvidence::parseRule('{"level":12,"description":" Multiple authentication failures. ","id":"5763"}')
        );
        $this->assertSame(15, WazuhEvidence::parseRule('{"level":"99"}')['level'], 'Niveau borné à 15.');
        $empty = ['level' => null, 'description' => '', 'id' => ''];
        $this->assertSame($empty, WazuhEvidence::parseRule(null));
        $this->assertSame($empty, WazuhEvidence::parseRule('pas du json'));
        $this->assertSame($empty, WazuhEvidence::parseRule('""'), 'Le plugin Wazuh stocke "" sans règle.');
    }

    public function testAlertSeverityFollowsTheWazuhLevels(): void
    {
        $this->assertSame('high', WazuhEvidence::alertSeverity(12));
        $this->assertSame('medium', WazuhEvidence::alertSeverity(7));
        $this->assertSame('low', WazuhEvidence::alertSeverity(6));
        $this->assertSame('unknown', WazuhEvidence::alertSeverity(null));
    }

    public function testOnlyMonitoringAndMalwareControlsShowTheCoverage(): void
    {
        $this->assertTrue(WazuhEvidence::isRelevantControl('A.8.16'));
        $this->assertTrue(WazuhEvidence::isRelevantControl('A.8.7'));
        $this->assertFalse(WazuhEvidence::isRelevantControl('A.8.8'));
    }
}
