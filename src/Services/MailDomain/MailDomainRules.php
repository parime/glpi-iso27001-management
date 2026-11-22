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

namespace GlpiPlugin\Grcmanager\Services\MailDomain;

/**
 * Issue #855 : règles pures de la sécurité de la messagerie et des domaines. Lecture des
 * enregistrements DNS publics (SPF, DKIM, DMARC, MTA-STS, TLS-RPT, DNSSEC) déjà obtenus, échéances
 * saisies (domaine, certificat des serveurs de messagerie) et écarts. Aucun appel réseau ici, aucune
 * dépendance à GLPI : les libellés sont donnés par la classe MailDomainSecurity.
 */
final class MailDomainRules
{
    public const SEVERITY_DANGER = 'danger';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    /** Ordre des gravités, de la plus forte à la plus faible. */
    public const SEVERITIES = [self::SEVERITY_DANGER, self::SEVERITY_WARNING, self::SEVERITY_INFO];

    /** Alerte avant l'échéance d'un domaine ou d'un certificat (jours). */
    public const ALERT_DAYS = 30;

    /** Sélecteurs DKIM déclarés au plus par domaine. */
    public const MAX_SELECTORS = 10;

    /** Limite de la norme SPF : dix mécanismes qui demandent une requête DNS. */
    public const SPF_MAX_LOOKUPS = 10;

    public const EXPIRY_NONE = 'none';
    public const EXPIRY_OK = 'ok';
    public const EXPIRY_SOON = 'soon';
    public const EXPIRY_EXPIRED = 'expired';

    /** Écarts possibles et leur gravité, dans l'ordre d'affichage. */
    public const FINDINGS = [
        'unresolved'            => self::SEVERITY_DANGER,
        'spf_missing'           => self::SEVERITY_DANGER,
        'spf_multiple'          => self::SEVERITY_DANGER,
        'spf_pass_all'          => self::SEVERITY_DANGER,
        'spf_neutral'           => self::SEVERITY_WARNING,
        'spf_too_many_lookups'  => self::SEVERITY_WARNING,
        'spf_softfail'          => self::SEVERITY_INFO,
        'dmarc_missing'         => self::SEVERITY_DANGER,
        'dmarc_invalid'         => self::SEVERITY_DANGER,
        'dmarc_none'            => self::SEVERITY_WARNING,
        'dmarc_partial'         => self::SEVERITY_INFO,
        'dmarc_no_reports'      => self::SEVERITY_INFO,
        'dkim_missing'          => self::SEVERITY_WARNING,
        'dkim_revoked'          => self::SEVERITY_WARNING,
        'dkim_not_declared'     => self::SEVERITY_INFO,
        'dnssec_missing'        => self::SEVERITY_WARNING,
        'dnssec_unknown'        => self::SEVERITY_INFO,
        'mta_sts_missing'       => self::SEVERITY_INFO,
        'tls_rpt_missing'       => self::SEVERITY_INFO,
        'domain_expired'        => self::SEVERITY_DANGER,
        'domain_expiring'       => self::SEVERITY_WARNING,
        'domain_expiry_unknown' => self::SEVERITY_INFO,
        'cert_expired'          => self::SEVERITY_DANGER,
        'cert_expiring'         => self::SEVERITY_WARNING,
    ];

    /**
     * Nom de domaine en minuscules, sans point final ni espaces, ou null s'il n'est pas valide
     * (adresse e-mail, URL, adresse IP, caractères interdits). Les noms internationalisés sont
     * attendus sous leur forme « xn-- ».
     */
    public static function normalizeDomain(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = rtrim($value, '.');
        if ($value === '' || strlen($value) > 253) {
            return null;
        }
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        return preg_match('/^(?:' . $label . '\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * Sélecteurs DKIM saisis (séparés par des virgules, des espaces ou des retours à la ligne),
     * dédoublonnés ; les valeurs invalides sont ignorées.
     *
     * @return list<string>
     */
    public static function normalizeSelectors(string $value): array
    {
        $selectors = [];
        foreach (preg_split('/[\s,;]+/', strtolower($value)) ?: [] as $selector) {
            if (
                $selector !== ''
                && preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62})$/', $selector) === 1
                && !str_contains($selector, '..')
                && !in_array($selector, $selectors, true)
            ) {
                $selectors[] = $selector;
            }
            if (count($selectors) >= self::MAX_SELECTORS) {
                break;
            }
        }

        return $selectors;
    }

