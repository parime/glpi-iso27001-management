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
use GlpiPlugin\Grcmanager\AssetSecurity360;
use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\ConfigurationCompliance;
use GlpiPlugin\Grcmanager\DiskEncryption;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\ProductAlias;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\SecurityIncidentLinkedItem;
use GlpiPlugin\Grcmanager\ServerManagement;
use GlpiPlugin\Grcmanager\Services\Compliance\DiskEncryptionRules;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules as Rules;
use Html;
use NetworkEquipment;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #837 : premières sources de l'onglet « Sécurité 360° » (AssetSecurity360) : vulnérabilités du
 * parc, chiffrement des disques, contrôleur de gestion, conformité de configuration (OpenSCAP), risques
 * et incidents de sécurité liés. Chaque calcul lit seulement les données de l'actif, réutilise les
 * méthodes des modules et renvoie null quand la source n'a rien à dire (discrète).
 *
 * Une autre fonctionnalité ajoute ses sources dans son propre descripteur (AssetSecuritySourcesProvider).
 */
final class Security360CoreSourcesFeature implements AssetSecuritySourcesProvider
{
    public static function assetSecuritySources(): array
    {
        $both = [Computer::class, NetworkEquipment::class];

        return [
            'vulnerabilities' => [
                'label'     => __('Known vulnerabilities', 'grcmanager'),
                'icon'      => 'ti ti-bug',
                'itemtypes' => $both,
                'weight'    => 3,
                'compute'   => [self::class, 'vulnerabilities'],
            ],
            'encryption' => [
                'label'     => __('Disk encryption', 'grcmanager'),
                'icon'      => 'ti ti-lock',
                'itemtypes' => [Computer::class],
                'weight'    => 2,
                'compute'   => [self::class, 'encryption'],
            ],
            'configuration' => [
                'label'     => __('Configuration compliance', 'grcmanager'),
                'icon'      => 'ti ti-list-check',
                'itemtypes' => [Computer::class],
                'weight'    => 2,
                'compute'   => [self::class, 'configuration'],
            ],
            'management_controller' => [
                'label'     => __('Management controller', 'grcmanager'),
                'icon'      => 'ti ti-server-cog',
                'itemtypes' => [Computer::class],
                'weight'    => 1,
                'compute'   => [self::class, 'managementController'],
            ],
            'incidents' => [
                'label'     => __('Security incidents', 'grcmanager'),
                'icon'      => 'ti ti-urgent',
                'itemtypes' => $both,
                'weight'    => 2,
                'compute'   => [self::class, 'incidents'],
            ],
            'risks' => [
                'label'     => __('Linked risks', 'grcmanager'),
                'icon'      => 'ti ti-alert-square-rounded',
                'itemtypes' => $both,
                'weight'    => 1,
                'compute'   => [self::class, 'risks'],
            ],
        ];
    }

    /**
     * Vulnérabilités connues des logiciels, firmwares et système de l'actif (résultat de la veille, aucun
     * appel extérieur) ; absente si aucun produit n'est surveillé.
     *
     * @return array<string, mixed>|null
     */
    public static function vulnerabilities(CommonDBTM $item): ?array
    {
        if (!ParkVulnerability::canView() || countElementsInTable(ProductAlias::getTable()) === 0) {
            return null;
        }
        $rows = ParkVulnerability::getForItem($item);
        $priority = count(array_filter($rows, static fn (array $row): bool => (bool) ($row['priority'] ?? false)));
        $kev = count(array_filter($rows, static fn (array $row): bool => (bool) ($row['is_kev'] ?? false)));
        $url = AssetSecurity360::tabUrl($item, ParkVulnerability::class);
        if ($rows === []) {
            return [
                'status'  => Rules::STATUS_OK,
                'summary' => __(
                    'No known vulnerability in the watched products installed on this asset.',
                    'grcmanager'
                ),
                'url'     => $url,
            ];
        }

        return [
            'status'  => $priority > 0 ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING,
            'summary' => sprintf(
                __(
                    '%1$d known vulnerabilities, %2$d of them priority (actively exploited or very severe).',
                    'grcmanager'
                ),
                count($rows),
                $priority
            ),
            'facts'   => $kev > 0
                ? [sprintf(__('%d listed in the catalogue of exploited vulnerabilities (KEV)', 'grcmanager'), $kev)]
                : [],
            'url'     => $url,
        ];
    }

