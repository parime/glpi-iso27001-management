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

use CommonGLPI;
use Computer;
use ComputerType;
use Config;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules as Rules;
use GlpiPlugin\Grcmanager\Services\Cve\OperatingSystemInventory;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use Html;
use Session;

/**
 * Issue #854 : retard des correctifs de sécurité des systèmes (A.8.8, A.8.19), d'après l'inventaire
 * natif de GLPI : système et version de chaque ordinateur, mises à jour installées que l'agent
 * remonte (articles KB de Windows, catégorie « mise à jour »), installations de paquets sous Linux et
 * macOS. Aucun nouveau connecteur.
 *
 * - Délai cible réglable par criticité, déduite de la classification C/I/D ; un ordinateur publié
 *   sur Internet (flux réseau déclaré) suit le délai critique et passe en tête.
 * - Rapprochement avec les vulnérabilités du système (#780) et la fin de support (#704).
 * - Preuve des mesures A.8.8 et A.8.19, KRI « serveurs critiques avec correctifs en retard », carte
 *   du tableau de bord.
 *
 * Lecture seule : le plugin ne lance aucune mise à jour et ne modifie rien dans GLPI. Droits : droit
 * du plugin et lecture native des ordinateurs, dans les entités de la session ; modification du
 * plugin pour les délais cibles.
 */
