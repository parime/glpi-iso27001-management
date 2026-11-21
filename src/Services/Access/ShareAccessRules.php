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
 * Issue #846 : droits d'accès aux partages de fichiers Windows (SMB et NTFS), sans dépendance à
 * GLPI (testée unitairement). Lit l'export CSV du script PowerShell fourni (ou d'un outil tiers),
 * ramène les droits à trois niveaux (lecture, modification, contrôle total), reconnaît les comptes
 * « larges » (Tout le monde, Utilisateurs authentifiés…) et les comptes système, et calcule les
 * écarts, pondérés par la classification du partage. Elle signale ; elle ne modifie aucun droit.
 */
final class ShareAccessRules
{
    /** Niveau d'un droit. */
    public const LEVEL_FULL = 'full';
    public const LEVEL_MODIFY = 'modify';
    public const LEVEL_READ = 'read';
    public const LEVEL_OTHER = 'other';

    /** Nature du compte qui reçoit le droit. */
    public const KIND_BROAD = 'broad';
    public const KIND_SYSTEM = 'system';
    public const KIND_ORPHAN = 'orphan';
    public const KIND_USER = 'user';
    public const KIND_GROUP = 'group';
    public const KIND_UNKNOWN = 'unknown';

    /** Portée d'une ligne : droit de partage SMB ou droit NTFS d'un dossier. */
    public const SCOPE_SHARE = 'share';
    public const SCOPE_NTFS = 'ntfs';

    /** Écarts signalés. */
    public const GAP_BROAD_WRITE = 'broad_write';
    public const GAP_DIRECT_USER = 'direct_user';
    public const GAP_DELETED_ACCOUNT = 'deleted_account';
    public const GAP_DISABLED_ACCOUNT = 'disabled_account';
    public const GAP_BROKEN_INHERITANCE = 'broken_inheritance';
    public const GAP_NO_OWNER = 'no_owner';

    /** Écarts graves (poids 3) ; les autres pèsent 1. */
    public const SERIOUS_GAPS = [self::GAP_BROAD_WRITE, self::GAP_DELETED_ACCOUNT, self::GAP_DISABLED_ACCOUNT];

    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Valeurs « refuser » de la colonne du type d'accès. */
    private const DENY_WORDS = ['deny', 'refuser', 'refus', 'verweigern', 'denegar', 'negar', 'nega', 'odmow'];

    /**
     * Colonnes reconnues => en-têtes usuels normalisés (minuscules, sans séparateurs) : ceux du
     * script fourni, de Get-SmbShareAccess / Get-Acl, et d'outils tiers courants.
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'server'    => ['server', 'servername', 'computername', 'computer', 'host', 'hostname', 'serveur'],
        'share'     => ['share', 'sharename', 'partage', 'nomdupartage'],
        'sharepath' => ['sharepath', 'localpath', 'chemindupartage', 'cheminlocal'],
        'folder'    => ['folder', 'folderpath', 'fullname', 'path', 'directory', 'dossier', 'chemin'],
        'depth'     => ['depth', 'level', 'profondeur', 'niveau'],
        'scope'     => ['scope', 'acltype', 'permissiontype', 'portee'],
        'identity'  => [
            'identity', 'identityreference', 'accountname', 'account', 'principal', 'trustee', 'user',
            'username', 'compte', 'identite',
        ],
        'sid'       => ['sid', 'identitysid', 'securityidentifier', 'accountsid'],
        'type'      => ['principaltype', 'accounttype', 'objectclass', 'typedecompte', 'typeobjet'],
        'rights'    => [
            'rights', 'filesystemrights', 'accessright', 'accessrights', 'permissions', 'permission',
            'droits', 'droit',
        ],
        'access'    => ['accesstype', 'accesscontroltype', 'allowdeny', 'typedacces'],
        'inherited' => ['isinherited', 'inherited', 'herite'],
        'protected' => [
            'inheritanceprotected', 'areaccessrulesprotected', 'protected', 'inheritancebroken',
            'heritagedesactive', 'heritagecasse',
        ],
        'owner'     => ['owner', 'proprietaire'],
    ];

    /**
     * SID bien connus des comptes « larges » : Tout le monde, Ouverture de session anonyme,
     * Utilisateurs authentifiés, Utilisateurs et Invités locaux.
     */
    private const BROAD_SIDS = ['s-1-1-0', 's-1-5-7', 's-1-5-11', 's-1-5-32-545', 's-1-5-32-546'];

