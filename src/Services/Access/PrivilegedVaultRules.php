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
 * Issue #859 : règles du coffre-fort de mots de passe et des comptes à privilèges (A.5.17, A.8.2),
 * sans base de données ni session (testées seules).
 *
 * - Import CSV de métadonnées seulement. Un fichier dont un en-tête peut porter un secret (mot de
 *   passe, code TOTP, notes, champs personnalisés, clé privée) est refusé en entier, avant de lire
 *   la moindre ligne : rien n'est importé.
 * - Éléments du coffre : nom, collection, identifiant de connexion, adresse, date du dernier
 *   changement du mot de passe, personnes qui y ont accès.
 * - Membres du coffre : adresse, rôle, statut, collections, double authentification.
 * - Écarts signalés (jamais corrigés) : secret non renouvelé depuis N mois ou de date inconnue,
 *   accès d'une personne partie, membre inconnu de GLPI, administrateur du coffre sans double
 *   authentification, compte à privilèges d'un équipement sans élément dans le coffre.
 *
 * Valeurs de rôle et de statut : modèles de l'API publique de Bitwarden (dépôt bitwarden/server,
 * src/Core/AdminConsole/Enums/OrganizationUserType.cs et OrganizationUserStatusType.cs).
 */
final class PrivilegedVaultRules
{
    public const KIND_ENTRIES = 'entries';
    public const KIND_MEMBERS = 'members';

    /** Sources proposées à l'import (Vaultwarden passe par l'interface en ligne de commande « bw »). */
    public const SOURCES = ['bitwarden', 'pam', 'other'];

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';
    public const ROLE_CUSTOM = 'custom';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INVITED = 'invited';
    public const STATUS_REVOKED = 'revoked';

    /** Écarts d'un élément du coffre. */
    public const GAP_STALE = 'stale';
    public const GAP_NO_DATE = 'no_date';
    public const GAP_DEPARTED_HOLDER = 'departed_holder';

    /** Écarts d'un membre du coffre. */
    public const GAP_DEPARTED = 'departed';
    public const GAP_UNKNOWN = 'unknown';
    public const GAP_ADMIN_NO_2FA = 'admin_no_2fa';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Délai de renouvellement attendu (mois) : par défaut, bornes. */
    public const DEFAULT_ROTATION_MONTHS = 12;
    public const MIN_ROTATION_MONTHS = 1;
    public const MAX_ROTATION_MONTHS = 60;

    /**
     * Colonnes reconnues des éléments => en-têtes normalisés (minuscules, sans séparateurs), par
     * ordre de préférence. « date » : date du dernier changement du mot de passe de préférence
     * (passwordRevisionDate de Bitwarden), sinon de l'élément.
     *
     * @var array<string, list<string>>
     */
    public const ENTRY_FIELDS = [
        'external_id' => ['id', 'itemid', 'externalid', 'identifiant'],
        'name'        => ['name', 'item', 'itemname', 'account', 'accountname', 'nom', 'compte'],
        'collection'  => [
            'collectionids', 'collections', 'collection', 'collectionid', 'safe', 'vault', 'folder', 'coffre',
        ],
        'username'    => ['loginusername', 'username', 'login', 'user', 'utilisateur'],
        'uri'         => ['loginuri', 'uri', 'url', 'address', 'host', 'hostname', 'adresse'],
        'date'        => [
            'passwordrevisiondate', 'passwordchanged', 'passwordlastchanged', 'lastpasswordchange',
            'lastrotation', 'rotationdate', 'lastchanged', 'datechanged', 'revisiondate', 'lastmodified',
            'modified', 'datemodification', 'dernierchangement',
        ],
        'holders'     => ['holders', 'access', 'members', 'users', 'acces', 'membres'],
    ];

    /** @var array<string, list<string>> */
    public const MEMBER_FIELDS = [
        'external_id' => ['id', 'memberid', 'externalid'],
        'email'       => ['email', 'mail', 'emailaddress', 'courriel'],
        'name'        => ['name', 'fullname', 'nom'],
        'role'        => ['type', 'role', 'usertype'],
        'status'      => ['status', 'statut', 'state'],
        'collections' => ['collections', 'collectionids', 'collection', 'safes'],
        'two_factor'  => ['twofactorenabled', 'twofactor', '2fa', 'mfa', 'twostep'],
    ];

    /** En-têtes refusés tels quels (secrets, ou texte libre où l'on range souvent un secret). */
    private const SECRET_HEADERS = [
        'notes', 'note', 'fields', 'customfields', 'privatekey', 'sshkey', 'passphrase', 'pin', 'cvv', 'code',
        'cardnumber', 'number',
    ];

