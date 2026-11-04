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

use Glpi\Search\Input\QueryBuilder;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;

include('../../../inc/includes.php');

// Issue #377 : registre des processus métier et export pour le plan de continuité.
Session::checkRight(\GlpiPlugin\Grcmanager\BusinessProcess::$rightname, READ);

if (($_GET['export'] ?? '') === 'csv') {
    // Seuls les processus lisibles (droit et entité) sont exportés, voir continuityRows().
    \GlpiPlugin\Grcmanager\AuditLog::record('export', \GlpiPlugin\Grcmanager\BusinessProcess::class);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="processus-metier-bia-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach (\GlpiPlugin\Grcmanager\BusinessProcess::continuityRows() as $line) {
        // Une cellule commençant par = + - @ serait interprétée comme une formule par le tableur.
        fputcsv($out, array_map(
            static fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell,
            $line
        ), ';', '"', '');
    }
    fclose($out);
    exit;
}

Html::header(
    \GlpiPlugin\Grcmanager\BusinessProcess::getTypeName(Session::getPluralNumber()),
    \GlpiPlugin\Grcmanager\BusinessProcess::getSearchURL(),
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\BusinessProcess::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\AccessReview::class,
    \GlpiPlugin\Grcmanager\BusinessProcess::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('businessprocess');
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/business_process_list_header.html.twig', [
    'new_url'    => \GlpiPlugin\Grcmanager\BusinessProcess::canCreate()
        ? \GlpiPlugin\Grcmanager\BusinessProcess::getFormURL() : '',
    'export_url' => \GlpiPlugin\Grcmanager\BusinessProcess::getSearchURL() . '?export=csv',
]);

$params = QueryBuilder::manageParams(\GlpiPlugin\Grcmanager\BusinessProcess::class, $_GET);

Search::showList(
    \GlpiPlugin\Grcmanager\BusinessProcess::class,
    $params,
    DefaultSearchColumns::COLUMNS[\GlpiPlugin\Grcmanager\BusinessProcess::class]
);

Html::footer();