    /** Fin de SID de domaine : Utilisateurs du domaine (513), Invités du domaine (514). */
    private const BROAD_RIDS = ['513', '514'];

    /** Noms des comptes larges, dans les langues de Windows les plus courantes. */
    private const BROAD_NAMES = [
        'everyone', 'tout le monde', 'jeder', 'todos', 'chiunque', 'wszyscy',
        'authenticated users', 'utilisateurs authentifiés', 'authentifizierte benutzer', 'usuarios autentificados',
        'usuarios autenticados', 'utenti autenticati', 'użytkownicy uwierzytelnieni', 'usuários autenticados',
        'domain users', 'utilisateurs du domaine', 'domänen-benutzer', 'usuarios del dominio', 'utenti del dominio',
        'użytkownicy domeny', 'usuários do domínio',
        'users', 'utilisateurs', 'benutzer', 'usuarios', 'utenti', 'użytkownicy', 'usuários',
        'guests', 'invités', 'gäste', 'invitados', 'guest', 'goście', 'convidados',
        'domain guests', 'invités du domaine',
        'anonymous logon', 'ouverture de session anonyme', 'anonymous',
    ];

    /** SID des comptes système : SYSTEM, services, Administrateurs, Créateur propriétaire, opérateurs. */
    private const SYSTEM_SIDS = [
        's-1-5-18', 's-1-5-19', 's-1-5-20', 's-1-3-0', 's-1-3-1', 's-1-5-32-544', 's-1-5-32-549', 's-1-5-32-551',
    ];

    /** Fin de SID de domaine : Admins du domaine (512), Administrateurs de l'entreprise (519). */
    private const SYSTEM_RIDS = ['512', '519'];

    private const SYSTEM_NAMES = [
        'system', 'système', 'local service', 'network service', 'creator owner', 'créateur propriétaire',
        'ersteller-besitzer', 'administrators', 'administrateurs', 'administratoren', 'administradores',
        'administratori', 'administratorzy', 'domain admins', 'admins du domaine', 'domänen-admins',
        'enterprise admins', 'administrateurs de l’entreprise', "administrateurs de l'entreprise",
        'trustedinstaller', 'backup operators', 'opérateurs de sauvegarde', 'server operators',
        'opérateurs de serveur',
    ];

    /**
     * Niveau d'un droit tel que l'écrivent Get-SmbShareAccess (Full, Change, Read), Get-Acl
     * (« Modify, Synchronize », « ReadAndExecute »…) ou un outil tiers ; masque numérique accepté.
     */
    public static function rightLevel(string $rights): string
    {
        $value = mb_strtolower(trim($rights));
        if ($value === '') {
            return self::LEVEL_OTHER;
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return self::maskLevel((int) $value);
        }
        $value = str_replace(
            ['ô', 'é', 'è', 'ê', 'à', 'ã', 'ç', 'ó', 'ł'],
            ['o', 'e', 'e', 'e', 'a', 'a', 'c', 'o', 'l'],
            $value
        );
        $key = (string) preg_replace('/[^a-z]/', '', $value);
        $full = '/full|controletotal|vollzugriff|controltotal|controllocompleto|pelnakontrola|genericall/';
        if (preg_match($full, $key) === 1) {
            return self::LEVEL_FULL;
        }
        if (
            preg_match('/modify|change|write|append|delete|create|takeownership|changepermissions|modifier|ecriture'
            . '|suppression|ndern|schreiben|escritura|modificar|scrittura|modifica|zapis|modyfikacja|gravacao/', $key)
        ) {
            return self::LEVEL_MODIFY;
        }
        if (preg_match('/read|list|traverse|execute|synchronize|lecture|lesen|lectura|lettura|odczyt|leitura/', $key)) {
            return self::LEVEL_READ;
        }

        return self::LEVEL_OTHER;
    }

