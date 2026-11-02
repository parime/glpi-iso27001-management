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
 * Issue #370 : import d'un export CSV d'EDR / XDR. Lecture (encodage, séparateur), correspondance
 * automatique des colonnes usuelles, regroupement des alertes en incidents. Sans base : testable seul.
 */
final class EdrCsvImport
{
    public const MAX_ROWS = 20000;

    public const MAX_BYTES = 30 * 1024 * 1024;

    /** Modes de regroupement des lignes en incidents. */
    public const GROUPINGS = ['detection', 'host', 'single', 'row'];

    /**
     * Champs reconnus => en-têtes usuels (normalisés : minuscules, sans séparateurs), y compris les
     * en-têtes aplatis des exports JSON (« device.hostname » donne « devicehostname »).
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'alert_id'     => ['alertid', 'detectionid', 'incidentid', 'eventid', 'id', 'alertuuid', 'compositeid'],
        'detected_at'  => [
            'detectiontime', 'detectedat', 'timestamp', 'time', 'date', 'createdat', 'firstseen', 'eventtime',
            'alertcreationtime', 'creationtime', 'datetime',
        ],
        'title'        => ['alertname', 'title', 'name', 'detectionname', 'threatname', 'rulename', 'description'],
        'hostname'     => ['hostname', 'devicename', 'computername', 'host', 'devicehostname', 'endpoint', 'agentname'],
        'username'     => ['username', 'user', 'accountname', 'userprincipalname', 'loggedonuser', 'useraccount'],
        'severity'     => ['severity', 'priority', 'risklevel', 'threatlevel', 'criticality'],
        'tactic'       => ['tactic', 'mitretactic', 'tactics', 'attacktactic'],
        'technique'    => ['technique', 'mitretechnique', 'techniques', 'techniqueid', 'mitretechniqueid'],
        'command_line' => ['commandline', 'cmdline', 'processcommandline', 'command'],
        'sha256'       => ['sha256', 'filesha256', 'processsha256', 'hashsha256'],
        'md5'          => ['md5', 'filemd5', 'processmd5'],
        'file_name'    => ['filename', 'processname', 'imagename', 'filepath', 'processpath', 'image'],
        'ip'           => ['ip', 'remoteip', 'sourceip', 'destinationip', 'externalip', 'localip', 'ipaddress'],
        'domain'       => ['domain', 'remotedomain', 'dnsname', 'fqdn'],
        'url'          => ['url', 'remoteurl'],
        'console_url'  => ['consoleurl', 'link', 'alertlink', 'detectionlink', 'permalink'],
    ];

    /**
     * Texte UTF-8 sans BOM : un fichier qui n'est pas de l'UTF-8 valide est lu en Windows-1252
     * (exports Excel français).
     */
    public static function decode(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }

