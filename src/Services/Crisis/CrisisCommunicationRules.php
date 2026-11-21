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

namespace GlpiPlugin\Grcmanager\Services\Crisis;

/**
 * Issue #616 : règles de la communication de crise, sans base de données (testées seules).
 *
 * Un modèle est du texte brut dont les variables s'écrivent entre accolades : {reference}. Le
 * remplissage produit encore du texte brut : il n'est jamais interprété comme du HTML (Twig
 * l'échappe à l'affichage) et ne sert qu'à préparer un message que la personne envoie elle-même.
 */
final class CrisisCommunicationRules
{
    public const SITUATION_MAJOR_INCIDENT = 'major_incident';
    public const SITUATION_DATA_BREACH = 'data_breach';
    public const SITUATION_UNAVAILABILITY = 'unavailability';
    public const SITUATION_RANSOMWARE = 'ransomware';
    public const SITUATION_OTHER = 'other';

    public const SITUATIONS = [
        self::SITUATION_MAJOR_INCIDENT, self::SITUATION_DATA_BREACH, self::SITUATION_UNAVAILABILITY,
        self::SITUATION_RANSOMWARE, self::SITUATION_OTHER,
    ];

    public const STAGES = ['first', 'update', 'end'];

    public const AUDIENCES = ['internal', 'external'];

    /** Variables reconnues, dans l'ordre où l'aide les présente. */
    public const VARIABLES = [
        'reference', 'title', 'incident_type', 'severity', 'detection_date', 'affected_systems', 'entity',
        'author', 'today', 'next_update',
    ];

    /** Types de destinataires d'une liste de diffusion. */
    public const RECIPIENT_USER = 'User';
    public const RECIPIENT_GROUP = 'Group';
    public const RECIPIENT_CONTACT = 'GlpiPlugin\\Grcmanager\\ContextItem';

    public const RECIPIENT_TYPES = [self::RECIPIENT_USER, self::RECIPIENT_GROUP, self::RECIPIENT_CONTACT];

    /** Longueurs des colonnes (texte coupé plutôt qu'une erreur SQL). */
    public const SUBJECT_MAX = 255;
    public const BODY_MAX = 60000;

