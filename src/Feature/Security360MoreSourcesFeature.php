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

namespace GlpiPlugin\Grcmanager\Feature;

use CommonDBTM;
use Computer;
use GlpiPlugin\Grcmanager\AssetClassification;
use GlpiPlugin\Grcmanager\AssetSecurity360;
use GlpiPlugin\Grcmanager\BackupCoverage;
use GlpiPlugin\Grcmanager\EndOfLife;
use GlpiPlugin\Grcmanager\HardwareWarranty;
use GlpiPlugin\Grcmanager\LogCoverage;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\PatchCompliance;
use GlpiPlugin\Grcmanager\SecurityIncidentEdrImport;
use GlpiPlugin\Grcmanager\SentinelOneAlert;
use GlpiPlugin\Grcmanager\Services\Backup\BackupCoverageRules as BackupRules;
use GlpiPlugin\Grcmanager\Services\Classification\ClassificationLevels;
use GlpiPlugin\Grcmanager\Services\Compliance\PatchLagRules as PatchRules;
use GlpiPlugin\Grcmanager\Services\Control\SentinelOneEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Lifecycle\CycleMatcher;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\HardwareModelEolStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\WarrantyRules;
use GlpiPlugin\Grcmanager\Services\Logging\LogCoverageRules as LogRules;
use GlpiPlugin\Grcmanager\Services\Monitoring\ZabbixRules;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules as Rules;
use GlpiPlugin\Grcmanager\ZabbixMonitoring;
use Html;
use Infocom;
use NetworkEquipment;
use Session;

/**
 * Issue #837 : sources restantes de l'onglet « Sécurité 360° » : supervision Zabbix (#836),
 * sauvegardes (#838), journalisation (#853), correctifs (#854), fin de support des logiciels et
 * garantie du matériel (#704, #782), EDR SentinelOne et classification de l'actif.
 *
 * Chaque calcul réutilise les données déjà lues par le module (aucun appel extérieur), en lecture
 * seule, et renvoie null quand le module n'est pas utilisé ou n'a rien sur l'actif (source discrète).
 * Les modules qui calculent tout le parc (Zabbix, sauvegardes, journalisation) gardent leur calcul en
 * mémoire pour la requête ; les autres ne lisent que l'actif.
 */
final class Security360MoreSourcesFeature implements AssetSecuritySourcesProvider
{
    public static function assetSecuritySources(): array
    {
        $both = [Computer::class, NetworkEquipment::class];

        return [
            'classification' => [
                'label'     => __('Asset classification', 'grcmanager'),
                'icon'      => 'ti ti-shield-lock',
                'itemtypes' => $both,
                'weight'    => 1,
                'compute'   => [self::class, 'classification'],
            ],
            'patches' => [
                'label'     => __('Patch level', 'grcmanager'),
                'icon'      => 'ti ti-download',
                'itemtypes' => [Computer::class],
                'weight'    => 2,
                'compute'   => [self::class, 'patches'],
                'after'     => 'vulnerabilities',
            ],
            'end_of_support' => [
                'label'     => __('Software end of support', 'grcmanager'),
                'icon'      => 'ti ti-calendar-x',
                'itemtypes' => $both,
                'weight'    => 2,
                'compute'   => [self::class, 'endOfSupport'],
            ],
            'warranty' => [
                'label'     => __('Warranty and manufacturer end of life', 'grcmanager'),
                'icon'      => 'ti ti-shield-check',
                'itemtypes' => $both,
                'weight'    => 1,
                'compute'   => [self::class, 'warranty'],
            ],
            'edr' => [
                'label'     => __('Endpoint protection (EDR)', 'grcmanager'),
                'icon'      => 'ti ti-virus-search',
                'itemtypes' => [Computer::class],
                'weight'    => 2,
                'compute'   => [self::class, 'edr'],
            ],
            'monitoring' => [
                'label'     => __('Monitoring (Zabbix)', 'grcmanager'),
                'icon'      => 'ti ti-activity-heartbeat',
                'itemtypes' => $both,
                'weight'    => 1,
                'compute'   => [self::class, 'monitoring'],
            ],
            'backups' => [
                'label'     => __('Backups', 'grcmanager'),
                'icon'      => 'ti ti-database-export',
                'itemtypes' => $both,
                'weight'    => 2,
                'compute'   => [self::class, 'backups'],
            ],
            'logging' => [
                'label'     => __('Log collection', 'grcmanager'),
                'icon'      => 'ti ti-file-text',
                'itemtypes' => $both,
                'weight'    => 1,
                'compute'   => [self::class, 'logging'],
            ],
        ];
    }

