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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Backup;

use GlpiPlugin\Grcmanager\Services\Backup\BackupCoverageRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #838 : règles pures de la couverture des sauvegardes (exports fictifs).
 * Assertions sur des codes et des nombres, jamais sur des textes traduits.
 */
final class BackupCoverageRulesTest extends TestCase
{
    private const NOW = '2026-06-15 12:00:00';

    /** Fuseau du serveur : les dates sans fuseau des exports sont lues telles quelles. */
    private static function tz(): string
    {
        return date_default_timezone_get();
    }

    public function testSettingsAreBoundedWithDefaults(): void
    {
        self::assertSame(
            ['scope' => Rules::SCOPE_CRITICAL_SERVERS, 'max_age_hours' => 48, 'restore_months' => 12],
            Rules::normalizeSettings(null)
        );
        self::assertSame(
            ['scope' => Rules::SCOPE_ALL, 'max_age_hours' => Rules::MAX_AGE_HOURS, 'restore_months' => 1],
            Rules::normalizeSettings(['scope' => 'all', 'max_age_hours' => 999999, 'restore_months' => -4])
        );
        self::assertSame(Rules::SCOPE_CRITICAL_SERVERS, Rules::normalizeSettings(['scope' => '<b>'])['scope']);
    }

    public function testSourceIsNormalized(): void
    {
        self::assertSame('veeam', Rules::normalizeSource(' Veeam '));
        self::assertSame('other', Rules::normalizeSource('<script>'));
        self::assertSame('other', Rules::normalizeSource(['proxmox']));
    }

    public function testResultsInEnglishAndFrench(): void
    {
        foreach (['Success', 'Completed', 'Réussi', 'OK', 'Terminé'] as $value) {
            self::assertSame(Rules::RESULT_SUCCESS, Rules::normalizeResult($value), $value);
        }
        foreach (['Warning', 'Completed with warnings', 'Partiel', 'incomplete'] as $value) {
            self::assertSame(Rules::RESULT_WARNING, Rules::normalizeResult($value), $value);
        }
        foreach (['Failed', 'Error', 'Échec', 'KO', 'Unsuccessful', 'Missed'] as $value) {
            self::assertSame(Rules::RESULT_FAILED, Rules::normalizeResult($value), $value);
        }
        foreach (['', 'None', 'Running', null] as $value) {
            self::assertSame(Rules::RESULT_UNKNOWN, Rules::normalizeResult($value));
        }
    }

    public function testAllowedAgeTakesTheSmallestRpo(): void
    {
        self::assertSame(['hours' => 4, 'origin' => Rules::LIMIT_ASSET], Rules::allowedAge(4, [24, 0], 48));
        self::assertSame(['hours' => 12, 'origin' => Rules::LIMIT_PROCESS], Rules::allowedAge(24, [12, 72], 48));
        self::assertSame(['hours' => 24, 'origin' => Rules::LIMIT_PROCESS], Rules::allowedAge(0, [0, 24], 48));
        self::assertSame(['hours' => 48, 'origin' => Rules::LIMIT_DEFAULT], Rules::allowedAge(0, [0], 48));
    }

    public function testStatusComparesTheAgeWithTheLimit(): void
    {
        self::assertSame(Rules::STATUS_NONE, Rules::status(null, Rules::RESULT_UNKNOWN, self::NOW, 24));
        self::assertSame(Rules::STATUS_OK, Rules::status('2026-06-15 02:00:00', Rules::RESULT_SUCCESS, self::NOW, 24));
        self::assertSame(
            Rules::STATUS_FAILED,
            Rules::status('2026-06-15 02:00:00', Rules::RESULT_FAILED, self::NOW, 24)
        );
        self::assertSame(
            Rules::STATUS_TOO_OLD,
            Rules::status('2026-06-12 12:00:00', Rules::RESULT_FAILED, self::NOW, 24)
        );
        self::assertSame(10, Rules::ageHours('2026-06-15 02:00:00', self::NOW));
        self::assertNull(Rules::ageHours('pas une date', self::NOW));
    }

    public function testRestoreStatus(): void
    {
        self::assertSame(Rules::RESTORE_NEVER, Rules::restoreStatus(null, false, self::NOW, 12));
        self::assertSame(Rules::RESTORE_RECENT, Rules::restoreStatus('2026-01-10', false, self::NOW, 12));
        self::assertSame(Rules::RESTORE_FAILED, Rules::restoreStatus('2026-01-10', true, self::NOW, 12));
        self::assertSame(Rules::RESTORE_OLD, Rules::restoreStatus('2025-03-01', false, self::NOW, 12));
        self::assertSame(Rules::RESTORE_OLD, Rules::restoreStatus('2026-01-10', false, self::NOW, 3));
    }

