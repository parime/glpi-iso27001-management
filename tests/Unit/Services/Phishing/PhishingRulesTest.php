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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Phishing;

use GlpiPlugin\Grcmanager\Services\Phishing\PhishingRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #604 : lecture des résultats d'une simulation d'hameçonnage, taux et tendance.
 */
final class PhishingRulesTest extends TestCase
{
    public function testGoPhishExportIsRecognisedAndStatusGivesTheSteps(): void
    {
        $raw = "id,status,ip,email,first_name,last_name,reported\n"
            . "1,Email Sent,,alice.martin@example.org,Alice,Martin,false\n"
            . "2,Clicked Link,,bruno.petit@example.org,Bruno,Petit,false\n"
            . "3,Submitted Data,,chloe.durand@example.org,Chloe,Durand,true\n"
            . "4,Error,,david.leroy@example.org,David,Leroy,false\n";
        $parsed = Rules::parse($raw);
        $mapping = Rules::guessMapping($parsed['headers']);

        $this->assertSame(['identifier' => 3, 'status' => 1, 'reported' => 6], $mapping);
        $rows = array_map(static fn (array $row): array => Rules::readRow($row, $mapping), $parsed['rows']);
        $this->assertSame('alice.martin@example.org', $rows[0]['identifier']);
        $this->assertTrue($rows[0]['sent']);
        $this->assertFalse($rows[0]['opened']);
        $this->assertTrue($rows[1]['clicked']);
        $this->assertTrue($rows[1]['opened'], 'un lien cliqué a été ouvert');
        $this->assertTrue($rows[2]['submitted']);
        $this->assertTrue($rows[2]['clicked']);
        $this->assertTrue($rows[2]['reported']);
        $this->assertFalse($rows[3]['sent'], 'un message en erreur n\'a pas été reçu');
    }

    public function testFrenchYesNoColumnsWithSemicolonAndWindowsEncoding(): void
    {
        $raw = mb_convert_encoding(
            "Adresse;Ouvert;Cliqué;Données saisies;Signalé\n"
            . "emma.moreau@example.org;oui;non;non;oui\n"
            . "felix.roux@example.org;oui;oui;non;non\n",
            'Windows-1252',
            'UTF-8'
        );
        $parsed = Rules::parse($raw);
        $mapping = Rules::guessMapping($parsed['headers']);

        $this->assertSame(
            ['identifier' => 0, 'opened' => 1, 'clicked' => 2, 'submitted' => 3, 'reported' => 4],
            $mapping
        );
        $first = Rules::readRow($parsed['rows'][0], $mapping);
        $this->assertTrue($first['sent'], 'sans colonne « reçu », chaque ligne a reçu le message');
        $this->assertTrue($first['reported']);
        $this->assertFalse($first['clicked']);
        $this->assertTrue(Rules::readRow($parsed['rows'][1], $mapping)['clicked']);
    }

    public function testFormulaLikeValuesAreNeverEvaluatedAndAreNeutralised(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", Rules::safeIdentifier('=HYPERLINK("http://x")'));
        $this->assertSame("'+33 1 00", Rules::safeIdentifier("+33 1 00\t"));
        $this->assertSame('jdoe', Rules::safeIdentifier("j\x00doe"));
        $this->assertSame(255, mb_strlen(Rules::safeIdentifier('=' . str_repeat('a', 400))));
        // Une formule dans une colonne d'étape n'est qu'un texte non vide (« oui »).
        $this->assertTrue(Rules::isTrue('=1+1'));
        $this->assertFalse(Rules::isTrue(' Non '));
        $this->assertFalse(Rules::isTrue('FALSE'));
    }

    public function testBinaryContentIsRejected(): void
    {
        $this->assertFalse(Rules::looksLikeText("PK\x03\x04\x00\x00"));
        $this->assertFalse(Rules::looksLikeText(''));
        $this->assertTrue(Rules::looksLikeText("email\nalice@example.org\n"));
    }

    public function testMappingIsLimitedToKnownFieldsAndExistingColumns(): void
    {
        $headers = ['email', 'clicked'];
        $this->assertSame(
            ['identifier' => 0, 'clicked' => 1],
            Rules::sanitizeMapping(
                ['identifier' => '0', 'clicked' => '1', 'opened' => '7', 'users_id' => '0', 'sent' => '1; DROP'],
                $headers
            )
        );
    }

    public function testRatesAreOutOfTheEmailsReceived(): void
    {
        $counts = Rules::counts([
            ['is_sent' => 1, 'is_opened' => 1, 'is_clicked' => 1, 'is_submitted' => 0, 'is_reported' => 0],
            ['is_sent' => 1, 'is_opened' => 1, 'is_clicked' => 0, 'is_submitted' => 0, 'is_reported' => 1],
            ['is_sent' => 1, 'is_opened' => 0, 'is_clicked' => 0, 'is_submitted' => 0, 'is_reported' => 0],
            ['is_sent' => 0, 'is_opened' => 0, 'is_clicked' => 0, 'is_submitted' => 0, 'is_reported' => 0],
        ]);
        $this->assertSame(['sent' => 3, 'opened' => 2, 'clicked' => 1, 'submitted' => 0, 'reported' => 1], $counts);
        $this->assertSame(
            ['opened' => 66.7, 'clicked' => 33.3, 'submitted' => 0.0, 'reported' => 33.3],
            Rules::rates($counts)
        );
        $this->assertNull(Rules::rates(['sent' => 0])['clicked']);
        $this->assertFalse(Rules::isAnonymousEnough(4));
        $this->assertTrue(Rules::isAnonymousEnough(5));
    }

