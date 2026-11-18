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

namespace GlpiPlugin\Grcmanager\Tests\Mock;

/**
 * Issue #707 : fausse console SentinelOne (Management API v2.1), données entièrement fictives.
 *
 * Utilisée en mémoire par les tests PHPUnit (transport injecté dans SentinelOneClient) et derrière
 * le serveur intégré de PHP pour les essais dans le navigateur (router.php). Réponses au format de
 * l'API : { data: [...], pagination: { nextCursor, totalItems } }, pagination par curseur, filtre
 * createdAt__gt, tri par date de création.
 *
 * Jetons reconnus (en-tête « Authorization: ApiToken <jeton> ») :
 * - mock-viewer-token    : tout est visible ;
 * - mock-empty-token     : jeton accepté qui ne voit rien (aucun site) ;
 * - mock-licence-token   : alertes refusées (403) et vulnérabilités absentes (404), comme une
 *                          licence sans ces modules ;
 * - mock-ratelimit-token : 429 (Retry-After: 30) à partir de la deuxième page des menaces ;
 * - tout autre jeton     : 401.
 * Toute méthode autre que GET reçoit 405 : la console ne doit jamais recevoir d'action.
 */
final class MockSentinelOne
{
    public const VIEWER_TOKEN = 'mock-viewer-token';
    public const EMPTY_TOKEN = 'mock-empty-token';
    public const LICENCE_TOKEN = 'mock-licence-token';
    public const RATE_LIMIT_TOKEN = 'mock-ratelimit-token';

