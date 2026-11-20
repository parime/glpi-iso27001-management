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

namespace GlpiPlugin\Grcmanager\Services\Phishing;

use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;

/**
 * Issue #604 : règles des résultats de simulation d'hameçonnage, sans base (testables seules).
 *
 * Lecture du fichier : celle de l'import EDR (encodage, séparateur, BOM), réutilisée telle quelle.
 * Chaque ligne donne une personne (adresse ou identifiant) et cinq étapes : message reçu, ouvert,
 * lien cliqué, données saisies, signalé. Les étapes viennent de colonnes oui / non, ou de la colonne
 * « statut » des exports GoPhish (« Clicked Link », « Submitted Data »...). Aucune valeur n'est
 * évaluée : un texte qui ressemble à une formule de tableur reste un texte.
 */
final class PhishingRules
{
    /** Taille et nombre de lignes maximum d'un fichier de résultats. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 20000;

    /**
     * En dessous de ce nombre de messages reçus, les taux d'une campagne ne sont pas montrés à qui
     * n'a pas le droit de voir le détail : sur deux ou trois personnes, un taux désigne quelqu'un.
     */
    public const MIN_ANONYMOUS = 5;

    /** Étapes, dans l'ordre du parcours d'un message piégé. */
    public const STEPS = ['sent', 'opened', 'clicked', 'submitted', 'reported'];

