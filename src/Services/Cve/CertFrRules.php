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

namespace GlpiPlugin\Grcmanager\Services\Cve;

/**
 * Issue #160, phase 3 et #167 : avis et alertes du CERT-FR (flux RSS publics), rapprochés des
 * logiciels du parc et des fournisseurs du registre par le nom cité dans le titre.
 */
final class CertFrRules
{
    /** Flux publics du CERT-FR (redirigés vers le domaine en vigueur). */
    public const FEEDS = [
        'avis'   => 'https://www.cert.ssi.gouv.fr/avis/feed/',
        'alerte' => 'https://www.cert.ssi.gouv.fr/alerte/feed/',
    ];

    /** Mentions juridiques et mots trop génériques retirés d'un nom de fournisseur. */
    private const NOISE_WORDS = [
        'sas', 'sasu', 'sa', 'sarl', 'eurl', 'sca', 'snc', 'inc', 'ltd', 'llc', 'gmbh', 'ag', 'se', 'bv',
        'nv', 'spa', 'srl', 'plc', 'corp', 'corporation', 'company', 'co', 'group', 'groupe', 'holding',
        'france', 'europe', 'international', 'societe', 'ste', 'the', 'les', 'le', 'la', 'et', 'and',
    ];

    /**
     * Éléments d'un flux RSS du CERT-FR. Les éléments sans référence CERT-FR sont ignorés.
     *
     * @return list<array{ref: string, kind: string, title: string, link: string, summary: string, published: ?string}>
     */
    public static function parseFeed(string $xml, string $kind): array
    {
        if (trim($xml) === '') {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false || !isset($doc->channel->item)) {
            return [];
        }
        $items = [];
        foreach ($doc->channel->item as $item) {
            $link = trim((string) $item->link);
            if (preg_match('/CERTFR-\d{4}-(?:AVI|ALE)-\d+/', $link, $match) !== 1) {
                continue;
            }
            $timestamp = strtotime((string) $item->pubDate);
            $items[] = [
                'ref'       => $match[0],
                'kind'      => $kind,
                'title'     => mb_substr(trim((string) $item->title), 0, 255),
                'link'      => mb_substr($link, 0, 255),
                'summary'   => mb_substr(trim((string) $item->description), 0, 1000),
                'published' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null,
            ];
        }

        return $items;
    }

    /**
     * Minuscules et sans accents, pour comparer des noms écrits différemment.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        if (class_exists(\Normalizer::class)) {
            $decomposed = (string) \Normalizer::normalize($text, \Normalizer::FORM_D);
            $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Le texte cite-t-il ce nom, comme mot entier ? Un nom de moins de 3 lettres ne cite rien.
     */
    public static function mentions(string $text, string $name): bool
    {
        $name = self::normalize($name);
        if (mb_strlen($name) < 3) {
            return false;
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/u',
            self::normalize($text)
        ) === 1;
    }

    /**
     * Noms à rechercher pour un fournisseur : sa raison sociale sans mentions juridiques, et son
     * premier mot s'il est assez distinctif (« Dell Technologies » est cité « Dell »).
     *
     * @return list<string>
     */
    public static function supplierKeywords(string $name): array
    {
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}&+]+/u', self::normalize($name)) ?: [],
            static fn (string $word): bool => $word !== '' && !in_array($word, self::NOISE_WORDS, true)
        ));
        if ($words === []) {
            return [];
        }
        $keywords = [implode(' ', $words)];
        if (count($words) > 1 && mb_strlen($words[0]) >= 4) {
            $keywords[] = $words[0];
        }

        return array_values(array_unique(array_filter(
            $keywords,
            static fn (string $keyword): bool => mb_strlen($keyword) >= 3
        )));
    }

    /**
     * Le texte cite-t-il l'un de ces noms ?
     *
     * @param list<string> $names
     */
    public static function mentionsAny(string $text, array $names): bool
    {
        foreach ($names as $name) {
            if (self::mentions($text, $name)) {
                return true;
            }
        }

        return false;
    }
}
