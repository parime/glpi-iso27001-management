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

namespace GlpiPlugin\Grcmanager\Services\Dora;

/**
 * Issue #540 : formats attendus par le registre d'informations DORA (LEI, codes pays et devises,
 * types de services, codes des prestataires) et nettoyage des saisies. PHP pur, testé seul.
 */
final class RegisterRules
{
    /**
     * LEI (ISO 17442) : 20 caractères alphanumériques dont les deux derniers sont une clé de
     * contrôle ISO 7064 MOD 97-10, comme un IBAN.
     */
    public static function isLei(string $value): bool
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9]{18}[0-9]{2}$/', $value) !== 1) {
            return false;
        }
        $digits = '';
        foreach (str_split($value) as $char) {
            $digits .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    public static function isCountry(string $value): bool
    {
        return preg_match('/^[A-Z]{2}$/', $value) === 1
            && in_array($value, explode(' ', RegisterTemplates::COUNTRIES), true);
    }

    /** Code ISO 4217 alphabétique (trois lettres). */
    public static function isCurrency(string $value): bool
    {
        return preg_match('/^[A-Z]{3}$/', $value) === 1;
    }

    /** Pays saisi (« fr ») ramené au code ISO, ou '' s'il n'en est pas un. */
    public static function country(mixed $value): string
    {
        $code = strtoupper(trim(is_scalar($value) ? (string) $value : ''));

        return self::isCountry($code) ? $code : '';
    }

    /**
     * Liste de pays (« fr, DE ; ie ») : codes ISO valides, sans doublon, dans l'ordre saisi.
     *
     * @return list<string>
     */
    public static function countries(mixed $value): array
    {
        $parts = preg_split('/[\s,;]+/', strtoupper(is_scalar($value) ? (string) $value : '')) ?: [];
        $codes = [];
        foreach ($parts as $part) {
            if (self::isCountry($part) && !in_array($part, $codes, true)) {
                $codes[] = $part;
            }
        }

        return $codes;
    }

    public static function currency(mixed $value): string
    {
        $code = strtoupper(trim(is_scalar($value) ? (string) $value : ''));

        return self::isCurrency($code) ? $code : '';
    }

    /**
     * Types de services TIC cochés (S01 à S19), sans doublon, dans l'ordre de l'annexe III.
     *
     * @return list<string>
     */
    public static function serviceTypes(mixed $value): array
    {
        $values = is_array($value) ? $value : (preg_split('/[\s,;]+/', is_scalar($value) ? (string) $value : '') ?: []);
        $values = array_map(static fn ($v): string => strtoupper(trim(is_scalar($v) ? (string) $v : '')), $values);

        return array_values(array_filter(
            array_keys(RegisterTemplates::SERVICE_TYPES),
            static fn (string $code): bool => in_array($code, $values, true)
        ));
    }

    /**
     * Option d'une liste fermée (clé entière), ou 0 si la valeur n'en fait pas partie.
     *
     * @param array<int, string> $options
     */
    public static function option(mixed $value, array $options): int
    {
        $key = is_numeric($value) ? (int) $value : 0;

        return isset($options[$key]) ? $key : 0;
    }

    /** Nombre de jours (préavis), ou null si vide ou négatif. */
    public static function days(mixed $value): ?int
    {
        if (!is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    /** Montant positif ou nul, arrondi au centime, ou null si vide. */
    public static function amount(mixed $value): ?float
    {
        $text = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], is_scalar($value) ? (string) $value : '');
        if ($text === '' || !is_numeric($text) || (float) $text < 0) {
            return null;
        }

        return round((float) $text, 2);
    }

    /** Date ISO 8601 (aaaa-mm-jj) valide, ou null. */
    public static function date(mixed $value): ?string
    {
        $text = trim(is_scalar($value) ? (string) $value : '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $text : null;
    }

    /**
     * Type de code d'un prestataire tel que l'écrit le modèle B_05.01.0020 : « LEI », « EUID », ou
     * pays d'émission et type pour un code national (« FR_CRN »). '' si incomplet.
     */
    public static function codeType(string $type, string $country = ''): string
    {
        $type = strtoupper(trim($type));
        if (in_array($type, [RegisterTemplates::CODE_LEI, RegisterTemplates::CODE_EUID], true)) {
            return $type;
        }
        if (!in_array($type, RegisterTemplates::NATIONAL_CODES, true)) {
            return '';
        }
        $country = self::country($country);

        return $country === '' ? '' : $country . '_' . $type;
    }

    /**
     * Le code d'un prestataire est-il cohérent avec son type ? Un LEI doit en être un ; une
     * personne morale n'est identifiée que par un LEI ou un EUID (B_05.01.0020).
     */
    public static function isProviderCodeValid(string $code, string $type, int $personType): bool
    {
        $type = strtoupper($type);
        if ($code === '' || $type === '') {
            return false;
        }
        if ($type === RegisterTemplates::CODE_LEI) {
            return self::isLei($code);
        }
        if ($personType === RegisterTemplates::LEGAL_PERSON) {
            return $type === RegisterTemplates::CODE_EUID;
        }

        return true;
    }

    /**
     * Date de fin d'un contrat GLPI : début plus la durée en mois, ou date conventionnelle d'un
     * accord sans fin (B_02.02.0080) si aucune durée n'est fixée. Null sans date de début.
     */
    public static function endDate(?string $beginDate, int $durationMonths): ?string
    {
        if ($beginDate === null || self::date($beginDate) === null) {
            return null;
        }
        if ($durationMonths <= 0) {
            return RegisterTemplates::OPEN_DATE;
        }
        $end = (new \DateTimeImmutable($beginDate))->modify(sprintf('+%d months', $durationMonths));

        return $end->format('Y-m-d');
    }

    /**
     * Impact de l'arrêt d'une fonction (B_06.01.0110) déduit du pire impact de sa BIA (0 non évalué,
     * 1 négligeable, 2 limité, 3 grave, 4 critique).
     */
    public static function impactFromBia(int $worstLevel): int
    {
        return match (true) {
            $worstLevel <= 0 => RegisterTemplates::IMPACT_NOT_ASSESSED,
            $worstLevel === 1 => 1,
            $worstLevel === 2 => 2,
            default => 3,
        };
    }

    /**
     * Texte libre neutralisé contre l'injection de formules à l'ouverture dans un tableur.
     */
    public static function csvText(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }
}
