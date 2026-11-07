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

    public function testOnlyMonitoringAndMalwareControlsShowTheCoverage(): void
    {
        $this->assertTrue(WazuhEvidence::isRelevantControl('A.8.16'));
        $this->assertTrue(WazuhEvidence::isRelevantControl('A.8.7'));
        $this->assertFalse(WazuhEvidence::isRelevantControl('A.8.8'));
    }
}
