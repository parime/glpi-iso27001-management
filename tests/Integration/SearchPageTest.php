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

use Glpi\Search\Input\QueryBuilder;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;
use GlpiPlugin\Grcmanager\Services\Ui\SearchPage;

/**
 * Issue #1093 : la liste des processus métier affiche la recherche complète, quelle que soit la
 * préférence « Afficher le formulaire de recherche au-dessus des résultats ».
 */
final class SearchPageTest extends GrcmanagerIntegrationTestCase
{
    private mixed $previousPreference = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPreference = $_SESSION['glpishow_search_form'] ?? null;
    }

    protected function tearDown(): void
    {
        $_SESSION['glpishow_search_form'] = $this->previousPreference;
        parent::tearDown();
    }

    private function render(int $showSearchForm): string
    {
        $_SESSION['glpishow_search_form'] = $showSearchForm;
        $params = QueryBuilder::manageParams(BusinessProcess::class, []);

        ob_start();
        SearchPage::show(BusinessProcess::class, $params, DefaultSearchColumns::COLUMNS[BusinessProcess::class]);

        return (string) ob_get_clean();
    }

    public function testSearchFormAboveResults(): void
    {
        $html = $this->render(1);

        // Le bouton « Enregistrer la recherche » n'agit que dans un conteneur .search-container.
        $this->assertStringContainsString('data-glpi-search-container', $html);
        $this->assertStringContainsString('saved-searches-panel', $html);
        $this->assertStringContainsString('save_bookmark_record', $html);
        $this->assertStringNotContainsString('show-search-filters', $html);
    }

    public function testSearchFormInTheFilterMenu(): void
    {
        $html = $this->render(0);

        $this->assertStringContainsString('data-glpi-search-container', $html);
        $this->assertStringContainsString('saved-searches-panel', $html);
        $this->assertStringContainsString('show-search-filters', $html);
        $this->assertStringContainsString('save_bookmark_record', $html);
    }
}
