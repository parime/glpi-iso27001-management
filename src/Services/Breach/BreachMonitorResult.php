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

namespace GlpiPlugin\Grcmanager\Services\Breach;

/**
 * Bilan d'un passage de la surveillance des fuites.
 */
final class BreachMonitorResult
{
    /** Nouvelles expositions enregistrées. */
    public int $created = 0;

    /** Adresses vérifiées (adresse par adresse) ou touchées (recherche par domaine). */
    public int $checked = 0;

    /** Arrêt sur la limite de débit de la clé : reprise au passage suivant. */
    public bool $rateLimited = false;

    /** Dernier utilisateur interrogé adresse par adresse (0 : liste terminée). */
    public int $cursor = 0;

    /** '' si tout s'est bien passé, sinon « unauthorized » (clé refusée) ou « http_<code> ». */
    public string $error = '';
}
