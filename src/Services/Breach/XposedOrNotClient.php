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

use Closure;
use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #1055 : client de l'API gratuite de XposedOrNot, second fournisseur de la surveillance des fuites.
 *
 * Sources (aucun champ inventé) :
 * - documentation officielle : https://xposedornot.com/api_doc (mise à jour du 03/06/2026) ;
 * - code source officiel : https://github.com/XposedOrNot/XposedOrNot-API (api/v1/breaches.py,
 *   api/v1/domain_breaches.py), pour ce que la documentation ne précise pas.
 *
 * Routes utilisées :
 * - GET /v1/check-email/{email}, sans clé : {"breaches": [["Nom", ...]], "email": ..., "status": "success"} ;
 *   adresse sans fuite : {"Error": "Not found", ...}, renvoyé avec le code 200 ou 404 selon le cas (code
 *   source : modèle d'erreur sans code explicite, 404 sur exception) ; les deux valent « aucune fuite » ;
 * - GET /v1/breaches, sans clé : {"exposedBreaches": [{breachID, breachedDate, addedDate, domain,
 *   exposedData, verified, ...}]}, catalogue lu une fois par passage pour détailler les noms ;
 * - POST /v1/domain-breaches/ avec l'en-tête x-api-key (clé gratuite après vérification du domaine sur
 *   leur tableau de bord) et Content-Length: 0 : {"metrics": {"Domain_Summary": {domaine: n},
 *   "Breaches_Details": [{"email", "domain", "breach"}], ...}, "status": "success"} ; 401 = clé refusée.
 *
 * Limites documentées de l'offre gratuite, par adresse IP : 2 requêtes/s ; check-email 25/heure et
 * 100/jour ; domain-breaches 25/heure et 50/jour ; breaches 50/heure et 100/jour. Le client attend 1 s
 * entre deux requêtes et s'arrête de lui-même après MAX_ACCOUNTS_PER_RUN adresses : il répond alors 429
 * et BreachMonitor reprend au même utilisateur au passage suivant. Un 429 du service signale une limite
 * horaire ou journalière (la documentation ne publie pas d'en-tête Retry-After) : il est traité comme
 * une attente d'une heure, donc comme un arrêt du passage.
 *
 * Hypothèses, faute de précision dans la documentation :
 * - les noms renvoyés par check-email sont les breachID du catalogue (sinon la fuite est gardée sous
 *   son seul nom) ;
 * - le champ « email » de Breaches_Details est l'adresse complète ou, à défaut, l'alias ;
 * - les domaines vérifiés sont les clés de Domain_Summary (le code source les initialise toutes).
 *
 * User-Agent générique, sans nom ni adresse. Transport injectable : aucun appel extérieur en CI.
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int}
 * @phpstan-type Transport Closure(string, string, array<string, string>): HttpResult
 */
final class XposedOrNotClient implements BreachProvider
{
    public const BASE_URL = 'https://api.xposedornot.com/v1';

    /** Adresse d'exemple de la documentation (domaine réservé example.com) : essai sans adresse réelle. */
    public const TEST_ACCOUNT = 'test@example.com';

    /** Adresses vérifiées au plus par passage : sous la limite de 25 par heure de check-email. */
    public const MAX_ACCOUNTS_PER_RUN = 20;

    /** Attente retenue pour un 429 du service (limite horaire ou journalière). */
    private const CAP_RETRY_AFTER = 3600;

    /** @var Transport */
    private Closure $transport;

    /** @var Closure(int): void */
    private Closure $sleep;

    private bool $sentOnce = false;

    private int $accounts = 0;

    private ?HibpResponse $catalog = null;

    private ?HibpResponse $domainReport = null;

