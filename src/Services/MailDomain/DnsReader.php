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

namespace GlpiPlugin\Grcmanager\Services\MailDomain;

/**
 * Issue #855 : lecture DNS publique d'un domaine. Seuls les serveurs DNS sont interrogés : aucun
 * e-mail envoyé, aucune connexion aux serveurs de messagerie, aucun service tiers. Les tests
 * remplacent cette lecture par des réponses fictives.
 */
interface DnsReader
{
    /**
     * Textes des enregistrements TXT d'un nom (chaînes d'un même enregistrement réunies), [] si aucun.
     *
     * @return list<string>
     */
    public function txt(string $name): array;

    /**
     * Serveurs de messagerie (MX) d'un domaine, par priorité, [] si aucun.
     *
     * @return list<string>
     */
    public function mx(string $name): array;

    /**
     * Le domaine existe-t-il dans le DNS (zone ou enregistrement trouvé) ?
     */
    public function exists(string $name): bool;

    /**
     * Délégation signée (enregistrement DS publié par la zone parente) : true, false, ou null si
     * le serveur DNS n'a pas répondu.
     */
    public function hasDs(string $name): ?bool;
}
