<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\EvidenceSeal;
use PHPUnit\Framework\TestCase;

final class EvidenceSealTest extends TestCase
{
    public function testSealAndCheck(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'grc');
        self::assertIsString($path);
        file_put_contents($path, 'journal pare-feu');

        $seal = EvidenceSeal::seal($path);
        self::assertSame(['sha256' => hash('sha256', 'journal pare-feu'), 'size' => 16], $seal);
        self::assertSame(EvidenceSeal::OK, EvidenceSeal::check($path, strtoupper($seal['sha256'])));

        file_put_contents($path, 'journal pare-feu modifié');
        self::assertSame(EvidenceSeal::MODIFIED, EvidenceSeal::check($path, $seal['sha256']));

        unlink($path);
        self::assertSame(EvidenceSeal::MISSING, EvidenceSeal::check($path, $seal['sha256']));
        self::assertNull(EvidenceSeal::seal($path));
    }
}
