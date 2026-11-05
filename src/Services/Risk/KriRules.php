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
     * Niveau à partir duquel un KRI déclenche une alerte : au rouge seulement (défaut), ou dès
     * l'orange. Une valeur inconnue revient au défaut.
     */
    public static function normalizeAlertLevel(mixed $level): string
    {
        return $level === self::ORANGE ? self::ORANGE : self::RED;
    }

    /**
     * Le statut est-il une alerte pour ce niveau ?
     */
    public static function isAlert(string $status, string $level): bool
    {
        return $status === self::RED
            || ($status === self::ORANGE && self::normalizeAlertLevel($level) === self::ORANGE);
    }

    /**
     * Notification à envoyer quand le statut d'un KRI change, une seule fois par changement d'état.
     *
     * - $notified : statut de la dernière alerte envoyée (orange ou rouge), vide si aucune n'est en cours.
     * - Une alerte part quand le statut atteint un niveau d'alerte plus grave que la dernière envoyée
     *   (vert vers rouge, orange vers rouge...). Une amélioration qui reste en alerte (rouge vers
     *   orange, niveau orange) ne prévient pas mais permet une nouvelle alerte au retour au rouge.
     * - Le retour au vert après une alerte prévient une fois. Sortir de l'alerte sans revenir au vert
     *   (rouge vers orange au niveau rouge, seuils retirés) réarme l'alerte sans rien envoyer.
     *
     * @return array{event: ?string, notified: string} event : 'alert', 'cleared' ou null
     */
    public static function alertTransition(string $notified, string $status, string $level): array
    {
        $rank = static fn (string $value): int => match ($value) {
            self::RED    => 2,
            self::ORANGE => 1,
            default      => 0,
        };
        if (self::isAlert($status, $level)) {
            return [
                'event'    => $rank($status) > $rank($notified) ? 'alert' : null,
                'notified' => $status,
            ];
        }
        if ($notified !== '' && $status === self::GREEN) {
            return ['event' => 'cleared', 'notified' => ''];
        }

        return ['event' => null, 'notified' => ''];
    }

    /**
     * Nombre de KRI au rouge et à l'orange à la fin de chaque mois : le statut retenu est celui du
     * dernier relevé du KRI à cette date (statut enregistré au moment du relevé).
     *
     * @param array<int, list<array{date: string, status: string}>> $valuesByKri relevés chronologiques
     * @param list<string>                                           $months      mois « AAAA-MM »
     * @return array<string, array{red: int, orange: int}>
     */
    public static function monthlyStatusCounts(array $valuesByKri, array $months): array
    {
        $result = [];
        foreach ($months as $month) {
            $end = $month . '-31';
            $counts = [self::RED => 0, self::ORANGE => 0];
            foreach ($valuesByKri as $values) {
                $status = null;
                foreach ($values as $value) {
                    if ($value['date'] > $end) {
                        break;
                    }
                    $status = $value['status'];
                }
                if ($status !== null && isset($counts[$status])) {
                    $counts[$status]++;
                }
            }
            $result[$month] = ['red' => $counts[self::RED], 'orange' => $counts[self::ORANGE]];
        }

        return $result;
    }

    /**
     * Les douze derniers mois « AAAA-MM », du plus ancien au mois de la date donnée.
     *
     * @return list<string>
     */
    public static function lastMonths(string $today, int $count = 12): array
    {
        $first = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $months[] = $first->modify(sprintf('-%d months', $i))->format('Y-m');
        }

        return $months;
    }

    /**
     * Taux en pourcentage (une décimale), null quand rien n'est mesurable.
     */
    public static function rate(int $count, int $total): ?float
    {
        return $total > 0 ? round($count * 100 / $total, 1) : null;
    }
}
