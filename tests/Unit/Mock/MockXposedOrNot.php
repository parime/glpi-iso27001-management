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
 * Issue #1055 : faux serveur de l'API XposedOrNot, bâti sur les exemples de réponses de la
 * documentation officielle (https://xposedornot.com/api_doc) et, pour ce qu'elle ne précise pas,
 * sur le code source officiel (https://github.com/XposedOrNot/XposedOrNot-API, api/v1/breaches.py et
 * api/v1/domain_breaches.py). Données fictives, aucun champ inventé.
 *
 * - GET /v1/check-email/{email} : adresse de $compromised : noms de fuites (liste de l'exemple documenté, réduite
 *   à deux noms du catalogue et un hors catalogue) ; NOT_FOUND_200 : {"Error":"Not found","email":null}
 *   avec le code 200 (modèle d'erreur renvoyé sans code dans le code source) ; toute autre adresse :
 *   le même corps avec le code 404 (tableau des codes de la documentation) ;
 * - GET /v1/breaches : enveloppe exposedBreaches et champs de l'exemple « APK.TW » ;
 * - POST /v1/domain-breaches/ : en-tête x-api-key VALID_KEY, sinon 401 {"detail": "Invalid or missing
 *   API key"} (exemple documenté) ; Domain_Summary et Breaches_Details (email, domain, breach) ;
 * - au-delà de $limit requêtes check-email : 429 sans Retry-After (non documenté).
 * Chaque requête est notée dans $calls (méthode, chemin, en-têtes).
 */
final class MockXposedOrNot
{
    public const VALID_KEY = 'mock-xon-domain-key';
    public const NOT_FOUND_200 = 'nobody-200@grc-xon-test.example';

    /** @var list<array{method: string, path: string, headers: array<string, string>}> */
    public array $calls = [];

    private int $checks = 0;

    /**
     * @param list<string> $compromised Adresses touchées par les fuites de l'exemple
     * @param string $domain Domaine vérifié sous VALID_KEY
     */
    public function __construct(
        private readonly array $compromised = [],
        private readonly string $domain = 'grc-xon-test.example',
        private readonly int $limit = PHP_INT_MAX,
    ) {
    }

    /**
     * Transport à injecter dans XposedOrNotClient.
     *
     * @return \Closure(string, string, array<string, string>): array{status: int, body: string, retry_after: int}
     */
    public function transport(): \Closure
    {
        return function (string $method, string $url, array $headers): array {
            $path = (string) substr($url, strlen('https://api.xposedornot.com/v1'));
            $this->calls[] = ['method' => $method, 'path' => $path, 'headers' => $headers];

            return $this->respond($method, $path, $headers);
        };
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, retry_after: int}
     */
    private function respond(string $method, string $path, array $headers): array
    {
        if ($method === 'GET' && str_starts_with($path, '/check-email/')) {
            if (++$this->checks > $this->limit) {
                return self::reply(429, '');
            }
            $email = rawurldecode(substr($path, strlen('/check-email/')));
            if (in_array($email, $this->compromised, true)) {
                return self::reply(200, [
                    'breaches' => [['APK.TW', 'Tesco', 'KiwiFarms']],
                    'email'    => $email,
                    'status'   => 'success',
                ]);
            }

            return self::reply($email === self::NOT_FOUND_200 ? 200 : 404, ['Error' => 'Not found', 'email' => null]);
        }
        if ($method === 'GET' && $path === '/breaches') {
            return self::reply(200, ['exposedBreaches' => [
                [
                    'breachID'            => 'APK.TW',
                    'breachedDate'        => '2022-09-01T00:00:00+00:00',
                    'addedDate'           => '2023-11-08T06:30:35+00:00',
                    'domain'              => 'apk.tw',
                    'exposedData'         => ['Email addresses', 'Usernames', 'Passwords', 'IP addresses'],
                    'exposedRecords'      => 2457094,
                    'exposureDescription' => 'Fictitious description.',
                    'industry'            => 'Information Technology',
                    'logo'                => '',
                    'passwordRisk'        => 'easytocrack',
                    'referenceURL'        => '',
                    'searchable'          => true,
                    'sensitive'           => false,
                    'verified'            => true,
                ],
                [
                    'breachID'     => 'Tesco',
                    'breachedDate' => '2014-02-01T00:00:00+00:00',
                    'addedDate'    => '2023-01-01T00:00:00+00:00',
                    'domain'       => 'tesco.com',
                    'exposedData'  => ['Email addresses'],
                    'verified'     => false,
                ],
            ]]);
        }
        if ($method === 'POST' && $path === '/domain-breaches/') {
            if (($headers['x-api-key'] ?? '') !== self::VALID_KEY) {
                return self::reply(401, ['detail' => 'Invalid or missing API key']);
            }
            $details = [];
            foreach ($this->compromised as $email) {
                $details[] = ['email' => $email, 'domain' => $this->domain, 'breach' => 'APK.TW'];
            }

            return self::reply(200, ['metrics' => [
                'Breach_Summary'   => ['APK.TW' => count($details)],
                'Breaches_Details' => $details,
                'Domain_Summary'   => [$this->domain => count($details)],
            ], 'status' => 'success']);
        }

        return self::reply(404, ['Error' => 'Not found']);
    }

    /**
     * @param array<string, mixed>|string $body
     * @return array{status: int, body: string, retry_after: int}
     */
    private static function reply(int $status, array|string $body): array
    {
        return [
            'status'      => $status,
            'body'        => is_string($body) ? $body : (string) json_encode($body),
            'retry_after' => 0,
        ];
    }
}
