<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlAttributeCatalog;
use GlpiPlugin\Grcmanager\Services\Control\SoaScopingRules as Rules;
use GlpiPlugin\Grcmanager\Services\Control\SoaSelectionRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #873 : propositions du cadrage guidé de la SoA.
 */
final class SoaScopingRulesTest extends TestCase
{
    public function testQuestionsOnlyCiteAnnexAControls(): void
    {
        $known = array_keys(ControlAttributeCatalog::CONTROLS);
        self::assertCount(93, $known);
        foreach (Rules::QUESTIONS as $codes) {
            foreach ($codes as $code) {
                self::assertContains($code, $known);
            }
        }
        self::assertContains(Rules::EXCLUSION_CATEGORY, SoaSelectionRules::EXCLUSION_CATEGORIES);
    }

    public function testEveryControlGetsAProposalOnceAllQuestionsAreAnswered(): void
    {
        $answers = array_fill_keys(array_keys(Rules::QUESTIONS), Rules::ANSWER_NO);
        $excluded = 0;
        foreach (array_keys(ControlAttributeCatalog::CONTROLS) as $code) {
            $proposal = Rules::proposal($code, $answers);
            self::assertNotNull($proposal, $code);
            $excluded += $proposal['applicability'] === 'no' ? 1 : 0;
        }
        self::assertSame(array_sum(array_map('count', Rules::QUESTIONS)), $excluded);
    }

    public function testProposalFollowsTheAnswer(): void
    {
        self::assertSame(['applicability' => 'yes', 'basis' => 'baseline'], Rules::proposal('A.5.1', []));
        self::assertNull(Rules::proposal('A.6.7', []));
        self::assertSame(
            ['applicability' => 'yes', 'basis' => 'remote_work'],
            Rules::proposal('A.6.7', ['remote_work' => 'yes'])
        );
        self::assertSame(
            ['applicability' => 'no', 'basis' => 'remote_work'],
            Rules::proposal('A.6.7', ['remote_work' => 'no'])
        );
        self::assertNull(Rules::proposal('A.6.7', ['remote_work' => 'maybe']));
    }

    public function testStatus(): void
    {
        $no = ['applicability' => 'no', 'basis' => 'remote_work'];
        self::assertSame(Rules::STATUS_WAITING, Rules::status(null, 'yes', '', null));
        self::assertSame(Rules::STATUS_DIFFERS, Rules::status($no, 'yes', '', null));
        // Exclusion déjà posée mais sans justification : à relire.
        self::assertSame(Rules::STATUS_DIFFERS, Rules::status($no, 'no', '', null));
        self::assertSame(Rules::STATUS_MATCHES, Rules::status($no, 'no', 'Written', null));
        $accepted = ['decision' => 'accepted', 'proposed_applicability' => 'no'];
        self::assertSame(Rules::STATUS_ACCEPTED, Rules::status($no, 'no', 'Written', $accepted));
        // Mesure modifiée depuis sur sa fiche : la proposition redevient à relire.
        self::assertSame(Rules::STATUS_DIFFERS, Rules::status($no, 'yes', 'Written', $accepted));
        $rejected = ['decision' => 'rejected', 'proposed_applicability' => 'no'];
        self::assertSame(Rules::STATUS_REJECTED, Rules::status($no, 'yes', '', $rejected));
        // Nouvelle proposition (réponse changée) : la décision passée ne vaut plus.
        $yes = ['applicability' => 'yes', 'basis' => 'remote_work'];
        self::assertSame(Rules::STATUS_MATCHES, Rules::status($yes, 'yes', '', $rejected));
    }

    public function testChangesNeverOverwriteAWrittenJustification(): void
    {
        self::assertSame(
            ['applicability' => 'no', 'justification' => 'Proposed', 'exclusion_category' => 'activity_absent'],
            Rules::changes('no', 'Proposed', ['justification' => '', 'exclusion_category' => ''])
        );
        self::assertSame(
            ['applicability' => 'no'],
            Rules::changes('no', 'Proposed', ['justification' => 'Ours', 'exclusion_category' => 'out_of_scope'])
        );
        self::assertSame(
            ['applicability' => 'yes'],
            Rules::changes('yes', 'Proposed', ['justification' => 'Ours'])
        );
    }

    public function testCountByStatus(): void
    {
        $counts = Rules::countByStatus(['waiting', 'differs', 'differs', 'unknown']);
        self::assertSame(1, $counts['waiting']);
        self::assertSame(2, $counts['differs']);
        self::assertSame(0, $counts['accepted']);
    }
}