    /** @var int Date de référence (les dates des données sont relatives : toujours récentes). */
    private int $now;

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return array{status: int, body: string, retry_after: int}
     */
    public function handle(string $method, string $path, array $query, array $headers): array
    {
        if (strtoupper($method) !== 'GET') {
            return $this->json(405, ['errors' => [['code' => 4050010, 'title' => 'Method Not Allowed']]]);
        }
        $headers = array_change_key_case($headers);
        $token = preg_replace('/^ApiToken\s+/i', '', (string) ($headers['authorization'] ?? '')) ?? '';
        if (!in_array($token, [self::VIEWER_TOKEN, self::EMPTY_TOKEN, self::LICENCE_TOKEN, self::RATE_LIMIT_TOKEN], true)) {
            return $this->json(401, ['errors' => [['code' => 4010010, 'title' => 'Authentication Failed']]]);
        }
        $list = match ($path) {
            '/web/api/v2.1/agents'                         => 'agents',
            '/web/api/v2.1/threats'                        => 'threats',
            '/web/api/v2.1/cloud-detection/alerts'         => 'alerts',
            '/web/api/v2.1/application-management/risks'   => 'vulnerabilities',
            default                                        => '',
        };
        if ($list === '') {
            return $this->json(404, ['errors' => [['code' => 4040010, 'title' => 'Not Found']]]);
        }
        if ($token === self::LICENCE_TOKEN && $list === 'alerts') {
            return $this->json(403, ['errors' => [['code' => 4030010, 'title' => 'Forbidden']]]);
        }
        if ($token === self::LICENCE_TOKEN && $list === 'vulnerabilities') {
            return $this->json(404, ['errors' => [['code' => 4040010, 'title' => 'Not Found']]]);
        }
        $items = $token === self::EMPTY_TOKEN ? [] : $this->items($list);

        $since = (string) ($query['createdAt__gt'] ?? '');
        if ($since !== '' && in_array($list, ['threats', 'alerts'], true)) {
            $items = array_values(array_filter($items, fn (array $item): bool => strcmp($this->createdAt($list, $item), $since) > 0));
        }
        if (($query['sortBy'] ?? '') === 'createdAt') {
            usort($items, fn (array $a, array $b): int => strcmp($this->createdAt($list, $a), $this->createdAt($list, $b)));
            if (strtolower((string) ($query['sortOrder'] ?? 'asc')) === 'desc') {
                $items = array_reverse($items);
            }
        }

        $limit = max(1, min(1000, (int) ($query['limit'] ?? 10)));
        $offset = 0;
        if (isset($query['cursor'])) {
            $decoded = base64_decode((string) $query['cursor'], true);
            if ($decoded === false || !ctype_digit($decoded)) {
                return $this->json(400, ['errors' => [['code' => 4000010, 'title' => 'Validation Error']]]);
            }
            $offset = (int) $decoded;
        }
        if ($token === self::RATE_LIMIT_TOKEN && $list === 'threats' && $offset > 0) {
            return $this->json(429, ['errors' => [['code' => 4290010, 'title' => 'Too Many Requests']]], 30);
        }
        $page = array_slice($items, $offset, $limit);
        $next = $offset + $limit < count($items) ? base64_encode((string) ($offset + $limit)) : null;

        return $this->json(200, ['data' => $page, 'pagination' => ['nextCursor' => $next, 'totalItems' => count($items)]]);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function createdAt(string $list, array $item): string
    {
        return (string) ($list === 'alerts' ? ($item['alertInfo']['createdAt'] ?? '') : ($item['threatInfo']['createdAt'] ?? ''));
    }

    private function date(int $hoursAgo): string
    {
        return gmdate('Y-m-d\TH:i:s', $this->now - $hoursAgo * 3600) . '.000000Z';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $list): array
    {
        return match ($list) {
            'agents'          => $this->agents(),
            'threats'         => $this->threats(),
            'alerts'          => $this->alerts(),
            default           => $this->vulnerabilities(),
        };
    }

    /** @return list<array<string, mixed>> */
    private function agents(): array
    {
        $agent = fn (string $id, string $name, string $serial, bool $active, string $os): array => [
            'id'             => $id,
            'uuid'           => md5('agent-' . $id),
            'computerName'   => $name,
            'serialNumber'   => $serial,
            'isActive'       => $active,
            'lastActiveDate' => $this->date($active ? 1 : 400),
            'osName'         => $os,
            'siteName'       => 'Siège (fictif)',
            'agentVersion'   => '24.2.3.471',
        ];

        return [
            $agent('1900000000000000001', 'PC-COMPTA-01', 'SN-FICTIF-0001', true, 'Windows 11 Pro'),
            $agent('1900000000000000002', 'LAPTOP-7F3K2', 'SN-FICTIF-RH-01', true, 'Windows 11 Pro'),
            $agent('1900000000000000003', 'SRV-WEB-01.entreprise-fictive.local', '', true, 'Ubuntu 24.04'),
            $agent('1900000000000000004', 'MAC-INCONNU-42', 'SN-FICTIF-9999', false, 'macOS 15'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function threats(): array
    {
        $threat = fn (string $id, int $hoursAgo, string $name, string $agentId, string $host, string $user, string $confidence, string $sha256, string $path, array $techniques): array => [
            'id'                 => $id,
            'agentRealtimeInfo'  => ['agentComputerName' => $host, 'agentId' => $agentId, 'agentUuid' => md5('agent-' . $agentId), 'agentOsName' => 'Windows 11 Pro'],
            'agentDetectionInfo' => ['agentIpV4' => '192.0.2.' . (int) substr($id, -2), 'agentLastLoggedInUserName' => $user, 'agentDomain' => 'ENTREPRISE-FICTIVE'],
            'threatInfo'         => [
                'threatId'          => $id,
                'threatName'        => $name,
                'classification'    => 'Trojan',
                'confidenceLevel'   => $confidence,
                'createdAt'         => $this->date($hoursAgo),
                'identifiedAt'      => $this->date($hoursAgo),
                'sha256'            => $sha256,
                'md5'               => '',
                'filePath'          => $path,
                'originatorProcess' => 'outlook.exe',
                'incidentStatus'    => 'unresolved',
                'mitigationStatus'  => 'mitigated',
                'analystVerdict'    => 'undefined',
            ],
            'indicators'         => [[
                'category' => 'Persistence',
                'tactics'  => [['name' => 'Persistence', 'source' => 'MITRE', 'techniques' => array_map(
                    static fn (string $t): array => ['name' => $t, 'link' => 'https://attack.mitre.org/techniques/' . str_replace('.', '/', $t) . '/'],
                    $techniques
                )]],
            ]],
        ];

        return [
            $threat('1950000000000000101', 70, 'facture_novembre.pdf.exe', '1900000000000000001', 'PC-COMPTA-01', 'ENTREPRISE-FICTIVE\\demo.compta', 'malicious', str_repeat('a1', 32), 'C:\\Users\\demo.compta\\Downloads\\facture_novembre.pdf.exe', ['T1204.002', 'T1547.001']),
            $threat('1950000000000000102', 50, 'outil_rh_macro.docm', '1900000000000000002', 'LAPTOP-7F3K2', 'ENTREPRISE-FICTIVE\\demo.rh', 'suspicious', str_repeat('b2', 32), 'C:\\Users\\demo.rh\\Documents\\outil_rh_macro.docm', ['T1059.005']),
            $threat('1950000000000000103', 30, 'cryptominer.sh', '1900000000000000003', 'SRV-WEB-01.entreprise-fictive.local', 'www-data', 'malicious', str_repeat('c3', 32), '/tmp/.cache/cryptominer.sh', ['T1496']),
            $threat('1950000000000000104', 10, 'keygen_inconnu.exe', '1900000000000000004', 'MAC-INCONNU-42', 'invite', 'suspicious', str_repeat('d4', 32), '/Users/invite/Downloads/keygen_inconnu.exe', []),
            $threat('1950000000000000105', 2, 'powershell_encode.ps1', '1900000000000000001', 'PC-COMPTA-01', 'ENTREPRISE-FICTIVE\\demo.compta', 'malicious', str_repeat('e5', 32), 'C:\\Windows\\Temp\\powershell_encode.ps1', ['T1059.001']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function alerts(): array
    {
        $alert = fn (string $id, int $hoursAgo, string $rule, string $severity, string $agentId, string $host, string $command): array => [
            'alertInfo'          => ['alertId' => $id, 'createdAt' => $this->date($hoursAgo), 'incidentStatus' => 'Unresolved', 'analystVerdict' => 'Undefined', 'srcIp' => '192.0.2.50'],
            'ruleInfo'           => ['id' => 'rule-' . $id, 'name' => $rule, 'severity' => $severity],
            'agentDetectionInfo' => ['id' => $agentId, 'name' => $host, 'uuid' => md5('agent-' . $agentId)],
            'sourceProcessInfo'  => ['commandline' => $command, 'user' => 'ENTREPRISE-FICTIVE\\demo.dsi', 'fileHashSha256' => str_repeat('f6', 32), 'filePath' => 'C:\\Windows\\System32\\cmd.exe'],
            'mitreTechniques'    => [['name' => 'T1003.001']],
            'mitreTactics'       => [['name' => 'Credential Access']],
        ];

        return [
            $alert('1960000000000000201', 20, 'Accès à la mémoire de LSASS (règle STAR fictive)', 'Critical', '1900000000000000001', 'PC-COMPTA-01', 'rundll32.exe comsvcs.dll MiniDump 624 lsass.dmp full'),
            $alert('1960000000000000202', 5, 'Création de compte administrateur local (règle STAR fictive)', 'Medium', '1900000000000000003', 'SRV-WEB-01.entreprise-fictive.local', 'net user support Fictif#2026 /add'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function vulnerabilities(): array
    {
        $vuln = static fn (string $cve, string $endpointId, string $endpoint, string $app, string $version, float $score, string $severity): array => [
            'cveId'              => $cve,
            'endpointId'         => $endpointId,
            'endpointName'       => $endpoint,
            'applicationName'    => $app,
            'applicationVersion' => $version,
            'baseScore'          => $score,
            'severity'           => $severity,
            'status'             => 'NEW',
        ];

        return [
            $vuln('CVE-2025-29927', '1900000000000000003', 'SRV-WEB-01.entreprise-fictive.local', 'Next.js', '14.2.20', 9.1, 'CRITICAL'),
            $vuln('CVE-2024-38063', '1900000000000000001', 'PC-COMPTA-01', 'Windows TCP/IP', '10.0.22631', 9.8, 'CRITICAL'),
            $vuln('CVE-2024-38063', '1900000000000000002', 'LAPTOP-7F3K2', 'Windows TCP/IP', '10.0.22631', 9.8, 'CRITICAL'),
            $vuln('CVE-2023-4863', '1900000000000000001', 'PC-COMPTA-01', 'Google Chrome', '116.0.5845.96', 8.8, 'HIGH'),
            $vuln('CVE-2023-4863', '1900000000000000004', 'MAC-INCONNU-42', 'Google Chrome', '116.0.5845.96', 8.8, 'HIGH'),
            $vuln('not-a-cve', '1900000000000000001', 'PC-COMPTA-01', 'Exemple', '1.0', 1.0, 'LOW'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: string, retry_after: int}
     */
    private function json(int $status, array $data, int $retryAfter = 0): array
    {
        return ['status' => $status, 'body' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'retry_after' => $retryAfter];
    }
}