    /**
     * Niveau d'un masque d'accès Windows (FileSystemRights numérique, droits génériques compris).
     */
    public static function maskLevel(int $mask): string
    {
        $mask &= 0xFFFFFFFF;
        if (($mask & 0x10000000) !== 0 || ($mask & 0x1F01FF) === 0x1F01FF) {
            return self::LEVEL_FULL;
        }
        // GENERIC_WRITE, écriture, ajout, attributs étendus, suppression, droits, propriétaire.
        if (($mask & (0x40000000 | 0x2 | 0x4 | 0x10 | 0x40 | 0x100 | 0x10000 | 0x40000 | 0x80000)) !== 0) {
            return self::LEVEL_MODIFY;
        }
        if (($mask & (0x80000000 | 0x20000000 | 0x1 | 0x8 | 0x20 | 0x80 | 0x20000)) !== 0) {
            return self::LEVEL_READ;
        }

        return self::LEVEL_OTHER;
    }

    /**
     * Nom court d'un compte : « EXEMPLE\jdupont » ou « jdupont@exemple.test » donnent « jdupont ».
     */
    public static function shortName(string $identity): string
    {
        $name = trim($identity);
        $slash = mb_strrpos($name, '\\');
        if ($slash !== false) {
            $name = mb_substr($name, $slash + 1);
        }
        $at = mb_strpos($name, '@');
        if ($at !== false && $at > 0) {
            $name = mb_substr($name, 0, $at);
        }

        return mb_strtolower(trim($name));
    }

    /**
     * Nature d'un compte d'après son SID (prioritaire, indépendant de la langue) puis son nom.
     * Le type déclaré par l'export (User, Group) départage un compte ordinaire.
     */
    public static function principalKind(string $identity, string $sid = '', string $type = ''): string
    {
        $sid = mb_strtolower(trim($sid));
        $id = mb_strtolower(trim($identity));
        // Un SID non résolu à la place du nom : compte supprimé de l'annuaire.
        if (preg_match('/^s-1-5-21-[\d-]+$/', $id) === 1) {
            return self::KIND_ORPHAN;
        }
        if ($sid === '' && preg_match('/^s-1-[\d-]+$/', $id) === 1) {
            $sid = $id;
        }
        if ($sid !== '') {
            if (in_array($sid, self::BROAD_SIDS, true)) {
                return self::KIND_BROAD;
            }
            if (in_array($sid, self::SYSTEM_SIDS, true)) {
                return self::KIND_SYSTEM;
            }
            if (str_starts_with($sid, 's-1-5-21-')) {
                $rid = (string) substr($sid, (int) strrpos($sid, '-') + 1);
                if (in_array($rid, self::BROAD_RIDS, true)) {
                    return self::KIND_BROAD;
                }
                if (in_array($rid, self::SYSTEM_RIDS, true)) {
                    return self::KIND_SYSTEM;
                }
            }
        }
        $short = self::shortName($identity);
        if (in_array($short, self::BROAD_NAMES, true)) {
            return self::KIND_BROAD;
        }
        if (in_array($short, self::SYSTEM_NAMES, true)) {
            return self::KIND_SYSTEM;
        }
        $type = (string) preg_replace('/[^a-z]/', '', mb_strtolower($type));
        if (in_array($type, ['user', 'utilisateur', 'inetorgperson', 'person', 'benutzer', 'usuario'], true)) {
            return self::KIND_USER;
        }
        if (in_array($type, ['group', 'groupe', 'gruppe', 'grupo', 'gruppo', 'grupa', 'alias'], true)) {
            return self::KIND_GROUP;
        }

        return self::KIND_UNKNOWN;
    }

    /**
     * Valeur oui/non d'une cellule ; null si vide ou illisible.
     */
    public static function bool(string $cell): ?bool
    {
        return MfaCoverageRules::bool($cell);
    }

    /**
     * Découpe un chemin UNC « \\serveur\partage\dossier » ; chaînes vides si ce n'en est pas un.
     *
     * @return array{server: string, share: string}
     */
    public static function splitUnc(string $path): array
    {
        $path = str_replace('/', '\\', trim($path));
        if (!str_starts_with($path, '\\\\')) {
            return ['server' => '', 'share' => ''];
        }
        $parts = explode('\\', substr($path, 2));

        return ['server' => (string) ($parts[0] ?? ''), 'share' => (string) ($parts[1] ?? '')];
    }

