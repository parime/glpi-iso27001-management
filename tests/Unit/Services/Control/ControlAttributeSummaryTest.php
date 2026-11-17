<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlAttributeSummary;
use PHPUnit\Framework\TestCase;

/**
 * Issue #610 : répartition de la SoA par valeur d'attribut, sur des données fictives.
 */
final class ControlAttributeSummaryTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function row(string $applicability, string $status, string $types): array
    {
        return ['applicability' => $applicability, 'implementation_status' => $status, 'control_types' => $types];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function controls(): array
    {
        return [
            self::row('yes', 'implemented', 'preventive'),
            self::row('yes', 'not_started', 'preventive,detective'),
            self::row('partial', 'in_progress', 'detective'),
            self::row('no', 'not_started', 'corrective'),
            self::row('yes', 'verified', 'detective,corrective'),
        ];
    }

    public function testOneLinePerValueInCatalogueOrder(): void
    {
        $lines = ControlAttributeSummary::byValue(self::controls(), 'control_types');

        self::assertSame(['preventive', 'detective', 'corrective'], array_column($lines, 'value'));
    }

    public function testCountsAndRates(): void
    {
        $lines = array_column(ControlAttributeSummary::byValue(self::controls(), 'control_types'), null, 'value');

        // Préventive : 2 applicables, 1 mise en œuvre, 1 non démarrée.
        self::assertSame(2, $lines['preventive']['total']);
        self::assertSame(2, $lines['preventive']['applicable']);
        self::assertSame(1, $lines['preventive']['implemented']);
        self::assertSame(50.0, $lines['preventive']['rate']);

        // Détective : 3 applicables (dont une partielle), 1 vérifiée, 1 en cours (compte pour moitié).
        self::assertSame(3, $lines['detective']['applicable']);
        self::assertSame(1, $lines['detective']['implemented']);
        self::assertSame(1, $lines['detective']['in_progress']);
        self::assertSame(50.0, $lines['detective']['rate']);

        // Corrective : la mesure non applicable est écartée du taux, mais comptée au total.
        self::assertSame(2, $lines['corrective']['total']);
        self::assertSame(1, $lines['corrective']['applicable']);
        self::assertSame(100.0, $lines['corrective']['rate']);
    }

    public function testValueWithoutApplicableControlHasNoRate(): void
    {
        $lines = array_column(ControlAttributeSummary::byValue([
            ['applicability' => 'no', 'implementation_status' => 'not_started',
                'security_properties' => 'availability'],
        ], 'security_properties'), null, 'value');

        self::assertNull($lines['availability']['rate']);
        self::assertNull($lines['confidentiality']['rate']);
        self::assertSame(1, $lines['availability']['total']);
        self::assertSame(0, $lines['availability']['applicable']);
    }

    public function testUnknownStoredValuesAreIgnored(): void
    {
        $lines = ControlAttributeSummary::byValue([
            ['applicability' => 'yes', 'implementation_status' => 'implemented',
                'cybersecurity_concepts' => 'protect,unknown'],
        ], 'cybersecurity_concepts');

        self::assertSame(1, array_sum(array_column($lines, 'total')));
    }
}
