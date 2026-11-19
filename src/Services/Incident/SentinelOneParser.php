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
 * Issue #707 : lecture des réponses de la Management API v2.1 de SentinelOne (menaces, alertes,
 * agents, vulnérabilités applicatives). Fonctions pures, sans base ni réseau : chaque objet est
 * ramené à un tableau de chaînes bornées ; un objet sans identifiant est ignoré (null). Aucune
 * valeur n'est interprétée comme du HTML : l'échappement reste celui de Twig à l'affichage.
 */
final class SentinelOneParser
{
    /** Longueur maximale gardée pour un champ texte (titre, ligne de commande...). */
    public const MAX_LENGTH = 1000;

    /**
     * Adresse de la console ramenée à « https://hôte[:port] » (sans chemin, ni identifiants, ni
     * paramètres), ou null si elle n'est pas utilisable. HTTPS obligatoire ; HTTP seulement si
     * $allowHttp (adresses internes autorisées par l'administrateur : laboratoire, faux serveur).
     */
    public static function normalizeConsoleUrl(string $url, bool $allowHttp = false): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !($scheme === 'https' || ($scheme === 'http' && $allowHttp))) {
            return null;
        }
        if (preg_match('/^[a-z0-9.\-]+$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
            return null;
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /**
     * Page d'une liste paginée : éléments, curseur suivant (null en fin de liste), total annoncé.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null, total: int|null}|null
     */
    public static function page(string $body): ?array
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !array_key_exists('data', $json) || !is_array($json['data'])) {
            return null;
        }
        $items = array_values(array_filter($json['data'], 'is_array'));
        $pagination = is_array($json['pagination'] ?? null) ? $json['pagination'] : [];
        $cursor = $pagination['nextCursor'] ?? null;
        $total = $pagination['totalItems'] ?? null;

        return [
            'items'       => $items,
            'next_cursor' => is_string($cursor) && $cursor !== '' ? $cursor : null,
            'total'       => is_numeric($total) ? (int) $total : null,
        ];
    }

    /**
     * Menace (GET /web/api/v2.1/threats) ramenée aux champs de l'import EDR.
     *
     * @param array<string, mixed> $item
     * @return array<string, string>|null
     */
    public static function threat(array $item, string $consoleUrl = ''): ?array
    {
        $info = self::arr($item, 'threatInfo');
        $realtime = self::arr($item, 'agentRealtimeInfo');
        $detection = self::arr($item, 'agentDetectionInfo');
        $id = self::str($item, 'id') ?: self::str($info, 'threatId');
        if ($id === '') {
            return null;
        }
        $confidence = strtolower(self::str($info, 'confidenceLevel'));
        [$tactics, $techniques] = self::mitre(self::list($item, 'indicators'));
        $process = self::str($info, 'originatorProcess');
        $arguments = self::str($info, 'maliciousProcessArguments');

        return self::bounded([
            'kind'         => 'threat',
            'alert_id'     => $id,
            'detected_at'  => self::str($info, 'createdAt') ?: self::str($info, 'identifiedAt'),
            'title'        => self::str($info, 'threatName') ?: self::str($info, 'classification'),
            'classification' => self::str($info, 'classification'),
            'hostname'     => self::str($realtime, 'agentComputerName'),
            'agent_id'     => self::str($realtime, 'agentId'),
            'agent_uuid'   => self::str($realtime, 'agentUuid') ?: self::str($detection, 'agentUuid'),
            'username'     => self::str($detection, 'agentLastLoggedInUserName'),
            // Confiance de SentinelOne : « malicious » est une gravité élevée, « suspicious » moyenne.
            'severity'     => match ($confidence) {
                'malicious'  => 'High',
                'suspicious' => 'Medium',
                default      => 'Low',
            },
            'tactic'       => implode(', ', $tactics),
            'technique'    => implode(', ', $techniques),
            'command_line' => trim($process . ' ' . $arguments),
            'sha256'       => self::hash(self::str($info, 'sha256'), 64),
            'md5'          => self::hash(self::str($info, 'md5'), 32),
            'file_name'    => self::str($info, 'filePath'),
            'ip'           => self::str($detection, 'agentIpV4'),
            'domain'       => '',
            'url'          => '',
            'console_url'  => $consoleUrl !== ''
                ? $consoleUrl . '/incidents/threats/' . rawurlencode($id) . '/overview'
                : '',
            'status'       => self::str($info, 'incidentStatus'),
        ]);
    }

    /**
     * Alerte (GET /web/api/v2.1/cloud-detection/alerts, règles STAR et détections personnalisées)
     * ramenée aux champs de l'import EDR.
     *
     * @param array<string, mixed> $item
     * @return array<string, string>|null
     */
    public static function alert(array $item, string $consoleUrl = ''): ?array
    {
        $alert = self::arr($item, 'alertInfo');
        $rule = self::arr($item, 'ruleInfo');
        $agent = self::arr($item, 'agentDetectionInfo');
        $process = self::arr($item, 'sourceProcessInfo');
        $target = self::arr($item, 'targetProcessInfo');
        $id = self::str($alert, 'alertId') ?: self::str($item, 'id');
        if ($id === '') {
            return null;
        }
        $techniques = [];
        $tactics = [];
        foreach (self::list($item, 'mitreTechniques') as $technique) {
            $techniques[] = self::nameOf($technique);
        }
        foreach (self::list($item, 'mitreTactics') as $tactic) {
            $tactics[] = self::nameOf($tactic);
        }

        return self::bounded([
            'kind'         => 'alert',
            'alert_id'     => $id,
            'detected_at'  => self::str($alert, 'createdAt') ?: self::str($alert, 'eventTime'),
            'title'        => self::str($rule, 'name') ?: self::str($alert, 'analystVerdict'),
            'classification' => '',
            'hostname'     => self::str($agent, 'name'),
            'agent_id'     => self::str($agent, 'id'),
            'agent_uuid'   => self::str($agent, 'uuid'),
            'username'     => self::str($process, 'user'),
            'severity'     => self::str($rule, 'severity') ?: self::str($alert, 'severity'),
            'tactic'       => implode(', ', array_filter($tactics)),
            'technique'    => implode(', ', array_filter($techniques)),
            'command_line' => self::str($process, 'commandline'),
            'sha256'       => self::hash(self::str($process, 'fileHashSha256'), 64),
            'md5'          => self::hash(self::str($process, 'fileHashMd5'), 32),
            'file_name'    => self::str($process, 'filePath') ?: self::str($target, 'filePath'),
            'ip'           => self::str($alert, 'srcIp') ?: self::str($alert, 'dstIp'),
            'domain'       => self::str($alert, 'dnsRequest'),
            'url'          => '',
            'console_url'  => $consoleUrl !== '' ? $consoleUrl . '/alerts/' . rawurlencode($id) : '',
            'status'       => self::str($alert, 'incidentStatus'),
        ]);
    }

    /**
     * Agent (GET /web/api/v2.1/agents).
     *
     * @param array<string, mixed> $item
     * @return array{agent_id: string, uuid: string, computer_name: string, serial: string,
     *     is_active: bool, last_active: string, os: string}|null
     */
    public static function agent(array $item): ?array
    {
        $id = self::str($item, 'id');
        if ($id === '') {
            return null;
        }
        $active = $item['isActive'] ?? false;

        return [
            'agent_id'      => mb_substr($id, 0, 64),
            'uuid'          => mb_substr(self::str($item, 'uuid'), 0, 64),
            'computer_name' => mb_substr(self::str($item, 'computerName'), 0, 255),
            'serial'        => mb_substr(self::str($item, 'serialNumber'), 0, 255),
            'is_active'     => $active === true || $active === 1 || $active === 'true',
            'last_active'   => self::str($item, 'lastActiveDate'),
            'os'            => mb_substr(self::str($item, 'osName'), 0, 255),
        ];
    }

    /**
     * Vulnérabilité applicative (GET /web/api/v2.1/application-management/risks) : une CVE, une
     * application et un poste. Une CVE mal formée est ignorée.
     *
     * @param array<string, mixed> $item
     * @return array{cve: string, endpoint_id: string, endpoint_name: string, application: string,
     *     version: string, score: float|null, severity: string}|null
     */
    public static function vulnerability(array $item): ?array
    {
        $cve = strtoupper(self::str($item, 'cveId') ?: self::str($item, 'cve'));
        if (preg_match('/^CVE-\d{4}-\d{4,}$/', $cve) !== 1) {
            return null;
        }
        $score = $item['baseScore'] ?? ($item['cvssScore'] ?? null);
        $score = is_numeric($score) && (float) $score >= 0 && (float) $score <= 10 ? (float) $score : null;

        return [
            'cve'           => $cve,
            'endpoint_id'   => mb_substr(self::str($item, 'endpointId') ?: self::str($item, 'agentId'), 0, 64),
            'endpoint_name' => mb_substr(self::str($item, 'endpointName') ?: self::str($item, 'computerName'), 0, 255),
            'application'   => mb_substr(
                self::str($item, 'applicationName') ?: self::str($item, 'application'),
                0,
                255
            ),
            'version'       => mb_substr(self::str($item, 'applicationVersion') ?: self::str($item, 'version'), 0, 100),
            'score'         => $score,
            'severity'      => mb_substr(strtolower(self::str($item, 'severity')), 0, 20),
        ];
    }

    /**
     * Ligne et en-têtes au format de l'import CSV (SecurityIncidentEdrImport::import()) : les
     * champs de EdrCsvImport::FIELDS, dans cet ordre, la correspondance étant l'identité.
     *
     * @param array<string, string> $proposal
     * @return array{headers: list<string>, row: list<string>, mapping: array<string, int>}
     */
    public static function toImportRow(array $proposal): array
    {
        $headers = array_keys(EdrCsvImport::FIELDS);
        $row = [];
        foreach ($headers as $field) {
            $row[] = (string) ($proposal[$field] ?? '');
        }

        return ['headers' => $headers, 'row' => $row, 'mapping' => array_flip($headers)];
    }

    /**
     * Date ISO 8601 de l'API pour le filtre « createdAt__gt » (UTC, millisecondes).
     */
    public static function apiDate(string $date): string
    {
        try {
            $value = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $value = new \DateTimeImmutable('@0');
        }

        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Tactiques et techniques MITRE ATT&CK des indicateurs d'une menace.
     *
     * @param list<mixed> $indicators
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function mitre(array $indicators): array
    {
        $tactics = [];
        $techniques = [];
        foreach ($indicators as $indicator) {
            if (!is_array($indicator)) {
                continue;
            }
            foreach (self::list($indicator, 'tactics') as $tactic) {
                if (!is_array($tactic)) {
                    continue;
                }
                $tactics[] = self::str($tactic, 'name');
                foreach (self::list($tactic, 'techniques') as $technique) {
                    if (is_array($technique)) {
                        $techniques[] = self::str($technique, 'name');
                    }
                }
            }
        }
        $clean = static fn (array $values): array => array_values(array_unique(array_filter($values)));

        return [$clean($tactics), $clean($techniques)];
    }

    /** Nom d'une technique ou tactique (objet avec « name », ou simple chaîne). */
    private static function nameOf(mixed $value): string
    {
        if (is_array($value)) {
            return self::str($value, 'name');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private static function bounded(array $values): array
    {
        return array_map(static fn (string $value): string => mb_substr($value, 0, self::MAX_LENGTH), $values);
    }

    private static function hash(string $value, int $length): string
    {
        $value = strtolower($value);

        return preg_match('/^[a-f0-9]{' . $length . '}$/', $value) === 1 ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function arr(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? array_values($data[$key]) : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
