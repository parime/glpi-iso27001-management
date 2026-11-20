<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\License;

use GlpiPlugin\Grcmanager\Services\License\LicenseComplianceRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #779 : règles des licences logicielles (A.5.32), sur des données fictives.
 *
 * Entités : 0 (racine) > 1 (Siège) > 2 (Agence Nord), 3 (Agence Sud) ; 4 (Filiale) sous 0.
 */
final class LicenseComplianceRulesTest extends TestCase
{
    private const TODAY = '2026-11-20';

    private const ANCESTORS = [0 => [], 1 => [0], 2 => [0, 1], 3 => [0, 1], 4 => [0]];

    private const SOFTWARE = [
        10 => ['id' => 10, 'name' => 'Suite Bureautique Démo', 'manufacturer' => 'Microsoft Corporation'],
        11 => ['id' => 11, 'name' => 'Microsoft Visual C++ 2022 Redistributable', 'manufacturer' => 'Microsoft'],
        12 => ['id' => 12, 'name' => 'Éditeur libre Démo', 'manufacturer' => 'Communauté Démo'],
        13 => ['id' => 13, 'name' => 'CAO Démo', 'manufacturer' => 'Autodesk, Inc.'],
    ];

    /**
     * @return array{software_id: int, entity_id: int, itemtype: string, items_id: int, item_name: string}
     */
    private static function install(int $software, int $entity, int $computer): array
    {
        return [
            'software_id' => $software, 'entity_id' => $entity, 'itemtype' => 'Computer',
            'items_id' => $computer, 'item_name' => 'PC démo ' . $computer,
        ];
    }

    /**
     * @return array{id: int, name: string, software_id: int, entity_id: int, is_recursive: bool,
     *     number: int, expire: string}
     */
    private static function licence(
        int $id,
        int $software,
        int $entity,
        int $number,
        bool $recursive = false,
        string $expire = ''
    ): array {
        return [
            'id' => $id, 'name' => 'Licence démo ' . $id, 'software_id' => $software, 'entity_id' => $entity,
            'is_recursive' => $recursive, 'number' => $number, 'expire' => $expire,
        ];
    }

