<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #378 : règles des indicateurs de risque (KRI), sans dépendance à GLPI (testé unitairement).
 *
 * Un KRI a deux seuils : orange (vigilance) et rouge (alerte). Selon son sens, une valeur haute
 * (« plus c'est haut, pire c'est » : vulnérabilités critiques ouvertes) ou basse (« plus c'est bas,
 * pire c'est » : taux de postes chiffrés) est mauvaise. Le seuil est atteint dès l'égalité.
 */
final class KriRules
{
    public const HIGHER_IS_WORSE = 'higher_is_worse';
    public const LOWER_IS_WORSE = 'lower_is_worse';
    public const DIRECTIONS = [self::HIGHER_IS_WORSE, self::LOWER_IS_WORSE];

    public const GREEN = 'green';
    public const ORANGE = 'orange';
    public const RED = 'red';
    /** Aucune valeur relevée, ou aucun seuil défini. */
    public const NONE = 'none';
    public const STATUSES = [self::GREEN, self::ORANGE, self::RED, self::NONE];

    public const TREND_UP = 'up';
    public const TREND_DOWN = 'down';
    public const TREND_STABLE = 'stable';

    public static function isDirection(?string $direction): bool
    {
        return $direction !== null && in_array($direction, self::DIRECTIONS, true);
    }

    /**
     * Seuils cohérents avec le sens : le seuil rouge est au-delà du seuil orange (ou égal). Un seuil
     * absent est toujours accepté.
     */
    public static function areThresholdsValid(string $direction, ?float $warning, ?float $critical): bool
    {
        if ($warning === null || $critical === null) {
            return true;
        }

        return $direction === self::LOWER_IS_WORSE ? $critical <= $warning : $critical >= $warning;
    }

    public static function status(?float $value, string $direction, ?float $warning, ?float $critical): string
    {
        if ($value === null || ($warning === null && $critical === null)) {
            return self::NONE;
        }
        $reached = static fn (?float $threshold): bool => $threshold !== null
            && ($direction === self::LOWER_IS_WORSE ? $value <= $threshold : $value >= $threshold);
        if ($reached($critical)) {
            return self::RED;
        }
        if ($reached($warning)) {
            return self::ORANGE;
        }

        return self::GREEN;
    }

    /**
     * Tendance entre les deux dernières valeurs (ordre chronologique), null sans deux valeurs.
     *
     * @param list<float> $values
     */
    public static function trend(array $values): ?string
    {
        if (count($values) < 2) {
            return null;
        }
        [$previous, $last] = array_slice($values, -2);
        if (abs($last - $previous) < 0.00001) {
            return self::TREND_STABLE;
        }

        return $last > $previous ? self::TREND_UP : self::TREND_DOWN;
    }

    /**
     * La tendance va-t-elle dans le mauvais sens pour ce KRI ?
     */
    public static function isWorsening(?string $trend, string $direction): bool
    {
        return ($trend === self::TREND_UP && $direction !== self::LOWER_IS_WORSE)
            || ($trend === self::TREND_DOWN && $direction === self::LOWER_IS_WORSE);
    }

    /**
     * Taux en pourcentage (une décimale), null quand rien n'est mesurable.
     */
    public static function rate(int $count, int $total): ?float
    {
        return $total > 0 ? round($count * 100 / $total, 1) : null;
    }
}
