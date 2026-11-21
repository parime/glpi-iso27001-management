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

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Issue #545 : catalogue de modèles de politiques de sécurité prêts à adapter. Règles pures, sans
 * base de données :
 *
 * - la structure des modèles (sections, mesures de l'Annexe A couvertes par chaque section) est
 *   commune aux langues et décrite ici ;
 * - le texte, rédigé par le projet (aucun texte de norme recopié) et placé sous CC0 1.0, est dans
 *   resources/policy-templates/<langue>.json (français et anglais ; les autres langues de
 *   l'interface reçoivent l'anglais) ;
 * - le rendu remplace les variables {organisation}, {ciso} et {review_months} ; une variable
 *   laissée vide devient un repère entre crochets à compléter.
 */
final class PolicyTemplateCatalog
{
    public const LANGUAGES = ['fr', 'en'];
    public const DEFAULT_LANGUAGE = 'en';
    public const LICENSE = 'CC0-1.0';

    public const REVIEW_MONTHS_DEFAULT = 12;
    public const REVIEW_MONTHS_MIN = 1;
    public const REVIEW_MONTHS_MAX = 36;

    /** Section commune ajoutée à la fin de chaque modèle. */
    public const COMMON_REVIEW = 'review';

    /**
     * Modèle => section => mesures de l'Annexe A (ISO/IEC 27001:2022) couvertes. Une section sans
     * mesure (objet et périmètre) n'est reliée à rien. Toute politique thématique relève de A.5.1,
     * reliée par la section commune de revue.
     */
    public const STRUCTURE = [
        'isms'                => [
            'purpose'     => [],
            'commitments' => ['A.5.1'],
            'roles'       => ['A.5.2', 'A.5.3', 'A.5.4'],
            'compliance'  => ['A.5.36', 'A.6.3', 'A.6.4'],
        ],
        'access_control'      => [
            'purpose'        => [],
            'principles'     => ['A.5.15'],
            'lifecycle'      => ['A.5.16', 'A.5.18'],
            'authentication' => ['A.5.17', 'A.8.5'],
            'privileged'     => ['A.8.2', 'A.8.3'],
        ],
        'asset_management'    => [
            'purpose'        => [],
            'inventory'      => ['A.5.9'],
            'acceptable_use' => ['A.5.10'],
            'return'         => ['A.5.11'],
            'disposal'       => ['A.7.10', 'A.7.14'],
        ],
        'backup'              => [
            'purpose'    => [],
            'frequency'  => ['A.8.13'],
            'protection' => ['A.8.13'],
            'restore'    => ['A.8.13'],
        ],
        'logging'             => [
            'purpose'    => [],
            'events'     => ['A.8.15'],
            'protection' => ['A.8.15', 'A.8.17'],
            'monitoring' => ['A.8.16'],
        ],
        'incident_management' => [
            'purpose'    => [],
            'reporting'  => ['A.6.8'],
            'assessment' => ['A.5.25'],
            'response'   => ['A.5.24', 'A.5.26'],
            'evidence'   => ['A.5.28'],
            'lessons'    => ['A.5.27'],
        ],
        'supplier'            => [
            'purpose'      => [],
            'selection'    => ['A.5.19'],
            'agreements'   => ['A.5.20'],
            'supply_chain' => ['A.5.21'],
            'monitoring'   => ['A.5.22'],
            'cloud'        => ['A.5.23'],
        ],
        'teleworking'         => [
            'purpose'    => [],
            'conditions' => ['A.6.7'],
            'equipment'  => ['A.8.1', 'A.7.9'],
            'connection' => ['A.6.7'],
            'workplace'  => ['A.7.7'],
        ],
        'secure_development'  => [
            'purpose'      => [],
            'lifecycle'    => ['A.8.25'],
            'requirements' => ['A.8.26', 'A.8.27'],
            'coding'       => ['A.8.28'],
            'testing'      => ['A.8.29', 'A.8.33'],
            'environments' => ['A.8.31', 'A.8.32'],
            'outsourced'   => ['A.8.30'],
        ],
        'cryptography'        => [
            'purpose'    => [],
            'usage'      => ['A.8.24'],
            'algorithms' => ['A.8.24'],
            'keys'       => ['A.8.24'],
        ],
        'continuity'          => [
            'purpose'    => [],
            'impact'     => ['A.5.30'],
            'security'   => ['A.5.29'],
            'redundancy' => ['A.8.14'],
            'exercises'  => ['A.5.30'],
        ],
        'classification'      => [
            'purpose'   => [],
            'levels'    => ['A.5.12'],
            'labelling' => ['A.5.13'],
            'handling'  => ['A.5.10', 'A.5.14'],
        ],
    ];

    /** Mesures reliées par la section commune de revue. */
    public const COMMON_REVIEW_CONTROLS = ['A.5.1'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::STRUCTURE);
    }

    /** Langue du texte : celle demandée si elle existe, sinon déduite de la langue de l'interface. */
    public static function language(?string $requested, string $uiLanguage = ''): string
    {
        if ($requested !== null && in_array($requested, self::LANGUAGES, true)) {
            return $requested;
        }
        $prefix = strtolower(substr($uiLanguage, 0, 2));

        return in_array($prefix, self::LANGUAGES, true) ? $prefix : self::DEFAULT_LANGUAGE;
    }

    /** Périodicité de revue, en mois, ramenée dans les bornes. */
    public static function reviewMonths(mixed $value): int
    {
        $months = is_numeric($value) ? (int) $value : self::REVIEW_MONTHS_DEFAULT;

        return max(self::REVIEW_MONTHS_MIN, min(self::REVIEW_MONTHS_MAX, $months));
    }

    /**
     * Contenu brut d'une langue (fichier JSON du projet).
     *
     * @return array<string, mixed>
     */
    public static function load(string $language, ?string $directory = null): array
    {
        $language = self::language($language);
        $directory ??= dirname(__DIR__, 3) . '/resources/policy-templates';
        $raw = @file_get_contents($directory . '/' . $language . '.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * Modèles d'une langue, dans l'ordre du catalogue, avec leurs sections numérotées et les
     * mesures de chaque section.
     *
     * @param array<string, mixed> $data contenu chargé par load()
     * @return array<string, array{title: string, summary: string, controls: list<string>,
     *     sections: list<array{key: string, title: string, body: string, controls: list<string>}>}>
     */
    public static function templates(array $data): array
    {
        $templates = [];
        $review = $data['common'][self::COMMON_REVIEW] ?? [];
        $review = is_array($review) ? $review : [];
        foreach (self::STRUCTURE as $key => $sections) {
            $text = is_array($data['templates'][$key] ?? null) ? $data['templates'][$key] : null;
            if ($text === null) {
                continue;
            }
            $list = [];
            $number = 0;
            $sections[self::COMMON_REVIEW] = self::COMMON_REVIEW_CONTROLS;
            foreach ($sections as $sectionKey => $controls) {
                $section = $sectionKey === self::COMMON_REVIEW ? $review : ($text['sections'][$sectionKey] ?? []);
                $number++;
                $list[] = [
                    'key'      => $sectionKey,
                    'title'    => $number . '. ' . (string) ($section['title'] ?? $sectionKey),
                    'body'     => (string) ($section['body'] ?? ''),
                    'controls' => $controls,
                ];
            }
            $all = array_values(array_unique(array_merge(...array_column($list, 'controls'))));
            usort($all, 'strnatcmp');
            $templates[$key] = [
                'title'    => (string) ($text['title'] ?? $key),
                'summary'  => (string) ($text['summary'] ?? ''),
                'controls' => $all,
                'sections' => $list,
            ];
        }

        return $templates;
    }

    /**
     * Remplace les variables. Une valeur vide devient le repère entre crochets de la langue.
     *
     * @param array<string, mixed> $data contenu chargé par load()
     * @param array{organisation?: string, ciso?: string, review_months?: int} $values
     */
    public static function fill(string $text, array $data, array $values): string
    {
        $placeholders = is_array($data['placeholders'] ?? null) ? $data['placeholders'] : [];
        $replace = [];
        foreach (['organisation', 'ciso'] as $name) {
            $value = trim((string) ($values[$name] ?? ''));
            $fallback = (string) ($placeholders[$name] ?? '[' . $name . ']');
            $replace['{' . $name . '}'] = $value !== '' ? $value : $fallback;
        }
        $replace['{review_months}'] = (string) self::reviewMonths($values['review_months'] ?? null);

        return strtr($text, $replace);
    }

    /**
     * Texte complet de la politique créée : sections numérotées, variables remplacées.
     *
     * @param array{sections: list<array{title: string, body: string}>} $template
     * @param array<string, mixed> $data
     * @param array{organisation?: string, ciso?: string, review_months?: int} $values
     */
    public static function render(array $template, array $data, array $values): string
    {
        $parts = [];
        foreach ($template['sections'] as $section) {
            $parts[] = $section['title'] . "\n" . self::fill($section['body'], $data, $values);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Modèles demandés qui existent, sans doublon, dans l'ordre du catalogue.
     *
     * @param array<int|string, mixed> $requested
     * @return list<string>
     */
    public static function requested(array $requested): array
    {
        $wanted = array_map('strval', array_values($requested));

        return array_values(array_intersect(self::keys(), $wanted));
    }
}