    public function testSummaryCountsRecentBackupsEvenAfterAFailedAttempt(): void
    {
        $summary = Rules::summary([
            ['status' => Rules::STATUS_OK],
            ['status' => Rules::STATUS_FAILED],
            ['status' => Rules::STATUS_TOO_OLD],
            ['status' => Rules::STATUS_NONE],
        ]);

        self::assertSame(4, $summary['total']);
        self::assertSame(50, $summary['rate']);
        self::assertSame(2, $summary['uncovered']);
        self::assertNull(Rules::summary([])['rate']);
    }

    public function testConcernedAssets(): void
    {
        self::assertTrue(Rules::isConcerned(Rules::SCOPE_CRITICAL_SERVERS, false, true));
        self::assertFalse(Rules::isConcerned(Rules::SCOPE_CRITICAL_SERVERS, false, false));
        self::assertFalse(Rules::isConcerned(Rules::SCOPE_CRITICAL, false, true));
        self::assertTrue(Rules::isConcerned(Rules::SCOPE_ALL, false, false));
    }

    public function testParsesASummaryExportWithResultOfTheLastAttempt(): void
    {
        $parsed = Rules::parseCsv(
            "Name;Last backup;Status;Plan\n"
            . "srv-paie.exemple.test;2026-06-15 01:00:00;Succeeded;Serveurs\n"
            . "srv-fichiers;2026-06-14 01:00:00;Failed;Serveurs\n"
            . ";;;\n",
            self::tz()
        );

        self::assertSame([], $parsed['errors']);
        self::assertCount(2, $parsed['rows']);
        self::assertSame('srv-paie.exemple.test', $parsed['rows'][0]['host']);
        self::assertSame('2026-06-15 01:00:00', $parsed['rows'][0]['last_success']);
        self::assertSame(Rules::RESULT_SUCCESS, $parsed['rows'][0]['last_result']);
        self::assertSame('Serveurs', $parsed['rows'][0]['job']);
        self::assertSame(Rules::RESULT_FAILED, $parsed['rows'][1]['last_result']);
    }

    public function testParsesARunLogKeepingTheLastSuccessAndTheLastAttempt(): void
    {
        $parsed = Rules::parseCsv(
            "host,ip,job,end time,result\n"
            . "srv-web,192.0.2.10,Quotidien,2026-06-13 23:00:00,Success\n"
            . "srv-web,192.0.2.10,Quotidien,2026-06-14 23:00:00,Failed\n"
            . "srv-web,192.0.2.10,Quotidien,2026-06-12 23:00:00,Success\n"
            . "srv-web,pas-une-ip,Quotidien,2026-06-14 23:00:00,Success\n",
            self::tz()
        );

        self::assertSame([], $parsed['errors']);
        self::assertCount(2, $parsed['rows']);
        $row = $parsed['rows'][0];
        self::assertSame('2026-06-13 23:00:00', $row['last_success']);
        self::assertSame('2026-06-14 23:00:00', $row['last_attempt']);
        self::assertSame(Rules::RESULT_FAILED, $row['last_result']);
        self::assertSame('', $parsed['rows'][1]['ip']);
    }

    public function testRecognizesFrenchHeaders(): void
    {
        $parsed = Rules::parseCsv(
            "Hôte;Dernière sauvegarde réussie;Résultat;Tâche\n" . "srv-rh;15/06/2026 01:00;Réussi;Nuit\n",
            self::tz()
        );

        self::assertSame([], $parsed['errors']);
        self::assertSame('srv-rh', $parsed['rows'][0]['host']);
        self::assertSame('2026-06-15 01:00:00', $parsed['rows'][0]['last_success']);
        self::assertSame(Rules::RESULT_SUCCESS, $parsed['rows'][0]['last_result']);
        self::assertSame('Nuit', $parsed['rows'][0]['job']);
    }

    public function testParsesAProxmoxSnapshotList(): void
    {
        $parsed = Rules::parseCsv("backup-type,backup-id,backup-time\nvm,101,1781500000\nvm,101,1781400000\n");

        self::assertSame([], $parsed['errors']);
        self::assertCount(1, $parsed['rows']);
        self::assertSame('101', $parsed['rows'][0]['host']);
        self::assertNotNull($parsed['rows'][0]['last_success']);
    }

    public function testRefusesAFileWithoutHostOrDate(): void
    {
        self::assertSame(['missing_host'], Rules::parseCsv("date,status\n2026-06-01,ok\n")['errors']);
        self::assertSame(['missing_date'], Rules::parseCsv("host,status\nsrv,ok\n")['errors']);
        self::assertSame(['missing_host'], Rules::parseCsv('')['errors']);
    }

    public function testTrappedValuesAreKeptAsPlainText(): void
    {
        $parsed = Rules::parseCsv("host,last success\n\"<img src=x onerror=alert(1)>\",2026-06-15 01:00:00\n");

        self::assertSame('<img src=x onerror=alert(1)>', $parsed['rows'][0]['host']);
    }
}
