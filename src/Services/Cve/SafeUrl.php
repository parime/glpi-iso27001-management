<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-iso27001-management
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

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Liens venus de flux tiers (références NVD, avis du CERT-FR) affichés dans un href : seules les
 * URL absolues http(s) passent. L'échappement Twig n'arrête pas un « javascript: » ou un
 * « data: » (revue de sécurité 2.11.0). Vérifié à l'analyse du flux ET à la relecture en base,
 * pour les lignes enregistrées avant ce contrôle.
 */
final class SafeUrl
{
    /**
     * @param list<string>|null $hosts Hôtes admis (comparaison exacte, sans casse), ou null pour tout hôte.
     */
    public static function isWebUrl(string $url, bool $httpsOnly = false, ?array $hosts = null): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ($httpsOnly || $scheme !== 'http')) {
            return false;
        }

        return $hosts === null || in_array(strtolower($parts['host']), array_map('strtolower', $hosts), true);
    }
}
