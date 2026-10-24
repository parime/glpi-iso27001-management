<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Review;

/**
 * Issue #141 : catalogue des revues périodiques d'un SMSI, sans dépendance à GLPI (testé
 * unitairement). Chaque revue activée devient un ticket récurrent natif de GLPI
 * (PluginGrcmanagerPeriodicReview) : rien n'est oublié, et le ticket résolu sert de preuve.
 *
 * Les libellés et listes de contrôle sont traduits par l'appelant (PluginGrcmanagerPeriodicReview::
 * getLabel()/getChecklist()) : ce catalogue ne porte que les clés, les fréquences par défaut et les
 * mesures de l'Annexe A concernées (références seulement, aucun texte de la norme).
 */
final class PeriodicReviewCatalog
{
    /** Fréquences proposées, en mois. */
    public const FREQUENCIES = [1, 3, 6, 12, 24];

    /**
     * Clé => [fréquence par défaut en mois, mesures de l'Annexe A ou articles concernés].
     *
     * @var array<string, array{months: int, controls: list<string>}>
     */
    public const REVIEWS = [
        'access_rights'       => ['months' => 6, 'controls' => ['A.5.15', 'A.5.18']],
        'privileged_accounts' => ['months' => 3, 'controls' => ['A.8.2', 'A.5.18']],
        'network_flows'       => ['months' => 6, 'controls' => ['A.8.20', 'A.8.21', 'A.8.22']],
        'risk_register'       => ['months' => 6, 'controls' => ['6.1.2', '8.2']],
        'supplier_risks'      => ['months' => 12, 'controls' => ['A.5.19', 'A.5.20', 'A.5.22']],
        'policies'            => ['months' => 12, 'controls' => ['A.5.1']],
        'soa'                 => ['months' => 12, 'controls' => ['6.1.3']],
        'legal_obligations'   => ['months' => 12, 'controls' => ['A.5.31']],
        'backup_restore_test' => ['months' => 6, 'controls' => ['A.8.13']],
        'continuity_test'     => ['months' => 12, 'controls' => ['A.5.29', 'A.5.30']],
        'security_logs'       => ['months' => 1, 'controls' => ['A.8.15', 'A.8.16']],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::REVIEWS);
    }

    public static function isKey(string $key): bool
    {
        return isset(self::REVIEWS[$key]);
    }

    public static function defaultMonths(string $key): int
    {
        return self::REVIEWS[$key]['months'] ?? 12;
    }

    /**
     * @return list<string>
     */
    public static function controls(string $key): array
    {
        return self::REVIEWS[$key]['controls'] ?? [];
    }

    /**
     * Fréquence ramenée à une valeur proposée (la plus proche ; 12 mois si absente ou négative).
     */
    public static function normalizeMonths(int $months): int
    {
        if (in_array($months, self::FREQUENCIES, true)) {
            return $months;
        }
        if ($months <= 0) {
            return 12;
        }
        $best = 12;
        foreach (self::FREQUENCIES as $candidate) {
            if (abs($candidate - $months) < abs($best - $months)) {
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Périodicité au format de CommonITILRecurrent : « NMONTH » sous un an, « NYEAR » au-delà.
     */
    public static function periodicity(int $months): string
    {
        $months = self::normalizeMonths($months);
        return $months % 12 === 0 ? ($months / 12) . 'YEAR' : $months . 'MONTH';
    }

    /**
     * Prochaine échéance d'une revue : dernière revue + fréquence, ou null si jamais faite.
     */
    public static function nextDueDate(?string $lastReviewDate, int $months): ?string
    {
        if ($lastReviewDate === null || $lastReviewDate === '') {
            return null;
        }
        $time = strtotime($lastReviewDate);
        if ($time === false) {
            return null;
        }
        return date('Y-m-d', strtotime('+' . self::normalizeMonths($months) . ' months', $time));
    }

    /**
     * En retard : jamais faite alors qu'active depuis plus d'une période, ou échéance dépassée.
     */
    public static function isOverdue(?string $lastReviewDate, int $months, string $activatedOn, string $today): bool
    {
        $due = self::nextDueDate($lastReviewDate, $months)
            ?? self::nextDueDate($activatedOn, $months);
        return $due !== null && $due < $today;
    }
}
