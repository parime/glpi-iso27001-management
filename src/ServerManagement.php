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

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;
use CommonGLPI;
use Computer;
use ComputerModel;
use ComputerType;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GLPIKey;
use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Install\ServerManagementTables as Tables;
use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules;
use GlpiPlugin\Grcmanager\Services\Cve\OperatingSystemInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\CycleMatcher;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\ProductMatcher;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcFirmwareRules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcRules as Rules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\RedfishClient;
use Html;
use Manufacturer;
use Session;

/**
 * Issue #843 : contrôleurs de gestion des serveurs (HPE iLO, Dell iDRAC, Lenovo XClarity
 * Controller, Supermicro IPMI/BMC), angles morts des mesures A.8.2 (accès privilégiés), A.8.9
 * (configuration) et A.8.22 (cloisonnement des réseaux).
 *
 * - Contrôleurs déclarés un par un (adresse https, compte local en lecture seule, mot de passe
 *   chiffré par GLPI et jamais réaffiché, certificat auto-signé accepté seulement si l'option est
 *   cochée pour ce contrôleur), lus par Redfish sur clic : GET uniquement, aucune action sur le
 *   serveur. Une lecture en échec ne remplace pas la précédente.
 * - Contrôleurs sans Redfish (IPMI seul) : import CSV.
 * - Inventaire (modèle, firmware du contrôleur, BIOS, numéro de série), rapproché des ordinateurs
 *   de GLPI par le numéro de série ; serveurs physiques sans contrôleur connu.
 * - Écarts signalés seulement : IPMI sur le réseau, Telnet, SNMP v1/v2c, protocoles TLS anciens
 *   (import), certificat auto-signé ou expiré, démarrage sécurisé désactivé, compte par défaut
 *   actif, adresse hors des réseaux d'administration de la matrice des flux, santé dégradée.
 * - Comptes locaux et leur rôle, pour la revue des accès privilégiés.
 *
 * Droits : droit du plugin et lecture native des ordinateurs, dans les entités de la session ;
 * modification du plugin pour déclarer, lire, importer et supprimer.
 */
