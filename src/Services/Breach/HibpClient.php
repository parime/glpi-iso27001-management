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
use GlpiPlugin\Grcmanager\Compatibility\Http;

/**
 * Issue #183 : client de l'API v3 de Have I Been Pwned (https://haveibeenpwned.com/API/v3).
 *
 * Chaque réponse est une HibpResponse : l'API répond par son code HTTP (404 = aucune fuite pour cette
 * adresse ou ce domaine, 401 = clé invalide, 403 = domaine non vérifié, 429 = limite de débit de la
 * clé, avec un délai « retry-after »). Le transport est injectable pour les tests (aucun appel
 * extérieur en CI).
 *
 * @phpstan-type HttpResult array{status: int, body: string, retry_after: int}
 * @phpstan-type Transport Closure(string, array<string, string>): HttpResult
 */
final class HibpClient
{
    public const BASE_URL = 'https://haveibeenpwned.com/api/v3';

    /** Clé de test publiée par Have I Been Pwned, valable pour les comptes @hibp-integration-tests.com. */
    public const TEST_API_KEY = '00000000000000000000000000000000';

    /** Compte de test publié par Have I Been Pwned (une fuite) : éprouve une clé sans adresse réelle. */
    public const TEST_ACCOUNT = 'account-exists@hibp-integration-tests.com';

    /** @var Transport */
    private Closure $transport;

    /**
     * @param Transport|null $transport Transport HTTP (Http::request() par défaut)
     */
    public function __construct(private readonly string $apiKey, ?Closure $transport = null)
    {
        $this->transport = $transport ?? static function (string $url, array $headers): array {
            return Http::request($url, $headers);
        };
    }

    /**
     * Domaines vérifiés sous cette clé (recherche par domaine possible), en minuscules.
     */
    public function subscribedDomains(): HibpResponse
    {
        $response = $this->get('/subscribeddomains');
        if ($response->status !== 200) {
            return $response;
        }
        $domains = [];
        foreach ((array) $response->data as $row) {
            if (is_array($row) && isset($row['DomainName'])) {
                $domains[] = strtolower((string) $row['DomainName']);
            }
        }

        return new HibpResponse(200, $domains);
    }

    /**
     * Adresses d'un domaine vérifié touchées par une fuite : alias (partie avant @) => noms des fuites.
     */
    public function breachedDomain(string $domain): HibpResponse
    {
        $response = $this->get('/breacheddomain/' . rawurlencode($domain));
        if ($response->status === 404) {
            return new HibpResponse(200, []);
        }
        if ($response->status !== 200) {
            return $response;
        }
        $aliases = [];
        foreach ((array) $response->data as $alias => $names) {
            $aliases[strtolower((string) $alias)] = array_values(array_map('strval', (array) $names));
        }

        return new HibpResponse(200, $aliases);
    }

    /**
     * Fuites d'une adresse (modèle complet de chaque fuite). Une adresse sans fuite donne une liste vide.
     */
    public function breachedAccount(string $email): HibpResponse
    {
        $response = $this->get('/breachedaccount/' . rawurlencode($email) . '?truncateResponse=false');
        if ($response->status === 404) {
            return new HibpResponse(200, []);
        }

        return $response->status === 200 ? new HibpResponse(200, array_values((array) $response->data)) : $response;
    }

    /**
     * Toutes les fuites connues, par nom (sans clé d'API) : détails des fuites renvoyées par nom seul
     * par la recherche par domaine.
     */
    public function breaches(): HibpResponse
    {
        $response = $this->get('/breaches', false);
        if ($response->status !== 200) {
            return $response;
        }
        $byName = [];
        foreach ((array) $response->data as $breach) {
            if (is_array($breach) && isset($breach['Name'])) {
                $byName[(string) $breach['Name']] = $breach;
            }
        }

        return new HibpResponse(200, $byName);
    }

    private function get(string $path, bool $withKey = true): HibpResponse
    {
        $headers = ['User-Agent' => 'GLPI-GRC-Manager'];
        if ($withKey) {
            $headers['hibp-api-key'] = $this->apiKey;
        }
        $result = ($this->transport)(self::BASE_URL . $path, $headers);
        $data = null;
        if ($result['status'] === 200) {
            $data = json_decode($result['body'], true);
            if (!is_array($data)) {
                return new HibpResponse(0, null, 0, 'invalid JSON');
            }
        }

        return new HibpResponse($result['status'], $data, $result['retry_after']);
    }
}