    /**
     * Chiffrement des volumes d'après l'inventaire et la règle du plugin ; absente sans volume inventorié.
     *
     * @return array<string, mixed>|null
     */
    public static function encryption(CommonDBTM $item): ?array
    {
        if (!DiskEncryption::canView()) {
            return null;
        }
        $rows = ComplianceAudit::diskEncryptionRows(
            ['id' => (int) $item->getID()] + getEntitiesRestrictCriteria(Computer::getTable(), '', '', true)
        );
        $row = $rows[0] ?? null;
        if ($row === null || $row['status'] === DiskEncryptionRules::STATUS_UNKNOWN) {
            return null;
        }
        $label = DiskEncryption::statusInfos()[$row['status']]['label'] ?? (string) $row['status'];
        $facts = [];
        if ($row['clear_volumes'] !== []) {
            $facts[] = sprintf(__('Volumes not encrypted: %s', 'grcmanager'), implode(', ', $row['clear_volumes']));
        }
        if ($row['tools'] !== []) {
            $facts[] = sprintf(__('Tools: %s', 'grcmanager'), implode(', ', $row['tools']));
        }
        if ($row['is_gap']) {
            $status = Rules::STATUS_CRITICAL;
            $summary = sprintf(__('%s, although the encryption rule requires it.', 'grcmanager'), $label);
        } elseif ($row['status'] === DiskEncryptionRules::STATUS_ENCRYPTED) {
            $status = Rules::STATUS_OK;
            $summary = $label;
        } else {
            $status = Rules::STATUS_INFO;
            $summary = sprintf(__('%s, not required by the encryption rule.', 'grcmanager'), $label);
        }

        return ['status' => $status, 'summary' => $summary, 'facts' => $facts, 'url' => DiskEncryption::getSearchURL()];
    }

    /**
     * Dernier rapport de conformité de configuration (OpenSCAP, XCCDF) rattaché à l'ordinateur.
     *
     * @return array<string, mixed>|null
     */
    public static function configuration(CommonDBTM $item): ?array
    {
        if (!ConfigurationCompliance::canView()) {
            return null;
        }
        $report = ConfigurationCompliance::reports(['computers_id' => (int) $item->getID()])[0] ?? null;
        if ($report === null) {
            return null;
        }
        $failed = (int) $report['fail_count'];
        $high = (int) $report['high_fail_count'];
        $benchmark = (string) ($report['profile_title'] ?: $report['benchmark_title']);

        return [
            'status'  => $high > 0 ? Rules::STATUS_CRITICAL : ($failed > 0 ? Rules::STATUS_WARNING : Rules::STATUS_OK),
            'summary' => sprintf(
                __('Scan of %1$s: %2$s of rules passed, %3$d failed including %4$d of high severity.', 'grcmanager'),
                (string) Html::convDateTime((string) $report['scan_date']),
                $report['rate'] === null ? '-' : $report['rate'] . ' %',
                $failed,
                $high
            ),
            'facts'   => $benchmark !== '' ? [$benchmark] : [],
            'url'     => ConfigurationCompliance::getReportURL((int) $report['id']),
        ];
    }

