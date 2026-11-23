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

namespace GlpiPlugin\Grcmanager\Services\Scanner;

/**
 * Issue #543 : lecteur d'un format de rapport de scanner de vulnérabilités (cadre commun des imports
 * de Parc > Sécurité du parc > Vulnérabilités). Un nouveau format (Nessus, Qualys, Rapid7 : issue
 * #841) ajoute une classe qui implémente cette interface et se déclare dans ScannerReports::parsers().
 *
 * Chaque lecteur suit la documentation ou le schéma officiel de l'outil, cités en tête de sa classe ;
 * il lit le fichier sans réseau, sans DTD ni entité externe, et rend des constats normalisés :
 *
 * - report : { uuid, task, scan_start, scan_end } (dates au format de la base, '' si absentes) ;
 * - hosts : adresse (ou nom) => nom d'hôte, pour chaque hôte analysé, même sans constat (un constat
 *   absent d'un hôte analysé par une analyse plus récente est considéré corrigé) ;
 * - findings : liste de { host, hostname, port, test_id, name, family, severity (CVSS 0-10), qod,
 *   cves (liste), solution_type, solution } ;
 * - skipped : résultats d'information (gravité nulle, faux positifs, erreurs) non gardés ;
 * - truncated : true si le fichier dépassait le nombre de constats lus.
 */
interface ScannerReportParser
{
    /** Identifiant court enregistré en base (« openvas »). */
    public static function scanner(): string;

    /** Nom de l'outil, non traduit (nom propre). */
    public static function label(): string;

    /** Vrai si le début du fichier ressemble à ce format. */
    public static function accepts(string $head): bool;

    /**
     * @return array{error: string, report: array{uuid: string, task: string, scan_start: string,
     *     scan_end: string}, hosts: array<string, string>, findings: list<array<string, mixed>>,
     *     skipped: int, truncated: bool}
     */
    public static function parseFile(string $path): array;
}
