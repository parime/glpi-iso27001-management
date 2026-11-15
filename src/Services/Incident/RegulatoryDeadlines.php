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
 * Issue #600 : deux régimes de plus, chacun avec son propre point de départ.
 *
 * - DORA (règlement (UE) 2022/2554, article 19, paragraphe 4, délais fixés par l'article 5 du
 *   règlement délégué (UE) 2025/301) pour un incident TIC majeur : notification initiale dans les
 *   4 heures suivant la classification comme majeur et au plus tard 24 heures après la prise de
 *   connaissance (article 5.1 a) ; classifié plus de 24 heures après, 4 heures depuis la
 *   classification (article 5.2) ; rapport intermédiaire sous 72 heures après la notification
 *   initiale (5.1 b) ; rapport final un mois au plus tard après le rapport intermédiaire (5.1 c).
 *   Le report au jour ouvré suivant (5.4) n'est pas appliqué : l'échéance affichée reste la plus
 *   prudente.
 * - CRA (règlement (UE) 2024/2847, article 14, applicable depuis le 11 septembre 2026 selon son
 *   article 71.2) pour un fabricant de produit comportant des éléments numériques : vulnérabilité
 *   activement exploitée (14.2) ou incident grave (14.4), alerte précoce sous 24 heures et
 *   notification sous 72 heures depuis la prise de connaissance ; rapport final 14 jours au plus tard
 *   après la mise à disposition d'une mesure corrective ou d'atténuation (vulnérabilité, 14.2 c) ou
 *   un mois après la notification de l'incident (incident, 14.4 c).
 *
 * La prise de connaissance est la date de détection de l'incident, comme pour NIS2. Une étape
 * enchaînée (DORA intermédiaire et final, CRA final d'un incident) part de la date à laquelle
 * l'étape précédente a été faite, ou, tant qu'elle ne l'est pas, de son échéance.
 *
 * Seules les échéances applicables (cases de l'incident) sont retenues. Le plugin n'envoie jamais
 * rien à une autorité : l'équipe saisit la date à laquelle elle a effectivement notifié. Logique pure
 * (ni $DB ni __()), testée directement, comme les autres services du plugin.
 */
final class RegulatoryDeadlines
{
    public const STATUS_DONE = 'done';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_DUE_SOON = 'due_soon';
    public const STATUS_PENDING = 'pending';
    /** Issue #620 : violation inscrite au registre avec la décision motivée de ne pas notifier. */
    public const STATUS_NOT_REQUIRED = 'not_required';
    /** Issue #600 : le point de départ n'est pas encore connu (mesure corrective du CRA). */
    public const STATUS_WAITING = 'waiting';
    /** Issue #600 : régime pas encore applicable à la date de détection de l'incident. */
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /** Motifs d'une échéance sans date (champ « reason »). */
    public const REASON_AWAITING_FIX = 'awaiting_fix';
    public const REASON_BEFORE_APPLICATION = 'before_application';

    /** Issue #600 : types de signalement CRA (article 14, paragraphes 1 et 3). */
    public const CRA_VULNERABILITY = 'vulnerability';
    public const CRA_INCIDENT = 'incident';
    public const CRA_TYPES = [self::CRA_VULNERABILITY, self::CRA_INCIDENT];

    /**
     * Issue #620 : champ ajouté en mémoire (jamais en base) par DataBreach::withAuthorityDecisions()
     * quand le registre des violations porte la décision motivée de ne pas notifier l'autorité.
     */
    public const GDPR_NOT_NOTIFIED_FLAG = '_gdpr_not_notified';

    /**
     * Échéances : clé => [condition d'application, délai, champ « notifié le »]. Le délai est compté
     * depuis le point de départ propre à l'échéance (voir start()).
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const DEADLINES = [
        'nis2_early_warning' => ['nis2_applicable', '+24 hours', 'nis2_early_warning_at'],
        'nis2_notification'  => ['nis2_applicable', '+72 hours', 'nis2_notification_at'],
        'nis2_final_report'  => ['nis2_applicable', '+1 month', 'nis2_final_report_at'],
        'gdpr_notification'  => ['personal_data_breach', '+72 hours', 'gdpr_notification_at'],
        // Issue #600 : DORA, règlement délégué (UE) 2025/301, article 5.
        'dora_initial'       => ['dora_major', '+4 hours', 'dora_initial_at'],
        'dora_intermediate'  => ['dora_major', '+72 hours', 'dora_intermediate_at'],
        'dora_final'         => ['dora_major', '+1 month', 'dora_final_at'],
        // Issue #600 : CRA, règlement (UE) 2024/2847, article 14.
        'cra_early_warning'  => ['cra_report_type', '+24 hours', 'cra_early_warning_at'],
        'cra_notification'   => ['cra_report_type', '+72 hours', 'cra_notification_at'],
        'cra_final_report'   => ['cra_report_type', '+1 month', 'cra_final_report_at'],
    ];

    /**
     * Issue #600 : points de départ saisis par l'équipe (en plus de la date de détection).
     *
     * @var list<string>
     */
    public const START_FIELDS = ['dora_classified_at', 'cra_fix_available_at'];

    /** DORA, article 5.1 a : plafond de la notification initiale depuis la prise de connaissance. */
    private const DORA_AWARENESS_LIMIT = '+24 hours';
    /** CRA, article 14.2 c : rapport final d'une vulnérabilité après la mesure corrective. */
    private const CRA_FIX_DELAY = '+14 days';

    /**
     * Issue #600 : date d'application de chaque régime. DORA s'applique depuis le 17 janvier 2025
     * (article 64 du règlement (UE) 2022/2554), l'article 14 du CRA depuis le 11 septembre 2026.
     *
     * @var array<string, string>
     */
    public const APPLICABLE_FROM = [
        'dora_major'      => '2025-01-17 00:00:00',
        'cra_report_type' => '2026-09-11 00:00:00',
    ];

    /** Part du délai restante sous laquelle l'échéance est « proche » (alerte). */
    private const DUE_SOON_RATIO = 0.25;

    /**
     * @param array<string, mixed> $fields champs de l'incident (date, cases, dates « notifié le »)
     * @return list<array{key: string, due: ?string, done_at: ?string, status: string, reason: ?string}>
     */
    public static function compute(array $fields, ?int $now = null): array
    {
        $now ??= time();
        $detected = self::time($fields['date'] ?? null);
        if ($detected === null) {
            return [];
        }

        $rows = [];
        /** @var array<string, array{due: ?int, done: ?int}> $steps échéances déjà calculées */
        $steps = [];
        foreach (self::DEADLINES as $key => [$flag, , $doneField]) {
            if (!self::applies($flag, $fields)) {
                continue;
            }
            $doneAt = self::time($fields[$doneField] ?? null) !== null ? (string) $fields[$doneField] : null;
            $reason = null;
            $start = null;
            $due = null;
            if (isset(self::APPLICABLE_FROM[$flag]) && $detected < (int) strtotime(self::APPLICABLE_FROM[$flag])) {
                $reason = self::REASON_BEFORE_APPLICATION;
            } else {
                [$start, $due] = self::window($key, $fields, $detected, $steps);
                if ($due === null) {
                    $reason = self::REASON_AWAITING_FIX;
                }
            }
            $steps[$key] = ['due' => $due, 'done' => self::time($doneAt)];

            if ($reason === self::REASON_BEFORE_APPLICATION) {
                $status = self::STATUS_NOT_APPLICABLE;
            } elseif (
                $key === 'gdpr_notification' && $doneAt === null && !empty($fields[self::GDPR_NOT_NOTIFIED_FLAG])
            ) {
                $status = self::STATUS_NOT_REQUIRED;
            } elseif ($doneAt !== null) {
                $status = self::STATUS_DONE;
            } elseif ($start === null || $due === null) {
                $status = self::STATUS_WAITING;
            } else {
                $status = self::status($start, $due, $now);
            }

            $rows[] = [
                'key'     => $key,
                'due'     => $due !== null ? date('Y-m-d H:i:s', $due) : null,
                'done_at' => $doneAt,
                'status'  => $status,
                'reason'  => $reason,
            ];
        }

        return $rows;
    }

    /**
     * Issue #600 : point de départ et échéance d'une étape (null quand le point de départ manque).
     *
     * @param array<string, mixed> $fields
     * @param array<string, array{due: ?int, done: ?int}> $steps
     * @return array{0: ?int, 1: ?int}
     */
    private static function window(string $key, array $fields, int $detected, array $steps): array
    {
        $delay = self::DEADLINES[$key][1];
        switch ($key) {
            case 'dora_initial':
                $limit = (int) strtotime(self::DORA_AWARENESS_LIMIT, $detected);
                $classified = self::time($fields['dora_classified_at'] ?? null);
                if ($classified === null) {
                    // Classification pas encore datée : le plafond des 24 heures s'applique.
                    return [$detected, $limit];
                }
                // Une classification saisie avant la détection est ramenée à la détection.
                $classified = max($classified, $detected);
                $due = (int) strtotime($delay, $classified);

                // Article 5.1 a : 4 h et 24 h au plus ; article 5.2 : classification tardive, 4 h seules.
                return [$classified, $classified > $limit ? $due : min($due, $limit)];
            case 'dora_intermediate':
            case 'dora_final':
            case 'cra_final_report':
                if ($key === 'cra_final_report' && ($fields['cra_report_type'] ?? '') === self::CRA_VULNERABILITY) {
                    $fixed = self::time($fields['cra_fix_available_at'] ?? null);

                    return $fixed === null
                        ? [null, null]
                        : [$fixed, (int) strtotime(self::CRA_FIX_DELAY, $fixed)];
                }
                $previous = $steps[self::PREVIOUS[$key]] ?? ['due' => null, 'done' => null];
                $base = $previous['done'] ?? $previous['due'];

                return $base === null ? [null, null] : [$base, (int) strtotime($delay, $base)];
            default:
                return [$detected, (int) strtotime($delay, $detected)];
        }
    }

    /** Étape dont part une étape enchaînée. */
    private const PREVIOUS = [
        'dora_intermediate' => 'dora_initial',
        'dora_final'        => 'dora_intermediate',
        'cra_final_report'  => 'cra_notification',
    ];

    /**
     * @param array<string, mixed> $fields
     */
    private static function applies(string $flag, array $fields): bool
    {
        if ($flag === 'cra_report_type') {
            return in_array((string) ($fields[$flag] ?? ''), self::CRA_TYPES, true);
        }

        return !empty($fields[$flag]);
    }

    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    private static function status(int $start, int $due, int $now): string
    {
        if ($now > $due) {
            return self::STATUS_OVERDUE;
        }
        $window = max(1, $due - $start);

        return ($due - $now) / $window <= self::DUE_SOON_RATIO ? self::STATUS_DUE_SOON : self::STATUS_PENDING;
    }

    /**
     * Issue #600 : retire les régimes désactivés (référentiel DORA, option CRA) : leurs échéances ne
     * sont ni affichées, ni comptées, ni alertées. Rien n'est effacé en base.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function restrictRegimes(array $fields, bool $doraEnabled, bool $craEnabled): array
    {
        if (!$doraEnabled) {
            $fields['dora_major'] = 0;
        }
        if (!$craEnabled) {
            $fields['cra_report_type'] = '';
        }

        return $fields;
    }

    /**
     * Issue #372 : échéances non encore notifiées pour les bandeaux en tête de l'incident, la plus
     * urgente d'abord, avec le nombre d'heures entières restantes (0 une fois dépassée). Les échéances
     * sans date (point de départ inconnu, régime pas encore applicable) n'y figurent pas.
     *
     * @param array<string, mixed> $fields champs de l'incident
     * @return list<array{key: string, due: string, done_at: ?string, status: string, reason: ?string,
     *     hours_left: int}>
     */
    public static function banners(array $fields, ?int $now = null): array
    {
        $now ??= time();
        $rows = [];
        foreach (self::compute($fields, $now) as $row) {
            $due = $row['due'];
            if ($due === null || $row['done_at'] !== null || !self::isOpen($row['status'])) {
                continue;
            }
            $row['due'] = $due;
            $row['hours_left'] = max(0, (int) floor(((int) strtotime($due) - $now) / 3600));
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['due'], $b['due']));

        return $rows;
    }

    /** Échéance encore à tenir (ni faite, ni écartée, ni sans date). */
    private static function isOpen(string $status): bool
    {
        return in_array($status, [self::STATUS_PENDING, self::STATUS_DUE_SOON, self::STATUS_OVERDUE], true);
    }

    /**
     * Échéances qui appellent une alerte (proche ou dépassée) et qui n'en ont pas encore reçu pour
     * ce niveau : une alerte « proche », puis une « dépassée », jamais deux fois la même.
     *
     * @param array<string, mixed> $fields champs de l'incident
     * @param list<string> $alreadyAlerted clés « échéance:statut » déjà signalées
     * @return list<array{key: string, due: ?string, done_at: ?string, status: string, reason: ?string}>
     */
    public static function toAlert(array $fields, array $alreadyAlerted, ?int $now = null): array
    {
        return array_values(array_filter(
            self::compute($fields, $now),
            static fn (array $row): bool => in_array(
                $row['status'],
                [self::STATUS_DUE_SOON, self::STATUS_OVERDUE],
                true
            )
                && !in_array($row['key'] . ':' . $row['status'], $alreadyAlerted, true)
        ));
    }
}
