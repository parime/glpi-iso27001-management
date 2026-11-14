<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Audit;

use GlpiPlugin\Grcmanager\Services\Audit\EvidenceRequestRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #538 : statuts, échéances, relances, avancement et périmètre de l'auditeur des demandes de
 * preuves d'un audit.
 */
final class EvidenceRequestRulesTest extends TestCase
{
    public function testOnlyTheAssigneeStepsAcceptPiecesAndSubmission(): void
    {
        self::assertTrue(Rules::canAddPiece(Rules::STATUS_REQUESTED));
        self::assertTrue(Rules::canAddPiece(Rules::STATUS_TO_COMPLETE));
        self::assertFalse(Rules::canAddPiece(Rules::STATUS_SUBMITTED), 'en revue');
        self::assertFalse(Rules::canAddPiece(Rules::STATUS_ACCEPTED));

        self::assertFalse(Rules::canSubmit(Rules::STATUS_REQUESTED, 0), 'sans pièce');
        self::assertTrue(Rules::canSubmit(Rules::STATUS_REQUESTED, 1));
        self::assertTrue(Rules::canSubmit(Rules::STATUS_TO_COMPLETE, 2), 'nouvelle soumission');
        self::assertFalse(Rules::canSubmit(Rules::STATUS_SUBMITTED, 1), 'déjà soumise');
        self::assertFalse(Rules::canSubmit(Rules::STATUS_ACCEPTED, 1));
        self::assertFalse(Rules::isStatus('closed'));
    }

    public function testReviewAcceptsOrAsksToCompleteWithAReason(): void
    {
        self::assertSame(Rules::STATUS_ACCEPTED, Rules::statusAfterReview(Rules::STATUS_SUBMITTED, 'accept', ''));
        self::assertSame(
            Rules::STATUS_TO_COMPLETE,
            Rules::statusAfterReview(Rules::STATUS_SUBMITTED, 'to_complete', 'Extrait signé manquant')
        );
        self::assertNull(Rules::statusAfterReview(Rules::STATUS_SUBMITTED, 'to_complete', '   '), 'motif obligatoire');
        self::assertNull(Rules::statusAfterReview(Rules::STATUS_REQUESTED, 'accept', ''), 'rien à revoir');
        self::assertNull(Rules::statusAfterReview(Rules::STATUS_ACCEPTED, 'to_complete', 'x'), 'déjà acceptée');
        self::assertNull(Rules::statusAfterReview(Rules::STATUS_SUBMITTED, 'close', 'x'), 'décision inconnue');
    }

    public function testOverdueOnlyWhileWaitingForTheAssignee(): void
    {
        self::assertFalse(Rules::isOverdue(Rules::STATUS_REQUESTED, '2026-11-10', '2026-11-10'), 'échéance incluse');
        self::assertTrue(Rules::isOverdue(Rules::STATUS_REQUESTED, '2026-11-10', '2026-11-11'));
        self::assertTrue(Rules::isOverdue(Rules::STATUS_TO_COMPLETE, '2026-11-10', '2026-11-20'));
        self::assertFalse(Rules::isOverdue(Rules::STATUS_SUBMITTED, '2026-11-10', '2026-11-20'), 'en revue');
        self::assertFalse(Rules::isOverdue(Rules::STATUS_REQUESTED, null, '2026-11-20'), 'sans échéance');
        self::assertSame(3, Rules::daysLate(Rules::STATUS_REQUESTED, '2026-11-10', '2026-11-13'));
        self::assertSame(0, Rules::daysLate(Rules::STATUS_ACCEPTED, '2026-11-10', '2026-11-13'));
    }

