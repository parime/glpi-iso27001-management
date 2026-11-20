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

use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\Competence;
use GlpiPlugin\Grcmanager\CompetenceDeclaration;
use GlpiPlugin\Grcmanager\CompetenceExport;
use GlpiPlugin\Grcmanager\CompetenceMatrix;
use GlpiPlugin\Grcmanager\IsmsFunction;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Competence\CompetenceRules as Rules;
use GlpiPlugin\Grcmanager\Services\Competence\DefaultCompetences;
use GlpiPlugin\Grcmanager\Training;
use Group;
use Group_User;
use Profile_User;
use UserTitle;

/**
 * Issue #774 : compétences requises par fonction, acquises et écarts, sur des données fictives.
 */
final class CompetenceTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = ['glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiactiveprofile'];
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

    private function member(string $firstname, int $entityId = 0, array $extra = []): int
    {
        $id = $this->createTestUser($firstname, 'Exemple', $extra);
        (new Profile_User())->add([
            'users_id' => $id, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => 1,
        ]);

        return $id;
    }

    private function competence(string $name, int $entityId = 0): int
    {
        $id = (int) (new Competence())->add(['name' => $name, 'entities_id' => $entityId, 'is_recursive' => 1]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function func(string $name, array $input = [], int $entityId = 0): IsmsFunction
    {
        $function = new IsmsFunction();
        $id = (int) $function->add(['name' => $name, 'entities_id' => $entityId, 'is_recursive' => 1] + $input);
        $this->assertGreaterThan(0, $id);
        $function->getFromDB($id);

        return $function;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(int $userId): array
    {
        foreach (CompetenceMatrix::data()['rows'] as $row) {
            if ($row['id'] === $userId) {
                return $row;
            }
        }
        $this->fail('Personne absente de la matrice.');
    }

    public function testInputIsValidated(): void
    {
        $this->assertFalse((new IsmsFunction())->add(['name' => ' ', 'entities_id' => 0]));
        $this->assertFalse((new Competence())->add(['name' => '', 'entities_id' => 0]));
        $user = $this->member('Alice');
        $competence = $this->competence('PHPUnit pare-feux');
        // Sans référence de preuve, sans type de preuve, ou dates incohérentes : refusé.
        $base = ['users_id' => $user, Competence::FK => $competence, 'level' => 2, 'evidence_type' => 'certificate'];
        $this->assertFalse((new CompetenceDeclaration())->add($base + ['evidence_reference' => ' ']));
        $this->assertFalse((new CompetenceDeclaration())->add(
            ['evidence_type' => 'bogus', 'evidence_reference' => 'X'] + $base
        ));
        $this->assertFalse((new CompetenceDeclaration())->add($base + [
            'evidence_reference' => 'Certificat 42', 'date_acquired' => '2026-05-01', 'date_expiry' => '2026-01-01',
        ]));
        $id = (int) (new CompetenceDeclaration())->add(array_merge($base, [
            'evidence_reference' => 'Certificat fictif 42', 'level' => 9, 'entities_id' => 12345,
        ]));
        $this->assertGreaterThan(0, $id);
        $declaration = new CompetenceDeclaration();
        $declaration->getFromDB($id);
        $this->assertSame(1, (int) $declaration->fields['level'], 'Niveau inconnu ramené à 1.');
        $this->assertSame(0, (int) $declaration->fields['entities_id'], 'Entité jamais tirée de la requête.');
        $this->assertSame(2, (int) $declaration->fields['users_id_recorder']);
    }

    public function testDefaultsAreAddedOnceWithTheirRequirements(): void
    {
        global $DB;

        // Base de test déjà utilisée : on repart sans fonction ni compétence par défaut (annulé au tearDown).
        $DB->delete(IsmsFunction::getTable(), ['NOT' => ['default_key' => '']]);
        $DB->delete(Competence::getTable(), ['NOT' => ['default_key' => '']]);
        $added = IsmsFunction::addDefaults(0);
        $this->assertSame(count(DefaultCompetences::FUNCTIONS), $added['functions']);
        $this->assertSame(count(DefaultCompetences::COMPETENCES), $added['competences']);
        $this->assertSame(['functions' => 0, 'competences' => 0], IsmsFunction::addDefaults(0));
        $ciso = new IsmsFunction();
        $this->assertTrue($ciso->getFromDBByCrit(['default_key' => 'ciso']));
        $this->assertCount(5, IsmsFunction::requirementsFor([$ciso->getID()])[$ciso->getID()]);
    }

    public function testGapsFromTrainingsDeclarationsAndHolders(): void
    {
        global $DB;

        $alice = $this->member('Alice');
        $bob = $this->member('Bob');
        $titleId = (int) (new UserTitle())->add(['name' => 'PHPUnit administrateur fictif']);
        $carol = $this->member('Carol', 0, ['usertitles_id' => $titleId]);
        $groupId = (int) (new Group())->add([
            'name' => 'PHPUnit équipe fictive', 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        (new Group_User())->add(['groups_id' => $groupId, 'users_id' => $bob]);

        $firewall = $this->competence('PHPUnit pare-feux');
        $awareness = $this->competence('PHPUnit sensibilisation');
        $training = new Training();
        $trainingId = (int) $training->add(['title' => 'PHPUnit formation pare-feux', 'renewal_period_months' => 12]);
        $sessionId = (int) (new Training())->add([
            'title' => 'PHPUnit session', 'plugin_grcmanager_trainings_id' => $trainingId,
        ]);
        // La compétence est liée par la formation principale, même si l'on choisit une session.
        (new Competence())->update(['id' => $firewall, '_add_trainings_id' => $sessionId, '_add_training_level' => 2]);
        $this->assertSame([$firewall => [$trainingId => 2]], Competence::trainingsFor([$firewall]));

        $function = $this->func('PHPUnit administrateur');
        $function->update([
            'id' => $function->getID(), '_add_users_id' => $alice, '_add_groups_id' => $groupId,
            '_add_usertitles_id' => $titleId, '_add_competences_id' => $firewall,
            '_add_level' => 2, '_add_mode' => 'training',
        ]);
        $function->update([
            'id' => $function->getID(), '_add_competences_id' => $awareness, '_add_level' => 1,
            '_add_mode' => 'experience',
        ]);
        $this->assertCount(3, IsmsFunction::holdersFor([$function->getID()])[$function->getID()]);

        // Alice réussit la session ; Bob la suit mais échoue ; Carol l'a réussie il y a deux ans (expirée).
        $participations = [
            [$alice, 'passed', date('Y-m-d')], [$bob, 'failed', date('Y-m-d')], [$carol, 'passed', '2024-01-10'],
        ];
        foreach ($participations as [$u, $r, $d]) {
            $DB->insert('glpi_plugin_grcmanager_trainings_users', [
                'plugin_grcmanager_trainings_id' => $sessionId, 'users_id' => $u, 'completion_status' => 'completed',
                'assessment_result' => $r, 'completion_date' => $d,
            ]);
        }
        (new CompetenceDeclaration())->add([
            'users_id' => $alice, Competence::FK => $awareness, 'level' => 1,
            'evidence_type' => 'annual_review', 'evidence_reference' => 'Entretien annuel fictif 2026',
        ]);

        $this->assertSame(Rules::STATUS_MET, $this->rowOf($alice)['cells'][$firewall]['status']);
        $this->assertSame(0, $this->rowOf($alice)['gaps']);
        $this->assertSame(Rules::STATUS_MISSING, $this->rowOf($bob)['cells'][$firewall]['status']);
        $this->assertSame(Rules::STATUS_EXPIRED, $this->rowOf($carol)['cells'][$firewall]['status']);

        $data = CompetenceMatrix::data();
        $bobGaps = array_values(array_filter($data['gaps'], static fn (array $g): bool => $g['person'] === $bob));
        $this->assertCount(2, $bobGaps);
        $byCompetence = array_column($bobGaps, null, 'competence');
        $this->assertSame('PHPUnit formation pare-feux', $byCompetence['PHPUnit pare-feux']['trainings'][0]['title']);
        $this->assertSame(
            CompetenceMatrix::actionText('experience'),
            $byCompetence['PHPUnit sensibilisation']['action']
        );

        // Sa propre fiche, sans aucun droit sur le plugin.
        $_SESSION['glpiactiveprofile'] = ['interface' => 'central'];
        $this->assertTrue(CompetenceMatrix::canViewFor((int) $_SESSION['glpiID']));
        $this->assertFalse(CompetenceMatrix::canViewFor($bob), 'Fiche d\'une autre personne sans droit.');
        $this->assertFalse(CompetenceMatrix::canView());
        $own = CompetenceMatrix::data($alice, false);
        $this->assertCount(1, $own['rows']);
        $this->assertSame([], $own['gaps']);

        // Purge : titulaires et exigences partent avec la fonction ; une compétence déclarée reste.
        $_SESSION['glpiactiveprofile'] = $this->saved['glpiactiveprofile'];
        $this->assertFalse((new Competence())->delete(['id' => $awareness], true), 'Compétence déclarée conservée.');
        $function->delete(['id' => $function->getID()], true);
        $this->assertSame([], IsmsFunction::holdersFor([$function->getID()]));
        $this->assertSame([], IsmsFunction::requirementsFor([$function->getID()]));
    }

    public function testPeopleAndFunctionsAreLimitedToTheEntitiesOfTheSession(): void
    {
        $entityA = $this->createTestEntity(0, 'PHPUnit entité A');
        $entityB = $this->createTestEntity(0, 'PHPUnit entité B');
        $competence = $this->competence('PHPUnit chiffrement');
        $inA = $this->member('Denis', $entityA);
        $inB = $this->member('Elsa', $entityB);
        $functionA = $this->func('PHPUnit fonction A', [], $entityA);
        $functionA->update([
            'id' => $functionA->getID(), '_add_users_id' => $inA, '_add_competences_id' => $competence,
        ]);
        $functionB = $this->func('PHPUnit fonction B', [], $entityB);
        $functionB->update([
            'id' => $functionB->getID(), '_add_users_id' => $inB, '_add_competences_id' => $competence,
        ]);
        // Une personne d'une autre entité n'est pas acceptée comme titulaire.
        $functionA->update(['id' => $functionA->getID(), '_add_users_id' => $inB]);
        $this->assertCount(1, IsmsFunction::holdersFor([$functionA->getID()])[$functionA->getID()]);

        $_SESSION['glpiactiveentities'] = [$entityA];
        $_SESSION['glpiactiveentities_string'] = (string) $entityA;
        $_SESSION['glpiactive_entity'] = $entityA;
        $data = CompetenceMatrix::data();
        $this->assertSame([$inA], array_column($data['rows'], 'id'));
        $this->assertSame(['PHPUnit fonction A'], array_column($data['functions'], 'label'));
        $this->assertFalse(CompetenceDeclaration::isVisibleUser($inB));
        $this->assertFalse((new CompetenceDeclaration())->add([
            'users_id' => $inB, Competence::FK => $competence,
            'evidence_type' => 'diploma', 'evidence_reference' => 'X',
        ]), 'Déclaration pour une personne hors des entités de la session.');
        $this->assertFalse($functionB->can($functionB->getID(), READ));
    }

    public function testPlannedTrainingsAndProposedSession(): void
    {
        global $DB;

        $alice = $this->member('Alice');
        $bob = $this->member('Bob');
        $carol = $this->member('Carol');
        $network = $this->competence('PHPUnit réseau fictif');
        $mainId = (int) (new Training())->add(['title' => 'PHPUnit formation réseau', 'max_score' => 0]);
        $sessionId = (int) (new Training())->add([
            'title' => 'PHPUnit session réseau', 'plugin_grcmanager_trainings_id' => $mainId,
            'date_delivered' => date('Y-m-d', strtotime('+20 days')),
        ]);
        $basicId = (int) (new Training())->add(['title' => 'PHPUnit notions réseau']);
        (new Competence())->update(['id' => $network, '_add_trainings_id' => $mainId, '_add_training_level' => 2]);
        (new Competence())->update(['id' => $network, '_add_trainings_id' => $basicId, '_add_training_level' => 1]);
        $function = $this->func('PHPUnit administrateur réseau');
        foreach ([$alice, $bob, $carol] as $user) {
            $function->update(['id' => $function->getID(), '_add_users_id' => $user]);
        }
        $function->update([
            'id' => $function->getID(), '_add_competences_id' => $network, '_add_level' => 2,
        ]);
        // Bob est inscrit à la session à venir ; Carol à une formation de niveau 1 qui ne comble pas
        // l'écart ; Alice à rien.
        foreach ([[$bob, $sessionId], [$carol, $basicId]] as [$user, $trainingId]) {
            $DB->insert('glpi_plugin_grcmanager_trainings_users', [
                'plugin_grcmanager_trainings_id' => $trainingId, 'users_id' => $user,
                'completion_status' => 'pending', 'assessment_result' => 'not_applicable',
            ]);
        }

        $data = CompetenceMatrix::data();
        $gaps = [];
        foreach ($data['gaps'] as $gap) {
            if ($gap['competence'] === 'PHPUnit réseau fictif') {
                $gaps[$gap['person']] = $gap;
            }
        }
        $this->assertCount(3, $gaps, 'Une inscription ne vaut pas acquisition : trois écarts.');
        $this->assertSame([], $gaps[$alice]['planned']);
        $this->assertSame('PHPUnit session réseau', $gaps[$bob]['planned'][0]['label']);
        $this->assertSame($mainId, $gaps[$bob]['planned'][0]['training']);
        $this->assertSame([], $gaps[$bob]['trainings'], 'Écart planifié : plus de formation à proposer.');
        $this->assertSame([], $gaps[$carol]['planned'], 'Une formation de niveau 1 ne comble pas le niveau 2.');
        $this->assertGreaterThanOrEqual(1, $data['summary']['gaps_planned']);
        $this->assertSame(
            $data['summary']['gaps'] - $data['summary']['gaps_planned'],
            $data['summary']['gaps_unplanned']
        );

        $plans = array_column($data['to_plan'], null, 'id');
        $this->assertArrayHasKey($mainId, $plans);
        $this->assertArrayNotHasKey($basicId, $plans, 'Niveau insuffisant : non proposée.');
        $expected = [$alice, $carol];
        sort($expected);
        $this->assertSame($expected, $plans[$mainId]['users']);
        $this->assertSame(CompetenceMatrix::sessionUrl($mainId), $plans[$mainId]['session_url']);
        $this->assertSame($expected, CompetenceMatrix::gapPeopleForTraining($mainId));

        // Session proposée : préremplie, rattachée à la formation principale, rien n'est enregistré.
        $before = countElementsInTable(Training::getTable());
        ob_start();
        (new Training())->showForm(0, ['competence_gaps_of' => $sessionId]);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('value="' . $alice . '"', $html);
        $this->assertStringContainsString('value="' . $carol . '"', $html);
        $this->assertStringContainsString('PHPUnit formation réseau', $html);
        $this->assertSame($before, countElementsInTable(Training::getTable()), 'Rien n\'est enregistré.');

        $summary = CompetenceMatrix::clauseReadiness('7.2');
        $this->assertNotNull($summary);
        $this->assertSame($data['summary']['gaps_unplanned'], $summary['gaps_unplanned']);
        $this->assertSame((int) $data['summary']['rate'], CompetenceMatrix::dashboardCard()['number']);

        // Sans la lecture des utilisateurs : ni personnes proposées, ni écarts dans la synthèse.
        $_SESSION['glpiactiveprofile']['user'] = 0;
        $this->assertSame([], CompetenceMatrix::gapPeopleForTraining($mainId));
        $summary = CompetenceMatrix::clauseReadiness('7.2');
        $this->assertNotNull($summary);
        $this->assertNull($summary['gaps_unplanned']);
        $this->assertNull($summary['without_holders']);
        $this->assertSame(0, CompetenceMatrix::dashboardCard()['number']);
    }

    public function testClauseSummaryAndArchiveAsEvidence(): void
    {
        global $DB;

        $this->assertNull(CompetenceMatrix::clauseReadiness('7.3'));
        $function = $this->func('PHPUnit RSSI fictif');
        $summary = CompetenceMatrix::clauseReadiness('7.2');
        $this->assertNotNull($summary);
        $this->assertGreaterThanOrEqual(1, $summary['functions']);
        $this->assertStringContainsString('PHPUnit RSSI fictif', CompetenceMatrix::renderPdf([$function], false));

        $documentId = CompetenceExport::archive();
        try {
            $this->assertGreaterThan(0, $documentId);
            $this->assertSame(CompetenceExport::class, RestrictedDocument::sourceOf($documentId)['itemtype'] ?? null);
            $linked = array_column(iterator_to_array($DB->request([
                'SELECT' => ['itemtype'], 'FROM' => Document_Item::getTable(),
                'WHERE'  => ['documents_id' => $documentId],
            ]), false), 'itemtype');
            $this->assertContains(IsmsRequirement::class, $linked);
            $this->assertNotEmpty(CompetenceExport::recent());
        } finally {
            if ($documentId > 0) {
                (new Document())->delete(['id' => $documentId], true);
            }
        }
    }
}
