<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Compatibility;

use GlpiPlugin\Grcmanager\Compatibility\RequirementChecker;
use PHPUnit\Framework\TestCase;

final class RequirementCheckerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // PLUGIN_GRCMANAGER_MAX_GLPI: setup.php only declares constants/functions at load time
        // (same reasoning as SetupMenuRedefinitionTest).
        require_once __DIR__ . '/../../../setup.php';
    }

    private RequirementChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new RequirementChecker();
    }

    public function testPhpVersionAboveMinimumIsSupported(): void
    {
        self::assertTrue($this->checker->isPhpVersionSupported('8.2.10', '8.1.0'));
    }

    public function testPhpVersionEqualToMinimumIsSupported(): void
    {
        self::assertTrue($this->checker->isPhpVersionSupported('8.1.0', '8.1.0'));
    }

    public function testPhpVersionBelowMinimumIsNotSupported(): void
    {
        self::assertFalse($this->checker->isPhpVersionSupported('8.0.30', '8.1.0'));
    }

    public function testGlpiVersionWithinRangeIsSupported(): void
    {
        self::assertTrue($this->checker->isGlpiVersionSupported('11.0.2', '11.0.0', '11.99.99'));
    }

    public function testGlpiVersionBelowRangeIsNotSupported(): void
    {
        self::assertFalse($this->checker->isGlpiVersionSupported('10.0.9', '11.0.0', '11.99.99'));
    }

    public function testGlpiVersionAboveRangeIsNotSupported(): void
    {
        self::assertFalse($this->checker->isGlpiVersionSupported('12.0.0', '11.0.0', '11.99.99'));
    }

    public function testGlpiVersionAtUpperBoundIsSupported(): void
    {
        self::assertTrue($this->checker->isGlpiVersionSupported('11.99.99', '11.0.0', '11.99.99'));
    }

    public function testGlpi12PreReleaseIsSupportedWithTheGlpi12Ceiling(): void
    {
        self::assertTrue($this->checker->isGlpiVersionSupported('12.0.0-rc2', '11.0.0', PLUGIN_GRCMANAGER_MAX_GLPI));
        self::assertTrue($this->checker->isGlpiVersionSupported('11.0.9', '11.0.0', PLUGIN_GRCMANAGER_MAX_GLPI));
    }
}
