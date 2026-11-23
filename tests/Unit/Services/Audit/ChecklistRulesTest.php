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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Audit;

use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #606 : règles de la grille d'audit interne et de la couverture du programme d'audit.
 */
final class ChecklistRulesTest extends TestCase
{
    public function testResultsAndFindingsRaisedFromALine(): void
    {
        self::assertSame(Rules::RESULT_NOT_AUDITED, Rules::normalizeResult('closed'), 'résultat inconnu');
        self::assertSame(Rules::RESULT_NC_MAJOR, Rules::normalizeResult('nc_major'));
        self::assertFalse(Rules::isAudited(Rules::RESULT_NOT_AUDITED));
        self::assertTrue(Rules::isAudited(Rules::RESULT_COMPLIANT));
        self::assertFalse(Rules::isAudited('autre'));

        self::assertFalse(Rules::canRaiseFinding(Rules::RESULT_COMPLIANT));
        self::assertFalse(Rules::canRaiseFinding(Rules::RESULT_NOT_AUDITED));
        $raising = [
            Rules::RESULT_NC_MINOR, Rules::RESULT_NC_MAJOR, Rules::RESULT_OBSERVATION, Rules::RESULT_IMPROVEMENT,
        ];
        foreach ($raising as $result) {
            self::assertTrue(Rules::canRaiseFinding($result), $result);
            self::assertNotNull(Rules::findingPreset($result), $result);
        }
        self::assertSame(
            ['finding_type' => 'nonconformity', 'severity' => 'major'],
            Rules::findingPreset(Rules::RESULT_NC_MAJOR)
        );
        self::assertSame(
            ['finding_type' => 'nonconformity', 'severity' => 'minor'],
            Rules::findingPreset(Rules::RESULT_NC_MINOR)
        );
        self::assertSame('observation', Rules::findingPreset(Rules::RESULT_IMPROVEMENT)['finding_type'] ?? null);
        self::assertNull(Rules::findingPreset(Rules::RESULT_COMPLIANT));
        self::assertTrue(Rules::isNonconformity(Rules::RESULT_NC_MINOR));
        self::assertFalse(Rules::isNonconformity(Rules::RESULT_OBSERVATION));
    }

    public function testChecklistIsFrozenOnceTheAuditIsClosed(): void
    {
        self::assertFalse(Rules::isAuditClosed('planned'));
        self::assertFalse(Rules::isAuditClosed('in_progress'));
        self::assertTrue(Rules::isAuditClosed('completed'));
        self::assertTrue(Rules::isAuditClosed('cancelled'));
    }

    public function testReferenceKeys(): void
    {
        self::assertSame('clause:9.2.2', Rules::refKey(Rules::TYPE_CLAUSE, ' 9.2.2 ', 12));
        self::assertSame('control:A.5.15', Rules::refKey(Rules::TYPE_CONTROL, 'A.5.15', 99), 'numéro, pas id');
        self::assertSame('custom:7', Rules::refKey(Rules::TYPE_CUSTOM, 'REQ-1', 7));
        self::assertNull(Rules::refKey(Rules::TYPE_CUSTOM, 'REQ-1', 0));
        self::assertNull(Rules::refKey(Rules::TYPE_FREE, 'P-1', 0), 'un point libre n\'a pas de clé');
        self::assertNull(Rules::refKey(Rules::TYPE_CLAUSE, '', 1));
    }

    public function testTextCleaning(): void
    {
        self::assertSame('Revue  des accès', Rules::cleanLongText("  Revue  des accès \r\n"));
        self::assertSame("a\nb", Rules::cleanLongText("a\r\nb"));
        self::assertSame('Revue des lots fictifs', Rules::cleanText("  Revue \n des   lots   fictifs ", 255));
        self::assertSame('abc', Rules::cleanText('abcdef', 3));
        self::assertSame('', Rules::cleanText(['tableau'], 10));
    }

    public function testCoveragePeriod(): void
    {
        self::assertSame(3, Rules::coverageYears(null));
        self::assertSame(3, Rules::coverageYears('abc'));
        self::assertSame(1, Rules::coverageYears('0'));
        self::assertSame(10, Rules::coverageYears(50));
        self::assertSame(2, Rules::coverageYears('2'));
        self::assertSame('2024-11-19', Rules::coverageStart('2027-11-19', 3));
        self::assertSame('2026-11-19', Rules::coverageStart('2027-11-19', 1));
    }

    public function testSummaryCountsEveryResult(): void
    {
        $summary = Rules::summary(['compliant', 'compliant', 'nc_minor', 'not_audited', 'inconnu']);
        self::assertSame(5, $summary['total']);
        self::assertSame(3, $summary['audited']);
        self::assertSame(60, $summary['percent']);
        self::assertSame(2, $summary['counts']['compliant']);
        self::assertSame(2, $summary['counts']['not_audited'], 'résultat inconnu compté comme non audité');
        self::assertSame(0, Rules::summary([])['percent']);
    }

    public function testCoverageListsNeverAuditedFirstThenOldestAudits(): void
    {
        $catalog = [
            ['key' => 'clause:9.2.2', 'type' => 'clause', 'code' => '9.2.2', 'label' => 'Programme'],
            ['key' => 'control:A.5.15', 'type' => 'control', 'code' => 'A.5.15', 'label' => 'Accès'],
            ['key' => 'control:A.8.13', 'type' => 'control', 'code' => 'A.8.13', 'label' => 'Sauvegarde'],
            ['key' => 'control:A.5.1', 'type' => 'control', 'code' => 'A.5.1', 'label' => 'Politiques'],
        ];
        $line = static fn (?string $key, string $date, string $result, int $audit): array => [
            'key'         => $key,
            'date'        => $date,
            'result'      => $result,
            'audit_id'    => $audit,
            'audit_title' => 'Audit ' . $audit,
        ];
        $coverage = Rules::coverage($catalog, [
            $line('control:A.5.15', '2026-03-01', 'compliant', 1),
            $line('control:A.5.15', '2027-02-01', 'nc_minor', 2),
            $line('clause:9.2.2', '2026-06-01', 'compliant', 1),
            // Le même jour, la non-conformité l'emporte sur la ligne conforme.
            $line('clause:9.2.2', '2026-06-01', 'nc_major', 3),
            // Hors période, non audité, ou sans clé : ignorés.
            $line('control:A.8.13', '2023-01-01', 'compliant', 4),
            $line('control:A.5.1', '2027-01-01', 'not_audited', 2),
            $line(null, '2027-01-01', 'compliant', 2),
        ], '2024-11-19');

        self::assertSame(4, $coverage['total']);
        self::assertSame(2, $coverage['covered']);
        self::assertSame(2, $coverage['never']);
        self::assertSame(50, $coverage['percent']);
        self::assertSame(
            ['A.8.13', 'A.5.1', '9.2.2', 'A.5.15'],
            array_column($coverage['rows'], 'code'),
            'jamais auditées en tête (ordre du catalogue), puis du plus ancien au plus récent'
        );
        $byCode = array_column($coverage['rows'], null, 'code');
        self::assertSame('2027-02-01', $byCode['A.5.15']['last_date']);
        self::assertSame('nc_minor', $byCode['A.5.15']['last_result'], 'dernier audit, pas le plus favorable');
        self::assertSame(2, $byCode['A.5.15']['audit_id']);
        self::assertSame('nc_major', $byCode['9.2.2']['last_result']);
        self::assertFalse($byCode['A.8.13']['audited']);
        self::assertNull($byCode['A.8.13']['last_date']);
    }
}
