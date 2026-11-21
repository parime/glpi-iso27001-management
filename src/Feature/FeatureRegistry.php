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

namespace GlpiPlugin\Grcmanager\Feature;

/**
 * Fonctionnalités découvertes : chaque fichier src/Feature/<Fonctionnalité>Feature.php déclare ce
 * que la fonctionnalité ajoute aux listes communes du plugin (cartes du tableau de bord, sources de
 * KRI, cadres de preuve sur la fiche d'une mesure) en implémentant les interfaces voulues. Une
 * nouvelle fonctionnalité ajoute ainsi un fichier au lieu d'allonger hook.php, RiskIndicator.php,
 * Control.php et control_form.html.twig, que toutes les branches modifient en même temps.
 *
 * Ordre : alphabétique des noms de fichier ; une entrée peut demander à suivre une entrée existante
 * (clé « after ») pour garder l'ordre d'affichage, sinon elle va à la fin.
 */
final class FeatureRegistry
{
    /** @var array<string, list<class-string>> */
    private static array $cache = [];

    /**
     * @template T of object
     * @param class-string<T> $interface
     * @return list<class-string<T>>
     */
    public static function implementing(string $interface): array
    {
        if (!isset(self::$cache[$interface])) {
            $files = glob(__DIR__ . '/*Feature.php') ?: [];
            sort($files, SORT_STRING);
            $found = [];
            foreach ($files as $file) {
                $class = __NAMESPACE__ . '\\' . basename($file, '.php');
                if (class_exists($class) && is_subclass_of($class, $interface)) {
                    $found[] = $class;
                }
            }
            self::$cache[$interface] = $found;
        }

        /** @var list<class-string<T>> */
        return self::$cache[$interface];
    }

    /**
     * Ajoute des entrées à une liste associative : chacune après la clé de son « after » si elle
     * existe, sinon à la fin. La clé « after » est retirée de l'entrée.
     *
     * @param array<string, array<string, mixed>> $list
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    public static function insertAfter(array $list, array $entries): array
    {
        foreach ($entries as $key => $entry) {
            $after = (string) ($entry['after'] ?? '');
            unset($entry['after']);
            unset($list[$key]);
            $position = $after !== '' ? array_search($after, array_keys($list), true) : false;
            if ($position === false) {
                $list[$key] = $entry;
                continue;
            }
            $list = array_slice($list, 0, $position + 1, true) + [$key => $entry]
                + array_slice($list, $position + 1, null, true);
        }

        return $list;
    }

    /**
     * Cartes du tableau de bord des fonctionnalités, ajoutées au catalogue du plugin.
     *
     * @param array<string, array<string, mixed>> $cards
     * @return array<string, array<string, mixed>>
     */
    public static function dashboardCards(array $cards, string $group): array
    {
        foreach (self::implementing(DashboardCardsProvider::class) as $class) {
            $own = [];
            foreach ($class::dashboardCards() as $key => $card) {
                $own[$key] = $card + ['group' => $group];
            }
            $cards = self::insertAfter($cards, $own);
        }

        return $cards;
    }

    /**
     * Sources automatiques de KRI des fonctionnalités.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function riskIndicatorSources(): array
    {
        $sources = [];
        foreach (self::implementing(RiskIndicatorSourcesProvider::class) as $class) {
            $sources += $class::riskIndicatorSources();
        }

        return $sources;
    }

    /**
     * Cadres de preuve des fonctionnalités pour une mesure, dans l'ordre des fichiers.
     *
     * @return list<array{title: string, template: string, data: array<string, mixed>}>
     */
    public static function controlEvidence(string $code): array
    {
        $boxes = [];
        foreach (self::implementing(ControlEvidenceProvider::class) as $class) {
            $data = $class::controlEvidence($code);
            if ($data !== null) {
                $boxes[] = [
                    'title'    => $class::controlEvidenceTitle(),
                    'template' => $class::controlEvidenceTemplate(),
                    'data'     => $data,
                ];
            }
        }

        return $boxes;
    }
}
