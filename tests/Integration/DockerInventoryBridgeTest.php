<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\ConfigurationCompliance;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Services\Control\DockerInventoryEvidence;
use GlpiPlugin\Grcmanager\Services\Control\DockerInventoryEvidenceProvider;

/**
 * Docker Inventory #10 : preuves du plugin Inventaire Docker sur les mesures A.5.9, A.8.8, A.8.9 et
 * A.8.32. Sans le plugin, rien ne s'affiche ; avec lui, les preuves citent les objets fictifs de
 * l'entité active et jamais ceux d'une entité non autorisée. Marqueurs et noms fictifs seulement,
 * jamais de libellé traduit (CI en anglais, instance de développement en français).
 */
final class DockerInventoryBridgeTest extends GrcmanagerIntegrationTestCase
{
    private const MARKER = 'id="grc-docker-evidence"';

    private function render(string $code): string
    {
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code]), $code);
        ob_start();
        $control->showForm($control->getID());

        return (string) ob_get_clean();
    }

    private static function dockerUsable(): bool
    {
        return \Plugin::isPluginActive(DockerInventoryEvidence::PLUGIN_KEY)
            && class_exists(DockerInventoryEvidence::API_CLASS);
    }

    public function testWithoutTheDockerInventoryPluginNothingIsShown(): void
    {
        if (self::dockerUsable()) {
            $this->markTestSkipped('Le plugin Inventaire Docker est actif sur cette instance.');
        }
        $this->assertFalse(DockerInventoryEvidenceProvider::isAvailable());
        $this->assertNull(DockerInventoryEvidenceProvider::fetchVulnerableImages());
        foreach (array_keys(DockerInventoryEvidence::CONTROLS) as $code) {
            $this->assertNull(DockerInventoryEvidenceProvider::fetchForControl($code), $code);
            $this->assertStringNotContainsString(self::MARKER, $this->render($code), $code);
        }
    }

    /**
     * Issue #916 : sans le plugin, la preuve de configuration à risque n'apparaît pas dans les
     * sources de conformité de configuration (A.8.9).
     */
    public function testWithoutTheDockerInventoryPluginNoHardeningSource(): void
    {
        if (self::dockerUsable()) {
            $this->markTestSkipped('Le plugin Inventaire Docker est actif sur cette instance.');
        }
        $this->assertNull(DockerInventoryEvidenceProvider::fetchHardening());
        $keys = array_column(ConfigurationCompliance::evidenceSources(), 'key');
        $this->assertNotContains('docker_hardening', $keys);
        $this->assertStringNotContainsString('data-config-source="docker_hardening"', $this->render('A.8.9'));
    }

    /**
     * Issue #916 : avec un plugin qui fournit Evidence::hardening(), la source apparaît avec le lien
     * vers la sous-page ; avec une version plus ancienne, elle n'apparaît pas.
     */
    public function testHardeningSourceFollowsThePluginApi(): void
    {
        if (!self::dockerUsable()) {
            $this->markTestSkipped('Le plugin Inventaire Docker n\'est pas actif sur cette instance.');
        }
        $keys = array_column(ConfigurationCompliance::evidenceSources(), 'key');
        if (!method_exists(DockerInventoryEvidence::API_CLASS, 'hardening')) {
            $this->assertNull(DockerInventoryEvidenceProvider::fetchHardening());
            $this->assertNotContains('docker_hardening', $keys);

            return;
        }
        $summary = DockerInventoryEvidenceProvider::fetchHardening();
        $this->assertNotNull($summary);
        $this->assertContains('docker_hardening', $keys);
        $this->assertStringContainsString('hardening', $summary['page_url']);
    }

    public function testEvidenceOfTheActiveEntityOnlyWhenThePluginIsPresent(): void
    {
        if (!self::dockerUsable()) {
            $this->markTestSkipped('Le plugin Inventaire Docker n\'est pas actif sur cette instance.');
        }
        global $DB;

        $suffix = bin2hex(random_bytes(4));
        $mine = $this->createTestEntity(0, 'PHPUnit Docker A ' . $suffix);
        // Entité soeur, hors des entités actives de la session.
        $other = $this->createTestEntity(0, 'PHPUnit Docker B ' . $suffix);
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        foreach (['a' => $mine, 'b' => $other] as $letter => $entity) {
            $name = $letter . '-' . $suffix;
            $DB->insert('glpi_plugin_dockerinventory_connections', [
                'name' => 'portainer-' . $name, 'entities_id' => $entity, 'type' => 'portainer',
                'url' => 'https://portainer-' . $letter . '.exemple.test', 'last_import_status' => 1,
                'last_success_date' => '2026-03-01 10:00:00',
            ]);
            $connection = (int) $DB->insertId();
            $DB->insert('glpi_plugin_dockerinventory_services', [
                'name' => 'web-' . $name, 'entities_id' => $entity, 'service_uid' => 'web-' . $name,
                'plugin_dockerinventory_connections_id' => $connection, 'stack_name' => 'boutique-' . $letter,
                'image' => 'nginx', 'tag' => '1.25.0', 'digest' => 'sha256:' . str_repeat($letter, 64),
                'replicas' => 2, 'running_replicas' => 2, 'ports' => '443:8443/tcp',
            ]);
            $service = (int) $DB->insertId();
            $DB->insert('glpi_plugin_dockerinventory_imageversions', [
                'entities_id' => $entity, 'plugin_dockerinventory_connections_id' => $connection,
                'plugin_dockerinventory_services_id' => $service, 'image' => 'nginx',
                'deployed_tag' => '1.25.0', 'latest_tag' => '2.0.0', 'gap' => 'major',
            ]);
            if ($DB->tableExists('glpi_plugin_dockerinventory_versionchanges')) {
                $DB->insert('glpi_plugin_dockerinventory_versionchanges', [
                    'entities_id' => $entity, 'plugin_dockerinventory_connections_id' => $connection,
                    'plugin_dockerinventory_services_id' => $service, 'service_name' => 'web-' . $name,
                    'kind' => 'tag', 'old_image' => 'nginx', 'new_image' => 'nginx', 'old_tag' => '1.24.0',
                    'new_tag' => '1.25.0', 'date_change' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }

        $this->assertTrue(DockerInventoryEvidenceProvider::isAvailable());
        $expected = [
            'A.5.9'  => 'portainer-a-' . $suffix,
            'A.8.8'  => 'web-a-' . $suffix,
            'A.8.9'  => 'web-a-' . $suffix,
            'A.8.32' => 'web-a-' . $suffix,
        ];
        foreach ($expected as $code => $name) {
            $html = $this->render($code);
            $this->assertStringContainsString(self::MARKER, $html, $code);
            $this->assertStringContainsString($name, $html, $code);
            $this->assertStringNotContainsString('-b-' . $suffix, $html, $code . ' : entité non autorisée citée');
        }
        $this->assertStringNotContainsString(self::MARKER, $this->render('A.8.16'), 'Mesure sans preuve Docker.');

        // Sans le droit de lecture des services Docker, plus rien.
        $right = $_SESSION['glpiactiveprofile']['plugin_dockerinventory_service'] ?? null;
        $_SESSION['glpiactiveprofile']['plugin_dockerinventory_service'] = 0;
        try {
            $this->assertFalse(DockerInventoryEvidenceProvider::isAvailable());
            $this->assertStringNotContainsString(self::MARKER, $this->render('A.8.9'));
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_dockerinventory_service'] = $right;
        }
    }
}
