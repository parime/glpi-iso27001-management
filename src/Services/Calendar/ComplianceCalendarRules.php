<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Calendar;

/**
 * Issue #142 : calendrier de conformité, sans dépendance à GLPI (testé unitairement) — types
 * d'événements, normalisation des saisies, tri, et export iCalendar (RFC 5545).
 */
final class ComplianceCalendarRules
{
    public const EVENT_TYPES = [
        'internal_audit',
        'certification_audit',
        'surveillance_audit',
        'pentest',
        'crisis_exercise',
        'management_review',
        'certificate_renewal',
        'other',
    ];
    public const DEFAULT_EVENT_TYPE = 'other';

    public const STATUSES = ['planned', 'done', 'cancelled'];
    public const DEFAULT_STATUS = 'planned';

    /** Sources agrégées dans le calendrier (événements saisis + échéances des autres registres). */
    public const SOURCES = [
        'event', 'audit', 'management_review', 'access_review', 'periodic_review', 'policy', 'obligation',
        'evidence',
    ];

    public static function normalizeType(mixed $value): string
    {
        $value = (string) $value;
        return in_array($value, self::EVENT_TYPES, true) ? $value : self::DEFAULT_EVENT_TYPE;
    }

    public static function normalizeStatus(mixed $value): string
    {
        $value = (string) $value;
        return in_array($value, self::STATUSES, true) ? $value : self::DEFAULT_STATUS;
    }

    /**
     * Début et fin cohérents : fin absente ou antérieure = journée entière à partir du début.
     *
     * @return array{0: string, 1: string}|null [début, fin] au format Y-m-d H:i:s, null si début invalide
     */
    public static function normalizeRange(mixed $begin, mixed $end): ?array
    {
        $beginTime = strtotime((string) $begin);
        if ($begin === null || $begin === '' || $beginTime === false) {
            return null;
        }
        $endTime = ($end === null || $end === '') ? false : strtotime((string) $end);
        if ($endTime === false || $endTime <= $beginTime) {
            $endTime = strtotime(date('Y-m-d', $beginTime) . ' +1 day');
        }

        return [date('Y-m-d H:i:s', $beginTime), date('Y-m-d H:i:s', (int) $endTime)];
    }

    /**
     * Une échéance d'un autre registre (une date sans heure) : journée entière.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function dayRange(?string $date): ?array
    {
        if ($date === null || $date === '' || strtotime($date) === false) {
            return null;
        }
        $day = date('Y-m-d', (int) strtotime($date));

        return [$day . ' 00:00:00', date('Y-m-d', (int) strtotime($day . ' +1 day')) . ' 00:00:00'];
    }

    public static function overlaps(string $begin, string $end, string $rangeBegin, string $rangeEnd): bool
    {
        return $begin < $rangeEnd && $end > $rangeBegin;
    }

    /** Issue #142 : le responsable est prévenu 15 jours avant le début de l'événement. */
    public const REMINDER_DAYS = 15;

    /**
     * Rappel dû : événement planifié, avec un responsable, qui commence dans les 15 prochains
     * jours (ou aujourd'hui) et pour lequel aucun rappel n'a encore été envoyé.
     */
    public static function reminderDue(
        string $status,
        ?string $dateBegin,
        int $usersId,
        ?string $notifiedAt,
        string $today
    ): bool {
        if ($status !== 'planned' || $usersId <= 0 || ($notifiedAt !== null && $notifiedAt !== '')) {
            return false;
        }
        if ($dateBegin === null || $dateBegin === '') {
            return false;
        }
        $day = substr($dateBegin, 0, 10);
        $limit = date('Y-m-d', (int) strtotime($today . ' +' . self::REMINDER_DAYS . ' days'));

        return $day >= $today && $day <= $limit;
    }

    /**
     * @param list<array{begin: string}> $events
     * @return list<array{begin: string}>
     */
    public static function sortByDate(array $events): array
    {
        usort($events, static fn (array $a, array $b): int => strcmp($a['begin'], $b['begin']));
        return $events;
    }

    /**
     * Calendrier iCalendar : un VEVENT par échéance, journée entière quand l'échéance n'a pas d'heure.
     *
     * @param list<array{uid: string, name: string, begin: string, end: string, description?: string,
     *     url?: string}> $events
     */
    public static function toIcs(array $events, string $calendarName, string $now): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//GLPI GRC Manager//Calendrier de conformite//FR',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:' . self::escape($calendarName),
        ];
        $stamp = gmdate('Ymd\THis\Z', (int) strtotime($now));
        foreach ($events as $event) {
            $allDay = str_ends_with($event['begin'], '00:00:00') && str_ends_with($event['end'], '00:00:00');
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::singleLine((string) $event['uid']);
            $lines[] = 'DTSTAMP:' . $stamp;
            if ($allDay) {
                $lines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', (int) strtotime($event['begin']));
                $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', (int) strtotime($event['end']));
            } else {
                $lines[] = 'DTSTART:' . date('Ymd\THis', (int) strtotime($event['begin']));
                $lines[] = 'DTEND:' . date('Ymd\THis', (int) strtotime($event['end']));
            }
            $lines[] = 'SUMMARY:' . self::escape($event['name']);
            if (($event['description'] ?? '') !== '') {
                $lines[] = 'DESCRIPTION:' . self::escape((string) $event['description']);
            }
            if (($event['url'] ?? '') !== '') {
                $lines[] = 'URL:' . self::singleLine((string) $event['url']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * Texte d'une propriété : retours à la ligne (y compris un \r isolé) encodés, autres
     * caractères de contrôle retirés, pour qu'aucun texte ne termine la propriété et n'injecte
     * de ligne iCalendar.
     */
    private static function escape(string $text): string
    {
        $text = str_replace(["\\", ';', ',', "\r\n", "\r", "\n"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $text);

        return (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text);
    }

    /** Valeur d'UID ou d'URL : jamais de caractère de contrôle (ni CR ni LF). */
    private static function singleLine(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]/', '', $value);
    }

    /**
     * Lignes de 75 octets au plus, suite précédée d'une espace (RFC 5545 §3.1), sans couper un
     * caractère UTF-8.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $out . "\r\n " . $current;
    }
}
