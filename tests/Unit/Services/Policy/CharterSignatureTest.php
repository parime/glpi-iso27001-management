<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Policy;

use GlpiPlugin\Grcmanager\Services\Policy\CharterSignature;
use PHPUnit\Framework\TestCase;

/**
 * Issue #283 : une nouvelle version approuvée de la charte appelle une nouvelle campagne.
 */
final class CharterSignatureTest extends TestCase
{
    public function testANewApprovedVersionCallsForANewCampaign(): void
    {
        self::assertTrue(CharterSignature::needsNewCampaign('approved', '2026-10-20', '2026-03-01 09:00:00'));
        self::assertTrue(CharterSignature::needsNewCampaign('approved', '2026-10-20', null), 'Aucune campagne.');
        self::assertFalse(CharterSignature::needsNewCampaign('approved', '2026-03-01', '2026-03-01 09:00:00'));
        self::assertFalse(CharterSignature::needsNewCampaign('approved', '2026-02-01', '2026-03-01 09:00:00'));
        self::assertFalse(CharterSignature::needsNewCampaign('draft', '2026-10-20', null), 'Brouillon.');
        self::assertFalse(CharterSignature::needsNewCampaign('approved', null, null), 'Sans date d\'approbation.');
    }
}
