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

namespace GlpiPlugin\Grcmanager\Services\Backup;

use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;
use GlpiPlugin\Grcmanager\Services\Logging\LogCoverageRules;

/**
 * Issue #838 : logique pure de la couverture des sauvegardes (A.8.13, A.5.30), sans base de
 * données : lecture d'un export CSV d'un outil de sauvegarde (Acronis, Commvault, Synology Active
 * Backup, Proxmox Backup Server…), résultat d'une sauvegarde, état d'un actif (sauvegarde récente,
 * dernière tentative en échec, trop ancienne au regard du RPO, aucune), ancienneté admise et état
 * du dernier test de restauration.
 *
 * Le plugin lit seulement : il ne lance aucune sauvegarde ni restauration et ne modifie rien.
 */
final class BackupCoverageRules
{
    /** Outils de sauvegarde reconnus (Veeam : connecteur ou import CSV, les autres : import CSV). */
    public const SOURCES = ['veeam', 'acronis', 'commvault', 'synology', 'proxmox', 'other'];

    /** Résultat d'une sauvegarde. */
    public const RESULT_SUCCESS = 'success';
    public const RESULT_WARNING = 'warning';
    public const RESULT_FAILED = 'failed';
    public const RESULT_UNKNOWN = 'unknown';

    /** État d'un actif, du plus grave au meilleur. */
    public const STATUS_NONE = 'none';
    public const STATUS_TOO_OLD = 'too_old';
    public const STATUS_FAILED = 'failed';
    public const STATUS_OK = 'ok';
    public const STATUSES = [self::STATUS_NONE, self::STATUS_TOO_OLD, self::STATUS_FAILED, self::STATUS_OK];

    /** Dernier test de restauration d'un actif. */
    public const RESTORE_RECENT = 'recent';
    public const RESTORE_OLD = 'old';
    public const RESTORE_FAILED = 'failed';
    public const RESTORE_NEVER = 'never';

    /** Origine de l'ancienneté admise. */
    public const LIMIT_ASSET = 'asset';
    public const LIMIT_PROCESS = 'process';
    public const LIMIT_DEFAULT = 'default';

    /** Actifs concernés. */
    public const SCOPE_CRITICAL_SERVERS = 'critical_servers';
    public const SCOPE_CRITICAL = 'critical';
    public const SCOPE_ALL = 'all';
    public const SCOPES = [self::SCOPE_CRITICAL_SERVERS, self::SCOPE_CRITICAL, self::SCOPE_ALL];

