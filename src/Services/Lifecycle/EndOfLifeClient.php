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

namespace GlpiPlugin\Grcmanager\Services\Lifecycle;

use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #704 : lecture seule des cycles de vie d'un produit dans l'API publique d'endoflife.date.
 * Un GET par produit, sans authentification ; seul l'identifiant public du produit (« debian »,
 * « windows ») est envoyé, jamais le nom d'un actif ni d'une entité.
 *
 * Formats lus (lecture défensive, champ inconnu ignoré, rien n'est gardé sans validation) :
 * - API v1 : {"result": {"releases": [{"name", "codename", "isEol", "eolFrom", "latest": {"name"}}]}} ;
 * - ancienne API : [{"cycle", "codename", "eol" (date ou booléen), "latest"}].
 *
 * L'adresse est un modèle avec {product}, changeable par la constante
 * PLUGIN_GRCMANAGER_ENDOFLIFE_URL (config/local_define.php : miroir interne). Le transport est
 * injectable (tests sans réseau) ; par défaut Compat\Http::send(), le client sortant durci du
 * plugin (proxy de GLPI, adresses internes refusées, aucune redirection, délai d'attente).
 *
 * Modèle repris du plugin Inventaire Docker (LifecycleClient), sans en dépendre.
 *
 * @phpstan-type Cycle array{cycle: string, codename: string, eol: string, latest: string}
 * @phpstan-type Transport callable(string): array{status: int, body: string, retry_after: int}
 */
final class EndOfLifeClient
{
    public const DEFAULT_URL = 'https://endoflife.date/api/v1/products/{product}/';

    /** Page publique d'un produit (lien affiché seulement avec la source par défaut). */
    public const PRODUCT_PAGE = 'https://endoflife.date/';

    /** Valeur de « eol » : fin de support atteinte, date non donnée par la source. */
    public const EOL_WITHOUT_DATE = 'yes';

    /** Cycles gardés au plus par produit (les plus récents d'abord dans la source). */
    public const MAX_CYCLES = 300;

    /** @var callable(string): array{status: int, body: string, retry_after: int} */
    private $transport;

    private int $requests = 0;

    /**
     * @param (callable(string): array{status: int, body: string, retry_after: int})|null $transport
     */
    public function __construct(private readonly string $urlTemplate = '', ?callable $transport = null)
    {
        $this->transport = $transport ?? static function (string $url): array {
            $error = null;

            return Http::send('GET', $url, ['Accept' => 'application/json'], $error);
        };
    }

    /**
     * Modèle d'adresse : la constante PLUGIN_GRCMANAGER_ENDOFLIFE_URL si elle est définie.
     */
    public static function configuredUrl(): string
    {
        $constant = 'PLUGIN_GRCMANAGER_ENDOFLIFE_URL';
        $url = defined($constant) ? constant($constant) : '';

        return is_string($url) && $url !== '' ? $url : self::DEFAULT_URL;
    }

    /**
     * Adresse lue pour un produit : « {product} » remplacé, ou « /<produit>.json » ajouté.
     */
    public static function productUrl(string $template, string $product): string
    {
        $template = $template === '' ? self::configuredUrl() : $template;
        $encoded = rawurlencode($product);

        return str_contains($template, '{product}')
            ? str_replace('{product}', $encoded, $template)
            : rtrim($template, '/') . '/' . $encoded . '.json';
    }

    /**
     * Identifiant de produit acceptable : minuscules, chiffres, « . + _ - », 64 caractères au plus.
     */
    public static function isValidProduct(string $product): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.+_-]{0,63}$/', $product) === 1;
    }

    /**
     * Requêtes envoyées par ce client (compte rendu de la tâche).
     */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Cycles de vie du produit, dans l'ordre de la source.
     *
     * @return list<Cycle>
     * @throws EndOfLifeException
     */
    public function cycles(string $product): array
    {
        if (!self::isValidProduct($product)) {
            throw new EndOfLifeException(EndOfLifeException::NOT_FOUND);
        }
        $this->requests++;
        try {
            $response = ($this->transport)(self::productUrl($this->urlTemplate, $product));
        } catch (\Throwable) {
            throw new EndOfLifeException(EndOfLifeException::UNREACHABLE);
        }
        $code = EndOfLifeException::codeForStatus((int) $response['status']);
        if ($code !== null) {
            throw new EndOfLifeException($code);
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new EndOfLifeException(EndOfLifeException::UNEXPECTED);
        }

        return self::parse($data);
    }

    /**
     * @param array<mixed> $data Réponse décodée (API v1 ou ancienne API).
     * @return list<Cycle>
     * @throws EndOfLifeException
     */
    public static function parse(array $data): array
    {
        $isV1 = isset($data['result']) && is_array($data['result']);
        $releases = $isV1 ? ($data['result']['releases'] ?? null) : $data;
        if (!is_array($releases) || !array_is_list($releases)) {
            throw new EndOfLifeException(EndOfLifeException::UNEXPECTED);
        }
        $cycles = [];
        foreach ($releases as $release) {
            if (!is_array($release) || count($cycles) >= self::MAX_CYCLES) {
                continue;
            }
            $name = self::text($release[$isV1 ? 'name' : 'cycle'] ?? null);
            if ($name === '') {
                continue;
            }
            if ($isV1) {
                $eol = self::date($release['eolFrom'] ?? null);
                if ($eol === '' && ($release['isEol'] ?? false) === true) {
                    $eol = self::EOL_WITHOUT_DATE;
                }
                $latest = is_array($release['latest'] ?? null) ? self::text($release['latest']['name'] ?? null) : '';
            } else {
                $value = $release['eol'] ?? null;
                $eol = $value === true ? self::EOL_WITHOUT_DATE : self::date($value);
                $latest = self::text($release['latest'] ?? null);
            }
            $cycles[] = [
                'cycle'    => mb_substr($name, 0, 64),
                'codename' => mb_substr(self::text($release['codename'] ?? null), 0, 64),
                'eol'      => $eol,
                'latest'   => mb_substr($latest, 0, 64),
            ];
        }

        return $cycles;
    }

    /**
     * Texte d'un champ (sans caractère de contrôle), chaîne vide sinon.
     */
    private static function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        return trim((string) preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $value));
    }

    /**
     * « 2026-11-13 » (heure éventuelle ignorée), chaîne vide sinon.
     */
    private static function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
    }
}
