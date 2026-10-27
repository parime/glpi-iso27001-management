<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Access;

/**
 * Issue #184 : contrôles d'hygiène des comptes GLPI, sans dépendance à GLPI (testés unitairement).
 * Ils signalent ; ils ne désactivent ni ne modifient jamais un compte (règle commune du plugin) : la
 * suite se décide dans un ticket ou dans la revue des accès.
 */
final class AccountHygieneRules
{
    public const DIRECTORY_DELETED = 'directory_deleted';
    public const EXPIRED = 'expired';
    public const INACTIVE = 'inactive';
    public const NEVER_USED = 'never_used';
    public const DUPLICATE_EMAIL = 'duplicate_email';
    public const DUPLICATE_NAME = 'duplicate_name';
    public const GROUP_CHANGED = 'group_changed';

    /** Durées d'inactivité proposées, en jours. */
    public const INACTIVITY_DAYS = [30, 60, 90, 180, 365];
    public const DEFAULT_INACTIVITY_DAYS = 90;

    public static function normalizeDays(mixed $days): int
    {
        $days = (int) $days;
        return in_array($days, self::INACTIVITY_DAYS, true) ? $days : self::DEFAULT_INACTIVITY_DAYS;
    }

    /**
     * Contrôles propres à un compte actif.
     *
     * @param array{is_deleted_ldap?: mixed, end_date?: mixed, last_login?: mixed, date_creation?: mixed} $user
     * @return list<string>
     */
    public static function checks(array $user, string $now, int $inactivityDays): array
    {
        $checks = [];
        if ((int) ($user['is_deleted_ldap'] ?? 0) === 1) {
            $checks[] = self::DIRECTORY_DELETED;
        }
        $end = (string) ($user['end_date'] ?? '');
        if ($end !== '' && $end < $now) {
            $checks[] = self::EXPIRED;
        }
        $limit = date('Y-m-d H:i:s', (int) strtotime($now . ' -' . $inactivityDays . ' days'));
        $lastLogin = (string) ($user['last_login'] ?? '');
        if ($lastLogin !== '') {
            if ($lastLogin < $limit) {
                $checks[] = self::INACTIVE;
            }
        } elseif ((string) ($user['date_creation'] ?? '') !== '' && (string) $user['date_creation'] < $limit) {
            $checks[] = self::NEVER_USED;
        }

        return $checks;
    }

    /**
     * Issue #184, phase 2 : changement de service. Les groupes du compte (dont ceux synchronisés
     * depuis l'annuaire) ont changé pendant la période, et ses profils GLPI n'ont pas été revus
     * depuis : ses anciens droits sont peut-être restés. Les groupes posés le jour de la création
     * du compte ne comptent pas (compte neuf, pas un changement de service).
     */
    public static function groupChangedWithoutProfileReview(
        ?string $lastGroupChange,
        ?string $lastProfileChange,
        ?string $created,
        string $now,
        int $days
    ): bool {
        if ($lastGroupChange === null || $lastGroupChange === '') {
            return false;
        }
        $since = date('Y-m-d H:i:s', (int) strtotime($now . ' -' . $days . ' days'));
        if ($lastGroupChange < $since) {
            return false;
        }
        if ($created !== null && $created !== '') {
            $settling = date('Y-m-d H:i:s', (int) strtotime($created . ' +1 day'));
            if ($lastGroupChange <= $settling) {
                return false;
            }
        }

        return $lastProfileChange === null || $lastProfileChange === '' || $lastProfileChange < $lastGroupChange;
    }

    /**
     * Doublons parmi les comptes actifs : même adresse e-mail, ou mêmes prénom et nom.
     *
     * @param list<array{id: int, emails?: list<string>, firstname?: ?string, realname?: ?string}> $users
     * @return array<int, list<string>> id du compte => contrôles DUPLICATE_*
     */
    public static function duplicates(array $users): array
    {
        $byEmail = [];
        $byName = [];
        foreach ($users as $user) {
            $emails = array_unique(array_map(
                static fn (string $email): string => strtolower(trim($email)),
                $user['emails'] ?? []
            ));
            foreach ($emails as $email) {
                if ($email !== '') {
                    $byEmail[$email][] = $user['id'];
                }
            }
            $name = self::nameKey((string) ($user['firstname'] ?? ''), (string) ($user['realname'] ?? ''));
            if ($name !== null) {
                $byName[$name][] = $user['id'];
            }
        }
        $result = [];
        foreach ([self::DUPLICATE_EMAIL => $byEmail, self::DUPLICATE_NAME => $byName] as $check => $groups) {
            foreach ($groups as $ids) {
                $ids = array_values(array_unique($ids));
                if (count($ids) < 2) {
                    continue;
                }
                foreach ($ids as $id) {
                    if (!in_array($check, $result[$id] ?? [], true)) {
                        $result[$id][] = $check;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Prénom et nom comparés sans casse, accents ni espaces superflus ; null si l'un manque (un
     * compte technique sans prénom n'est pas le « doublon » d'un autre).
     */
    public static function nameKey(string $firstname, string $realname): ?string
    {
        // Table explicite plutôt qu'iconv(TRANSLIT), dont le résultat dépend du système (« ? » sous musl).
        $normalize = static fn (string $value): string => strtr(
            mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value))),
            ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
                'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u',
                'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', '-' => ' ']
        );
        $first = $normalize($firstname);
        $last = $normalize($realname);
        if ($first === '' || $last === '') {
            return null;
        }

        return $first . '|' . $last;
    }
}
