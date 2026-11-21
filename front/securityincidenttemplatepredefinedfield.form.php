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

// Issue #951 : sans ce fichier, le formulaire « Ajouter » de l'onglet du gabarit partait vers le
// contrôleur générique de GLPI, qui affichait ensuite la fiche du champ ajouté au lieu de revenir
// au gabarit. Délègue au contrôleur partagé du cœur, comme front/changetemplatepredefinedfield.form.php.

$itiltype  = 'PluginGrcmanagerSecurityIncident';
$fieldtype = 'Predefined';

// Même droit que le contrôleur du cœur, vérifié ici aussi avant de lui passer la main.
Session::checkRight(ITILTemplate::$rightname, UPDATE);

include(GLPI_ROOT . '/front/itiltemplatefield.form.php');
