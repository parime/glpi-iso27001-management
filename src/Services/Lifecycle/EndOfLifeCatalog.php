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

/**
 * Issue #1056 : catalogue des produits d'endoflife.date et correspondances PROPOSÉES pour les noms
 * de l'inventaire qui ne sont reliés à rien. PHP pur, sans GLPI. Une proposition n'est jamais
 * appliquée seule : une personne l'accepte (elle devient une correspondance manuelle) ou la refuse.
 *
 * Sources, toutes de l'API v1 (https://endoflife.date/docs/api/v1/, schéma OpenAPI
 * https://endoflife.date/docs/api/v1/openapi.yml, version 1.2.1) :
 * - GET /products (ProductListResponse) : « result » = liste de ProductSummary, dont on lit
 *   « name » (identifiant), « label » (nom affiché), « aliases » (autres identifiants) et
 *   « category » ;
 * - GET /identifiers/cpe et GET /identifiers/purl (IdentifierListResponse) : « result » = liste de
 *   {« identifier », « product »: {« name », « uri »}}.
 *
 * Termes comparés au nom GLPI (forme simple de ProductMatcher::normalize(), puis « - _ . / »
 * remplacés par des espaces), par ordre de confiance :
 * 1. nom affiché, identifiant et alias du produit ;
 * 2. CPE (« cpe:/o:canonical:ubuntu_linux », « cpe:2.3:a:mozilla:firefox ») : le champ « product »
 *    (« ubuntu linux ») et « vendor product » (« mozilla firefox ») ;
 * 3. purl (« pkg:deb/debian/firefox-esr ») : le nom (« firefox esr ») et « namespace nom ».
 * Égalité exacte uniquement, jamais de ressemblance. Seul le niveau le plus sûr qui donne un
 * résultat compte ; plus de MAX_CANDIDATES produits possibles : rien n'est proposé.
 *
 * Hypothèses (écrites dans la PR) : la décomposition des CPE suit CPE 2.2 (« cpe:/part:vendor:product »)
 * et CPE 2.3 (« cpe:2.3:part:vendor:product:... ») ; celle des purl suit la spécification purl
 * (https://github.com/package-url/purl-spec : « pkg:type/namespace/name@version?qualifiers#subpath »).
 * L'API ne dit pas comment rapprocher un nom de logiciel : la règle ci-dessus est la nôtre.
 *
 * @phpstan-type Term array{term: string, level: int, source: string}
 * @phpstan-type Product array{label: string, category: string, terms: list<Term>}
 * @phpstan-type Suggestion array{kind: string, name: string, token: string,
 *     candidates: list<array{product: string, label: string, category: string, via: string, source: string}>}
 */
final class EndOfLifeCatalog
{
    public const LEVEL_PRODUCT = 1;
    public const LEVEL_CPE = 2;
    public const LEVEL_PURL = 3;

    /** Raison affichée de chaque niveau. */
    public const VIA = [self::LEVEL_PRODUCT => 'product', self::LEVEL_CPE => 'cpe', self::LEVEL_PURL => 'purl'];

    /** Au-delà, le terme est trop vague (« core », « cms ») : aucune proposition. */
    public const MAX_CANDIDATES = 3;

    /** Produits gardés au plus (le catalogue en compte environ 500 en 2026). */
    public const MAX_PRODUCTS = 3000;

    /** Identifiants lus au plus par type. */
    public const MAX_IDENTIFIERS = 10000;

    /** Termes plus courts écartés pour les identifiants (« v2 »). */
    private const MIN_IDENTIFIER_TERM = 3;

    /**
     * Forme de comparaison d'un nom GLPI ou d'un terme du catalogue.
     */
    public static function key(string $text): string
    {
        $simple = ProductMatcher::normalize($text);
        $simple = (string) preg_replace('#[-_./]+#u', ' ', $simple);

        return trim((string) preg_replace('/\s+/u', ' ', $simple));
    }

    /**
     * Catalogue construit à partir des trois réponses (null : réponse absente). Réponse
     * inattendue : exception, le catalogue précédent reste utilisé.
     *
     * @param array<mixed> $products Réponse de GET /products.
     * @param array<mixed>|null $cpe Réponse de GET /identifiers/cpe.
     * @param array<mixed>|null $purl Réponse de GET /identifiers/purl.
     * @return array<string, Product> Identifiant du produit => libellé, catégorie, termes.
     * @throws EndOfLifeException
     */
    public static function build(array $products, ?array $cpe = null, ?array $purl = null): array
    {
        $list = $products['result'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new EndOfLifeException(EndOfLifeException::UNEXPECTED);
        }
        $catalog = [];
        foreach ($list as $row) {
            if (!is_array($row) || count($catalog) >= self::MAX_PRODUCTS) {
                continue;
            }
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            if (!EndOfLifeClient::isValidProduct($name)) {
                continue;
            }
            $label = self::text($row['label'] ?? null);
            $catalog[$name] = [
                'label'    => mb_substr($label === '' ? $name : $label, 0, 255),
                'category' => mb_substr(self::text($row['category'] ?? null), 0, 32),
                'terms'    => [],
            ];
            $words = [$name, $label];
            foreach (is_array($row['aliases'] ?? null) ? $row['aliases'] : [] as $alias) {
                $words[] = self::text($alias);
            }
            foreach ($words as $word) {
                self::addTerm($catalog[$name], $word, self::LEVEL_PRODUCT, $word);
            }
        }
        foreach ([self::LEVEL_CPE => $cpe, self::LEVEL_PURL => $purl] as $level => $response) {
            $identifiers = is_array($response) ? ($response['result'] ?? null) : null;
            if (!is_array($identifiers) || !array_is_list($identifiers)) {
                continue;
            }
            foreach (array_slice($identifiers, 0, self::MAX_IDENTIFIERS) as $row) {
                $identifier = is_array($row) ? self::text($row['identifier'] ?? null) : '';
                $ref = is_array($row) ? ($row['product'] ?? null) : null;
                $product = is_array($ref) ? ($ref['name'] ?? null) : null;
                if ($identifier === '' || !is_string($product) || !isset($catalog[$product])) {
                    continue;
                }
                $words = $level === self::LEVEL_CPE ? self::cpeWords($identifier) : self::purlWords($identifier);
                foreach ($words as $word) {
                    if (mb_strlen(self::key($word)) >= self::MIN_IDENTIFIER_TERM) {
                        self::addTerm($catalog[$product], $word, $level, mb_substr($identifier, 0, 255));
                    }
                }
            }
        }
        ksort($catalog);

        return $catalog;
    }

