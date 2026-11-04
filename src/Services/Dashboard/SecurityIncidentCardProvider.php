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

namespace GlpiPlugin\Grcmanager\Services\Dashboard;

use Entity;
use Glpi\Dashboard\Provider;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Grcmanager\SecurityIncidentPlaybookTask;
use GlpiPlugin\Grcmanager\SecurityIncidentTeam;
use GlpiPlugin\Grcmanager\SecurityIncidentTechnique;
use GlpiPlugin\Grcmanager\Services\Incident\MitreAttack;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use ITILCategory;
use PluginGrcmanagerSecurityIncident;
use Toolbox;

/**
 * Cartes de tableau de bord des incidents de sécurité. Toutes passent par SecuredDashboardCards
 * (droit de lecture des incidents et groupe sécurité) et ne comptent que les incidents que
 * l'utilisateur peut ouvrir (issue #143) : hors corbeille et hors exercices de crise, dans les
 * entités actives, et, sans « Voir tout », seulement ceux où il est acteur ou membre de l'équipe
 * d'intervention (SecurityIncidentTeam::viewCriteria(), même règle que canViewItem()).
 *
 * Les répartitions mènent à la recherche des incidents, qui applique elle-même ses droits : un
 * lien ne fait que pré-remplir des critères. Les filtres du tableau de bord (dates, catégorie,
 * groupe...) sont appliqués par les fournisseurs de GLPI (Provider::getFiltersCriteria()).
 */
final class SecurityIncidentCardProvider
{
    /** Champs de recherche des incidents utilisés par les liens. */
    private const SO_STATUS = 12;
    private const SO_CATEGORY = 7;
    private const SO_ENTITY = 80;
    private const SO_CLASSIFICATION = 220;
    private const SO_SEVERITY = 221;
    private const SO_EXERCISE = 232;

    /**
     * Critères communs : incidents visibles par l'utilisateur, filtres du tableau de bord compris.
     *
     * @param array<string, mixed> $params paramètres de la carte (apply_filters)
     * @param list<mixed> $extra critères supplémentaires, combinés en ET
     * @return array<string, mixed> FROM, WHERE et jointures éventuelles des filtres
     */
    public static function scope(array $params = [], array $extra = []): array
    {
        $table = PluginGrcmanagerSecurityIncident::getTable();
        $where = [["$table.is_deleted" => 0, "$table.is_exercise" => 0]];
        $entities = \getEntitiesRestrictCriteria($table, '', '', true);
        if ($entities !== []) {
            $where[] = $entities;
        }
        foreach (array_merge(SecurityIncidentTeam::viewCriteria(), $extra) as $criterion) {
            $where[] = $criterion;
        }
        $criteria = ['FROM' => $table];
        $filters = Provider::getFiltersCriteria($table, (array) ($params['apply_filters'] ?? []));
        if (!empty($filters['WHERE'])) {
            $where[] = $filters['WHERE'];
        }
        if (!empty($filters['LEFT JOIN'])) {
            $criteria['LEFT JOIN'] = $filters['LEFT JOIN'];
        }
        $criteria['WHERE'] = $where;

        return $criteria;
    }

    /**
     * Statuts « en cours » : ni résolus, ni clos (valeur « notold » de la recherche).
     *
     * @return list<mixed>
     */
    private static function openCriteria(): array
    {
        $table = PluginGrcmanagerSecurityIncident::getTable();

        return [['NOT' => ["$table.status" => array_merge(
            PluginGrcmanagerSecurityIncident::getSolvedStatusArray(),
            PluginGrcmanagerSecurityIncident::getClosedStatusArray()
        )]]];
    }

    /**
     * Critères de recherche des incidents en cours. La valeur « notold » du statut n'est comprise
     * par GLPI que pour ses propres tickets, problèmes et changements : ici, on écarte les statuts un
     * à un.
     *
     * @return list<array{field: int, searchtype: string, value: int|string}>
     */
    private static function openSearchCriteria(): array
    {
        $criteria = [];
        foreach (
            array_merge(
                PluginGrcmanagerSecurityIncident::getSolvedStatusArray(),
                PluginGrcmanagerSecurityIncident::getClosedStatusArray()
            ) as $status
        ) {
            $criteria[] = ['field' => self::SO_STATUS, 'searchtype' => 'notequals', 'value' => (int) $status];
        }

        return $criteria;
    }

