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

namespace GlpiPlugin\Grcmanager\Services\Access;

use GlpiPlugin\Grcmanager\Services\Incident\EdrCsvImport;

/**
 * Issue #852 : couverture de l'authentification multifacteur (MFA) par compte, sans dépendance à
 * GLPI (testée unitairement). Classe les méthodes déclarées par les fournisseurs (Entra ID, Okta,
 * Google Workspace, solution sur site), lit un export CSV générique et calcule les taux et les
 * écarts. Elle signale ; elle ne modifie jamais un compte.
 */
final class MfaCoverageRules
{
    /** Niveau de protection d'un compte. */
    public const LEVEL_STRONG = 'strong';
    public const LEVEL_WEAK = 'weak';
    public const LEVEL_NONE = 'none';

    /** Classe d'une méthode. */
    public const METHOD_STRONG = 'strong';
    public const METHOD_WEAK = 'weak';
    public const METHOD_IGNORED = 'ignored';

    /** Écarts signalés. */
    public const GAP_ADMIN_NO_MFA = 'admin_no_mfa';
    public const GAP_REMOTE_NO_MFA = 'remote_no_mfa';
    public const GAP_WEAK_ONLY = 'weak_only';
    public const GAP_EXCLUDED_NO_REASON = 'excluded_no_reason';

    /** Fournisseurs proposés à l'import. */
    public const PROVIDERS = ['entra', 'okta', 'google', 'onpremise', 'other'];