    /**
     * « cpe:/o:canonical:ubuntu_linux » : [« ubuntu_linux », « canonical ubuntu_linux »].
     *
     * @return list<string>
     */
    public static function cpeWords(string $cpe): array
    {
        $parts = explode(':', strtolower($cpe));
        if ($parts[0] !== 'cpe') {
            return [];
        }
        if (($parts[1] ?? '') === '2.3') {
            [$vendor, $product] = [$parts[3] ?? '', $parts[4] ?? ''];
        } elseif (str_starts_with($parts[1] ?? '', '/')) {
            [$vendor, $product] = [$parts[2] ?? '', $parts[3] ?? ''];
        } else {
            return [];
        }
        $vendor = in_array($vendor, ['*', '-'], true) ? '' : $vendor;
        if ($product === '' || in_array($product, ['*', '-'], true)) {
            return [];
        }

        return $vendor === '' ? [$product] : [$product, $vendor . ' ' . $product];
    }

    /**
     * « pkg:deb/debian/firefox-esr » : [« firefox-esr », « debian firefox-esr »].
     *
     * @return list<string>
     */
    public static function purlWords(string $purl): array
    {
        if (preg_match('#^pkg:/*[a-z0-9.+-]+/([^?\#@]+)#i', $purl, $m) !== 1) {
            return [];
        }
        $segments = array_values(array_filter(
            array_map('rawurldecode', explode('/', trim($m[1], '/'))),
            static fn (string $s): bool => $s !== ''
        ));
        if ($segments === []) {
            return [];
        }
        $name = (string) array_pop($segments);
        $namespace = ltrim(implode(' ', $segments), '@');

        return $namespace === '' ? [$name] : [$name, $namespace . ' ' . $name];
    }

    /**
     * Propositions pour les noms qui ne sont reliés à aucun produit (ni à la main, ni par une forme
     * sûre de ProductMatcher). Les refus enregistrés écartent le produit refusé pour ce nom.
     *
     * @param list<array{kind: string, name: string}> $unlinked
     * @param array<string, Product> $catalog
     * @param array<string, true> $rejected rejectKey() => true.
     * @return list<Suggestion>
     */
    public static function suggest(array $unlinked, array $catalog, array $rejected = []): array
    {
        $index = [];
        foreach ($catalog as $product => $entry) {
            foreach ($entry['terms'] as $term) {
                $index[$term['term']][$term['level']][$product] ??= $term['source'];
            }
        }
        $suggestions = [];
        foreach ($unlinked as $entry) {
            $levels = $index[self::key($entry['name'])] ?? [];
            ksort($levels);
            foreach ($levels as $level => $products) {
                $products = array_filter(
                    $products,
                    static fn (string $product): bool
                        => !isset($rejected[self::rejectKey($entry['kind'], $entry['name'], $product)]),
                    ARRAY_FILTER_USE_KEY
                );
                if ($products === []) {
                    continue;
                }
                if (count($products) <= self::MAX_CANDIDATES) {
                    ksort($products);
                    $candidates = [];
                    foreach ($products as $product => $source) {
                        $candidates[] = [
                            'product'  => (string) $product,
                            'label'    => $catalog[$product]['label'],
                            'category' => $catalog[$product]['category'],
                            'via'      => self::VIA[$level],
                            'source'   => $source,
                        ];
                    }
                    $suggestions[] = [
                        'kind'       => $entry['kind'],
                        'name'       => $entry['name'],
                        'token'      => self::token($entry['kind'], $entry['name']),
                        'candidates' => $candidates,
                    ];
                }
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Repère d'une proposition dans le formulaire (le produit retenu est recalculé côté serveur).
     */
    public static function token(string $kind, string $name): string
    {
        return substr(hash('sha256', ProductMatcher::key($kind, $name)), 0, 16);
    }

    /**
     * Clé d'un refus : type d'élément, nom sans casse, produit.
     */
    public static function rejectKey(string $kind, string $name, string $product): string
    {
        return ProductMatcher::key($kind, $name) . "\0" . $product;
    }

    /**
     * @param Product $entry
     */
    private static function addTerm(array &$entry, string $word, int $level, string $source): void
    {
        $term = mb_substr(self::key($word), 0, 255);
        if ($term === '') {
            return;
        }
        foreach ($entry['terms'] as $known) {
            if ($known['term'] === $term && $known['level'] <= $level) {
                return;
            }
        }
        $entry['terms'][] = ['term' => $term, 'level' => $level, 'source' => self::text($source)];
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim((string) preg_replace('/[\x00-\x1f\x7f]/u', '', $value));
    }
}