    public static function detectDelimiter(string $firstLine): string
    {
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = count(str_getcsv($firstLine, $candidate, '"', ''));
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, truncated: bool}
     */
    public static function parse(string $raw): array
    {
        $text = self::decode($raw);
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $firstLine = (string) ($lines[0] ?? '');
        $delimiter = self::detectDelimiter($firstLine);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['headers' => [], 'rows' => [], 'truncated' => false];
        }
        fwrite($handle, $text);
        rewind($handle);
        $headers = array_map('trim', array_map('strval', fgetcsv($handle, null, $delimiter, '"', '') ?: []));
        $rows = [];
        $truncated = false;
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($row === [null] || implode('', array_map('strval', $row)) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $rows[] = array_map(static fn ($value): string => trim((string) $value), $row);
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows, 'truncated' => $truncated];
    }

    public static function normalizeHeader(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
    }

    /**
     * Correspondance automatique : champ => index de colonne. Un en-tête aplati (« device.hostname »)
     * est aussi comparé par son dernier segment.
     *
     * @param list<string> $headers
     * @return array<string, int>
     */
    public static function guessMapping(array $headers): array
    {
        $mapping = [];
        $used = [];
        foreach (self::FIELDS as $field => $aliases) {
            foreach ($aliases as $alias) {
                foreach ($headers as $index => $header) {
                    $parts = preg_split('/[.\/>]/', $header) ?: [$header];
                    $candidates = [self::normalizeHeader($header), self::normalizeHeader((string) end($parts))];
                    if (!isset($used[$index]) && in_array($alias, $candidates, true)) {
                        $mapping[$field] = $index;
                        $used[$index] = true;
                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Gravité du plugin (minor, major, critical) d'une valeur d'EDR (texte ou nombre sur 10 ou 100).
     */
    public static function severity(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (is_numeric($value)) {
            $number = (float) $value;
            $number = $number > 10 ? $number / 10 : $number;

            return $number >= 8 ? 'critical' : ($number >= 5 ? 'major' : 'minor');
        }
        if (preg_match('/crit|sev(ere)?|urgent|very high|tr[eè]s [ée]lev/u', $value)) {
            return 'critical';
        }
        if (preg_match('/high|[ée]lev|major|majeur|medium|moyen/u', $value)) {
            return 'major';
        }

        return 'minor';
    }

    /**
     * Regroupe les lignes en incidents et prépare chacun : titre, date la plus ancienne, gravité
     * maximale, techniques, indicateurs, hôtes, utilisateurs, identifiants externes et lien console.
     * Les lignes brutes sont gardées telles quelles.
     *
     * @param list<list<string>> $rows
     * @param array<string, int> $mapping
     * @return list<array{
     *     key: string, title: string, date: ?string, severity: string, external_ids: list<string>,
     *     hosts: list<string>, users: list<string>, techniques: list<string>, iocs: list<string>,
     *     console_url: string, rows: list<list<string>>
     * }>
     */
    public static function group(array $rows, array $mapping, string $grouping, string $timezone = 'UTC'): array
    {
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $groups = [];
        foreach ($rows as $index => $row) {
            $key = match ($grouping) {
                'host'   => mb_strtolower($get($row, 'hostname')) ?: '-',
                'single' => 'all',
                'row'    => (string) $index,
                default  => $get($row, 'alert_id') ?: ('row-' . $index),
            };
            $groups[$key][] = $row;
        }

        $order = ['minor' => 0, 'major' => 1, 'critical' => 2];
        $incidents = [];
        foreach ($groups as $key => $groupRows) {
            $dates = [];
            $severity = 'minor';
            $collect = ['external_ids' => [], 'hosts' => [], 'users' => [], 'techniques' => [], 'iocs' => []];
            $title = '';
            $console = '';
            foreach ($groupRows as $row) {
                $title = $title ?: $get($row, 'title');
                $console = $console ?: $get($row, 'console_url');
                $date = self::parseDate($get($row, 'detected_at'), $timezone);
                if ($date !== null) {
                    $dates[] = $date;
                }
                $rowSeverity = $get($row, 'severity') === '' ? 'minor' : self::severity($get($row, 'severity'));
                $severity = $order[$rowSeverity] > $order[$severity] ? $rowSeverity : $severity;
                $collect['external_ids'][] = $get($row, 'alert_id');
                $collect['hosts'][] = $get($row, 'hostname');
                $collect['users'][] = $get($row, 'username');
                foreach (preg_split('/[\s,;|]+/', $get($row, 'technique')) ?: [] as $technique) {
                    $collect['techniques'][] = $technique;
                }
                foreach (['sha256', 'md5', 'ip', 'domain', 'url', 'file_name'] as $iocField) {
                    $collect['iocs'][] = $get($row, $iocField);
                }
            }
            sort($dates);
            $hosts = array_values(array_unique(array_filter($collect['hosts'])));
            $incidents[] = [
                'key'          => (string) $key,
                'title'        => $title !== '' ? $title : 'EDR: ' . ($hosts[0] ?? (string) $key),
                'date'         => $dates[0] ?? null,
                'severity'     => $severity,
                'external_ids' => array_values(array_unique(array_filter($collect['external_ids']))),
                'hosts'        => $hosts,
                'users'        => array_values(array_unique(array_filter($collect['users']))),
                'techniques'   => array_values(array_unique(array_filter($collect['techniques']))),
                'iocs'         => array_values(array_unique(array_filter($collect['iocs']))),
                'console_url'  => preg_match('#^https?://#i', $console) ? $console : '',
                'rows'         => $groupRows,
            ];
        }

        return $incidents;
    }

    /**
     * Date d'EDR (ISO 8601, « 2026-10-30 14:05:00 », « 30/10/2026 14:05 », horodatage Unix en
     * secondes ou millisecondes) ramenée à « Y-m-d H:i:s » dans le fuseau du serveur. Une date sans
     * fuseau est lue dans $timezone.
     */
    public static function parseDate(string $value, string $timezone = 'UTC'): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        if (ctype_digit($value)) {
            $seconds = strlen($value) > 10 ? intdiv((int) $value, 1000) : (int) $value;
            $date = (new \DateTimeImmutable('@' . $seconds));
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $value, $m)) {
            $date = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0),
                $zone
            ) ?: null;
        } else {
            try {
                $date = new \DateTimeImmutable($value, $zone);
            } catch (\Exception) {
                return null;
            }
        }
        if ($date === null) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
