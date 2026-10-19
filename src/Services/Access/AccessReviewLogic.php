<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Access;

/**
 * Issue #126 : règles pures de la campagne de revue des accès (PluginGrcmanagerAccessReview),
 * sans dépendance à GLPI pour être testées unitairement.
 *
 * Le plugin ne modifie jamais un droit, un compte ou un statut : une décision « modifier » ou
 * « retirer » ouvre un ticket que l'équipe support traite (needsTicket()).
 */
final class AccessReviewLogic
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const DECISION_PENDING = 'pending';
    public const DECISION_KEEP = 'keep';
    public const DECISION_MODIFY = 'modify';
    public const DECISION_REMOVE = 'remove';

    public const SOURCE_SUPERVISOR = 'supervisor';
    public const SOURCE_GROUP = 'group';
    public const SOURCE_OWNER = 'owner';

    /** Fréquence => nombre de mois entre deux campagnes. */
    public const FREQUENCY_MONTHS = [
        'quarterly'  => 3,
        'semiannual' => 6,
        'annual'     => 12,
    ];

    /** Délai minimal entre deux relances d'un même responsable en retard. */
    public const REMINDER_INTERVAL_DAYS = 7;

    /** Mesure de l'Annexe A proposée par défaut (droits d'accès). */
    public const DEFAULT_CONTROL_CODE = 'A.5.18';

    /**
     * @return list<string>
     */
    public static function decisions(): array
    {
        return [self::DECISION_PENDING, self::DECISION_KEEP, self::DECISION_MODIFY, self::DECISION_REMOVE];
    }

    public static function isDecision(string $decision): bool
    {
        return in_array($decision, self::decisions(), true) && $decision !== self::DECISION_PENDING;
    }

    public static function needsTicket(string $decision): bool
    {
        return $decision === self::DECISION_MODIFY || $decision === self::DECISION_REMOVE;
    }

    /**
     * Date de début de la campagne suivante (fréquence inconnue : annuelle).
     */
    public static function nextStartDate(string $start, string $frequency): string
    {
        $months = self::FREQUENCY_MONTHS[$frequency] ?? 12;
        $date = new \DateTimeImmutable($start);

        return $date->modify(sprintf('+%d months', $months))->format('Y-m-d');
    }

    /**
     * Responsable chargé de revoir les accès d'un compte : son responsable hiérarchique, sinon le
     * premier responsable d'un de ses groupes GLPI (jamais le compte lui-même), sinon le pilote
     * de la campagne.
     *
     * @param list<array{groups_id: int, users_id: int}> $groupManagers responsables des groupes du compte
     * @return array{users_id: int, groups_id: int, source: string}
     */
    public static function reviewerFor(int $usersId, int $supervisorId, array $groupManagers, int $ownerId): array
    {
        if ($supervisorId > 0 && $supervisorId !== $usersId) {
            return ['users_id' => $supervisorId, 'groups_id' => 0, 'source' => self::SOURCE_SUPERVISOR];
        }
        foreach ($groupManagers as $manager) {
            if ($manager['users_id'] > 0 && $manager['users_id'] !== $usersId) {
                return [
                    'users_id'  => $manager['users_id'],
                    'groups_id' => $manager['groups_id'],
                    'source'    => self::SOURCE_GROUP,
                ];
            }
        }

        return ['users_id' => $ownerId, 'groups_id' => 0, 'source' => self::SOURCE_OWNER];
    }

    /**
     * @param list<string> $decisions
     * @return array{total: int, done: int, percent: int}
     */
    public static function progress(array $decisions): array
    {
        $total = count($decisions);
        $done = count(array_filter($decisions, static fn (string $d): bool => $d !== self::DECISION_PENDING));

        return [
            'total'   => $total,
            'done'    => $done,
            'percent' => $total > 0 ? (int) floor($done * 100 / $total) : 100,
        ];
    }

    /**
     * Une relance est due quand l'échéance est dépassée et que la précédente relance (s'il y en a
     * eu une) date d'au moins REMINDER_INTERVAL_DAYS jours.
     */
    public static function isReminderDue(?string $dueDate, ?string $lastReminder, string $today): bool
    {
        if ($dueDate === null || $dueDate === '' || $today <= substr($dueDate, 0, 10)) {
            return false;
        }
        if ($lastReminder === null || $lastReminder === '') {
            return true;
        }
        $next = (new \DateTimeImmutable(substr($lastReminder, 0, 10)))
            ->modify(sprintf('+%d days', self::REMINDER_INTERVAL_DAYS))
            ->format('Y-m-d');

        return $today >= $next;
    }
}
