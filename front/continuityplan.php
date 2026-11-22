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

use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\PageHelp;

// Issue #1083 : plans de continuité et de reprise (PCA, PRA, PCI, PRI), A.5.29 et A.5.30. Droit de la
// continuité ; seuls les plans et processus des entités de la session sont lus.
Session::checkRight(ContinuityPlan::$rightname, READ);

Menu::header(
    ContinuityPlan::class,
    ContinuityPlan::getTypeName(Session::getPluralNumber()),
    ContinuityPlan::getSearchURL()
);
Menu::showSection(ContinuityPlan::class);
PageHelp::show('continuityplan');
ContinuityPlan::showRegister();

Html::footer();
