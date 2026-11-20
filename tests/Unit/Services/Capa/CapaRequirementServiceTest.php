<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Capa;

use GlpiPlugin\Grcmanager\Services\Capa\CapaRequirementService;
use PHPUnit\Framework\TestCase;

final class CapaRequirementServiceTest extends TestCase
{
    public function testCapaIsMandatoryForANonconformity(): void
    {
        self::assertTrue(CapaRequirementService::isCapaMandatory('nonconformity'));
    }

    public function testCapaIsOptionalForAnObservation(): void
    {
        self::assertFalse(CapaRequirementService::isCapaMandatory('observation'));
    }

    /** Issue #772 : point sensible et piste de progrès de l'organisme de certification. */
    public function testCapaIsOptionalForAnAreaOfConcernOrAnOpportunityForImprovement(): void
    {
        self::assertFalse(CapaRequirementService::isCapaMandatory('sensitive_point'));
        self::assertFalse(CapaRequirementService::isCapaMandatory('improvement'));
        self::assertTrue(CapaRequirementService::isCapaMandatory('nonconformity'));
    }

    public function testUnknownOrBlankFindingTypeDefaultsToMandatory(): void
    {
        self::assertTrue(CapaRequirementService::isCapaMandatory(''));
        self::assertTrue(CapaRequirementService::isCapaMandatory('anything-else'));
    }
}
