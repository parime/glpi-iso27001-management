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

use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;

/**
 * Issue #167 : avis du CERT-FR rapprochés des fournisseurs du registre et des logiciels du parc,
 * sans appel réseau (les avis sont posés comme les écrirait la synchronisation).
 */
final class SecurityWatchTest extends GrcmanagerIntegrationTestCase
{
    private string $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = 'Fournidemo' . random_int(1000, 9999);
        ParkVulnerabilitySync::storeCertFr([[
            'ref'       => 'CERTFR-2099-AVI-' . random_int(1000, 9999),
            'kind'      => 'avis',
            'title'     => 'Multiples vulnérabilités dans ' . $this->vendor . ' Viewer (fictif)',
            'link'      => 'https://cert.example/avis/fictif/',
            'summary'   => 'Avis fictif de démonstration.',
            'published' => date('Y-m-d H:i:s', strtotime('-2 days')),
        ]]);
    }

    private function supplierRisk(int $ownerId): \PluginGrcmanagerSupplierRisk
    {
        $supplierId = (int) (new \Supplier())->add(['name' => $this->vendor . ' SAS', 'entities_id' => 0]);
        $risk = new \PluginGrcmanagerSupplierRisk();
        $risk->getFromDB((int) $risk->add([
            'title'        => 'PHPUnit dépendance ' . $this->vendor,
            'suppliers_id' => $supplierId,
            'users_id'     => $ownerId,
            'entities_id'  => 0,
        ]));

        return $risk;
    }

    public function testSupplierTabListsNoticesAndOwnerIsNotifiedOnce(): void
    {
        global $DB;

        $risk = $this->supplierRisk((int) \Session::getLoginUserID());
        $this->assertSame([strtolower($this->vendor)], \PluginGrcmanagerSecurityWatch::keywordsFor($risk));

        $tab = (string) (new \PluginGrcmanagerSecurityWatch())->getTabNameForItem($risk);
        $this->assertStringContainsString(__('Veille sécurité', 'grcmanager'), $tab);
        ob_start();
        \PluginGrcmanagerSecurityWatch::displayTabContentForItem($risk);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('CERTFR-2099-AVI-', $html);
        $this->assertStringContainsString($this->vendor . ' Viewer', $html);

        $links = ['plugin_grcmanager_supplierrisks_id' => $risk->getID()];
        \PluginGrcmanagerSecurityWatch::notifyOwners();
        $this->assertSame(1, countElementsInTable(\PluginGrcmanagerSecurityWatch::NOTIFIED_TABLE, $links));
        \PluginGrcmanagerSecurityWatch::notifyOwners();
        $this->assertSame(1, countElementsInTable(\PluginGrcmanagerSecurityWatch::NOTIFIED_TABLE, $links), 'Une fois.');

        $this->assertSame(1, countElementsInTable('glpi_notifications', [
            'itemtype' => \PluginGrcmanagerSupplierRisk::class,
            'event'    => \PluginGrcmanagerSecurityWatch::NOTIFY_EVENT,
        ]), 'Notification installée.');
        $DB->delete(\PluginGrcmanagerSecurityWatch::NOTIFIED_TABLE, $links);
    }

    public function testRiskWithoutMatchingNoticeShowsNothing(): void
    {
        $this->vendor = 'Inconnudemo' . random_int(1000, 9999);
        $risk = $this->supplierRisk(0);
        $keywords = \PluginGrcmanagerSecurityWatch::keywordsFor($risk);
        $this->assertSame([], \PluginGrcmanagerSecurityWatch::noticesFor($keywords, 90));
    }

    public function testParkRowGetsTheCertFrBadge(): void
    {
        $this->assertStringStartsWith('CERTFR-2099-AVI-', (string) ($this->parkBadge($this->vendor)['ref'] ?? ''));
    }

    public function testVendorWideNoticeCoversItsSoftware(): void
    {
        $vendor = 'Gammedemo' . random_int(1000, 9999);
        ParkVulnerabilitySync::storeCertFr([[
            'ref'       => 'CERTFR-2099-AVI-' . random_int(10000, 99999),
            'kind'      => 'avis',
            'title'     => 'Vulnérabilité dans les produits ' . $vendor . ' (fictif)',
            'link'      => 'https://cert.example/avis/gamme/',
            'summary'   => '',
            'published' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]]);
        $this->assertNotNull($this->parkBadge($vendor), '« produits Éditeur » couvre ses logiciels.');
    }

    /**
     * Logiciel « <éditeur> Viewer » installé, surveillé et vulnérable : badge CERT-FR de sa ligne.
     *
     * @return array{ref: string, link: string, title: string}|null
     */
    private function parkBadge(string $vendor): ?array
    {
        global $DB;

        $software = (int) (new \Software())->add(['name' => $vendor . ' Viewer', 'entities_id' => 0]);
        $version = (int) (new \SoftwareVersion())->add([
            'name'         => '1.0',
            'softwares_id' => $software,
            'entities_id'  => 0,
        ]);
        $computer = (int) (new \Computer())->add(['name' => 'PC demo veille', 'entities_id' => 0]);
        (new \Item_SoftwareVersion())->add([
            'items_id'            => $computer,
            'itemtype'            => \Computer::class,
            'softwareversions_id' => $version,
            'entities_id'         => 0,
        ]);
        $id = (int) \PluginGrcmanagerParkVulnerability::confirm(
            $vendor . ' Viewer',
            'cpe:2.3:a:' . strtolower($vendor) . ':viewer:1.0:*:*:*:*:*:*:*'
        );
        $cve = 'CVE-2099-8' . random_int(10000, 99999);
        ParkVulnerabilitySync::replaceParkCves($id, '1.0', [$cve]);

        $rows = array_values(array_filter(
            \PluginGrcmanagerParkVulnerability::getVulnerabilityRows(),
            static fn (array $row): bool => $row['cve_id'] === $cve
        ));
        $DB->delete(ParkVulnerabilitySync::PARK_CVES_TABLE, ['cve_id' => $cve]);
        $this->assertCount(1, $rows);

        return $rows[0]['certfr'];
    }
}
