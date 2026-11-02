<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\AuditChain;
use PHPUnit\Framework\TestCase;

final class AuditChainTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function chain(): array
    {
        $entries = [];
        $previous = AuditChain::GENESIS;
        foreach (['view', 'update', 'export'] as $i => $action) {
            $entry = [
                'id' => $i + 1, 'date' => '2026-11-02 10:0' . $i . ':00', 'users_id' => 2, 'users_id_real' => 0,
                'profiles_id' => 4, 'action' => $action, 'itemtype' => 'PluginGrcmanagerSecurityIncident',
                'items_id' => 7, 'plugin_grcmanager_securityincidents_id' => 7, 'ip' => '192.0.2.10',
                'result' => 'success', 'details' => '',
            ];
            $entry['prev_hash'] = $previous;
            $entry['hash'] = AuditChain::hash($previous, $entry);
            $previous = $entry['hash'];
            $entries[] = $entry;
        }

        return $entries;
    }

    public function testIntactChainIsValid(): void
    {
        $result = AuditChain::verify(self::chain());
        self::assertTrue($result['valid']);
        self::assertSame(3, $result['checked']);
        self::assertNull($result['broken_id']);
    }

    public function testModifiedEntryBreaksTheChainAtThatEntry(): void
    {
        $entries = self::chain();
        $entries[1]['users_id'] = 99;
        $result = AuditChain::verify($entries);
        self::assertFalse($result['valid']);
        self::assertSame(2, $result['broken_id']);
        self::assertSame(1, $result['checked']);
    }

    public function testRecomputedHashStillBreaksTheNextEntry(): void
    {
        // Falsifier une entrée et recalculer son empreinte : l'entrée suivante ne la suit plus.
        $entries = self::chain();
        $entries[1]['action'] = 'view';
        $entries[1]['hash'] = AuditChain::hash($entries[1]['prev_hash'], $entries[1]);
        self::assertSame(3, AuditChain::verify($entries)['broken_id']);
    }

    public function testDeletedEntryIsDetected(): void
    {
        $entries = self::chain();
        unset($entries[1]);
        self::assertSame(3, AuditChain::verify(array_values($entries))['broken_id']);
    }
}
