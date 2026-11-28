<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\WazuhAlertGrouping;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1185 : regroupement des alertes Wazuh répétées avant d'en faire des incidents de sécurité.
 * Données fictives.
 */
final class WazuhAlertGroupingTest extends TestCase
{
    /**
     * @param list<string> $techniques
     * @return array{id: int, key: string, computers_id: int, agent: string, date: string, level: int|null,
     *     description: string, rule_id: string, techniques: list<string>}
     */
    private static function alert(
        int $id,
        int $computer,
        string $rule,
        ?int $level,
        string $date,
        array $techniques = []
    ): array {
        return [
            'id' => $id, 'key' => 'cle-' . $id, 'computers_id' => $computer, 'agent' => 'agent-' . $computer,
            'date' => $date, 'level' => $level, 'description' => 'Regle fictive ' . $rule, 'rule_id' => $rule,
            'techniques' => $techniques,
        ];
    }

    public function testRepeatedAlertsOfTheSameRuleAndComputerFormOneGroup(): void
    {
        $groups = WazuhAlertGrouping::group([
            self::alert(1, 41, '5763', 10, '2099-01-02 10:00:00', ['T1110']),
            self::alert(2, 41, '5763', 12, '2099-01-01 09:00:00', ['T1110', 'T1078']),
            self::alert(3, 42, '5763', 10, '2099-01-03 08:00:00'),
            self::alert(4, 41, '550', 7, '2099-01-04 08:00:00'),
            self::alert(5, 41, '5763', 10, '2099-01-02 10:00:00'),
        ], [], 0);

        $this->assertSame(['41:5763', '42:5763', '41:550'], array_column($groups, 'key'), 'Niveau haut en tête.');
        $this->assertSame(3, $groups[0]['count']);
        $this->assertSame(12, $groups[0]['level'], 'Niveau le plus élevé du groupe.');
        $this->assertSame('critical', $groups[0]['incident_severity']);
        $this->assertSame('2099-01-01 09:00:00', $groups[0]['first']);
        $this->assertSame('2099-01-02 10:00:00', $groups[0]['last']);
        $this->assertSame(['T1110', 'T1078'], $groups[0]['techniques']);
        $this->assertSame([2, 1, 5], array_column($groups[0]['alerts'], 'id'), 'Ordre chronologique.');
        $this->assertSame('agent-41', $groups[0]['agent']);
    }

    public function testImportedAlertsAndLowLevelsAreLeftOut(): void
    {
        $alerts = [
            self::alert(1, 41, '5763', 12, '2099-01-01 09:00:00'),
            self::alert(2, 41, '5763', 12, '2099-01-02 09:00:00'),
            self::alert(3, 42, '100', 12, '2099-01-02 09:00:00'),
            self::alert(4, 43, '200', 5, '2099-01-02 09:00:00'),
            self::alert(5, 44, '300', null, '2099-01-02 09:00:00'),
        ];
        $imported = [
            WazuhAlertGrouping::alertId('cle-1') => true,
            WazuhAlertGrouping::alertId('cle-3') => true,
        ];
        $groups = WazuhAlertGrouping::group($alerts, $imported, 7);

        $this->assertSame(['41:5763'], array_column($groups, 'key'), 'Déjà importé en entier ou trop bas : écarté.');
        $this->assertSame(1, $groups[0]['count'], 'Seule la répétition nouvelle compte.');
        $this->assertTrue($groups[0]['repeated']);
        $this->assertCount(4, WazuhAlertGrouping::group($alerts, [], 0), 'Niveau inconnu gardé sans filtre.');
    }

    public function testGroupKeysAreStrictAndUnsafeRuleIdsAreHashed(): void
    {
        $this->assertTrue(WazuhAlertGrouping::isGroupKey('41:5763'));
        $this->assertTrue(WazuhAlertGrouping::isGroupKey('41:-'));
        $this->assertFalse(WazuhAlertGrouping::isGroupKey('0:5763'));
        $this->assertFalse(WazuhAlertGrouping::isGroupKey('41:57 63'));
        $this->assertFalse(WazuhAlertGrouping::isGroupKey("41:5763'"));
        $this->assertFalse(WazuhAlertGrouping::isGroupKey('41'));
        $this->assertSame(41, WazuhAlertGrouping::computerOf('41:5763'));

        $unsafe = WazuhAlertGrouping::groupKey(41, '<script>', 'Description fictive');
        $this->assertTrue(WazuhAlertGrouping::isGroupKey($unsafe));
        $this->assertSame('41:-', WazuhAlertGrouping::groupKey(41, '', ''));
        $groups = WazuhAlertGrouping::group([
            ['id' => 1, 'key' => 'k', 'computers_id' => 41, 'agent' => '', 'date' => '2099-01-01 00:00:00',
                'level' => 9, 'description' => 'Description fictive', 'rule_id' => '<script>', 'techniques' => []],
        ], [], 0);
        $this->assertSame('', $groups[0]['rule_id'], 'Identifiant de règle douteux jamais repris.');
    }

    public function testLevelFromAFormIsOneOfTheProposedValues(): void
    {
        $this->assertSame(12, WazuhAlertGrouping::level('12'));
        $this->assertSame(0, WazuhAlertGrouping::level(0));
        $this->assertSame(WazuhAlertGrouping::DEFAULT_LEVEL, WazuhAlertGrouping::level('9'));
        $this->assertSame(WazuhAlertGrouping::DEFAULT_LEVEL, WazuhAlertGrouping::level(null));
        $this->assertSame(WazuhAlertGrouping::DEFAULT_LEVEL, WazuhAlertGrouping::level('abc'));
    }
}
