<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Evidence;

use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceValidity;
use PHPUnit\Framework\TestCase;

final class EvidenceValidityTest extends TestCase
{
    public function testStatusAroundTheWarningWindowAndTheExpiryDate(): void
    {
        $status = static fn (?string $validUntil): string => EvidenceValidity::status($validUntil, '2026-10-23');
        self::assertSame(EvidenceValidity::STATUS_NONE, $status(null));
        self::assertSame(EvidenceValidity::STATUS_VALID, $status('2026-12-31'));
        self::assertSame(EvidenceValidity::STATUS_EXPIRING, $status('2026-11-22'), '30 jours avant.');
        self::assertSame(EvidenceValidity::STATUS_VALID, $status('2026-11-23'), '31 jours avant.');
        self::assertSame(EvidenceValidity::STATUS_EXPIRING, $status('2026-10-23'), 'Valide le jour même.');
        self::assertSame(EvidenceValidity::STATUS_EXPIRED, $status('2026-10-22'));
    }

    public function testTheResponsibleIsWarnedOnceBeforeExpiry(): void
    {
        $today = '2026-10-23';
        self::assertTrue(EvidenceValidity::reminderDue('2026-11-10', null, $today));
        self::assertFalse(EvidenceValidity::reminderDue('2026-11-10', '2026-10-20 08:00:00', $today), 'Déjà prévenu.');
        self::assertFalse(EvidenceValidity::reminderDue('2027-06-01', null, $today), 'Trop tôt.');
        self::assertFalse(EvidenceValidity::reminderDue('2026-10-01', null, $today), 'Expirée : plus de rappel.');
    }

    public function testOneRenewalTicketOnceExpired(): void
    {
        self::assertTrue(EvidenceValidity::ticketDue('2026-10-22', 0, '2026-10-23'));
        self::assertFalse(EvidenceValidity::ticketDue('2026-10-22', 12, '2026-10-23'), 'Ticket déjà ouvert.');
        self::assertFalse(EvidenceValidity::ticketDue('2026-10-23', 0, '2026-10-23'), 'Encore valide ce jour.');
        self::assertFalse(EvidenceValidity::ticketDue(null, 0, '2026-10-23'), 'Sans date, rien à renouveler.');
    }

    public function testSupportedItemtypes(): void
    {
        self::assertTrue(EvidenceValidity::isSupportedItemtype('GlpiPlugin\\Grcmanager\\Control'));
        self::assertTrue(EvidenceValidity::isSupportedItemtype('GlpiPlugin\\Grcmanager\\Policy'));
        self::assertFalse(EvidenceValidity::isSupportedItemtype('Computer'));
    }
}
