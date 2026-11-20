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
    private static function evaluate(
        array $installs,
        array $licences = [],
        array $assignments = [],
        int $expiringDays = 0
    ): array {
        /** @phpstan-ignore argument.type, argument.type, argument.type */
        return R::evaluate(
            self::SOFTWARE,
            $installs,
            $licences,
            $assignments,
            self::ANCESTORS,
            R::DEFAULT_PUBLISHERS,
            R::DEFAULT_IGNORED,
            self::TODAY,
            $expiringDays
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
        self::assertSame(
            ['unlicensed' => 1, 'overdeployed' => 1, 'expired' => 1, 'expiring' => 0],
            R::counts($findings)
        );
    }

    public function testExpiringHorizonIsBoundedAndComputed(): void
    {
        self::assertSame(0, R::boundExpiringDays(-5));
        self::assertSame(R::MAX_EXPIRING_DAYS, R::boundExpiringDays(5000));
        self::assertTrue(R::isExpiring('2026-11-20', self::TODAY, 60), 'le jour même');
        self::assertTrue(R::isExpiring('2027-01-19', self::TODAY, 60), 'dernier jour inclus');
        self::assertFalse(R::isExpiring('2027-01-20', self::TODAY, 60));
        self::assertFalse(R::isExpiring('2026-11-19', self::TODAY, 60), 'déjà expirée');
        self::assertFalse(R::isExpiring('', self::TODAY, 60), 'sans date');
        self::assertFalse(R::isExpiring('2026-12-01', self::TODAY, 0), 'alerte désactivée');
    }

    public function testLicenceExpiringSoonIsAReminderUnlessRenewed(): void
    {
        $soon = self::licence(1, 13, 1, 5, false, '2026-12-15');
        $installs = [self::install(13, 1, 100)];
        // Alerte désactivée par défaut dans evaluate() (horizon 0).
        self::assertSame([], self::evaluate($installs, [$soon]));
        $findings = self::evaluate($installs, [$soon], [], 60);
        self::assertSame(['expiring:entity:1:13:1/5'], self::summary($findings));
        self::assertSame('2026-12-15', $findings[0]['expire']);
        self::assertSame(1, $findings[0]['licence_id']);
        // Échéance au-delà de l'horizon : rien.
        self::assertSame([], self::evaluate($installs, [self::licence(1, 13, 1, 5, false, '2027-06-30')], [], 60));
        // Renouvellement déjà enregistré (sans date ou au-delà de l'horizon) : rien.
        self::assertSame([], self::evaluate($installs, [$soon, self::licence(2, 13, 1, 5)], [], 60));
        self::assertSame(
            [],
            self::evaluate($installs, [$soon, self::licence(2, 13, 1, 5, false, '2027-12-31')], [], 60)
        );
        // Une autre licence qui expire aussi dans l'horizon n'est pas un renouvellement.
        self::assertSame(
            ['expiring:entity:1:13:1/5', 'expiring:entity:1:13:1/5'],
            self::summary(self::evaluate(
                $installs,
                [$soon, self::licence(2, 13, 1, 5, false, '2026-12-31')],
                [],
                60
            ))
        );
        // Licence inutilisée (rien d'installé, aucun actif affecté) : rien.
        self::assertSame([], self::evaluate([], [$soon], [], 60));
    }

    public function testOverDeploymentAllowedByTheLicenceIsStillReportedWithThatNote(): void
    {
        $installs = [self::install(10, 1, 100), self::install(10, 1, 101)];
        $licence = self::licence(1, 10, 1, 1);
        $findings = self::evaluate($installs, [$licence]);
        self::assertFalse($findings[0]['overquota_allowed']);
        $licence['allow_overquota'] = true;
        $findings = self::evaluate($installs, [$licence]);
        self::assertSame(['overdeployed:entity:1:10:2/1'], self::summary($findings));
        self::assertTrue($findings[0]['overquota_allowed']);
    }

    public function testCoverageCountsWatchedSoftwarePerEntity(): void
    {
        $installs = [
            self::install(10, 1, 100), self::install(10, 1, 101), // couvert par une licence
            self::install(10, 4, 300),                           // autre entité, sans licence
            self::install(11, 1, 100),                           // gratuit ignoré
            self::install(12, 1, 100),                           // éditeur non surveillé
            self::install(13, 2, 200),                           // licence expirée seulement
        ];
        $licences = [self::licence(1, 10, 1, 1), self::licence(2, 13, 2, 5, false, '2026-01-01')];
        /** @phpstan-ignore argument.type, argument.type */
        $coverage = R::coverage(
            self::SOFTWARE,
            $installs,
            $licences,
            self::ANCESTORS,
            R::DEFAULT_PUBLISHERS,
            R::DEFAULT_IGNORED,
            self::TODAY
        );
        self::assertSame(['watched' => 3, 'licensed' => 1], $coverage);
    }

    /**
     * Lot 3 : licence fille (softwarelicenses_id), rattachée à sa licence mère.
     *
     * @return array<string, mixed>
     */
    private static function child(
        int $id,
        int $parent,
        int $software,
        int $entity,
        int $number,
        string $expire = ''
    ): array {
        return ['parent_id' => $parent] + self::licence($id, $software, $entity, $number, false, $expire);
    }

    public function testChildLicencesShareTheSeatsOfTheirParent(): void
    {
        $today = self::TODAY;
        // Mère de 10 postes répartis en deux filles de 4 et 6 : 10 postes, pas 20.
        $volume = [self::licence(1, 10, 1, 10), self::child(2, 1, 10, 1, 4), self::child(3, 1, 10, 1, 6)];
        /** @phpstan-ignore argument.type */
        self::assertSame(['seats' => 10, 'nested' => true], R::seatCount($volume, $today));
        // Fille « mise à niveau » d'un poste : la mère garde ses 5 postes.
        /** @phpstan-ignore argument.type */
        self::assertSame(5, R::seats([self::licence(1, 10, 1, 5), self::child(2, 1, 10, 1, 1)], $today));
        // Filles au-delà de la mère : leur somme compte.
        $above = [self::licence(1, 10, 1, 2), self::child(2, 1, 10, 1, 3), self::child(3, 1, 10, 1, 4)];
        /** @phpstan-ignore argument.type */
        self::assertSame(7, R::seats($above, $today));
        // Petite-fille sous une fille expirée : rattachée à la grand-mère valable.
        $chain = [self::licence(1, 10, 1, 8), self::child(2, 1, 10, 1, 3, '2026-01-31'), self::child(3, 2, 10, 1, 2)];
        /** @phpstan-ignore argument.type */
        self::assertSame(['seats' => 8, 'nested' => true], R::seatCount($chain, $today));
        // Mère expirée : ses filles valables comptent seules.
        /** @phpstan-ignore argument.type */
        self::assertSame(['seats' => 4, 'nested' => false], R::seatCount(
            [self::licence(1, 10, 1, 10, false, '2026-01-31'), self::child(2, 1, 10, 1, 4)],
            $today
        ));
        // Mère hors de la liste (autre entité, non visible), boucle dans les données : chaque licence compte.
        /** @phpstan-ignore argument.type */
        self::assertSame(['seats' => 5, 'nested' => false], R::seatCount(
            [self::child(2, 99, 10, 1, 2), self::child(4, 5, 10, 1, 3)],
            $today
        ));
        $loop = [self::child(1, 2, 10, 1, 3), self::child(2, 1, 10, 1, 4)];
        /** @phpstan-ignore argument.type */
        self::assertSame(7, R::seats($loop, $today));
        // Fille illimitée : tout est couvert.
        /** @phpstan-ignore argument.type */
        self::assertNull(R::seats([self::licence(1, 10, 1, 3), self::child(2, 1, 10, 1, -1)], $today));
    }

    public function testOverDeploymentHiddenByChildLicencesIsReported(): void
    {
        $installs = [];
        for ($i = 0; $i < 12; $i++) {
            $installs[] = self::install(10, 1, 100 + $i);
        }
        $findings = self::evaluate(
            $installs,
            [self::licence(1, 10, 1, 10), self::child(2, 1, 10, 1, 4), self::child(3, 1, 10, 1, 6)]
        );
        self::assertSame(['overdeployed:entity:1:10:12/10'], self::summary($findings));
        self::assertTrue($findings[0]['nested']);
        // Sans hiérarchie, les mêmes licences valent 20 postes : rien à signaler.
        self::assertSame([], self::evaluate(
            $installs,
            [self::licence(1, 10, 1, 10), self::licence(2, 10, 1, 4), self::licence(3, 10, 1, 6)]
        ));
    }
}