    public function testTrendSortsByDateAndGivesTheChangeInClicks(): void
    {
        $trend = Rules::trend([
            ['id' => 2, 'name' => 'Automne', 'date' => '2026-10-01', 'counts' => ['sent' => 10, 'clicked' => 2]],
            ['id' => 1, 'name' => 'Printemps', 'date' => '2026-04-01', 'counts' => ['sent' => 10, 'clicked' => 4]],
            ['id' => 3, 'name' => 'Sans résultat', 'date' => '2026-11-01', 'counts' => ['sent' => 0]],
        ]);

        $this->assertSame(['Printemps', 'Automne', 'Sans résultat'], array_column($trend, 'name'));
        $this->assertNull($trend[0]['click_delta']);
        $this->assertSame(-20.0, $trend[1]['click_delta']);
        $this->assertNull($trend[2]['click_delta']);
    }

    public function testRetentionIsBoundedAndGivesThePurgeDate(): void
    {
        $this->assertSame(0, Rules::normalizeRetention('abc'));
        $this->assertSame(0, Rules::normalizeRetention(-3));
        $this->assertSame(Rules::MAX_RETENTION_MONTHS, Rules::normalizeRetention(999));
        $this->assertNull(Rules::retentionLimit(0, '2026-06-15'));
        $this->assertSame('2025-06-15', Rules::retentionLimit(12, '2026-06-15'));
        $imported = '2026-04-01 10:00:00';
        $this->assertSame('2026-03-01', Rules::referenceDate(['campaign_date' => '2026-03-01', 'date_import' => $imported]));
        $this->assertSame('2026-04-01', Rules::referenceDate(['campaign_date' => null, 'date_import' => $imported]));
        $this->assertNull(Rules::referenceDate([]));
        $this->assertSame('2026-09-01', Rules::purgeDate(['campaign_date' => '2026-03-01'], 6));
        $this->assertNull(Rules::purgeDate(['campaign_date' => '2026-03-01'], 0));
    }

    public function testGroupCountsAreAnonymousAndSmallGroupsAreCombined(): void
    {
        $step = static fn (bool $clicked): array => [
            'sent' => true, 'opened' => $clicked, 'clicked' => $clicked, 'submitted' => false, 'reported' => !$clicked,
        ];
        $byUser = [];
        for ($i = 1; $i <= 9; $i++) {
            $byUser[$i] = $step($i <= 3);
        }
        // 1 à 6 : groupe 10 ; 7 et 8 : groupe 20 ; 8 aussi dans 30 ; 9 : sans groupe.
        $groups = [1 => [10], 2 => [10], 3 => [10], 4 => [10], 5 => [10], 6 => [10], 7 => [20], 8 => [20, 30]];
        $counts = Rules::groupCounts($byUser, $groups);
        $this->assertSame([0, 10, 20, 30], array_keys($counts));
        $this->assertSame(6, $counts[10]['sent']);
        $this->assertSame(3, $counts[10]['clicked']);
        $this->assertSame(1, $counts[0]['sent']);
        $this->assertSame($counts, Rules::decodeGroupCounts((string) json_encode($counts, JSON_FORCE_OBJECT)));
        $this->assertSame([], Rules::decodeGroupCounts('not json'));

        $all = Rules::groupRows($counts, true);
        $this->assertCount(4, $all);
        $this->assertFalse($all[0]['hidden']);

        $anonymous = Rules::groupRows($counts, false);
        $this->assertSame([10, Rules::OTHER_GROUPS], array_column($anonymous, 'groups_id'));
        $this->assertSame(50.0, $anonymous[0]['rates']['clicked']);
        $this->assertSame(4, $anonymous[1]['counts']['sent']);
        $this->assertTrue($anonymous[1]['hidden'], 'Moins de 5 personnes, même regroupées : taux masqués.');
        $this->assertNull($anonymous[1]['rates']['clicked']);
    }

    public function testEntityRowsKeepTheLastMeasuredCampaign(): void
    {
        $counts = static fn (int $sent, int $clicked): array => [
            'sent' => $sent, 'opened' => 0, 'clicked' => $clicked, 'submitted' => 0, 'reported' => 0,
        ];
        $rows = Rules::entityRows([
            ['id' => 2, 'entities_id' => 1, 'date' => '2026-05-01', 'counts' => $counts(10, 1)],
            ['id' => 1, 'entities_id' => 1, 'date' => '2026-01-01', 'counts' => $counts(10, 4)],
            ['id' => 3, 'entities_id' => 1, 'date' => '2026-09-01', 'counts' => $counts(0, 0)],
            ['id' => 4, 'entities_id' => 2, 'date' => '2026-02-01', 'counts' => $counts(20, 5)],
        ]);
        $this->assertSame(2, $rows[1]['campaigns']);
        $this->assertSame('2026-05-01', $rows[1]['last_date']);
        $this->assertSame(10.0, $rows[1]['rates']['clicked']);
        $this->assertSame(25.0, $rows[2]['rates']['clicked']);
    }
}