    /**
     * Classification C/I/D de l'actif ; absente tant que le registre ne classe aucun actif.
     *
     * @return array<string, mixed>|null
     */
    public static function classification(CommonDBTM $item): ?array
    {
        if (
            !(bool) Session::haveRight(AssetClassification::$rightname, READ)
            || countElementsInTable(AssetClassification::getTable()) === 0
        ) {
            return null;
        }
        $row = AssetClassification::getByItem($item::class, (int) $item->getID());
        $url = AssetSecurity360::tabUrl($item, AssetClassification::class);
        if (!ClassificationLevels::isClassified($row)) {
            return [
                'status'  => Rules::STATUS_WARNING,
                'summary' => __(
                    'Not classified yet: its criticality cannot set the backup, logging and patch targets.',
                    'grcmanager'
                ),
                'url'     => $url,
            ];
        }
        $levels = AssetClassification::getLevels();
        $level = static fn (string $axis): string => $levels[(string) ($row[$axis] ?? '')] ?? '-';
        $facts = [];
        if ((int) ($row['rto_hours'] ?? 0) > 0) {
            $facts[] = sprintf(__('RTO: %d h', 'grcmanager'), (int) $row['rto_hours']);
        }
        if ((int) ($row['rpo_hours'] ?? 0) > 0) {
            $facts[] = sprintf(__('RPO: %d h', 'grcmanager'), (int) $row['rpo_hours']);
        }

        return [
            'status'  => Rules::STATUS_OK,
            'summary' => sprintf(
                __('Confidentiality %1$s, integrity %2$s, availability %3$s.', 'grcmanager'),
                $level('confidentiality'),
                $level('integrity'),
                $level('availability')
            ),
            'facts'   => $facts,
            'url'     => $url,
        ];
    }

    /**
     * Ancienneté du dernier correctif connu face au délai cible de l'ordinateur (#854).
     *
     * @return array<string, mixed>|null
     */
    public static function patches(CommonDBTM $item): ?array
    {
        if (!PatchCompliance::canView()) {
            return null;
        }
        $rows = PatchCompliance::computeRows(
            [Computer::getTable() . '.id' => (int) $item->getID()]
                + getEntitiesRestrictCriteria(Computer::getTable(), '', '', true)
        );
        $row = $rows[0] ?? null;
        if ($row === null || $row['status'] === PatchRules::STATUS_UNKNOWN) {
            return null;
        }
        $facts = [sprintf(__('Target delay: %d days', 'grcmanager'), (int) $row['target'])];
        if ((string) $row['os'] !== '') {
            $facts[] = (string) $row['os'];
        }
        if ($row['status'] === PatchRules::STATUS_LATE) {
            $overdue = (int) $row['overdue'];

            return [
                'status'  => $overdue >= (int) $row['target'] ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING,
                'summary' => sprintf(
                    __('Last known patch on %1$s, %2$d days past the target delay.', 'grcmanager'),
                    (string) Html::convDate((string) $row['last_patch']),
                    $overdue
                ),
                'facts'   => $facts,
                'url'     => PatchCompliance::getSearchURL(),
            ];
        }

        return [
            'status'  => Rules::STATUS_OK,
            'summary' => sprintf(
                __('Last known patch on %s, within the target delay.', 'grcmanager'),
                (string) Html::convDate((string) $row['last_patch'])
            ),
            'facts'   => $facts,
            'url'     => PatchCompliance::getSearchURL(),
        ];
    }

