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
use GlpiPlugin\Grcmanager\DoraRegister;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Navigation\Section\SupplierSecuritySection;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\SupplierQuestionnaireTemplate;
use GlpiPlugin\Grcmanager\SupplierRisk;
use GlpiPlugin\Grcmanager\SupplierSecurityTab;
use Supplier;

/**
 * Issue #1040 : « Gestion > Sécurité des fournisseurs » (risques fournisseurs, questionnaires,
 * registre DORA) juste après Fournisseurs, et onglet « Sécurité » de la fiche Fournisseur.
 */
final class SupplierSecuritySectionTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sections::reset();
    }

    protected function tearDown(): void
    {
        Sections::reset();
        parent::tearDown();
    }

    public function testSupplierScreensAreInTheManagementSector(): void
    {
        $this->assertSame('management', SupplierSecuritySection::sector());
        $this->assertSame(['management', SupplierRisk::class], Menu::sectorPath(SupplierRisk::class));
        $pages = array_keys(Sections::pages(SupplierRisk::class));
        $this->assertSame([SupplierQuestionnaireTemplate::class, DoraRegister::class], $pages);
        $this->assertLessThanOrEqual(Sections::MAX_PAGES, count($pages));
        foreach ($pages as $page) {
            $this->assertSame(['management', SupplierRisk::class, $page], Menu::sectorPath($page), $page);
        }
        // Retirés des rubriques Risques et Mesures de sécurité du menu GRC.
        $this->assertNotContains(SupplierRisk::class, array_keys(Sections::pages(Risk::class)));
        $this->assertNotContains(DoraRegister::class, array_keys(Sections::pages(Control::class)));
        $this->assertContains(SupplierRisk::class, Sections::menuToAdd()['management'] ?? []);
    }

    public function testSectionEntryHasItsTitleAndHomePage(): void
    {
        $menu = SupplierRisk::getMenuContent();
        $this->assertIsArray($menu);
        $this->assertSame(SupplierSecuritySection::title(), $menu['title']);
        $this->assertSame(Sections::hubUrl(SupplierSecuritySection::class), $menu['page']);
        $this->assertStringContainsString('supplierrisk.php', (string) $menu['section_page']);
        $this->assertArrayHasKey(SupplierQuestionnaireTemplate::class, $menu['options'] ?? []);
    }

    public function testEntryFollowsSuppliersInTheManagementMenu(): void
    {
        $key = strtolower(SupplierRisk::class);
        $menu = ['management' => ['content' => [
            'softwarelicense' => [],
            'supplier'        => [],
            'contact'         => [],
            'contract'        => [],
            $key              => [],
        ]]];
        $this->assertSame(
            ['softwarelicense', 'supplier', $key, 'contact', 'contract'],
            array_keys(Sections::reorderMenu($menu)['management']['content'])
        );
    }

    public function testSupplierTabListsItsRisksWithLevelAndNextReview(): void
    {
        $suppliersId = (int) (new Supplier())->add(['name' => 'PHPUnit supplier 1040', 'entities_id' => 0]);
        $otherId = (int) (new Supplier())->add(['name' => 'PHPUnit other supplier 1040', 'entities_id' => 0]);
        $risk = new SupplierRisk();
        $soon = date('Y-m-d', strtotime('+20 days'));
        $later = date('Y-m-d', strtotime('+90 days'));
        foreach ([[$suppliersId, $later], [$suppliersId, $soon], [$otherId, $soon]] as [$supplier, $review]) {
            $this->assertGreaterThan(0, (int) $risk->add([
                'suppliers_id' => $supplier,
                'title'        => 'PHPUnit hosting ' . $review,
                'probability'  => 'probable',
                'impact'       => 'high',
                'users_id'     => (int) \Session::getLoginUserID(),
                'review_date'  => $review,
                'entities_id'  => 0,
            ]));
        }

        $data = SupplierSecurityTab::tabData($suppliersId, date('Y-m-d'));
        $this->assertCount(2, $data['risks'], 'only the risks of this supplier');
        $this->assertSame($soon, $data['next_review']);
        $this->assertFalse($data['review_overdue']);
        $this->assertNull($data['risks'][0]['last_evaluation']);
        $this->assertStringContainsString('suppliers_id=' . $suppliersId, (string) $data['create_url']);

        $overdue = SupplierSecurityTab::tabData($suppliersId, date('Y-m-d', strtotime('+30 days')));
        $this->assertTrue($overdue['review_overdue']);

        $supplier = new Supplier();
        $supplier->getFromDB($suppliersId);
        $this->assertNotSame('', (new SupplierSecurityTab())->getTabNameForItem($supplier));
    }
}
