<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\MitreAttack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #369 : référentiel ATT&CK embarqué (resources/mitre-attack.json).
 */
final class MitreAttackTest extends TestCase
{
    public function testEmbeddedFileIsComplete(): void
    {
        self::assertGreaterThanOrEqual(14, count(MitreAttack::tactics()));
        self::assertGreaterThan(500, count(MitreAttack::techniques()));
        self::assertSame('Credential Access', MitreAttack::tactics()['TA0006']);
        self::assertStringContainsString('MITRE', MitreAttack::notice());
        foreach (MitreAttack::techniques() as $technique) {
            self::assertNotSame([], $technique['tactics'], $technique['id']);
        }
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $input, ?array $expected): void
    {
        self::assertSame($expected, MitreAttack::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: array{kind: string, id: string}|null}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'identifiant exact'         => ['T1003.001', ['kind' => 'technique', 'id' => 'T1003.001']],
            'minuscules et barre'       => ['t1003/001', ['kind' => 'technique', 'id' => 'T1003.001']],
            'avec son nom'              => ['T1566 - Phishing', ['kind' => 'technique', 'id' => 'T1566']],
            'tactique'                  => ['ta0006', ['kind' => 'tactic', 'id' => 'TA0006']],
            'nom de tactique'           => ['credential access', ['kind' => 'tactic', 'id' => 'TA0006']],
            'nom de sous-technique'     => ['LSASS Memory', ['kind' => 'technique', 'id' => 'T1003.001']],
            'nom complet'               => [
                'OS Credential Dumping: LSASS Memory',
                ['kind' => 'technique', 'id' => 'T1003.001'],
            ],
            'identifiant inconnu'       => ['T9999', null],
            'texte quelconque'          => ['un pirate', null],
        ];
    }

    public function testFullNameAndUrl(): void
    {
        self::assertSame('OS Credential Dumping: LSASS Memory', MitreAttack::fullName('T1003.001'));
        self::assertSame('https://attack.mitre.org/techniques/T1003/001/', MitreAttack::url('T1003.001'));
        self::assertSame('https://attack.mitre.org/tactics/TA0006/', MitreAttack::url('TA0006'));
    }

    public function testDropdownGroupsATechniqueUnderEachOfItsTactics(): void
    {
        $options = MitreAttack::dropdownOptions();
        $valid = MitreAttack::technique('T1078');
        self::assertNotNull($valid);
        foreach ($valid['tactics'] as $tacticId) {
            $group = $tacticId . ' - ' . MitreAttack::tactics()[$tacticId];
            self::assertArrayHasKey('T1078|' . $tacticId, $options[$group]);
        }
    }
}
