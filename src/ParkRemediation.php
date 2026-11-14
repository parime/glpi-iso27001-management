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

use Config;
use GlpiPlugin\Grcmanager\Services\Classification\ClassificationLevels;
use GlpiPlugin\Grcmanager\Services\Cve\RemediationRules as Rules;
use GlpiPlugin\Grcmanager\Services\Waiver\WaiverRules;
use Html;
use Session;

/**
 * Issue #601 : politique de délais de correction des vulnérabilités du parc et ses indicateurs
 * (ISO 27001 A.8.8, NIS2). Règles dans Services\Cve\RemediationRules.
 *
 * Chaque vulnérabilité du parc (couple CVE et produit du catalogue) est suivie dans sa propre table,
 * indépendante de la réponse de NVD (remplacée à chaque vérification) : sa date de première
 * détection est donc conservée. Le suivi est mis à jour par la tâche planifiée des vulnérabilités
 * du parc, et à l'ouverture de la page quand la politique est active.
 *
 * Une dérogation de sécurité validée peut couvrir des CVE (onglet de la dérogation) : tant qu'elle
 * est en cours, l'échéance devient sa date de fin. Rien n'est modifié automatiquement, ni ticket ni
 * dérogation.
 */
final class ParkRemediation
{
    public const TABLE = 'glpi_plugin_grcmanager_parkcveremediations';

    public const WAIVER_CVES_TABLE = 'glpi_plugin_grcmanager_waivers_cves';

    private const CONFIG_CONTEXT = 'plugin:grcmanager';

    private const CONFIG_KEYS = [
        'park_sla_enabled', 'park_sla_days_kev', 'park_sla_days_critical', 'park_sla_days_high',
        'park_sla_days_medium', 'park_sla_days_low', 'park_sla_critical_percent',
    ];