    public function testRemindersBeforeTheDeadlineThenWeekly(): void
    {
        $due = '2026-11-20';
        self::assertFalse(Rules::isReminderDue(Rules::STATUS_REQUESTED, $due, null, '2026-11-12'), 'trop tôt');
        self::assertTrue(Rules::isReminderDue(Rules::STATUS_REQUESTED, $due, null, '2026-11-13'), 'J-7');
        self::assertFalse(
            Rules::isReminderDue(Rules::STATUS_REQUESTED, $due, '2026-11-13 08:00:00', '2026-11-18'),
            'une seule relance avant l\'échéance'
        );
        self::assertTrue(
            Rules::isReminderDue(Rules::STATUS_REQUESTED, $due, '2026-11-13 08:00:00', '2026-11-21'),
            'lendemain de l\'échéance'
        );
        self::assertFalse(
            Rules::isReminderDue(Rules::STATUS_REQUESTED, $due, '2026-11-21 08:00:00', '2026-11-25'),
            'au plus une par semaine'
        );
        self::assertTrue(Rules::isReminderDue(Rules::STATUS_TO_COMPLETE, $due, '2026-11-21 08:00:00', '2026-11-28'));
        self::assertFalse(Rules::isReminderDue(Rules::STATUS_SUBMITTED, $due, null, '2026-11-28'), 'en revue');
        self::assertFalse(Rules::isReminderDue(Rules::STATUS_ACCEPTED, $due, null, '2026-11-28'));
        self::assertFalse(Rules::isReminderDue(Rules::STATUS_REQUESTED, null, null, '2026-11-28'), 'sans échéance');
    }

    public function testProgressCountsAcceptedRequests(): void
    {
        $progress = Rules::progress([
            Rules::STATUS_ACCEPTED, Rules::STATUS_SUBMITTED, Rules::STATUS_TO_COMPLETE, Rules::STATUS_REQUESTED,
        ]);
        self::assertSame(4, $progress['total']);
        self::assertSame(25, $progress['percent']);
        self::assertSame(50, $progress['provided_percent'], 'acceptées et soumises');
        self::assertSame('orange', $progress['color']);
        self::assertSame('green', Rules::progress([Rules::STATUS_ACCEPTED])['color']);
        self::assertSame(['total' => 0, 'percent' => 0, 'color' => 'secondary'], array_intersect_key(
            Rules::progress([]),
            ['total' => 0, 'percent' => 0, 'color' => '']
        ));
    }

    public function testTheAuditorSeesOnlySubmittedRequestsAndPieces(): void
    {
        self::assertFalse(Rules::isVisibleToAuditor(Rules::STATUS_REQUESTED, null), 'jamais soumise');
        self::assertTrue(Rules::isVisibleToAuditor(Rules::STATUS_SUBMITTED, '2026-11-10 10:00:00'));
        self::assertTrue(Rules::isVisibleToAuditor(Rules::STATUS_TO_COMPLETE, '2026-11-10 10:00:00'));
        self::assertTrue(Rules::isVisibleToAuditor(Rules::STATUS_ACCEPTED, '2026-11-10 10:00:00'));
        self::assertFalse(Rules::isPieceVisibleToAuditor(false), 'pièce en préparation');
        self::assertTrue(Rules::isPieceVisibleToAuditor(true));
    }

    public function testAuditorAccessIsLimitedInTime(): void
    {
        self::assertTrue(Rules::isAccessActive('2026-11-01', '2026-11-30', null, '2026-11-30'), 'dernier jour inclus');
        self::assertFalse(Rules::isAccessActive('2026-11-01', '2026-11-30', null, '2026-12-01'), 'terminé');
        self::assertFalse(Rules::isAccessActive('2026-11-20', '2026-11-30', null, '2026-11-19'), 'pas commencé');
        self::assertFalse(
            Rules::isAccessActive('2026-11-01', '2026-11-30', '2026-11-15 10:00:00', '2026-11-16'),
            'révoqué'
        );
        self::assertFalse(Rules::isAccessActive(null, '2026-11-30', null, '2026-11-16'));

        self::assertSame('2026-12-14', Rules::defaultAccessEnd('2026-11-14'));
        self::assertSame('2026-11-20', Rules::accessEnd('2026-11-20', '2026-11-14'));
        self::assertSame('2026-11-20', Rules::accessEnd('20/11/2026', '2026-11-14'), 'format d\'un tableur');
        self::assertNull(Rules::accessEnd('2026-11-13', '2026-11-14'), 'avant le début');
        self::assertNull(Rules::accessEnd('2028-01-01', '2026-11-14'), 'plus d\'un an');
        self::assertNull(Rules::accessEnd('2026-02-30', '2026-01-01'), 'date impossible');
        self::assertNull(Rules::accessEnd('', '2026-11-14'));
    }

    public function testReferenceIsTrimmedAndBounded(): void
    {
        self::assertSame('A.5.1 politique', Rules::reference("  A.5.1 \n politique "));
        self::assertSame(Rules::MAX_REFERENCE_LENGTH, mb_strlen(Rules::reference(str_repeat('é', 400))));
        self::assertSame('', Rules::reference(['x']));
    }
}