    /**
     * Contrôleur de gestion du serveur (iLO, iDRAC, XClarity, IPMI) et ses écarts.
     *
     * @return array<string, mixed>|null
     */
    public static function managementController(CommonDBTM $item): ?array
    {
        if (!ServerManagement::canView()) {
            return null;
        }
        $rows = ServerManagement::tabData($item)['rows'];
        if ($rows === []) {
            return null;
        }
        $infos = ServerManagement::gapInfos();
        $high = false;
        $gaps = [];
        $errors = false;
        foreach ($rows as $row) {
            $high = $high || (bool) $row['high'];
            $errors = $errors || (string) $row['read_error'] !== '';
            foreach ($row['gaps'] as $gap) {
                $gaps[$gap] = (string) ($infos[$gap]['label'] ?? $gap);
            }
        }
        $names = implode(', ', array_map(static fn (array $row): string => (string) $row['name'], $rows));
        if ($gaps === []) {
            $status = $errors ? Rules::STATUS_WARNING : Rules::STATUS_OK;
            $summary = sprintf(
                $errors ? __('%s: last reading failed.', 'grcmanager') : __('%s: no gap found.', 'grcmanager'),
                $names
            );
        } else {
            $status = $high ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING;
            $summary = sprintf(__('%1$s: %2$d gaps found.', 'grcmanager'), $names, count($gaps));
        }

        return [
            'status'  => $status,
            'summary' => $summary,
            'facts'   => array_values($gaps),
            'url'     => AssetSecurity360::tabUrl($item, ServerManagement::class),
        ];
    }

    /**
     * Incidents de sécurité liés à l'actif que la personne peut lire ; un incident ouvert de priorité
     * haute ou plus est critique.
     *
     * @return array<string, mixed>|null
     */
    public static function incidents(CommonDBTM $item): ?array
    {
        $incidents = SecurityIncidentLinkedItem::getVisibleIncidents($item);
        if ($incidents === []) {
            return null;
        }
        $done = array_merge(
            PluginGrcmanagerSecurityIncident::getSolvedStatusArray(),
            PluginGrcmanagerSecurityIncident::getClosedStatusArray()
        );
        $open = [];
        $urgent = 0;
        foreach ($incidents as $incident) {
            if (in_array((int) $incident->fields['status'], array_map('intval', $done), true)) {
                continue;
            }
            $open[] = (string) $incident->fields['name'];
            $urgent += (int) ($incident->fields['priority'] ?? 0) >= 4 ? 1 : 0;
        }
        $url = AssetSecurity360::tabUrl($item, SecurityIncidentLinkedItem::class);
        if ($open === []) {
            return [
                'status'  => Rules::STATUS_OK,
                'summary' => sprintf(__('No open incident (%d closed or solved).', 'grcmanager'), count($incidents)),
                'url'     => $url,
            ];
        }

        return [
            'status'  => $urgent > 0 ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING,
            'summary' => sprintf(
                __('%1$d open incidents, %2$d of them with high priority or more.', 'grcmanager'),
                count($open),
                $urgent
            ),
            'facts'   => array_slice($open, 0, 5),
            'url'     => $url,
        ];
    }

    /**
     * Risques du registre liés à l'actif (entités de la session) : élevé ou critique à traiter en
     * priorité, moyen à surveiller.
     *
     * @return array<string, mixed>|null
     */
    public static function risks(CommonDBTM $item): ?array
    {
        if (!(bool) Session::haveRight(Risk::$rightname, READ)) {
            return null;
        }
        $risks = Risk::getRisksLinkedToItem($item::class, (int) $item->getID());
        if ($risks === []) {
            return null;
        }
        $levels = array_count_values(array_map(
            static fn (array $risk): string => (string) $risk['risk_level'],
            $risks
        ));
        $high = ($levels['high'] ?? 0) + ($levels['critical'] ?? 0);
        $medium = $levels['medium'] ?? 0;

        return [
            'status'  => $high > 0 ? Rules::STATUS_CRITICAL : ($medium > 0 ? Rules::STATUS_WARNING : Rules::STATUS_OK),
            'summary' => sprintf(
                __('%1$d linked risks: %2$d high or critical, %3$d medium.', 'grcmanager'),
                count($risks),
                $high,
                $medium
            ),
            'facts'   => array_slice(array_column($risks, 'title'), 0, 5),
            'url'     => AssetSecurity360::tabUrl($item, Risk::class),
        ];
    }
}
