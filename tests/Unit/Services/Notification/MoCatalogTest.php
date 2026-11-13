<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Notification;

use GlpiPlugin\Grcmanager\Services\Notification\MoCatalog;
use PHPUnit\Framework\TestCase;

/** Issue #582 : catalogues du plugin lus pour une langue donnée, sans changer la session. */
final class MoCatalogTest extends TestCase
{
    public function testTranslatesFromThePluginCatalogOfTheGivenLanguage(): void
    {
        self::assertSame('Evidenza da rinnovare', MoCatalog::translator('it_IT')('Evidence to renew'));
        self::assertSame('Preuve à renouveler', MoCatalog::translator('fr_FR')('Evidence to renew'));
        self::assertSame('Preuve à renouveler', MoCatalog::translator('fr_CA')('Evidence to renew'));
    }

    public function testFallsBackToTheMsgid(): void
    {
        self::assertSame('Evidence to renew', MoCatalog::translator('')('Evidence to renew'));
        self::assertSame('Evidence to renew', MoCatalog::translator('xx_XX')('Evidence to renew'));
        self::assertSame('Evidence to renew', MoCatalog::translator('../../etc/passwd')('Evidence to renew'));
        self::assertSame('Unknown msgid (test)', MoCatalog::translator('it_IT')('Unknown msgid (test)'));
    }

    public function testPluralEntriesAlsoTranslateTheirSingular(): void
    {
        self::assertSame('Rischio fornitore', MoCatalog::translator('it_IT')('Supplier risk'));
    }

    public function testInvalidDataGivesAnEmptyCatalog(): void
    {
        self::assertSame([], MoCatalog::parse(''));
        self::assertSame([], MoCatalog::parse(str_repeat('x', 64)));
    }
}
