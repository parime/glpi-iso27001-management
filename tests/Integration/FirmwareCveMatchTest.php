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
 * Issue #705 : firmwares des matériels réseau (Item_DeviceFirmware) rapprochés des CVE par alias
 * déclaré, dans l'onglet CVE comme dans « Vulnérabilités du parc », sans sortir des entités de
 * l'utilisateur. Matériels, firmwares et CVE fictifs, sans appel réseau.
 */
final class FirmwareCveMatchTest extends GrcmanagerIntegrationTestCase
{
    private const PREFIX = 'cpe:2.3:o:phpunitdemo:switchos';

    private const CVE = 'CVE-2099-97051';

    private string $designation;

    private int $hiddenEntity;

    /** @var list<int> */
    private array $savedEntities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEntities = $_SESSION['glpiactiveentities'];
        $this->designation = 'PhpUnitDemo SwitchOS ' . random_int(1000, 9999);
        // Entité B hors des entités actives : ses matériels ne doivent jamais apparaître.
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
     * Matériel réseau fictif qui porte ce firmware.
     */
    private function switchWithFirmware(string $version, int $entityId = 0): int
    {
        $firmware = new \DeviceFirmware();
        $firmwareId = $firmware->getFromDBByCrit(['designation' => $this->designation, 'version' => $version])
            ? (int) $firmware->getID()
            : (int) $firmware->add(['designation' => $this->designation, 'version' => $version, 'entities_id' => 0]);
        $switchId = (int) (new \NetworkEquipment())->add([
            'name'        => 'SW demo ' . random_int(1, 999999),
            'entities_id' => $entityId,
        ]);
        (new \Item_DeviceFirmware())->add([
            'itemtype'           => \NetworkEquipment::class,
            'items_id'           => $switchId,
            'devicefirmwares_id' => $firmwareId,
            'entities_id'        => $entityId,
        ]);

        return $switchId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function firmwareMatches(): array
    {
        return array_values(array_filter(
            InventoryCveMatcher::findMatchingAssets([[
                'cpe23_uri'               => self::PREFIX . ':*:*:*:*:*:*:*:*',
                'version_start_including' => '7.0.0',
                'version_start_excluding' => null,
                'version_end_including'   => null,
                'version_end_excluding'   => '7.0.14',
            ]]),
            fn (array $row): bool => $row['software_name'] === $this->designation
        ));
    }

    public function testFirmwareLinkedByAliasIsMatchedOnlyInTheUserEntities(): void
    {
        $visible = $this->switchWithFirmware('v7.0.12,build0523');
        $unreadable = $this->switchWithFirmware('vendor default');
        $patched = $this->switchWithFirmware('7.0.15');
        $hidden = $this->switchWithFirmware('v7.0.12,build0523', $this->hiddenEntity);

        $this->assertSame([], $this->firmwareMatches(), 'Sans alias déclaré, aucun rapprochement par le nom.');

        $canonicalId = (int) ParkVulnerability::confirm($this->designation, self::PREFIX);
        $this->assertGreaterThan(0, $canonicalId);

        $matches = $this->firmwareMatches();
        $this->assertSame([
            [
                'itemtype' => \NetworkEquipment::class, 'items_id' => $visible, 'version' => '7.0.12',
                'source' => 'firmware', 'state' => CpeVersionMatch::MATCHED,
            ],
            [
                'itemtype' => \NetworkEquipment::class, 'items_id' => $unreadable, 'version' => 'vendor default',
                'source' => 'firmware', 'state' => CpeVersionMatch::UNKNOWN,
            ],
        ], array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
            'itemtype', 'items_id', 'version', 'source', 'state',
        ])), $matches), 'Version corrigée écartée, matériel de l\'entité B jamais nommé.');
        $this->assertNotContains($patched, array_column($matches, 'items_id'));
        $this->assertNotContains($hidden, array_column($matches, 'items_id'));

        // Sans le droit de lire les matériels réseau, aucun n'est nommé.
        $right = $_SESSION['glpiactiveprofile'][\NetworkEquipment::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][\NetworkEquipment::$rightname] = 0;
        try {
            $this->assertSame([], $this->firmwareMatches());
        } finally {
            $_SESSION['glpiactiveprofile'][\NetworkEquipment::$rightname] = $right;
        }
    }

    public function testFleetVulnerabilitiesCountFirmwaresInTheUserEntities(): void
    {
        $visible = $this->switchWithFirmware('v7.0.12,build0523');
        $hidden = $this->switchWithFirmware('7.0.12', $this->hiddenEntity);
        $canonicalId = (int) ParkVulnerability::confirm($this->designation, self::PREFIX);

        $rows = ParkVulnerability::getSoftwareRows('watched', $this->designation);
        $this->assertSame([$this->designation], array_column($rows, 'name'));
        $this->assertSame(['firmware'], $rows[0]['sources']);
        $this->assertSame(1, $rows[0]['installs'], 'Matériel de l\'entité B non compté.');

        $due = array_values(array_filter(
            ParkVulnerabilitySync::dueVersions(1000),
            static fn (array $v): bool => $v['canonical_id'] === $canonicalId
        ));
        $this->assertSame(['7.0.12'], array_column($due, 'version'), 'Version normalisée envoyée à NVD.');

        ParkVulnerabilitySync::replaceParkCves($canonicalId, '7.0.12', [self::CVE]);
        $rows = array_values(array_filter(
            ParkVulnerability::getVulnerabilityRows(),
            static fn (array $row): bool => $row['cve_id'] === self::CVE
        ));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['machines']);
        $this->assertSame(['firmware'], $rows[0]['sources']);
        $this->assertSame(['7.0.12'], $rows[0]['versions']);

        // Tâche planifiée : tout le parc.
        $all = array_values(array_filter(
            ParkVulnerability::getVulnerabilityRows(false, []),
            static fn (array $row): bool => $row['cve_id'] === self::CVE
        ));
        $this->assertSame(2, $all[0]['machines']);

        // Machines liées au ticket : seulement celles de l'utilisateur pour une demande de sa part.
        $mine = ParkVulnerabilitySync::machinesFor(
            $canonicalId,
            ['7.0.12'],
            getEntitiesRestrictCriteria('glpi_items_softwareversions')
        );
        $this->assertSame([$visible], array_column($mine, 'items_id'));
        $this->assertEqualsCanonicalizing(
            [$visible, $hidden],
            array_column(ParkVulnerabilitySync::machinesFor($canonicalId, ['7.0.12']), 'items_id')
        );

        // Onglet « Vulnérabilités » du matériel réseau.
        $switch = new \NetworkEquipment();
        $this->assertTrue($switch->getFromDB($visible));
        $tab = ParkVulnerability::getForItem($switch);
        $this->assertSame([self::CVE], array_column($tab, 'cve_id'));
        $this->assertSame('firmware', $tab[0]['source']);
    }
}
