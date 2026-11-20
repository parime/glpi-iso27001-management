<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\PersonnelCompliance;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;
use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceProvider;
use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceRules as Rules;
use GlpiPlugin\Grcmanager\Training;

/**
 * Issue #546 : fiche de conformité sécurité d'un collaborateur, limitée aux entités de la
 * personne qui consulte ; la consultation n'écrit rien, la saisie ne garde que statut et dates.
 */
final class PersonnelComplianceTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> entités de session avant le test */
    private array $sessionEntities = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity'] as $key) {
            $this->sessionEntities[$key] = $_SESSION[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionEntities as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function userIn(int $entitiesId, string $name, array $fields = []): int
    {
        global $DB;

        $id = $this->createTestUser('Conf', $name);
        (new \Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => $entitiesId]);
        if ($fields !== []) {
            $DB->update('glpi_users', $fields, ['id' => $id]);
        }

        return $id;
    }

    private function onlyEntity(int $entitiesId): void
    {
        $_SESSION['glpiactiveentities'] = [$entitiesId];
        $_SESSION['glpiactiveentities_string'] = (string) $entitiesId;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function components(int $usersId): array
    {
        $rows = PersonnelCompliance::evaluateUsers(['users_id' => [$usersId]], false);
        $this->assertCount(1, $rows);

        return array_column($rows[0]['components'], null, 'key');
    }

    public function testRecordGathersWhatThePluginAlreadyTracks(): void
    {
        global $DB;

        $entity = $this->createTestEntity(0, 'PHPUnit conformité A ' . random_int(1000, 9999));
        $longAgo = date('Y-m-d H:i:s', strtotime('-400 days'));
        $person = $this->userIn($entity, 'Ancien', ['begin_date' => $longAgo, 'date_creation' => $longAgo]);

        // Formation obligatoire assignée il y a 100 jours, jamais suivie : écart.
        $training = (int) (new Training())->add(['title' => 'PHPUnit sensibilisation', 'participants' => [$person]]);
        $DB->update(
            'glpi_plugin_grcmanager_trainings_users',
            ['date_creation' => date('Y-m-d H:i:s', strtotime('-100 days'))],
            ['plugin_grcmanager_trainings_id' => $training, 'users_id' => $person]
        );
        // Politique à lire, échéance passée.
        $DB->insert(\GlpiPlugin\Grcmanager\Policy::getTable(), [
            'title' => 'PHPUnit PSSI', 'version' => '1.0', 'status' => 'approved', 'approval_date' => date('Y-m-d'),
        ]);
        $policy = (int) $DB->insertId();
        $DB->insert(PolicyAcknowledgement::CAMPAIGNS_TABLE, [
            'plugin_grcmanager_policies_id' => $policy, 'version' => '1.0', 'status' => 'open',
            'date_launch' => $longAgo, 'due_date' => date('Y-m-d', strtotime('-10 days')),
        ]);
        $DB->insert(PolicyAcknowledgement::LINES_TABLE, [
            'plugin_grcmanager_policycampaigns_id' => (int) $DB->insertId(), 'users_id' => $person,
            'entities_id' => $entity,
        ]);
        // Revue des accès décidée il y a deux mois.
        $DB->insert(AccessReview::getTable(), ['name' => 'PHPUnit revue', 'entities_id' => $entity, 'status' => 'closed']);
        $DB->insert(AccessReview::LINES_TABLE, [
            'plugin_grcmanager_accessreviews_id' => (int) $DB->insertId(), 'users_id' => $person,
            'decision' => 'keep', 'date_decision' => date('Y-m-d H:i:s', strtotime('-60 days')),
        ]);

        $components = $this->components($person);
        $this->assertSame(Rules::LATE, $components[Rules::SCREENING]['status']);
        $this->assertSame(Rules::LATE, $components[Rules::TRAINING]['status']);
        $this->assertSame(['titles' => ['PHPUnit sensibilisation']], $components[Rules::TRAINING]['params']);
        $this->assertSame(Rules::LATE, $components[Rules::POLICIES]['status']);
        $this->assertSame(Rules::OK, $components[Rules::ACCESS_REVIEW]['status']);
        $this->assertSame(Rules::OK, $components[Rules::ACCOUNT]['status']);
        $this->assertSame(
            PersonnelComplianceProvider::charterAvailable() ? Rules::LATE : Rules::NOT_TRACKED,
            $components[Rules::CHARTER]['status']
        );
        $this->assertSame(
            0,
            countElementsInTable(PersonnelComplianceProvider::RECORDS_TABLE, ['users_id' => $person]),
            'La consultation ne crée aucune ligne.'
        );

        // Formation suivie, puis vérification et engagement saisis.
        $DB->update(
            'glpi_plugin_grcmanager_trainings_users',
            ['completion_status' => 'completed', 'completion_date' => date('Y-m-d')],
            ['plugin_grcmanager_trainings_id' => $training, 'users_id' => $person]
        );
        $this->assertTrue(PersonnelCompliance::saveRecord($person, [
            'screening_status' => Rules::SCREENING_DONE, 'screening_date' => '2025-09-01',
            'nda_date' => '2025-09-15', 'nda_version' => 'v2', 'comment' => 'jamais conservé',
        ]));
        $components = $this->components($person);
        $this->assertSame(Rules::OK, $components[Rules::TRAINING]['status']);
        $this->assertSame(Rules::OK, $components[Rules::SCREENING]['status']);
        $this->assertSame(['date' => '2025-09-15', 'version' => 'v2'], $components[Rules::NDA]['params']);

        $record = PersonnelComplianceProvider::records([$person])[$person];
        $this->assertArrayNotHasKey('comment', $record, 'Aucun champ libre : statut et dates seulement.');
    }

    public function testSaveRecordKeepsOnlyKnownValues(): void
    {
        $entity = $this->createTestEntity(0, 'PHPUnit conformité B ' . random_int(1000, 9999));
        $person = $this->userIn($entity, 'Saisie');

        $this->assertTrue(PersonnelCompliance::saveRecord($person, [
            'screening_status' => 'inconnu', 'screening_date' => '2025-01-01',
            'nda_date' => 'pas une date', 'nda_version' => 'v9',
        ]));
        $record = PersonnelComplianceProvider::records([$person])[$person];
        $this->assertSame('', $record['screening_status']);
        $this->assertNull($record['screening_date'], 'Une date sans vérification faite est retirée.');
        $this->assertNull($record['nda_date']);
        $this->assertSame('', $record['nda_version'], 'Pas de version sans date de signature.');

        $this->assertTrue(PersonnelCompliance::saveRecord($person, ['screening_status' => Rules::SCREENING_NOT_APPLICABLE]));
        $this->assertSame(
            1,
            countElementsInTable(PersonnelComplianceProvider::RECORDS_TABLE, ['users_id' => $person]),
            'Une seule ligne par personne.'
        );
        $this->assertSame(Rules::NOT_APPLICABLE, $this->components($person)[Rules::SCREENING]['status']);
    }

    public function testListIsLimitedToTheEntitiesOfTheSession(): void
    {
        $visible = $this->createTestEntity(0, 'PHPUnit conformité visible ' . random_int(1000, 9999));
        $hidden = $this->createTestEntity(0, 'PHPUnit conformité cachée ' . random_int(1000, 9999));
        $newcomer = $this->userIn($visible, 'Nouveau', ['begin_date' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $other = $this->userIn($hidden, 'Autre');

        $this->onlyEntity($visible);
        $ids = array_column(PersonnelCompliance::filteredRows(PersonnelCompliance::filters([])), 'id');
        $this->assertContains($newcomer, $ids);
        $this->assertNotContains($other, $ids);
        $this->assertSame([], PersonnelCompliance::filteredRows(PersonnelCompliance::filters(['entities_id' => $hidden])));

        $pending = array_column(PersonnelCompliance::filteredRows(PersonnelCompliance::filters(['status' => 'pending'])), 'id');
        $this->assertContains($newcomer, $pending, 'Nouvel arrivant dans le délai.');
        $late = array_column(PersonnelCompliance::filteredRows(PersonnelCompliance::filters(['status' => 'late'])), 'id');
        $this->assertNotContains($newcomer, $late);

        $this->assertTrue(PersonnelCompliance::canViewFor($newcomer));
        $this->assertFalse(PersonnelCompliance::canViewFor($other), 'Compte d\'une entité non visible.');
        $this->assertFalse(PersonnelCompliance::canEditFor($other));
        $this->assertFalse(PersonnelCompliance::saveRecord($other, ['screening_status' => Rules::SCREENING_DONE]));
        $this->assertTrue(PersonnelCompliance::canViewFor((int) $_SESSION['glpiID']), 'Sa propre fiche.');
    }

    public function testGroupAggregationAndCsv(): void
    {
        global $DB;

        $entity = $this->createTestEntity(0, 'PHPUnit conformité groupe ' . random_int(1000, 9999));
        $person = $this->userIn($entity, 'Formule', ['realname' => '=Formule']);
        $DB->insert('glpi_groups', ['name' => 'PHPUnit service', 'entities_id' => 0, 'is_recursive' => 1]);
        $group = (int) $DB->insertId();
        $DB->insert('glpi_groups_users', ['groups_id' => $group, 'users_id' => $person]);

        $this->onlyEntity($entity);
        $rows = PersonnelCompliance::evaluateUsers();
        $groups = array_column(PersonnelCompliance::groupAggregation($rows), null, 'id');
        $this->assertSame(1, $groups[$group]['people']);
        $this->assertSame(['id' => [$person]], ['id' => array_column(
            PersonnelCompliance::filteredRows(PersonnelCompliance::filters(['groups_id' => $group])),
            'id'
        )]);

        $lines = PersonnelCompliance::csvLines(PersonnelCompliance::exportRows(PersonnelCompliance::filters([]), $person));
        $this->assertCount(1 + count(Rules::COMPONENTS), $lines, 'Une ligne par composante.');
        $this->assertStringStartsWith("'", $lines[1][0], 'Formule neutralisée pour le tableur.');
    }

    public function testSettingsAreNormalized(): void
    {
        PersonnelCompliance::saveSettings('900', [Rules::NDA => 0, Rules::TRAINING => '3', Rules::SCREENING => 'x']);

        $this->assertSame(Rules::DEFAULT_DAYS, PersonnelCompliance::days());
        $weights = PersonnelCompliance::weights();
        $this->assertSame(0, $weights[Rules::NDA]);
        $this->assertSame(3, $weights[Rules::TRAINING]);
        $this->assertSame(Rules::DEFAULT_WEIGHT, $weights[Rules::SCREENING]);

        PersonnelCompliance::saveSettings(45, []);
        $this->assertSame(45, PersonnelCompliance::days());
        PersonnelCompliance::saveSettings(Rules::DEFAULT_DAYS, []);
    }
}
