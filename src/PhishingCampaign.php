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
use CronTask;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\PhishingCampaignBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\PhishingTables;
use GlpiPlugin\Grcmanager\Services\Phishing\PhishingRules as Rules;
use Html;
use Log;
use Session;

/**
 * Issue #604 : campagne de simulation d'hameçonnage, preuve de sensibilisation (ISO 27001, 7.3 et
 * mesure A.6.3). La campagne est faite avec un outil externe (GoPhish, outil d'un prestataire...) :
 * le plugin n'envoie rien, il en garde la date, l'outil, le scénario, la population ciblée et les
 * résultats importés (PhishingResult).
 *
 * Droit général du plugin et entité de la campagne (check() du cœur) pour la campagne et ses taux.
 * Les résultats par personne sont des données personnelles sensibles : ils exigent en plus le droit
 * des registres sensibles (PhishingResult::canSeeDetail()) ; chacun voit les siens dans ses
 * préférences.
 */
class PhishingCampaign extends PhishingCampaignBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const FK = 'plugin_grcmanager_phishingcampaigns_id';

    /** Exigence et mesure dont la campagne est une preuve. */
    public const CLAUSE_CODE = '7.3';
    public const CONTROL_CODE = 'A.6.3';

    /** Issue #604, lot 2 : durée de conservation des résultats individuels (mois, 0 = sans limite). */
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const RETENTION_KEY = 'phishing_retention_months';

    public static function getTable($classname = null)
    {
        return PhishingTables::CAMPAIGNS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Phishing simulation', 'Phishing simulations', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-fish-hook';
    }

    /** Sous-page de la rubrique « Revue des accès », après les formations. */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    public function defineTabs($options = [])
    {
        // Pas d'onglet Documents : un export brut de l'outil, nominatif, y serait lisible de qui lit
        // les documents, hors du droit des registres sensibles.
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(PhishingResult::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id']) || !Session::haveAccessToEntity((int) $input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }

        return $this->normalizeInput($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        if (array_key_exists('entities_id', $input) && !Session::haveAccessToEntity((int) $input['entities_id'])) {
            unset($input['entities_id']);
        }

        return $this->normalizeInput($input, $this->fields);
    }

    /**
     * Nom obligatoire, date valide ; les compteurs ne viennent que de l'import (jamais du formulaire).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input, array $current)
    {
        foreach (
            [
                'nb_sent', 'nb_opened', 'nb_clicked', 'nb_submitted', 'nb_reported', 'nb_unmatched',
                'group_counts', 'import_file', 'date_import', 'date_anonymized',
            ] as $computed
        ) {
            unset($input[$computed]);
        }
        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
        }
        if (trim((string) ($input['name'] ?? ($current['name'] ?? ''))) === '') {
            Session::addMessageAfterRedirect(__('Give the campaign a name.', 'grcmanager'), false, ERROR);

            return false;
        }
        if (array_key_exists('campaign_date', $input)) {
            $date = trim((string) $input['campaign_date']);
            $parsed = \DateTime::createFromFormat('!Y-m-d', substr($date, 0, 10));
            $input['campaign_date'] = $parsed !== false ? $parsed->format('Y-m-d') : null;
        }

        return $input;
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(PhishingTables::RESULTS, [self::FK => (int) $this->getID()]);
    }

    /**
     * Compteurs de la campagne (gardés après anonymisation).
     *
     * @param array<string, mixed> $fields
     * @return array{sent: int, opened: int, clicked: int, submitted: int, reported: int}
     */
    public static function countsOf(array $fields): array
    {
        $counts = [];
        foreach (Rules::STEPS as $step) {
            $counts[$step] = (int) ($fields['nb_' . $step] ?? 0);
        }

        return $counts;
    }

    /**
     * Campagnes visibles (droit et entités de la session), de la plus ancienne à la plus récente.
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleCampaigns(): array
    {
        global $DB;

        if (!self::canView() || !$DB->tableExists(self::getTable())) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => EntityPerimeter::criteria(self::getTable()),
                'ORDER' => ['campaign_date ASC', 'id ASC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Tendance des campagnes visibles, prête pour l'affichage. Les taux d'une campagne sur trop peu
     * de personnes sont masqués à qui ne voit pas le détail des résultats.
     *
     * @return list<array<string, mixed>>
     */
    public static function trendRows(): array
    {
        $campaigns = [];
        foreach (self::visibleCampaigns() as $row) {
            $campaigns[] = [
                'id'     => (int) $row['id'],
                'name'   => (string) $row['name'],
                'date'   => $row['campaign_date'] !== null ? (string) $row['campaign_date'] : null,
                'counts' => self::countsOf($row),
            ];
        }
        $canDetail = Session::haveRight(Installer::SENSITIVE_RIGHT, READ);
        $rows = [];
        $previousHidden = false;
        foreach (Rules::trend($campaigns) as $campaign) {
            $hidden = !$canDetail && !Rules::isAnonymousEnough($campaign['counts']['sent']);
            if ($hidden) {
                // Ni les taux, ni l'écart de la campagne suivante (il permettrait de les retrouver).
                $campaign['rates'] = array_fill_keys(array_keys($campaign['rates']), null);
                $campaign['click_delta'] = null;
            } elseif ($previousHidden) {
                $campaign['click_delta'] = null;
            }
            if ($campaign['counts']['sent'] > 0) {
                $previousHidden = $hidden;
            }
            $rows[] = $campaign + [
                'url'        => self::getFormURLWithID($campaign['id']),
                'date_label' => $campaign['date'] !== null ? Html::convDate($campaign['date']) : '',
                'hidden'     => $hidden,
            ];
        }

        return $rows;
    }

    /**
     * Preuve de sensibilisation : campagnes des 12 derniers mois et taux de la dernière campagne
     * (avec l'écart du taux de clic sur la précédente). Null sans droit de lecture.
     *
     * @return array<string, mixed>|null
     */
    public static function readiness(): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $trend = self::trendRows();
        $since = date('Y-m-d', strtotime('-12 months'));
        $recent = array_filter($trend, static fn (array $row): bool => $row['date'] !== null && $row['date'] >= $since);
        $withResults = array_values(array_filter($trend, static fn (array $row): bool => $row['counts']['sent'] > 0));
        $last = $withResults === [] ? null : $withResults[count($withResults) - 1];

        return [
            'campaigns' => count($recent),
            'last'      => $last,
            'url'       => self::getSearchURL(),
        ];
    }

    /**
     * Preuve affichée sur la fiche de la mesure A.6.3 (null pour les autres mesures).
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        return $code === self::CONTROL_CODE ? self::readiness() : null;
    }

    /**
     * Preuve affichée sur l'exigence 7.3 du SMSI (null pour les autres exigences).
     *
     * @return array<string, mixed>|null
     */
    public static function clauseReadiness(string $code): ?array
    {
        return $code === self::CLAUSE_CODE ? self::readiness() : null;
    }

    /**
     * Taux de clic (%) de la dernière campagne avec résultats dans les entités données, pour
     * l'indicateur de risque (KRI) « hameçonnage » ; null sans campagne mesurée.
     *
     * @param list<int> $entities
     */
    public static function lastClickRate(array $entities): ?float
    {
        global $DB;

        if ($entities === [] || !$DB->tableExists(self::getTable())) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['entities_id' => $entities, 'nb_sent' => ['>', 0]],
            'ORDER' => ['campaign_date DESC', 'id DESC'],
            'LIMIT' => 1,
        ])->current();

        return $row === null ? null : Rules::rates(self::countsOf($row))['clicked'];
    }

    /**
     * Issue #604, lot 2 : indicateurs par entité (dernière campagne mesurée de chaque entité), avec
     * le même masquage que la tendance.
     *
     * @return list<array<string, mixed>>
     */
    public static function entityRows(): array
    {
        $campaigns = [];
        foreach (self::visibleCampaigns() as $row) {
            $campaigns[] = [
                'id'          => (int) $row['id'],
                'entities_id' => (int) $row['entities_id'],
                'date'        => $row['campaign_date'] !== null ? (string) $row['campaign_date'] : null,
                'counts'      => self::countsOf($row),
            ];
        }
        $canDetail = (bool) Session::haveRight(Installer::SENSITIVE_RIGHT, READ);
        $rows = [];
        foreach (Rules::entityRows($campaigns) as $entityId => $row) {
            $hidden = !$canDetail && !Rules::isAnonymousEnough($row['counts']['sent']);
            $rows[] = [
                'entity'     => (string) \Dropdown::getDropdownName(Entity::getTable(), $entityId),
                'campaigns'  => $row['campaigns'],
                'date_label' => $row['last_date'] !== null ? Html::convDate($row['last_date']) : '',
                'counts'     => $row['counts'],
                'rates'      => $hidden ? array_fill_keys(array_keys($row['rates']), null) : $row['rates'],
                'hidden'     => $hidden,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['entity'], $b['entity']));

        return $rows;
    }

    // ------------------------------------------------------------------ Conservation.

    public static function retentionMonths(): int
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::RETENTION_KEY]);

        return Rules::normalizeRetention($values[self::RETENTION_KEY] ?? 0);
    }

    /** @return bool vrai si la durée a changé */
    public static function saveRetention(mixed $months): bool
    {
        $before = self::retentionMonths();
        $after = Rules::normalizeRetention($months);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::RETENTION_KEY => $after]);

        return $before !== $after;
    }

    /**
     * Réglage de la configuration du plugin.
     *
     * @return array{months: int, max: int, url: string}
     */
    public static function configData(): array
    {
        return [
            'months' => self::retentionMonths(),
            'max'    => Rules::MAX_RETENTION_MONTHS,
            'url'    => self::getSearchURL(),
        ];
    }

    /**
     * Campagnes dont les résultats individuels ont dépassé la durée de conservation (toutes
     * entités : c'est la tâche planifiée qui les traite).
     *
     * @return list<int>
     */
    public static function expiredCampaignIds(int $months, string $today): array
    {
        global $DB;

        $limit = Rules::retentionLimit($months, $today);
        if ($limit === null || !$DB->tableExists(self::getTable())) {
            return [];
        }
        $ids = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['date_anonymized' => null, 'NOT' => ['date_import' => null]],
            ]) as $row
        ) {
            $reference = Rules::referenceDate($row);
            if ($reference !== null && $reference < $limit) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        return $name === 'phishingretention'
            ? ['description' => __(
                'Phishing simulations: delete the individual results past the retention period',
                'grcmanager'
            )]
            : [];
    }

    /**
     * Tâche planifiée quotidienne : supprime les résultats individuels des campagnes plus anciennes
     * que la durée de conservation réglée ; les compteurs et les taux (par campagne et par service)
     * restent. Sans effet tant qu'aucune durée n'est réglée.
     */
    public static function cronPhishingretention(CronTask $task): int
    {
        $done = 0;
        foreach (self::expiredCampaignIds(self::retentionMonths(), date('Y-m-d')) as $id) {
            $campaign = new self();
            if ($campaign->getFromDB($id)) {
                PhishingResult::anonymize($campaign);
                $done++;
            }
        }
        $task->addVolume($done);
        $task->log(sprintf(
            __('%d phishing campaign(s): individual results deleted, rates kept.', 'grcmanager'),
            $done
        ));

        return $done > 0 ? 1 : 0;
    }

    // ------------------------------------------------------------------ Tableau de bord.

    /**
     * Carte du tableau de bord : taux de clic, de saisie et de signalement des campagnes visibles,
     * d'une campagne à l'autre (12 dernières, campagnes masquées exclues).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function dashboardTrendCard(array $params = []): array
    {
        $rows = array_values(array_filter(
            self::trendRows(),
            static fn (array $row): bool => $row['counts']['sent'] > 0 && !$row['hidden']
        ));
        $rows = array_slice($rows, -12);
        $labels = array_map(
            static fn (array $row): string => (string) ($row['date_label'] !== '' ? $row['date_label'] : $row['name']),
            $rows
        );
        $serie = static fn (string $step): array => array_map(
            static fn (array $row): float => (float) ($row['rates'][$step] ?? 0),
            $rows
        );

        return [
            'data'  => [
                'labels' => $labels,
                'series' => [
                    ['name' => __('Link clicked', 'grcmanager'), 'data' => $serie('clicked')],
                    ['name' => __('Data entered', 'grcmanager'), 'data' => $serie('submitted')],
                    ['name' => __('Reported', 'grcmanager'), 'data' => $serie('reported')],
                ],
            ],
            'label' => $params['label'] ?? __('Phishing simulations: rates by campaign (%)', 'grcmanager'),
            'icon'  => 'ti ti-chart-line',
        ];
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => $table, 'field' => 'campaign_date', 'name' => __('Campaign date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'tool', 'name' => __('Simulation tool', 'grcmanager'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'target_population',
            'name' => __('Targeted population', 'grcmanager'), 'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'nb_sent', 'name' => __('Emails received', 'grcmanager'),
            'datatype' => 'number', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'scenario', 'name' => __('Scenario', 'grcmanager'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@grcmanager/phishing_campaign_form.html.twig', [
            'item'    => $this,
            'params'  => $options,
            'summary' => $this->isNewItem() ? null : PhishingResult::summaryFor($this),
        ]);

        return true;
    }
}
