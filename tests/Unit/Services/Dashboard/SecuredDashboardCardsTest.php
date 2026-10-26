<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Dashboard;

use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use PHPUnit\Framework\TestCase;

/**
 * Sans le droit de lecture de l'objet compté, une carte de tableau de bord garde sa forme (le
 * rendu ne casse pas) mais ne porte aucune donnée tirée des tables du plugin, quelle que soit la
 * forme du widget (revue de sécurité 2.11.0).
 */
final class SecuredDashboardCardsTest extends TestCase
{
    public function testBigNumberCardIsZeroedButKeepsItsPresentation(): void
    {
        $card = SecuredDashboardCards::zeroed([
            'number' => 42, 'label' => 'Risques ouverts', 'icon' => 'ti ti-alert', 'url' => '/risk.php?id=3',
        ]);

        self::assertEquals(['number' => 0, 'label' => 'Risques ouverts', 'icon' => 'ti ti-alert'], $card);
    }

    public function testListCardsLoseEveryEntryAndTheirLabels(): void
    {
        $card = SecuredDashboardCards::zeroed([
            'data'  => [
                ['label' => 'Sensibilisation phishing 2026', 'number' => 3],
                ['label' => 'Formation RGPD', 'number' => 7],
            ],
            'label' => 'Réussite par session',
        ]);

        self::assertSame([], $card['data'], 'Les titres des formations ne passent pas.');
        self::assertSame('Réussite par session', $card['label']);
    }

    public function testLineCardsLoseTheirSeries(): void
    {
        $card = SecuredDashboardCards::zeroed([
            'data'  => ['labels' => ['2026-09', '2026-10'], 'series' => [['name' => 'Risques', 'data' => [4, 5]]]],
            'label' => 'Risques et non-conformités par mois',
        ]);

        self::assertSame(['labels' => [], 'series' => []], $card['data']);
    }

    public function testFlatNumericSeriesAreEmptied(): void
    {
        self::assertSame(['data' => []], SecuredDashboardCards::zeroed(['data' => [5, 1, 9]]));
    }

    public function testUnknownKeysNeverPassThrough(): void
    {
        $card = SecuredDashboardCards::zeroed(['label' => 'Nouvelle carte', 'rows' => [['title' => 'Secret']]]);

        self::assertSame(['label' => 'Nouvelle carte'], $card);
    }
}