class ServerManagement extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Contrôleurs et serveurs listés au plus sur la page. */
    public const MAX_ROWS = 300;

    /** Numéros de série trop génériques pour rapprocher un serveur. */
    private const GENERIC_SERIALS = [
        'to be filled by o.e.m.', 'default string', 'system serial number', 'none', 'n/a', '0', '123456789',
    ];

    /** @var array<string, mixed>|null calcul de la requête (session) */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('Server management controllers', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-server-cog';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/servermanagement.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ)
            && (bool) Session::haveRight(Computer::$rightname, READ);
    }

    /** Déclarer, lire, importer et supprimer des contrôleurs. */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Sécurité technique » (Menu::sections()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function reset(): void
    {
        self::$memo = null;
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, array{label: string, help: string, severity: string, color: string, icon: string}>
     */
    public static function gapInfos(): array
    {
        $texts = [
            Rules::GAP_READ_FAILED => [
                __('Last reading failed', 'grcmanager'),
                __('The values shown come from the previous successful reading.', 'grcmanager'),
                'ti ti-plug-connected-x',
            ],
            Rules::GAP_NOT_READ => [
                __('Never read', 'grcmanager'),
                __('Click "Read now" to read the controller.', 'grcmanager'),
                'ti ti-clock-question',
            ],
            Rules::GAP_IPMI_LAN => [
                __('IPMI over the network enabled', 'grcmanager'),
                __(
                    'IPMI over LAN has known weaknesses (password hashes can be retrieved): disable it if it is not '
                        . 'used.',
                    'grcmanager'
                ),
                'ti ti-network',
            ],
            Rules::GAP_DEFAULT_ACCOUNT => [
                __('Default account still enabled', 'grcmanager'),
                __(
                    'A factory account (root, Administrator, ADMIN, USERID) is enabled: check that its password was '
                        . 'changed, or disable it.',
                    'grcmanager'
                ),
                'ti ti-user-exclamation',
            ],
            Rules::GAP_OUTSIDE_ADMIN_NETWORK => [
                __('Outside the administration networks', 'grcmanager'),
                __(
                    'The address of the controller is not in an administration network declared in the network flow '
                        . 'matrix: segregation gap.',
                    'grcmanager'
                ),
                'ti ti-route-off',
            ],
            Rules::GAP_TELNET => [
                __('Telnet enabled', 'grcmanager'),
                __('Telnet sends the password in clear text: disable it.', 'grcmanager'),
                'ti ti-terminal',
            ],
            Rules::GAP_WEAK_TLS => [
                __('Old protocols (SSLv3, TLS 1.0 or 1.1)', 'grcmanager'),
                __('Reported by the import: keep only TLS 1.2 and later.', 'grcmanager'),
                'ti ti-lock-open',
            ],
            Rules::GAP_CERT_EXPIRED => [
                __('Certificate expired', 'grcmanager'),
                __('The HTTPS certificate of the controller has expired.', 'grcmanager'),
                'ti ti-certificate-off',
            ],
            Rules::GAP_HEALTH_CRITICAL => [
                __('Hardware health critical', 'grcmanager'),
                __('The controller reports a critical hardware state.', 'grcmanager'),
                'ti ti-heart-broken',
            ],
            Rules::GAP_FIRMWARE_PRIORITY_CVE => [
                __('Firmware with a priority vulnerability', 'grcmanager'),
                __(
                    'The firmware of the controller or the BIOS has a known vulnerability that is exploited, critical '
                        . 'or likely to be exploited (page "Fleet vulnerabilities"): update it first.',
                    'grcmanager'
                ),
                'ti ti-bug',
            ],
            Rules::GAP_FIRMWARE_EOL => [
                __('Firmware no longer supported', 'grcmanager'),
                __(
                    'The firmware of the controller or the BIOS has reached its end of support (endoflife.date): '
                        . 'no more security fixes.',
                    'grcmanager'
                ),
                'ti ti-calendar-off',
            ],
            Rules::GAP_FIRMWARE_CVE => [
                __('Firmware with known vulnerabilities', 'grcmanager'),
                __(
                    'The firmware of the controller or the BIOS has known vulnerabilities (page "Fleet '
                        . 'vulnerabilities").',
                    'grcmanager'
                ),
                'ti ti-bug',
            ],
            Rules::GAP_FIRMWARE_EOL_SOON => [
                __('Firmware support ending soon', 'grcmanager'),
                __('The end of support of the firmware or the BIOS is near (endoflife.date).', 'grcmanager'),
                'ti ti-calendar-time',
            ],
            Rules::GAP_SNMP_V1V2 => [
                __('SNMP v1 or v2c enabled', 'grcmanager'),
                __('SNMP v1 and v2c use a community sent in clear text: prefer SNMPv3.', 'grcmanager'),
                'ti ti-broadcast',
            ],
            Rules::GAP_SECURE_BOOT_OFF => [
                __('Secure Boot disabled', 'grcmanager'),
                __('Secure Boot of the server is disabled.', 'grcmanager'),
                'ti ti-shield-off',
            ],
            Rules::GAP_CERT_SELF_SIGNED => [
                __('Self-signed certificate', 'grcmanager'),
                __('Replace it with a certificate of your certification authority.', 'grcmanager'),
                'ti ti-certificate',
            ],
            Rules::GAP_TLS_NOT_VERIFIED => [
                __('Certificate not verified', 'grcmanager'),
                __(
                    'The controller is read with the option accepting a self-signed certificate: its identity is '
                        . 'not verified.',
                    'grcmanager'
                ),
                'ti ti-lock-question',
            ],
            Rules::GAP_HEALTH_WARNING => [
                __('Hardware health degraded', 'grcmanager'),
                __('The controller reports a warning on the hardware.', 'grcmanager'),
                'ti ti-heartbeat',
            ],
            Rules::GAP_CERT_EXPIRING => [
                __('Certificate expiring soon', 'grcmanager'),
                sprintf(__('The HTTPS certificate expires within %d days.', 'grcmanager'), Rules::CERT_EXPIRING_DAYS),
                'ti ti-calendar-time',
            ],
            Rules::GAP_NO_SERVER => [
                __('No matching server in GLPI', 'grcmanager'),
                __(
                    'No computer of GLPI has this serial number: choose the server by hand on the controller.',
                    'grcmanager'
                ),
                'ti ti-link-off',
            ],
        ];
        $colors = [
            Rules::SEVERITY_HIGH => 'danger', Rules::SEVERITY_MEDIUM => 'warning', Rules::SEVERITY_LOW => 'secondary',
        ];
        $infos = [];
        foreach (Rules::GAPS as $gap => $severity) {
            $infos[$gap] = [
                'label'    => $texts[$gap][0],
                'help'     => $texts[$gap][1],
                'icon'     => $texts[$gap][2],
                'severity' => $severity,
                'color'    => $colors[$severity],
            ];
        }

        return $infos;
    }

    /**
     * Message d'une erreur de lecture ou d'enregistrement (jamais le mot de passe).
     */
    public static function errorMessage(string $code): string
    {
        return match ($code) {
            RedfishClient::ERROR_CONFIG       => __(
                'Enter the address, the account and the password of the controller first.',
                'grcmanager'
            ),
            RedfishClient::ERROR_UNAUTHORIZED => __(
                'The controller refused the account or the password.',
                'grcmanager'
            ),
            RedfishClient::ERROR_FORBIDDEN    => __(
                'The controller refused access: give the account the read-only role.',
                'grcmanager'
            ),
            RedfishClient::ERROR_INTERNAL     => __(
                'The controller has an internal address, refused by default: allow internal addresses in the '
                    . 'plugin configuration page (Outgoing connections).',
                'grcmanager'
            ),
            RedfishClient::ERROR_TLS          => __(
                'The certificate of the controller is not trusted (self-signed or expired): install a certificate '
                    . 'of your certification authority, or tick "Accept a self-signed certificate" for this '
                    . 'controller.',
                'grcmanager'
            ),
            RedfishClient::ERROR_UNREACHABLE  => __(
                'The controller could not be reached: check the address, the network access or the GLPI proxy.',
                'grcmanager'
            ),
            RedfishClient::ERROR_NOT_REDFISH  => __(
                'No Redfish service at this address: for a controller with IPMI only, use the CSV import.',
                'grcmanager'
            ),
            'invalid_url'       => __(
                'The address must be a https:// address of the controller, without path or parameters.',
                'grcmanager'
            ),
            'missing_name'      => __('Enter a name.', 'grcmanager'),
            'password_required' => __('Enter the password again: the address or the account changed.', 'grcmanager'),
            'invalid_computer'  => __('The server chosen cannot be used.', 'grcmanager'),
            'csv_source'        => __(
                'This controller comes from the CSV import: import the file again to update it.',
                'grcmanager'
            ),
            'not_found'         => __('Controller not found.', 'grcmanager'),
            'rights'            => __('You are not allowed to do this.', 'grcmanager'),
            'empty'             => __('The file is empty.', 'grcmanager'),
            'csv_missing_name'  => __('The file has no "name" column.', 'grcmanager'),
            default             => __('The controller returned an unexpected answer.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Contrôleurs déclarés.

    /**
     * Restriction des contrôleurs : entités de la session, ou périmètre donné (KRI).
     *
     * @param list<int>|null $entities
     * @return array<mixed>
     */
    private static function entityWhere(?array $entities): array
    {
        if ($entities === null) {
            return getEntitiesRestrictCriteria(Tables::CONTROLLERS, '', '', true);
        }

        return [Tables::CONTROLLERS . '.entities_id' => $entities === [] ? [-1] : $entities];
    }

    /**
     * Contrôleur visible dans les entités de la session (null sinon).
     *
     * @return array<string, mixed>|null
     */
    public static function controller(int $id): ?array
    {
        global $DB;

        if ($id <= 0) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => Tables::CONTROLLERS,
            'WHERE' => ['id' => $id] + self::entityWhere(null),
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Ordinateur choisi à la main : existant, non supprimé, dans une entité de la session.
     */
    private static function validComputer(int $id): bool
    {
        if ($id <= 0) {
            return true;
        }
        $computer = new Computer();

        return $computer->getFromDB($id)
            && (int) $computer->fields['is_deleted'] === 0
            && (int) $computer->fields['is_template'] === 0
            && Session::haveAccessToEntity((int) $computer->fields['entities_id']);
    }

    /**
     * Déclare ou modifie un contrôleur lu par Redfish. Mot de passe vide : celui enregistré est
     * gardé, sauf si l'adresse ou le compte changent (il faut alors le saisir de nouveau, pour
     * qu'un mot de passe enregistré ne puisse pas être envoyé à une autre adresse).
     *
     * @param array<string, mixed> $input
     * @return array{id: int, error: string}
     */
    public static function saveController(array $input, int $id = 0): array
    {
        global $DB;

        if (!self::canManage()) {
            return ['id' => 0, 'error' => 'rights'];
        }
        $existing = $id > 0 ? self::controller($id) : null;
        if ($id > 0 && $existing === null) {
            return ['id' => 0, 'error' => 'not_found'];
        }
        if ($existing !== null && (string) $existing['source'] !== Rules::SOURCE_REDFISH) {
            return ['id' => 0, 'error' => 'csv_source'];
        }
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 255);
        if ($name === '') {
            return ['id' => 0, 'error' => 'missing_name'];
        }
        $url = Rules::normalizeUrl((string) ($input['url'] ?? ''), Http::allowsPrivateNetworks());
        if ($url === null) {
            return ['id' => 0, 'error' => 'invalid_url'];
        }
        $username = mb_substr(trim((string) ($input['username'] ?? '')), 0, 255);
        $password = (string) ($input['password'] ?? '');
        $computerId = max(0, (int) ($input['computers_id'] ?? 0));
        if (!self::validComputer($computerId)) {
            return ['id' => 0, 'error' => 'invalid_computer'];
        }
        $changedTarget = $existing !== null
            && ((string) $existing['url'] !== $url || (string) $existing['username'] !== $username);
        $hasStored = $existing !== null && (string) ($existing['password'] ?? '') !== '';
        if ($password === '' && $changedTarget && $hasStored) {
            return ['id' => 0, 'error' => 'password_required'];
        }
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $values = [
            'name'              => $name,
            'url'               => $url,
            'username'          => $username,
            'allow_self_signed' => empty($input['allow_self_signed']) ? 0 : 1,
            'computers_id'      => $computerId,
            'date_mod'          => $now,
        ];
        if ($password !== '') {
            $values['password'] = (string) (new GLPIKey())->encrypt($password);
        }
        if ($existing === null) {
            $DB->insert(Tables::CONTROLLERS, $values + [
                'entities_id'   => (int) Session::getActiveEntity(),
                'is_recursive'  => 0,
                'source'        => Rules::SOURCE_REDFISH,
                'date_creation' => $now,
            ]);
            $id = (int) $DB->insertId();
        } else {
            $DB->update(Tables::CONTROLLERS, $values, ['id' => $id]);
        }
        // Seulement le fait que le mot de passe ait changé, jamais sa valeur.
        AuditLog::record($existing === null ? 'create' : 'update', self::class, $id, 0, 'success', [
            'url'               => $url,
            'allow_self_signed' => $values['allow_self_signed'],
            'password_changed'  => $password !== '',
        ]);
        self::reset();

        return ['id' => $id, 'error' => ''];
    }

    /** Supprime un contrôleur visible et ses comptes lus. */
    public static function deleteController(int $id): bool
    {
        global $DB;

        if (!self::canManage() || self::controller($id) === null) {
            return false;
        }
        $DB->delete(Tables::ACCOUNTS, ['plugin_grcmanager_bmccontrollers_id' => $id]);
        $DB->delete(Tables::CONTROLLERS, ['id' => $id]);
        AuditLog::record('purge', self::class, $id, 0, 'success');
        self::reset();

        return true;
    }

    /** Client Redfish d'un contrôleur (mot de passe déchiffré au moment de l'appel seulement). */
    public static function clientFor(array $controller): RedfishClient
    {
        $stored = (string) ($controller['password'] ?? '');
        $url = Rules::normalizeUrl((string) $controller['url'], Http::allowsPrivateNetworks()) ?? '';

        return new RedfishClient(
            $url,
            (string) $controller['username'],
            $stored === '' ? '' : (string) (new GLPIKey())->decrypt($stored),
            (int) $controller['allow_self_signed'] !== 1
        );
    }

    /**
     * Lit un contrôleur par Redfish (GET seulement). Une lecture en échec ne remplace pas la
     * précédente : seuls le code d'erreur et sa date sont notés.
     *
     * @return array{error: string, accounts: int, accounts_read: bool}
     */
    public static function read(int $id, ?RedfishClient $client = null): array
    {
        global $DB;

        $result = ['error' => '', 'accounts' => 0, 'accounts_read' => false];
        if (!self::canManage()) {
            return ['error' => 'rights'] + $result;
        }
        $controller = self::controller($id);
        if ($controller === null) {
            return ['error' => 'not_found'] + $result;
        }
        if ((string) $controller['source'] !== Rules::SOURCE_REDFISH) {
            return ['error' => 'csv_source'] + $result;
        }
        $client ??= self::clientFor($controller);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $fetch = $client->fetch();
        if ($fetch['error'] !== '' || !$fetch['complete']) {
            $error = $fetch['error'] !== '' ? $fetch['error'] : RedfishClient::ERROR_INVALID;
            $DB->update(Tables::CONTROLLERS, ['read_error' => $error, 'date_read_failed' => $now], ['id' => $id]);
            AuditLog::record('import', self::class, $id, 0, 'failure', ['error' => $error]);
            self::reset();

            return ['error' => $error] + $result;
        }
        $host = Rules::hostOf((string) $controller['url']);
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : '';
        if ($ip === '' && $host !== '') {
            $resolved = gethostbyname($host);
            $ip = filter_var($resolved, FILTER_VALIDATE_IP) !== false ? $resolved : '';
        }
        $snapshot = $fetch['snapshot'];
        $DB->update(Tables::CONTROLLERS, [
            'ip_address'       => mb_substr($ip, 0, 64),
            'vendor'           => (string) $snapshot['vendor'],
            'redfish_version'  => (string) $snapshot['redfish_version'],
            'manager_model'    => (string) $snapshot['manager_model'],
            'firmware_version' => (string) $snapshot['firmware_version'],
            'bios_version'     => (string) $snapshot['bios_version'],
            'serial'           => (string) $snapshot['serial'],
            'system_model'     => (string) $snapshot['system_model'],
            'manufacturer'     => (string) $snapshot['manufacturer'],
            'health'           => (string) $snapshot['health'],
            'power_state'      => (string) $snapshot['power_state'],
            'ipmi_lan'         => (int) $snapshot['ipmi_lan'],
            'telnet'           => (int) $snapshot['telnet'],
            'snmp_v1v2'        => (int) $snapshot['snmp_v1v2'],
            'weak_tls'         => (int) $snapshot['weak_tls'],
            'secure_boot'      => (int) $snapshot['secure_boot'],
            'tls_verified'     => (int) $snapshot['tls_verified'],
            'cert_self_signed' => (int) $snapshot['cert_self_signed'],
            'cert_expiry'      => $snapshot['cert_expiry'],
            'accounts_read'    => $fetch['accounts_read'] ? 1 : 0,
            'date_read'        => $now,
            'read_error'       => '',
            'date_read_failed' => null,
        ], ['id' => $id]);
        // Comptes illisibles avec ce compte : la liste précédente est gardée.
        if ($fetch['accounts_read']) {
            self::storeAccounts($id, $fetch['accounts'], $now);
        }
        AuditLog::record('import', self::class, $id, 0, 'success', [
            'source'   => 'redfish',
            'accounts' => count($fetch['accounts']),
        ]);
        self::reset();

        return ['error' => '', 'accounts' => count($fetch['accounts']), 'accounts_read' => $fetch['accounts_read']];
    }

    /**
     * @param list<array{username: string, role: string, enabled: int, locked: int}> $accounts
     */
    private static function storeAccounts(int $controllerId, array $accounts, string $now): void
    {
        global $DB;

        $DB->delete(Tables::ACCOUNTS, ['plugin_grcmanager_bmccontrollers_id' => $controllerId]);
        foreach ($accounts as $account) {
            $DB->insert(Tables::ACCOUNTS, [
                'plugin_grcmanager_bmccontrollers_id' => $controllerId,
                'username'    => (string) $account['username'],
                'role'        => (string) $account['role'],
                'is_enabled'  => (int) $account['enabled'],
                'is_locked'   => (int) $account['locked'],
                'date_import' => $now,
            ]);
        }
    }

    /**
     * Import CSV des contrôleurs sans Redfish, dans l'entité active : une ligne par contrôleur,
     * rapprochée par son nom des contrôleurs déjà importés de l'entité (mis à jour) ; un contrôleur
     * lu par Redfish du même nom n'est pas touché.
     *
     * @return array{imported: int, updated: int, skipped: int, errors: list<string>, truncated: bool}
     */
    public static function importCsv(string $raw): array
    {
        global $DB;

        $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => false];
        if (!self::canManage()) {
            return ['errors' => ['rights']] + $result;
        }
        $parsed = Rules::parseCsv($raw);
        if ($parsed['errors'] !== []) {
            return ['errors' => array_map(
                static fn (string $code): string => $code === 'missing_name' ? 'csv_missing_name' : $code,
                $parsed['errors']
            )] + $result;
        }
        $entity = (int) Session::getActiveEntity();
        $existing = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'source'],
                'FROM'   => Tables::CONTROLLERS,
                'WHERE'  => ['entities_id' => $entity],
            ]) as $row
        ) {
            $existing[mb_strtolower((string) $row['name'])] = $row;
        }
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach ($parsed['rows'] as $row) {
            $current = $existing[mb_strtolower($row['name'])] ?? null;
            if ($current !== null && (string) $current['source'] !== Rules::SOURCE_CSV) {
                $result['skipped']++;
                continue;
            }
            $values = [
                'name'             => $row['name'],
                'url'              => '',
                'ip_address'       => mb_substr((string) $row['ip_address'], 0, 64),
                'vendor'           => $row['vendor'],
                'manager_model'    => $row['manager_model'],
                'firmware_version' => $row['firmware_version'],
                'bios_version'     => $row['bios_version'],
                'serial'           => $row['serial'],
                'ipmi_lan'         => $row['ipmi_lan'],
                'telnet'           => $row['telnet'],
                'snmp_v1v2'        => $row['snmp_v1v2'],
                'weak_tls'         => $row['weak_tls'],
                'secure_boot'      => $row['secure_boot'],
                'accounts_read'    => $row['accounts'] !== [] ? 1 : 0,
                'date_read'        => $now,
                'read_error'       => '',
                'date_mod'         => $now,
            ];
            if ($current === null) {
                $DB->insert(Tables::CONTROLLERS, $values + [
                    'entities_id'   => $entity,
                    'is_recursive'  => 0,
                    'source'        => Rules::SOURCE_CSV,
                    'date_creation' => $now,
                ]);
                $controllerId = (int) $DB->insertId();
                $existing[mb_strtolower($row['name'])] = ['id' => $controllerId, 'source' => Rules::SOURCE_CSV];
                $result['imported']++;
            } else {
                $controllerId = (int) $current['id'];
                $DB->update(Tables::CONTROLLERS, $values, ['id' => $controllerId]);
                $result['updated']++;
            }
            if ($row['accounts'] !== []) {
                self::storeAccounts($controllerId, $row['accounts'], $now);
            }
        }
        $result['truncated'] = $parsed['truncated'];
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'source'   => 'csv',
            'imported' => $result['imported'],
            'updated'  => $result['updated'],
        ]);
        self::reset();

        return $result;
    }

    // ------------------------------------------------------------------ Calcul.

    /**
     * Ordinateurs des entités (session ou périmètre) : série, type, système, modèle, fabricant.
     *
     * @param list<int>|null $entities
     * @return array<int, array<string, mixed>>
     */
    private static function computers(?array $entities): array
    {
        global $DB;

        $table = Computer::getTable();
        $where = [$table . '.is_deleted' => 0, $table . '.is_template' => 0]
            + ($entities === null
                ? getEntitiesRestrictCriteria($table, '', '', true)
                : [$table . '.entities_id' => $entities === [] ? [-1] : $entities]);
        $excluded = ComplianceAudit::getExcludedStateIds();
        if ($excluded !== []) {
            $where[] = ['NOT' => [$table . '.states_id' => $excluded]];
        }
        $computers = [];
        $typeIds = [];
        $modelIds = [];
        $manufacturerIds = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'serial', 'entities_id', 'computertypes_id', 'computermodels_id', 'manufacturers_id',
                ],
                'FROM'   => $table,
                'WHERE'  => $where,
            ]) as $row
        ) {
            $computers[(int) $row['id']] = $row;
            $typeIds[(int) $row['computertypes_id']] = true;
            $modelIds[(int) $row['computermodels_id']] = true;
            $manufacturerIds[(int) $row['manufacturers_id']] = true;
        }
        $names = static function (string $table, array $ids) use ($DB): array {
            unset($ids[0]);
            $out = [];
            if ($ids !== []) {
                $request = ['SELECT' => ['id', 'name'], 'FROM' => $table, 'WHERE' => ['id' => array_keys($ids)]];
                foreach ($DB->request($request) as $row) {
                    $out[(int) $row['id']] = (string) $row['name'];
                }
            }

            return $out;
        };
        $types = $names(ComputerType::getTable(), $typeIds);
        $models = $names(ComputerModel::getTable(), $modelIds);
        $manufacturers = $names(Manufacturer::getTable(), $manufacturerIds);
        $systems = OperatingSystemInventory::forComputers(array_keys($computers));
        foreach ($computers as $id => $row) {
            $type = $types[(int) $row['computertypes_id']] ?? '';
            $model = $models[(int) $row['computermodels_id']] ?? '';
            $manufacturer = $manufacturers[(int) $row['manufacturers_id']] ?? '';
            $computers[$id] = [
                'id'          => $id,
                'name'        => (string) $row['name'],
                'serial'      => (string) $row['serial'],
                'entities_id' => (int) $row['entities_id'],
                'type'        => $type,
                'model'       => $model,
                'is_server'   => PatchLagRules::isServer($type, (string) ($systems[$id]['name'] ?? '')),
                'is_virtual'  => Rules::isVirtual($model, $manufacturer),
            ];
        }

        return $computers;
    }

    /** Numéro de série utilisable pour un rapprochement (null : trop court ou générique). */
    public static function serialKey(string $serial): ?string
    {
        $serial = mb_strtolower(trim($serial));

        return mb_strlen($serial) >= 4 && !in_array($serial, self::GENERIC_SERIALS, true) ? $serial : null;
    }

    /**
     * Calcul complet : contrôleurs visibles, leur serveur, leurs comptes et leurs écarts ; serveurs
     * physiques sans contrôleur connu. Sans session (KRI), passer le périmètre.
     *
     * @param list<int>|null $entities
     * @return array{controllers: list<array<string, mixed>>, servers_without: list<array<string, mixed>>,
     *     physical_servers: int, admin_networks: int}
     */
    private static function compute(?array $entities): array
    {
        global $DB;

        $computers = self::computers($entities);
        $bySerial = [];
        $duplicates = [];
        foreach ($computers as $computer) {
            $key = self::serialKey((string) $computer['serial']);
            if ($key !== null) {
                if (isset($bySerial[$key])) {
                    $duplicates[$key] = true;
                }
                $bySerial[$key] = (int) $computer['id'];
            }
        }
        foreach (array_keys($duplicates) as $key) {
            unset($bySerial[$key]);
        }

        $flows = [];
        foreach (
            $DB->request([
                'SELECT' => ['source_label', 'destination_label'],
                'FROM'   => NetworkFlow::getTable(),
                'WHERE'  => ['is_active' => 1],
            ]) as $row
        ) {
            $flows[] = [
                'source_label'      => (string) $row['source_label'],
                'destination_label' => (string) $row['destination_label'],
            ];
        }
        $networks = Rules::adminNetworks($flows);

        $rows = [];
        $request = ['FROM' => Tables::CONTROLLERS, 'WHERE' => self::entityWhere($entities), 'ORDER' => 'name'];
        foreach ($DB->request($request) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $accounts = [];
        if ($rows !== []) {
            foreach (
                $DB->request([
                    'FROM'  => Tables::ACCOUNTS,
                    'WHERE' => ['plugin_grcmanager_bmccontrollers_id' => array_keys($rows)],
                    'ORDER' => 'username',
                ]) as $account
            ) {
                $accounts[(int) $account['plugin_grcmanager_bmccontrollers_id']][] = [
                    'username' => (string) $account['username'],
                    'role'     => (string) $account['role'],
                    'enabled'  => (int) $account['is_enabled'],
                    'locked'   => (int) $account['is_locked'],
                    'default'  => Rules::isDefaultAccount((string) $account['username']),
                    'admin'    => Rules::isAdminRole((string) $account['role']),
                ];
            }
        }

        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')), 0, 10);
        $firmwares = self::firmwares($rows, $today);
        $matched = [];
        $controllers = [];
        foreach ($rows as $id => $row) {
            $computerId = (int) $row['computers_id'];
            $matchedBy = $computerId > 0 ? 'manual' : '';
            if ($computerId > 0 && !isset($computers[$computerId])) {
                // Serveur choisi à la main mais hors des entités visibles : non affiché.
                $computerId = 0;
                $matchedBy = '';
            }
            if ($computerId === 0) {
                $key = self::serialKey((string) $row['serial']);
                if ($key !== null && isset($bySerial[$key])) {
                    $computerId = $bySerial[$key];
                    $matchedBy = 'serial';
                }
            }
            if ($computerId > 0) {
                $matched[$computerId] = true;
            }
            $list = $accounts[$id] ?? [];
            $inAdmin = Rules::inAdminNetwork((string) $row['ip_address'], $networks);
            $firmware = $firmwares[$id] ?? [];
            $gaps = Rules::gaps($row, $list, $inAdmin, $computerId > 0, $today, BmcFirmwareRules::gaps($firmware));
            $controllers[] = [
                'id'          => (int) $id,
                'row'         => $row,
                'computer_id' => $computerId,
                'computer'    => $computerId > 0 ? $computers[$computerId] : null,
                'matched_by'  => $matchedBy,
                'accounts'    => $list,
                'firmwares'   => $firmware,
                'in_admin'    => $inAdmin,
                'gaps'        => $gaps,
                'high'        => Rules::hasHighGap($gaps),
            ];
        }

        $without = [];
        $physical = 0;
        foreach ($computers as $computer) {
            if (!$computer['is_server'] || $computer['is_virtual']) {
                continue;
            }
            $physical++;
            if (!isset($matched[(int) $computer['id']])) {
                $without[] = $computer;
            }
        }
        usort($without, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return [
            'controllers'      => $controllers,
            'servers_without'  => $without,
            'physical_servers' => $physical,
            'admin_networks'   => count($networks),
        ];
    }

    /**
     * Lot 2 : firmware du contrôleur et BIOS de chaque contrôleur, rapprochés des vulnérabilités
     * du parc (#780) et des fins de support (#782) déjà calculées par ces modules (mêmes alias,
     * même cache, aucun appel extérieur).
     *
     * @param array<int, array<string, mixed>> $rows contrôleurs par identifiant
     * @return array<int, list<array{part: string, name: string, version: string, cves: int, priority: int,
     *     top_cves: list<string>, eol_status: string, eol: string, cycle: string, product: string,
     *     watched: bool}>>
     */
    private static function firmwares(array $rows, string $today): array
    {
        $items = [];
        foreach ($rows as $id => $row) {
            foreach (BmcFirmwareRules::items($row) as $item) {
                $items[$id . ':' . $item['part']] = $item + ['controller_id' => (int) $id];
            }
        }
        if ($items === []) {
            return [];
        }
        $cves = ParkVulnerability::getForFirmwares($items);
        $eol = [];
        if (EndOfLifeStore::isEnabled()) {
            $eol = EndOfLifeInventory::evaluate(array_map(static fn (array $item): array => [
                'kind'      => ProductMatcher::KIND_FIRMWARE,
                'itemtype'  => self::class,
                'items_id'  => $item['controller_id'],
                'item_name' => '',
                'name'      => $item['name'],
                'version'   => $item['version'],
            ], $items), $today);
        }
        $result = [];
        foreach ($items as $key => $item) {
            $list = $cves[$key]['cves'] ?? [];
            $evaluation = $eol[$key]['evaluation'] ?? null;
            $result[$item['controller_id']][] = [
                'part'       => $item['part'],
                'name'       => $item['name'],
                'version'    => $item['version'],
                'cves'       => count($list),
                'priority'   => count(array_filter($list, static fn (array $cve): bool => (bool) $cve['priority'])),
                'top_cves'   => array_map(
                    static fn (array $c): string => (string) $c['cve_id'],
                    array_slice($list, 0, 3)
                ),
                'watched'    => $cves[$key]['watched'] ?? false,
                'eol_status' => $evaluation !== null && $evaluation['product'] !== ''
                    ? $evaluation['status'] : CycleMatcher::STATUS_UNKNOWN,
                'eol'        => (string) ($evaluation['eol'] ?? ''),
                'cycle'      => (string) ($evaluation['cycle'] ?? ''),
                'product'    => (string) ($evaluation['product'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        return self::$memo ??= self::compute(null);
    }

    /**
     * Synthèse : contrôleurs, écarts de gravité haute, comptes, serveurs sans contrôleur.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    public static function summary(?array $data = null): array
    {
        $data ??= self::data();
        $gaps = array_fill_keys(array_keys(Rules::GAPS), 0);
        $high = 0;
        $read = 0;
        $accounts = 0;
        $admins = 0;
        $defaults = 0;
        foreach ($data['controllers'] as $controller) {
            foreach ($controller['gaps'] as $gap) {
                $gaps[$gap]++;
            }
            if ($controller['high']) {
                $high++;
            }
            if ((string) $controller['row']['date_read'] !== '') {
                $read++;
            }
            foreach ($controller['accounts'] as $account) {
                if ((int) $account['enabled'] === 0) {
                    continue;
                }
                $accounts++;
                if ($account['admin']) {
                    $admins++;
                }
                if ($account['default']) {
                    $defaults++;
                }
            }
        }
        $total = count($data['controllers']);
        $physical = (int) $data['physical_servers'];
        $without = count($data['servers_without']);

        return [
            'controllers'      => $total,
            'read'             => $read,
            'high'             => $high,
            'gaps'             => $gaps,
            'accounts'         => $accounts,
            'admin_accounts'   => $admins,
            'default_accounts' => $defaults,
            'physical_servers' => $physical,
            'servers_without'  => $without,
            'coverage'         => $physical > 0 ? (int) round(100 * ($physical - $without) / $physical) : null,
            'admin_networks'   => (int) $data['admin_networks'],
        ];
    }

    /**
     * KRI : contrôleurs du périmètre avec au moins un écart de gravité haute (null sans contrôleur).
     *
     * @param list<int> $perimeter
     */
    public static function riskyControllers(array $perimeter): ?float
    {
        $data = self::compute($perimeter);
        if ($data['controllers'] === []) {
            return null;
        }

        return (float) self::summary($data)['high'];
    }

    // ------------------------------------------------------------------ Affichage.

    private static function tristateLabel(int $value): string
    {
        return match ($value) {
            1       => __('Yes'),
            0       => __('No'),
            default => __('Unknown', 'grcmanager'),
        };
    }

    /**
     * Contrôleur prêt pour le gabarit (le mot de passe n'en fait jamais partie).
     *
     * @param array<string, mixed> $controller
     * @return array<string, mixed>
     */
    private static function decorate(array $controller): array
    {
        $row = $controller['row'];
        $computer = $controller['computer'];

        return [
            'id'               => $controller['id'],
            'name'             => (string) $row['name'],
            'source'           => (string) $row['source'],
            'is_redfish'       => (string) $row['source'] === Rules::SOURCE_REDFISH,
            'url'              => (string) $row['url'],
            'username'         => (string) $row['username'],
            'has_password'     => (string) ($row['password'] ?? '') !== '',
            'allow_self_signed' => (int) $row['allow_self_signed'] === 1,
            'computers_id'     => (int) $row['computers_id'],
            'ip_address'       => (string) $row['ip_address'],
            'vendor'           => (string) $row['vendor'],
            'manager_model'    => (string) $row['manager_model'],
            'firmware_version' => (string) $row['firmware_version'],
            'bios_version'     => (string) $row['bios_version'],
            'serial'           => (string) $row['serial'],
            'system_model'     => (string) $row['system_model'],
            'health'           => (string) $row['health'],
            'power_state'      => (string) $row['power_state'],
            'ipmi_lan'         => self::tristateLabel((int) $row['ipmi_lan']),
            'secure_boot'      => self::tristateLabel((int) $row['secure_boot']),
            'cert_expiry'      => (string) ($row['cert_expiry'] ?? '') !== ''
                ? (string) Html::convDate((string) $row['cert_expiry']) : '',
            'date_read'        => (string) ($row['date_read'] ?? '') !== ''
                ? (string) Html::convDateTime((string) $row['date_read']) : '',
            'read_error'       => (string) $row['read_error'] !== ''
                ? self::errorMessage((string) $row['read_error']) : '',
            'accounts_read'    => (int) $row['accounts_read'] === 1,
            'computer'         => $computer === null ? null : [
                'name' => (string) $computer['name'] !== '' ? (string) $computer['name'] : '#' . (int) $computer['id'],
                'url'  => Computer::getFormURLWithID((int) $computer['id']),
            ],
            'matched_by'       => $controller['matched_by'],
            'in_admin'         => $controller['in_admin'],
            'accounts'         => $controller['accounts'],
            'firmwares'        => array_map(static fn (array $f): array => $f + [
                'part_label' => $f['part'] === BmcFirmwareRules::PART_BIOS
                    ? __('BIOS', 'grcmanager') : __('Controller', 'grcmanager'),
                'eol_info'   => EndOfLife::statusInfo($f['eol_status']),
                'eol_date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['eol']) === 1
                    ? (string) Html::convDate($f['eol']) : '',
            ], $controller['firmwares'] ?? []),
            'gaps'             => $controller['gaps'],
            'high'             => $controller['high'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(int $editId = 0): array
    {
        global $CFG_GLPI;

        $data = self::data();
        $controllers = $data['controllers'];
        usort(
            $controllers,
            static fn (array $a, array $b): int => [!$a['high'], -count($a['gaps']), (string) $a['row']['name']]
                <=> [!$b['high'], -count($b['gaps']), (string) $b['row']['name']]
        );
        $edit = null;
        if ($editId > 0 && self::canManage()) {
            $row = self::controller($editId);
            if ($row !== null && (string) $row['source'] === Rules::SOURCE_REDFISH) {
                $edit = [
                    'id'                => (int) $row['id'],
                    'name'              => (string) $row['name'],
                    'url'               => (string) $row['url'],
                    'username'          => (string) $row['username'],
                    'has_password'      => (string) ($row['password'] ?? '') !== '',
                    'allow_self_signed' => (int) $row['allow_self_signed'] === 1,
                    'computers_id'      => (int) $row['computers_id'],
                ];
            }
        }
        $without = $data['servers_without'];

        return [
            'summary'         => self::summary($data),
            'gap_infos'       => self::gapInfos(),
            'rows'            => array_map(self::decorate(...), array_slice($controllers, 0, self::MAX_ROWS)),
            'total_rows'      => count($controllers),
            'servers_without' => array_map(static fn (array $c): array => [
                'name'  => (string) $c['name'] !== '' ? (string) $c['name'] : '#' . (int) $c['id'],
                'url'   => Computer::getFormURLWithID((int) $c['id']),
                'type'  => (string) $c['type'],
                'model' => (string) $c['model'],
                'serial' => (string) $c['serial'],
            ], array_slice($without, 0, self::MAX_ROWS)),
            'total_without'   => count($without),
            'edit'            => $edit,
            'can_manage'      => self::canManage(),
            'allow_private'   => Http::allowsPrivateNetworks(),
            'glpi12_hint'     => Http::glpi12ContextHint(),
            'action'          => self::getSearchURL(),
            'config_url'      => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grc-outgoing-http',
            'flows_url'       => NetworkFlow::getSearchURL(),
            // Lot 2 : vulnérabilités du parc (droit sensible) et fins de support.
            'fleet_url'       => ParkVulnerability::canView() ? ParkVulnerability::getSearchURL() : '',
            'eol_enabled'     => EndOfLifeStore::isEnabled(),
            'eol_config_url'  => EndOfLife::canView() ? EndOfLife::getConfigURL() : '',
            'entity_name'     => (string) Dropdown::getDropdownName('glpi_entities', (int) Session::getActiveEntity()),
            'max_bytes_label' => sprintf('%d MB', (int) (Rules::MAX_BYTES / 1024 / 1024)),
        ];
    }

    public static function showPage(int $editId = 0): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/server_management.html.twig', self::pageData($editId));
    }

    // ------------------------------------------------------------------ Onglet sur l'ordinateur.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Computer || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return self::createTabEntry(__('Management controller', 'grcmanager'), 0, $item::class, self::getIcon());
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : les droits sont
     * vérifiés ici aussi.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Computer || $item->isNewItem() || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/server_management_item_tab.html.twig',
            self::tabData($item)
        );

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function tabData(CommonDBTM $item): array
    {
        $id = (int) $item->getID();
        $rows = [];
        foreach (self::data()['controllers'] as $controller) {
            if ((int) $controller['computer_id'] === $id) {
                $rows[] = self::decorate($controller);
            }
        }

        return [
            'rows'      => $rows,
            'gap_infos' => self::gapInfos(),
            'page_url'  => self::getSearchURL(),
        ];
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuve des mesures A.8.2 (comptes des contrôleurs), A.8.9 (configuration) et A.8.22
     * (cloisonnement). Null si la mesure n'est pas concernée, sans les droits ou sans contrôleur.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.2', 'A.8.9', 'A.8.22'], true) || !self::canView()) {
            return null;
        }
        $summary = self::summary();
        if ($summary['controllers'] === 0) {
            return null;
        }
        $config = 0;
        foreach (
            [Rules::GAP_IPMI_LAN, Rules::GAP_TELNET, Rules::GAP_SNMP_V1V2, Rules::GAP_WEAK_TLS,
                Rules::GAP_CERT_EXPIRED, Rules::GAP_CERT_SELF_SIGNED, Rules::GAP_SECURE_BOOT_OFF] as $gap
        ) {
            $config += $summary['gaps'][$gap];
        }

        return [
            'code'             => $code,
            'controllers'      => $summary['controllers'],
            'high'             => $summary['high'],
            'config_gaps'      => $config,
            'outside_admin'    => $summary['gaps'][Rules::GAP_OUTSIDE_ADMIN_NETWORK],
            'admin_networks'   => $summary['admin_networks'],
            'admin_accounts'   => $summary['admin_accounts'],
            'default_accounts' => $summary['gaps'][Rules::GAP_DEFAULT_ACCOUNT],
            'servers_without'  => $summary['servers_without'],
            'date'             => (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'url'              => self::getSearchURL(),
        ];
    }
}
