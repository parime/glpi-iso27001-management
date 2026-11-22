<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\ContextualSeverityRules as Context;
use GlpiPlugin\Grcmanager\Services\Cve\RemediationRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #887 : niveau d'une vulnérabilité ajusté à l'environnement et à l'exposition des machines.
 */
final class ContextualSeverityRulesTest extends TestCase
{
    public function testSanitizeKeepsOnlyKnownValues(): void
    {
        $this->assertSame('test', Context::sanitizeEnvironment('test'));
        $this->assertSame('', Context::sanitizeEnvironment('<script>'));
        $this->assertSame('', Context::sanitizeEnvironment(['production']));
        $this->assertSame('internet', Context::sanitizeExposure('internet'));
        $this->assertSame(Context::EXPOSURE_AUTO, Context::sanitizeExposure('dmz'));
    }

    public function testDeclaredExposureWinsOverTheFlowMatrix(): void
    {
        $this->assertTrue(Context::isExposed(Context::EXPOSURE_AUTO, true));
        $this->assertFalse(Context::isExposed(Context::EXPOSURE_AUTO, false));
        $this->assertTrue(Context::isExposed(Context::EXPOSURE_INTERNET, false));
        $this->assertFalse(Context::isExposed(Context::EXPOSURE_INTERNAL, true));
    }

    /**
     * @return iterable<string, array{string, string, bool, string}>
     */
    public static function machineCases(): iterable
    {
        yield 'production inchangée' => [Rules::HIGH, 'production', false, Rules::HIGH];
        yield 'non renseigné comme la production' => [Rules::MEDIUM, '', false, Rules::MEDIUM];
        yield 'test abaissé' => [Rules::HIGH, 'test', false, Rules::MEDIUM];
        yield 'développement abaissé' => [Rules::CRITICAL, 'development', false, Rules::HIGH];
        yield 'faible ne descend pas plus bas' => [Rules::LOW, 'test', false, Rules::LOW];
        yield 'exposée relevée' => [Rules::MEDIUM, 'production', true, Rules::HIGH];
        yield 'exposée en test relevée quand même' => [Rules::LOW, 'test', true, Rules::MEDIUM];
        yield 'critique ne devient pas KEV' => [Rules::CRITICAL, 'production', true, Rules::CRITICAL];
        yield 'KEV jamais abaissée' => [Rules::KEV, 'development', false, Rules::KEV];
    }

    #[DataProvider('machineCases')]
    public function testMachineLevel(string $base, string $environment, bool $exposed, string $expected): void
    {
        $this->assertSame($expected, Context::machineLevel($base, $environment, $exposed));
    }

    public function testTheMostUrgentMachineDecides(): void
    {
        $this->assertSame(
            ['level' => Rules::HIGH, 'reason' => Context::REASON_NONE],
            Context::adjust(Rules::HIGH, [
                ['environment' => 'test', 'exposed' => false],
                ['environment' => 'production', 'exposed' => false],
            ]),
            'Une machine de production suffit à garder le niveau.'
        );
        $this->assertSame(
            ['level' => Rules::LOW, 'reason' => Context::REASON_NON_PRODUCTION],
            Context::adjust(Rules::MEDIUM, [
                ['environment' => 'test', 'exposed' => false],
                ['environment' => 'development', 'exposed' => false],
            ])
        );
        $this->assertSame(
            ['level' => Rules::CRITICAL, 'reason' => Context::REASON_EXPOSED],
            Context::adjust(Rules::HIGH, [
                ['environment' => 'test', 'exposed' => false],
                ['environment' => 'production', 'exposed' => true],
            ])
        );
        $this->assertSame(
            ['level' => Rules::HIGH, 'reason' => Context::REASON_NONE],
            Context::adjust(Rules::HIGH, []),
            'Sans machine connue, le niveau de départ reste.'
        );
    }

    public function testReasonComparesBothLevels(): void
    {
        $this->assertSame(Context::REASON_EXPOSED, Context::reason(Rules::MEDIUM, Rules::HIGH));
        $this->assertSame(Context::REASON_NON_PRODUCTION, Context::reason(Rules::HIGH, Rules::MEDIUM));
        $this->assertSame(Context::REASON_NONE, Context::reason(Rules::KEV, Rules::KEV));
    }
}
