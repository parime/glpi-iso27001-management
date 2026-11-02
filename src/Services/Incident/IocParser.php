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

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #369 : indicateurs de compromission saisis en masse (une valeur par ligne). Détecte le
 * type, « réarme » les valeurs désamorcées (hxxp, [.], (at)...) et normalise pour dédoublonner.
 * Aucune requête réseau ni base : testable seul.
 */
final class IocParser
{
    private const FILE_EXTENSIONS = '/\.(exe|dll|scr|bat|cmd|ps1|vbs|js|jar|msi|lnk|hta|iso'
        . '|zip|rar|7z|docm|xlsm|pdf|doc|xls)$/i';

    public const TYPES = ['ipv4', 'ipv6', 'domain', 'url', 'email', 'md5', 'sha1', 'sha256', 'filename'];

    /**
     * Liens de recherche manuelle par type (aucun envoi automatique : l'analyste clique).
     *
     * @var array<string, array<string, string>>
     */
    private const LOOKUPS = [
        'ipv4'   => [
            'VirusTotal' => 'https://www.virustotal.com/gui/ip-address/%s',
            'AbuseIPDB'  => 'https://www.abuseipdb.com/check/%s',
        ],
        'ipv6'   => [
            'VirusTotal' => 'https://www.virustotal.com/gui/ip-address/%s',
            'AbuseIPDB'  => 'https://www.abuseipdb.com/check/%s',
        ],
        'domain' => [
            'VirusTotal' => 'https://www.virustotal.com/gui/domain/%s',
            'urlscan'    => 'https://urlscan.io/search/#domain:%s',
        ],
        'url'    => [
            'VirusTotal' => 'https://www.virustotal.com/gui/search/%s',
            'urlscan'    => 'https://urlscan.io/search/#%s',
        ],
        'md5'    => ['VirusTotal' => 'https://www.virustotal.com/gui/file/%s'],
        'sha1'   => ['VirusTotal' => 'https://www.virustotal.com/gui/file/%s'],
        'sha256' => ['VirusTotal' => 'https://www.virustotal.com/gui/file/%s'],
    ];

    /**
     * Découpe une saisie multiligne en IOC typés et dédoublonnés. Les lignes vides et les
     * commentaires (# ...) sont ignorés ; une ligne non reconnue reste dans `rejected`.
     *
     * @return array{iocs: list<array{type: string, value: string}>, rejected: list<string>}
     */
    public static function parse(string $raw): array
    {
        $iocs = [];
        $rejected = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $ioc = self::detect($line);
            if ($ioc === null) {
                $rejected[] = $line;
                continue;
            }
            $iocs[$ioc['type'] . "\0" . $ioc['value']] = $ioc;
        }

        return ['iocs' => array_values($iocs), 'rejected' => $rejected];
    }

    /**
     * @return array{type: string, value: string}|null
     */
    public static function detect(string $value): ?array
    {
        $value = self::refang(trim($value));
        if ($value === '' || strlen($value) > 2048) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ['type' => 'ipv4', 'value' => $value];
        }
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return ['type' => 'ipv6', 'value' => strtolower($value)];
        }
        if (preg_match('/^[a-f0-9]{32}$/i', $value)) {
            return ['type' => 'md5', 'value' => strtolower($value)];
        }
        if (preg_match('/^[a-f0-9]{40}$/i', $value)) {
            return ['type' => 'sha1', 'value' => strtolower($value)];
        }
        if (preg_match('/^[a-f0-9]{64}$/i', $value)) {
            return ['type' => 'sha256', 'value' => strtolower($value)];
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://\S+$#i', $value)) {
            return ['type' => 'url', 'value' => $value];
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return ['type' => 'email', 'value' => strtolower($value)];
        }
        if (preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $value)) {
            // « invoice.pdf.exe » ressemble à un domaine : une extension exécutable ou bureautique
            // connue en fin de valeur en fait un nom de fichier.
            if (preg_match(self::FILE_EXTENSIONS, $value)) {
                return ['type' => 'filename', 'value' => $value];
            }

            return ['type' => 'domain', 'value' => strtolower($value)];
        }
        if (preg_match('/^[^\/\\\\:*?"<>|\s][^\/\\\\:*?"<>|]{0,254}\.[a-z0-9]{1,8}$/i', $value)) {
            return ['type' => 'filename', 'value' => $value];
        }

        return null;
    }

    /**
     * Réarme les notations désamorcées courantes des rapports de menace.
     */
    public static function refang(string $value): string
    {
        $value = preg_replace('/^hxxp(s?):\/\//i', 'http$1://', $value) ?? $value;
        $value = preg_replace('/^fxp:\/\//i', 'ftp://', $value) ?? $value;

        return str_ireplace(
            ['[.]', '(.)', '{.}', '[dot]', '(dot)', '[:]', '[at]', '(at)', '[@]'],
            ['.', '.', '.', '.', '.', ':', '@', '@', '@'],
            $value
        );
    }

    /**
     * @return array<string, string> Nom du service => URL de recherche.
     */
    public static function lookupLinks(string $type, string $value): array
    {
        $links = [];
        foreach (self::LOOKUPS[$type] ?? [] as $name => $pattern) {
            $links[$name] = sprintf($pattern, rawurlencode($value));
        }

        return $links;
    }
}