    /**
     * @param list<array<string, mixed>> $installs
     * @param list<array<string, mixed>> $licences
     * @param list<array<string, mixed>> $assignments
     * @return list<array<string, mixed>>
     */
    private static function evaluate(array $installs, array $licences = [], array $assignments = []): array
    {
        /** @phpstan-ignore argument.type, argument.type, argument.type */
        return R::evaluate(
            self::SOFTWARE,
            $installs,
            $licences,
            $assignments,
            self::ANCESTORS,
            R::DEFAULT_PUBLISHERS,
            R::DEFAULT_IGNORED,
            self::TODAY
        );
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<string>
     */
    private static function summary(array $findings): array
    {
        return array_map(
            static fn (array $f): string => $f['type'] . ':' . $f['scope'] . ':' . $f['entity_id'] . ':'
                . $f['software_id'] . ':' . $f['installs'] . '/' . $f['seats'],
            $findings
        );
    }

    public function testListsAreCleanedDeduplicatedAndBounded(): void
    {
        // Première orthographe gardée, doublons écartés sans tenir compte de la casse.
        self::assertSame(
            ['Microsoft', 'adobe', 'Oracle'],
            R::parseList(" Microsoft \r\n\nadobe;Adobe\nMICROSOFT\nOracle")
        );
        self::assertSame([], R::parseList(str_repeat('x', R::MAX_ENTRY_LENGTH + 1)));
        self::assertCount(R::MAX_LIST_ENTRIES, R::parseList(implode("\n", range(1, R::MAX_LIST_ENTRIES + 50))));
    }

    public function testWatchedPublisherMatchesWholeWordsIgnoringCaseAndAccents(): void
    {
        self::assertTrue(R::isWatchedPublisher('Microsoft Corporation', ['microsoft']));
        self::assertTrue(R::isWatchedPublisher('Autodesk, Inc.', R::DEFAULT_PUBLISHERS));
        self::assertTrue(R::isWatchedPublisher('Dassault Systèmes SE', R::DEFAULT_PUBLISHERS));
        self::assertFalse(R::isWatchedPublisher('Microsoftware Démo', ['Microsoft']));
        self::assertFalse(R::isWatchedPublisher('', R::DEFAULT_PUBLISHERS));
        self::assertTrue(R::isIgnoredSoftware('Microsoft Visual C++ 2022 Redistributable (x64)', R::DEFAULT_IGNORED));
        self::assertFalse(R::isIgnoredSoftware('Suite Bureautique Démo', R::DEFAULT_IGNORED));
    }

    public function testExpiryAndUnlimitedLicences(): void
    {
        self::assertFalse(R::isExpired('', self::TODAY));
        self::assertFalse(R::isExpired('2026-11-20', self::TODAY), 'valable le jour même');
        self::assertTrue(R::isExpired('2026-11-19', self::TODAY));
        self::assertTrue(R::isUnlimited(0));
        self::assertTrue(R::isUnlimited(-1));
        self::assertFalse(R::isUnlimited(1));
        self::assertNull(R::seats([self::licence(1, 10, 1, 5), self::licence(2, 10, 1, -1)], self::TODAY));
        $expiredUnlimited = self::licence(2, 10, 1, -1, false, '2025-01-01');
        self::assertSame(5, R::seats([self::licence(1, 10, 1, 5), $expiredUnlimited], self::TODAY));
    }

    public function testLicenceCountsForItsEntityAndForChildrenOnlyWhenRecursive(): void
    {
        $own = self::licence(1, 10, 1, 5);
        $recursive = self::licence(2, 10, 1, 5, true);
        self::assertTrue(R::countsFor($own, 1, self::ANCESTORS[1]));
        self::assertFalse(R::countsFor($own, 2, self::ANCESTORS[2]), 'non récursive : pas pour une sous-entité');
        self::assertTrue(R::countsFor($recursive, 2, self::ANCESTORS[2]));
        self::assertFalse(R::countsFor($recursive, 4, self::ANCESTORS[4]), 'jamais pour une entité soeur');
        self::assertFalse(R::countsFor($recursive, 0, self::ANCESTORS[0]), 'jamais pour une entité parente');
    }

    public function testCommercialSoftwareWithoutLicenceIsReportedButNotFreeOrIgnoredSoftware(): void
    {
        $findings = self::evaluate([
            self::install(10, 2, 100), self::install(10, 2, 101),
            self::install(11, 2, 100),
            self::install(12, 2, 100),
        ]);
        self::assertSame(['unlicensed:entity:2:10:2/0'], self::summary($findings));
        self::assertSame('Suite Bureautique Démo', $findings[0]['software_name']);
        self::assertCount(2, $findings[0]['assets']);
    }

    public function testLicenceOfASiblingEntityDoesNotCover(): void
    {
        $findings = self::evaluate([self::install(13, 4, 100)], [self::licence(1, 13, 2, 10, true)]);
        self::assertSame(['unlicensed:entity:4:13:1/0'], self::summary($findings));
    }

    public function testRecursiveParentLicenceCoversAChildEntity(): void
    {
        self::assertSame([], self::evaluate([self::install(10, 2, 100)], [self::licence(1, 10, 1, 1, true)]));
        // Non récursive : la sous-entité n'est pas couverte.
        self::assertSame(
            ['unlicensed:entity:2:10:1/0'],
            self::summary(self::evaluate([self::install(10, 2, 100)], [self::licence(1, 10, 1, 1)]))
        );
    }

    public function testOverDeploymentCountsDistinctAssetsAndRespectsUnlimitedLicences(): void
    {
        $installs = [self::install(10, 1, 100), self::install(10, 1, 101), self::install(10, 1, 102)];
        // Deux versions sur le même actif : un seul poste.
        $installs[] = self::install(10, 1, 102);
        self::assertSame(
            ['overdeployed:entity:1:10:3/2'],
            self::summary(self::evaluate($installs, [self::licence(1, 10, 1, 2)]))
        );
        self::assertSame([], self::evaluate($installs, [self::licence(1, 10, 1, 3)]));
        self::assertSame([], self::evaluate($installs, [self::licence(1, 10, 1, 2), self::licence(2, 10, 1, 0)]));
        self::assertSame([], self::evaluate($installs, [self::licence(1, 10, 1, -1)]));
        // Une licence expirée ne compte plus dans les postes.
        self::assertSame(
            ['overdeployed:entity:1:10:3/2'],
            self::summary(self::evaluate(
                $installs,
                [self::licence(1, 10, 1, 2), self::licence(2, 10, 1, 5, false, '2026-01-31')]
            ))
        );
    }

    public function testSharedRecursiveLicenceIsCheckedOverTheWholeSubTree(): void
    {
        // 3 postes récursifs au siège ; 2 installations dans chaque agence : 4 > 3.
        $installs = [
            self::install(10, 2, 100), self::install(10, 2, 101),
            self::install(10, 3, 200), self::install(10, 3, 201),
        ];
        $findings = self::evaluate($installs, [self::licence(1, 10, 1, 3, true)]);
        self::assertSame(['overdeployed:subtree:1:10:4/3'], self::summary($findings));
        // Une seule agence en excès : un seul constat, sur l'agence, pas un second sur le siège.
        self::assertSame(
            ['overdeployed:entity:2:10:2/1'],
            self::summary(self::evaluate(
                [self::install(10, 2, 100), self::install(10, 2, 101)],
                [self::licence(1, 10, 1, 1, true)]
            ))
        );
        // Une licence propre à une agence s'ajoute au périmètre.
        self::assertSame([], self::evaluate($installs, [self::licence(1, 10, 1, 3, true), self::licence(2, 10, 3, 1)]));
    }

    public function testExpiredLicenceStillInUseIsReported(): void
    {
        $expired = self::licence(1, 13, 1, 5, false, '2026-06-30');
        // Logiciel encore installé, aucune autre licence valable.
        $findings = self::evaluate([self::install(13, 1, 100)], [$expired]);
        self::assertSame(['expired:entity:1:13:1/5'], self::summary($findings));
        self::assertSame(1, $findings[0]['licence_id']);
        self::assertSame('2026-06-30', $findings[0]['expire']);
        self::assertSame(0, $findings[0]['assigned']);

        // Renouvelée par une nouvelle licence : plus rien tant qu'aucun actif n'est resté affecté.
        $renewed = self::licence(2, 13, 1, 5);
        self::assertSame([], self::evaluate([self::install(13, 1, 100)], [$expired, $renewed]));
        $findings = self::evaluate(
            [self::install(13, 1, 100)],
            [$expired, $renewed],
            [['licence_id' => 1, 'itemtype' => 'Computer', 'items_id' => 100]]
        );
        self::assertSame(['expired:entity:1:13:1/5'], self::summary($findings));
        self::assertSame(1, $findings[0]['assigned']);

        // Logiciel désinstallé : la licence expirée ne gêne plus.
        $assigned = [['licence_id' => 1, 'itemtype' => 'Computer', 'items_id' => 100]];
        self::assertSame([], self::evaluate([], [$expired], $assigned));
    }

    public function testExpiredRecursiveLicenceCoversItsSubTree(): void
    {
        $findings = self::evaluate([self::install(13, 2, 100)], [self::licence(1, 13, 1, 5, true, '2026-06-30')]);
        self::assertSame(['expired:subtree:1:13:1/5'], self::summary($findings));
    }

    public function testFindingsAreSortedAndCounted(): void
    {
        $findings = self::evaluate(
            [
                self::install(13, 1, 100), self::install(10, 4, 300),
                self::install(10, 1, 100), self::install(10, 1, 101),
            ],
            [self::licence(1, 13, 1, 5, false, '2026-06-30'), self::licence(2, 10, 1, 1)]
        );
        self::assertSame(
            ['unlicensed:entity:4:10:1/0', 'overdeployed:entity:1:10:2/1', 'expired:entity:1:13:1/5'],
            self::summary($findings)
        );
        self::assertSame(['unlicensed' => 1, 'overdeployed' => 1, 'expired' => 1], R::counts($findings));
    }
}
