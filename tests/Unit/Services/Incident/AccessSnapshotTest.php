<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\AccessSnapshot;
use PHPUnit\Framework\TestCase;

final class AccessSnapshotTest extends TestCase
{
    public function testDetectsRightAndAssignmentChanges(): void
    {
        $before = ['profiles' => [4 => 1023, 6 => 2, 7 => 6], 'users' => ['10:6:0', '11:7:0']];
        $after = ['profiles' => [4 => 1023, 6 => 6, 7 => 2, 8 => 1], 'users' => ['10:6:0', '12:6:1']];

        $types = array_column(AccessSnapshot::diff($before, $after), 'type');
        self::assertSame(
            ['right_extended', 'right_reduced', 'right_granted', 'profile_assigned', 'profile_unassigned'],
            $types
        );
        $changes = AccessSnapshot::diff($before, $after);
        self::assertSame(12, $changes[3]['users_id']);
        self::assertSame(1, $changes[3]['entities_id']);
        self::assertTrue(AccessSnapshot::widensAccess($changes));
    }

    public function testRemovalOnlyDoesNotWidenAccess(): void
    {
        $changes = AccessSnapshot::diff(
            ['profiles' => [6 => 2], 'users' => ['10:6:0']],
            ['profiles' => [], 'users' => []]
        );
        self::assertSame(['right_removed', 'profile_unassigned'], array_column($changes, 'type'));
        self::assertFalse(AccessSnapshot::widensAccess($changes));
        self::assertSame([], AccessSnapshot::diff(['profiles' => [6 => 2]], ['profiles' => [6 => 2]]));
    }
}
