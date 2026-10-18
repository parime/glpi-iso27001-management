<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use PHPUnit\Framework\TestCase;

final class AccessReviewLogicTest extends TestCase
{
    public function testTheSupervisorReviewsFirst(): void
    {
        $reviewer = AccessReviewLogic::reviewerFor(10, 20, [['groups_id' => 3, 'users_id' => 30]], 1);

        $this->assertSame(['users_id' => 20, 'groups_id' => 0, 'source' => 'supervisor'], $reviewer);
    }

    public function testTheGroupManagerReviewsWithoutSupervisorButNeverTheAccountItself(): void
    {
        $reviewer = AccessReviewLogic::reviewerFor(10, 10, [
            ['groups_id' => 3, 'users_id' => 10],
            ['groups_id' => 4, 'users_id' => 40],
        ], 1);

        $this->assertSame(['users_id' => 40, 'groups_id' => 4, 'source' => 'group'], $reviewer);
    }

    public function testTheCampaignOwnerReviewsAccountsWithoutManager(): void
    {
        $this->assertSame(
            ['users_id' => 1, 'groups_id' => 0, 'source' => 'owner'],
            AccessReviewLogic::reviewerFor(10, 0, [], 1)
        );
    }

    public function testOnlyModifyAndRemoveOpenATicket(): void
    {
        $this->assertTrue(AccessReviewLogic::needsTicket('modify'));
        $this->assertTrue(AccessReviewLogic::needsTicket('remove'));
        $this->assertFalse(AccessReviewLogic::needsTicket('keep'));
        $this->assertFalse(AccessReviewLogic::isDecision('pending'));
        $this->assertFalse(AccessReviewLogic::isDecision('delete'));
    }

    public function testNextStartDateFollowsTheFrequency(): void
    {
        $this->assertSame('2027-01-15', AccessReviewLogic::nextStartDate('2026-10-15', 'quarterly'));
        $this->assertSame('2027-04-15', AccessReviewLogic::nextStartDate('2026-10-15', 'semiannual'));
        $this->assertSame('2027-10-15', AccessReviewLogic::nextStartDate('2026-10-15', 'unknown'));
    }

    public function testProgress(): void
    {
        $this->assertSame(
            ['total' => 3, 'done' => 2, 'percent' => 66],
            AccessReviewLogic::progress(['keep', 'pending', 'remove'])
        );
        $this->assertSame(['total' => 0, 'done' => 0, 'percent' => 100], AccessReviewLogic::progress([]));
    }

    public function testRemindersStartAfterTheDueDateThenEveryWeek(): void
    {
        $this->assertFalse(AccessReviewLogic::isReminderDue('2026-10-18', null, '2026-10-18'));
        $this->assertTrue(AccessReviewLogic::isReminderDue('2026-10-18', null, '2026-10-19'));
        $this->assertFalse(AccessReviewLogic::isReminderDue('2026-10-18', '2026-10-19 08:00:00', '2026-10-25'));
        $this->assertTrue(AccessReviewLogic::isReminderDue('2026-10-18', '2026-10-19 08:00:00', '2026-10-26'));
        $this->assertFalse(AccessReviewLogic::isReminderDue(null, null, '2026-10-26'));
    }
}
