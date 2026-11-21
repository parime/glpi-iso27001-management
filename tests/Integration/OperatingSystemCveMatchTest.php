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

use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\Services\Cve\CpeVersionMatch;
use GlpiPlugin\Grcmanager\Services\Cve\InventoryCveMatcher;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;

/**
 * Issue #780 : systèmes d'exploitation (Item_OperatingSystem) rapprochés des CVE par alias déclaré,
 * dans l'onglet CVE, dans « Vulnérabilités du parc » et dans les tickets de mise à jour, sans
 * sortir des entités de l'utilisateur ni de l'entité du ticket. Ordinateurs, système et CVE
 * fictifs, sans appel réseau.
 */
final class OperatingSystemCveMatchTest extends GrcmanagerIntegrationTestCase
{
    private const PREFIX = 'cpe:2.3:o:microsoft:windows_11_23h2';

    private string $osName;

    private string $designation;

    private string $cve;

    private int $hiddenEntity;

    /** @var list<int> */
    private array $savedEntities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEntities = $_SESSION['glpiactiveentities'];
        // Nom fictif reconnu comme Windows 11 (#704) : la version 23H2 entre dans la désignation.
        $this->osName = 'Microsoft Windows 11 PhpUnitDemo ' . random_int(1000, 9999);
        $this->designation = $this->osName . ' 23H2';
        $this->cve = 'CVE-2099-978' . random_int(10, 99);
        // Entité B hors des entités actives : ses ordinateurs ne doivent jamais apparaître.
        $this->hiddenEntity = $this->createTestEntity(0, 'PhpUnitDemo entité B ' . random_int(1000, 9999));
        $this->setActiveEntities(array_values(array_diff($_SESSION['glpiactiveentities'], [$this->hiddenEntity])));
    }

    protected function tearDown(): void
    {
        $this->setActiveEntities($this->savedEntities);
        parent::tearDown();
    }

    /**
     * @param list<int> $entities
     */
    private function setActiveEntities(array $entities): void
    {
        $_SESSION['glpiactiveentities'] = $entities;
        $_SESSION['glpiactiveentities_string'] = implode("','", $entities);
    }

    /**
     * Identifiant d'un intitulé de GLPI (système, version, noyau), créé au besoin.
     *
     * @param class-string<\CommonDropdown> $class
     */
    private function dropdown(string $class, string $name): int
    {
        $item = new $class();

        return $item->getFromDBByCrit(['name' => $name]) ? (int) $item->getID() : (int) $item->add(['name' => $name]);
    }

    /**
     * Ordinateur fictif sous ce système, en version 23H2, avec ce build de noyau.
     */
    private function computerWithOs(string $kernel, int $entityId = 0): int
    {
        $computerId = (int) (new \Computer())->add([
            'name'        => 'PC demo OS ' . random_int(1, 999999),
            'entities_id' => $entityId,
        ]);
        (new \Item_OperatingSystem())->add([
            'itemtype'                         => \Computer::class,
            'items_id'                         => $computerId,
            'operatingsystems_id'              => $this->dropdown(\OperatingSystem::class, $this->osName),
            'operatingsystemversions_id'       => $this->dropdown(\OperatingSystemVersion::class, '23H2'),
            'operatingsystemkernelversions_id' => $this->dropdown(\OperatingSystemKernelVersion::class, $kernel),
            'entities_id'                      => $entityId,
        ]);

        return $computerId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function osMatches(): array
    {
        return array_values(array_filter(
            InventoryCveMatcher::findMatchingAssets([[
                'cpe23_uri'               => self::PREFIX . ':*:*:*:*:*:*:x64:*',
                'version_start_including' => null,
                'version_start_excluding' => null,
                'version_end_including'   => null,
                'version_end_excluding'   => '10.0.22631.4317',
            ]]),
            fn (array $row): bool => $row['software_name'] === $this->designation
        ));
    }

    /**
     * Le CPE proposé par défaut, accepté comme le ferait l'administrateur.
     */
    private function acceptProposal(): int
    {
        $rows = ParkVulnerability::getSoftwareRows('todo', $this->designation);
        $this->assertSame([$this->designation], array_column($rows, 'name'));
        $this->assertSame(self::PREFIX, $rows[0]['proposed'], 'Correspondance proposée pour Windows 11 23H2.');

        return (int) ParkVulnerability::confirm($this->designation, $rows[0]['proposed']);
    }

    public function testOperatingSystemLinkedByAliasIsMatchedOnlyInTheUserEntities(): void
    {
        $vulnerable = $this->computerWithOs('10.0.22631.4169');
        $noRevision = $this->computerWithOs('10.0.22631');
        $patched = $this->computerWithOs('10.0.22631.4460');
        $hidden = $this->computerWithOs('10.0.22631.4169', $this->hiddenEntity);

        $this->assertSame([], $this->osMatches(), 'Sans alias validé, aucun rapprochement par le nom.');
        $this->assertGreaterThan(0, $this->acceptProposal());

        $matches = $this->osMatches();
        $this->assertSame([
            [
                'itemtype' => \Computer::class, 'items_id' => $vulnerable, 'version' => '10.0.22631.4169',
                'source' => 'os', 'state' => CpeVersionMatch::MATCHED,
            ],
            [
                'itemtype' => \Computer::class, 'items_id' => $noRevision, 'version' => '10.0.22631',
                'source' => 'os', 'state' => CpeVersionMatch::UNKNOWN,
            ],
        ], array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
            'itemtype', 'items_id', 'version', 'source', 'state',
        ])), $matches), 'Build corrigé écarté, révision inconnue non vérifiable, entité B jamais nommée.');
        $this->assertNotContains($patched, array_column($matches, 'items_id'));
        $this->assertNotContains($hidden, array_column($matches, 'items_id'));

        // Sans le droit de lire les ordinateurs, aucun n'est nommé.
        $right = $_SESSION['glpiactiveprofile'][\Computer::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][\Computer::$rightname] = 0;
        try {
            $this->assertSame([], $this->osMatches());
        } finally {
            $_SESSION['glpiactiveprofile'][\Computer::$rightname] = $right;
        }
    }

    public function testFleetVulnerabilitiesCountOperatingSystemsInTheUserEntities(): void
    {
        $visible = $this->computerWithOs('10.0.22631.4169');
        $this->computerWithOs('10.0.22631');
        $hidden = $this->computerWithOs('10.0.22631.4169', $this->hiddenEntity);
        $canonicalId = $this->acceptProposal();

        $rows = ParkVulnerability::getSoftwareRows('watched', $this->designation);
        $this->assertSame(['os'], $rows[0]['sources']);
        $this->assertSame(2, $rows[0]['installs'], 'Ordinateur de l\'entité B non compté.');
        $this->assertSame('', $rows[0]['proposed'], 'Déjà surveillé : plus de proposition.');

        $due = array_values(array_filter(
            ParkVulnerabilitySync::dueVersions(1000),
            static fn (array $v): bool => $v['canonical_id'] === $canonicalId
        ));
        $this->assertSame(['10.0.22631.4169'], array_column($due, 'version'), 'Build sans révision non interrogé.');

        ParkVulnerabilitySync::replaceParkCves($canonicalId, '10.0.22631.4169', [$this->cve]);
        $rows = array_values(array_filter(
            ParkVulnerability::getVulnerabilityRows(),
            fn (array $row): bool => $row['cve_id'] === $this->cve
        ));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['machines']);
        $this->assertSame(['os'], $rows[0]['sources']);

        $all = array_values(array_filter(
            ParkVulnerability::getVulnerabilityRows(false, []),
            fn (array $row): bool => $row['cve_id'] === $this->cve
        ));
        $this->assertSame(2, $all[0]['machines'], 'Tâche planifiée : tout le parc.');

        $mine = ParkVulnerabilitySync::machinesFor(
            $canonicalId,
            ['10.0.22631.4169'],
            getEntitiesRestrictCriteria('glpi_items_softwareversions')
        );
        $this->assertSame([$visible], array_column($mine, 'items_id'));
        $this->assertEqualsCanonicalizing(
            [$visible, $hidden],
            array_column(ParkVulnerabilitySync::machinesFor($canonicalId, ['10.0.22631.4169']), 'items_id')
        );

        // Onglet « Vulnérabilités » de l'ordinateur.
        $computer = new \Computer();
        $this->assertTrue($computer->getFromDB($visible));
        $tab = ParkVulnerability::getForItem($computer);
        $this->assertSame([$this->cve], array_column($tab, 'cve_id'));
        $this->assertSame('os', $tab[0]['source']);

        // Lot 2 : le classement « Machines les plus exposées » compte aussi le système.
        $ranked = array_column(array_filter(
            ParkVulnerability::getMachineRanking(1000)['rows'],
            static fn (array $row): bool => $row['itemtype'] === \Computer::class
        ), 'cves', 'items_id');
        $this->assertSame(1, $ranked[$visible] ?? null, 'Exposé par son système d\'exploitation.');
        $this->assertArrayNotHasKey($hidden, $ranked, 'Entité B jamais classée.');
    }

    /**
     * Règle de #702 : le ticket de mise à jour lie les ordinateurs concernés de son entité, puis
     * ceux qui le deviennent ; jamais ceux d'une autre entité.
     */
    public function testUpdateTicketLinksTheAffectedComputersOfItsEntity(): void
    {
        global $DB;

        $saved = ParkVulnerability::getTicketThresholds();
        try {
            ParkVulnerability::saveTicketThresholds([
                'park_tickets_enabled'     => 1,
                'park_ticket_cvss'         => 10,
                'park_ticket_epss_percent' => 100,
            ]);
            $first = [$this->computerWithOs('10.0.22631.4169'), $this->computerWithOs('10.0.22631.4169')];
            $hidden = $this->computerWithOs('10.0.22631.4169', $this->hiddenEntity);
            $canonicalId = $this->acceptProposal();
            ParkVulnerabilitySync::replaceParkCves($canonicalId, '10.0.22631.4169', [$this->cve]);
            $DB->insert('glpi_plugin_grcmanager_cveenrichments', [
                'cve_id' => $this->cve, 'cvss_score' => 10.0, 'severity' => 'X', 'fetch_status' => 'ok',
            ]);
            $linked = function () use ($DB, $canonicalId): array {
                $ticket = $DB->request([
                    'FROM'  => ParkVulnerabilitySync::TICKETS_TABLE,
                    'WHERE' => ['plugin_grcmanager_canonicalproducts_id' => $canonicalId],
                ])->current();
                $this->assertNotNull($ticket, 'Un ticket pour le système au-dessus du seuil.');
                $items = [];
                foreach (
                    $DB->request([
                        'FROM'  => 'glpi_items_tickets',
                        'WHERE' => ['tickets_id' => (int) $ticket['tickets_id']],
                    ]) as $row
                ) {
                    $items[] = $row['itemtype'] . ':' . $row['items_id'];
                }

                return $items;
            };
            $keys = static fn (array $ids): array => array_map(
                static fn (int $id): string => \Computer::class . ':' . $id,
                $ids
            );

            ParkVulnerability::processTickets();
            $this->assertEqualsCanonicalizing($keys($first), $linked(), 'Entité B jamais liée.');

            $late = $this->computerWithOs('10.0.22631.4169');
            ParkVulnerability::processTickets();
            $this->assertEqualsCanonicalizing($keys([...$first, $late]), $linked());
            $this->assertNotContains(\Computer::class . ':' . $hidden, $linked());
        } finally {
            ParkVulnerability::saveTicketThresholds([
                'park_tickets_enabled'     => $saved['enabled'] ? 1 : 0,
                'park_ticket_kev'          => $saved['kev'] ? 1 : 0,
                'park_ticket_cvss'         => $saved['cvss'],
                'park_ticket_epss_percent' => $saved['epss'] * 100,
            ]);
        }
    }
}
