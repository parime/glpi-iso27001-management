<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Feature;

use GlpiPlugin\Grcmanager\Feature\ControlEvidenceProvider;
use GlpiPlugin\Grcmanager\Feature\DashboardCardsProvider;
use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Feature\MfaCoverageFeature;
use GlpiPlugin\Grcmanager\Feature\RiskIndicatorSourcesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fonctionnalités découvertes (src/Feature/*Feature.php) : ordre stable et place demandée (« after »).
 */
final class FeatureRegistryTest extends TestCase
{
    public function testDiscoversTheFeaturesByInterface(): void
    {
        foreach ([DashboardCardsProvider::class, RiskIndicatorSourcesProvider::class, ControlEvidenceProvider::class] as $i) {
            $found = FeatureRegistry::implementing($i);
            $this->assertContains(MfaCoverageFeature::class, $found, $i);
            $sorted = $found;
            sort($sorted, SORT_STRING);
            $this->assertSame($sorted, $found, 'ordre alphabétique stable');
        }
    }

    public function testInsertAfterKeepsTheRequestedPlace(): void
    {
        $list = ['a' => ['n' => 1], 'b' => ['n' => 2], 'c' => ['n' => 3]];
        $result = FeatureRegistry::insertAfter($list, [
            'x' => ['n' => 9, 'after' => 'a'],
            'y' => ['n' => 8],
            'z' => ['n' => 7, 'after' => 'absent'],
            'w' => ['n' => 6, 'after' => 'x'],
        ]);
        $this->assertSame(['a', 'x', 'w', 'b', 'c', 'y', 'z'], array_keys($result));
        $this->assertSame(['n' => 9], $result['x'], 'la clé « after » est retirée');
    }
}
