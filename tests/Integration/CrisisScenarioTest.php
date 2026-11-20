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

use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\BusinessProcessRecovery;
use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\CrisisMessageRecipient;
use GlpiPlugin\Grcmanager\CrisisMessageTemplate;
use GlpiPlugin\Grcmanager\CrisisScenario;
use GlpiPlugin\Grcmanager\CrisisScenarioReadiness;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules;
use GlpiPlugin\Grcmanager\Install\CrisisScenarioTables;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Playbook;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioRules as Rules;
use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #776 : scénarios de crise sécurité de l'information du PCA (A.5.29) : catalogue par défaut,
 * processus impactés, couverture, exercices, droits et entités. Données fictives.
 */
final class CrisisScenarioTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        global $DB;

        parent::setUp();
        $keys = [
            'glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiactiveprofile',
            'glpiparententities',
        ];
        foreach ($keys as $key) {
            $this->saved[$key] = $_SESSION[$key] ?? null;
        }
        // Base de test partagée : on part d'un registre vide (annulé avec la transaction).
        $tables = [CrisisScenarioTables::PROCESSES, CrisisScenarioTables::SCENARIOS, BusinessProcess::getTable()];
        foreach ($tables as $table) {
            $DB->delete($table, ['id' => ['>', 0]]);
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

    private function process(string $name, int $rto, int $entityId = 0, bool $recursive = false): int
    {
        $id = (int) (new BusinessProcess())->add([
            'name' => $name, 'rto_hours' => $rto, 'entities_id' => $entityId, 'is_recursive' => $recursive ? 1 : 0,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function scenario(string $name, array $input = [], int $entityId = 0): CrisisScenario
    {
        $scenario = new CrisisScenario();
        $id = (int) $scenario->add($input + [
            'name'            => $name,
            'entities_id'     => $entityId,
            'is_recursive'    => 0,
            'degraded_mode'   => 'Accès limités, validation à deux',
            'role_management' => 'Décide',
            'role_ciso'       => 'Coordonne',
            'role_it'         => 'Restaure',
        ]);
        $this->assertGreaterThan(0, $id);
        $scenario->getFromDB($id);

        return $scenario;
    }

    /**
     * @return array<string, mixed>
     */
    private static function presented(int $id): array
    {
        foreach (CrisisScenario::register() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('Scénario absent du registre.');
    }

    private function onlyEntity(int $entityId): void
    {
        $_SESSION['glpiactiveentities'] = [$entityId];
        $_SESSION['glpiactiveentities_string'] = (string) $entityId;
        $_SESSION['glpiactive_entity'] = $entityId;
        // Comme une vraie session : l'entité racine est parente (critères « récursif » de GLPI).
        $_SESSION['glpiparententities'] = [0];
    }

    public function testDefaultCatalogueIsAddedOnceAndReusesPlaybooksExercisesAndTemplates(): void
    {
        $this->assertSame(5, CrisisScenario::addDefaults(0));
        $this->assertSame(0, CrisisScenario::addDefaults(0), 'Jamais une seconde fois dans la même entité.');

        $byKey = [];
        foreach ((new CrisisScenario())->find() as $row) {
            $byKey[(string) $row['default_key']] = $row;
        }
        $keys = array_keys($byKey);
        sort($keys);
        $this->assertSame(
            ['data_breach', 'denial_of_service', 'privileged_accounts', 'ransomware', 'supplier_outage'],
            $keys
        );
        foreach ($byKey as $row) {
            $this->assertNotSame('', trim((string) $row['degraded_mode']));
            $this->assertNotSame('', trim((string) $row['role_ciso']));
            $this->assertSame(1, (int) $row['is_recursive']);
        }
        // Le guide de réponse est la procédure fournie, jamais une copie.
        $playbook = new Playbook();
        $this->assertTrue($playbook->getFromDB((int) $byKey['ransomware']['plugin_grcmanager_playbooks_id']));
        $this->assertSame('malware', $playbook->fields['category']);
        $this->assertTrue($playbook->getFromDB((int) $byKey['privileged_accounts']['plugin_grcmanager_playbooks_id']));
        $this->assertSame('unauthorized_access', $playbook->fields['category']);
        $this->assertSame(0, (int) $byKey['denial_of_service']['plugin_grcmanager_playbooks_id']);
        $this->assertSame('ransomware', $byKey['ransomware']['exercise_scenario']);
        $this->assertSame('outage', $byKey['supplier_outage']['exercise_scenario']);
        $this->assertSame(
            CrisisCommunicationRules::SITUATION_DATA_BREACH,
            $byKey['data_breach']['communication_situation']
        );

        $denial = self::presented((int) $byKey['denial_of_service']['id']);
        $this->assertContains(Rules::GAP_NO_PROCESS, $denial['gaps']);
        $this->assertContains(Rules::GAP_NO_PLAYBOOK, $denial['gaps']);
        $this->assertContains(Rules::GAP_NEVER_EXERCISED, $denial['gaps']);
        $ransomware = self::presented((int) $byKey['ransomware']['id']);
        $this->assertNotSame([], $ransomware['templates'], 'Modèles de messages « rançongiciel » proposés.');
        $this->assertNotContains(Rules::GAP_NO_PLAYBOOK, $ransomware['gaps']);
    }

    public function testInputIsNormalized(): void
    {
        $this->assertFalse((new CrisisScenario())->add(['name' => '   ']));
        $scenario = $this->scenario('PHPUnit scénario fictif', [
            'default_key'             => 'inconnu',
            'exercise_scenario'       => '<b>x</b>',
            'communication_situation' => 'later',
            'plugin_grcmanager_playbooks_id' => 999999,
            'last_exercise_date'      => '2026-02-30x',
            'is_active'               => 'oui',
        ]);
        $this->assertSame('', $scenario->fields['default_key']);
        $this->assertSame('', $scenario->fields['exercise_scenario']);
        $this->assertSame('', $scenario->fields['communication_situation']);
        $this->assertSame(0, (int) $scenario->fields['plugin_grcmanager_playbooks_id']);
        $this->assertNull($scenario->fields['last_exercise_date']);
        $this->assertSame(1, (int) $scenario->fields['is_active']);

        $entityB = $this->createTestEntity(0, 'PHPUnit entité B');
        $scenario->update([
            'id' => $scenario->getID(), 'entities_id' => $entityB, 'default_key' => 'ransomware',
            'last_exercise_date' => '2026-05-04',
        ]);
        $scenario->getFromDB($scenario->getID());
        $this->assertSame(0, (int) $scenario->fields['entities_id'], 'Entité inchangée par un formulaire forgé.');
        $this->assertSame('', $scenario->fields['default_key']);
        $this->assertSame('2026-05-04', $scenario->fields['last_exercise_date']);
    }

    public function testCoverageOfCriticalProcessesAndGaps(): void
    {
        $billing = $this->process('PHPUnit facturation', 4);
        $payroll = $this->process('PHPUnit paie', 24);
        $this->process('PHPUnit archives', 72);
        $scenario = $this->scenario('PHPUnit rançongiciel', [
            '_processes_sent' => 1, '_processes' => [$billing],
        ]);
        $this->scenario('PHPUnit inactif', ['is_active' => 0, '_processes_sent' => 1, '_processes' => [$payroll]]);

        $coverage = CrisisScenario::coverage(CrisisScenario::register());
        $this->assertSame(2, $coverage['summary']['critical']);
        $this->assertSame(1, $coverage['summary']['critical_uncovered'], 'Un scénario inactif ne couvre rien.');
        $this->assertSame(['PHPUnit paie'], array_column($coverage['uncovered'], 'name'));
        $this->assertSame(1, $coverage['summary']['scenarios']);
        $this->assertSame(1, $coverage['summary']['never_exercised']);
        $this->assertCount(5, $coverage['summary']['missing_defaults']);

        $row = self::presented($scenario->getID());
        $this->assertSame(['PHPUnit facturation'], array_column($row['processes'], 'name'));
        $this->assertTrue($row['processes'][0]['critical']);
        $this->assertSame(
            [Rules::GAP_NO_PLAYBOOK, Rules::GAP_NEVER_EXERCISED, Rules::GAP_RECOVERY_UNTESTED],
            $row['gaps']
        );

        // Exercice sur table hors plugin, daté de plus d'un an : à exercer de nouveau.
        $scenario->update(['id' => $scenario->getID(), 'last_exercise_date' => date('Y-m-d', strtotime('-400 days'))]);
        $this->assertSame(Rules::EXERCISE_OVERDUE, self::presented($scenario->getID())['exercise_status']);

        // Onglet du processus : le scénario qui le touche.
        $process = new BusinessProcess();
        $process->getFromDB($billing);
        $this->assertSame(
            ['PHPUnit rançongiciel'],
            array_column(CrisisScenario::scenariosForProcess($process), 'name')
        );

        // Retirer un processus du formulaire supprime le lien.
        $scenario->update(['id' => $scenario->getID(), '_processes_sent' => 1, '_processes' => []]);
        $this->assertSame([], self::presented($scenario->getID())['processes']);
    }

    public function testFinishedDrillCountsOnlyForIncidentReaders(): void
    {
        global $DB;

        $scenario = $this->scenario('PHPUnit exercé', ['exercise_scenario' => 'ransomware']);
        $incidentId = (int) (new PluginGrcmanagerSecurityIncident())->add([
            'name' => 'PHPUnit [EXERCICE]', 'content' => 'Exercice fictif', 'entities_id' => 0,
            'category' => 'malware', 'severity' => 'critical', 'is_exercise' => 1,
            'date' => date('Y-m-d H:i:s'),
        ]);
        $this->assertGreaterThan(0, $incidentId);
        $DB->insert(CrisisExercise::getTable(), [
            'entities_id' => 0, 'plugin_grcmanager_securityincidents_id' => $incidentId,
            'scenario' => 'ransomware', 'users_id' => 2,
            'started_at' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'ended_at' => date('Y-m-d H:i:s'),
        ]);
        $row = self::presented($scenario->getID());
        $this->assertSame(Rules::EXERCISE_OK, $row['exercise_status']);
        $this->assertSame(date('Y-m-d'), $row['last_exercise']);

        // Sans le droit des incidents, l'exercice n'est ni daté ni compté.
        $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::RIGHTNAME] = 0;
        $this->assertSame(Rules::EXERCISE_NEVER, self::presented($scenario->getID())['exercise_status']);
    }

    /**
     * Exercice de crise fictif terminé (incident d'exercice), renvoie l'identifiant de l'exercice.
     */
    private function drill(string $scenario, bool $validated = false): int
    {
        global $DB;

        $incidentId = (int) (new PluginGrcmanagerSecurityIncident())->add([
            'name' => 'PHPUnit [EXERCICE] ' . $scenario, 'content' => 'Exercice fictif', 'entities_id' => 0,
            'category' => 'malware', 'severity' => 'critical', 'is_exercise' => 1,
            'date' => date('Y-m-d H:i:s'),
        ]);
        $this->assertGreaterThan(0, $incidentId);
        $DB->insert(CrisisExercise::getTable(), [
            'entities_id' => 0, 'plugin_grcmanager_securityincidents_id' => $incidentId,
            'scenario' => $scenario, 'users_id' => 2,
            'started_at' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'ended_at' => date('Y-m-d H:i:s'),
            'validated_at' => $validated ? date('Y-m-d H:i:s') : null,
            'users_id_validator' => $validated ? 2 : 0,
        ]);

        return (int) $DB->insertId();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function contact(string $name, array $input = []): int
    {
        $id = (int) (new ContextItem())->add($input + [
            'kind'                => ContextItemRules::KIND_PARTY,
            'name'                => $name,
            'party_type'          => 'other',
            'is_official_contact' => 1,
            'contact_category'    => 'authority',
            'contact_details'     => 'contact@autorite.example',
            'entities_id'         => 0,
            'is_recursive'        => 1,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    public function testReviewOfTheLastExerciseAndRecoveryTestsAreGaps(): void
    {
        global $DB;

        $processId = $this->process('PHPUnit processus critique', 4);
        $scenario = $this->scenario('PHPUnit bilan et reprise', [
            'exercise_scenario' => 'outage', '_processes_sent' => 1, '_processes' => [$processId],
            'plugin_grcmanager_playbooks_id' => (int) (new Playbook())->add([
                'name' => 'PHPUnit procédure', 'is_active' => 1,
            ]),
        ]);
        $row = self::presented($scenario->getID());
        $this->assertContains(Rules::GAP_RECOVERY_UNTESTED, $row['gaps']);
        $this->assertContains(Rules::GAP_NEVER_EXERCISED, $row['gaps']);
        $this->assertNull($row['drill']);

        // Exercice terminé, bilan pas encore validé.
        $exerciseId = $this->drill('outage');
        $row = self::presented($scenario->getID());
        $this->assertSame($exerciseId, $row['drill']['id']);
        $this->assertFalse($row['drill']['validated']);
        $this->assertContains(Rules::GAP_REVIEW_PENDING, $row['gaps']);
        $this->assertNotContains(Rules::GAP_REVIEW_PENDING, $row['todo_labels']);

        // Test de reprise du processus, puis bilan validé : plus aucun manque.
        $this->assertGreaterThan(0, (int) (new BusinessProcessRecovery())->add([
            'plugin_grcmanager_businessprocesses_id' => $processId,
            'test_date' => date('Y-m-d'), 'measured_minutes' => 300,
        ]));
        $DB->update(CrisisExercise::getTable(), ['validated_at' => date('Y-m-d H:i:s'), 'users_id_validator' => 2], [
            'id' => $exerciseId,
        ]);
        $row = self::presented($scenario->getID());
        $this->assertSame([], $row['gaps']);
        $this->assertSame(date('Y-m-d'), $row['processes'][0]['last_test']);
        $this->assertTrue($row['processes'][0]['test_exceeds'], '5 heures mesurées pour un RTO de 4 heures.');
        $summary = CrisisScenario::coverage(CrisisScenario::register())['summary'];
        $this->assertSame(1, $summary['reviews_validated']);
        $this->assertSame(0, $summary['review_pending']);
        $this->assertSame(0, $summary['recovery_untested']);
        $evidence = CrisisScenario::evidence('A.5.29');
        $this->assertNotNull($evidence);
        $this->assertSame(1, $evidence['reviews_validated']);
    }

    public function testPreparednessTabGathersExercisesMessagesAndOfficialContacts(): void
    {
        $authority = $this->contact('PHPUnit autorité fictive', ['on_security_incident' => 1]);
        $privacy = $this->contact('PHPUnit autorité de protection fictive', ['on_data_breach' => 1]);
        $this->contact('PHPUnit contact non concerné');
        $template = new CrisisMessageTemplate();
        $templateId = (int) $template->add([
            'name' => 'PHPUnit message de fuite', 'situation' => CrisisCommunicationRules::SITUATION_DATA_BREACH,
            'stage' => 'first', 'audience' => 'internal', 'subject' => 'Objet fictif', 'body' => 'Texte fictif',
            'is_active' => 1,
        ]);
        $this->assertGreaterThan(0, $templateId);
        $this->assertTrue(
            CrisisMessageRecipient::addTo($templateId, CrisisCommunicationRules::RECIPIENT_CONTACT, $privacy)
        );

        $ransomware = $this->scenario('PHPUnit préparation rançongiciel', [
            'exercise_scenario' => 'ransomware',
            'communication_situation' => CrisisCommunicationRules::SITUATION_RANSOMWARE,
        ]);
        $data = CrisisScenarioReadiness::collect($ransomware);
        $names = array_column($data['contacts'], 'name');
        $this->assertContains('PHPUnit autorité fictive', $names);
        $this->assertNotContains('PHPUnit autorité de protection fictive', $names);
        $this->assertNotContains('PHPUnit contact non concerné', $names);
        $this->assertNull($data['last_review']);
        $this->assertTrue($data['can_launch']);

        $first = $this->drill('ransomware', true);
        $second = $this->drill('ransomware');
        $data = CrisisScenarioReadiness::collect($ransomware);
        $this->assertSame([$second, $first], array_slice(array_column($data['drills'], 'id'), 0, 2));
        $this->assertFalse($data['last_review']['validated']);
        $this->assertTrue($data['drills'][1]['validated']);
        $this->assertNotNull($data['last_review']);
        $this->assertArrayNotHasKey('exercise', $data['drills'][0], 'Aucun objet transmis au gabarit.');

        $leak = $this->scenario('PHPUnit préparation fuite', [
            'communication_situation' => CrisisCommunicationRules::SITUATION_DATA_BREACH,
        ]);
        $data = CrisisScenarioReadiness::collect($leak);
        $names = array_column($data['contacts'], 'name');
        $this->assertContains('PHPUnit autorité fictive', $names);
        $this->assertContains('PHPUnit autorité de protection fictive', $names);
        $this->assertFalse($data['can_launch'], 'Aucun exercice choisi.');
        $templates = array_column($data['templates'], 'recipients', 'name');
        $this->assertSame(1, $templates['PHPUnit message de fuite'] ?? null);
        $this->assertContains($authority, array_column($data['contacts'], 'id'));

        // Sans le droit des incidents : aucun exercice ; sans celui du contexte : aucun contact.
        $_SESSION['glpiactiveprofile'][PluginGrcmanagerSecurityIncident::RIGHTNAME] = 0;
        $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = 0;
        $data = CrisisScenarioReadiness::collect($ransomware);
        $this->assertSame([], $data['drills']);
        $this->assertSame([], $data['contacts']);
        $this->assertFalse($data['can_contacts']);
    }

    public function testProcessesAndScenariosRespectEntities(): void
    {
        $entityA = $this->createTestEntity(0, 'PHPUnit entité A');
        $entityB = $this->createTestEntity(0, 'PHPUnit entité B');
        $inA = $this->process('PHPUnit processus A', 4, $entityA);
        $inB = $this->process('PHPUnit processus B', 4, $entityB);
        $shared = $this->process('PHPUnit processus racine partagé', 8, 0, true);
        $scenarioA = $this->scenario('PHPUnit scénario A', [
            '_processes_sent' => 1, '_processes' => [$inA, $inB, $shared],
        ], $entityA);
        $this->assertSame(
            ['PHPUnit processus A', 'PHPUnit processus racine partagé'],
            array_column(self::presented($scenarioA->getID())['processes'], 'name'),
            'Un processus d\'une autre entité n\'est jamais lié.'
        );
        $this->assertArrayNotHasKey($inB, $scenarioA->processChoices());
        $scenarioB = $this->scenario('PHPUnit scénario B', [], $entityB);

        $this->onlyEntity($entityA);
        $names = array_column(CrisisScenario::register(), 'name');
        $this->assertContains('PHPUnit scénario A', $names);
        $this->assertNotContains('PHPUnit scénario B', $names);
        $this->assertFalse($scenarioB->can($scenarioB->getID(), READ));
        $coverage = CrisisScenario::coverage(CrisisScenario::register());
        $this->assertSame(2, $coverage['summary']['critical'], 'Processus de A et racine partagé, jamais celui de B.');
        $this->assertSame([], $coverage['uncovered']);
    }

    public function testRightsOfTheContinuityPlaybooksAndEvidence(): void
    {
        $this->assertNull(CrisisScenario::evidence('A.5.30'));
        $evidence = CrisisScenario::evidence('A.5.29');
        $this->assertNotNull($evidence);
        $this->assertArrayHasKey('critical_uncovered', $evidence);

        // Sans la lecture des procédures, le guide de réponse ne se choisit pas.
        $playbookId = (int) (new Playbook())->add(['name' => 'PHPUnit procédure fictive', 'is_active' => 1]);
        $_SESSION['glpiactiveprofile'][Playbook::RIGHTNAME] = 0;
        $scenario = $this->scenario('PHPUnit sans procédures', ['plugin_grcmanager_playbooks_id' => $playbookId]);
        $this->assertSame(0, (int) $scenario->fields['plugin_grcmanager_playbooks_id']);
        $this->assertSame([0], array_keys(CrisisScenario::playbookChoices()));

        // Sans le droit de la continuité : ni registre, ni preuve, ni menu.
        $_SESSION['glpiactiveprofile'][Installer::CONTINUITY_RIGHT] = 0;
        $this->assertSame([], CrisisScenario::register());
        $this->assertNull(CrisisScenario::evidence('A.5.29'));
        $this->assertFalse(CrisisScenario::getMenuContent());
    }

    public function testPurgeCleansLinks(): void
    {
        $processId = $this->process('PHPUnit processus purgé', 4);
        $playbook = new Playbook();
        $playbookId = (int) $playbook->add(['name' => 'PHPUnit procédure purgée', 'is_active' => 1]);
        $scenario = $this->scenario('PHPUnit purge', [
            'plugin_grcmanager_playbooks_id' => $playbookId, '_processes_sent' => 1, '_processes' => [$processId],
        ]);
        (new BusinessProcess())->delete(['id' => $processId], true);
        $playbook->delete(['id' => $playbookId], true);
        $links = static fn (array $where): int => countElementsInTable(CrisisScenarioTables::PROCESSES, $where);
        $this->assertSame(0, $links([CrisisScenario::FK => $scenario->getID()]));
        $scenario->getFromDB($scenario->getID());
        $this->assertSame(0, (int) $scenario->fields['plugin_grcmanager_playbooks_id']);

        $otherProcess = $this->process('PHPUnit processus restant', 4);
        $scenario->update(['id' => $scenario->getID(), '_processes_sent' => 1, '_processes' => [$otherProcess]]);
        $scenario->delete(['id' => $scenario->getID()], true);
        $this->assertSame(0, $links(['plugin_grcmanager_businessprocesses_id' => $otherProcess]));
        $this->assertSame(1, countElementsInTable(BusinessProcess::getTable(), ['id' => $otherProcess]));
    }
}