    /**
     * Date « AAAA-MM-JJ » valide, ou null.
     */
    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * Étiquettes d'un enregistrement « v=XXX; a=b; c=d ».
     *
     * @return array<string, string>
     */
    public static function tags(string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $key = strtolower(trim($pair[0]));
                if ($key !== '' && !array_key_exists($key, $tags)) {
                    $tags[$key] = trim($pair[1]);
                }
            }
        }

        return $tags;
    }

    /**
     * Enregistrements TXT qui commencent par une version donnée (« v=spf1 », « v=DMARC1 »...).
     *
     * @param list<string> $txts
     * @return list<string>
     */
    private static function withVersion(array $txts, string $version): array
    {
        return array_values(array_filter(
            $txts,
            static fn (string $txt): bool => preg_match(
                '/^\s*v\s*=\s*' . preg_quote($version, '/') . '(?:\s|;|$)/i',
                $txt
            ) === 1
        ));
    }

    /**
     * SPF du domaine.
     *
     * @param list<string> $txts enregistrements TXT du domaine
     * @return array{status: string, record: string, all: string, lookups: int}
     *     status : missing, multiple, ok ; all : -all, ~all, ?all, +all, redirect, none
     */
    public static function spf(array $txts): array
    {
        $records = array_values(array_filter(
            $txts,
            static fn (string $txt): bool => preg_match('/^\s*v=spf1(?:\s|$)/i', $txt) === 1
        ));
        if ($records === []) {
            return ['status' => 'missing', 'record' => '', 'all' => 'none', 'lookups' => 0];
        }
        $record = trim($records[0]);
        $all = 'none';
        $lookups = 0;
        foreach (preg_split('/\s+/', strtolower($record)) ?: [] as $term) {
            if (preg_match('/^([+?~-]?)all$/', $term, $m) === 1) {
                $all = ($m[1] === '' ? '+' : $m[1]) . 'all';
            } elseif (preg_match('/^[+?~-]?(include|a|mx|ptr|exists)(?:[:\/]|$)/', $term) === 1) {
                $lookups++;
            } elseif (str_starts_with($term, 'redirect=')) {
                $lookups++;
                if ($all === 'none') {
                    $all = 'redirect';
                }
            }
        }

        return [
            'status'  => count($records) > 1 ? 'multiple' : 'ok',
            'record'  => $record,
            'all'     => $all,
            'lookups' => $lookups,
        ];
    }

    /**
     * Politique DMARC (enregistrement TXT de « _dmarc.<domaine> »).
     *
     * @param list<string> $txts
     * @return array{status: string, record: string, policy: string, subdomain_policy: string, pct: int,
     *     reports: bool}
     *     status : missing, multiple, invalid, ok ; policy : none, quarantine, reject ou ''
     */
    public static function dmarc(array $txts): array
    {
        $records = self::withVersion($txts, 'DMARC1');
        $empty = ['record' => '', 'policy' => '', 'subdomain_policy' => '', 'pct' => 100, 'reports' => false];
        if ($records === []) {
            return ['status' => 'missing'] + $empty;
        }
        $record = trim($records[0]);
        $tags = self::tags($record);
        $policy = strtolower($tags['p'] ?? '');
        $valid = ['none', 'quarantine', 'reject'];
        $subdomain = strtolower($tags['sp'] ?? '');
        $pct = isset($tags['pct']) && ctype_digit($tags['pct']) ? min(100, (int) $tags['pct']) : 100;
        $status = count($records) > 1 ? 'multiple' : (in_array($policy, $valid, true) ? 'ok' : 'invalid');

        return [
            'status'           => $status,
            'record'           => $record,
            'policy'           => in_array($policy, $valid, true) ? $policy : '',
            'subdomain_policy' => in_array($subdomain, $valid, true) ? $subdomain : '',
            'pct'              => $pct,
            'reports'          => trim($tags['rua'] ?? '') !== '',
        ];
    }

    /**
     * Clé DKIM publiée pour un sélecteur (enregistrement TXT de « <sélecteur>._domainkey.<domaine> »).
     *
     * @param list<string> $txts
     * @return string missing, revoked (clé vide) ou ok
     */
    public static function dkim(array $txts): string
    {
        foreach ($txts as $txt) {
            $tags = self::tags($txt);
            if (preg_match('/^\s*v\s*=\s*DKIM1/i', $txt) === 1 || array_key_exists('p', $tags)) {
                return trim($tags['p'] ?? '') === '' ? 'revoked' : 'ok';
            }
        }

        return 'missing';
    }

    /**
     * Annonce MTA-STS (« _mta-sts.<domaine> », v=STSv1) ou TLS-RPT (« _smtp._tls.<domaine> », v=TLSRPTv1).
     *
     * @param list<string> $txts
     */
    public static function announced(array $txts, string $version): bool
    {
        return self::withVersion($txts, $version) !== [];
    }

    /**
     * Échéance d'un domaine ou d'un certificat par rapport à aujourd'hui.
     *
     * @return array{status: string, days: ?int}
     */
    public static function expiry(?string $date, string $today, int $alertDays = self::ALERT_DAYS): array
    {
        $date = $date === null ? null : self::normalizeDate(substr($date, 0, 10));
        $today = self::normalizeDate(substr($today, 0, 10));
        if ($date === null || $today === null) {
            return ['status' => self::EXPIRY_NONE, 'days' => null];
        }
        $days = (int) round((strtotime($date . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400);

        return [
            'status' => match (true) {
                $days < 0           => self::EXPIRY_EXPIRED,
                $days <= $alertDays => self::EXPIRY_SOON,
                default             => self::EXPIRY_OK,
            },
            'days'   => $days,
        ];
    }

    /**
     * Résultat d'une lecture DNS, tel qu'il est enregistré : rien d'autre que des enregistrements publics.
     *
     * @param array{resolved: bool, mx: list<string>, txt: list<string>, dmarc: list<string>,
     *     mta_sts: list<string>, tls_rpt: list<string>, dkim: array<string, list<string>>, dnssec: ?bool} $dns
     * @return array<string, mixed>
     */
    public static function analyse(array $dns): array
    {
        $dkim = [];
        foreach ($dns['dkim'] as $selector => $txts) {
            $dkim[(string) $selector] = self::dkim($txts);
        }

        return [
            'resolved' => $dns['resolved'],
            'mx'       => array_slice($dns['mx'], 0, 10),
            'spf'      => self::spf($dns['txt']),
            'dmarc'    => self::dmarc($dns['dmarc']),
            'dkim'     => $dkim,
            'mta_sts'  => self::announced($dns['mta_sts'], 'STSv1'),
            'tls_rpt'  => self::announced($dns['tls_rpt'], 'TLSRPTv1'),
            'dnssec'   => $dns['dnssec'],
        ];
    }

    /**
     * Écarts d'un domaine : résultat de la dernière lecture DNS (null si jamais lu) et échéances saisies.
     *
     * @param array<string, mixed>|null $result
     * @return list<array{code: string, severity: string}>
     */
    public static function findings(?array $result, ?string $domainExpiry, ?string $certExpiry, string $today): array
    {
        $codes = [];
        if ($result !== null) {
            $codes = array_merge($codes, self::dnsFindings($result));
        }
        $domain = self::expiry($domainExpiry, $today)['status'];
        $codes[] = match ($domain) {
            self::EXPIRY_EXPIRED => 'domain_expired',
            self::EXPIRY_SOON    => 'domain_expiring',
            self::EXPIRY_NONE    => 'domain_expiry_unknown',
            default              => '',
        };
        $cert = self::expiry($certExpiry, $today)['status'];
        $codes[] = match ($cert) {
            self::EXPIRY_EXPIRED => 'cert_expired',
            self::EXPIRY_SOON    => 'cert_expiring',
            default              => '',
        };
        $findings = [];
        foreach (array_keys(self::FINDINGS) as $code) {
            if (in_array($code, $codes, true)) {
                $findings[] = ['code' => $code, 'severity' => self::FINDINGS[$code]];
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private static function dnsFindings(array $result): array
    {
        if (empty($result['resolved'])) {
            return ['unresolved'];
        }
        $codes = [];
        $spf = is_array($result['spf'] ?? null) ? $result['spf'] : ['status' => 'missing'];
        $codes[] = match ($spf['status'] ?? 'missing') {
            'missing'  => 'spf_missing',
            'multiple' => 'spf_multiple',
            default    => '',
        };
        if (($spf['status'] ?? '') === 'ok') {
            $codes[] = match ($spf['all'] ?? 'none') {
                '+all'         => 'spf_pass_all',
                '?all', 'none' => 'spf_neutral',
                '~all'         => 'spf_softfail',
                default        => '',
            };
            if ((int) ($spf['lookups'] ?? 0) > self::SPF_MAX_LOOKUPS) {
                $codes[] = 'spf_too_many_lookups';
            }
        }
        $dmarc = is_array($result['dmarc'] ?? null) ? $result['dmarc'] : ['status' => 'missing'];
        $codes[] = match ($dmarc['status'] ?? 'missing') {
            'missing'             => 'dmarc_missing',
            'multiple', 'invalid' => 'dmarc_invalid',
            default               => '',
        };
        if (($dmarc['status'] ?? '') === 'ok') {
            if (($dmarc['policy'] ?? '') === 'none') {
                $codes[] = 'dmarc_none';
            } elseif ((int) ($dmarc['pct'] ?? 100) < 100) {
                $codes[] = 'dmarc_partial';
            }
            if (empty($dmarc['reports'])) {
                $codes[] = 'dmarc_no_reports';
            }
        }
        $dkim = is_array($result['dkim'] ?? null) ? $result['dkim'] : [];
        if ($dkim === []) {
            $codes[] = 'dkim_not_declared';
        } else {
            if (in_array('missing', $dkim, true)) {
                $codes[] = 'dkim_missing';
            }
            if (in_array('revoked', $dkim, true)) {
                $codes[] = 'dkim_revoked';
            }
        }
        $codes[] = match ($result['dnssec'] ?? null) {
            false   => 'dnssec_missing',
            null    => 'dnssec_unknown',
            default => '',
        };
        // MTA-STS et TLS-RPT ne concernent qu'un domaine qui reçoit du courrier (serveurs MX).
        if (!empty($result['mx'])) {
            if (empty($result['mta_sts'])) {
                $codes[] = 'mta_sts_missing';
            }
            if (empty($result['tls_rpt'])) {
                $codes[] = 'tls_rpt_missing';
            }
        }

        return array_values(array_filter($codes, static fn (string $code): bool => $code !== ''));
    }

    /**
     * Gravité la plus forte d'une liste d'écarts, « success » s'il n'y en a aucun.
     *
     * @param list<array{code: string, severity: string}> $findings
     */
    public static function worst(array $findings): string
    {
        $severities = array_column($findings, 'severity');
        foreach (self::SEVERITIES as $severity) {
            if (in_array($severity, $severities, true)) {
                return $severity;
            }
        }

        return 'success';
    }

    /**
     * DMARC appliqué : politique « quarantine » ou « reject ».
     *
     * @param array<string, mixed>|null $result
     */
    public static function dmarcEnforced(?array $result): bool
    {
        $dmarc = is_array($result['dmarc'] ?? null) ? $result['dmarc'] : [];

        return ($dmarc['status'] ?? '') === 'ok' && in_array($dmarc['policy'] ?? '', ['quarantine', 'reject'], true);
    }
}