    /**
     * Lien vers la recherche des incidents, filtres du tableau de bord compris et hors exercices de
     * crise, pour retrouver le nombre affiché par la carte.
     *
     * @param list<array{field: int, searchtype: string, value: int|string}> $criteria
     * @param array<string, mixed> $params
     */
    public static function searchUrl(array $criteria, array $params = []): string
    {
        $filters = Provider::getSearchFiltersCriteria(
            PluginGrcmanagerSecurityIncident::getTable(),
            (array) ($params['apply_filters'] ?? [])
        )['criteria'] ?? [];
        $url = PluginGrcmanagerSecurityIncident::getSearchURL();

        return $url . (str_contains($url, '?') ? '&' : '?') . Toolbox::append_params([
            'criteria' => array_merge(
                $filters,
                $criteria,
                [['field' => self::SO_EXERCISE, 'searchtype' => 'equals', 'value' => 0]]
            ),
            'reset'    => 'reset',
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<mixed> $extra
     */
    private static function count(array $params, array $extra = []): int
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $row = $DB->request(['SELECT' => ['COUNT DISTINCT' => "$table.id AS cpt"]] + self::scope($params, $extra))
            ->current();

        return (int) ($row['cpt'] ?? 0);
    }

    /**
     * Nombre d'incidents par valeur d'une colonne de l'incident.
     *
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    private static function countBy(string $field, array $params): array
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $counts = [];
        $request = [
            'SELECT'  => ["$table.$field AS value", 'COUNT DISTINCT' => "$table.id AS cpt"],
            'GROUPBY' => "$table.$field",
        ] + self::scope($params);
        foreach ($DB->request($request) as $row) {
            $counts[(string) $row['value']] = (int) $row['cpt'];
        }

        return $counts;
    }

    /**
     * Sans donnée, les widgets de GLPI affichent « Aucune donnée » au lieu d'un cadre vide (même
     * convention que Glpi\Dashboard\Provider).
     *
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private static function orNoData(array $data): array
    {
        return $data === [] ? ['nodata' => true] : $data;
    }

    /**
     * Répartition selon une liste de valeurs connues, chaque part menant à la recherche.
     *
     * @param array<string, string> $labels valeur => libellé
     * @param array<string, mixed> $params
     * @return list<array{label: string, number: int, url: string}>
     */
    private static function breakdown(string $field, int $searchOption, array $labels, array $params): array
    {
        $counts = self::countBy($field, $params);
        $data = [];
        foreach ($labels as $value => $label) {
            $data[] = [
                'label'  => $label,
                'number' => $counts[(string) $value] ?? 0,
                'url'    => self::searchUrl(
                    [['field' => $searchOption, 'searchtype' => 'equals', 'value' => $value]],
                    $params
                ),
            ];
        }

        return $data;
    }

    /**
     * @return array{number: int, url: string, label: string, icon: string}
     */
    public static function incidentsTotal(array $params = []): array
    {
        return [
            'number' => self::count($params),
            'url'    => self::searchUrl([], $params),
            'label'  => $params['label'] ?? __('Security incidents', 'grcmanager'),
            'icon'   => PluginGrcmanagerSecurityIncident::getIcon(),
        ];
    }

    /**
     * @return array{number: int, url: string, label: string, icon: string}
     */
    public static function open(array $params = []): array
    {
        return [
            'number' => self::count($params, self::openCriteria()),
            'url'    => self::searchUrl(self::openSearchCriteria(), $params),
            'label'  => $params['label'] ?? _x('security incidents', 'Open', 'grcmanager'),
            'icon'   => PluginGrcmanagerSecurityIncident::getIcon(),
        ];
    }

    /**
     * Issue #372 : incidents en cours de sévérité critique.
     *
     * @return array{number: int, url: string, label: string, icon: string}
     */
    public static function openCritical(array $params = []): array
    {
        $table = PluginGrcmanagerSecurityIncident::getTable();

        return [
            'number' => self::count($params, array_merge(self::openCriteria(), [["$table.severity" => 'critical']])),
            'url'    => self::searchUrl(array_merge(self::openSearchCriteria(), [
                ['field' => self::SO_SEVERITY, 'searchtype' => 'equals', 'value' => 'critical'],
            ]), $params),
            'label'  => $params['label'] ?? __('Open critical security incidents', 'grcmanager'),
            'icon'   => 'ti ti-urgent',
        ];
    }

    /**
     * @return array{data: list<array{label: string, number: int, url: string}>, label: string, icon: string}
     */
    public static function byStatus(array $params = []): array
    {
        return [
            'data'  => self::breakdown(
                'status',
                self::SO_STATUS,
                array_map('strval', PluginGrcmanagerSecurityIncident::getAllStatusArray()),
                $params
            ),
            'label' => $params['label'] ?? __('Security incidents by status', 'grcmanager'),
            'icon'  => 'ti ti-chart-pie',
        ];
    }

    /**
     * @return array{data: list<array{label: string, number: int, url: string}>, label: string, icon: string}
     */
    public static function bySeverity(array $params = []): array
    {
        return [
            'data'  => self::breakdown(
                'severity',
                self::SO_SEVERITY,
                PluginGrcmanagerSecurityIncident::getSeverities(),
                $params
            ),
            'label' => $params['label'] ?? __('Security incidents by severity', 'grcmanager'),
            'icon'  => 'ti ti-chart-pie',
        ];
    }

    /**
     * Issue #372 : répartition par catégorie d'incident (classification ISO 27001).
     *
     * @return array{data: array<int|string, mixed>, label: string, icon: string}
     */
    public static function byClassification(array $params = []): array
    {
        return [
            'data'  => self::orNoData(array_values(array_filter(
                self::breakdown(
                    'category',
                    self::SO_CLASSIFICATION,
                    PluginGrcmanagerSecurityIncident::getCategories(),
                    $params
                ),
                static fn (array $entry): bool => $entry['number'] > 0
            ))),
            'label' => $params['label'] ?? __('Security incidents by incident category', 'grcmanager'),
            'icon'  => 'ti ti-category',
        ];
    }

    /**
     * Répartition par entité ou par catégorie GLPI : seules les entités et catégories des
     * incidents visibles sont nommées.
     *
     * @param class-string<\CommonDBTM> $fkItemtype
     * @return list<array{label: string, number: int, url: string}>
     */
    private static function byForeignKey(string $fkItemtype, int $searchOption, array $params): array
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $fkTable = $fkItemtype::getTable();
        $fkField = getForeignKeyFieldForItemType($fkItemtype);
        $request = self::scope($params);
        $request['LEFT JOIN'] = ($request['LEFT JOIN'] ?? []) + [
            $fkTable => ['ON' => [$fkTable => 'id', $table => $fkField]],
        ];
        $request += [
            'SELECT'  => [
                "$table.$fkField AS fk_id",
                "$fkTable.completename AS fk_name",
                'COUNT DISTINCT' => "$table.id AS cpt",
            ],
            'GROUPBY' => ["$table.$fkField", "$fkTable.completename"],
            'ORDER'   => 'cpt DESC',
            'LIMIT'   => 50,
        ];
        $data = [];
        foreach ($DB->request($request) as $row) {
            $data[] = [
                'label'  => (string) ($row['fk_name'] ?? '') !== '' ? (string) $row['fk_name'] : __('None'),
                'number' => (int) $row['cpt'],
                'url'    => self::searchUrl(
                    [['field' => $searchOption, 'searchtype' => 'equals', 'value' => (int) $row['fk_id']]],
                    $params
                ),
            ];
        }

        return $data;
    }

    /**
     * @return array{data: array<int|string, mixed>, label: string, icon: string}
     */
    public static function incidentsByEntity(array $params = []): array
    {
        return [
            'data'  => self::orNoData(self::byForeignKey(Entity::class, self::SO_ENTITY, $params)),
            'label' => $params['label'] ?? __('Security incidents by entity', 'grcmanager'),
            'icon'  => Entity::getIcon(),
        ];
    }

    /**
     * @return array{data: array<int|string, mixed>, label: string, icon: string}
     */
    public static function incidentsByItilCategory(array $params = []): array
    {
        return [
            'data'  => self::orNoData(self::byForeignKey(ITILCategory::class, self::SO_CATEGORY, $params)),
            'label' => $params['label'] ?? __('Security incidents by GLPI category', 'grcmanager'),
            'icon'  => ITILCategory::getIcon(),
        ];
    }

    /**
     * Les 12 derniers mois, du plus ancien au mois en cours.
     *
     * @return list<string> AAAA-MM
     */
    public static function lastTwelveMonths(?int $now = null): array
    {
        $now ??= time();
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = date('Y-m', (int) mktime(0, 0, 0, (int) date('n', $now) - $i, 1, (int) date('Y', $now)));
        }

        return $months;
    }

