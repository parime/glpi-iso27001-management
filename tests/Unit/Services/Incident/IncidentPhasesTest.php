<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\IncidentPhases;
use PHPUnit\Framework\TestCase;

final class IncidentPhasesTest extends TestCase
{
    public function testComputesElapsedHoursAndCurrentPhase(): void
    {
        $phases = IncidentPhases::compute([
            'date'            => '2026-10-01 08:00:00',
            'acknowledged_at' => '2026-10-01 09:30:00',
            'contained_at'    => '2026-10-02 08:00:00',
            'eradicated_at'   => null,
            'recovered_at'    => '',
            'closedate'       => null,
        ]);

        self::assertSame(
            ['detection', 'acknowledgement', 'containment', 'eradication', 'recovery', 'closure'],
            array_column($phases, 'key')
        );
        self::assertSame([0, 1, 24, null, null, null], array_column($phases, 'hours'));
        self::assertSame([true, true, true, false, false, false], array_column($phases, 'done'));
        self::assertSame('containment', $phases[array_search(true, array_column($phases, 'current'), true)]['key']);
        self::assertNotContains(true, array_column($phases, 'out_of_order'));
    }

    public function testFlagsAPhaseDatedBeforeThePreviousOne(): void
    {
        $phases = IncidentPhases::compute([
            'date'          => '2026-10-01 08:00:00',
            'contained_at'  => '2026-10-03 08:00:00',
            'eradicated_at' => '2026-10-02 08:00:00',
        ]);

        self::assertTrue($phases[3]['out_of_order']);
        self::assertFalse($phases[2]['out_of_order']);
        self::assertSame(24, $phases[3]['hours']);
    }

    public function testNoDateMeansNoCurrentPhase(): void
    {
        self::assertNotContains(true, array_column(IncidentPhases::compute([]), 'current'));
    }

    public function testNormalizeInput(): void
    {
        self::assertSame('2026-10-18 20:00:00', IncidentPhases::normalizeInput('2026-10-18T20:00'));
        self::assertSame('NULL', IncidentPhases::normalizeInput(''));
        self::assertNull(IncidentPhases::normalizeInput('pas une date'));
    }
}
