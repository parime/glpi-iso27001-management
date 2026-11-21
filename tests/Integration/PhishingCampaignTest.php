<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Install\PhishingTables;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\PhishingCampaign;
use GlpiPlugin\Grcmanager\PhishingResult;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Phishing\PhishingRules as Rules;
use GlpiPlugin\Grcmanager\Training;
use Profile_User;

/**
 * Issue #604 : simulations d'hameçonnage (campagne, import, rapprochement, droits, entités, preuve),
 * sur des données fictives.
 */
final class PhishingCampaignTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiactiveprofile', 'glpiID',
        ];
        foreach ($keys as $key) {
            $this->saved[$key] = $_SESSION[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function person(string $firstname, int $entityId, string $email = ''): int
    {
        $extra = $email !== '' ? ['_useremails' => [-1 => $email]] : [];
        $id = $this->createTestUser($firstname, 'Fictif', $extra);
        (new Profile_User())->add([
            'users_id' => $id, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => 0,
        ]);

        return $id;
    }

    private function campaign(string $name, int $entityId, string $date = '2026-10-01'): PhishingCampaign
    {
        $campaign = new PhishingCampaign();
        $id = (int) $campaign->add([
            'name' => $name, 'entities_id' => $entityId, 'campaign_date' => $date, 'tool' => 'GoPhish',
            'scenario' => 'Faux avis de livraison', 'target_population' => 'Service fictif',
        ]);
        $this->assertGreaterThan(0, $id);
        $campaign->getFromDB($id);

        return $campaign;
    }

    /**
     * @param list<string> $lines lignes CSV sans en-tête
     * @return array{total: int, matched: int, unmatched: int, ignored: int}
     */
    private function import(PhishingCampaign $campaign, array $lines, string $header = 'email,status,reported'): array
    {
        $parsed = Rules::parse($header . "\n" . implode("\n", $lines) . "\n");
        $result = PhishingResult::import($campaign, $parsed['rows'], Rules::guessMapping($parsed['headers']), 'x.csv');
        $campaign->getFromDB($campaign->getID());

        return $result;
    }

    public function testInputIsValidatedAndCountersOnlyComeFromTheImport(): void
    {
        $this->assertFalse((new PhishingCampaign())->add(['name' => '  ', 'entities_id' => 0]));
        $campaign = new PhishingCampaign();
        $id = (int) $campaign->add([
            'name' => 'Campagne fictive', 'entities_id' => 0, 'campaign_date' => 'pas une date',
            'nb_sent' => 99, 'nb_clicked' => 50, 'date_anonymized' => '2026-01-01 00:00:00',
        ]);
        $this->assertGreaterThan(0, $id);
        $campaign->getFromDB($id);
        $this->assertNull($campaign->fields['campaign_date']);
        $this->assertSame(0, (int) $campaign->fields['nb_sent'], 'Compteurs jamais tirés du formulaire.');
        $this->assertNull($campaign->fields['date_anonymized']);
        $campaign->update(['id' => $id, 'nb_clicked' => 12]);
        $campaign->getFromDB($id);
        $this->assertSame(0, (int) $campaign->fields['nb_clicked']);
    }

    public function testImportMatchesUsersOfTheEntityAndListsTheOtherLines(): void
    {
        global $DB;

        $entityA = $this->createTestEntity(0, 'Hameconnage fictif A');
        $entityB = $this->createTestEntity(0, 'Hameconnage fictif B');
        $alice = $this->person('Alice', $entityA, 'alice.phish@example.org');
        $bob = $this->person('Bob', $entityB, 'bob.phish@example.org');
        $carol = $this->person('Carol', $entityA);
        $carolLogin = (string) $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $carol]])->current()['name'];
        $campaign = $this->campaign('Campagne A', $entityA);

        $result = $this->import($campaign, [
            'ALICE.PHISH@example.org,Clicked Link,false',
            'bob.phish@example.org,Submitted Data,false',
            'inconnu.phish@example.org,Email Opened,true',
            '=HYPERLINK("http://example.org"),Email Sent,false',
            $carolLogin . ',Email Sent,true',
            ',Email Sent,false',
            'alice.phish@example.org,Email Sent,true',
        ]);

        $this->assertSame(['total' => 7, 'matched' => 2, 'unmatched' => 3, 'ignored' => 1], $result);
        $detail = PhishingResult::detailRows($campaign->getID());
        $this->assertEqualsCanonicalizing([$alice, $carol], array_column($detail, 'users_id'));
        $aliceRow = array_values(array_filter($detail, static fn (array $row): bool => $row['users_id'] === $alice))[0];
        $this->assertTrue($aliceRow['clicked']);
        $this->assertTrue($aliceRow['reported'], 'Deux lignes de la même personne : étapes cumulées.');
        $this->assertNotContains($bob, array_column($detail, 'users_id'), 'Autre entité : non rapprochée.');

        $unmatched = array_column(PhishingResult::unmatchedRows($campaign->getID()), 'identifier');
        $this->assertSame(
            ['bob.phish@example.org', 'inconnu.phish@example.org', '\'=HYPERLINK("http://example.org")'],
            $unmatched
        );
        // L'adresse n'est gardée que pour les lignes non rapprochées.
        $this->assertSame(0, countElementsInTable(PhishingTables::RESULTS, [
            PhishingCampaign::FK => $campaign->getID(), 'users_id' => ['>', 0], 'NOT' => ['identifier' => ''],
        ]));
        // Compteurs : 5 personnes (2 rapprochées, 3 non), clic et saisie de Bob comptés.
        $this->assertSame(
            ['sent' => 5, 'opened' => 3, 'clicked' => 2, 'submitted' => 1, 'reported' => 3],
            PhishingCampaign::countsOf($campaign->fields)
        );
        $this->assertSame(3, (int) $campaign->fields['nb_unmatched']);
        $this->assertSame([$alice], PhishingResult::remedialUserIds($campaign->getID()));

        // Un nouvel import remplace les résultats.
        $this->import($campaign, ['alice.phish@example.org,Email Sent,false']);
        $this->assertSame(1, (int) $campaign->fields['nb_sent']);
        $this->assertSame(
            1,
            countElementsInTable(PhishingTables::RESULTS, [PhishingCampaign::FK => $campaign->getID()])
        );
    }

    public function testDetailNeedsTheSensitiveRightAndSmallCampaignsHideTheirRates(): void
    {
        $campaign = $this->campaign('Campagne droits', 0);
        $this->import($campaign, [
            'a1.phish@example.org,Clicked Link,false', 'a2.phish@example.org,Email Sent,false',
            'a3.phish@example.org,Email Sent,false',
        ]);

        $this->assertTrue(PhishingResult::canSeeDetail($campaign));
        $this->assertTrue(PhishingResult::canImport($campaign));
        $this->assertFalse(PhishingResult::summaryFor($campaign)['hidden']);

        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = READ;
        $this->assertTrue(PhishingResult::canSeeDetail($campaign));
        $this->assertFalse(PhishingResult::canImport($campaign), 'Import : registres sensibles en modification.');

        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        $this->assertFalse(PhishingResult::canSeeDetail($campaign));
        $this->assertFalse(PhishingResult::canImport($campaign));
        $summary = PhishingResult::summaryFor($campaign);
        $this->assertTrue($summary['hidden'], 'Trois personnes : un taux pourrait désigner quelqu\'un.');
        $trend = array_values(array_filter(
            PhishingCampaign::trendRows(),
            static fn (array $row): bool => $row['id'] === $campaign->getID()
        ));
        $this->assertTrue($trend[0]['hidden']);
        $this->assertNull($trend[0]['rates']['clicked']);

        // L'onglet des résultats ne montre alors aucun nom ni adresse.
        ob_start();
        PhishingResult::displayTabContentForItem($campaign);
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('a1.phish@example.org', $html);
        $this->assertStringNotContainsString('name="phishing_file"', $html);

        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        ob_start();
        $shown = PhishingResult::displayTabContentForItem($campaign);
        ob_end_clean();
        $this->assertFalse($shown, 'Onglet refusé sans le droit du plugin.');
    }

    public function testEntitiesAreRespected(): void
    {
        $entityA = $this->createTestEntity(0, 'Hameconnage entite A');
        $entityB = $this->createTestEntity(0, 'Hameconnage entite B');
        $campaignB = $this->campaign('Campagne B fictive', $entityB);
        $this->import($campaignB, ['b1.phish@example.org,Clicked Link,false']);

        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        $_SESSION['glpiactive_entity'] = $entityA;

        $this->assertFalse($campaignB->can($campaignB->getID(), READ));
        $this->assertFalse(PhishingResult::canSeeDetail($campaignB));
        $this->assertFalse(PhishingResult::canImport($campaignB));
        $this->assertNotContains($campaignB->getID(), array_column(PhishingCampaign::visibleCampaigns(), 'id'));
        ob_start();
        $shown = PhishingResult::displayTabContentForItem($campaignB);
        ob_end_clean();
        $this->assertFalse($shown);
        // L'entité n'est jamais une entité non accessible, ni à la création ni à la modification.
        $campaignA = $this->campaign('Campagne A fictive', $entityB);
        $this->assertSame($entityA, (int) $campaignA->fields['entities_id']);
        $campaignA->update(['id' => $campaignA->getID(), 'entities_id' => $entityB]);
        $campaignA->getFromDB($campaignA->getID());
        $this->assertSame($entityA, (int) $campaignA->fields['entities_id']);
    }

    public function testEachPersonSeesOnlyTheirOwnResults(): void
    {
        $alice = $this->person('Alice', 0, 'alice.own@example.org');
        $bob = $this->person('Bob', 0, 'bob.own@example.org');
        $campaign = $this->campaign('Campagne personnelle', 0);
        $this->import($campaign, ['alice.own@example.org,Clicked Link,false', 'bob.own@example.org,Email Sent,true']);

        $mine = PhishingResult::myResults($alice);
        $this->assertCount(1, $mine);
        $this->assertTrue($mine[0]['clicked']);
        $this->assertSame('Campagne personnelle', $mine[0]['name']);

        $_SESSION['glpiID'] = $bob;
        $_SESSION['glpiactiveprofile'] = ['interface' => 'central'];
        ob_start();
        $this->assertTrue(PhishingResult::displayTabContentForItem(new \Preference()));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Campagne personnelle', $html);
        $this->assertStringNotContainsString('Alice', $html);
    }

    public function testRemedialTrainingIsOnlyProposed(): void
    {
        $alice = $this->person('Alice', 0, 'alice.remedial@example.org');
        $campaign = $this->campaign('Campagne rattrapage', 0);
        $this->import($campaign, ['alice.remedial@example.org,Submitted Data,false']);
        $before = countElementsInTable(Training::getTable());

        ob_start();
        (new Training())->showForm(0, ['phishing_of' => $campaign->getID()]);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Campagne rattrapage', $html);
        $this->assertStringContainsString('value="' . $alice . '"', $html);
        $this->assertSame($before, countElementsInTable(Training::getTable()), 'Rien n\'est enregistré.');

        // Sans le droit de voir le détail, aucune personne n'est préremplie.
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        ob_start();
        (new Training())->showForm(0, ['phishing_of' => $campaign->getID()]);
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('Campagne rattrapage', $html);
    }

    public function testAnonymizeKeepsTheRatesAndEvidenceIsShownOnA63And73(): void
    {
        $entity = $this->createTestEntity(0, 'Hameconnage tendance');
        $older = $this->campaign('Campagne printemps', $entity, date('Y-m-d', strtotime('-6 months')));
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = 'p' . $i . '.spring@example.org,' . ($i <= 4 ? 'Clicked Link' : 'Email Sent') . ',false';
        }
        $this->import($older, $lines);
        $newer = $this->campaign('Campagne automne', $entity, date('Y-m-d', strtotime('-1 month')));
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = 'p' . $i . '.autumn@example.org,' . ($i <= 1 ? 'Clicked Link' : 'Email Sent') . ',true';
        }
        $this->import($newer, $lines);

        PhishingResult::anonymize($older);
        $older->getFromDB($older->getID());
        $this->assertSame(0, countElementsInTable(PhishingTables::RESULTS, [PhishingCampaign::FK => $older->getID()]));
        $this->assertNotNull($older->fields['date_anonymized']);
        $this->assertSame(40.0, Rules::rates(PhishingCampaign::countsOf($older->fields))['clicked']);

        $evidence = PhishingCampaign::evidence('A.6.3');
        $this->assertNotNull($evidence);
        $this->assertGreaterThanOrEqual(2, $evidence['campaigns']);
        $this->assertSame('Campagne automne', $evidence['last']['name']);
        $this->assertSame(-30.0, $evidence['last']['click_delta']);
        $this->assertNull(PhishingCampaign::evidence('A.5.1'));
        $this->assertNotNull(PhishingCampaign::clauseReadiness('7.3'));
        $this->assertNull(PhishingCampaign::clauseReadiness('7.2'));

        // Indicateur de risque : taux de clic de la dernière campagne du périmètre.
        $this->assertSame(10.0, PhishingCampaign::lastClickRate([$entity]));
        $this->assertArrayHasKey('phishing_click_rate', RiskIndicator::getSources());
        $this->assertTrue(RiskIndicator::canUseSource('phishing_click_rate', $entity));

        // Fiche de l'exigence 7.3 : l'encadré des simulations y figure.
        $requirement = new IsmsRequirement();
        $found = $requirement->getFromDBByCrit(['code' => '7.3']);
        if ($found) {
            ob_start();
            $requirement->showForm($requirement->getID());
            $this->assertStringContainsString('grc-phishing-evidence', (string) ob_get_clean());
        }
    }

    public function testRatesByGroupSurviveTheDeletionOfTheResultsPerPerson(): void
    {
        $entity = $this->createTestEntity(0, 'Hameconnage services');
        $group = new \Group();
        $groupId = (int) $group->add(['name' => 'Service fictif phishing', 'entities_id' => $entity]);
        $lines = [];
        for ($i = 1; $i <= 6; $i++) {
            $user = $this->person('Agent' . $i, $entity, 'agent' . $i . '.group@example.org');
            if ($i <= 5) {
                (new \Group_User())->add(['groups_id' => $groupId, 'users_id' => $user]);
            }
            $lines[] = 'agent' . $i . '.group@example.org,' . ($i <= 2 ? 'Clicked Link' : 'Email Sent') . ',false';
        }
        $campaign = $this->campaign('Campagne services', $entity);
        $this->import($campaign, $lines);

        $groups = PhishingResult::summaryFor($campaign)['groups'];
        $byId = array_column($groups, null, 'groups_id');
        $this->assertSame(5, $byId[$groupId]['counts']['sent']);
        $this->assertSame(40.0, $byId[$groupId]['rates']['clicked']);
        $this->assertSame('Service fictif phishing', $byId[$groupId]['name']);
        $this->assertSame(1, $byId[0]['counts']['sent'], 'Personne sans groupe.');

        // Sans le droit du détail : la personne sans groupe est regroupée et masquée.
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        $anonymous = array_column(PhishingResult::summaryFor($campaign)['groups'], null, 'groups_id');
        $this->assertFalse($anonymous[$groupId]['hidden']);
        $this->assertTrue($anonymous[Rules::OTHER_GROUPS]['hidden']);
        $this->assertArrayNotHasKey(0, $anonymous);
        ob_start();
        PhishingResult::displayTabContentForItem($campaign);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-phishing-groups', $html);
        $this->assertStringNotContainsString('agent1.group@example.org', $html);
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = ALLSTANDARDRIGHT;

        PhishingResult::anonymize($campaign);
        $campaign->getFromDB($campaign->getID());
        $after = array_column(PhishingResult::summaryFor($campaign)['groups'], null, 'groups_id');
        $this->assertSame(40.0, $after[$groupId]['rates']['clicked'], 'Taux par service gardés.');

        // Les compteurs par service ne viennent jamais du formulaire.
        $campaign->update(['id' => $campaign->getID(), 'group_counts' => '{"1":{"sent":99}}']);
        $campaign->getFromDB($campaign->getID());
        $this->assertStringNotContainsString('99', (string) $campaign->fields['group_counts']);
    }

    public function testRetentionDeletesOnlyTheExpiredResultsPerPerson(): void
    {
        $old = $this->campaign('Campagne ancienne', 0, date('Y-m-d', strtotime('-14 months')));
        $this->import($old, ['old1.retention@example.org,Clicked Link,false']);
        $recent = $this->campaign('Campagne recente', 0, date('Y-m-d', strtotime('-2 months')));
        $this->import($recent, ['new1.retention@example.org,Clicked Link,false']);

        $this->assertSame([], PhishingCampaign::expiredCampaignIds(0, date('Y-m-d')), 'Sans durée : rien.');
        $expired = PhishingCampaign::expiredCampaignIds(12, date('Y-m-d'));
        $this->assertContains($old->getID(), $expired);
        $this->assertNotContains($recent->getID(), $expired);

        $before = PhishingCampaign::retentionMonths();
        try {
            $this->assertTrue(PhishingCampaign::saveRetention('12'));
            $this->assertSame(12, PhishingCampaign::retentionMonths());
            $this->assertNotSame('', PhishingResult::summaryFor($recent)['purge_date']);
            $task = new \CronTask();
            PhishingCampaign::cronPhishingretention($task);
            $old->getFromDB($old->getID());
            $this->assertNotNull($old->fields['date_anonymized']);
            $this->assertSame(1, (int) $old->fields['nb_clicked'], 'Taux gardés.');
            $results = static fn (PhishingCampaign $c): int
                => countElementsInTable(PhishingTables::RESULTS, [PhishingCampaign::FK => $c->getID()]);
            $this->assertSame(0, $results($old));
            $this->assertSame(1, $results($recent));
            $this->assertNotContains($old->getID(), PhishingCampaign::expiredCampaignIds(12, date('Y-m-d')));
            $this->assertSame([], PhishingCampaign::cronInfo('other'));
        } finally {
            PhishingCampaign::saveRetention($before);
        }
    }

    public function testEntityRowsAndDashboardTrendCard(): void
    {
        $entityA = $this->createTestEntity(0, 'Hameconnage tableau A');
        $entityB = $this->createTestEntity(0, 'Hameconnage tableau B');
        foreach ([$entityA => 'tabA', $entityB => 'tabB'] as $entity => $prefix) {
            $campaign = $this->campaign('Campagne ' . $prefix, $entity, date('Y-m-d', strtotime('-1 month')));
            $lines = [];
            for ($i = 1; $i <= 5; $i++) {
                $lines[] = $prefix . $i . '@example.org,' . ($i === 1 ? 'Clicked Link' : 'Email Sent') . ',false';
            }
            $this->import($campaign, $lines);
        }
        $entities = array_column(PhishingCampaign::entityRows(), 'rates', 'entity');
        $names = array_keys($entities);
        $this->assertNotEmpty(array_filter(
            $names,
            static fn (string $name): bool => str_contains($name, 'Hameconnage tableau A')
        ));
        foreach ($entities as $name => $rates) {
            if (str_contains($name, 'Hameconnage tableau')) {
                $this->assertSame(20.0, $rates['clicked']);
            }
        }

        $card = SecuredDashboardCards::phishingTrend();
        $this->assertTrue(SecuredDashboardCards::canShow('phishingTrend'));
        $this->assertCount(3, $card['data']['series']);
        $this->assertNotEmpty($card['data']['labels']);
        $this->assertContains(20.0, $card['data']['series'][0]['data']);

        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        $this->assertFalse(SecuredDashboardCards::canShow('phishingTrend'));
    }
}