    /**
     * Lit un export CSV (en-tête obligatoire, séparateur et encodage détectés) en lignes de droits.
     *
     * @return array{rows: list<array{server: string, share: string, share_path: string, folder: string,
     *     depth: int, scope: string, identity: string, sid: string, type: string, rights: string,
     *     level: string, is_deny: bool, is_inherited: bool, is_protected: bool, owner: string}>,
     *     errors: list<string>, truncated: bool}
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
        if (!isset($mapping['identity']) && !isset($mapping['sid'])) {
            return ['rows' => [], 'errors' => ['missing_identity'], 'truncated' => false];
        }
        if (!isset($mapping['rights'])) {
            return ['rows' => [], 'errors' => ['missing_rights'], 'truncated' => false];
        }
        if (!isset($mapping['share']) && !isset($mapping['folder'])) {
            return ['rows' => [], 'errors' => ['missing_share'], 'truncated' => false];
        }
        $get = static fn (array $row, string $field): string
            => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $identity = mb_substr($get($row, 'identity'), 0, 255);
            $sid = mb_substr($get($row, 'sid'), 0, 190);
            if ($identity === '') {
                $identity = $sid;
            }
            $folder = mb_substr($get($row, 'folder'), 0, 1000);
            $server = mb_substr($get($row, 'server'), 0, 255);
            $share = mb_substr($get($row, 'share'), 0, 255);
            $unc = self::splitUnc($folder);
            $server = $server !== '' ? $server : $unc['server'];
            $share = $share !== '' ? $share : $unc['share'];
            if ($identity === '' || $share === '') {
                continue;
            }
            $scope = mb_strtolower($get($row, 'scope'));
            $scope = in_array($scope, ['share', 'smb', 'partage'], true) ? self::SCOPE_SHARE : self::SCOPE_NTFS;
            $access = mb_strtolower($get($row, 'access'));
            $depth = $get($row, 'depth');
            $rights = mb_substr($get($row, 'rights'), 0, 255);
            $rows[] = [
                'server'       => $server,
                'share'        => $share,
                'share_path'   => mb_substr($get($row, 'sharepath'), 0, 1000),
                'folder'       => $folder !== '' ? $folder : '\\\\' . $server . '\\' . $share,
                'depth'        => is_numeric($depth) ? max(0, min(99, (int) $depth)) : 0,
                'scope'        => $scope,
                'identity'     => $identity,
                'sid'          => $sid,
                'type'         => mb_substr($get($row, 'type'), 0, 32),
                'rights'       => $rights,
                'level'        => self::rightLevel($rights),
                'is_deny'      => in_array($access, self::DENY_WORDS, true),
                'is_inherited' => self::bool($get($row, 'inherited')) === true,
                'is_protected' => self::bool($get($row, 'protected')) === true,
                'owner'        => mb_substr($get($row, 'owner'), 0, 255),
            ];
        }

        return ['rows' => $rows, 'errors' => [], 'truncated' => $parsed['truncated']];
    }

    /**
     * Empreinte d'un droit, stable d'un import à l'autre (suivi des droits ajoutés et retirés).
     *
     * @param array<string, mixed> $row server, share, folder, scope, identity, sid, level, is_deny
     */
    public static function fingerprint(array $row): string
    {
        $who = trim((string) ($row['sid'] ?? '')) !== '' ? (string) $row['sid'] : (string) ($row['identity'] ?? '');

        return sha1(mb_strtolower(implode('|', [
            trim((string) ($row['server'] ?? '')),
            trim((string) ($row['share'] ?? '')),
            rtrim(str_replace('/', '\\', trim((string) ($row['folder'] ?? ''))), '\\'),
            (string) ($row['scope'] ?? ''),
            trim($who),
            (string) ($row['level'] ?? ''),
            !empty($row['is_deny']) ? 'deny' : 'allow',
        ])));
    }

