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

use GlpiPlugin\Grcmanager\EndOfLife;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;

// Issue #704 : fin de support des éléments suivis, un élément par ligne, calculée à la demande avec
// les droits et les entités de la personne (rien n'est conservé).
Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Software::$rightname, READ);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="fin-de-support-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
foreach (EndOfLife::csvRows() as $row) {
    fputcsv($out, array_map([ConsistencyRules::class, 'csvCell'], $row), ';', '"', '');
}
fclose($out);
exit;