    /** Mots qui désignent un secret dans un en-tête... */
    private const SECRET_WORDS = '/password|passwd|motdepasse|secret|totp|otp|token|apikey|privatekey/';

    /** ...sauf s'il s'agit d'une date ou d'un âge (passwordRevisionDate, password_age). */
    private const METADATA_WORDS = '/date|change|age$|rotation|revision|modified|expir/';

    /**
     * En-tête qui peut porter un secret : le fichier entier est refusé.
     */
    public static function isSecretHeader(string $header): bool
    {
        $normalized = EdrCsvImport::normalizeHeader($header);
        if ($normalized === '') {
            return false;
        }
        if (in_array($normalized, self::SECRET_HEADERS, true)) {
            return true;
        }

        return preg_match(self::SECRET_WORDS, $normalized) === 1
            && preg_match(self::METADATA_WORDS, $normalized) !== 1;
    }

    public static function normalizeSource(mixed $source): string
    {
        $source = is_string($source) ? strtolower(trim($source)) : '';

        return in_array($source, self::SOURCES, true) ? $source : 'other';
    }

    public static function normalizeKind(mixed $kind): string
    {
        return $kind === self::KIND_MEMBERS ? self::KIND_MEMBERS : self::KIND_ENTRIES;
    }

    public static function normalizeRotationMonths(mixed $months): int
    {
        $months = is_numeric($months) ? (int) $months : self::DEFAULT_ROTATION_MONTHS;

        return max(self::MIN_ROTATION_MONTHS, min(self::MAX_ROTATION_MONTHS, $months));
    }

    /**
     * Rôle d'un membre : valeurs de l'API publique de Bitwarden (OrganizationUserType : 0 Owner,
     * 1 Admin, 2 User, 4 Custom ; 3 Manager, retiré, compté comme personnalisé) ou libellés.
     */
    public static function normalizeRole(mixed $role): string
    {
        $value = is_scalar($role) ? mb_strtolower(trim((string) $role)) : '';

        return match ($value) {
            '0', 'owner', 'proprietaire', 'propriétaire'                   => self::ROLE_OWNER,
            '1', 'admin', 'administrator', 'administrateur'                => self::ROLE_ADMIN,
            '3', '4', 'custom', 'manager', 'personnalise', 'personnalisé'  => self::ROLE_CUSTOM,
            default                                                        => self::ROLE_USER,
        };
    }

    /**
     * Statut d'un membre : OrganizationUserStatusType de Bitwarden (0 Invited, 1 Accepted,
     * 2 Confirmed, 3 Staged, -1 Revoked) ou libellés.
     */
    public static function normalizeStatus(mixed $status): string
    {
        $value = is_scalar($status) ? mb_strtolower(trim((string) $status)) : '';

        return match ($value) {
            '-1', 'revoked', 'revoque', 'révoqué', 'disabled', 'desactive', 'désactivé' => self::STATUS_REVOKED,
            '0', '3', 'invited', 'staged', 'invite', 'invité'                         => self::STATUS_INVITED,
            default                                                                    => self::STATUS_ACTIVE,
        };
    }

