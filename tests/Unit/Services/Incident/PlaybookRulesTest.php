<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\PlaybookDefaults;
use GlpiPlugin\Grcmanager\Services\Incident\PlaybookRules;
use PHPUnit\Framework\TestCase;

final class PlaybookRulesTest extends TestCase
{
    public function testProgressAndClosureBlocking(): void
    {
        $tasks = [
            ['status' => 'done', 'is_mandatory' => 1],
            ['status' => 'not_applicable', 'is_mandatory' => 1],
            ['status' => 'todo', 'is_mandatory' => 0],
            ['status' => 'in_progress', 'is_mandatory' => 1],
        ];

        self::assertSame(
            ['done' => 2, 'total' => 4, 'percent' => 50, 'mandatory_pending' => 1],
            PlaybookRules::progress($tasks)
        );
        self::assertTrue(PlaybookRules::blocksClosure($tasks));

        $tasks[3]['status'] = 'not_applicable';
        self::assertFalse(PlaybookRules::blocksClosure($tasks));
        self::assertFalse(PlaybookRules::blocksClosure([]));
    }

    public function testTasksAreGroupedByPhaseInOrder(): void
    {
        $groups = PlaybookRules::byPhase([
            ['phase' => 'recovery', 'rank' => 3, 'name' => 'c'],
            ['phase' => 'containment', 'rank' => 2, 'name' => 'b'],
            ['phase' => 'containment', 'rank' => 1, 'name' => 'a'],
            ['phase' => 'unknown', 'rank' => 4, 'name' => 'd'],
        ]);

        self::assertSame(['containment', 'recovery', 'post_incident'], array_keys($groups));
        self::assertSame(['a', 'b'], array_column($groups['containment'], 'name'));
    }

    public function testPlaybooksOfTheIncidentCategoryComeFirst(): void
    {
        $suggested = PlaybookRules::suggest([
            ['id' => 1, 'name' => 'Phishing', 'category' => 'unauthorized_access'],
            ['id' => 2, 'name' => 'Ransomware', 'category' => 'malware'],
            ['id' => 3, 'name' => 'Malware', 'category' => 'malware'],
        ], 'malware');

        self::assertSame([3, 2, 1], array_column($suggested, 'id'));
        self::assertSame([true, true, false], array_column($suggested, 'recommended'));
    }

    public function testDefaultPlaybooksAreWellFormed(): void
    {
        $definitions = PlaybookDefaults::definitions();
        self::assertCount(5, $definitions);
        self::assertSame(
            ['ransomware', 'phishing', 'account_compromise', 'malware', 'data_leak'],
            array_column($definitions, 'key')
        );
        foreach ($definitions as $playbook) {
            self::assertNotSame([], $playbook['steps']);
            self::assertContains(true, array_column($playbook['steps'], 3), $playbook['key']);
            foreach ($playbook['steps'] as [$phase, $name]) {
                self::assertContains($phase, PlaybookRules::PHASES, $playbook['key'] . ': ' . $name);
            }
        }
    }
}
