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

use GlpiPlugin\Grcmanager\HardwareWarranty;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;

// Issue #782 : fin de garantie du matériel, un matériel par ligne, calculée à la demande avec les
// droits et les entités de la personne (rien n'est conservé). Droit des registres sensibles du
// plugin et lecture native des informations financières ; chaque type de matériel n'est lu que si
// la personne a le droit de le lire. Cellules protégées contre l'injection de formules.
Session::checkRight(\GlpiPlugin\Grcmanager\ParkVulnerability::RIGHTNAME, READ);
Session::checkRight(Infocom::$rightname, READ);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="fin-de-garantie-materiel-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel affiche correctement les accents.
foreach (HardwareWarranty::csvRows() as $row) {
    fputcsv($out, array_map([ConsistencyRules::class, 'csvCell'], $row), ';', '"', '');
}
fclose($out);
exit;
