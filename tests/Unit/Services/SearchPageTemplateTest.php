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

namespace GlpiPlugin\Grcmanager\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * Issue #1093 : Search::showList() seul affiche les résultats sans l'enveloppe de la recherche
 * (conteneur .search-container, panneau des recherches enregistrées, formulaire de critères au-dessus
 * des résultats). Recherche et enregistrement des critères ne fonctionnaient pas sur ces listes.
 */
final class SearchPageTemplateTest extends TestCase
{
    public function testNoFrontPageCallsSearchShowListDirectly(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (glob($root . '/front/*.php') ?: [] as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                $code = ltrim($line);
                if (str_starts_with($code, '//') || str_starts_with($code, '*')) {
                    continue;
                }
                if (str_contains($code, 'Search::showList(')) {
                    $found[] = basename($file) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $found, 'Use \GlpiPlugin\Grcmanager\Services\Ui\SearchPage::show() or Search::show().');
    }

    public function testBusinessProcessListUsesTheFullSearchPage(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 3) . '/front/businessprocess.php');

        self::assertStringContainsString('SearchPage::show(', $code);
    }

    public function testTemplateWrapsResultsLikeSearchEngineShow(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/_search_page.html.twig');

        $expected = [
            'search-container',
            'data-glpi-search-container',
            "include('layout/parts/saved_searches.html.twig'",
            "user_pref('show_search_form')",
            'QueryBuilder::showGenericSearch',
            'Search::showList',
        ];
        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $twig);
        }
    }
}
