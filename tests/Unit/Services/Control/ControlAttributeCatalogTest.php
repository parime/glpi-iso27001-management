<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\ControlAttributeCatalog;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Issue #610 : attributs livrés pour les 93 mesures de l'Annexe A.
 */
final class ControlAttributeCatalogTest extends TestCase
{
    public function testEveryAnnexAControlHasItsAttributes(): void
    {
        self::assertSame(
            array_keys(ControlCatalogDefaults::CONTROLS),
            array_keys(ControlAttributeCatalog::CONTROLS)
        );
    }

    public function testFiveAttributesInDisplayOrder(): void
    {
        self::assertSame(
            [
                'control_types', 'security_properties', 'cybersecurity_concepts',
                'operational_capabilities', 'security_domains',
            ],
            ControlAttributeCatalog::attributes()
        );
    }

    public function testEveryStoredValueIsKnownAndNotEmpty(): void
    {
        foreach (ControlAttributeCatalog::CONTROLS as $code => $values) {
            foreach (ControlAttributeCatalog::defaultsFor($code) as $attribute => $stored) {
                $decoded = ControlAttributeCatalog::decode($attribute, $stored);
                self::assertNotSame([], $decoded, $code . ' ' . $attribute);
                self::assertCount(count(explode(',', $stored)), $decoded, $code . ' ' . $attribute . ' : ' . $stored);
                self::assertLessThanOrEqual(255, strlen($stored), $code . ' ' . $attribute);
            }
        }
    }

    public function testEveryValueIsUsedByAtLeastOneControl(): void
    {
        foreach (ControlAttributeCatalog::VALUES as $attribute => $values) {
            $used = [];
            foreach (array_keys(ControlAttributeCatalog::CONTROLS) as $code) {
                $used = array_merge(
                    $used,
                    ControlAttributeCatalog::decode($attribute, ControlAttributeCatalog::defaultsFor($code)[$attribute])
                );
            }
            self::assertSame([], array_values(array_diff($values, $used)), $attribute);
        }
    }

    /**
     * Le filtre de la recherche cherche la valeur dans la liste stockée : aucune valeur ne doit
     * en contenir une autre du même attribut.
     */
    public function testNoValueContainsAnotherOfTheSameAttribute(): void
    {
        foreach (ControlAttributeCatalog::VALUES as $attribute => $values) {
            foreach ($values as $value) {
                foreach ($values as $other) {
                    if ($value !== $other) {
                        self::assertStringNotContainsString($other, $value, $attribute);
                    }
                }
            }
        }
    }

    public function testEncodeKeepsKnownValuesInCatalogueOrder(): void
    {
        self::assertSame(
            'preventive,corrective',
            ControlAttributeCatalog::encode('control_types', ['corrective', 'unknown', 'preventive', 'corrective'])
        );
        self::assertSame('', ControlAttributeCatalog::encode('control_types', null));
        self::assertSame('integrity', ControlAttributeCatalog::encode('security_properties', ' integrity ,x'));
        self::assertSame([], ControlAttributeCatalog::decode('unknown_attribute', 'preventive'));
    }

    public function testHasAndDefaults(): void
    {
        self::assertTrue(ControlAttributeCatalog::has('security_properties', 'integrity,availability', 'availability'));
        self::assertFalse(ControlAttributeCatalog::has('security_properties', 'integrity', 'availability'));
        self::assertSame([], ControlAttributeCatalog::defaultsFor('A.9.9'));
        self::assertSame('availability', ControlAttributeCatalog::defaultsFor('A.5.30')['security_properties']);
    }

    /**
     * Répartition globale du catalogue livré : la grande majorité des mesures sont préventives et
     * chaque fonction (concept) est couverte.
     */
    public function testCatalogueDistribution(): void
    {
        $types = array_count_values(array_merge(...array_map(
            static fn (string $code): array => ControlAttributeCatalog::decode(
                'control_types',
                ControlAttributeCatalog::defaultsFor($code)['control_types']
            ),
            array_keys(ControlAttributeCatalog::CONTROLS)
        )));

        self::assertGreaterThan(70, $types['preventive']);
        self::assertGreaterThan(5, $types['detective']);
        self::assertGreaterThan(5, $types['corrective']);
    }
}
