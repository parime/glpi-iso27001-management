<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\ApprovedApplication;

use GlpiPlugin\Grcmanager\Services\ApprovedApplication\ApprovedApplicationRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #622 : applications et services en ligne approuvés (A.5.23). Données fictives.
 */
final class ApprovedApplicationRulesTest extends TestCase
{
    public function testSanitizersKeepOnlyKnownValues(): void
    {
        self::assertSame(R::STATUS_RESTRICTED, R::sanitizeStatus('restricted'));
        self::assertSame(R::STATUS_EVALUATION, R::sanitizeStatus('<script>'));
        self::assertSame(R::STATUS_EVALUATION, R::sanitizeStatus(['approved']));
        self::assertSame(R::HOSTING_ONLINE, R::sanitizeHosting('online'));
        self::assertSame(R::HOSTING_UNKNOWN, R::sanitizeHosting('mars'));
        self::assertSame('medium', R::sanitizeLevel('medium'));
        self::assertSame('', R::sanitizeLevel('critical'));
        self::assertSame(12, R::sanitizeMonths(''));
        self::assertSame(12, R::sanitizeMonths('douze'));
        self::assertSame(0, R::sanitizeMonths('0'));
        self::assertSame(60, R::sanitizeMonths('600'));
        self::assertSame(12, R::sanitizeMonths('-3'), 'Valeur négative refusée : cycle par défaut.');
        self::assertSame('2026-02-28', R::sanitizeDate('2026-02-28'));
        self::assertNull(R::sanitizeDate('2026-02-30'));
        self::assertNull(R::sanitizeDate('demain'));
        self::assertSame([3, 7], R::sanitizeIds(['3', '7', '3', '0', '-2', 'x', 7]));
        self::assertSame([3, 7], R::decodeIds(R::encodeIds([3, 7])));
        self::assertSame([], R::decodeIds('{"a":"<b>"}'));
        self::assertSame('', R::encodeIds([]));
    }

    public function testNextReviewStartsFromLastReviewThenStartOfUse(): void
    {
        $today = '2026-06-15';
        $sheet = ['status' => R::STATUS_APPROVED, 'review_months' => 12, 'date_start' => '2025-01-10'];
        self::assertSame('2026-01-10', R::nextReview($sheet, $today));
        self::assertSame(R::REVIEW_OVERDUE, R::reviewState(R::nextReview($sheet, $today), $today));

        $sheet['last_review_date'] = '2026-06-01';
        self::assertSame('2027-06-01', R::nextReview($sheet, $today));
        self::assertSame(R::REVIEW_OK, R::reviewState('2027-06-01', $today));
        self::assertSame(R::REVIEW_SOON, R::reviewState('2026-07-01', $today));

        self::assertNull(R::nextReview(['review_months' => 0] + $sheet, $today), 'Cycle 0 : aucune revue.');
        self::assertNull(R::nextReview(['status' => R::STATUS_REFUSED] + $sheet, $today), 'Refusée : aucune revue.');
        self::assertNull(R::nextReview(['date_end' => '2026-05-01'] + $sheet, $today), 'Usage terminé.');
        self::assertSame(R::REVIEW_NONE, R::reviewState(null, $today));
        self::assertSame($today, R::nextReview(['status' => R::STATUS_EVALUATION, 'review_months' => 6], $today));
        self::assertSame('2026-02-28', R::addMonths('2026-01-31', 1));
        self::assertSame('2027-03-31', R::addMonths('2025-12-31', 15));
    }

    public function testPublishedListKeepsOnlyApprovedApplicationsInUse(): void
    {
        $today = '2026-06-15';
        self::assertTrue(R::isPublished(R::STATUS_APPROVED, null, null, $today));
        self::assertTrue(R::isPublished(R::STATUS_RESTRICTED, '2026-01-01', '2026-12-31', $today));
        self::assertFalse(R::isPublished(R::STATUS_EVALUATION, null, null, $today));
        self::assertFalse(R::isPublished(R::STATUS_REFUSED, null, null, $today));
        self::assertFalse(R::isPublished(R::STATUS_APPROVED, '2026-07-01', null, $today), 'Pas encore commencé.');
        self::assertFalse(R::isPublished(R::STATUS_APPROVED, null, '2026-06-14', $today), 'Usage terminé.');
        self::assertTrue(R::isPublished(R::STATUS_APPROVED, null, '2026-06-15', $today), 'Dernier jour d\'usage.');
    }

    public function testGapsAndDataLevel(): void
    {
        $sheet = [
            'status' => R::STATUS_RESTRICTED, 'users_id_owner' => 0, 'allowed_uses' => ' ', 'max_data_level' => '',
        ];
        self::assertSame(
            [R::GAP_NO_OWNER, R::GAP_NO_USES, R::GAP_NO_LEVEL, R::GAP_NO_RESTRICTIONS],
            R::gaps($sheet)
        );
        self::assertSame([], R::gaps([
            'status' => R::STATUS_APPROVED, 'users_id_owner' => 4, 'allowed_uses' => 'Partage de fichiers',
            'max_data_level' => 'low',
        ]));
        self::assertSame([], R::gaps(['status' => R::STATUS_REFUSED]), 'Une application refusée n\'a pas d\'écart.');
        self::assertTrue(R::levelExceeded('low', 'high'));
        self::assertFalse(R::levelExceeded('high', 'medium'));
        self::assertFalse(R::levelExceeded('', 'high'), 'Niveau non renseigné : pas de comparaison.');
        self::assertFalse(R::levelExceeded('low', ''));
    }

    public function testInventoryNameMatching(): void
    {
        self::assertTrue(R::nameMatches('Dropbox 200.4.5', 'Dropbox'));
        self::assertTrue(R::nameMatches('PARTAGE-Éclair', 'partage eclair'));
        self::assertFalse(R::nameMatches('Dropboxer', 'Dropbox'), 'Nom plus long sans séparateur.');
        self::assertFalse(R::nameMatches('Go tools', 'Go'), 'Nom trop court pour un rapprochement.');
        self::assertFalse(R::nameMatches('', 'Dropbox'));
    }

    public function testIndicators(): void
    {
        $today = '2026-06-15';
        $counts = R::indicators([
            ['status' => R::STATUS_APPROVED, 'hosting' => R::HOSTING_ONLINE, 'review_months' => 12,
                'last_review_date' => '2025-01-01', 'users_id_owner' => 2, 'allowed_uses' => 'x',
                'max_data_level' => 'low'],
            ['status' => R::STATUS_RESTRICTED, 'hosting' => R::HOSTING_ONLINE, 'review_months' => 12,
                'last_review_date' => '2025-07-01', 'users_id_owner' => 0, 'allowed_uses' => '',
                'max_data_level' => ''],
            ['status' => R::STATUS_REFUSED, 'hosting' => R::HOSTING_ONPREMISE, 'review_months' => 12],
            ['status' => 'bogus', 'review_months' => 0],
        ], $today);
        self::assertSame(4, $counts['total']);
        self::assertSame(1, $counts[R::STATUS_APPROVED]);
        self::assertSame(1, $counts[R::STATUS_RESTRICTED]);
        self::assertSame(1, $counts[R::STATUS_REFUSED]);
        self::assertSame(1, $counts[R::STATUS_EVALUATION]);
        self::assertSame(2, $counts['online']);
        self::assertSame(1, $counts['review_overdue']);
        self::assertSame(1, $counts['review_soon']);
        self::assertSame(1, $counts['with_gaps']);
    }
}
