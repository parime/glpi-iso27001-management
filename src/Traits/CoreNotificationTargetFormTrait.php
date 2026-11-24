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

namespace GlpiPlugin\Grcmanager\Traits;

/**
 * Issue #1114 : une cible de notification du plugin n'a pas de fiche propre. Ses lignes sont celles
 * de la table commune glpi_notificationtargets, que GLPI modifie depuis l'onglet « Destinataires »
 * d'une notification et son formulaire front/notificationtarget.form.php. Sans cette adresse,
 * GLPI calculait plugins/grcmanager/front/notificationtarget<x>.form.php, une page générique sans
 * objet (son fil d'Ariane menait à une page refusée).
 */
trait CoreNotificationTargetFormTrait
{
    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? (string) ($CFG_GLPI['root_doc'] ?? '') : '') . '/front/notificationtarget.form.php';
    }
}
