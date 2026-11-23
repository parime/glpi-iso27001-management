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

namespace GlpiPlugin\Grcmanager\Services\Workspace;

/**
 * Issue #877 : règles pures (sans GLPI) du récapitulatif personnel par e-mail, qui reprend le
 * contenu de « Mon espace GRC ».
 *
 * - Fréquence choisie par chaque personne : chaque jour, chaque semaine (par défaut) ou jamais
 *   (seulement les notifications habituelles, envoyées au fil de l'eau).
 * - Un récapitulatif est dû une fois par jour (quotidien) ou une fois tous les sept jours
 *   (hebdomadaire) depuis le dernier envoi ; un récapitulatif vide n'est jamais envoyé.
 * - Les éléments vus avec chacun des profils de la personne sont réunis sans doublon, puis triés
 *   comme la page (MySpaceRules::compare()).
 */
final class PersonalDigestRules
{
    public const FREQUENCY_DAILY  = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_NEVER  = 'never';

    /** Fréquence d'une personne qui n'a rien choisi. */
    public const DEFAULT_FREQUENCY = self::FREQUENCY_WEEKLY;

    /** Lignes détaillées dans l'e-mail ; au-delà, un renvoi vers « Mon espace GRC ». */
    public const MAX_LISTED = 50;

    /** Personnes examinées par exécution de la tâche (toutes les heures) : charge bornée. */
    public const USERS_PER_RUN = 100;

    /** @return list<string> */
    public static function frequencies(): array
    {
        return [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_NEVER];
    }

    /** Valeur enregistrée ramenée à une fréquence connue ; vide ou inconnue : la valeur par défaut. */
    public static function normalizeFrequency(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, self::frequencies(), true) ? $value : self::DEFAULT_FREQUENCY;
    }

    /**
     * Le récapitulatif est-il dû aujourd'hui ? Jamais pour « jamais » ; toujours s'il n'a jamais
     * été envoyé ; sinon le lendemain (quotidien) ou sept jours après (hebdomadaire) le dernier envoi.
     */
    public static function isDue(string $frequency, ?string $lastSent, string $today): bool
    {
        $frequency = self::normalizeFrequency($frequency);
        if ($frequency === self::FREQUENCY_NEVER) {
            return false;
        }
        $last = MySpaceRules::normalizeDate($lastSent);
        if ($last === null) {
            return true;
        }
        if ($frequency === self::FREQUENCY_DAILY) {
            return $last < $today;
        }

        return $last <= (new \DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
    }

    /**
     * Réunit les éléments lus avec chaque profil : un élément vu avec deux profils n'apparaît
     * qu'une fois ; tri de la page (retards d'abord, sans échéance à la fin).
     *
     * @param list<list<array{source: string, id: int, name: string, due: ?string, url: string,
     *     detail: string, urgency: string, days_late: int}>> $lists
     * @return list<array{source: string, id: int, name: string, due: ?string, url: string,
     *     detail: string, urgency: string, days_late: int}>
     */
    public static function merge(array $lists): array
    {
        $items = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                $items[$item['source'] . '|' . $item['id'] . '|' . $item['url'] . '|' . ($item['due'] ?? '')] ??= $item;
            }
        }
        $items = array_values($items);
        usort($items, [MySpaceRules::class, 'compare']);

        return $items;
    }

    /**
     * Adresse complète d'un lien de la page (« /glpi/plugins/... ») pour l'e-mail : le préfixe
     * d'installation est remplacé par l'adresse publique de GLPI. Une adresse déjà complète est
     * gardée.
     */
    public static function absoluteUrl(string $url, string $rootDoc, string $urlBase): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $rootDoc = rtrim($rootDoc, '/');
        if ($rootDoc !== '' && str_starts_with($url, $rootDoc . '/')) {
            $url = substr($url, strlen($rootDoc));
        }

        return rtrim($urlBase, '/') . '/' . ltrim($url, '/');
    }
}
