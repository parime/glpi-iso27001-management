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
 * Issue #704 : cycle (branche) d'une version installée et état de son support. PHP pur, sans GLPI.
 *
 * Versions essayées, de la plus précise à la moins précise :
 * 1. « numéro du nom » + « - » + version, quand le nom porte un numéro : « Microsoft Windows 11 »
 *    en version « 23H2 » donne « 11-23h2 », qui retrouve les cycles « 11-23h2-e » et « 11-23h2-w »
 *    (le plus prudent, celui dont la fin de support est la plus proche, est retenu) ;
 * 2. la version installée : nom de cycle identique (« 22.04 », « 2019 »), sinon le cycle le plus
 *    précis dont les nombres commencent la version (« 3.11 » pour 3.11.4, « 12 » pour « 12.5 » ou
 *    « 12 (bookworm) »), sinon un nom de code (« bookworm ») ;
 * 3. le numéro du nom seul (« Microsoft Office 2019 », version 16.0.x : cycle « 2019 »).
 *
 * Une version sans cycle correspondant reste « support inconnu », jamais « supportée ».
 * États : fin de support passée ou atteinte aujourd'hui : « plus supporté » ; dans N jours au plus
 * (réglage) : « fin proche » ; sinon « supporté » (date future, ou aucune date annoncée).
 *
 * Modèle repris du plugin Inventaire Docker (CycleMatcher), sans en dépendre.
 *
 * @phpstan-import-type Cycle from EndOfLifeClient
 * @phpstan-type Evaluation array{status: string, reason: string, product: string, cycle: string,
 *     eol: string, days: int|null, latest: string}
 */
final class CycleMatcher
{
    public const STATUS_EOL = 'eol';
    public const STATUS_SOON = 'soon';
    public const STATUS_SUPPORTED = 'supported';
    public const STATUS_UNKNOWN = 'unknown';

    /** Du plus grave au moins grave (ordre d'affichage). */
    public const STATUSES = [self::STATUS_EOL, self::STATUS_SOON, self::STATUS_SUPPORTED, self::STATUS_UNKNOWN];

    /** Raisons d'un état inconnu. */
    public const REASON_NO_PRODUCT = 'no_product';
    public const REASON_NOT_CHECKED = 'not_checked';
    public const REASON_PRODUCT_NOT_FOUND = 'product_not_found';
    public const REASON_NO_CYCLE = 'no_cycle';

    public const REASONS = [
        self::REASON_NO_PRODUCT, self::REASON_NOT_CHECKED, self::REASON_PRODUCT_NOT_FOUND, self::REASON_NO_CYCLE,
    ];

    /** Mots qui ne désignent pas une branche. */
    private const FLOATING = ['latest', 'stable', 'edge', 'mainline', 'lts', 'current'];

    /**
     * Cycle de la version installée, null si aucun ne correspond.
     *
     * @param list<Cycle> $cycles
     * @return Cycle|null
     */
    public static function match(string $name, string $version, array $cycles): ?array
    {
        $version = strtolower(trim($version));
        $nameNumber = self::nameNumber($name);
        if ($nameNumber !== '' && $version !== '') {
            $found = self::matchComposite($nameNumber . '-' . self::firstToken($version), $cycles);
            if ($found !== null) {
                return $found;
            }
        }
        if ($version !== '') {
            $found = self::matchVersion($version, $cycles);
            if ($found !== null) {
                return $found;
            }
        }

        return $nameNumber === '' ? null : self::matchExact($nameNumber, $cycles);
    }

