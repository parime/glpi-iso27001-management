<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #380 : règles des tests d'efficacité des mesures de l'Annexe A, sans dépendance à GLPI
 * (testées unitairement). Méthodes et résultats admis, échéance du prochain test, état de
 * l'échéance, et effet d'un test sur l'état « vérifié » de la SoA.
 */
final class ControlTestRules
{
    /** Méthodes de test (ISO 19011 : examen documentaire, échantillonnage, test technique, entretien). */
    public const METHODS = ['inspection', 'sampling', 'technical', 'interview'];
    public const DEFAULT_METHOD = 'inspection';

    public const RESULT_EFFECTIVE = 'effective';
    public const RESULT_PARTIAL = 'partial';
    public const RESULT_INEFFECTIVE = 'ineffective';
    public const RESULTS = [self::RESULT_EFFECTIVE, self::RESULT_PARTIAL, self::RESULT_INEFFECTIVE];

    /** Fréquences proposées, en mois (0 : aucun plan de test). */
    public const FREQUENCIES = [0, 1, 3, 6, 12, 24];

    /** Une échéance à moins de ce nombre de jours est signalée « à venir ». */
    public const DUE_SOON_DAYS = 30;

    public const DUE_NONE = 'none';
    public const DUE_PLANNED = 'planned';
    public const DUE_SOON = 'due_soon';
    public const DUE_OVERDUE = 'overdue';