    /**
     * @return array{enabled: bool, days: array<string, int>, critical_percent: int}
     */
    public static function getSettings(): array
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, self::CONFIG_KEYS);
        $days = [];
        foreach (Rules::LEVELS as $level) {
            $days[$level] = $values['park_sla_days_' . $level] ?? null;
        }

        return Rules::settings([
            'enabled'          => $values['park_sla_enabled'] ?? false,
            'days'             => $days,
            'critical_percent' => $values['park_sla_critical_percent'] ?? null,
        ]);
    }

    public static function isEnabled(): bool
    {
        return self::getSettings()['enabled'];
    }

    /**
     * @param array<string, mixed> $input Champs du formulaire de configuration.
     */
    public static function saveSettings(array $input): void
    {
        $days = [];
        foreach (Rules::LEVELS as $level) {
            $days[$level] = $input['park_sla_days_' . $level] ?? null;
        }
        $settings = Rules::settings([
            'enabled'          => !empty($input['park_sla_enabled']),
            'days'             => $days,
            'critical_percent' => $input['park_sla_critical_percent'] ?? null,
        ]);
        $values = ['park_sla_enabled' => (int) $settings['enabled'], 'park_sla_critical_percent' => $settings['critical_percent']];
        foreach ($settings['days'] as $level => $value) {
            $values['park_sla_days_' . $level] = $value;
        }
        Config::setConfigurationValues(self::CONFIG_CONTEXT, $values);
    }

    /**
     * @return array<string, string>
     */
    public static function getLevelLabels(): array
    {
        return [
            Rules::KEV      => __('Actively exploited (CISA KEV)', 'grcmanager'),
            Rules::CRITICAL => __('Critical (CVSS 9 or more)', 'grcmanager'),
            Rules::HIGH     => __('High (CVSS 7 to 8.9)', 'grcmanager'),
            Rules::MEDIUM   => __('Medium (CVSS 4 to 6.9, or unknown)', 'grcmanager'),
            Rules::LOW      => __('Low (CVSS below 4)', 'grcmanager'),
        ];
    }

    /**
     * Libellé et couleur du badge d'un état.
     *
     * @param array{status: string, due: string, days: int, on_time: ?bool} $state
     * @return array{label: string, class: string, icon: string}
     */
    public static function badge(array $state): array
    {
        $due = (string) Html::convDate($state['due']);

        return match ($state['status']) {
            Rules::STATUS_OVERDUE  => [
                'label' => sprintf(_n('Overdue by %d day', 'Overdue by %d days', -$state['days'], 'grcmanager'), -$state['days']),
                'class' => 'bg-red text-white',
                'icon'  => 'ti ti-alarm',
            ],
            Rules::STATUS_DUE_SOON => [
                'label' => $state['days'] === 0
                    ? __('Due today', 'grcmanager')
                    : sprintf(_n('Due in %d day', 'Due in %d days', $state['days'], 'grcmanager'), $state['days']),
                'class' => 'bg-orange text-white',
                'icon'  => 'ti ti-hourglass-high',
            ],
            Rules::STATUS_WAIVED   => [
                'label' => sprintf(__('Waiver until %s', 'grcmanager'), $due),
                'class' => 'bg-blue-lt',
                'icon'  => 'ti ti-file-alert',
            ],
            Rules::STATUS_FIXED    => [
                'label' => $state['on_time'] ? __('Fixed on time', 'grcmanager') : __('Fixed late', 'grcmanager'),
                'class' => $state['on_time'] ? 'bg-green-lt' : 'bg-secondary-lt',
                'icon'  => 'ti ti-circle-check',
            ],
            default                => [
                'label' => sprintf(__('Due %s', 'grcmanager'), $due),
                'class' => 'bg-green-lt',
                'icon'  => 'ti ti-calendar-check',
            ],
        };
    }

    /**
     * Met à jour le suivi à partir des vulnérabilités actuelles de tout le parc : nouvelles,
     * réapparues, changées de niveau, et corrigées (disparues).
     *
     * @return int Vulnérabilités suivies ajoutées ou modifiées.
     */
    public static function sync(?string $today = null): int
    {
        global $DB;

        $today ??= date('Y-m-d');
        $settings = self::getSettings();
        $context = self::versionContext();
        $records = self::records();
        $now = date('Y-m-d H:i:s');
        $changed = 0;
        $seen = [];
        foreach (ParkVulnerability::getVulnerabilityRows(false, []) as $row) {
            $key = $row['canonical_id'] . "\0" . $row['cve_id'];
            $seen[$key] = true;
            $critical = false;
            $entities = [];
            foreach ($row['versions'] as $version) {
                $info = $context[$row['canonical_id'] . "\0" . $version] ?? null;
                if ($info !== null) {
                    $critical = $critical || $info['critical'];
                    $entities = array_merge($entities, $info['entities']);
                }
            }
            $entities = array_values(array_unique($entities));
            sort($entities);
            $existing = $records[$key] ?? null;
            $next = Rules::observe(
                $existing,
                Rules::level($row['cvss'], (bool) $row['is_kev']),
                $critical,
                $today,
                $settings
            );
            $fields = self::toColumns($next) + ['entities' => json_encode($entities)];
            if ($existing === null) {
                $DB->insert(self::TABLE, [
                    'plugin_grcmanager_canonicalproducts_id' => $row['canonical_id'],
                    'cve_id'                                 => $row['cve_id'],
                    'date_creation'                          => $now,
                    'date_mod'                               => $now,
                ] + $fields);
                $changed++;
            } elseif (self::differs($existing, $fields)) {
                $DB->update(self::TABLE, $fields + ['date_mod' => $now], ['id' => $existing['id']]);
                $changed++;
            }
        }
        foreach ($records as $key => $record) {
            if (!isset($seen[$key]) && $record['date_fixed'] === null) {
                $DB->update(self::TABLE, ['date_fixed' => $today, 'date_mod' => $now], ['id' => $record['id']]);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Ajoute à chaque ligne de ParkVulnerability::getVulnerabilityRows() son état de correction
     * (clé « remediation », null quand la politique est désactivée ou la vulnérabilité pas encore
     * suivie).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function annotate(array $rows, ?string $today = null): array
    {
        $settings = self::getSettings();
        if (!$settings['enabled'] || $rows === []) {
            return array_map(static fn (array $row): array => $row + ['remediation' => null], $rows);
        }
        $today ??= date('Y-m-d');
        $records = self::records(array_values(array_unique(array_column($rows, 'cve_id'))));
        $waivers = self::waiversByCve(array_values(array_unique(array_column($rows, 'cve_id'))));
        $canReadWaivers = Session::haveRight(Waiver::RIGHTNAME, READ);
        foreach ($rows as $i => $row) {
            $record = $records[$row['canonical_id'] . "\0" . $row['cve_id']] ?? null;
            if ($record === null) {
                $rows[$i]['remediation'] = null;
                continue;
            }
            $waiver = $waivers[$row['cve_id']] ?? null;
            $state = Rules::state($record, $waiver['date_end'] ?? null, $today, $settings);
            $rows[$i]['remediation'] = $state + [
                'badge'      => self::badge($state),
                'first_seen' => $record['date_first_seen'],
                'level_label' => self::getLevelLabels()[$state['level']] ?? '',
                'critical'   => $record['is_critical'],
                'waiver'     => $waiver === null ? null : [
                    'name' => $canReadWaivers ? $waiver['name'] : '',
                    'url'  => $canReadWaivers ? Waiver::getFormURLWithID($waiver['id']) : '',
                    'end'  => (string) Html::convDate($waiver['date_end']),
                ],
            ];
        }

        return $rows;
    }

    /**
     * Indicateurs de respect des délais. Pour un utilisateur, seules les vulnérabilités vues sur des
     * machines de ses entités actives comptent ; pour la tâche planifiée et le rapport par e-mail
     * (sans session), tout le parc.
     *
     * @return array<string, mixed>|null Null quand la politique est désactivée.
     */
    public static function indicators(bool $restrictToSession = true, ?string $today = null): ?array
    {
        $settings = self::getSettings();
        if (!$settings['enabled']) {
            return null;
        }
        $today ??= date('Y-m-d');
        $active = $restrictToSession ? array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [])) : [];
        $records = self::records();
        $waivers = self::waiversByCve(array_values(array_unique(array_column($records, 'cve_id'))));
        $states = [];
        foreach ($records as $record) {
            if ($active !== [] && array_intersect($record['entities'], $active) === []) {
                continue;
            }
            $waiver = $waivers[$record['cve_id']] ?? null;
            $states[] = Rules::state($record, $waiver['date_end'] ?? null, $today, $settings)
                + ['date_fixed' => $record['date_fixed']];
        }
        $indicators = Rules::indicators($states, $today, $settings);
        $labels = self::getLevelLabels();
        foreach ($indicators['by_level'] as $level => $counts) {
            $indicators['by_level'][$level]['label'] = $labels[$level];
        }

        return $indicators;
    }

    /**
     * Phrase de synthèse du rapport par e-mail, vide quand la politique est désactivée.
     */
    public static function digestSummary(): string
    {
        $indicators = self::indicators(false);
        if ($indicators === null) {
            return '';
        }
        $parts = [];
        if ($indicators['overdue'] > 0) {
            $levels = [];
            foreach ($indicators['by_level'] as $counts) {
                if ($counts['overdue'] > 0) {
                    $levels[] = sprintf(__('%1$d %2$s', 'grcmanager'), $counts['overdue'], mb_strtolower($counts['label']));
                }
            }
            $parts[] = sprintf(
                _n(
                    '%1$d vulnerability past its remediation deadline (%2$s), the oldest by %3$d days',
                    '%1$d vulnerabilities past their remediation deadline (%2$s), the oldest by %3$d days',
                    $indicators['overdue'],
                    'grcmanager'
                ),
                $indicators['overdue'],
                implode(', ', $levels),
                (int) $indicators['oldest_overdue']
            );
        } else {
            $parts[] = __('No vulnerability past its remediation deadline', 'grcmanager');
        }
        if ($indicators['rate'] !== null) {
            $parts[] = sprintf(
                __('%1$d %% fixed on time over %2$d days, in %3$s days on average', 'grcmanager'),
                $indicators['rate'],
                Rules::WINDOW_DAYS,
                number_format((float) $indicators['mttr'], 1)
            );
        }
        if ($indicators['waived'] > 0) {
            $parts[] = sprintf(
                _n('%d covered by a waiver', '%d covered by a waiver', $indicators['waived'], 'grcmanager'),
                $indicators['waived']
            );
        }

        return sprintf(__('Remediation deadlines: %s.', 'grcmanager'), implode('; ', $parts));
    }

    /**
     * Échéance à reprendre dans un ticket de mise à jour : la plus proche des vulnérabilités
     * listées, ou null sans politique active.
     *
     * @param list<array<string, mixed>> $rows Lignes annotées (annotate()).
     * @return array{due: string, overdue: bool}|null
     */
    public static function earliestDue(array $rows): ?array
    {
        $earliest = null;
        foreach ($rows as $row) {
            $state = $row['remediation'] ?? null;
            if (!is_array($state) || $state['status'] === Rules::STATUS_FIXED) {
                continue;
            }
            if ($earliest === null || $state['due'] < $earliest['due']) {
                $earliest = ['due' => $state['due'], 'overdue' => $state['status'] === Rules::STATUS_OVERDUE];
            }
        }

        return $earliest;
    }

    /**
     * CVE couvertes par une dérogation.
     *
     * @return list<string>
     */
    public static function getWaiverCves(int $waiverId): array
    {
        global $DB;

        $cves = [];
        if ($waiverId <= 0) {
            return $cves;
        }
        foreach (
            $DB->request([
                'SELECT' => 'cve_id',
                'FROM'   => self::WAIVER_CVES_TABLE,
                'WHERE'  => ['plugin_grcmanager_waivers_id' => $waiverId],
                'ORDER'  => 'cve_id',
            ]) as $row
        ) {
            $cves[] = (string) $row['cve_id'];
        }

        return $cves;
    }

    /**
     * Remplace les CVE couvertes par une dérogation (identifiants lus dans un texte libre).
     */
    public static function setWaiverCves(int $waiverId, string $text): void
    {
        global $DB;

        if ($waiverId <= 0) {
            return;
        }
        $wanted = Rules::parseCveIds($text);
        $current = self::getWaiverCves($waiverId);
        $removed = array_diff($current, $wanted);
        if ($removed !== []) {
            $DB->delete(self::WAIVER_CVES_TABLE, [
                'plugin_grcmanager_waivers_id' => $waiverId,
                'cve_id'                       => array_values($removed),
            ]);
        }
        foreach (array_diff($wanted, $current) as $cveId) {
            $DB->insert(self::WAIVER_CVES_TABLE, [
                'plugin_grcmanager_waivers_id' => $waiverId,
                'cve_id'                       => $cveId,
                'date_creation'                => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function removeWaiverCves(int $waiverId): void
    {
        global $DB;

        $DB->delete(self::WAIVER_CVES_TABLE, ['plugin_grcmanager_waivers_id' => $waiverId]);
    }

    /**
     * Dérogation validée qui couvre chaque CVE, celle qui finit le plus tard (une dérogation
     * demandée, refusée, close ou à la corbeille ne couvre rien).
     *
     * @param list<string> $cveIds
     * @return array<string, array{id: int, name: string, date_end: string}>
     */
    public static function waiversByCve(array $cveIds): array
    {
        global $DB;

        if ($cveIds === []) {
            return [];
        }
        $waivers = Waiver::getTable();
        $links = self::WAIVER_CVES_TABLE;
        $byCve = [];
        foreach (
            $DB->request([
                'SELECT'     => ["$links.cve_id", "$waivers.id", "$waivers.name", "$waivers.date_end"],
                'FROM'       => $links,
                'INNER JOIN' => [$waivers => ['FKEY' => [$links => 'plugin_grcmanager_waivers_id', $waivers => 'id']]],
                'WHERE'      => [
                    "$links.cve_id"       => $cveIds,
                    "$waivers.status"     => WaiverRules::STATUS_APPROVED,
                    "$waivers.is_deleted" => 0,
                    'NOT'                 => ["$waivers.date_end" => null],
                ],
            ]) as $row
        ) {
            $cveId = (string) $row['cve_id'];
            $end = substr((string) $row['date_end'], 0, 10);
            if (!isset($byCve[$cveId]) || $end > $byCve[$cveId]['date_end']) {
                $byCve[$cveId] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'date_end' => $end];
            }
        }

        return $byCve;
    }

    /**
     * Vulnérabilités suivies, par couple produit et CVE.
     *
     * @param list<string>|null $cveIds Restriction facultative.
     * @return array<string, array{id: int, canonical_id: int, cve_id: string, date_first_seen: string,
     *     date_start: string, date_last_seen: string, date_fixed: ?string, level: string, is_critical: bool,
     *     date_level: string, previous_due: ?string, entities: list<int>}>
     */
    public static function records(?array $cveIds = null): array
    {
        global $DB;

        if ($cveIds === []) {
            return [];
        }
        $criteria = ['FROM' => self::TABLE];
        if ($cveIds !== null) {
            $criteria['WHERE'] = ['cve_id' => $cveIds];
        }
        $records = [];
        foreach ($DB->request($criteria) as $row) {
            $entities = json_decode((string) ($row['entities'] ?? ''), true);
            $records[$row['plugin_grcmanager_canonicalproducts_id'] . "\0" . $row['cve_id']] = [
                'id'              => (int) $row['id'],
                'canonical_id'    => (int) $row['plugin_grcmanager_canonicalproducts_id'],
                'cve_id'          => (string) $row['cve_id'],
                'date_first_seen' => (string) $row['date_first_seen'],
                'date_start'      => (string) $row['date_start'],
                'date_last_seen'  => (string) $row['date_last_seen'],
                'date_fixed'      => $row['date_fixed'] !== null ? (string) $row['date_fixed'] : null,
                'level'           => (string) $row['level'],
                'is_critical'     => (bool) $row['is_critical'],
                'date_level'      => (string) $row['date_level'],
                'previous_due'    => $row['previous_due'] !== null ? (string) $row['previous_due'] : null,
                'entities'        => is_array($entities) ? array_map('intval', $entities) : [],
            ];
        }

        return $records;
    }

    /**
     * Pour chaque version installée d'un produit surveillé : entités des machines qui la portent,
     * et présence sur au moins un actif de classification C/I/D élevée.
     *
     * @return array<string, array{entities: list<int>, critical: bool}>
     */
    private static function versionContext(): array
    {
        global $DB;

        $productByAlias = [];
        foreach ($DB->request(['FROM' => ProductAlias::getTable()]) as $row) {
            $productByAlias[(string) $row['alias']] = (int) $row['plugin_grcmanager_canonicalproducts_id'];
        }
        if ($productByAlias === []) {
            return [];
        }
        $base = [
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
            'WHERE'      => [
                'glpi_softwares.name'                          => array_keys($productByAlias),
                'glpi_items_softwareversions.is_deleted'       => 0,
                'glpi_items_softwareversions.is_deleted_item'  => 0,
                'glpi_items_softwareversions.is_template_item' => 0,
            ],
            'DISTINCT'   => true,
        ];
        $context = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'glpi_softwares.name AS software',
                    'glpi_softwareversions.name AS version',
                    'glpi_items_softwareversions.entities_id',
                ],
            ] + $base) as $row
        ) {
            $key = $productByAlias[(string) $row['software']] . "\0" . $row['version'];
            $context[$key] ??= ['entities' => [], 'critical' => false];
            $context[$key]['entities'][] = (int) $row['entities_id'];
        }
        $classifications = AssetClassification::getTable();
        $critical = $base;
        $critical['INNER JOIN'][$classifications] = ['ON' => [
            $classifications              => 'items_id',
            'glpi_items_softwareversions' => 'items_id',
            ['AND' => [
                "$classifications.itemtype" => new \Glpi\DBAL\QueryExpression(
                    $DB->quoteName('glpi_items_softwareversions.itemtype')
                ),
            ]],
        ]];
        $critical['WHERE'][] = ['OR' => array_map(
            static fn (string $axis): array => ["$classifications.$axis" => ClassificationLevels::HIGH],
            ClassificationLevels::AXES
        )];
        foreach (
            $DB->request([
                'SELECT' => ['glpi_softwares.name AS software', 'glpi_softwareversions.name AS version'],
            ] + $critical) as $row
        ) {
            $key = $productByAlias[(string) $row['software']] . "\0" . $row['version'];
            if (isset($context[$key])) {
                $context[$key]['critical'] = true;
            }
        }

        return $context;
    }

    /**
     * @param array{date_first_seen: string, date_start: string, date_last_seen: string, date_fixed: ?string,
     *     level: string, is_critical: bool, date_level: string, previous_due: ?string} $record
     * @return array<string, mixed>
     */
    private static function toColumns(array $record): array
    {
        return ['is_critical' => (int) $record['is_critical']] + $record;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $fields
     */
    private static function differs(array $existing, array $fields): bool
    {
        $existing['entities'] = json_encode($existing['entities']);
        $existing['is_critical'] = (int) $existing['is_critical'];
        foreach ($fields as $column => $value) {
            if (($existing[$column] ?? null) !== $value) {
                return true;
            }
        }

        return false;
    }
}