    /**
     * Champs reconnus => en-têtes usuels normalisés (minuscules, sans séparateurs) des exports de
     * GoPhish, de Microsoft Attack Simulation Training et des outils de prestataires.
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'identifier' => [
            'email', 'emailaddress', 'mail', 'courriel', 'adresse', 'adressemail', 'adresseemail',
            'useremail', 'upn', 'userprincipalname', 'username', 'login', 'identifiant', 'user', 'utilisateur',
        ],
        'status'     => ['status', 'statut', 'result', 'resultat', 'lastevent', 'derniereaction'],
        'sent'       => [
            'sent', 'emailsent', 'senddate', 'delivered', 'envoye', 'recu', 'emailrecu', 'dateenvoi',
        ],
        'opened'     => ['opened', 'emailopened', 'open', 'ouvert', 'ouverture', 'messageread', 'read'],
        'clicked'    => ['clicked', 'clickedlink', 'click', 'linkclicked', 'clique', 'lienclique', 'clic'],
        'submitted'  => [
            'submitted', 'submitteddata', 'compromised', 'credentialsupplied', 'credentialssubmitted',
            'datasubmitted', 'saisi', 'donneessaisies', 'identifiantssaisis',
        ],
        'reported'   => ['reported', 'emailreported', 'report', 'signale', 'signalement'],
    ];

    /** Valeurs lues comme « non » dans une colonne d'étape ; toute autre valeur non vide vaut « oui ». */
    private const FALSE_VALUES = [
        '', '0', 'false', 'no', 'non', 'n', 'faux', 'nein', 'none', 'null', '-', 'never', 'jamais', 'f',
        'nie', 'nao', 'não', 'falso',
    ];

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, truncated: bool}
     */
    public static function parse(string $raw): array
    {
        $parsed = EdrCsvImport::parse($raw);
        if (count($parsed['rows']) > self::MAX_ROWS) {
            $parsed['rows'] = array_slice($parsed['rows'], 0, self::MAX_ROWS);
            $parsed['truncated'] = true;
        }

        return $parsed;
    }

    /**
     * Un fichier texte : pas d'octet nul (fichier binaire, tableur .xlsx renommé...).
     */
    public static function looksLikeText(string $raw): bool
    {
        return $raw !== '' && !str_contains($raw, "\0");
    }

    /**
     * En-tête normalisé comme pour l'import EDR, accents retirés (« Cliqué » donne « clique »).
     */
    public static function normalizeHeader(string $header): string
    {
        $folded = strtr(mb_strtolower($header), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ô' => 'o',
            'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ł' => 'l', 'ś' => 's',
            'ź' => 'z', 'ż' => 'z', 'ć' => 'c', 'ń' => 'n', 'ę' => 'e', 'ą' => 'a',
        ]);

        return EdrCsvImport::normalizeHeader($folded);
    }

    /**
     * Correspondance automatique : champ => index de colonne.
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
                    if (!isset($used[$index]) && self::normalizeHeader($header) === $alias) {
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
     * Correspondance envoyée par le formulaire, limitée aux champs connus et aux colonnes du fichier.
     *
     * @param array<mixed> $posted
     * @param list<string> $headers
     * @return array<string, int>
     */
    public static function sanitizeMapping(array $posted, array $headers): array
    {
        $mapping = [];
        foreach ($posted as $field => $index) {
            if (
                array_key_exists((string) $field, self::FIELDS) && is_scalar($index) && (string) $index !== ''
                && ctype_digit((string) $index) && isset($headers[(int) $index])
            ) {
                $mapping[(string) $field] = (int) $index;
            }
        }

        return $mapping;
    }

    public static function isTrue(string $value): bool
    {
        return !in_array(mb_strtolower(trim($value)), self::FALSE_VALUES, true);
    }

    /**
     * Étapes atteintes d'après le statut d'un export GoPhish (ou équivalent) : chaque étape inclut
     * les précédentes. Statut inconnu : aucune étape.
     *
     * @return array<string, bool>
     */
    public static function stepsFromStatus(string $status): array
    {
        $status = mb_strtolower(trim($status));
        $steps = array_fill_keys(self::STEPS, false);
        $level = match (true) {
            (bool) preg_match('/submit|compromis|saisi|credential/u', $status) => 4,
            (bool) preg_match('/click|cliqu/u', $status)                       => 3,
            (bool) preg_match('/open|ouvert|read|lu\b/u', $status)              => 2,
            (bool) preg_match('/report|signal/u', $status)                      => 1,
            (bool) preg_match('/sent|envoy|deliver|recu|reçu/u', $status)       => 1,
            default                                                             => 0,
        };
        $order = ['sent' => 1, 'opened' => 2, 'clicked' => 3, 'submitted' => 4];
        foreach ($order as $step => $rank) {
            $steps[$step] = $level >= $rank;
        }
        $steps['reported'] = (bool) preg_match('/report|signal/u', $status);

        return $steps;
    }

    /**
     * Résultat d'une ligne : identifiant et étapes atteintes. Une étape implique les précédentes
     * (un lien cliqué a été reçu et ouvert). Sans colonne « reçu » ni statut, une ligne du fichier
     * est une personne qui a reçu le message.
     *
     * @param list<string> $row
     * @param array<string, int> $mapping
     * @return array{identifier: string, sent: bool, opened: bool, clicked: bool, submitted: bool, reported: bool}
     */
    public static function readRow(array $row, array $mapping): array
    {
        $get = static fn (string $field): ?string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : null;

        $steps = isset($mapping['status'])
            ? self::stepsFromStatus((string) $get('status'))
            : array_fill_keys(self::STEPS, false);
        foreach (self::STEPS as $step) {
            $value = $get($step);
            if ($value !== null) {
                $steps[$step] = $steps[$step] || self::isTrue($value);
            }
        }
        if (!isset($mapping['sent']) && !isset($mapping['status'])) {
            $steps['sent'] = true;
        }
        $steps['clicked'] = $steps['clicked'] || $steps['submitted'];
        $steps['opened'] = $steps['opened'] || $steps['clicked'];
        $steps['sent'] = $steps['sent'] || $steps['opened'] || $steps['reported'];

        return ['identifier' => (string) $get('identifier')] + $steps;
    }

    /**
     * Identifiant gardé pour une ligne non rapprochée : 255 caractères au plus, sans caractère de
     * contrôle, et neutralisé s'il commence comme une formule de tableur (=, +, -, @).
     */
    public static function safeIdentifier(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = mb_substr(trim($value), 0, 254);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }

        return $value;
    }

    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Compteurs d'une liste de résultats.
     *
     * @param iterable<array<string, mixed>> $results lignes avec is_sent, is_opened... ou sent, opened...
     * @return array{sent: int, opened: int, clicked: int, submitted: int, reported: int}
     */
    public static function counts(iterable $results): array
    {
        $counts = array_fill_keys(self::STEPS, 0);
        foreach ($results as $result) {
            foreach (self::STEPS as $step) {
                if (!empty($result['is_' . $step] ?? $result[$step] ?? false)) {
                    $counts[$step]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Taux (en %, une décimale) rapportés aux messages reçus ; null sans message reçu.
     *
     * @param array<string, int> $counts
     * @return array{opened: ?float, clicked: ?float, submitted: ?float, reported: ?float}
     */
    public static function rates(array $counts): array
    {
        $sent = (int) ($counts['sent'] ?? 0);
        $rates = [];
        foreach (['opened', 'clicked', 'submitted', 'reported'] as $step) {
            $rates[$step] = $sent > 0 ? round(100 * (int) ($counts[$step] ?? 0) / $sent, 1) : null;
        }

        return $rates;
    }

    /**
     * Les taux peuvent-ils être montrés sans le détail ? Assez de personnes pour ne désigner
     * personne.
     */
    public static function isAnonymousEnough(int $sent): bool
    {
        return $sent >= self::MIN_ANONYMOUS;
    }

    /**
     * Tendance : campagnes triées par date, chacune avec ses taux et l'écart du taux de clic avec la
     * campagne précédente (en points).
     *
     * @param list<array{id: int, name: string, date: ?string, counts: array<string, int>}> $campaigns
     * @return list<array{
     *     id: int, name: string, date: ?string, counts: array<string, int>,
     *     rates: array<string, ?float>, click_delta: ?float
     * }>
     */
    public static function trend(array $campaigns): array
    {
        usort($campaigns, static fn (array $a, array $b): int
            => [(string) $a['date'], $a['id']] <=> [(string) $b['date'], $b['id']]);
        $previous = null;
        $trend = [];
        foreach ($campaigns as $campaign) {
            $rates = self::rates($campaign['counts']);
            $trend[] = $campaign + [
                'rates'       => $rates,
                'click_delta' => $previous !== null && $rates['clicked'] !== null
                    ? round($rates['clicked'] - $previous, 1) : null,
            ];
            if ($rates['clicked'] !== null) {
                $previous = $rates['clicked'];
            }
        }

        return $trend;
    }
}
