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

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Install\SoaScopingTables;
use GlpiPlugin\Grcmanager\Services\Control\SoaScopingRules;
use GlpiPlugin\Grcmanager\SoaScoping;

/**
 * Issue #873 : cadrage guidé de la SoA sur des données fictives. Indépendant de la langue : seuls
 * les codes, les états et les valeurs enregistrées sont comparés.
 */
final class SoaScopingTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @return array<string, int>
     */
    private function seed(): array
    {
        global $DB;

        $entity = SoaScoping::entity();
        $DB->delete(SoaScopingTables::ANSWERS, ['entities_id' => $entity]);
        $DB->delete(SoaScopingTables::DECISIONS, ['entities_id' => $entity]);
        $ids = EntityPerimeter::controlIdsByCode($entity);
        // A.6.7 sans justification ; A.7.1 avec une justification écrite par l'organisation.
        $DB->update(Control::getTable(), ['applicability' => 'yes', 'justification' => ''], ['id' => $ids['A.6.7']]);
        $DB->update(Control::getTable(), [
            'applicability' => 'yes', 'justification' => 'Justification fictive 873',
        ], ['id' => $ids['A.7.1']]);

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function byCode(array $rows): array
    {
        return array_column($rows, null, 'code');
    }

    public function testProposalsWaitForAnswersAndNothingIsAppliedBeforeADecision(): void
    {
        global $DB;

        $ids = $this->seed();
        $entity = SoaScoping::entity();
        $rows = self::byCode(SoaScoping::rows($entity));
        $this->assertSame(SoaScopingRules::STATUS_WAITING, $rows['A.6.7']['status']);
        $this->assertNotNull($rows['A.5.1']['proposal'], 'mesure de base');

        SoaScoping::saveAnswers($entity, ['remote_work' => 'no', 'own_premises' => 'no', 'cloud_services' => 'yes']);
        $this->assertSame(
            ['remote_work' => 'no', 'cloud_services' => 'yes', 'own_premises' => 'no'],
            array_intersect_key(SoaScoping::answers($entity), array_flip(['remote_work', 'cloud_services', 'own_premises']))
        );
        $rows = self::byCode(SoaScoping::rows($entity));
        $this->assertSame(SoaScopingRules::STATUS_DIFFERS, $rows['A.6.7']['status']);
        $this->assertSame('no', $rows['A.6.7']['proposal']['applicability']);
        $this->assertNotSame('', $rows['A.6.7']['proposed_justification']);
        $control = $DB->request(['FROM' => Control::getTable(), 'WHERE' => ['id' => $ids['A.6.7']]])->current();
        $this->assertSame('yes', $control['applicability'], 'rien n\'est appliqué sans décision');

        // Une réponse effacée remet la mesure en attente.
        SoaScoping::saveAnswers($entity, ['remote_work' => '']);
        $this->assertArrayNotHasKey('remote_work', SoaScoping::answers($entity));
    }

    public function testAcceptingKeepsAWrittenJustificationAndIsLogged(): void
    {
        global $DB;

        $ids = $this->seed();
        $entity = SoaScoping::entity();
        SoaScoping::saveAnswers($entity, ['remote_work' => 'no', 'own_premises' => 'no']);

        $this->assertSame(2, SoaScoping::decide($entity, [$ids['A.6.7'], $ids['A.7.1']], 'accepted'));
        $a67 = $DB->request(['FROM' => Control::getTable(), 'WHERE' => ['id' => $ids['A.6.7']]])->current();
        $a71 = $DB->request(['FROM' => Control::getTable(), 'WHERE' => ['id' => $ids['A.7.1']]])->current();
        $this->assertSame('no', $a67['applicability']);
        $this->assertNotSame('', (string) $a67['justification'], 'justification proposée ajoutée');
        $this->assertSame('activity_absent', $a67['exclusion_category']);
        $this->assertSame('no', $a71['applicability']);
        $this->assertSame('Justification fictive 873', $a71['justification'], 'justification écrite conservée');
        $this->assertSame(1, (int) $a67['is_reviewed']);

        $rows = self::byCode(SoaScoping::rows($entity));
        $this->assertSame(SoaScopingRules::STATUS_ACCEPTED, $rows['A.6.7']['status']);
        $this->assertNotNull($rows['A.6.7']['decision']);

        $journal = SoaScoping::journal($entity);
        $this->assertCount(2, $journal);
        $this->assertSame('accepted', $journal[0]['decision_key']);
        $log = $DB->request([
            'FROM' => SoaScopingTables::DECISIONS, 'WHERE' => ['plugin_grcmanager_controls_id' => $ids['A.7.1']],
        ])->current();
        $this->assertSame('yes', $log['previous_applicability']);
        $this->assertSame('Justification fictive 873', $log['previous_justification']);
        $this->assertSame(2, (int) $log['users_id']);
    }

    public function testRejectingChangesNothingAndUnansweredControlsAreSkipped(): void
    {
        global $DB;

        $ids = $this->seed();
        $entity = SoaScoping::entity();
        SoaScoping::saveAnswers($entity, ['remote_work' => 'no']);

        // A.7.1 attend la réponse sur les locaux : aucune décision possible.
        $this->assertSame(1, SoaScoping::decide($entity, [$ids['A.6.7'], $ids['A.7.1']], 'rejected'));
        $a67 = $DB->request(['FROM' => Control::getTable(), 'WHERE' => ['id' => $ids['A.6.7']]])->current();
        $this->assertSame('yes', $a67['applicability']);
        $rows = self::byCode(SoaScoping::rows($entity));
        $this->assertSame(SoaScopingRules::STATUS_REJECTED, $rows['A.6.7']['status']);
        $this->assertSame(0, SoaScoping::decide($entity, [$ids['A.6.7']], 'unknown'));
    }

    public function testCsvExportListsAnswersControlsAndLog(): void
    {
        $ids = $this->seed();
        $entity = SoaScoping::entity();
        SoaScoping::saveAnswers($entity, ['remote_work' => 'no']);
        SoaScoping::decide($entity, [$ids['A.6.7']], 'accepted');

        $lines = SoaScoping::csvRows($entity);
        $codes = array_column(array_filter($lines, static fn (array $l): bool => count($l) > 2), 0);
        $this->assertContains('A.6.7', $codes);
        $this->assertContains('A.8.34', $codes);
        $this->assertCount(count(SoaScopingRules::QUESTIONS) + 1, array_slice($lines, 0, array_search([], $lines, true)));
    }
}
