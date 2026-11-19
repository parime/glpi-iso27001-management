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

use Computer;
use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\SentinelOneAlert;
use GlpiPlugin\Grcmanager\Services\Control\SentinelOneEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneClient;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneConfig;
use GlpiPlugin\Grcmanager\Tests\Mock\MockSentinelOne;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;

/**
 * Issue #707 : connecteur SentinelOne contre la fausse console (en mémoire). Propositions sans
 * création automatique, absence de doublon, rapprochement par numéro de série ou nom exact,
 * erreurs 403 / 429 non bloquantes, cloisonnement par entité. Assertions sur des nombres et des
 * codes, jamais sur des textes traduits.
 */
final class SentinelOneConnectorTest extends GrcmanagerIntegrationTestCase
{
    private const BASE = 'https://console.example.test';

    /** @var list<string> */
    private array $methods = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $DB;
        $tables = [
            SentinelOneAlert::getTable(), SentinelOneAlert::AGENTS_TABLE, SentinelOneAlert::VULNERABILITIES_TABLE,
        ];
        foreach ($tables as $table) {
            $DB->delete($table, ['id' => ['>', 0]]);
        }
        \Config::setConfigurationValues(SentinelOneConfig::CONTEXT, [
            SentinelOneConfig::URL_KEY           => self::BASE,
            SentinelOneConfig::THREATS_SINCE_KEY => '',
            SentinelOneConfig::ALERTS_SINCE_KEY  => '',
            SentinelOneConfig::ENTITY_KEY        => '0',
            SentinelOneConfig::LAST_RUN_KEY      => '',
        ]);
    }

    private function client(string $token): SentinelOneClient
    {
        $mock = new MockSentinelOne();

        return new SentinelOneClient(self::BASE, $token, function (string $url, array $headers) use ($mock): array {
            $this->methods[] = 'GET';
            $parts = parse_url($url);
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);

            return $mock->handle('GET', (string) $parts['path'], array_map('strval', $query), $headers);
        });
    }

    private function computer(string $name, string $serial = '', int $entityId = 0): int
    {
        return (int) (new Computer())->add(['name' => $name, 'serial' => $serial, 'entities_id' => $entityId]);
    }

    private function pendingCount(): int
    {
        return countElementsInTable(SentinelOneAlert::getTable(), ['status' => SentinelOneAlert::STATUS_PENDING]);
    }

    public function testSynchronizationProposesWithoutCreatingAndWithoutDuplicates(): void
    {
        $suffix = (string) random_int(1000, 9999);
        // Noms propres au test : les ordinateurs de la fausse console sont rapprochés par série.
        $bySerial = $this->computer('PORTABLE-RH-' . $suffix, 'SN-FICTIF-RH-01');
        $incidentsBefore = countElementsInTable(PluginGrcmanagerSecurityIncident::getTable());

        $steps = SentinelOneAlert::synchronize($this->client(MockSentinelOne::VIEWER_TOKEN));

        self::assertSame('', $steps['agents']['error']);
        self::assertSame(4, $steps['agents']['items']);
        self::assertSame(5, $steps['threats']['new']);
        self::assertSame(2, $steps['alerts']['new']);
        self::assertSame(7, $this->pendingCount());
        // Aucune création automatique.
        self::assertSame($incidentsBefore, countElementsInTable(PluginGrcmanagerSecurityIncident::getTable()));
        // Rapprochement par numéro de série, même avec un autre nom.
        self::assertSame(1, countElementsInTable(SentinelOneAlert::AGENTS_TABLE, [
            'computers_id' => $bySerial,
            'agent_id'     => '1900000000000000002',
        ]));
        self::assertSame(1, countElementsInTable(SentinelOneAlert::getTable(), [
            'external_id'  => '1950000000000000102',
            'computers_id' => $bySerial,
        ]));
        // Uniquement des lectures.
        self::assertSame(['GET'], array_values(array_unique($this->methods)));

        // Deuxième passage : rien de nouveau (reprise après la dernière date lue, identifiants uniques).
        $steps = SentinelOneAlert::synchronize($this->client(MockSentinelOne::VIEWER_TOKEN));
        self::assertSame(0, $steps['threats']['new'] + $steps['alerts']['new']);
        self::assertSame(7, $this->pendingCount());

        // Même en relisant tout depuis le début, aucun doublon.
        SentinelOneConfig::setSince('threats', '');
        SentinelOneConfig::setSince('alerts', '');
        $steps = SentinelOneAlert::synchronize($this->client(MockSentinelOne::VIEWER_TOKEN));
        self::assertSame(0, $steps['threats']['new'] + $steps['alerts']['new']);
        self::assertSame(7, countElementsInTable(SentinelOneAlert::getTable()));
    }

    public function testImportCreatesOneIncidentLinkedToTheComputerAndNeverTwice(): void
    {
        $computerId = $this->computer('PORTABLE-RH-' . random_int(1000, 9999), 'SN-FICTIF-RH-01');
        SentinelOneAlert::synchronize($this->client(MockSentinelOne::VIEWER_TOKEN));
        $proposal = new SentinelOneAlert();
        self::assertTrue($proposal->getFromDBByCrit(['external_id' => '1950000000000000102']));

        $result = SentinelOneAlert::importProposals([(int) $proposal->getID()]);
        self::assertCount(1, $result['created']);
        $incidentId = $result['created'][0];
        self::assertSame(1, countElementsInTable(PluginGrcmanagerSecurityIncident_Item::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'itemtype' => Computer::class,
            'items_id' => $computerId,
        ]));
        $alerts = SecurityIncidentEdrImport::getAlertsTable();
        self::assertSame(1, countElementsInTable($alerts, ['alert_id' => '1950000000000000102']));
        $proposal->getFromDB($proposal->getID());
        self::assertSame(SentinelOneAlert::STATUS_IMPORTED, $proposal->fields['status']);

        // Deuxième import de la même proposition : rien.
        $again = SentinelOneAlert::importProposals([(int) $proposal->getID()]);
        self::assertSame([], $again['created']);

        // Écarter : la proposition quitte la liste, sans incident.
        $other = new SentinelOneAlert();
        self::assertTrue($other->getFromDBByCrit(['external_id' => '1950000000000000104']));
        self::assertSame(1, SentinelOneAlert::ignoreProposals([(int) $other->getID()]));
        self::assertSame(5, $this->pendingCount());
    }

    public function testAlertAlreadyImportedFromACsvFileIsNotProposedAgain(): void
    {
        global $DB;
        $DB->insert(SecurityIncidentEdrImport::getAlertsTable(), [
            'plugin_grcmanager_securityincidents_id' => 0,
            'alert_id' => '1950000000000000101',
        ]);

        SentinelOneAlert::synchronize($this->client(MockSentinelOne::VIEWER_TOKEN));

        $table = SentinelOneAlert::getTable();
        self::assertSame(0, countElementsInTable($table, ['external_id' => '1950000000000000101']));
        self::assertSame(6, $this->pendingCount());
    }

    public function testForbiddenListsDoNotBlockTheOthers(): void
    {
        $steps = SentinelOneAlert::synchronize($this->client(MockSentinelOne::LICENCE_TOKEN));

        self::assertSame(SentinelOneClient::ERROR_FORBIDDEN, $steps['alerts']['error']);
        self::assertSame(SentinelOneClient::ERROR_NOT_FOUND, $steps['vulnerabilities']['error']);
        self::assertSame(5, $steps['threats']['new']);
        self::assertNotNull(SentinelOneConfig::lastRun());
    }

    public function testRateLimitKeepsWhatWasReadAndResumesLater(): void
    {
        $client = $this->client(MockSentinelOne::RATE_LIMIT_TOKEN);
        $agents = $client->fetchAll('agents', [], static function (): void {
        });
        self::assertSame('', $agents['error']);

        // Page de 100 : les 5 menaces tiennent dans la première page, puis la limite tombe.
        $first = SentinelOneAlert::syncProposals($client, 'threats');
        self::assertSame('', $first['error']);

        self::assertSame(5, $first['new']);

        SentinelOneConfig::setSince('threats', '');
        $paged = new SentinelOneClient(self::BASE, MockSentinelOne::RATE_LIMIT_TOKEN, $this->pagedTransport(2));
        $fetch = $paged->fetchAll('threats', [], static function (): void {
        });
        self::assertSame(SentinelOneClient::ERROR_RATE_LIMITED, $fetch['error']);
        self::assertSame(2, $fetch['items']);

        // Un passage complet arrêté par la limite ne lève rien et garde son bilan.
        $steps = SentinelOneAlert::synchronize($paged);
        self::assertContains(SentinelOneClient::ERROR_RATE_LIMITED, array_column($steps, 'error'));
        self::assertSame(SentinelOneAlert::SKIPPED, $steps['vulnerabilities']['error']);
    }

    /**
     * Transport qui impose une petite taille de page (pour atteindre la deuxième page).
     */
    private function pagedTransport(int $limit): \Closure
    {
        $mock = new MockSentinelOne();

        return static function (string $url, array $headers) use ($mock, $limit): array {
            $parts = parse_url($url);
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            $query['limit'] = (string) $limit;

            return $mock->handle('GET', (string) $parts['path'], array_map('strval', $query), $headers);
        };
    }

    public function testProposalsAreScopedToTheActiveEntities(): void
    {
        $entityA = $this->createTestEntity(0, 'Entité S1 A ' . random_int(1000, 9999));
        $entityB = $this->createTestEntity(0, 'Entité S1 B ' . random_int(1000, 9999));
        $this->computer('PORTABLE-RH-' . random_int(1000, 9999), 'SN-FICTIF-RH-01', $entityA);
        \Config::setConfigurationValues(SentinelOneConfig::CONTEXT, [
            SentinelOneConfig::ENTITY_KEY => (string) $entityB,
        ]);

        SentinelOneAlert::synchronize($this->client(MockSentinelOne::VIEWER_TOKEN));
        self::assertSame(1, countElementsInTable(SentinelOneAlert::getTable(), ['entities_id' => $entityA]));

        // Session limitée à l'entité B : la proposition de l'entité A n'est ni vue ni importable.
        $saved = [
            $_SESSION['glpiactiveentities'],
            $_SESSION['glpiactiveentities_string'],
            $_SESSION['glpiactive_entity'],
        ];
        $_SESSION['glpiactiveentities'] = [$entityB];
        $_SESSION['glpiactiveentities_string'] = "'" . $entityB . "'";
        $_SESSION['glpiactive_entity'] = $entityB;
        try {
            $pending = SentinelOneAlert::pendingForCurrentUser();
            self::assertNotContains($entityA, array_map('intval', array_column($pending['rows'], 'entities_id')));
            self::assertSame(
                countElementsInTable(SentinelOneAlert::getTable(), [
                    'entities_id' => $entityB,
                    'status'      => SentinelOneAlert::STATUS_PENDING,
                ]),
                $pending['total']
            );
            self::assertGreaterThan(0, $pending['total']);

            $inA = new SentinelOneAlert();
            self::assertTrue($inA->getFromDBByCrit(['entities_id' => $entityA]));
            $result = SentinelOneAlert::importProposals([(int) $inA->getID()]);
            self::assertSame([], $result['created']);
            self::assertSame(0, SentinelOneAlert::ignoreProposals([(int) $inA->getID()]));

            // Une proposition de l'entité B devient un incident de l'entité B.
            $inB = new SentinelOneAlert();
            // Plusieurs propositions dans l'entité B : la première suffit.
            $criteria = ['entities_id' => $entityB, 'status' => SentinelOneAlert::STATUS_PENDING];
            $firstInB = $inB->find($criteria, ['id'], 1);
            self::assertNotSame([], $firstInB);
            self::assertTrue($inB->getFromDB((int) array_key_first($firstInB)));
            $created = SentinelOneAlert::importProposals([(int) $inB->getID()])['created'];
            self::assertCount(1, $created);
            $incident = new PluginGrcmanagerSecurityIncident();
            self::assertTrue($incident->getFromDB($created[0]));
            self::assertSame($entityB, (int) $incident->fields['entities_id']);
        } finally {
            $_SESSION['glpiactiveentities'] = $saved[0];
            $_SESSION['glpiactiveentities_string'] = $saved[1];
            $_SESSION['glpiactive_entity'] = $saved[2];
        }
    }

    public function testVulnerabilitiesAndCoverageOfVisibleComputers(): void
    {
        $suffix = (string) random_int(1000, 9999);
        $this->computer('PORTABLE-RH-' . $suffix, 'SN-FICTIF-RH-01');
        \Config::setConfigurationValues(SentinelOneConfig::CONTEXT, [SentinelOneConfig::TOKEN_KEY => 'stored']);

        $steps = SentinelOneAlert::synchronize($this->client(MockSentinelOne::VIEWER_TOKEN));
        // Six lignes lues, dont une sans CVE valide, ignorée.
        self::assertSame(6, $steps['vulnerabilities']['items']);
        self::assertSame(5, countElementsInTable(SentinelOneAlert::VULNERABILITIES_TABLE));

        $vulnerabilities = SentinelOneEvidenceProvider::fetchVulnerabilities([]);
        self::assertNotNull($vulnerabilities);
        $cves = array_column($vulnerabilities['only'], 'cve_id');
        self::assertContains('CVE-2024-38063', $cves);

        $known = SentinelOneEvidenceProvider::fetchVulnerabilities(['CVE-2024-38063' => true]);
        self::assertNotNull($known);
        self::assertGreaterThanOrEqual(1, $known['also']['CVE-2024-38063'] ?? 0);
        self::assertNotContains('CVE-2024-38063', array_column($known['only'], 'cve_id'));

        $coverage = SentinelOneEvidenceProvider::fetchCoverage();
        self::assertNotNull($coverage);
        self::assertSame(4, $coverage['agents']);
        self::assertSame(3, $coverage['active']);
        self::assertGreaterThanOrEqual(1, $coverage['covered']);
    }
}
