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

use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\SoftwareLicenseCompliance;

// Issue #779 : constats des licences logicielles, un par ligne, calculés à la demande avec les droits
// et les entités de la personne (rien n'est conservé). Cellules protégées contre l'injection de
// formules (ConsistencyRules::csvCell()).
Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Software::$rightname, READ);
Session::checkRight(SoftwareLicense::$rightname, READ);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="licences-logicielles-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
foreach (SoftwareLicenseCompliance::csvRows() as $row) {
    fputcsv($out, array_map([ConsistencyRules::class, 'csvCell'], $row), ';', '"', '');
}
fclose($out);
exit;