    public static function normalizeMethod(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, self::METHODS, true) ? $value : self::DEFAULT_METHOD;
    }

    /**
     * Méthode du plan de test : vide admis (pas encore de plan).
     */
    public static function normalizePlanMethod(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, self::METHODS, true) ? $value : '';
    }

    public static function isResult(mixed $value): bool
    {
        return in_array((string) $value, self::RESULTS, true);
    }

    public static function normalizeFrequency(mixed $value): int
    {
        $months = (int) $value;

        return in_array($months, self::FREQUENCIES, true) ? $months : 0;
    }

    /**
     * Date au format Y-m-d réellement valide, sinon null.
     */
    public static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }

    /**
     * Échéance du test suivant : celle saisie, sinon la date du test plus la fréquence du plan,
     * sinon aucune.
     */
    public static function nextTestDate(string $testDate, ?string $explicitNext, int $frequencyMonths): ?string
    {
        $explicitNext = self::normalizeDate($explicitNext);
        if ($explicitNext !== null) {
            return $explicitNext;
        }
        $testDate = self::normalizeDate($testDate);
        if ($testDate === null || $frequencyMonths <= 0) {
            return null;
        }
        $date = new \DateTimeImmutable($testDate);
        $next = $date->modify(sprintf('+%d months', $frequencyMonths));
        // Fin de mois : le 31 janvier plus un mois donne le 28 (ou 29) février, pas le 3 mars.
        if ((int) $next->format('d') !== (int) $date->format('d')) {
            $next = $next->modify('last day of previous month');
        }

        return $next->format('Y-m-d');
    }

    /**
     * État de l'échéance du prochain test à la date $today.
     */
    public static function dueStatus(?string $nextDate, string $today): string
    {
        $nextDate = self::normalizeDate($nextDate);
        if ($nextDate === null) {
            return self::DUE_NONE;
        }
        if ($nextDate < $today) {
            return self::DUE_OVERDUE;
        }
        $soon = (new \DateTimeImmutable($today))->modify('+' . self::DUE_SOON_DAYS . ' days')->format('Y-m-d');

        return $nextDate <= $soon ? self::DUE_SOON : self::DUE_PLANNED;
    }

    /**
     * Nouvel état de mise en œuvre de la mesure après un test, ou null s'il ne change pas.
     * L'état « vérifié » s'appuie sur le dernier test : un test efficace fait passer une mesure
     * mise en œuvre à « vérifiée », un test partiel ou inefficace retire l'état « vérifié ».
     * Une mesure pas encore mise en œuvre ne change jamais d'état.
     */
    public static function statusAfterTest(string $currentStatus, string $result): ?string
    {
        if ($result === self::RESULT_EFFECTIVE && $currentStatus === 'implemented') {
            return 'verified';
        }
        if ($result !== self::RESULT_EFFECTIVE && $currentStatus === 'verified') {
            return 'implemented';
        }

        return null;
    }

    /**
     * Retrait automatique de l'état « vérifié » : une mesure vérifiée dont le test suivant est en
     * retard redevient « mise en œuvre » (la preuve d'efficacité est échue). Null sinon.
     */
    public static function statusWhenDue(string $currentStatus, ?string $nextDate, string $today): ?string
    {
        return $currentStatus === 'verified' && self::dueStatus($nextDate, $today) === self::DUE_OVERDUE
            ? 'implemented'
            : null;
    }

    /**
     * Clé du rappel à envoyer pour cette échéance (échéance et état), null si aucun rappel n'est dû.
     * Un rappel n'est envoyé qu'une fois par clé : une fois quand l'échéance approche, une fois
     * quand elle est dépassée, et de nouveau quand un nouveau test déplace l'échéance.
     */
    public static function reminderKey(?string $nextDate, string $today): ?string
    {
        $status = self::dueStatus($nextDate, $today);
        if ($status !== self::DUE_OVERDUE && $status !== self::DUE_SOON) {
            return null;
        }

        return $nextDate . '|' . $status;
    }

    /** Groupe RSSI prévenu : jamais, pour les tests en retard seulement, ou pour tous les rappels. */
    public const CISO_NEVER = 'never';
    public const CISO_OVERDUE = 'overdue';
    public const CISO_ALWAYS = 'always';
    public const CISO_MODES = [self::CISO_NEVER, self::CISO_OVERDUE, self::CISO_ALWAYS];
    public const DEFAULT_CISO_MODE = self::CISO_OVERDUE;

    public static function normalizeCisoMode(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, self::CISO_MODES, true) ? $value : self::DEFAULT_CISO_MODE;
    }

    /**
     * Le groupe RSSI reçoit-il le rappel d'une échéance dans cet état ?
     */
    public static function notifiesCiso(string $mode, string $dueStatus): bool
    {
        return match (self::normalizeCisoMode($mode)) {
            self::CISO_ALWAYS  => in_array($dueStatus, [self::DUE_OVERDUE, self::DUE_SOON], true),
            self::CISO_OVERDUE => $dueStatus === self::DUE_OVERDUE,
            default            => false,
        };
    }

    /**
     * Couleur de la charte des notifications publiée par Configuration GLPI Auto : une couleur
     * hexadécimale #rrggbb, sinon la couleur par défaut (la même que celle de ce plugin).
     */
    public const DEFAULT_BRAND_COLOR = '#206bc4';

    public static function brandColor(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : self::DEFAULT_BRAND_COLOR;
    }

    /**
     * Un test inefficace ouvre une non-conformité ; un test partiel reste une observation à suivre.
     */
    public static function opensNonconformity(string $result): bool
    {
        return $result === self::RESULT_INEFFECTIVE;
    }

    /**
     * Un contrôle technique automatique dont le taux de conformité (en %) atteint ce seuil, sans
     * être parfait, donne un test « partiellement efficace » ; en dessous, « inefficace ».
     */
    public const TECHNICAL_PARTIAL_THRESHOLD = 80;

    /**
     * Issue #380 (#162) : résultat d'un test alimenté par un contrôle technique automatique, à
     * partir de ses écarts. Aucun écart : efficace ; peu d'écarts : partiellement efficace ;
     * sinon inefficace. Null si la population est vide (rien à prouver).
     */
    public static function resultFromTechnicalCheck(int $gaps, int $total): ?string
    {
        if ($total <= 0) {
            return null;
        }
        $gaps = max(0, min($gaps, $total));
        if ($gaps === 0) {
            return self::RESULT_EFFECTIVE;
        }

        return 100 * ($total - $gaps) / $total >= self::TECHNICAL_PARTIAL_THRESHOLD
            ? self::RESULT_PARTIAL
            : self::RESULT_INEFFECTIVE;
    }

    /**
     * Contrôles techniques automatiques (ComplianceAuditScore::CHECKS) rattachés à une mesure de
     * l'Annexe A par leur référence ISO 27001.
     *
     * @param array<string, array<string, string>> $checks
     * @return list<string>
     */
    public static function technicalChecksFor(string $controlCode, array $checks): array
    {
        $keys = [];
        foreach ($checks as $check => $references) {
            if (($references['iso27001'] ?? null) === $controlCode) {
                $keys[] = (string) $check;
            }
        }

        return $keys;
    }
}