    /**
     * Fin de support du système et des logiciels de l'actif, d'après le cache endoflife.date déjà lu
     * (aucun appel extérieur) ; absente si la source est désactivée ou qu'aucun produit n'est suivi.
     *
     * @return array<string, mixed>|null
     */
    public static function endOfSupport(CommonDBTM $item): ?array
    {
        if (!EndOfLifeStore::isEnabled() || !EndOfLife::canView()) {
            return null;
        }
        $tracked = array_filter(
            EndOfLifeInventory::evaluate(EndOfLifeInventory::itemsOf($item::class, (int) $item->getID())),
            static fn (array $row): bool => $row['evaluation']['product'] !== ''
        );
        if ($tracked === []) {
            return null;
        }
        $eol = [];
        $soon = [];
        foreach ($tracked as $row) {
            $label = trim($row['name'] . ' ' . $row['version']);
            if ($row['evaluation']['status'] === CycleMatcher::STATUS_EOL) {
                $eol[$label] = $label;
            } elseif ($row['evaluation']['status'] === CycleMatcher::STATUS_SOON) {
                $soon[$label] = $label;
            }
        }
        $url = ParkVulnerability::getSearchURL() . '#grc-endoflife';
        if ($eol === [] && $soon === []) {
            return [
                'status'  => Rules::STATUS_OK,
                'summary' => sprintf(
                    __('%d tracked products, all still supported or with an unknown date.', 'grcmanager'),
                    count($tracked)
                ),
                'url'     => $url,
            ];
        }

        return [
            'status'  => $eol !== [] ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING,
            'summary' => sprintf(
                __('%1$d products no longer supported, %2$d reaching end of support soon.', 'grcmanager'),
                count($eol),
                count($soon)
            ),
            'facts'   => array_slice(array_values([...$eol, ...$soon]), 0, 5),
            'url'     => $url,
        ];
    }

    /**
     * Garantie (informations financières de GLPI) et fin de vie constructeur du modèle ; absente sans
     * informations financières ni date de fin de vie.
     *
     * @return array<string, mixed>|null
     */
    public static function warranty(CommonDBTM $item): ?array
    {
        if (!HardwareWarranty::canView()) {
            return null;
        }
        $today = substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')), 0, 10);
        $warningDays = EndOfLifeStore::warningDays();
        $infocom = new Infocom();
        $hasInfocom = (bool) $infocom->getFromDBforDevice($item::class, (int) $item->getID());
        $warranty = WarrantyRules::evaluate(
            $hasInfocom,
            $hasInfocom && $infocom->fields['warranty_date'] !== null ? (string) $infocom->fields['warranty_date'] : null,
            $hasInfocom ? (int) $infocom->fields['warranty_duration'] : 0,
            $today,
            $warningDays
        );
        $model = $item->getModelClass();
        $modelId = $model !== null ? (int) ($item->fields[$model::getForeignKeyField()] ?? 0) : 0;
        $eol = WarrantyRules::endOfLife(
            $modelId > 0 ? HardwareModelEolStore::dateFor((string) $model, $modelId) : null,
            $today,
            $warningDays
        );
        if (!$hasInfocom && $eol['status'] === WarrantyRules::EOL_NONE) {
            return null;
        }
        $status = match (true) {
            $eol['status'] === WarrantyRules::EOL_REACHED => Rules::STATUS_CRITICAL,
            $eol['status'] === WarrantyRules::EOL_SOON,
            in_array($warranty['status'], [WarrantyRules::STATUS_EXPIRED, WarrantyRules::STATUS_SOON], true)
                => Rules::STATUS_WARNING,
            $warranty['status'] === WarrantyRules::STATUS_COVERED => Rules::STATUS_OK,
            default => Rules::STATUS_INFO,
        };
        $detail = HardwareWarranty::detail($warranty);
        $summary = HardwareWarranty::statusInfo($warranty['status'])['label'];
        $facts = [];
        if ($detail !== '') {
            $facts[] = $detail;
        }
        $eolDetail = HardwareWarranty::endOfLifeDetail($eol);
        if ($eolDetail !== '') {
            $facts[] = $eolDetail;
        }

        return [
            'status'  => $status,
            'summary' => $summary,
            'facts'   => $facts,
            'url'     => ParkVulnerability::getSearchURL() . '#grc-hardware-warranty',
        ];
    }

