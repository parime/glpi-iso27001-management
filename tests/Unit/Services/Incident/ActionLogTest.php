<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\ActionLog;
use PHPUnit\Framework\TestCase;

final class ActionLogTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function actions(): array
    {
        return [
            ['id' => 2, 'date' => '2026-10-02 10:00:00', 'users_id' => 7, 'target' => 'SRV-AD01', 'tool' => 'powershell',
                'command' => 'Get-ADUser jdoe', 'finding' => 'compte actif', 'result' => 'success'],
            ['id' => 1, 'date' => '2026-10-01 09:00:00', 'users_id' => 5, 'target' => 'PC-COMPTA-03', 'tool' => 'edr',
                'command' => 'isolate', 'finding' => '', 'result' => 'partial'],
            ['id' => 3, 'date' => '2026-10-03 11:00:00', 'users_id' => 7, 'target' => 'FW-01', 'tool' => 'firewall',
                'command' => 'block 203.0.113.7', 'finding' => '', 'result' => 'to_check'],
        ];
    }

    public function testSummary(): void
    {
        $summary = ActionLog::summary(self::actions());

        self::assertSame(3, $summary['count']);
        self::assertSame('2026-10-01 09:00:00', $summary['first']);
        self::assertSame('2026-10-03 11:00:00', $summary['last']);
        self::assertSame(['success' => 1, 'partial' => 1, 'failure' => 0, 'to_check' => 1], $summary['results']);
        self::assertSame([7, 5], $summary['users']);
    }

    public function testSummaryOfAnEmptyLog(): void
    {
        $summary = ActionLog::summary([]);
        self::assertSame(0, $summary['count']);
        self::assertNull($summary['first']);
    }

    public function testHashIgnoresRowOrderButNotContent(): void
    {
        $actions = self::actions();
        $hash = ActionLog::contentHash($actions);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame($hash, ActionLog::contentHash(array_reverse($actions)));

        $actions[0]['command'] = 'Get-ADUser jdoe -Properties *';
        self::assertNotSame($hash, ActionLog::contentHash($actions));
    }
}
