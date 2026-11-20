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

use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\OfficialContact;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;

// Issue #771 : registre des contacts officiels en PDF, à imprimer pour l'avoir hors ligne pendant
// une crise. À la demande, jamais enregistré. Droit du contexte de l'organisation ; contacts des
// entités de la session seulement.
Session::checkRight(ContextItem::$rightname, READ);

$pdf = PdfRenderer::renderHtmlToPdf(OfficialContact::reportHtml());
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="contacts-officiels-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
