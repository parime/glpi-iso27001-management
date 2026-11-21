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

    /** Durée de conservation des résultats individuels (mois) : 0 = jusqu'à suppression manuelle. */
    public const MAX_RETENTION_MONTHS = 120;

    /** Clé du regroupement des services de moins de MIN_ANONYMOUS personnes. */
    public const OTHER_GROUPS = -1;

    public static function normalizeRetention(mixed $months): int
    {
        $value = is_numeric($months) ? (int) $months : 0;

        return max(0, min(self::MAX_RETENTION_MONTHS, $value));
    }

    /**
     * Date (Y-m-d) avant laquelle une campagne dépasse la durée de conservation ; null sans durée.
     */
    public static function retentionLimit(int $months, string $today): ?string
    {
        $months = self::normalizeRetention($months);
        if ($months === 0) {
            return null;
        }
        $date = \DateTime::createFromFormat('!Y-m-d', substr($today, 0, 10));
        if ($date === false) {
            return null;
        }

        return $date->modify('-' . $months . ' months')->format('Y-m-d');
    }

    /**
     * Date de référence d'une campagne pour la conservation : la date de la campagne, sinon celle
     * de l'import.
     *
     * @param array<string, mixed> $campaign
     */
    public static function referenceDate(array $campaign): ?string
    {
        foreach (['campaign_date', 'date_import'] as $field) {
            $value = trim((string) ($campaign[$field] ?? ''));
            if ($value !== '') {
                return substr($value, 0, 10);
            }
        }

        return null;
    }

    /**
     * Date (Y-m-d) à laquelle les résultats individuels d'une campagne seront supprimés ; null sans
     * durée de conservation ou sans date.
     *
     * @param array<string, mixed> $campaign
     */
    public static function purgeDate(array $campaign, int $months): ?string
    {
        $months = self::normalizeRetention($months);
        $reference = self::referenceDate($campaign);
        if ($months === 0 || $reference === null) {
            return null;
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $reference);

        return $date === false ? null : $date->modify('+' . $months . ' months')->format('Y-m-d');
    }

    /**
     * Compteurs par service (groupe GLPI) des personnes rapprochées. Une personne de plusieurs
     * groupes compte dans chacun ; une personne sans groupe compte sous la clé 0.
     *
     * @param array<int, array<string, mixed>> $byUser utilisateur => étapes
     * @param array<int, list<int>> $groupsByUser utilisateur => groupes
     * @return array<int, array{sent: int, opened: int, clicked: int, submitted: int, reported: int}>
     */
    public static function groupCounts(array $byUser, array $groupsByUser): array
    {
        $byGroup = [];
        foreach ($byUser as $usersId => $result) {
            $groups = array_values(array_unique(array_map('intval', $groupsByUser[$usersId] ?? [])));
            foreach ($groups === [] ? [0] : $groups as $groupId) {
                $byGroup[$groupId][] = $result;
            }
        }
        $counts = [];
        foreach ($byGroup as $groupId => $results) {
            $counts[$groupId] = self::counts($results);
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Compteurs par service relus de la base (JSON), nettoyés : clés entières, étapes entières.
     *
     * @return array<int, array{sent: int, opened: int, clicked: int, submitted: int, reported: int}>
     */
    public static function decodeGroupCounts(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $counts = [];
        foreach ($decoded as $groupId => $row) {
            if (!is_numeric($groupId) || !is_array($row)) {
                continue;
            }
            foreach (self::STEPS as $step) {
                $counts[(int) $groupId][$step] = max(0, (int) ($row[$step] ?? 0));
            }
        }

        return $counts;
    }

    /**
     * Lignes par service à afficher. Sans le droit du détail, les services de moins de
     * MIN_ANONYMOUS personnes sont regroupés sous OTHER_GROUPS, masqué lui aussi s'il reste trop
     * petit.
     *
     * @param array<int, array<string, int>> $groupCounts
     * @return list<array{groups_id: int, counts: array<string, int>, rates: array<string, ?float>, hidden: bool}>
     */
    public static function groupRows(array $groupCounts, bool $canDetail): array
    {
        $rows = [];
        $other = [];
        foreach ($groupCounts as $groupId => $counts) {
            if (!$canDetail && !self::isAnonymousEnough((int) ($counts['sent'] ?? 0))) {
                $other[] = $counts;
                continue;
            }
            $rows[] = ['groups_id' => (int) $groupId, 'counts' => $counts];
        }
        if ($other !== []) {
            $sum = array_fill_keys(self::STEPS, 0);
            foreach ($other as $counts) {
                foreach (self::STEPS as $step) {
                    $sum[$step] += (int) ($counts[$step] ?? 0);
                }
            }
            $rows[] = ['groups_id' => self::OTHER_GROUPS, 'counts' => $sum];
        }
        foreach ($rows as $i => $row) {
            $hidden = !$canDetail && !self::isAnonymousEnough($row['counts']['sent']);
            $rates = self::rates($row['counts']);
            $rows[$i]['rates'] = $hidden ? array_fill_keys(array_keys($rates), null) : $rates;
            $rows[$i]['hidden'] = $hidden;
        }

        return $rows;
    }

    /**
     * Indicateurs par entité : nombre de campagnes mesurées et taux de la dernière, de la plus
     * ancienne à la plus récente pour chaque entité.
     *
     * @param list<array{entities_id: int, date: ?string, id: int, counts: array<string, int>}> $campaigns
     * @return array<int, array{
     *     campaigns: int, last_date: ?string, counts: array<string, int>, rates: array<string, ?float>
     * }>
     */
    public static function entityRows(array $campaigns): array
    {
        usort($campaigns, static fn (array $a, array $b): int
            => [(string) $a['date'], $a['id']] <=> [(string) $b['date'], $b['id']]);
        $rows = [];
        foreach ($campaigns as $campaign) {
            if ((int) ($campaign['counts']['sent'] ?? 0) === 0) {
                continue;
            }
            $entityId = (int) $campaign['entities_id'];
            $rows[$entityId] = [
                'campaigns' => ($rows[$entityId]['campaigns'] ?? 0) + 1,
                'last_date' => $campaign['date'],
                'counts'    => $campaign['counts'],
                'rates'     => self::rates($campaign['counts']),
            ];
        }

        return $rows;
    }
}
