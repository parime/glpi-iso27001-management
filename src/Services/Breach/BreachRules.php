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

/**
 * Issue #183 : règles de la surveillance des fuites, sans dépendance à GLPI.
 */
final class BreachRules
{
    /**
     * Domaines saisis par l'administrateur (séparés par des virgules, espaces ou retours à la ligne) :
     * en minuscules, sans « @ » ni doublon ; un texte qui n'est pas un nom de domaine est ignoré.
     *
     * @return list<string>
     */
    public static function normalizeDomains(string $input): array
    {
        $domains = [];
        foreach (preg_split('/[\s,;]+/', strtolower($input)) ?: [] as $domain) {
            $domain = ltrim(trim($domain), '@');
            if (
                $domain !== ''
                && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
                && !in_array($domain, $domains, true)
            ) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * Domaine d'une adresse, en minuscules ('' si l'adresse n'en est pas une).
     */
    public static function domainOf(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');

        return $at === false || $at === 0 ? '' : substr($email, $at + 1);
    }

    /**
     * Adresse surveillée : sur un domaine professionnel configuré. Les adresses personnelles ne sont
     * jamais envoyées à Have I Been Pwned.
     *
     * @param list<string> $domains
     */
    public static function isMonitored(string $email, array $domains): bool
    {
        return in_array(self::domainOf($email), $domains, true);
    }

    /**
     * Ce qui est conservé d'une fuite (minimisation) : jamais de description ni de compte.
     * null pour une fuite fabriquée ou retirée (sans objet).
     *
     * @param array<string, mixed> $breach Modèle de fuite de l'API
     * @return array{breach_name: string, breach_title: string, breach_domain: string, breach_date: ?string,
     *     added_date: ?string, data_classes: list<string>, is_verified: int, is_spam_list: int}|null
     */
    public static function summarize(array $breach): ?array
    {
        $name = (string) ($breach['Name'] ?? '');
        if ($name === '' || !empty($breach['IsFabricated']) || !empty($breach['IsRetired'])) {
            return null;
        }
        $date = static function (mixed $value): ?string {
            $time = is_string($value) && $value !== '' ? strtotime($value) : false;

            return $time === false ? null : date('Y-m-d H:i:s', $time);
        };

        return [
            'breach_name'   => mb_substr($name, 0, 255),
            'breach_title'  => mb_substr((string) ($breach['Title'] ?? $name), 0, 255),
            'breach_domain' => mb_substr((string) ($breach['Domain'] ?? ''), 0, 255),
            'breach_date'   => $date($breach['BreachDate'] ?? null),
            'added_date'    => $date($breach['AddedDate'] ?? null),
            'data_classes'  => array_values(array_map('strval', (array) ($breach['DataClasses'] ?? []))),
            'is_verified'   => empty($breach['IsVerified']) ? 0 : 1,
            'is_spam_list'  => empty($breach['IsSpamList']) ? 0 : 1,
        ];
    }

    /**
     * Fuite qui a exposé des mots de passe (même chiffrés) : action prioritaire.
     *
     * @param list<string> $dataClasses
     */
    public static function exposesPasswords(array $dataClasses): bool
    {
        foreach ($dataClasses as $class) {
            if (stripos($class, 'password') !== false) {
                return true;
            }
        }

        return false;
    }
}