    /**
     * Numéro autonome porté par le nom (« 11 », « 2019 »), pas celui d'une version pointée
     * (« Python 3.11.4 » n'en a pas), ni celui d'une précision entre parenthèses ou collé à un mot
     * (« (x64 fr) », « 64-bit »). Chaîne vide sinon.
     */
    public static function nameNumber(string $name): string
    {
        $name = (string) preg_replace('/\([^)]*\)/', ' ', $name);
        if (preg_match('/(?<![\w.-])(\d{2,4})(?![\w.-])/u', $name, $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    /**
     * @param list<Cycle> $cycles
     * @return Cycle|null
     */
    private static function matchComposite(string $candidate, array $cycles): ?array
    {
        $exact = self::matchExact($candidate, $cycles);
        if ($exact !== null) {
            return $exact;
        }
        // Variantes d'édition (« 11-23h2-e », « 11-23h2-w ») : la fin de support la plus proche.
        $best = null;
        foreach ($cycles as $cycle) {
            if (str_starts_with(strtolower($cycle['cycle']), $candidate . '-')) {
                if ($best === null || self::eolRank($cycle['eol']) < self::eolRank($best['eol'])) {
                    $best = $cycle;
                }
            }
        }

        return $best;
    }

    /**
     * Rang de prudence d'une fin de support : atteinte sans date, puis date la plus proche, puis
     * aucune date annoncée.
     */
    private static function eolRank(string $eol): string
    {
        return match (true) {
            $eol === EndOfLifeClient::EOL_WITHOUT_DATE => '0',
            $eol === ''                               => '9',
            default                                   => '1' . $eol,
        };
    }

    /**
     * @param list<Cycle> $cycles
     * @return Cycle|null
     */
    private static function matchExact(string $candidate, array $cycles): ?array
    {
        foreach ($cycles as $cycle) {
            if (strtolower($cycle['cycle']) === $candidate) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * @param list<Cycle> $cycles
     * @return Cycle|null
     */
    private static function matchVersion(string $version, array $cycles): ?array
    {
        $exact = self::matchExact($version, $cycles) ?? self::matchExact(self::firstToken($version), $cycles);
        if ($exact !== null) {
            return $exact;
        }
        $numbers = self::leadingNumbers($version);
        if ($numbers !== null) {
            $best = null;
            $bestLength = 0;
            foreach ($cycles as $cycle) {
                $cycleNumbers = self::numbers($cycle['cycle']);
                if ($cycleNumbers === null) {
                    continue;
                }
                $length = count($cycleNumbers);
                if (
                    $length <= count($numbers)
                    && array_slice($numbers, 0, $length) === $cycleNumbers
                    && $length > $bestLength
                ) {
                    $best = $cycle;
                    $bestLength = $length;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return self::matchCodename($version, $cycles);
    }

    /**
     * @param list<Cycle> $cycles
     * @return Cycle|null
     */
    private static function matchCodename(string $version, array $cycles): ?array
    {
        $words = preg_split('/[^a-z]+/', $version, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_diff($words, self::FLOATING));
        if ($words === []) {
            return null;
        }
        foreach ($cycles as $cycle) {
            $codename = strtolower($cycle['codename']);
            if ($codename === '') {
                continue;
            }
            // « Bookworm », « Jammy Jellyfish » : le premier mot du nom de code suffit.
            $first = (string) (preg_split('/[^a-z]+/', $codename, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '');
            if ($first !== '' && in_array($first, $words, true)) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * Premier mot d'une version (« 22h2 » pour « 22H2 (build 19045) »).
     */
    private static function firstToken(string $version): string
    {
        return (string) (preg_split('/[\s(]+/', trim($version), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '');
    }

    /**
     * Nombres en tête d'une version (« v12.5-r1 » : [12, 5]), null sans nombre en tête.
     *
     * @return list<int>|null
     */
    private static function leadingNumbers(string $version): ?array
    {
        if (preg_match('/^v?(\d+(?:\.\d+)*)/', $version, $m) !== 1) {
            return null;
        }

        return array_map('intval', explode('.', $m[1]));
    }

    /**
     * @return list<int>|null
     */
    private static function numbers(string $cycle): ?array
    {
        if (preg_match('/^\d+(?:\.\d+)*$/', trim($cycle)) !== 1) {
            return null;
        }

        return array_map('intval', explode('.', trim($cycle)));
    }

    /**
     * État du support d'une version installée.
     *
     * @param list<Cycle>|null $cycles Cycles du produit ; null s'ils n'ont jamais été lus.
     * @param string $today « 2026-11-18 ».
     * @param int $warningDays Seuil de « fin proche ».
     * @param bool $productNotFound La source ne connaît pas ce produit.
     * @return Evaluation
     */
    public static function evaluate(
        string $product,
        string $name,
        string $version,
        ?array $cycles,
        string $today,
        int $warningDays,
        bool $productNotFound = false
    ): array {
        $result = ['status' => self::STATUS_UNKNOWN, 'reason' => '', 'product' => $product, 'cycle' => '',
            'eol' => '', 'days' => null, 'latest' => ''];
        if ($product === '') {
            return ['reason' => self::REASON_NO_PRODUCT] + $result;
        }
        if ($productNotFound) {
            return ['reason' => self::REASON_PRODUCT_NOT_FOUND] + $result;
        }
        if ($cycles === null) {
            return ['reason' => self::REASON_NOT_CHECKED] + $result;
        }
        $cycle = self::match($name, $version, $cycles);
        if ($cycle === null) {
            return ['reason' => self::REASON_NO_CYCLE] + $result;
        }
        $result['cycle'] = $cycle['cycle'];
        $result['eol'] = $cycle['eol'];
        $result['latest'] = $cycle['latest'];
        if ($cycle['eol'] === EndOfLifeClient::EOL_WITHOUT_DATE) {
            return ['status' => self::STATUS_EOL] + $result;
        }
        if ($cycle['eol'] === '') {
            return ['status' => self::STATUS_SUPPORTED] + $result;
        }
        $days = self::daysBetween($today, $cycle['eol']);
        $result['days'] = $days;
        $status = match (true) {
            $days <= 0            => self::STATUS_EOL,
            $days <= $warningDays => self::STATUS_SOON,
            default               => self::STATUS_SUPPORTED,
        };

        return ['status' => $status] + $result;
    }

    /**
     * Jours de $from à $to (négatif si $to est passé), en dates calendaires.
     */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable(substr($from, 0, 10), new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable(substr($to, 0, 10), new \DateTimeZone('UTC'));

        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * Rang de gravité (0 = le plus grave).
     */
    public static function severity(string $status): int
    {
        $rank = array_search($status, self::STATUSES, true);

        return $rank === false ? count(self::STATUSES) : (int) $rank;
    }
}