    /** Méthode native de GLPI (application d'authentification, TOTP). */
    public const GLPI_TOTP = 'glpi_totp';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Colonnes reconnues => en-têtes usuels normalisés (minuscules, sans séparateurs), dont ceux du
     * rapport « détails d'inscription des utilisateurs » d'Entra ID, d'Okta et de Google Workspace.
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'account'  => [
            'login', 'account', 'username', 'userprincipalname', 'upn', 'user', 'primaryemail', 'samaccountname',
            'compte', 'identifiant',
        ],
        'email'    => ['email', 'mail', 'emailaddress', 'courriel'],
        'methods'  => [
            'methodsregistered', 'methods', 'authenticationmethods', 'factors', 'enrolledfactors', 'methodes',
            'mfamethods',
        ],
        'mfa'      => ['ismfaregistered', 'mfaregistered', 'mfa', 'isenrolledin2sv', '2svenrolled', 'mfaenabled'],
        'admin'    => ['isadmin', 'admin', 'administrator', 'administrateur', 'privileged'],
        'remote'   => ['remoteaccess', 'remote', 'vpn', 'accesdistant'],
        'excluded' => ['excluded', 'isexcluded', 'serviceaccount', 'exclu', 'comptedeservice'],
        'reason'   => ['exclusionreason', 'justification', 'reason', 'motif'],
    ];

    /** Méthodes faibles (code à usage unique transmis par le réseau téléphonique ou la messagerie). */
    private const WEAK_PATTERNS = '/sms|mobilephone|officephone|alternatemobilephone|phone|voice|call|appel'
        . '|telephone|email|mail|securityquestion|question/';

    /** Mots-clés ignorés : ce ne sont pas des seconds facteurs. */
    private const IGNORED = ['password', 'motdepasse', 'none', 'aucun', 'aucune', 'no', 'non', 'false', '0', ''];

    public static function normalizeProvider(mixed $provider): string
    {
        $provider = (string) $provider;

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'other';
    }

    /**
     * Classe d'une méthode telle que l'écrit le fournisseur (« microsoftAuthenticatorPush », « sms »,
     * « fido2 », « Okta Verify »…). Une méthode inconnue compte comme second facteur fort : le plugin
     * ne rabaisse pas ce qu'il ne connaît pas, il signale seulement les méthodes faibles connues.
     */
    public static function methodClass(string $method): string
    {
        $key = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($method)));
        if (in_array($key, self::IGNORED, true)) {
            return self::METHOD_IGNORED;
        }
        // Les applications d'authentification par notification ou code (Authenticator, Okta Verify,
        // « Google prompt ») sont fortes même si leur nom contient « phone ».
        if (
            preg_match('/authenticator|totp|otp|fido|webauthn|passkey|hello|securitykey|yubikey|u2f|certificate'
            . '|smartcard|verify|push|prompt|hardwaretoken|token|app|secureenclave|platformcredential|glpi/', $key)
        ) {
            return self::METHOD_STRONG;
        }
        if (preg_match(self::WEAK_PATTERNS, $key)) {
            return self::METHOD_WEAK;
        }

        return self::METHOD_STRONG;
    }

    /**
     * Méthodes d'une cellule (séparateurs usuels : virgule, point-virgule, barre verticale), en codes
     * normalisés, sans doublon. Les crochets et guillemets des exports JSON aplatis sont retirés.
     *
     * @return list<string>
     */
    public static function splitMethods(string $cell): array
    {
        $cell = str_replace(['[', ']', '"', "'"], '', $cell);
        $methods = [];
        foreach (preg_split('/[,;|]+/', $cell) ?: [] as $method) {
            $method = trim($method);
            if ($method === '' || self::methodClass($method) === self::METHOD_IGNORED) {
                continue;
            }
            $methods[mb_strtolower($method)] ??= $method;
        }

        return array_values($methods);
    }

    /**
     * Valeur oui/non d'une cellule ; null si vide ou illisible.
     */
    public static function bool(string $cell): ?bool
    {
        $value = mb_strtolower(trim($cell));
        if (in_array($value, ['1', 'true', 'yes', 'y', 'oui', 'o', 'x', 'vrai', 'enabled', 'enrolled'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'n', 'non', 'faux', 'disabled', 'notenrolled'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Niveau de protection d'un compte d'après toutes ses méthodes (toutes sources confondues).
     *
     * @param list<string> $methods
     */
    public static function level(array $methods, bool $registered = false): string
    {
        $hasWeak = false;
        foreach ($methods as $method) {
            $class = self::methodClass($method);
            if ($class === self::METHOD_STRONG) {
                return self::LEVEL_STRONG;
            }
            $hasWeak = $hasWeak || $class === self::METHOD_WEAK;
        }
        if ($hasWeak) {
            return self::LEVEL_WEAK;
        }

        // Fournisseur qui indique « MFA inscrit » sans détailler la méthode.
        return $registered ? self::LEVEL_STRONG : self::LEVEL_NONE;
    }

    /**
     * Écarts d'un compte.
     *
     * @param array{level: string, is_admin: bool, is_remote: bool, is_excluded: bool, reason: string} $account
     * @return list<string>
     */
    public static function gaps(array $account): array
    {
        $excludedWithReason = $account['is_excluded'] && trim($account['reason']) !== '';
        if ($account['is_excluded'] && !$excludedWithReason) {
            return [self::GAP_EXCLUDED_NO_REASON];
        }
        if ($excludedWithReason) {
            return [];
        }
        $gaps = [];
        if ($account['level'] === self::LEVEL_NONE) {
            if ($account['is_admin']) {
                $gaps[] = self::GAP_ADMIN_NO_MFA;
            }
            if ($account['is_remote']) {
                $gaps[] = self::GAP_REMOTE_NO_MFA;
            }
        }
        if ($account['level'] === self::LEVEL_WEAK) {
            $gaps[] = self::GAP_WEAK_ONLY;
        }

        return $gaps;
    }

    /**
     * Taux de MFA d'un ensemble de comptes. Les comptes exclus avec une justification sortent du
     * calcul ; un compte avec une méthode faible seule compte comme protégé mais reste un écart.
     *
     * @param list<array{level: string, is_excluded: bool, reason: string}> $accounts
     * @return array{total: int, protected: int, strong: int, weak: int, none: int, excluded: int, rate: ?int}
     */
    public static function summary(array $accounts): array
    {
        $out = ['total' => 0, 'protected' => 0, 'strong' => 0, 'weak' => 0, 'none' => 0, 'excluded' => 0];
        foreach ($accounts as $account) {
            if ($account['is_excluded'] && trim($account['reason']) !== '') {
                $out['excluded']++;
                continue;
            }
            $out['total']++;
            $level = $account['level'];
            $out[$level === self::LEVEL_STRONG ? 'strong' : ($level === self::LEVEL_WEAK ? 'weak' : 'none')]++;
            if ($level !== self::LEVEL_NONE) {
                $out['protected']++;
            }
        }
        $out['rate'] = $out['total'] > 0 ? (int) floor(100 * $out['protected'] / $out['total']) : null;

        return $out;
    }

    /**
     * Lit un export CSV (en-tête obligatoire, séparateur et encodage détectés) en lignes de comptes.
     *
     * @return array{rows: list<array{account: string, email: string, methods: list<string>, mfa: ?bool,
     *     admin: ?bool, remote: ?bool, excluded: bool, reason: string}>, errors: list<string>, truncated: bool}
     */
    public static function parseCsv(string $raw): array
    {
        $parsed = EdrCsvImport::parse($raw);
        $mapping = [];
        foreach (self::FIELDS as $field => $aliases) {
            foreach ($parsed['headers'] as $index => $header) {
                $normalized = EdrCsvImport::normalizeHeader($header);
                if (!in_array($index, $mapping, true) && in_array($normalized, $aliases, true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }
        if (!isset($mapping['account']) && !isset($mapping['email'])) {
            return ['rows' => [], 'errors' => ['missing_account'], 'truncated' => false];
        }
        if (!isset($mapping['methods']) && !isset($mapping['mfa'])) {
            return ['rows' => [], 'errors' => ['missing_methods'], 'truncated' => false];
        }
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $account = mb_substr($get($row, 'account'), 0, 255);
            $email = mb_substr($get($row, 'email'), 0, 255);
            if ($account === '' && $email === '') {
                continue;
            }
            if ($account === '') {
                $account = $email;
            }
            $rows[] = [
                'account'  => $account,
                'email'    => $email !== '' ? $email : (str_contains($account, '@') ? $account : ''),
                'methods'  => self::splitMethods($get($row, 'methods')),
                'mfa'      => self::bool($get($row, 'mfa')),
                'admin'    => self::bool($get($row, 'admin')),
                'remote'   => self::bool($get($row, 'remote')),
                'excluded' => self::bool($get($row, 'excluded')) === true,
                'reason'   => mb_substr($get($row, 'reason'), 0, 1000),
            ];
        }

        return ['rows' => $rows, 'errors' => [], 'truncated' => $parsed['truncated']];
    }

    /**
     * Rapprochement d'une ligne importée avec un compte GLPI : identifiant exact, puis adresse e-mail,
     * puis partie locale d'un UPN (« prenom.nom@exemple.test » donne « prenom.nom »). Insensible à la
     * casse. 0 si rien ne correspond.
     *
     * @param array<string, int> $byLogin identifiant en minuscules => id
     * @param array<string, int> $byEmail adresse en minuscules => id
     */
    public static function match(string $account, string $email, array $byLogin, array $byEmail): int
    {
        $account = mb_strtolower(trim($account));
        $email = mb_strtolower(trim($email));
        if ($account !== '' && isset($byLogin[$account])) {
            return $byLogin[$account];
        }
        foreach ([$email, $account] as $address) {
            if ($address !== '' && isset($byEmail[$address])) {
                return $byEmail[$address];
            }
        }
        if (str_contains($account, '@')) {
            $local = strstr($account, '@', true);
            if ($local !== false && $local !== '' && isset($byLogin[$local])) {
                return $byLogin[$local];
            }
        }

        return 0;
    }
}