    /** Réglages par défaut. */
    public const DEFAULT_MAX_AGE_HOURS = 48;
    public const MAX_AGE_HOURS = 24 * 366;
    public const DEFAULT_RESTORE_MONTHS = 12;
    public const MAX_RESTORE_MONTHS = 60;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Colonnes reconnues => en-têtes usuels normalisés (minuscules, sans séparateurs) : Acronis
     * (Name, Last backup, Status), Synology Active Backup (Device, Last backup time, Status),
     * Commvault (Client, Last successful backup), Proxmox Backup Server (backup-id, backup-time),
     * journal de travaux (Job, Start time, End time, Result).
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'host'         => [
            'host', 'hostname', 'vm', 'vmname', 'machine', 'computer', 'computername', 'device', 'devicename',
            'client', 'clientname', 'server', 'workload', 'resource', 'object', 'objectname', 'backupid', 'name',
            'asset', 'hote', 'nom', 'serveur', 'poste', 'appareil',
        ],
        'ip'           => ['ip', 'ipaddress', 'address', 'adresse', 'adresseip'],
        'job'          => [
            'job', 'jobname', 'backupjob', 'plan', 'planname', 'policy', 'policyname', 'task', 'taskname',
            'schedule', 'storagepolicy', 'datastore', 'tache', 'travail', 'plandesauvegarde',
        ],
        'last_success' => [
            'lastsuccess', 'lastsuccessful', 'lastsuccessfulbackup', 'lastsuccessbackup', 'lastgoodbackup',
            'lastbackup', 'lastbackuptime', 'lastbackupdate', 'backuptime', 'restorepoint', 'lastrestorepoint',
            'derniersauvegarde', 'dernieresauvegarde', 'dernieresauvegardereussie',
        ],
        'date'         => [
            'lastrun', 'lastattempt', 'endtime', 'end', 'finished', 'finishtime', 'starttime', 'start', 'date',
            'time', 'datedefin', 'datededebut', 'dernierlancement',
        ],
        'result'       => [
            'result', 'lastresult', 'status', 'laststatus', 'state', 'outcome', 'resultat', 'statut', 'etat',
        ],
    ];

    /**
     * En-tête comparable : sans accent (exports en français : « Dernière sauvegarde »), minuscules,
     * lettres et chiffres seulement.
     */
    public static function normalizeHeader(string $header): string
    {
        return EdrCsvImport::normalizeHeader(self::withoutAccents($header));
    }

    private static function withoutAccents(string $value): string
    {
        return strtr(mb_strtolower($value), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
    }

    public static function normalizeSource(mixed $source): string
    {
        $source = is_string($source) ? mb_strtolower(trim($source)) : '';

        return in_array($source, self::SOURCES, true) ? $source : 'other';
    }

    /**
     * Réglages relus d'une valeur stockée ou soumise : bornes et valeurs par défaut.
     *
     * @return array{scope: string, max_age_hours: int, restore_months: int}
     */
    public static function normalizeSettings(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $scope = (string) ($raw['scope'] ?? '');
        $hours = $raw['max_age_hours'] ?? null;
        $months = $raw['restore_months'] ?? null;

        return [
            'scope'          => in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_CRITICAL_SERVERS,
            'max_age_hours'  => is_numeric($hours)
                ? max(1, min(self::MAX_AGE_HOURS, (int) $hours))
                : self::DEFAULT_MAX_AGE_HOURS,
            'restore_months' => is_numeric($months)
                ? max(1, min(self::MAX_RESTORE_MONTHS, (int) $months))
                : self::DEFAULT_RESTORE_MONTHS,
        ];
    }

    /**
     * Actif concerné par les sauvegardes selon le réglage : actifs critiques et serveurs (défaut),
     * actifs critiques seulement, ou tous les ordinateurs et matériels réseau.
     */
    public static function isConcerned(string $scope, bool $critical, bool $isServer): bool
    {
        return match ($scope) {
            self::SCOPE_ALL      => true,
            self::SCOPE_CRITICAL => $critical,
            default              => $critical || $isServer,
        };
    }

    /**
     * Résultat d'une sauvegarde lu dans un export (anglais, français, valeurs de Veeam et des outils
     * usuels). Vide ou inconnu : RESULT_UNKNOWN.
     */
    public static function normalizeResult(mixed $value): string
    {
        $value = is_scalar($value) ? self::withoutAccents(trim((string) $value)) : '';
        if ($value === '') {
            return self::RESULT_UNKNOWN;
        }
        if (in_array($value, ['ko', 'nok', 'no', 'non', 'false', '0'], true)) {
            return self::RESULT_FAILED;
        }
        if (in_array($value, ['ok', 'yes', 'oui', 'true', '1'], true)) {
            return self::RESULT_SUCCESS;
        }
        $failed = ['fail', 'error', 'echec', 'echoue', 'erreur', 'unsuccess', 'aborted', 'interrompu', 'missed'];
        foreach ($failed as $word) {
            if (str_contains($value, $word)) {
                return self::RESULT_FAILED;
            }
        }
        foreach (['warn', 'avertissement', 'partial', 'partiel', 'incomplet', 'attention'] as $word) {
            if (str_contains($value, $word)) {
                return self::RESULT_WARNING;
            }
        }
        foreach (['success', 'succeed', 'reussi', 'complete', 'termine', 'done', 'passed', 'good'] as $word) {
            if (str_contains($value, $word)) {
                return self::RESULT_SUCCESS;
            }
        }

        return self::RESULT_UNKNOWN;
    }

    /**
     * Ancienneté admise d'une sauvegarde, en heures : le plus petit RPO défini entre celui de l'actif
     * (classification) et ceux des processus métier qui l'utilisent, sinon le réglage par défaut.
     *
     * @param list<int> $processRpoHours RPO des processus de l'actif (0 = non défini)
     * @return array{hours: int, origin: string}
     */
    public static function allowedAge(int $assetRpoHours, array $processRpoHours, int $defaultHours): array
    {
        $processes = array_values(array_filter($processRpoHours, static fn (int $hours): bool => $hours > 0));
        $process = $processes === [] ? 0 : min($processes);
        if ($assetRpoHours > 0 && ($process === 0 || $assetRpoHours <= $process)) {
            return ['hours' => $assetRpoHours, 'origin' => self::LIMIT_ASSET];
        }
        if ($process > 0) {
            return ['hours' => $process, 'origin' => self::LIMIT_PROCESS];
        }

        return ['hours' => max(1, $defaultHours), 'origin' => self::LIMIT_DEFAULT];
    }

    /**
     * Heures entières écoulées depuis une date, null si la date est absente ou illisible.
     */
    public static function ageHours(?string $date, string $now): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $from = strtotime($date);
        $to = strtotime($now);
        if ($from === false || $to === false) {
            return null;
        }

        return max(0, intdiv($to - $from, 3600));
    }

    /**
     * État d'un actif : aucune sauvegarde réussie connue, dernière sauvegarde réussie plus ancienne
     * que l'ancienneté admise, dernière tentative en échec (la précédente réussite restant dans le
     * délai), sinon sauvegarde récente.
     */
    public static function status(?string $lastSuccess, string $lastResult, string $now, int $allowedHours): string
    {
        $age = self::ageHours($lastSuccess, $now);
        if ($age === null) {
            return self::STATUS_NONE;
        }
        if ($age > $allowedHours) {
            return self::STATUS_TOO_OLD;
        }

        return $lastResult === self::RESULT_FAILED ? self::STATUS_FAILED : self::STATUS_OK;
    }

    /**
     * État du dernier test de restauration : jamais testé, échoué, plus ancien que la fréquence
     * attendue, sinon récent.
     */
    public static function restoreStatus(?string $testDate, bool $failed, string $now, int $months): string
    {
        if ($testDate === null || trim($testDate) === '') {
            return self::RESTORE_NEVER;
        }
        $limit = strtotime(sprintf('-%d months', max(1, $months)), (int) strtotime($now));
        $tested = strtotime($testDate);
        if ($tested === false || $limit === false || $tested < $limit) {
            return self::RESTORE_OLD;
        }

        return $failed ? self::RESTORE_FAILED : self::RESTORE_RECENT;
    }

    /**
     * Synthèse d'une liste d'actifs : nombre par état, taux de sauvegardes dans les délais
     * (récentes, même si la dernière tentative a échoué) et actifs sans sauvegarde récente.
     *
     * @param list<array{status: string}> $rows
     * @return array{total: int, none: int, too_old: int, failed: int, ok: int, rate: ?int, uncovered: int}
     */
    public static function summary(array $rows): array
    {
        $summary = [
            'total' => count($rows), 'none' => 0, 'too_old' => 0, 'failed' => 0, 'ok' => 0,
            'rate' => null, 'uncovered' => 0,
        ];
        foreach ($rows as $row) {
            $status = in_array($row['status'], self::STATUSES, true) ? $row['status'] : self::STATUS_NONE;
            $summary[$status]++;
        }
        $summary['uncovered'] = $summary['none'] + $summary['too_old'];
        if ($summary['total'] > 0) {
            $summary['rate'] = (int) floor(($summary['ok'] + $summary['failed']) * 100 / $summary['total']);
        }

        return $summary;
    }

    public static function latest(?string $a, ?string $b): ?string
    {
        return LogCoverageRules::latest($a, $b);
    }

    /**
     * Fusionne une sauvegarde dans l'état connu d'un hôte : dernière réussite la plus récente,
     * dernière tentative la plus récente et son résultat, travail de la dernière tentative.
     *
     * @param array{host: string, ip: string, job: string, last_success: ?string, last_attempt: ?string,
     *     last_result: string}|null $known
     * @param array{host: string, ip: string, job: string, last_success: ?string, last_attempt: ?string,
     *     last_result: string} $row
     * @return array{host: string, ip: string, job: string, last_success: ?string, last_attempt: ?string,
     *     last_result: string}
     */
    public static function merge(?array $known, array $row): array
    {
        if ($known === null) {
            return $row;
        }
        $known['last_success'] = self::latest($known['last_success'], $row['last_success']);
        $newer = $row['last_attempt'] !== null
            && ($known['last_attempt'] === null || strcmp($row['last_attempt'], $known['last_attempt']) > 0);
        if ($newer || ($known['last_attempt'] === null && $known['last_result'] === self::RESULT_UNKNOWN)) {
            $known['last_attempt'] = $row['last_attempt'] ?? $known['last_attempt'];
            $known['last_result'] = $row['last_result'];
            $known['job'] = $row['job'] !== '' ? $row['job'] : $known['job'];
        }
        if ($known['ip'] === '' && $row['ip'] !== '') {
            $known['ip'] = $row['ip'];
        }

        return $known;
    }

    /**
     * Lit un export CSV (en-tête obligatoire, séparateur et encodage détectés) en sauvegardes par
     * hôte. Une ligne par hôte (état de synthèse) ou une ligne par exécution (journal de travaux) :
     * les lignes du même hôte sont fusionnées. Sans colonne « dernière réussite », la date d'une
     * exécution réussie (ou de résultat inconnu) compte comme réussite.
     *
     * @return array{rows: list<array{host: string, ip: string, job: string, last_success: ?string,
     *     last_attempt: ?string, last_result: string}>, errors: list<string>, truncated: bool}
     */
    public static function parseCsv(string $raw, string $timezone = 'UTC'): array
    {
        $parsed = EdrCsvImport::parse($raw);
        $mapping = [];
        foreach (self::FIELDS as $field => $aliases) {
            foreach ($aliases as $alias) {
                foreach ($parsed['headers'] as $index => $header) {
                    if (!in_array($index, $mapping, true) && self::normalizeHeader($header) === $alias) {
                        $mapping[$field] = $index;
                        break 2;
                    }
                }
            }
        }
        if (!isset($mapping['host']) && !isset($mapping['ip'])) {
            return ['rows' => [], 'errors' => ['missing_host'], 'truncated' => false];
        }
        if (!isset($mapping['last_success']) && !isset($mapping['date'])) {
            return ['rows' => [], 'errors' => ['missing_date'], 'truncated' => false];
        }
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $hosts = [];
        foreach ($parsed['rows'] as $row) {
            $host = mb_substr($get($row, 'host'), 0, 255);
            $ip = mb_substr($get($row, 'ip'), 0, 64);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $ip = '';
            }
            if ($host === '' && $ip === '') {
                continue;
            }
            $result = self::normalizeResult($get($row, 'result'));
            $date = $get($row, 'date');
            $attempt = $date !== '' ? EdrCsvImport::parseDate($date, $timezone) : null;
            $success = $get($row, 'last_success');
            $lastSuccess = $success !== '' ? EdrCsvImport::parseDate($success, $timezone) : null;
            if (!isset($mapping['last_success']) && $result !== self::RESULT_FAILED) {
                $lastSuccess = $attempt;
            }
            if (isset($mapping['last_success']) && !isset($mapping['date']) && $result !== self::RESULT_UNKNOWN) {
                // État de synthèse : le résultat est celui de la dernière tentative, datée de la réussite.
                $attempt = $result === self::RESULT_FAILED ? null : $lastSuccess;
            }
            $key = mb_strtolower($host) . '|' . $ip;
            $hosts[$key] = self::merge($hosts[$key] ?? null, [
                'host'         => $host,
                'ip'           => $ip,
                'job'          => mb_substr($get($row, 'job'), 0, 255),
                'last_success' => $lastSuccess,
                'last_attempt' => $attempt,
                'last_result'  => $result,
            ]);
        }

        return ['rows' => array_values($hosts), 'errors' => [], 'truncated' => $parsed['truncated']];
    }
}