    /**
     * @param string $apiKey Clé de recherche par domaine ('' : vérification adresse par adresse seulement)
     * @param Transport|null $transport Transport HTTP (Http::send() par défaut)
     * @param (Closure(int): void)|null $sleep Attente entre deux requêtes (injectable pour les tests)
     */
    public function __construct(
        private readonly string $apiKey = '',
        ?Closure $transport = null,
        ?Closure $sleep = null,
    ) {
        $this->transport = $transport ?? static function (string $method, string $url, array $headers): array {
            $body = $method === 'POST' ? '' : null;

            return Http::send($method, $url, $headers, $error, Http::DEFAULT_TIMEOUT, $body);
        };
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function name(): string
    {
        return 'XposedOrNot';
    }

    public function subscribedDomains(): HibpResponse
    {
        if ($this->apiKey === '') {
            return new HibpResponse(200, []);
        }
        $report = $this->domainReport();
        if (!$report->isOk()) {
            return $report;
        }
        $domains = [];
        foreach (array_keys((array) ($report->data['Domain_Summary'] ?? [])) as $domain) {
            $domains[] = strtolower((string) $domain);
        }

        return new HibpResponse(200, array_values(array_unique($domains)));
    }

    public function breachedDomain(string $domain): HibpResponse
    {
        $report = $this->apiKey === '' ? new HibpResponse(401) : $this->domainReport();
        if (!$report->isOk()) {
            return $report;
        }
        $aliases = [];
        foreach ((array) ($report->data['Breaches_Details'] ?? []) as $row) {
            $breach = is_array($row) ? trim((string) ($row['breach'] ?? '')) : '';
            if ($breach === '' || $breach === 'No_Breaches') {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $at = strrpos($email, '@');
            $alias = $at === false ? $email : substr($email, 0, $at);
            $rowDomain = $at === false ? strtolower((string) ($row['domain'] ?? '')) : substr($email, $at + 1);
            if ($alias !== '' && $rowDomain === $domain && !in_array($breach, $aliases[$alias] ?? [], true)) {
                $aliases[$alias][] = $breach;
            }
        }

        return new HibpResponse(200, $aliases);
    }

    public function breachedAccount(string $email): HibpResponse
    {
        $names = $this->checkEmail($email);
        if (!$names->isOk() || $names->data === []) {
            return $names;
        }
        $catalog = $this->breaches();
        if (!$catalog->isOk()) {
            return $catalog;
        }
        $breaches = [];
        foreach ((array) $names->data as $name) {
            $breaches[] = ((array) $catalog->data)[$name] ?? ['Name' => $name];
        }

        return new HibpResponse(200, $breaches);
    }

    /**
     * Catalogue, converti au modèle de fuite commun (BreachProvider) : breachID => Name et Title,
     * domain => Domain, breachedDate => BreachDate, addedDate => AddedDate, exposedData => DataClasses,
     * verified => IsVerified. Lu une seule fois par passage.
     */
    public function breaches(): HibpResponse
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $response = $this->call('GET', '/breaches');
        if (!$response->isOk()) {
            return $response;
        }
        $byName = [];
        foreach ((array) ($response->data['exposedBreaches'] ?? []) as $breach) {
            if (!is_array($breach) || (string) ($breach['breachID'] ?? '') === '') {
                continue;
            }
            $name = (string) $breach['breachID'];
            $byName[$name] = [
                'Name'        => $name,
                'Title'       => $name,
                'Domain'      => (string) ($breach['domain'] ?? ''),
                'BreachDate'  => (string) ($breach['breachedDate'] ?? ''),
                'AddedDate'   => (string) ($breach['addedDate'] ?? ''),
                'DataClasses' => array_values(array_map('strval', (array) ($breach['exposedData'] ?? []))),
                'IsVerified'  => !empty($breach['verified']),
            ];
        }

        return $this->catalog = new HibpResponse(200, $byName);
    }

    /**
     * Avec une clé : lecture du rapport du domaine (401 = clé refusée). Sans clé : vérification de
     * l'adresse d'exemple de la documentation, qui ne désigne personne.
     */
    public function testConnection(): HibpResponse
    {
        if ($this->apiKey !== '') {
            return $this->subscribedDomains();
        }
        $probe = $this->checkEmail(self::TEST_ACCOUNT);

        return $probe->isOk() ? new HibpResponse(200, []) : $probe;
    }

    /**
     * Noms des fuites d'une adresse (liste vide sans fuite), dans la limite par passage.
     */
    private function checkEmail(string $email): HibpResponse
    {
        if ($this->accounts >= self::MAX_ACCOUNTS_PER_RUN) {
            return new HibpResponse(429, null, self::CAP_RETRY_AFTER);
        }
        $this->accounts++;
        $response = $this->call('GET', '/check-email/' . rawurlencode($email));
        if ($response->status === 404 || ($response->isOk() && !isset($response->data['breaches']))) {
            return new HibpResponse(200, []);
        }
        if (!$response->isOk()) {
            return $response;
        }
        $names = [];
        foreach ((array) $response->data['breaches'] as $group) {
            foreach ((array) $group as $name) {
                $name = trim((string) $name);
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return new HibpResponse(200, $names);
    }

    /** Rapport du domaine vérifié sous la clé, lu une seule fois par passage. */
    private function domainReport(): HibpResponse
    {
        if ($this->domainReport !== null) {
            return $this->domainReport;
        }
        $response = $this->call('POST', '/domain-breaches/', ['x-api-key' => $this->apiKey, 'Content-Length' => '0']);

        return $this->domainReport = $response->isOk()
            ? new HibpResponse(200, (array) ($response->data['metrics'] ?? []))
            : $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $path, array $headers = []): HibpResponse
    {
        if ($this->sentOnce) {
            // 2 requêtes par seconde au plus : une par seconde, avec de la marge.
            ($this->sleep)(1);
        }
        $this->sentOnce = true;
        $result = ($this->transport)($method, self::BASE_URL . $path, ['User-Agent' => 'GLPI-GRC-Manager'] + $headers);
        if ($result['status'] === 429) {
            return new HibpResponse(429, null, max(self::CAP_RETRY_AFTER, $result['retry_after']));
        }
        if ($result['status'] !== 200) {
            return new HibpResponse($result['status'], null, $result['retry_after']);
        }
        $data = json_decode($result['body'], true);

        return is_array($data) ? new HibpResponse(200, $data) : new HibpResponse(0, null, 0, 'invalid JSON');
    }
}
