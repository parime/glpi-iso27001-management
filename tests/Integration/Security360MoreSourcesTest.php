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

use GlpiPlugin\Grcmanager\AssetClassification;
use GlpiPlugin\Grcmanager\AssetSecurity360;
use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\Feature\Security360MoreSourcesFeature as Sources;
use GlpiPlugin\Grcmanager\LogCoverage;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules as Rules;

/**
 * Issue #837 : sources restantes de la vue Sécurité 360° (sauvegardes, journalisation, garantie,
 * classification) : discrètes sans données, état avec données, droits et entités de la session.
 */
final class Security360MoreSourcesTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private string $suffix;

    private \Computer $server;

    private \Computer $hidden;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Vue 360 ' . $this->suffix);
        $other = $this->createTestEntity(0, 'PhpUnitDemo Vue 360 cachee ' . $this->suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;
        $this->reset();

        $serverType = (int) (new \ComputerType())->import(['name' => 'Server']);
        $this->server = $this->computer('srv', $serverType, $this->entity);
        $this->hidden = $this->computer('srv-cache', $serverType, $other);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        BackupCoverage::reset();
        LogCoverage::reset();
        AssetSecurity360::reset();
    }

    private function host(string $name): string
    {
        return 'phpunitdemo-360-' . $name . '-' . $this->suffix;
    }

    private function computer(string $name, int $type, int $entity): \Computer
    {
        $computer = new \Computer();
        $id = (int) $computer->add([
            'name' => $this->host($name), 'entities_id' => $entity, 'computertypes_id' => $type,
        ]);
        $this->assertGreaterThan(0, $id);
        $this->assertTrue($computer->getFromDB($id));

        return $computer;
    }

    public function testSourcesStayDiscreetWithoutData(): void
    {
        $this->assertNull(Sources::backups($this->server), 'Aucun outil de sauvegarde importé.');
        $this->assertNull(Sources::warranty($this->server), 'Ni garantie renseignée ni fin de vie.');
        $keys = array_column(AssetSecurity360::tabData($this->server)['sources'] ?? [], 'key');
        $this->assertNotContains('backups', $keys);
        $this->assertNotContains('warranty', $keys);
    }

    public function testSourcesWithData(): void
    {
        global $DB;

        $recent = date('Y-m-d H:i:s', time() - 4 * 3600);
        $csv = "host;last success;status;job\n" . $this->host('srv') . ';' . $recent . ";Success;Serveurs\n"
            . $this->host('srv-cache') . ';' . $recent . ";Success;Serveurs\n";
        $this->assertSame([], BackupCoverage::import('acronis', $csv)['errors']);
        $backups = Sources::backups($this->server);
        $this->assertNotNull($backups);
        // Sauvegarde récente mais aucun test de restauration : à surveiller.
        $this->assertSame(Rules::STATUS_WARNING, $backups['status']);
        $this->assertSame(BackupCoverage::getSearchURL(), $backups['url']);

        // GLPI peut créer les informations financières avec l'ordinateur (réglage de l'instance).
        $infocom = new \Infocom();
        $warrantyFields = ['warranty_date' => date('Y-m-d', strtotime('-3 years')), 'warranty_duration' => 12];
        if ($infocom->getFromDBforDevice(\Computer::class, $this->server->getID())) {
            $this->assertTrue($infocom->update(['id' => $infocom->getID()] + $warrantyFields));
        } else {
            $this->assertGreaterThan(0, (int) $infocom->add([
                'itemtype' => \Computer::class, 'items_id' => $this->server->getID(), 'entities_id' => $this->entity,
            ] + $warrantyFields));
        }
        $warranty = Sources::warranty($this->server);
        $this->assertNotNull($warranty);
        $this->assertSame(Rules::STATUS_WARNING, $warranty['status'], 'Garantie expirée.');

        $DB->insert(AssetClassification::getTable(), [
            'itemtype' => \Computer::class, 'items_id' => $this->hidden->getID(), 'confidentiality' => 'high',
        ]);
        $unclassified = Sources::classification($this->server);
        $this->assertNotNull($unclassified);
        $this->assertSame(Rules::STATUS_WARNING, $unclassified['status'], 'Actif pas encore classé.');
        $DB->insert(AssetClassification::getTable(), [
            'itemtype' => \Computer::class, 'items_id' => $this->server->getID(), 'confidentiality' => 'high',
            'integrity' => 'medium', 'availability' => 'low', 'rto_hours' => 8,
        ]);
        $classified = Sources::classification($this->server);
        $this->assertNotNull($classified);
        $this->assertSame(Rules::STATUS_OK, $classified['status']);
        $this->assertStringContainsString(AssetClassification::getLevels()['high'], $classified['summary']);

        $keys = array_column(AssetSecurity360::tabData($this->server)['sources'], 'key');
        foreach (['backups', 'warranty', 'classification'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function testRightsAndEntities(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 4 * 3600);
        $csv = "host;last success\n" . $this->host('srv') . ';' . $recent . "\n"
            . $this->host('srv-cache') . ';' . $recent . "\n";
        BackupCoverage::import('acronis', $csv);
        $this->assertNotNull(Sources::backups($this->server));
        $this->assertNull(Sources::backups($this->hidden), 'Ordinateur hors des entités de la session.');
        $this->assertNull(Sources::patches($this->hidden), 'Ordinateur hors des entités de la session.');

        $right = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        $this->reset();
        try {
            $this->assertNull(Sources::backups($this->server), 'Sans droit de lecture des ordinateurs.');
            $this->assertNull(Sources::logging($this->server));
            $this->assertNull(Sources::patches($this->server));
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $right;
        }

        $right = $_SESSION['glpiactiveprofile'][AssetClassification::$rightname] ?? 0;
        $_SESSION['glpiactiveprofile'][AssetClassification::$rightname] = 0;
        try {
            $this->assertNull(Sources::classification($this->server));
        } finally {
            $_SESSION['glpiactiveprofile'][AssetClassification::$rightname] = $right;
        }
    }
}
