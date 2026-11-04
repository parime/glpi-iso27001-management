<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Dashboard;

use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecurityIncidentCardProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #143 / #372 : tendance sur 12 mois, notifications réglementaires à suivre, cartes liées à
 * un référentiel.
 */
final class SecurityIncidentCardProviderTest extends TestCase
{
    public function testTwelveMonthsEndWithTheCurrentMonthAcrossTheYear(): void
    {
        $months = SecurityIncidentCardProvider::lastTwelveMonths((int) mktime(12, 0, 0, 3, 31, 2026));

        self::assertCount(12, $months);
        self::assertSame('2025-04', $months[0]);
        self::assertSame('2026-02', $months[10], 'Pas de saut de février un 31 du mois.');
        self::assertSame('2026-03', $months[11]);
        self::assertSame([0, 4, 0], SecurityIncidentCardProvider::alignOnMonths(
            ['2026-01', '2026-02', '2026-03'],
            ['2026-02' => 4, '2025-01' => 9]
        ));
    }

    public function testPendingNotificationsSkipDoneOnesAndComeMostUrgentFirst(): void
    {
        $now = (int) strtotime('2026-06-10 12:00:00');
        $pending = SecurityIncidentCardProvider::pendingNotifications([
            [
                'id' => 1, 'name' => 'Rançongiciel fictif', 'date' => '2026-06-09 12:00:00',
                'nis2_applicable' => 1, 'personal_data_breach' => 0,
                'nis2_early_warning_at' => '2026-06-09 18:00:00',
            ],
            [
                'id' => 2, 'name' => 'Fuite fictive', 'date' => '2026-06-01 08:00:00',
                'nis2_applicable' => 0, 'personal_data_breach' => 1,
            ],
        ], $now);

        self::assertSame(
            [[2, 'gdpr_notification'], [1, 'nis2_notification'], [1, 'nis2_final_report']],
            array_map(static fn (array $row): array => [$row['id'], $row['key']], $pending)
        );
        self::assertSame('overdue', $pending[0]['status']);
    }

    public function testComplianceCardDisappearsOnlyWhenAllItsFrameworksAreDisabled(): void
    {
        self::assertTrue(
            SecuredDashboardCards::frameworkAvailable('complianceByFramework', [FrameworkSelection::NIS2])
        );
        self::assertFalse(SecuredDashboardCards::frameworkAvailable(
            'complianceByFramework',
            [FrameworkSelection::CIS_CONTROLS, FrameworkSelection::ISO27090]
        ));
        self::assertTrue(SecuredDashboardCards::frameworkAvailable('openRisksCount', []));
    }

    public function testIncidentCardsAreRecognisedWhateverTheirProvider(): void
    {
        foreach (['open', 'openCritical', 'incidentsByEntity', 'regulatoryNotifications'] as $name) {
            self::assertTrue(SecuredDashboardCards::isIncidentCard($name), $name);
        }
        foreach (['securityIncidentsByStatus', 'securityIncidentRegulatoryDeadlines'] as $name) {
            self::assertTrue(SecuredDashboardCards::isIncidentCard($name), $name);
        }
        self::assertFalse(SecuredDashboardCards::isIncidentCard('monthlyTrend'));
        self::assertFalse(SecuredDashboardCards::isIncidentCard('openRisksCount'));
    }

    /**
     * SecuredDashboardCards choisit le fournisseur d'après le seul nom de la méthode : deux
     * fournisseurs ne doivent jamais en partager un.
     */
    public function testNoCardNameIsSharedBetweenProviders(): void
    {
        $names = [];
        foreach ([DashboardCardService::class, PilotageCards::class, SecurityIncidentCardProvider::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $names[] = strtolower($method->getName());
            }
        }

        self::assertSame([], array_values(array_unique(array_diff_assoc($names, array_unique($names)))));
    }
}