    /**
     * Agent SentinelOne rapproché de l'ordinateur et alertes en attente de vérification, d'après la
     * dernière lecture de la tâche automatique ; absente si le connecteur n'a encore rien lu.
     *
     * @return array<string, mixed>|null
     */
    public static function edr(CommonDBTM $item): ?array
    {
        global $DB;

        if (
            !(bool) Session::haveRight(SentinelOneAlert::RIGHTNAME, READ)
            || !SentinelOneEvidenceProvider::hasData()
        ) {
            return null;
        }
        $id = (int) $item->getID();
        $agent = $DB->request([
            'SELECT' => ['is_active', 'last_active'],
            'FROM'   => SentinelOneAlert::AGENTS_TABLE,
            'WHERE'  => ['computers_id' => $id],
            'ORDER'  => ['is_active DESC', 'last_active DESC'],
            'LIMIT'  => 1,
        ])->current();
        $pending = countElementsInTable(
            SentinelOneAlert::getTable(),
            ['computers_id' => $id, 'status' => SentinelOneAlert::STATUS_PENDING]
        );
        $url = SecurityIncidentEdrImport::canImport() ? SecurityIncidentEdrImport::getImportURL() : '';
        $facts = [];
        if ($agent !== null && $agent['last_active'] !== null) {
            $facts[] = sprintf(
                __('Last seen by the console: %s', 'grcmanager'),
                (string) Html::convDateTime((string) $agent['last_active'])
            );
        }
        if ($pending > 0) {
            return [
                'status'  => Rules::STATUS_CRITICAL,
                'summary' => sprintf(
                    __('%d SentinelOne alerts waiting to be reviewed for this computer.', 'grcmanager'),
                    $pending
                ),
                'facts'   => $facts,
                'url'     => $url,
            ];
        }
        if ($agent === null) {
            return [
                'status'  => Rules::STATUS_WARNING,
                'summary' => __('No SentinelOne agent matched this computer.', 'grcmanager'),
                'url'     => $url,
            ];
        }
        $active = (int) $agent['is_active'] === 1;

        return [
            'status'  => $active ? Rules::STATUS_OK : Rules::STATUS_WARNING,
            'summary' => $active
                ? __('SentinelOne agent active, no alert waiting.', 'grcmanager')
                : __('SentinelOne agent inactive: the computer is no longer protected.', 'grcmanager'),
            'facts'   => $facts,
            'url'     => $url,
        ];
    }