    /**
     * Écarts d'un droit. $account : état du compte GLPI rapproché (null si aucun).
     *
     * @param array<string, mixed> $ace kind, level, is_deny
     * @param array{is_active: bool, is_deleted: bool}|null $account
     * @return list<string>
     */
    public static function aceGaps(array $ace, ?array $account = null): array
    {
        if (!empty($ace['is_deny'])) {
            return [];
        }
        $kind = (string) ($ace['kind'] ?? self::KIND_UNKNOWN);
        $level = (string) ($ace['level'] ?? self::LEVEL_OTHER);
        $gaps = [];
        if ($kind === self::KIND_BROAD && in_array($level, [self::LEVEL_MODIFY, self::LEVEL_FULL], true)) {
            $gaps[] = self::GAP_BROAD_WRITE;
        }
        if ($kind === self::KIND_ORPHAN || ($account !== null && $account['is_deleted'])) {
            $gaps[] = self::GAP_DELETED_ACCOUNT;
        } elseif ($account !== null && !$account['is_active']) {
            $gaps[] = self::GAP_DISABLED_ACCOUNT;
        }
        if ($kind === self::KIND_USER) {
            $gaps[] = self::GAP_DIRECT_USER;
        }

        return $gaps;
    }

    /**
     * Le partage est-il sensible ? Confidentialité ou intégrité moyenne ou élevée.
     *
     * @param array<string, mixed> $classification confidentiality, integrity, availability
     */
    public static function isSensitive(array $classification): bool
    {
        foreach (['confidentiality', 'integrity'] as $axis) {
            if (in_array($classification[$axis] ?? '', ['medium', 'high'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Facteur de pondération d'après la classification : élevée 3, moyenne 2, sinon 1.
     *
     * @param array<string, mixed> $classification
     */
    public static function factor(array $classification): int
    {
        $factor = 1;
        foreach (['confidentiality', 'integrity', 'availability'] as $axis) {
            $value = $classification[$axis] ?? '';
            $factor = max($factor, $value === 'high' ? 3 : ($value === 'medium' ? 2 : 1));
        }

        return $factor;
    }

    /**
     * Écarts d'un partage : ceux de ses droits, l'héritage cassé d'un sous-dossier quand le partage
     * est sensible, l'absence de propriétaire désigné.
     *
     * @param list<list<string>> $aceGaps écarts de chaque droit
     * @param list<array{depth: int, is_protected: bool}> $folders dossiers importés
     * @param array<string, mixed> $classification
     * @return array<string, int> écart => nombre
     */
    public static function shareGaps(array $aceGaps, array $folders, array $classification, bool $hasOwner): array
    {
        $counts = [];
        foreach ($aceGaps as $gaps) {
            foreach ($gaps as $gap) {
                $counts[$gap] = ($counts[$gap] ?? 0) + 1;
            }
        }
        if (self::isSensitive($classification)) {
            $broken = count(array_filter(
                $folders,
                static fn (array $folder): bool => $folder['depth'] > 0 && $folder['is_protected']
            ));
            if ($broken > 0) {
                $counts[self::GAP_BROKEN_INHERITANCE] = $broken;
            }
        }
        if (!$hasOwner) {
            $counts[self::GAP_NO_OWNER] = 1;
        }

        return $counts;
    }

    /**
     * Score pondéré d'un partage : écarts graves 3, autres 1, multipliés par le facteur de
     * classification.
     *
     * @param array<string, int> $gaps
     * @param array<string, mixed> $classification
     */
    public static function score(array $gaps, array $classification): int
    {
        $weight = 0;
        foreach ($gaps as $gap => $count) {
            $weight += $count * (in_array($gap, self::SERIOUS_GAPS, true) ? 3 : 1);
        }

        return $weight * self::factor($classification);
    }

    /**
     * Niveau affiché d'un score : 0 aucun écart, jusqu'à 3 faible, jusqu'à 9 moyen, au-delà élevé.
     */
    public static function scoreLevel(int $score): string
    {
        return match (true) {
            $score <= 0 => 'none',
            $score <= 3 => 'low',
            $score <= 9 => 'medium',
            default     => 'high',
        };
    }
}
