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

namespace GlpiPlugin\Grcmanager\Services\CodeSecurity;

/**
 * Issue #858 : règles pures (sans base de données) de la sécurité du code lue dans GitHub et GitLab.
 *
 * - Reconnaissance du dépôt d'une application (adresse https ou ssh de la fiche « Développement
 *   sécurisé ») : GitHub (github.com ou un serveur GitHub Enterprise) ou GitLab (gitlab.com ou le
 *   serveur configuré).
 * - Trois familles d'alertes : dépendances vulnérables (Dependabot, analyse des dépendances et des
 *   conteneurs de GitLab), secrets exposés (secret scanning, détection de secrets) et analyse du code
 *   (code scanning, SAST et DAST de GitLab).
 * - Gravité ramenée à quatre niveaux ; délai de correction de la politique des vulnérabilités du parc
 *   (#601) : celui du niveau de gravité, et celui des vulnérabilités activement exploitées pour un
 *   secret exposé, qui doit être révoqué sans attendre.
 * - Un secret ouvert que le fournisseur ne sait pas révoqué (validité « active » ou inconnue) est
 *   prioritaire.
 *
 * Rien n'est modifié : ces états servent à afficher et à mesurer.
 */
final class CodeSecurityRules
{
    public const PROVIDER_GITHUB = 'github';
    public const PROVIDER_GITLAB = 'gitlab';

    public const PROVIDERS = [self::PROVIDER_GITHUB, self::PROVIDER_GITLAB];

    public const CATEGORY_DEPENDENCY = 'dependency';
    public const CATEGORY_SECRET = 'secret';
    public const CATEGORY_CODE = 'code';

    public const CATEGORIES = [self::CATEGORY_SECRET, self::CATEGORY_DEPENDENCY, self::CATEGORY_CODE];

    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /** Gravités, de la plus urgente à la moins urgente. */
    public const SEVERITIES = [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW];

    /** Analyse lue (même sans alerte), ou non activée / non accessible avec le jeton. */
    public const SCAN_OK = 'ok';
    public const SCAN_UNAVAILABLE = 'unavailable';

    /** État de la dernière lecture d'un dépôt. */
    public const REPO_OK = 'ok';
    public const REPO_UNSUPPORTED = 'unsupported';
    public const REPO_NOT_CONFIGURED = 'not_configured';
    public const REPO_ERROR = 'error';

    /** Validité d'un secret selon le fournisseur (GitHub) ; vide : non indiquée (GitLab). */
    public const VALIDITY_ACTIVE = 'active';
    public const VALIDITY_INACTIVE = 'inactive';
    public const VALIDITY_UNKNOWN = 'unknown';

    /** Tranches d'ancienneté des alertes ouvertes. */
    public const AGE_RECENT = 'recent';
    public const AGE_MONTHS = 'months';
    public const AGE_OLD = 'old';

    public const AGE_BUCKETS = [self::AGE_RECENT, self::AGE_MONTHS, self::AGE_OLD];

    /** Délais proposés quand la politique n'est pas renseignée (jours). */
    public const DEFAULT_DAYS = [
        'kev'          => 7,
        self::CRITICAL => 15,
        self::HIGH     => 30,
        self::MEDIUM   => 90,
        self::LOW      => 180,
    ];