    /**
     * Supervision Zabbix d'après les dernières lectures (#836) ; absente sans lecture ou si l'actif
     * n'est pas suivi par le module.
     *
     * @return array<string, mixed>|null
     */
    public static function monitoring(CommonDBTM $item): ?array
    {
        if (!ZabbixMonitoring::canView()) {
            return null;
        }
        $data = ZabbixMonitoring::tabData($item);
        $row = $data['assets'][0] ?? null;
        if (!(bool) $data['readings'] || $row === null) {
            return null;
        }
        $label = (string) (ZabbixMonitoring::statusInfos()[$row['status']]['label'] ?? $row['status']);
        $high = (int) $row['open_high'];
        $other = (int) $row['open_other'];
        $status = match (true) {
            $row['status'] === ZabbixRules::STATUS_UNAVAILABLE, $high > 0 => Rules::STATUS_CRITICAL,
            !ZabbixRules::isCovered((string) $row['status']), $other > 0, (bool) $row['rto_gap']
                => Rules::STATUS_WARNING,
            default => Rules::STATUS_OK,
        };
        $facts = [];
        if ($row['availability'] !== null) {
            $facts[] = sprintf(
                __('Availability over %1$d days: %2$s %%', 'grcmanager'),
                (int) $data['period_days'],
                (string) $row['availability']
            );
        }
        if ((bool) $row['rto_gap']) {
            $facts[] = __('An outage exceeded the RTO of a business process.', 'grcmanager');
        }

        return [
            'status'  => $status,
            'summary' => ZabbixRules::isCovered((string) $row['status'])
                ? sprintf(
                    __('%1$s: %2$d open high problems, %3$d other open problems.', 'grcmanager'),
                    $label,
                    $high,
                    $other
                )
                : $label,
            'facts'   => $facts,
            'url'     => AssetSecurity360::tabUrl($item, ZabbixMonitoring::class),
        ];
    }

    /**
     * Dernière sauvegarde réussie face au délai autorisé (RPO) et test de restauration (#838).
     *
     * @return array<string, mixed>|null
     */
    public static function backups(CommonDBTM $item): ?array
    {
        $row = BackupCoverage::assetRow($item);
        if ($row === null) {
            return null;
        }
        $label = (string) (BackupCoverage::statusInfos()[$row['status']]['label'] ?? $row['status']);
        $status = match ($row['status']) {
            BackupRules::STATUS_OK      => Rules::STATUS_OK,
            BackupRules::STATUS_TOO_OLD => Rules::STATUS_WARNING,
            BackupRules::STATUS_NONE    => (bool) $row['critical'] ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING,
            default                     => Rules::STATUS_CRITICAL,
        };
        $facts = [sprintf(__('Allowed age: %s', 'grcmanager'), BackupCoverage::formatHours((int) $row['allowed_hours']))];
        $restore = (string) (BackupCoverage::restoreInfos()[$row['restore']]['label'] ?? '');
        if ($restore !== '') {
            $facts[] = $restore;
        }
        if ($status === Rules::STATUS_OK && $row['restore'] !== BackupRules::RESTORE_RECENT) {
            $status = Rules::STATUS_WARNING;
        }

        return [
            'status'  => $status,
            'summary' => $row['last_success'] !== null
                ? sprintf(
                    __('%1$s: last success on %2$s.', 'grcmanager'),
                    $label,
                    (string) Html::convDateTime((string) $row['last_success'])
                )
                : $label,
            'facts'   => $facts,
            'url'     => BackupCoverage::getSearchURL(),
        ];
    }

    /**
     * Réception des journaux de l'actif par une plateforme de collecte (#853).
     *
     * @return array<string, mixed>|null
     */
    public static function logging(CommonDBTM $item): ?array
    {
        $row = LogCoverage::assetRow($item);
        if ($row === null) {
            return null;
        }
        $label = (string) (LogCoverage::statusInfos()[$row['status']]['label'] ?? $row['status']);
        $status = match ($row['status']) {
            LogRules::STATUS_OK     => Rules::STATUS_OK,
            LogRules::STATUS_SILENT => (bool) $row['critical'] ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING,
            default                 => Rules::STATUS_WARNING,
        };

        return [
            'status'  => $status,
            'summary' => $row['last_event'] !== null
                ? sprintf(
                    __('%1$s: last event on %2$s.', 'grcmanager'),
                    $label,
                    (string) Html::convDateTime((string) $row['last_event'])
                )
                : $label,
            'facts'   => $row['platforms'] !== []
                ? [sprintf(__('Platforms: %s', 'grcmanager'), implode(', ', $row['platforms']))]
                : [],
            'url'     => LogCoverage::getSearchURL(),
        ];
    }
}
