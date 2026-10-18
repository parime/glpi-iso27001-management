<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #128 : délais de notification réglementaires d'un incident de sécurité, calculés depuis sa
 * date de détection (date d'ouverture de l'incident).
 *
 * - NIS2 (directive (UE) 2022/2555, article 23) : alerte précoce sous 24 heures, notification
 *   d'incident sous 72 heures, rapport final sous un mois ;
 * - RGPD (article 33) : notification à l'autorité de contrôle sous 72 heures en cas de violation
 *   de données personnelles.
 *
 * Seules les échéances applicables (cases « Soumis à NIS2 » / « Violation de données
 * personnelles » de l'incident) sont retenues. Le plugin n'envoie jamais rien à une autorité :
 * l'équipe saisit la date à laquelle elle a effectivement notifié. Logique pure (ni $DB ni __()),
 * testée directement, comme les autres services du plugin.
 */
final class RegulatoryDeadlines
{
    public const STATUS_DONE = 'done';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_DUE_SOON = 'due_soon';
    public const STATUS_PENDING = 'pending';

    /**
     * Échéances : clé => [condition d'application, délai, champ « notifié le »].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const DEADLINES = [
        'nis2_early_warning' => ['nis2_applicable', '+24 hours', 'nis2_early_warning_at'],
        'nis2_notification'  => ['nis2_applicable', '+72 hours', 'nis2_notification_at'],
        'nis2_final_report'  => ['nis2_applicable', '+1 month', 'nis2_final_report_at'],
        'gdpr_notification'  => ['personal_data_breach', '+72 hours', 'gdpr_notification_at'],
    ];

    /** Part du délai restante sous laquelle l'échéance est « proche » (alerte). */
    private const DUE_SOON_RATIO = 0.25;

    /**
     * @param array<string, mixed> $fields champs de l'incident (date, cases, dates « notifié le »)
     * @return list<array{key: string, due: string, done_at: ?string, status: string}>
     */
    public static function compute(array $fields, ?int $now = null): array
    {
        $now ??= time();
        $start = strtotime((string) ($fields['date'] ?? ''));
        if ($start === false) {
            return [];
        }

        $rows = [];
        foreach (self::DEADLINES as $key => [$flag, $delay, $doneField]) {
            if (empty($fields[$flag])) {
                continue;
            }
            $due = (int) strtotime($delay, $start);
            $doneAt = !empty($fields[$doneField]) ? (string) $fields[$doneField] : null;
            $rows[] = [
                'key'     => $key,
                'due'     => date('Y-m-d H:i:s', $due),
                'done_at' => $doneAt,
                'status'  => self::status($start, $due, $doneAt, $now),
            ];
        }

        return $rows;
    }

    private static function status(int $start, int $due, ?string $doneAt, int $now): string
    {
        if ($doneAt !== null) {
            return self::STATUS_DONE;
        }
        if ($now > $due) {
            return self::STATUS_OVERDUE;
        }
        $window = max(1, $due - $start);

        return ($due - $now) / $window <= self::DUE_SOON_RATIO ? self::STATUS_DUE_SOON : self::STATUS_PENDING;
    }

    /**
     * Échéances qui appellent une alerte (proche ou dépassée) et qui n'en ont pas encore reçu pour
     * ce niveau : une alerte « proche », puis une « dépassée », jamais deux fois la même.
     *
     * @param array<string, mixed> $fields champs de l'incident
     * @param list<string> $alreadyAlerted clés « échéance:statut » déjà signalées
     * @return list<array{key: string, due: string, done_at: ?string, status: string}>
     */
    public static function toAlert(array $fields, array $alreadyAlerted, ?int $now = null): array
    {
        return array_values(array_filter(
            self::compute($fields, $now),
            static fn (array $row): bool => in_array($row['status'], [self::STATUS_DUE_SOON, self::STATUS_OVERDUE], true)
                && !in_array($row['key'] . ':' . $row['status'], $alreadyAlerted, true)
        ));
    }
}
