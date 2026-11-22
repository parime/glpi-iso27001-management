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

use GlpiPlugin\Grcmanager\AuditorAccess;
use GlpiPlugin\Grcmanager\ReportedRisk;

/**
 * Issue #872 : « Mes signalements de risques ». Toute personne connectée, interface simplifiée
 * comprise et sans aucun droit sur le plugin, signale un risque ou une situation dangereuse et suit
 * ses propres signalements ; elle ne voit jamais le registre des risques. Le jeton CSRF est
 * contrôlé par GLPI sur tout POST.
 */
Session::checkLoginUser();
$usersId = (int) Session::getLoginUserID();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['report'])) {
        $result = ReportedRisk::submit($usersId, $_POST);
        if ($result['error'] === null) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Thank you, your report has been sent to the person in charge of risks. You will be told what '
                    . 'is decided.',
                'grcmanager'
            )));
        } else {
            Session::addMessageAfterRedirect(htmlescape(ReportedRisk::errorMessage($result['error'])), false, ERROR);
        }
    }
    Html::back();
}

AuditorAccess::header(__('Report a risk', 'grcmanager'), ReportedRisk::getMineURL());
\GlpiPlugin\Grcmanager\PageHelp::show('reportedrisk.mine');
ReportedRisk::showMine($usersId);
AuditorAccess::footer();
