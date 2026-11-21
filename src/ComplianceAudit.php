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

namespace GlpiPlugin\Grcmanager;

use Change;
use Change_Ticket;
use ChangeValidation;
use CommonGLPI;
use CommonITILValidation;
use Computer;
use ComputerType;
use Config;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore;
use GlpiPlugin\Grcmanager\Services\Compliance\DiskEncryptionRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use Html;
use Item_Disk;
use Location;
use Item_Ticket;
use Session;
use State;
use Ticket;
use User;

/**
 * Issue #162 : audit de conformité technique (ISO 27001, NIS2, RGPD) à partir de l'inventaire et
 * des processus de GLPI : postes sans antivirus à jour, disques non chiffrés, postes non
 * inventoriés, changements clos sans validation approuvée, comptes à vérifier. Chaque contrôle
 * liste ses écarts ; un ticket de mise en conformité est proposé, jamais créé automatiquement, et
 * un seul reste ouvert par contrôle et par entité. Ancre sans table, comme Maturity.
 *
 * Chaque contrôle n'est évalué que si la personne a le droit natif de lecture des éléments
 * concernés (ordinateurs, changements, utilisateurs).
 */
class ComplianceAudit extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const TICKETS_TABLE = 'glpi_plugin_grcmanager_complianceaudittickets';

    /** Un poste non inventorié depuis ce nombre de jours est un écart. */
    public const INVENTORY_DAYS = 30;

    /** Clé de configuration (contexte plugin:grcmanager) : statuts exclus, liste JSON d'identifiants. */
    public const EXCLUDED_STATES_KEY = 'compliance_excluded_states';

    /**
     * Issue #847 : clé de configuration (contexte plugin:grcmanager) de la règle « doit être
     * chiffré » (JSON, DiskEncryptionRules::normalizeRule()).
     */
    public const ENCRYPTION_RULE_KEY = 'disk_encryption_rule';

    /** Contrôles calculés sur les ordinateurs (droit natif de lecture des ordinateurs). */
    private const COMPUTER_CHECKS = ['antivirus', 'encryption', 'laptop_encryption', 'inventory'];

    /** Changements clos sur cette période (jours) contrôlés. */
    public const CHANGE_PERIOD_DAYS = 365;

    /** Comptes sans connexion depuis ce nombre de jours (hygiène des comptes). */
    public const ACCOUNT_DAYS = 90;

    /** Écarts liés au ticket de mise en conformité, au plus. */
    private const TICKET_ITEMS_MAX = 100;

    public static function getTypeName($nb = 0)
    {
        return __('Automated technical checks', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/complianceaudit.php';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
    }

    /**
     * Rubrique « Sécurité technique » : contrôles automatiques, vulnérabilités du parc et matrice
     * des flux (Menu::sections()).
     */
    public static function getMenuContent()
    {
        return Menu::withSubPages(self::class, [
            'title'         => __('Technical security', 'grcmanager'),
            'section_label' => self::getTypeName(),
            'page'          => self::getSearchURL(false),
            'icon'          => self::getIcon(),
        ]);
    }

    /**
     * @return array<string, array{label: string, help: string}>
     */
    public static function getCheckLabels(int $approvals = 1): array
    {
        return [
            'antivirus'         => [
                'label' => __('Computers without an active, up-to-date antivirus', 'grcmanager'),
                'help'  => __(
                    'From the inventory ("Antivirus" tab of computers): no antivirus, or an antivirus that is '
                        . 'inactive, out of date or expired.',
                    'grcmanager'
                ),
            ],
            'encryption'        => [
                'label' => __('Computers with an unencrypted disk', 'grcmanager'),
                'help'  => __(
                    'From the volume inventory: at least one unencrypted or partially encrypted disk, or a weak '
                        . 'encryption algorithm, on a computer that must be encrypted ("Disk encryption" page). Only '
                        . 'computers whose volumes are inventoried are counted. Volumes without a drive letter (EFI, '
                        . 'recovery), network shares, optical media, FAT partitions and, on Linux, /boot and '
                        . 'pseudo-filesystems are ignored.',
                    'grcmanager'
                ),
            ],
            // Issue #847 : portables (type d'ordinateur), quelle que soit la règle réglée (A.8.1).
            'laptop_encryption' => [
                'label' => __('Laptops with an unencrypted disk', 'grcmanager'),
                'help'  => __(
                    'Same check, limited to laptops and tablets (computer type reported by the inventory '
                        . 'agent, such as Laptop or Notebook), which are the most exposed outside the premises.',
                    'grcmanager'
                ),
            ],
            'inventory'         => [
                'label' => sprintf(
                    __('Computers not inventoried for more than %d days', 'grcmanager'),
                    self::INVENTORY_DAYS
                ),
                'help'  => __(
                    'Computers reported by the inventory agent whose last report is old: the asset inventory is no '
                        . 'longer reliable for them.',
                    'grcmanager'
                ),
            ],
            'change_validation' => [
                'label' => sprintf(
                    _n(
                        'Closed changes without an approved validation',
                        'Closed changes without %d approved validations',
                        $approvals,
                        'grcmanager'
                    ),
                    $approvals
                ),
                'help'  => __(
                    'Changes solved or closed within the last year with fewer approved validations than required.',
                    'grcmanager'
                ),
            ],
            'accounts'          => [
                'label' => _x('to check', 'Accounts to review', 'grcmanager'),
                'help'  => sprintf(
                    __(
                        'Active accounts deleted from the directory, expired, duplicated or without login for %d '
                            . 'days ("Account hygiene" screen).',
                        'grcmanager'
                    ),
                    self::ACCOUNT_DAYS
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getFrameworkLabels(): array
    {
        // Issue #403 : ISO 27001 et NIS2 seulement s'ils sont actifs (le RGPD n'est pas un
        // référentiel activable : obligation légale).
        $labels = [];
        if (FrameworkConfig::isEnabled(FrameworkSelection::ISO27001)) {
            $labels[ComplianceAuditScore::ISO27001] = 'ISO/IEC 27001';
        }
        if (FrameworkConfig::isEnabled(FrameworkSelection::NIS2)) {
            $labels[ComplianceAuditScore::NIS2] = 'NIS2';
        }
        $labels[ComplianceAuditScore::GDPR] = __('GDPR', 'grcmanager');

        return $labels;
    }

    /**
     * Références d'un contrôle dans les référentiels actifs (« ISO/IEC 27001 A.8.7 »...).
     *
     * @return list<string>
     */
    public static function getCheckReferences(string $check): array
    {
        $labels = self::getFrameworkLabels();
        $references = [];
        foreach (ComplianceAuditScore::CHECKS[$check] ?? [] as $framework => $reference) {
            if (isset($labels[$framework])) {
                $references[] = $labels[$framework] . ' ' . $reference;
            }
        }

        return $references;
    }

    /**
     * Tous les contrôles, dans l'ordre d'affichage.
     *
     * @return array<string, array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}>
     */
    public static function run(int $approvals = 1, ?string $now = null): array
    {
        $now ??= Session::getCurrentTime();
        $computers = Computer::canView() ? self::computers() : null;

        return [
            'antivirus'         => $computers === null ? self::notEvaluated() : self::antivirus($computers, $now),
            'encryption'        => $computers === null ? self::notEvaluated() : self::encryption($computers),
            'laptop_encryption' => $computers === null ? self::notEvaluated() : self::laptopEncryption($computers),
            'inventory'         => $computers === null ? self::notEvaluated() : self::inventory($computers, $now),
            // Liste (et lie au ticket) tous les changements des entités : réservé à qui voit tous
            // les changements. Change::canView() est vrai aussi avec « mes changements » ou un
            // droit de validation (revue de sécurité 2.11.0).
            'change_validation' => Session::haveRight(Change::$rightname, Change::READALL)
                ? self::changeValidation($approvals, $now)
                : self::notEvaluated(),
            'accounts'          => AccountHygiene::canView()
                ? self::accounts($now)
                : self::notEvaluated(),
        ];
    }

    /**
     * Issue #380 : un seul contrôle, avec les mêmes droits et le même périmètre que run() (test
     * d'efficacité alimenté par ce contrôle). Contrôle inconnu : non évalué.
     *
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    public static function runCheck(string $check, int $approvals = 1, ?string $now = null): array
    {
        $now ??= Session::getCurrentTime();
        if (in_array($check, self::COMPUTER_CHECKS, true)) {
            if (!Computer::canView()) {
                return self::notEvaluated();
            }
            $computers = self::computers();

            return match ($check) {
                'antivirus'         => self::antivirus($computers, $now),
                'encryption'        => self::encryption($computers),
                'laptop_encryption' => self::laptopEncryption($computers),
                default             => self::inventory($computers, $now),
            };
        }
        if ($check === 'change_validation') {
            return Session::haveRight(Change::$rightname, Change::READALL)
                ? self::changeValidation($approvals, $now)
                : self::notEvaluated();
        }
        if ($check === 'accounts') {
            return AccountHygiene::canView() ? self::accounts($now) : self::notEvaluated();
        }

        return self::notEvaluated();
    }

    /**
     * Issue #378 : part des ordinateurs en écart (en %) pour un contrôle technique (antivirus ou
     * chiffrement) sur les entités données, pour alimenter un indicateur de risque. Null si aucun
     * ordinateur n'est concerné. L'appelant vérifie les droits (RiskIndicator::canUseSource()).
     *
     * @param list<int> $entityIds
     */
    public static function gapRate(string $check, array $entityIds): ?float
    {
        if (!in_array($check, ['antivirus', 'encryption', 'laptop_encryption'], true) || $entityIds === []) {
            return null;
        }
        $computers = self::computers([Computer::getTable() . '.entities_id' => $entityIds]);
        $result = match ($check) {
            'antivirus'  => self::antivirus($computers, Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'encryption' => self::encryption($computers),
            default      => self::laptopEncryption($computers),
        };

        return KriRules::rate(count($result['gaps']), $result['total']);
    }

    /**
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function notEvaluated(): array
    {
        return ['evaluated' => false, 'total' => 0, 'gaps' => []];
    }

    /**
     * Issue #305 : statuts d'ordinateur exclus des contrôles techniques (réformé, en stock…).
     *
     * @return list<int>
     */
    public static function getExcludedStateIds(): array
    {
        $values = Config::getConfigurationValues(
            AuditTeam::CONFIG_CONTEXT,
            [self::EXCLUDED_STATES_KEY]
        );
        $ids = json_decode((string) ($values[self::EXCLUDED_STATES_KEY] ?? ''), true);

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param array<mixed> $ids statuts soumis par le formulaire ; seuls les statuts existants sont gardés
     */
    public static function setExcludedStateIds(array $ids): void
    {
        global $DB;

        $ids = array_values(array_unique(array_filter(
            array_map('intval', array_filter($ids, 'is_scalar')),
            static fn (int $id): bool => $id > 0
        )));
        $existing = [];
        if ($ids !== []) {
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => State::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
                $existing[] = (int) $row['id'];
            }
            sort($existing);
        }
        $ids = $existing;
        Config::setConfigurationValues(
            AuditTeam::CONFIG_CONTEXT,
            [self::EXCLUDED_STATES_KEY => json_encode($ids)]
        );
    }

    /**
     * Ordinateurs visibles (entités actives, hors corbeille, gabarits et statuts exclus).
     * Issue #378 : un indicateur de risque passe à la place le périmètre de son entité.
     * Issue #854 : relu par le retard des correctifs (PatchCompliance), mêmes exclusions.
     *
     * @param array<string, mixed>|null $entityCriteria
     * @return array<int, array<string, mixed>>
     */
    public static function computers(?array $entityCriteria = null): array
    {
        global $DB;

        $where = ['is_deleted' => 0, 'is_template' => 0];
        $excluded = self::getExcludedStateIds();
        if ($excluded !== []) {
            $where['NOT'] = ['states_id' => $excluded];
        }
        $computers = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'is_dynamic', 'last_inventory_update', 'entities_id', 'computertypes_id',
                    'locations_id',
                ],
                'FROM'   => Computer::getTable(),
                'WHERE'  => $where
                    + ($entityCriteria ?? getEntitiesRestrictCriteria(Computer::getTable(), '', '', true)),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $computers[(int) $row['id']] = $row;
        }

        return $computers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function gap(string $itemtype, int $id, string $name, string $detail): array
    {
        return [
            'itemtype' => $itemtype,
            'id'       => $id,
            'name'     => $name !== '' ? $name : sprintf('%s %d', $itemtype::getTypeName(1), $id),
            'url'      => $itemtype::getFormURLWithID($id),
            'detail'   => $detail,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $computers
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function antivirus(array $computers, string $now): array
    {
        global $DB;

        $found = [];
        $compliant = [];
        if ($computers !== []) {
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_itemantiviruses',
                    'WHERE' => ['itemtype' => Computer::class, 'items_id' => array_keys($computers), 'is_deleted' => 0],
                ]) as $row
            ) {
                $found[(int) $row['items_id']] = true;
                if (ComplianceAuditScore::isAntivirusCompliant($row, substr($now, 0, 10))) {
                    $compliant[(int) $row['items_id']] = true;
                }
            }
        }
        $gaps = [];
        foreach ($computers as $id => $computer) {
            if (!isset($compliant[$id])) {
                $gaps[] = self::gap(Computer::class, $id, (string) $computer['name'], isset($found[$id])
                    ? __('Antivirus inactive, out of date or expired', 'grcmanager')
                    : __('No antivirus inventoried', 'grcmanager'));
            }
        }

        return ['evaluated' => true, 'total' => count($computers), 'gaps' => $gaps];
    }

    /**
     * Issue #847 : règle « doit être chiffré » (tous les ordinateurs par défaut).
     *
     * @return array{scope: string, types: list<int>, confidentiality: string}
     */
    public static function getEncryptionRule(): array
    {
        $values = Config::getConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::ENCRYPTION_RULE_KEY]);

        return DiskEncryptionRules::normalizeRule(
            json_decode((string) ($values[self::ENCRYPTION_RULE_KEY] ?? ''), true)
        );
    }

    /**
     * @param array<mixed> $rule valeurs soumises ; seuls les types d'ordinateur existants sont gardés
     * @return array{scope: string, types: list<int>, confidentiality: string} règle enregistrée
     */
    public static function setEncryptionRule(array $rule): array
    {
        global $DB;

        $rule = DiskEncryptionRules::normalizeRule($rule);
        $existing = [];
        if ($rule['types'] !== []) {
            foreach (
                $DB->request([
                    'SELECT' => 'id',
                    'FROM'   => ComputerType::getTable(),
                    'WHERE'  => ['id' => $rule['types']],
                ]) as $row
            ) {
                $existing[] = (int) $row['id'];
            }
            sort($existing);
        }
        $rule['types'] = $existing;
        Config::setConfigurationValues(
            AuditTeam::CONFIG_CONTEXT,
            [self::ENCRYPTION_RULE_KEY => json_encode($rule)]
        );

        return $rule;
    }

    /**
     * Issue #847 : chiffrement de chaque ordinateur visible (mêmes exclusions que les contrôles), pour
     * la page « Chiffrement des disques ». L'appelant vérifie le droit de lecture des ordinateurs.
     *
     * @param array<string, mixed>|null $entityCriteria
     * @return list<array<string, mixed>>
     */
    public static function diskEncryptionRows(?array $entityCriteria = null): array
    {
        return self::encryptionRows(self::computers($entityCriteria));
    }

    /**
     * Une ligne par ordinateur : état d'après ses volumes chiffrables, volumes en clair, outils et
     * algorithmes déclarés par l'inventaire, portable ou non, concerné ou non par la règle. Seuls
     * l'état, l'outil et l'algorithme de chiffrement sont lus : jamais de clé.
     *
     * @param array<int, array<string, mixed>> $computers
     * @return list<array<string, mixed>>
     */
    private static function encryptionRows(array $computers): array
    {
        global $DB;

        if ($computers === []) {
            return [];
        }
        $ids = array_keys($computers);
        $disks = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_items_disks.items_id',
                    'glpi_items_disks.name',
                    'glpi_items_disks.mountpoint',
                    'glpi_items_disks.totalsize',
                    'glpi_items_disks.is_dynamic',
                    'glpi_items_disks.encryption_status',
                    'glpi_items_disks.encryption_tool',
                    'glpi_items_disks.encryption_algorithm',
                    'glpi_filesystems.name AS filesystem',
                ],
                'FROM'      => 'glpi_items_disks',
                'LEFT JOIN' => [
                    'glpi_filesystems' => [
                        'ON' => ['glpi_items_disks' => 'filesystems_id', 'glpi_filesystems' => 'id'],
                    ],
                ],
                'WHERE'     => [
                    'glpi_items_disks.itemtype'   => Computer::class,
                    'glpi_items_disks.items_id'   => $ids,
                    'glpi_items_disks.is_deleted' => 0,
                ],
                'ORDER'     => ['glpi_items_disks.mountpoint', 'glpi_items_disks.name'],
            ]) as $row
        ) {
            if (
                ComplianceAuditScore::isEncryptableVolume(
                    (string) $row['mountpoint'],
                    (string) $row['filesystem'],
                    (int) $row['totalsize'],
                    (bool) $row['is_dynamic']
                )
            ) {
                $disks[(int) $row['items_id']][] = $row;
            }
        }
        $types = [];
        $typeIds = array_values(array_unique(array_filter(array_map(
            static fn (array $computer): int => (int) ($computer['computertypes_id'] ?? 0),
            $computers
        ))));
        if ($typeIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => ComputerType::getTable(),
                    'WHERE'  => ['id' => $typeIds],
                ]) as $row
            ) {
                $types[(int) $row['id']] = (string) $row['name'];
            }
        }
        $confidentiality = [];
        foreach (
            $DB->request([
                'SELECT' => ['items_id', 'confidentiality'],
                'FROM'   => AssetClassification::getTable(),
                'WHERE'  => ['itemtype' => Computer::class, 'items_id' => $ids],
            ]) as $row
        ) {
            $confidentiality[(int) $row['items_id']] = (string) $row['confidentiality'];
        }
        $rule = self::getEncryptionRule();
        $entities = [];
        $locations = [];
        $rows = [];
        foreach ($computers as $id => $computer) {
            $volumes = $disks[$id] ?? [];
            $clear = [];
            $tools = [];
            $algorithms = [];
            foreach ($volumes as $volume) {
                if ((int) $volume['encryption_status'] !== Item_Disk::ENCRYPTION_STATUS_YES) {
                    $clear[] = (string) ($volume['mountpoint'] ?: $volume['name']);
                }
                if ((int) $volume['encryption_status'] !== Item_Disk::ENCRYPTION_STATUS_NO) {
                    $tool = trim((string) ($volume['encryption_tool'] ?? ''));
                    $algorithm = trim((string) ($volume['encryption_algorithm'] ?? ''));
                    if ($tool !== '') {
                        $tools[$tool] = $tool;
                    }
                    if ($algorithm !== '') {
                        $algorithms[$algorithm] = $algorithm;
                    }
                }
            }
            $status = DiskEncryptionRules::computerStatus(array_map(
                static fn (array $volume): int => (int) $volume['encryption_status'],
                $volumes
            ));
            $typeId = (int) ($computer['computertypes_id'] ?? 0);
            $typeName = $types[$typeId] ?? '';
            $isLaptop = DiskEncryptionRules::isLaptopType($typeName);
            $entityId = (int) ($computer['entities_id'] ?? 0);
            $locationId = (int) ($computer['locations_id'] ?? 0);
            $entities[$entityId] ??= (string) Dropdown::getDropdownName('glpi_entities', $entityId);
            $locations[$locationId] ??= $locationId > 0
                ? (string) Dropdown::getDropdownName(Location::getTable(), $locationId)
                : '';
            $strength = DiskEncryptionRules::weakestStrength(array_values($algorithms));
            $required = DiskEncryptionRules::isRequired($rule, $isLaptop, $typeId, $confidentiality[$id] ?? '');
            $name = (string) $computer['name'];
            $rows[] = [
                'id'             => $id,
                'name'           => $name !== '' ? $name : sprintf('%s %d', Computer::getTypeName(1), $id),
                'url'            => Computer::getFormURLWithID($id),
                'entities_id'    => $entityId,
                'entity'         => $entities[$entityId],
                'type_id'        => $typeId,
                'type'           => $typeName,
                'location'       => $locations[$locationId],
                'is_laptop'      => $isLaptop,
                'required'       => $required,
                'status'         => $status,
                'clear_volumes'  => $clear,
                'tools'          => array_values($tools),
                'algorithms'     => array_values($algorithms),
                'strength'       => $strength,
                'is_gap'         => DiskEncryptionRules::isGap($required, $status, $strength),
                'last_inventory' => (string) ($computer['last_inventory_update'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Détail d'un écart de chiffrement : volumes en clair, algorithme faible.
     *
     * @param array<string, mixed> $row ligne de encryptionRows()
     */
    private static function encryptionGapDetail(array $row): string
    {
        $parts = [];
        if ($row['clear_volumes'] !== []) {
            $parts[] = sprintf(__('Not encrypted: %s', 'grcmanager'), implode(', ', $row['clear_volumes']));
        }
        if ($row['strength'] === DiskEncryptionRules::ALGO_WEAK) {
            $parts[] = sprintf(
                __('Weak encryption algorithm: %s', 'grcmanager'),
                implode(', ', $row['algorithms'])
            );
        }

        return implode('; ', $parts);
    }

    /**
     * Ordinateurs concernés par la règle et dont les volumes sont inventoriés ; écart : volume en
     * clair ou algorithme faible.
     *
     * @param array<int, array<string, mixed>> $computers
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function encryption(array $computers): array
    {
        return self::encryptionResult(
            self::encryptionRows($computers),
            static fn (array $row): bool => (bool) $row['required']
        );
    }

    /**
     * Issue #847 : portables et tablettes, quelle que soit la règle (mesure A.8.1).
     *
     * @param array<int, array<string, mixed>> $computers
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function laptopEncryption(array $computers): array
    {
        return self::encryptionResult(
            self::encryptionRows($computers),
            static fn (array $row): bool => (bool) $row['is_laptop']
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): bool $concerned
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function encryptionResult(array $rows, callable $concerned): array
    {
        $total = 0;
        $gaps = [];
        foreach ($rows as $row) {
            if (!$concerned($row) || $row['status'] === DiskEncryptionRules::STATUS_UNKNOWN) {
                continue;
            }
            $total++;
            if (DiskEncryptionRules::isGap(true, (string) $row['status'], (string) $row['strength'])) {
                $gaps[] = self::gap(
                    Computer::class,
                    (int) $row['id'],
                    (string) $row['name'],
                    self::encryptionGapDetail($row)
                );
            }
        }

        return ['evaluated' => true, 'total' => $total, 'gaps' => $gaps];
    }

    /**
     * @param array<int, array<string, mixed>> $computers
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function inventory(array $computers, string $now): array
    {
        $limit = date('Y-m-d H:i:s', (int) strtotime($now) - self::INVENTORY_DAYS * DAY_TIMESTAMP);
        $total = 0;
        $gaps = [];
        foreach ($computers as $id => $computer) {
            if (empty($computer['is_dynamic'])) {
                continue;
            }
            $total++;
            $last = (string) ($computer['last_inventory_update'] ?? '');
            if ($last === '' || $last < $limit) {
                $gaps[] = self::gap(Computer::class, $id, (string) $computer['name'], $last === ''
                    ? __('Never inventoried', 'grcmanager')
                    : sprintf(__('Last inventory: %s', 'grcmanager'), Html::convDateTime($last)));
            }
        }

        return ['evaluated' => true, 'total' => $total, 'gaps' => $gaps];
    }

    /**
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function changeValidation(int $approvals, string $now): array
    {
        global $DB;

        $since = date('Y-m-d H:i:s', (int) strtotime($now) - self::CHANGE_PERIOD_DAYS * DAY_TIMESTAMP);
        $changes = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => Change::getTable(),
                'WHERE'  => [
                    'is_deleted' => 0,
                    'status'     => array_merge(Change::getSolvedStatusArray(), Change::getClosedStatusArray()),
                    'OR'         => [['solvedate' => ['>=', $since]], ['closedate' => ['>=', $since]]],
                ] + getEntitiesRestrictCriteria(Change::getTable(), '', '', true),
                'ORDER'  => 'id DESC',
            ]) as $row
        ) {
            $changes[(int) $row['id']] = (string) $row['name'];
        }
        $accepted = [];
        if ($changes !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['changes_id'],
                    'FROM'   => ChangeValidation::getTable(),
                    'WHERE'  => ['changes_id' => array_keys($changes), 'status' => CommonITILValidation::ACCEPTED],
                ]) as $row
            ) {
                $accepted[(int) $row['changes_id']] = ($accepted[(int) $row['changes_id']] ?? 0) + 1;
            }
        }
        $gaps = [];
        foreach ($changes as $id => $name) {
            $count = $accepted[$id] ?? 0;
            if ($count < $approvals) {
                $gaps[] = self::gap(Change::class, $id, $name, sprintf(
                    __('%1$d approved validation(s) out of %2$d required', 'grcmanager'),
                    $count,
                    $approvals
                ));
            }
        }

        return ['evaluated' => true, 'total' => count($changes), 'gaps' => $gaps];
    }

    /**
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function accounts(string $now): array
    {
        global $DB;

        $total = (int) $DB->request([
            'COUNT'      => 'cpt',
            'SELECT'     => 'glpi_users.id',
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => ['glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0]
                + getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true),
        ])->current()['cpt'];
        $gaps = [];
        foreach (AccountHygiene::findAccounts(self::ACCOUNT_DAYS, $now) as $account) {
            $gaps[] = self::gap(User::class, $account['id'], $account['fullname'], implode(', ', array_map(
                static fn (string $check): string => AccountHygiene::getCheckLabel(
                    $check,
                    self::ACCOUNT_DAYS
                ),
                $account['checks']
            )));
        }

        return ['evaluated' => true, 'total' => $total, 'gaps' => $gaps];
    }

    /**
     * Ticket de mise en conformité encore ouvert pour ce contrôle dans l'entité active.
     */
    public static function openTicket(string $check): ?Ticket
    {
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TICKETS_TABLE,
                'WHERE' => ['check_key' => $check, 'entities_id' => Session::getActiveEntity()],
                'ORDER' => 'id DESC',
            ]) as $row
        ) {
            $ticket = new Ticket();
            if (
                $ticket->getFromDB((int) $row['tickets_id'])
                && empty($ticket->fields['is_deleted'])
                && !in_array(
                    (int) $ticket->fields['status'],
                    array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()),
                    true
                )
            ) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Ticket de mise en conformité d'un contrôle : écarts recalculés au moment de la demande,
     * éléments liés au ticket. Un ticket déjà ouvert pour ce contrôle est réutilisé.
     *
     * @return int id du ticket, 0 si rien à faire ou refusé
     */
    public static function createTicket(string $check, int $approvals = 1): int
    {
        global $DB;

        if (
            !Session::haveRight(self::RIGHTNAME, UPDATE)
            || !Session::haveRight(Ticket::$rightname, CREATE)
            || !in_array(
                $check,
                ['antivirus', 'encryption', 'laptop_encryption', 'inventory', 'change_validation'],
                true
            )
        ) {
            return 0;
        }
        $existing = self::openTicket($check);
        if ($existing !== null) {
            return $existing->getID();
        }
        $result = self::run($approvals)[$check];
        if (!$result['evaluated'] || $result['gaps'] === []) {
            return 0;
        }
        $label = self::getCheckLabels($approvals)[$check]['label'];
        $references = self::getCheckReferences($check);
        $gaps = array_slice($result['gaps'], 0, self::TICKET_ITEMS_MAX);
        $ticketId = (int) (new Ticket())->add([
            'name'        => sprintf(
                __('Compliance remediation: %1$s (%2$d)', 'grcmanager'),
                $label,
                count($result['gaps'])
            ),
            'content'     => TemplateRenderer::getInstance()->render('@grcmanager/compliance_audit_ticket.html.twig', [
                'label'      => $label,
                'help'       => self::getCheckLabels($approvals)[$check]['help'],
                'references' => $references,
                'gaps'       => $gaps,
                'more'       => count($result['gaps']) - count($gaps),
            ]),
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => Session::getActiveEntity(),
        ]);
        if ($ticketId <= 0) {
            return 0;
        }
        foreach ($gaps as $gap) {
            // Sans notification par élément lié : le ticket a déjà notifié sa création.
            if ($gap['itemtype'] === Change::class) {
                (new Change_Ticket())->add([
                    'changes_id'    => $gap['id'],
                    'tickets_id'    => $ticketId,
                    '_disablenotif' => true,
                ]);
            } else {
                (new Item_Ticket())->add([
                    'itemtype'      => $gap['itemtype'],
                    'items_id'      => $gap['id'],
                    'tickets_id'    => $ticketId,
                    '_disablenotif' => true,
                ]);
            }
        }
        $DB->insert(self::TICKETS_TABLE, [
            'check_key'     => $check,
            'entities_id'   => Session::getActiveEntity(),
            'tickets_id'    => $ticketId,
            'date_creation' => Session::getCurrentTime(),
        ]);

        return $ticketId;
    }
}
