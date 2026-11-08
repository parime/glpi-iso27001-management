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

use GlpiPlugin\Grcmanager\MySpace;

// Issue #539 : « Mon espace GRC ». Comme « Mes revues d'accès », une session suffit : la page ne
// montre que ce qui est confié à l'utilisateur connecté, et chaque source n'est lue que si ses
// droits le permettent (MySpaceProvider). Lecture seule.
Session::checkLoginUser();

Html::header(MySpace::getTypeName(), MySpace::getSearchURL(), 'grcmanager', MySpace::class);
\GlpiPlugin\Grcmanager\PageHelp::show('myspace');
MySpace::show();
Html::footer();