    /**
     * Remplace les variables connues ; une variable sans valeur devient $missing (à compléter à la
     * main avant l'envoi), une accolade inconnue reste telle quelle.
     *
     * @param array<string, string> $values
     */
    public static function fill(string $text, array $values, string $missing): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $match) use ($values, $missing): string {
                $name = $match[1];
                if (!in_array($name, self::VARIABLES, true)) {
                    return $match[0];
                }
                $value = trim((string) ($values[$name] ?? ''));

                return $value === '' ? $missing : $value;
            },
            $text
        );
    }

    /**
     * Variables citées par un texte, connues ou non (aide à la rédaction d'un modèle).
     *
     * @return array{known: list<string>, unknown: list<string>}
     */
    public static function variablesIn(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $matches);
        $names = array_values(array_unique($matches[1]));

        return [
            'known'   => array_values(array_intersect($names, self::VARIABLES)),
            'unknown' => array_values(array_diff($names, self::VARIABLES)),
        ];
    }

    /**
     * Situation la plus proche d'un incident (catégorie du plugin) ou d'une violation de données.
     */
    public static function situationFor(string $category, bool $breach): string
    {
        if ($breach || $category === 'data_breach') {
            return self::SITUATION_DATA_BREACH;
        }

        return match ($category) {
            'availability' => self::SITUATION_UNAVAILABILITY,
            'malware'      => self::SITUATION_RANSOMWARE,
            default        => self::SITUATION_MAJOR_INCIDENT,
        };
    }

    /**
     * Modèles actifs triés pour un incident : ceux de sa situation d'abord (marqués recommandés),
     * puis par étape (premier message, point de situation, fin de crise) et par nom.
     *
     * @param list<array{id: int, name: string, situation: string, stage: string}> $templates
     * @return list<array{id: int, name: string, situation: string, stage: string, recommended: bool}>
     */
    public static function sortTemplates(array $templates, string $situation): array
    {
        $stageRank = array_flip(self::STAGES);
        foreach ($templates as &$template) {
            $template['recommended'] = $template['situation'] === $situation;
        }
        unset($template);
        usort($templates, static fn (array $a, array $b): int => [
            $a['recommended'] ? 0 : 1, $stageRank[$a['stage']] ?? 9, $a['name'],
        ] <=> [
            $b['recommended'] ? 0 : 1, $stageRank[$b['stage']] ?? 9, $b['name'],
        ]);

        return $templates;
    }

    /**
     * Issue #616, lot 2 : ordre d'affichage d'une liste de diffusion, chaque suppléant juste après
     * son destinataire principal. Un suppléant dont le principal n'est plus dans la liste (ou est
     * lui-même suppléant) est traité comme un principal.
     *
     * @param list<array{id: int, deputy_of: int}> $rows
     * @return list<array{id: int, deputy_of: int}> lignes ordonnées, deputy_of ramené à 0 si orphelin
     */
    public static function orderWithDeputies(array $rows): array
    {
        $parentOf = [];
        foreach ($rows as $row) {
            $parentOf[(int) $row['id']] = (int) $row['deputy_of'];
        }
        // Principal : sans lien, ou lié à une ligne absente, à lui-même ou elle-même suppléante.
        $isPrincipal = static function (int $id) use ($parentOf): bool {
            $parent = $parentOf[$id];

            return $parent <= 0 || $parent === $id || !isset($parentOf[$parent])
                || ($parentOf[$parent] > 0 && $parentOf[$parent] !== $parent && isset($parentOf[$parentOf[$parent]]));
        };
        $principals = [];
        $deputies = [];
        foreach ($rows as $row) {
            if ($isPrincipal((int) $row['id'])) {
                $principals[] = ['deputy_of' => 0] + $row;
            } else {
                $deputies[(int) $row['deputy_of']][] = $row;
            }
        }
        $ordered = [];
        foreach ($principals as $row) {
            $ordered[] = $row;
            foreach ($deputies[(int) $row['id']] ?? [] as $deputy) {
                $ordered[] = $deputy;
            }
        }

        return $ordered;
    }

    /**
     * Destinataires principaux sans suppléant dans une liste ordonnée par orderWithDeputies(). Un
     * groupe ne compte pas : ses membres se suppléent déjà.
     *
     * @param list<array{id: int, deputy_of: int, itemtype?: string}> $ordered
     */
    public static function principalsWithoutDeputy(array $ordered): int
    {
        $withDeputy = array_unique(array_filter(array_map(
            static fn (array $row): int => (int) $row['deputy_of'],
            $ordered
        )));
        $count = 0;
        foreach ($ordered as $row) {
            $isGroup = ($row['itemtype'] ?? '') === self::RECIPIENT_GROUP;
            if (!$isGroup && (int) $row['deputy_of'] === 0 && !in_array((int) $row['id'], $withDeputy, true)) {
                $count++;
            }
        }

        return $count;
    }

    public static function recipientKey(string $itemtype, int $id): string
    {
        return $itemtype . ':' . $id;
    }

    /**
     * Destinataires cochés retenus : seulement ceux proposés à la personne (liste du modèle filtrée
     * par ses droits et l'entité de l'incident), sans doublon. Toute autre valeur est ignorée.
     *
     * @param mixed $raw valeurs du formulaire
     * @param list<string> $allowed clés proposées
     * @return list<string>
     */
    public static function selectedRecipients(mixed $raw, array $allowed): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $selected = [];
        foreach ($raw as $value) {
            if (is_string($value) && in_array($value, $allowed, true) && !in_array($value, $selected, true)) {
                $selected[] = $value;
            }
        }

        return $selected;
    }

    /**
     * Autres destinataires saisis à la main (presse, client...) : une ligne par destinataire,
     * lignes vides retirées, 20 au plus, 255 caractères chacune.
     *
     * @return list<string>
     */
    public static function otherRecipients(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(self::singleLine($line));
            if ($line !== '' && !in_array($line, $lines, true)) {
                $lines[] = mb_substr($line, 0, 255);
            }
        }

        return array_slice($lines, 0, 20);
    }

    /**
     * Objet d'un message : une seule ligne (pas de saut de ligne dans un en-tête de courriel exporté).
     */
    public static function subject(string $subject): string
    {
        return mb_substr(trim(self::singleLine($subject)), 0, self::SUBJECT_MAX);
    }

    public static function body(string $body): string
    {
        return mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, self::BODY_MAX);
    }

    private static function singleLine(string $text): string
    {
        return (string) preg_replace('/[\r\n\t]+/', ' ', $text);
    }

    /**
     * Date de la communication saisie (AAAA-MM-JJ HH:MM, éventuellement avec un « T » et des
     * secondes), $now si vide. Null si invalide ou dans le futur : une communication tracée a eu lieu.
     */
    public static function communicationDate(string $value, string $now): ?string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            return $now;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(
            strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s',
            $value
        );
        if ($date === false) {
            return null;
        }
        $normalized = $date->format('Y-m-d H:i:s');
        if (substr($normalized, 0, 16) !== substr($value, 0, 16) || $normalized > $now) {
            return null;
        }

        return $normalized;
    }

    /**
     * Synthèse de la preuve (A.5.26, clause 7.4) : modèles actifs, modèles sans destinataire, et
     * situations attendues encore sans modèle actif.
     *
     * Lot 2 : listes de diffusion jamais revues ou revues il y a plus de 12 mois (clé facultative
     * « review_due »), et destinataires principaux sans suppléant (« without_deputy »).
     *
     * @param list<array{situation: string, recipients: int, review_due?: bool, without_deputy?: int}> $activeTemplates
     * @return array{templates: int, without_recipients: int, missing_situations: list<string>,
     *     review_due: int, without_deputy: int}
     */
    public static function readiness(array $activeTemplates): array
    {
        $covered = array_unique(array_column($activeTemplates, 'situation'));
        $expected = [
            self::SITUATION_MAJOR_INCIDENT, self::SITUATION_DATA_BREACH, self::SITUATION_UNAVAILABILITY,
            self::SITUATION_RANSOMWARE,
        ];

        return [
            'templates'          => count($activeTemplates),
            'without_recipients' => count(array_filter(
                $activeTemplates,
                static fn (array $template): bool => $template['recipients'] === 0
            )),
            'missing_situations' => array_values(array_diff($expected, $covered)),
            'review_due'         => count(array_filter(
                $activeTemplates,
                static fn (array $template): bool => !empty($template['review_due'])
            )),
            'without_deputy'     => array_sum(array_map(
                static fn (array $template): int => (int) ($template['without_deputy'] ?? 0),
                $activeTemplates
            )),
        ];
    }
}