class PatchCompliance extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Clé de configuration des délais cibles (contexte AuditTeam::CONFIG_CONTEXT). */
    public const TARGETS_KEY = 'patch_lag_targets';

    /** Ordinateurs listés au plus sur la page (retards d'abord). */
    public const MAX_ROWS = 300;

    /** @var list<array<string, mixed>>|null lignes calculées pendant la requête */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('Security patches', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-refresh-alert';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/patchcompliance.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Computer::canView();
    }

    /**
     * Délais cibles.
     */
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

    /**
     * Oublie les lignes calculées (après un changement de délais, tests).
     */
    public static function reset(): void
    {
        self::$memo = null;
    }

    // ------------------------------------------------------------------ Réglages.

    /**
     * @return array<string, int>
     */
    public static function getTargets(): array
    {
        $values = Config::getConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::TARGETS_KEY]);

        return Rules::normalizeTargets(json_decode((string) ($values[self::TARGETS_KEY] ?? ''), true));
    }

    /**
     * @param array<mixed> $targets valeurs soumises
     * @return array<string, int> délais enregistrés
     */
    public static function setTargets(array $targets): array
    {
        $targets = Rules::normalizeTargets($targets);
        Config::setConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::TARGETS_KEY => json_encode($targets)]);
        self::reset();

        return $targets;
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_LATE       => [
                'label' => __('Patches overdue', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-clock-exclamation',
                'help'  => __('The last known patch is older than the target delay of the computer.', 'grcmanager'),
            ],
            Rules::STATUS_UNKNOWN    => [
                'label' => __('Unknown', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __(
                    'No dated update in the inventory: no inventory agent, or a system whose agent does not report '
                        . 'installed updates.',
                    'grcmanager'
                ),
            ],
            Rules::STATUS_UP_TO_DATE => [
                'label' => __('Up to date', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-circle-check',
                'help'  => __('The last known patch is within the target delay of the computer.', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, help: string}>
     */
    public static function tierInfos(): array
    {
        return [
            Rules::TIER_CRITICAL  => [
                'label' => __('Critical', 'grcmanager'),
                'color' => 'danger',
                'help'  => __('A high C, I or A level, or published on the Internet.', 'grcmanager'),
            ],
            Rules::TIER_IMPORTANT => [
                'label' => __('Important', 'grcmanager'),
                'color' => 'warning',
                'help'  => __('A medium C, I or A level.', 'grcmanager'),
            ],
            Rules::TIER_STANDARD  => [
                'label' => __('Standard', 'grcmanager'),
                'color' => 'secondary',
                'help'  => __('Low or not classified.', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            Rules::SOURCE_UPDATE  => __('System update', 'grcmanager'),
            Rules::SOURCE_PACKAGE => __('Package installation', 'grcmanager'),
            Rules::SOURCE_NONE    => __('No dated update', 'grcmanager'),
        ];
    }

    // ------------------------------------------------------------------ Calculs.

    /**
     * Lignes des ordinateurs visibles (session), mémorisées pendant la requête.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return self::$memo ??= self::canView() ? self::computeRows(null, true) : [];
    }

    /**
     * Une ligne par ordinateur : système, dernier correctif connu, criticité, délai cible, état.
     * $entityCriteria null : entités de la session ; sinon périmètre d'un KRI (sans session).
     * $withContext : vulnérabilités du système et fin de support, sous les droits de leurs pages.
     *
     * @param array<string, mixed>|null $entityCriteria
     * @return list<array<string, mixed>>
     */
    public static function computeRows(
        ?array $entityCriteria = null,
        bool $withContext = false,
        ?string $today = null
    ): array {
        $computers = ComplianceAudit::computers($entityCriteria);
        if ($computers === []) {
            return [];
        }
        $today ??= date('Y-m-d', strtotime((string) (Session::getCurrentTime() ?? 'now')));
        $ids = array_keys($computers);
        $systems = OperatingSystemInventory::forComputers($ids);
        $updates = self::lastUpdates($ids);
        $packages = self::lastPackages($ids);
        $classification = self::classifications($ids);
        $exposed = self::exposedComputers($ids);
        $types = self::typeNames($computers);
        $targets = self::getTargets();
        $cves = $withContext && ParkVulnerability::canView() ? self::systemCves($systems) : null;
        $eol = null;
        if ($withContext) {
            $eol = [];
            foreach (EndOfLife::unsupportedAssets($today) ?? [] as $asset) {
                if ($asset['itemtype'] === Computer::class) {
                    $eol[(int) $asset['items_id']] = $asset['details'];
                }
            }
        }
        $entities = [];
        $rows = [];
        foreach ($computers as $id => $computer) {
            $system = $systems[$id]
                ?? ['name' => '', 'os_version' => '', 'kernel' => '', 'os_name' => '', 'version' => ''];
            $update = $updates[$id] ?? ['date' => '', 'name' => ''];
            $patch = Rules::lastPatch($update['date'], $packages[$id] ?? '', $system['name']);
            $levels = $classification[$id] ?? ['confidentiality' => '', 'integrity' => '', 'availability' => ''];
            $isExposed = isset($exposed[$id]);
            $tier = Rules::tier($levels['confidentiality'], $levels['integrity'], $levels['availability'], $isExposed);
            $age = Rules::ageDays($patch['date'], $today);
            $status = Rules::status($age, $targets[$tier]);
            $typeName = $types[(int) ($computer['computertypes_id'] ?? 0)] ?? '';
            $entityId = (int) ($computer['entities_id'] ?? 0);
            $entities[$entityId] ??= (string) Dropdown::getDropdownName('glpi_entities', $entityId);
            $lastInventory = (string) ($computer['last_inventory_update'] ?? '');
            $name = (string) $computer['name'];
            $rows[] = [
                'id'              => $id,
                'name'            => $name !== '' ? $name : sprintf('%s %d', Computer::getTypeName(1), $id),
                'url'             => Computer::getFormURLWithID($id),
                'vuln_url'        => Computer::getFormURLWithID($id) . '&forcetab='
                    . rawurlencode(ParkVulnerability::class . '$1'),
                'entities_id'     => $entityId,
                'entity'          => $entities[$entityId],
                'type'            => $typeName,
                'is_server'       => Rules::isServer($typeName, $system['name']),
                'os'              => $system['name'],
                'os_version'      => $system['os_version'],
                'kernel'          => $system['kernel'],
                'last_patch'      => $patch['date'],
                'source'          => $patch['source'],
                'last_update'     => $patch['source'] === Rules::SOURCE_UPDATE ? $update['name'] : '',
                'age'             => $age,
                'tier'            => $tier,
                'target'          => $targets[$tier],
                'overdue'         => $status === Rules::STATUS_LATE ? (int) $age - $targets[$tier] : 0,
                'status'          => $status,
                'exposed'         => $isExposed,
                'levels'          => $levels,
                'last_inventory'  => $lastInventory,
                'stale_inventory' => Rules::isStaleInventory($lastInventory, $today),
                'cves'            => $cves === null
                    ? null
                    : ($cves[$id] ?? ['total' => 0, 'critical' => 0, 'kev' => 0]),
                'eol'             => $eol === null ? null : ($eol[$id] ?? []),
            ];
        }

        return Rules::sort($rows);
    }

    /**
     * Mise à jour datée la plus récente de chaque ordinateur, avec son nom (article KB…).
     *
     * @param list<int> $ids
     * @return array<int, array{date: string, name: string}>
     */
    private static function lastUpdates(array $ids): array
    {
        global $DB;

        $candidates = [
            ['glpi_softwares.name' => ['LIKE', '%KB%']],
            ['glpi_softwares.is_update' => 1],
        ];
        $likes = ['%update%', '%hotfix%', '%patch%', '%mise%jour%', '%correctif%', '%actualizaci%', '%aggiornamento%'];
        foreach ($likes as $like) {
            $candidates[] = ['glpi_softwarecategories.name' => ['LIKE', $like]];
        }
        $updates = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'glpi_items_softwareversions.items_id',
                    'glpi_softwares.name',
                    'glpi_softwares.is_update',
                    'glpi_softwarecategories.name AS category',
                    'MAX' => 'glpi_items_softwareversions.date_install AS last_install',
                ],
                'FROM'       => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => ['FKEY' => [
                        'glpi_items_softwareversions' => 'softwareversions_id',
                        'glpi_softwareversions'       => 'id',
                    ]],
                    'glpi_softwares' => ['FKEY' => [
                        'glpi_softwareversions' => 'softwares_id',
                        'glpi_softwares'        => 'id',
                    ]],
                ],
                'LEFT JOIN'  => [
                    'glpi_softwarecategories' => ['FKEY' => [
                        'glpi_softwares'          => 'softwarecategories_id',
                        'glpi_softwarecategories' => 'id',
                    ]],
                ],
                'WHERE'      => [
                    'glpi_items_softwareversions.itemtype'   => Computer::class,
                    'glpi_items_softwareversions.items_id'   => $ids,
                    'glpi_items_softwareversions.is_deleted' => 0,
                    'NOT'                                    => ['glpi_items_softwareversions.date_install' => null],
                    'OR'                                     => $candidates,
                ],
                'GROUPBY'    => [
                    'glpi_items_softwareversions.items_id', 'glpi_softwares.name', 'glpi_softwares.is_update',
                    'glpi_softwarecategories.name',
                ],
            ]) as $row
        ) {
            $name = (string) ($row['name'] ?? '');
            if (!Rules::isUpdateSoftware($name, (string) ($row['category'] ?? ''), (bool) $row['is_update'])) {
                continue;
            }
            $date = Rules::normalizeDate((string) ($row['last_install'] ?? ''));
            $id = (int) $row['items_id'];
            if ($date !== '' && $date > ($updates[$id]['date'] ?? '')) {
                $updates[$id] = ['date' => $date, 'name' => $name];
            }
        }

        return $updates;
    }

    /**
     * Installation de logiciel (paquet) la plus récente de chaque ordinateur.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function lastPackages(array $ids): array
    {
        global $DB;

        $packages = [];
        foreach (
            $DB->request([
                'SELECT'  => ['items_id', 'MAX' => 'date_install AS last_install'],
                'FROM'    => 'glpi_items_softwareversions',
                'WHERE'   => [
                    'itemtype'   => Computer::class,
                    'items_id'   => $ids,
                    'is_deleted' => 0,
                    'NOT'        => ['date_install' => null],
                ],
                'GROUPBY' => 'items_id',
            ]) as $row
        ) {
            $packages[(int) $row['items_id']] = Rules::normalizeDate((string) ($row['last_install'] ?? ''));
        }

        return $packages;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{confidentiality: string, integrity: string, availability: string}>
     */
    private static function classifications(array $ids): array
    {
        global $DB;

        $levels = [];
        foreach (
            $DB->request([
                'SELECT' => ['items_id', 'confidentiality', 'integrity', 'availability'],
                'FROM'   => AssetClassification::getTable(),
                'WHERE'  => ['itemtype' => Computer::class, 'items_id' => $ids],
            ]) as $row
        ) {
            $levels[(int) $row['items_id']] = [
                'confidentiality' => (string) $row['confidentiality'],
                'integrity'       => (string) $row['integrity'],
                'availability'    => (string) $row['availability'],
            ];
        }

        return $levels;
    }

    /**
     * Ordinateurs destinataires d'un flux actif « publié sur Internet » (registre des flux réseau).
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private static function exposedComputers(array $ids): array
    {
        global $DB;

        $exposed = [];
        foreach (
            $DB->request([
                'SELECT' => ['destination_items_id'],
                'FROM'   => NetworkFlow::getTable(),
                'WHERE'  => [
                    'flow_type'            => 'internet_publication',
                    'is_active'            => 1,
                    'destination_itemtype' => Computer::class,
                    'destination_items_id' => $ids,
                ],
            ]) as $row
        ) {
            $exposed[(int) $row['destination_items_id']] = true;
        }

        return $exposed;
    }

    /**
     * @param array<int, array<string, mixed>> $computers
     * @return array<int, string>
     */
    private static function typeNames(array $computers): array
    {
        global $DB;

        $typeIds = array_values(array_unique(array_filter(array_map(
            static fn (array $computer): int => (int) ($computer['computertypes_id'] ?? 0),
            $computers
        ))));
        if ($typeIds === []) {
            return [];
        }
        $types = [];
        $criteria = ['SELECT' => ['id', 'name'], 'FROM' => ComputerType::getTable(), 'WHERE' => ['id' => $typeIds]];
        foreach ($DB->request($criteria) as $row) {
            $types[(int) $row['id']] = (string) $row['name'];
        }

        return $types;
    }

    /**
     * Issue #780 : CVE connues de la version du système de chaque ordinateur (désignation reliée à
     * un produit du catalogue par alias), dont critiques et exploitées (KEV).
     *
     * @param array<int, array{os_name: string, version: string}> $systems
     * @return array<int, array{total: int, critical: int, kev: int}>
     */
    private static function systemCves(array $systems): array
    {
        global $DB;

        $names = array_values(array_unique(array_filter(array_column($systems, 'os_name'))));
        if ($names === []) {
            return [];
        }
        $productByAlias = [];
        foreach ($DB->request(['FROM' => ProductAlias::getTable(), 'WHERE' => ['alias' => $names]]) as $row) {
            $productByAlias[(string) $row['alias']] = (int) $row['plugin_grcmanager_canonicalproducts_id'];
        }
        if ($productByAlias === []) {
            return [];
        }
        $park = ParkVulnerabilitySync::PARK_CVES_TABLE;
        $counts = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    "$park.plugin_grcmanager_canonicalproducts_id AS canonical_id",
                    "$park.version",
                    "$park.cve_id",
                    'e.cvss_score',
                    'e.severity',
                    's.is_kev',
                ],
                'FROM'      => $park,
                'LEFT JOIN' => [
                    'glpi_plugin_grcmanager_cveenrichments AS e' => ['FKEY' => [$park => 'cve_id', 'e' => 'cve_id']],
                    ParkVulnerabilitySync::SIGNALS_TABLE . ' AS s' => ['FKEY' => [$park => 'cve_id', 's' => 'cve_id']],
                ],
                'WHERE'     => [
                    "$park.plugin_grcmanager_canonicalproducts_id" => array_values(array_unique($productByAlias)),
                ],
            ]) as $row
        ) {
            $key = (int) $row['canonical_id'] . "\0" . (string) $row['version'];
            $counts[$key] ??= ['total' => 0, 'critical' => 0, 'kev' => 0];
            $counts[$key]['total']++;
            $cvss = $row['cvss_score'] !== null ? (float) $row['cvss_score'] : null;
            $critical = ($cvss !== null && $cvss >= RiskIndicator::CRITICAL_CVSS)
                || strtoupper((string) $row['severity']) === 'CRITICAL';
            if ($critical) {
                $counts[$key]['critical']++;
            }
            if ((bool) ($row['is_kev'] ?? false)) {
                $counts[$key]['kev']++;
            }
        }
        $byComputer = [];
        foreach ($systems as $id => $system) {
            $productId = $productByAlias[$system['os_name']] ?? null;
            $key = $productId . "\0" . $system['version'];
            if ($productId !== null && $system['version'] !== '' && isset($counts[$key])) {
                $byComputer[$id] = $counts[$key];
            }
        }

        return $byComputer;
    }

    /**
     * Synthèse des ordinateurs visibles.
     *
     * @return array{late: int, unknown: int, up_to_date: int, total: int, rate: ?int,
     *     late_critical_servers: int, late_exposed: int}
     */
    public static function summary(): array
    {
        return Rules::summary(self::rows());
    }

    /**
     * KRI : serveurs critiques avec correctifs en retard, dans le périmètre (sans session : la tâche
     * planifiée relève sans utilisateur connecté). Null sans périmètre.
     *
     * @param list<int> $entityIds
     */
    public static function lateCriticalServers(array $entityIds): ?float
    {
        if ($entityIds === []) {
            return null;
        }
        $rows = self::computeRows([Computer::getTable() . '.entities_id' => $entityIds]);

        return (float) Rules::summary($rows)['late_critical_servers'];
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $rows = self::rows();
        $shown = array_slice($rows, 0, self::MAX_ROWS);
        foreach ($shown as &$row) {
            $row['last_inventory_label'] = $row['last_inventory'] !== ''
                ? (string) Html::convDateTime($row['last_inventory'])
                : '';
            $row['last_patch_label'] = $row['last_patch'] !== '' ? (string) Html::convDate($row['last_patch']) : '';
        }
        unset($row);

        return [
            'summary'          => Rules::summary($rows),
            'by_tier'          => Rules::byTier($rows),
            'infos'            => self::statusInfos(),
            'tiers'            => self::tierInfos(),
            'sources'          => self::sourceLabels(),
            'targets'          => self::getTargets(),
            'min_target'       => Rules::MIN_TARGET,
            'max_target'       => Rules::MAX_TARGET,
            'stale_days'       => Rules::STALE_INVENTORY_DAYS,
            'rows'             => $shown,
            'total_rows'       => count($rows),
            'can_manage'       => self::canManage(),
            'show_cves'        => ParkVulnerability::canView(),
            'action'           => self::getSearchURL(),
            'vulnerabilities_url' => ParkVulnerability::canView() ? ParkVulnerability::getSearchURL() : '',
            'endoflife_url'    => ParkVulnerability::canView()
                ? ParkVulnerability::getSearchURL() . '#grc-endoflife'
                : '',
            'flows_url'        => NetworkFlow::canView() ? NetworkFlow::getSearchURL() : '',
            'computer_url'     => Computer::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/patch_compliance.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Preuves, tableau de bord.

    /**
     * Preuve des mesures A.8.8 et A.8.19 : taux d'ordinateurs à jour, retards, inconnus, délais
     * cibles, date du calcul. Null si la mesure n'est pas concernée ou sans les droits.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.8', 'A.8.19'], true) || !self::canView()) {
            return null;
        }
        $summary = self::summary();

        return [
            'rate'                  => $summary['rate'],
            'total'                 => $summary['total'],
            'late'                  => $summary[Rules::STATUS_LATE],
            'unknown'               => $summary[Rules::STATUS_UNKNOWN],
            'late_critical_servers' => $summary['late_critical_servers'],
            'late_exposed'          => $summary['late_exposed'],
            'targets'               => self::getTargets(),
            'date'                  => (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'url'                   => self::getSearchURL(),
        ];
    }

    /**
     * Carte du tableau de bord : ordinateurs dont les correctifs sont dans les délais (%).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function dashboardCard(array $params = []): array
    {
        $rate = self::canView() ? self::summary()['rate'] : null;

        return [
            'number' => $rate ?? 0,
            'url'    => self::getSearchURL(),
            'label'  => $params['label'] ?? __('Computers with security patches on time (%)', 'grcmanager'),
            'icon'   => self::getIcon(),
        ];
    }
}
