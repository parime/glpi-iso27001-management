<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Dashboard;

use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use PHPUnit\Framework\TestCase;

/**
 * Sans le droit de lecture de l'objet compté, une carte de tableau de bord garde sa forme (le
 * rendu ne casse pas) mais ne révèle aucun volume.
 */
final class SecuredDashboardCardsTest extends TestCase
{
    public function testBigNumberCardIsZeroedButKeepsItsLabel(): void
    {
        $card = SecuredDashboardCards::zeroed(['number' => 42, 'label' => 'Risques ouverts', 'icon' => 'ti ti-alert']);

        self::assertSame(['number' => 0, 'label' => 'Risques ouverts', 'icon' => 'ti ti-alert'], $card);
    }

    public function testMultipleNumberAndPieCardsAreZeroedEntryByEntry(): void
    {
        $card = SecuredDashboardCards::zeroed([
            'data'  => [
                ['label' => 'Critique', 'number' => 3],
                ['label' => 'Majeur', 'number' => 7],
            ],
            'label' => 'Incidents par sévérité',
        ]);

        self::assertSame([['label' => 'Critique', 'number' => 0], ['label' => 'Majeur', 'number' => 0]], $card['data']);
        self::assertSame('Incidents par sévérité', $card['label']);
    }

    public function testFlatNumericSeriesAreZeroed(): void
    {
        self::assertSame(['data' => [0, 0, 0]], SecuredDashboardCards::zeroed(['data' => [5, 1, 9]]));
    }
}
