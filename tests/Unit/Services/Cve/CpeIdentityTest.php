<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Cve;

use GlpiPlugin\Grcmanager\Services\Cve\CpeIdentity;
use PHPUnit\Framework\TestCase;

final class CpeIdentityTest extends TestCase
{
    public function testReducesARealCpeToItsFiveFieldPrefix(): void
    {
        self::assertSame(
            'cpe:2.3:a:apache:log4j',
            CpeIdentity::prefixOf('cpe:2.3:a:apache:log4j:*:*:*:*:*:*:*:*')
        );
    }

    public function testTwoDifferentVersionsOfTheSameProductReduceToTheSamePrefix(): void
    {
        $prefixA = CpeIdentity::prefixOf('cpe:2.3:a:apache:log4j:2.0:beta9:*:*:*:*:*:*');
        $prefixB = CpeIdentity::prefixOf('cpe:2.3:a:apache:log4j:2.17.0:*:*:*:*:*:*:*');

        self::assertSame($prefixA, $prefixB);
        self::assertSame('cpe:2.3:a:apache:log4j', $prefixA);
    }

    /**
     * The exact defect documented (and left unfixed) in the sibling plugin
     * glpi-vulnerability-manager's own CpeIdentity: a naive explode(':') would split
     * "product\:name" into two fields instead of keeping the escaped colon as part of the product
     * component, silently producing a wrong (and too-short) prefix.
     */
    public function testAnEscapedColonInsideAComponentIsNotTreatedAsAFieldSeparator(): void
    {
        $prefix = CpeIdentity::prefixOf('cpe:2.3:a:vendor:product\\:name:1.0:*:*:*:*:*:*');

        self::assertSame('cpe:2.3:a:vendor:product\\:name', $prefix);
    }

    public function testMalformedInputWithFewerThanFiveFieldsIsReturnedUnchanged(): void
    {
        self::assertSame('cpe:2.3:a', CpeIdentity::prefixOf('cpe:2.3:a'));
    }
}