    /**
     * @param list<string> $months
     * @param array<string, int> $counts mois => nombre
     * @return list<int>
     */
    public static function alignOnMonths(array $months, array $counts): array
    {
        return array_map(static fn (string $month): int => $counts[$month] ?? 0, $months);
    }

    /**
     * Nombre d'incidents par mois d'une date (déclaration ou résolution), depuis le premier mois.
     *
     * @param list<string> $months
     * @return array<string, int>
     */
    private static function countByMonth(string $dateExpression, array $months, array $params): array
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $request = [
            'SELECT'  => [new QueryExpression("DATE_FORMAT($dateExpression, '%Y-%m') AS month"),
                'COUNT DISTINCT' => "$table.id AS cpt"],
            'GROUPBY' => 'month',
        ] + self::scope($params, [
            new QueryExpression($dateExpression . ' >= ' . $DB->quoteValue($months[0] . '-01 00:00:00')),
        ]);
        $counts = [];
        foreach ($DB->request($request) as $row) {
            $counts[(string) $row['month']] = (int) $row['cpt'];
        }

        return $counts;
    }

    /**
     * Issue #372 : incidents déclarés et résolus par mois, sur 12 mois.
     *
     * @return array{data: array{labels: list<string>, series: list<array{name: string, data: list<int>}>},
     *     label: string, icon: string}
     */
    public static function incidentsMonthlyTrend(array $params = []): array
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $months = self::lastTwelveMonths();
        $declared = self::countByMonth($DB->quoteName("$table.date"), $months, $params);
        $resolved = self::countByMonth(
            'COALESCE(' . $DB->quoteName("$table.solvedate") . ', ' . $DB->quoteName("$table.closedate") . ')',
            $months,
            $params
        );

        return [
            'data'  => [
                'labels' => $months,
                'series' => [
                    ['name' => __('Declared', 'grcmanager'), 'data' => self::alignOnMonths($months, $declared)],
                    ['name' => __('Resolved', 'grcmanager'), 'data' => self::alignOnMonths($months, $resolved)],
                ],
            ],
            'label' => $params['label'] ?? __('Security incidents over 12 months', 'grcmanager'),
            'icon'  => 'ti ti-chart-line',
        ];
    }

    /**
     * Incidents en cours soumis à NIS2 ou au RGPD (une seule requête).
     *
     * @return list<array<string, mixed>>
     */
    public static function regulatedOpenIncidents(array $params = []): array
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $select = ["$table.id", "$table.name", "$table.date", "$table.nis2_applicable", "$table.personal_data_breach"];
        foreach (RegulatoryDeadlines::DEADLINES as [, , $doneField]) {
            $select[] = "$table.$doneField";
        }

        return iterator_to_array($DB->request(['SELECT' => $select] + self::scope($params, [
            ['NOT' => ["$table.status" => PluginGrcmanagerSecurityIncident::getClosedStatusArray()]],
            ['OR' => ["$table.nis2_applicable" => 1, "$table.personal_data_breach" => 1]],
        ])), false);
    }

    /**
     * Notifications NIS2 / RGPD non encore faites, la plus urgente d'abord.
     *
     * @param list<array<string, mixed>> $incidents
     * @return list<array{id: int, name: string, key: string, due: string, status: string}>
     */
    public static function pendingNotifications(array $incidents, ?int $now = null): array
    {
        $pending = [];
        foreach ($incidents as $incident) {
            foreach (RegulatoryDeadlines::banners($incident, $now) as $deadline) {
                $pending[] = [
                    'id'     => (int) $incident['id'],
                    'name'   => (string) $incident['name'],
                    'key'    => $deadline['key'],
                    'due'    => $deadline['due'],
                    'status' => $deadline['status'],
                ];
            }
        }
        usort($pending, static fn (array $a, array $b): int => strcmp($a['due'], $b['due']));

        return $pending;
    }

    /**
     * Issue #372 : notifications réglementaires à suivre, chacune menant à son incident.
     *
     * @return array{data: array<int|string, mixed>, label: string, icon: string, date: string}
     */
    public static function regulatoryNotifications(array $params = []): array
    {
        $labels = PluginGrcmanagerSecurityIncident::getRegulatoryLabels();
        $data = [];
        foreach (array_slice(self::pendingNotifications(self::regulatedOpenIncidents($params)), 0, 10) as $row) {
            $text = $row['status'] === RegulatoryDeadlines::STATUS_OVERDUE
                ? __('Overdue since %s', 'grcmanager')
                : __('Due by %s', 'grcmanager');
            // Le widget « liste » de GLPI n'affiche que le contenu de chaque entrée (texte riche).
            $data[] = [
                'label'   => $row['name'],
                'content' => '<p><strong>' . \htmlescape($row['name']) . '</strong><br>'
                    . \htmlescape(($labels[$row['key']] ?? $row['key']) . ' : '
                    . sprintf($text, (string) \Html::convDateTime($row['due']))) . '</p>',
                'author'  => '',
                'url'     => PluginGrcmanagerSecurityIncident::getFormURLWithID($row['id']),
            ];
        }

        return [
            'data'  => self::orNoData($data),
            'label' => $params['label'] ?? __('Regulatory notifications to follow', 'grcmanager'),
            'icon'  => 'ti ti-alarm',
            // Le widget « liste » de GLPI affiche cette date sur chaque ligne (« NaN » si vide) : celle
            // du calcul, jamais une donnée de l'incident.
            'date'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Nombre d'échéances NIS2 / RGPD proches ou dépassées (carte de pilotage, issue #143).
     */
    public static function regulatoryDeadlinesAtRisk(array $params = []): int
    {
        $alerting = [RegulatoryDeadlines::STATUS_OVERDUE, RegulatoryDeadlines::STATUS_DUE_SOON];

        return count(array_filter(
            self::pendingNotifications(self::regulatedOpenIncidents($params)),
            static fn (array $row): bool => in_array($row['status'], $alerting, true)
        ));
    }

    /**
     * Nombre d'incidents visibles par valeur d'une colonne des techniques MITRE ATT&CK (une
     * tactique ou une technique compte une fois par incident).
     *
     * @return array<string, int>
     */
    private static function attackCounts(string $column, ?int $limit = null): array
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $techniques = SecurityIncidentTechnique::getTable();
        $request = self::scope();
        $request['FROM'] = $techniques;
        $request['INNER JOIN'] = [
            $table => ['ON' => [$table => 'id', $techniques => 'plugin_grcmanager_securityincidents_id']],
        ];
        $request += [
            'SELECT'  => ["$techniques.$column AS value", 'COUNT DISTINCT' => "$table.id AS cpt"],
            'GROUPBY' => "$techniques.$column",
            'ORDER'   => ['cpt DESC', 'value ASC'],
        ];
        if ($limit !== null) {
            $request['LIMIT'] = $limit;
        }
        $counts = [];
        foreach ($DB->request($request) as $row) {
            $counts[(string) $row['value']] = (int) $row['cpt'];
        }

        return $counts;
    }

    /**
     * Issue #369 : nombre d'incidents par tactique MITRE ATT&CK, dans l'ordre de la chaîne d'attaque.
     *
     * @return array{data: array<int|string, mixed>, label: string, icon: string}
     */
    public static function attackTactics(array $params = []): array
    {
        $counts = self::attackCounts('tactic');
        $data = [];
        foreach (SecurityIncidentTechnique::translatedTactics() as $id => $name) {
            if (($counts[$id] ?? 0) > 0) {
                $data[] = ['label' => $name, 'number' => $counts[$id]];
            }
        }

        return [
            'data'  => self::orNoData($data),
            'label' => $params['label'] ?? __('Security incidents by MITRE ATT&CK tactic', 'grcmanager'),
            'icon'  => SecurityIncidentTechnique::getIcon(),
        ];
    }

    /**
     * Issue #369 : les 10 techniques MITRE ATT&CK les plus fréquentes.
     *
     * @return array{data: array<int|string, mixed>, label: string, icon: string}
     */
    public static function attackTopTechniques(array $params = []): array
    {
        $data = [];
        foreach (self::attackCounts('technique', 10) as $id => $count) {
            $data[] = ['label' => $id . ' ' . MitreAttack::fullName((string) $id), 'number' => $count];
        }

        return [
            'data'  => self::orNoData($data),
            'label' => $params['label'] ?? __('Most frequent MITRE ATT&CK techniques', 'grcmanager'),
            'icon'  => SecurityIncidentTechnique::getIcon(),
        ];
    }

    /**
     * Issue #370 : tâches de remédiation non terminées assignées à l'utilisateur connecté.
     *
     * @return array{number: int, label: string, icon: string, url: string}
     */
    public static function myRemediationTasksCount(array $params = []): array
    {
        $tasks = SecurityIncidentPlaybookTask::getOpenTasksFor((int) \Session::getLoginUserID());

        return [
            'number' => count($tasks),
            'label'  => $params['label'] ?? __('My open remediation tasks', 'grcmanager'),
            'icon'   => SecurityIncidentPlaybookTask::getIcon(),
            'url'    => $tasks === [] ? '' : PluginGrcmanagerSecurityIncident::getFormURLWithID(
                (int) $tasks[0]['plugin_grcmanager_securityincidents_id']
            ),
        ];
    }

    /**
     * Issue #370 : liste de ces tâches, chacune menant à son incident.
     *
     * @return array{data: list<array<string, string>>, label: string, icon: string, date: string}
     */
    public static function myRemediationTasks(array $params = []): array
    {
        $data = [];
        foreach (SecurityIncidentPlaybookTask::getOpenTasksFor((int) \Session::getLoginUserID()) as $task) {
            $data[] = [
                'label'   => (string) $task['name'],
                'content' => \htmlescape((string) $task['incident_name']),
                'author'  => '',
                'url'     => PluginGrcmanagerSecurityIncident::getFormURLWithID(
                    (int) $task['plugin_grcmanager_securityincidents_id']
                ),
            ];
        }

        return [
            'data'  => $data,
            'label' => $params['label'] ?? __('My remediation tasks', 'grcmanager'),
            'icon'  => SecurityIncidentPlaybookTask::getIcon(),
            'date'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Issue #370 : délai moyen entre la détection et le confinement, en heures.
     *
     * @return array{number: int, label: string, icon: string}
     */
    public static function averageTimeToContain(array $params = []): array
    {
        return [
            'number' => self::averageHoursTo('contained_at'),
            'label'  => $params['label'] ?? __('Average time to containment (hours)', 'grcmanager'),
            'icon'   => 'ti ti-shield-half',
        ];
    }

    /**
     * Issue #370 : délai moyen entre la détection et la clôture, en heures.
     *
     * @return array{number: int, label: string, icon: string}
     */
    public static function averageTimeToClose(array $params = []): array
    {
        return [
            'number' => self::averageHoursTo('closedate'),
            'label'  => $params['label'] ?? __('Average time to closure (hours)', 'grcmanager'),
            'icon'   => 'ti ti-flag-check',
        ];
    }

    /**
     * Moyenne en heures entre la détection (`date`) et un horodatage, calculée par la base sur les
     * incidents visibles où il est renseigné et postérieur à la détection.
     */
    public static function averageHoursTo(string $field): int
    {
        global $DB;

        $table = PluginGrcmanagerSecurityIncident::getTable();
        $start = $DB->quoteName("$table.date");
        $end = $DB->quoteName("$table.$field");
        $row = $DB->request([
            'SELECT' => [new QueryExpression("AVG(TIMESTAMPDIFF(SECOND, $start, $end)) AS avg_seconds")],
        ] + self::scope([], [
            ['NOT' => ["$table.$field" => null]],
            new QueryExpression("$end >= $start"),
        ]))->current();

        return $row === null || $row['avg_seconds'] === null ? 0 : (int) round((float) $row['avg_seconds'] / 3600);
    }
}