    /**
     * Adresse d'un serveur (GitHub Enterprise, GitLab) : https, ou http si les adresses internes sont
     * autorisées ; sans identifiants, paramètres ni fragment. null si refusée.
     */
    public static function normalizeServerUrl(string $url, bool $allowHttp = false): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            $url === '' || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !($scheme === 'https' || ($scheme === 'http' && $allowHttp))) {
            return null;
        }
        if (preg_match('/^[a-z0-9.\-]+$/', $host) !== 1) {
            return null;
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && (preg_match('#^[A-Za-z0-9/_.\-~]*$#', $path) !== 1 || str_contains($path, '..'))) {
            return null;
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
    }

    public static function hostOf(string $url): string
    {
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    }

    /**
     * Dépôt reconnu dans l'adresse de la fiche : https://hôte/chemin, ssh://git@hôte[:port]/chemin ou
     * git@hôte:chemin, avec ou sans « .git ». GitHub : propriétaire/dépôt (github.com, ou l'hôte de
     * GitHub Enterprise) ; GitLab : groupe(s)/projet (hôte du serveur GitLab). null sinon.
     *
     * @return array{provider: string, path: string, host: string}|null
     */
    public static function parseRepository(string $url, string $githubHost, string $gitlabHost): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $host = '';
        $path = '';
        if (preg_match('#^[A-Za-z0-9_.\-]+@([A-Za-z0-9.\-]+):(?!//)(.+)$#', $url, $m) === 1) {
            // Forme courte de ssh : git@hôte:chemin.
            $host = strtolower($m[1]);
            $path = $m[2];
        } else {
            $parts = parse_url($url);
            if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http', 'ssh'], true)) {
                return null;
            }
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
        }
        $path = trim($path, '/');
        // Pages d'un projet GitLab (« /-/tree/main ») ou d'un dépôt GitHub (« /tree/main ») ignorées.
        $path = (string) preg_replace('#/-/.*$#', '', $path);
        $path = (string) preg_replace('#\.git$#i', '', $path);
        if ($host === '' || $path === '' || str_contains($path, '..')) {
            return null;
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $segment) !== 1) {
                return null;
            }
        }
        $githubHosts = array_filter(['github.com', 'www.github.com', strtolower($githubHost)]);
        if (in_array($host, $githubHosts, true)) {
            if (count($segments) < 2) {
                return null;
            }

            return [
                'provider' => self::PROVIDER_GITHUB,
                'path'     => $segments[0] . '/' . preg_replace('#\.git$#i', '', $segments[1]),
                'host'     => $host === 'www.github.com' ? 'github.com' : $host,
            ];
        }
        if ($gitlabHost !== '' && $host === strtolower($gitlabHost)) {
            if (count($segments) < 2) {
                return null;
            }

            return ['provider' => self::PROVIDER_GITLAB, 'path' => implode('/', $segments), 'host' => $host];
        }

        return null;
    }

    /**
     * Gravité ramenée à quatre niveaux. GitHub : low, medium (ou moderate), high, critical ; code
     * scanning sans niveau de sécurité : error, warning, note. GitLab : CRITICAL... INFO, UNKNOWN.
     * Une gravité inconnue compte comme moyenne.
     */
    public static function normalizeSeverity(string $severity): string
    {
        return match (strtolower(trim($severity))) {
            'critical'         => self::CRITICAL,
            'high', 'error'    => self::HIGH,
            'low', 'note', 'info', 'informational', 'none' => self::LOW,
            default            => self::MEDIUM,
        };
    }

    public static function normalizeValidity(string $validity): string
    {
        $validity = strtolower(trim($validity));

        return in_array($validity, [self::VALIDITY_ACTIVE, self::VALIDITY_INACTIVE, self::VALIDITY_UNKNOWN], true)
            ? $validity : '';
    }

    /**
     * Délais de la politique, bornés (au moins un jour) ; un niveau absent prend le délai proposé.
     *
     * @param array<string, mixed> $days
     * @return array<string, int>
     */
    public static function policyDays(array $days): array
    {
        $out = [];
        foreach (self::DEFAULT_DAYS as $level => $default) {
            $value = $days[$level] ?? null;
            $out[$level] = is_numeric($value) && (int) $value >= 1 ? min(3650, (int) $value) : $default;
        }

        return $out;
    }

    /**
     * Délai de correction d'une alerte, en jours.
     *
     * @param array<string, int> $days délais de la politique (policyDays())
     */
    public static function deadline(string $category, string $severity, array $days): int
    {
        if ($category === self::CATEGORY_SECRET) {
            return $days['kev'] ?? self::DEFAULT_DAYS['kev'];
        }

        return $days[$severity] ?? $days[self::MEDIUM] ?? self::DEFAULT_DAYS[self::MEDIUM];
    }

    /** Un secret ouvert que rien ne dit révoqué : à traiter en premier. */
    public static function isPriority(string $category, string $validity): bool
    {
        return $category === self::CATEGORY_SECRET && $validity !== self::VALIDITY_INACTIVE;
    }

    public static function ageBucket(int $ageDays): string
    {
        return match (true) {
            $ageDays < 30  => self::AGE_RECENT,
            $ageDays <= 90 => self::AGE_MONTHS,
            default        => self::AGE_OLD,
        };
    }

    /**
     * État d'une alerte ouverte au jour $today : ancienneté, échéance et retard.
     *
     * @param array<string, int> $days
     * @return array{age: int, bucket: string, deadline: int, due: string, days_left: int, overdue: bool,
     *     priority: bool}
     */
    public static function state(
        string $category,
        string $severity,
        string $validity,
        string $detected,
        string $today,
        array $days
    ): array {
        $todayTs = (int) strtotime(substr($today, 0, 10));
        $detectedTs = strtotime(substr($detected, 0, 10));
        $detectedTs = $detectedTs === false ? $todayTs : min($todayTs, (int) $detectedTs);
        $age = (int) floor(($todayTs - $detectedTs) / 86400);
        $deadline = self::deadline($category, $severity, $days);
        $dueTs = $detectedTs + $deadline * 86400;
        $left = (int) floor(($dueTs - $todayTs) / 86400);

        return [
            'age'       => $age,
            'bucket'    => self::ageBucket($age),
            'deadline'  => $deadline,
            'due'       => date('Y-m-d', $dueTs),
            'days_left' => $left,
            'overdue'   => $left < 0,
            'priority'  => self::isPriority($category, $validity),
        ];
    }

    /**
     * Rang de tri : secrets prioritaires, alertes en retard, gravité, ancienneté (la plus ancienne
     * d'abord).
     *
     * @param array{priority: bool, overdue: bool, severity: string, age: int} $alert
     * @return list<int|bool>
     */
    public static function rank(array $alert): array
    {
        $severity = array_search($alert['severity'], self::SEVERITIES, true);

        return [!$alert['priority'], !$alert['overdue'], $severity === false ? 9 : (int) $severity, -$alert['age']];
    }

    /**
     * Bilan d'une liste d'alertes ouvertes, chacune avec son état (state()).
     *
     * @param list<array<string, mixed>> $alerts
     * @return array{open: int, severities: array<string, int>, categories: array<string, int>,
     *     ages: array<string, int>, overdue: int, secrets_priority: int, oldest: int}
     */
    public static function summarize(array $alerts): array
    {
        $out = [
            'open'             => count($alerts),
            'severities'       => array_fill_keys(self::SEVERITIES, 0),
            'categories'       => array_fill_keys(self::CATEGORIES, 0),
            'ages'             => array_fill_keys(self::AGE_BUCKETS, 0),
            'overdue'          => 0,
            'secrets_priority' => 0,
            'oldest'           => 0,
        ];
        foreach ($alerts as $alert) {
            $severity = (string) ($alert['severity'] ?? self::MEDIUM);
            $category = (string) ($alert['category'] ?? '');
            $out['severities'][$severity] = ($out['severities'][$severity] ?? 0) + 1;
            if (isset($out['categories'][$category])) {
                $out['categories'][$category]++;
            }
            $bucket = (string) ($alert['bucket'] ?? self::AGE_RECENT);
            $out['ages'][$bucket] = ($out['ages'][$bucket] ?? 0) + 1;
            $out['overdue'] += !empty($alert['overdue']) ? 1 : 0;
            $out['secrets_priority'] += !empty($alert['priority']) ? 1 : 0;
            $out['oldest'] = max($out['oldest'], (int) ($alert['age'] ?? 0));
        }

        return $out;
    }

    /** Pire gravité d'une liste ('' si vide). */
    public static function worstSeverity(array $severities): string
    {
        foreach (self::SEVERITIES as $severity) {
            if (in_array($severity, $severities, true)) {
                return $severity;
            }
        }

        return '';
    }

    /** Adresse web gardée seulement si elle est en http(s), sans identifiants. */
    public static function webUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            $url === '' || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
        ) {
            return '';
        }

        return mb_substr($url, 0, 1024);
    }

    /** Date ISO 8601 de l'API en « Y-m-d H:i:s » (UTC), '' si illisible. */
    public static function parseDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $time = strtotime($value);

        return $time === false ? '' : gmdate('Y-m-d H:i:s', $time);
    }
}