    public static function isAdminRole(string $role): bool
    {
        return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    /**
     * Oui, non ou inconnu (null) : true/false, 1/0, yes/no, oui/non.
     */
    public static function bool(mixed $cell): ?bool
    {
        if (is_bool($cell)) {
            return $cell;
        }
        $value = is_scalar($cell) ? mb_strtolower(trim((string) $cell)) : '';

        return match ($value) {
            '1', 'true', 'yes', 'oui', 'y', 'o', 'enabled', 'on' => true,
            '0', 'false', 'no', 'non', 'n', 'disabled', 'off'    => false,
            default                                              => null,
        };
    }

    /**
     * Liste d'un champ (collections, personnes) : séparée par des virgules, des points-virgules, des
     * barres ou des retours à la ligne ; sans doublon (casse ignorée).
     *
     * @return list<string>
     */
    public static function splitList(string $cell): array
    {
        $values = [];
        foreach (preg_split('/[,;\r\n|]+/', $cell) ?: [] as $value) {
            $value = trim($value, " \t\"'[]");
            if ($value !== '') {
                $values[mb_strtolower($value)] ??= mb_substr($value, 0, 255);
            }
        }

        return array_values($values);
    }

    /**
     * Date et heure (UTC) au format de la base, ou null : ISO 8601 (Bitwarden), AAAA-MM-JJ,
     * JJ/MM/AAAA.
     */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?$#', $value, $m) === 1) {
            $value = $m[3] . '-' . $m[2] . '-' . $m[1] . (isset($m[4]) ? ' ' . $m[4] : '');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        $year = (int) $date->format('Y');
        if ($year < 1990 || $year > 2100) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Correspondance champ => index de colonne, par ordre de préférence des en-têtes connus.
     *
     * @param list<string> $headers
     * @param array<string, list<string>> $fields
     * @return array<string, int>
     */
    public static function mapping(array $headers, array $fields): array
    {
        $normalized = array_map([EdrCsvImport::class, 'normalizeHeader'], $headers);
        $mapping = [];
        foreach ($fields as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalized, true);
                if ($index !== false && !in_array((int) $index, $mapping, true)) {
                    $mapping[$field] = (int) $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Lit un export CSV d'éléments ou de membres. Erreurs : secret_column (« secret » : en-tête en
     * cause), missing_name (éléments sans colonne de nom), missing_email (membres sans adresse).
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, secret: string, truncated: bool}
     */
    public static function parseCsv(string $kind, string $raw): array
    {
        $out = ['rows' => [], 'errors' => [], 'secret' => '', 'truncated' => false];
        $parsed = EdrCsvImport::parse($raw);
        foreach ($parsed['headers'] as $header) {
            if (self::isSecretHeader($header)) {
                // Rien n'est gardé du fichier : seulement le nom de la colonne refusée.
                return ['errors' => ['secret_column'], 'secret' => mb_substr($header, 0, 64)] + $out;
            }
        }
        $kind = self::normalizeKind($kind);
        $mapping = self::mapping(
            $parsed['headers'],
            $kind === self::KIND_MEMBERS ? self::MEMBER_FIELDS : self::ENTRY_FIELDS
        );
        if ($kind === self::KIND_ENTRIES && !isset($mapping['name'])) {
            return ['errors' => ['missing_name']] + $out;
        }
        if ($kind === self::KIND_MEMBERS && !isset($mapping['email'])) {
            return ['errors' => ['missing_email']] + $out;
        }
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        foreach ($parsed['rows'] as $row) {
            $parsedRow = $kind === self::KIND_MEMBERS
                ? self::memberRow(
                    $get($row, 'external_id'),
                    $get($row, 'email'),
                    $get($row, 'name'),
                    $get($row, 'role'),
                    $get($row, 'status'),
                    self::splitList($get($row, 'collections')),
                    self::bool($get($row, 'two_factor'))
                )
                : self::entryRow(
                    $get($row, 'external_id'),
                    $get($row, 'name'),
                    self::splitList($get($row, 'collection')),
                    $get($row, 'username'),
                    $get($row, 'uri'),
                    self::parseDate($get($row, 'date')),
                    self::splitList($get($row, 'holders'))
                );
            if ($parsedRow !== null) {
                $out['rows'][] = $parsedRow;
            }
        }
        $out['truncated'] = $parsed['truncated'];

        return $out;
    }

    /**
     * @param list<string> $collections
     * @param list<string> $holders
     * @return array{external_id: string, name: string, collections: list<string>, username: string,
     *     uri: string, date_changed: ?string, holders: list<string>}|null
     */
    public static function entryRow(
        string $externalId,
        string $name,
        array $collections,
        string $username,
        string $uri,
        ?string $dateChanged,
        array $holders
    ): ?array {
        $name = mb_substr(trim($name), 0, 255);
        if ($name === '') {
            return null;
        }

        return [
            'external_id'  => mb_substr(trim($externalId), 0, 255),
            'name'         => $name,
            'collections'  => $collections,
            'username'     => mb_substr(trim($username), 0, 255),
            'uri'          => self::cleanUri($uri),
            'date_changed' => $dateChanged,
            'holders'      => array_values(array_filter(
                array_map('mb_strtolower', $holders),
                static fn (string $holder): bool => $holder !== ''
            )),
        ];
    }

    /**
     * @param list<string> $collections
     * @return array{external_id: string, email: string, name: string, role: string, status: string,
     *     collections: list<string>, two_factor: ?bool}|null
     */
    public static function memberRow(
        string $externalId,
        string $email,
        string $name,
        mixed $role,
        mixed $status,
        array $collections,
        ?bool $twoFactor
    ): ?array {
        $email = mb_strtolower(mb_substr(trim($email), 0, 255));
        if ($email === '') {
            return null;
        }

        return [
            'external_id' => mb_substr(trim($externalId), 0, 255),
            'email'       => $email,
            'name'        => mb_substr(trim($name), 0, 255),
            'role'        => self::normalizeRole($role),
            'status'      => self::normalizeStatus($status),
            'collections' => $collections,
            'two_factor'  => $twoFactor,
        ];
    }

    /**
     * Adresse d'un élément sans identifiants, requête ni fragment (une adresse peut porter un jeton).
     */
    public static function cleanUri(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }
        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return '';
        }
        if (!isset($parts['host'])) {
            // Nom d'hôte ou adresse IP seule (« srv-db01 », « 10.0.0.5:22 »).
            return preg_match('/^[A-Za-z0-9.\-_:\[\]]{1,255}$/', $uri) === 1 ? $uri : '';
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) . '://' : '';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return mb_substr($scheme . (string) $parts['host'] . $port . (string) ($parts['path'] ?? ''), 0, 255);
    }

    /**
     * Écarts d'un élément du coffre.
     *
     * @param list<string> $holders adresses des personnes qui y ont accès (minuscules)
     * @param array<string, true> $departed adresses des personnes parties (minuscules)
     * @return list<string>
     */
    public static function entryGaps(
        ?string $dateChanged,
        int $rotationMonths,
        string $now,
        array $holders,
        array $departed
    ): array {
        $gaps = [];
        if ($dateChanged === null || $dateChanged === '') {
            $gaps[] = self::GAP_NO_DATE;
        } elseif (self::isStale($dateChanged, $rotationMonths, $now)) {
            $gaps[] = self::GAP_STALE;
        }
        foreach ($holders as $holder) {
            if (isset($departed[$holder])) {
                $gaps[] = self::GAP_DEPARTED_HOLDER;
                break;
            }
        }

        return $gaps;
    }

    /**
     * Secret non renouvelé depuis plus de N mois.
     */
    public static function isStale(string $dateChanged, int $rotationMonths, string $now): bool
    {
        $months = self::normalizeRotationMonths($rotationMonths);
        $limit = (new \DateTimeImmutable($now))->modify('-' . $months . ' months');

        return new \DateTimeImmutable($dateChanged) < $limit;
    }

    /**
     * Écarts d'un membre du coffre. Un membre révoqué n'a plus accès : aucun écart.
     *
     * @param array{matched: bool, departed: bool} $glpi compte GLPI rapproché, et parti (désactivé,
     *     supprimé ou fiche de départ passée)
     * @return list<string>
     */
    public static function memberGaps(string $role, string $status, ?bool $twoFactor, array $glpi): array
    {
        if ($status === self::STATUS_REVOKED) {
            return [];
        }
        $gaps = [];
        if ($glpi['departed']) {
            $gaps[] = self::GAP_DEPARTED;
        } elseif (!$glpi['matched']) {
            $gaps[] = self::GAP_UNKNOWN;
        }
        if (self::isAdminRole($role) && $twoFactor === false && $status === self::STATUS_ACTIVE) {
            $gaps[] = self::GAP_ADMIN_NO_2FA;
        }

        return $gaps;
    }

    /**
     * Personnes qui ont accès à un élément : celles citées par l'élément, et les membres actifs ou
     * invités d'une de ses collections.
     *
     * @param list<string> $holders
     * @param list<string> $collections
     * @param list<array{email: string, status: string, collections: list<string>}> $members
     * @return list<string>
     */
    public static function holdersOf(array $holders, array $collections, array $members): array
    {
        $all = array_fill_keys($holders, true);
        $wanted = array_fill_keys(array_map('mb_strtolower', $collections), true);
        if ($wanted !== []) {
            foreach ($members as $member) {
                if ($member['status'] === self::STATUS_REVOKED) {
                    continue;
                }
                foreach ($member['collections'] as $collection) {
                    if (isset($wanted[mb_strtolower($collection)])) {
                        $all[$member['email']] = true;
                        break;
                    }
                }
            }
        }

        return array_map('strval', array_keys($all));
    }

    /**
     * Un compte à privilèges d'un équipement (compte local d'un contrôleur de gestion) a-t-il son
     * élément dans le coffre ? Même identifiant de connexion, et le nom ou l'adresse de l'élément
     * cite le contrôleur (nom, hôte de son adresse ou adresse IP).
     *
     * @param list<string> $hosts nom du contrôleur, hôte et adresse IP
     * @param list<array{username: string, name: string, uri: string}> $entries
     */
    public static function hasEntry(string $username, array $hosts, array $entries): bool
    {
        $username = mb_strtolower(trim($username));
        $hosts = array_values(array_filter(array_map(
            static fn (string $host): string => mb_strtolower(trim($host)),
            $hosts
        ), static fn (string $host): bool => $host !== ''));
        if ($username === '' || $hosts === []) {
            return false;
        }
        foreach ($entries as $entry) {
            if (mb_strtolower(trim($entry['username'])) !== $username) {
                continue;
            }
            $haystack = mb_strtolower($entry['name'] . ' ' . $entry['uri']);
            foreach ($hosts as $host) {
                if (str_contains($haystack, $host)) {
                    return true;
                }
            }
        }

        return false;
    }
}
