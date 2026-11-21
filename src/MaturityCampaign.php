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
use Config;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\MaturityCampaignBase;
use GlpiPlugin\Grcmanager\Install\MaturityCampaignTables;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityGapRules as Rules;
use Html;
use Log;
use Session;
use User;

/**
 * Issue #603 : campagne d'évaluation de la maturité par mesure (profil actuel et profil cible).
 *
 * Une campagne est datée et porte, pour chaque mesure applicable de la SoA de son entité, un niveau
 * actuel et un niveau cible de 0 (inexistant) à 5 (optimisé), une échéance, une justification et un
 * responsable. Les campagnes sont conservées : deux campagnes se comparent mesure par mesure. Une
 * nouvelle campagne reprend les cibles de la précédente de la même entité.
 *
 * Ces niveaux sont facultatifs : ils ne changent ni l'état de mise en œuvre des mesures ni le score
 * de maturité global (page « Score de maturité »).
 *
 * Droits : droit général du plugin et entité de la campagne (check() du cœur). La grille se modifie
 * avec le droit de modifier la campagne.
 */
class MaturityCampaign extends MaturityCampaignBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const FK = 'plugin_grcmanager_maturitycampaigns_id';

    public const CONFIG_CONTEXT = 'plugin:grcmanager';

    /** Libellés personnalisés de l'échelle (JSON niveau => libellé). */
    public const LABELS_KEY = 'maturity_scale_labels';

    /** Campagne choisie pour la comparaison, retenue pour la session (campagne => campagne). */
    public const COMPARE_SESSION_KEY = 'plugin_grcmanager_maturity_compare';

    public static function getTable($classname = null)
    {
        return MaturityCampaignTables::CAMPAIGNS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Maturity campaign', 'Maturity campaigns', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-stairs-up';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $menu = [
            'title' => __('Maturity by control', 'grcmanager'),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => ['search' => self::getSearchURL(false)],
        ];
        if (self::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }

        return $menu;
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function load(int $campaignId): ?self
    {
        $campaign = new self();

        return $campaignId > 0 && $campaign->getFromDB($campaignId) ? $campaign : null;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(self::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    // ---------------------------------------------------------------------------------------------
    // Échelle
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    public static function defaultScaleLabels(): array
    {
        return [
            0 => __('Non-existent', 'grcmanager'),
            1 => __('Initial', 'grcmanager'),
            2 => __('Repeatable', 'grcmanager'),
            3 => __('Defined', 'grcmanager'),
            4 => __('Managed', 'grcmanager'),
            5 => __('Optimised', 'grcmanager'),
        ];
    }

    /**
     * Libellés saisis dans la configuration du plugin (vides : libellé par défaut).
     *
     * @return array<int, string>
     */
    public static function customScaleLabels(): array
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::LABELS_KEY]);
        $decoded = json_decode((string) ($values[self::LABELS_KEY] ?? ''), true);

        return is_array($decoded) ? Rules::sanitizeLabels($decoded) : [];
    }

    /**
     * @return array<int, string>
     */
    public static function scaleLabels(): array
    {
        return Rules::mergeLabels(self::defaultScaleLabels(), self::customScaleLabels());
    }

    /**
     * @param array<mixed> $labels
     */
    public static function saveScaleLabels(array $labels): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            self::LABELS_KEY => json_encode(Rules::sanitizeLabels($labels)),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Saisie
    // ---------------------------------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        $input = EntityPerimeter::prepareAdd($input);
        if ($input === false) {
            return false;
        }
        if (!isset($input['users_id'])) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }
        if (empty($input['campaign_date'])) {
            $input['campaign_date'] = date('Y-m-d');
        }
        $input = $this->normalizeInput($input);
        if (trim((string) ($input['name'] ?? '')) === '') {
            $input['name'] = sprintf(__('Maturity assessment of %s', 'grcmanager'), (string) $input['campaign_date']);
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // L'entité ne change pas : les lignes suivent la SoA de l'entité de la campagne.
        unset($input['entities_id']);

        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = Rules::cleanText($input['name'], Rules::MAX_NAME_LENGTH);
        }
        if (array_key_exists('comment', $input)) {
            $input['comment'] = Rules::cleanText($input['comment']);
        }
        if (array_key_exists('campaign_date', $input)) {
            $input['campaign_date'] = Rules::normalizeDate($input['campaign_date']) ?? date('Y-m-d');
        }
        if (array_key_exists('users_id', $input)) {
            $user = new User();
            $usersId = max(0, (int) $input['users_id']);
            $input['users_id'] = $usersId > 0 && $user->getFromDB($usersId) && (int) $user->fields['is_active'] === 1
                ? $usersId : 0;
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        MaturityAssessment::createLines($this, self::previousCampaignId($this));
    }

    public function cleanDBonPurge()
    {
        MaturityAssessment::purgeCampaign($this->getID());
    }

    /**
     * Campagne précédente de la même entité (date, puis numéro), 0 sinon.
     */
    public static function previousCampaignId(self $campaign): int
    {
        global $DB;

        if ($campaign->isNewItem()) {
            return 0;
        }
        $date = (string) ($campaign->fields['campaign_date'] ?? '');
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'entities_id' => (int) $campaign->fields['entities_id'],
                'OR'          => [
                    ['campaign_date' => ['<', $date]],
                    ['campaign_date' => $date, 'id' => ['<', $campaign->getID()]],
                ],
            ],
            'ORDER'  => ['campaign_date DESC', 'id DESC'],
            'LIMIT'  => 1,
        ])->current();

        return $row === null ? 0 : (int) $row['id'];
    }

    /**
     * Autres campagnes de la même entité, de la plus récente à la plus ancienne (comparaison).
     *
     * @return array<int, string>
     */
    public static function otherCampaigns(self $campaign): array
    {
        global $DB;

        $options = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'campaign_date'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'entities_id' => (int) $campaign->fields['entities_id'],
                    ['NOT' => ['id' => $campaign->getID()]],
                ],
                'ORDER'  => ['campaign_date DESC', 'id DESC'],
            ]) as $row
        ) {
            $date = empty($row['campaign_date']) ? '' : (string) Html::convDate((string) $row['campaign_date']);
            $options[(int) $row['id']] = trim($date . ' - ' . (string) $row['name'], ' -');
        }

        return $options;
    }

    /** Campagne comparée : celle choisie pendant la session, sinon la précédente. */
    public static function comparedCampaignId(self $campaign): int
    {
        $others = self::otherCampaigns($campaign);
        $chosen = (int) ($_SESSION[self::COMPARE_SESSION_KEY][$campaign->getID()] ?? 0);

        return isset($others[$chosen]) ? $chosen : self::previousCampaignId($campaign);
    }

    /** Retient la campagne à comparer (une campagne de la même entité seulement). */
    public static function chooseComparison(self $campaign, int $otherId): void
    {
        if (isset(self::otherCampaigns($campaign)[$otherId])) {
            $_SESSION[self::COMPARE_SESSION_KEY][$campaign->getID()] = $otherId;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Onglets : grille, écarts, comparaison
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::canView() || !$item instanceof self || $item->isNewItem()) {
            return '';
        }
        $summary = Rules::summary(self::levelRows(MaturityAssessment::getLines($item->getID())));

        return [
            1 => self::createTabEntry(__('Levels by control', 'grcmanager'), $summary['assessed'], null, 'ti ti-list-numbers'),
            2 => self::createTabEntry(__('Gaps', 'grcmanager'), $summary['gaps'], null, 'ti ti-arrows-diff'),
            3 => self::createTabEntry(__('Comparison', 'grcmanager'), 0, null, 'ti ti-columns'),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : mêmes contrôles.
        if (!self::canView() || !$item instanceof self || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        if ((int) $tabnum === 2) {
            self::showGaps($item);
        } elseif ((int) $tabnum === 3) {
            self::showComparison($item);
        } else {
            self::showGrid($item);
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array{current: ?int, target: ?int}>
     */
    private static function levelRows(array $lines): array
    {
        return array_map(static fn (array $l): array => [
            'current' => Rules::normalizeLevel($l['current_level'] ?? null),
            'target'  => Rules::normalizeLevel($l['target_level'] ?? null),
        ], $lines);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{current: ?int, target: ?int}>
     */
    private static function rowLevels(array $rows): array
    {
        return array_map(static fn (array $r): array => ['current' => $r['current'], 'target' => $r['target']], $rows);
    }

    private static function showGrid(self $campaign): void
    {
        $rows = MaturityAssessment::present($campaign);
        TemplateRenderer::getInstance()->display('@grcmanager/maturity_campaign_grid_tab.html.twig', [
            'campaign_id'  => $campaign->getID(),
            'rows'         => $rows,
            'summary'      => Rules::summary(self::rowLevels($rows)),
            'labels'       => self::scaleLabels(),
            'can_edit'     => $campaign->can($campaign->getID(), UPDATE),
            'action'       => self::getFormURL(),
            'has_previous' => self::previousCampaignId($campaign) > 0,
            'entity'       => (int) $campaign->fields['entities_id'],
            'soa_url'      => Control::getSearchURL(),
        ]);
    }

    /**
     * Écarts triés, filtres (référentiel, thème, responsable) et radar par thème.
     *
     * @return array<string, mixed>
     */
    public static function gapData(self $campaign): array
    {
        $rows = MaturityAssessment::present($campaign);
        $gaps = Rules::sortGaps($rows);
        $themes = Control::getThemes();
        $radar = [];
        foreach (Rules::themeAverages($rows, array_keys($themes)) as $theme => $values) {
            $radar[] = ['label' => $themes[$theme], 'current' => $values['current'], 'target' => $values['target']];
        }
        $owners = [];
        foreach ($gaps as $gap) {
            if ($gap['users_id'] > 0) {
                $owners[$gap['users_id']] = $gap['owner'];
            }
        }
        asort($owners);
        $usedThemes = array_unique(array_column($gaps, 'theme'));

        return [
            'rows'       => $gaps,
            'summary'    => Rules::summary(self::rowLevels($rows)),
            'radar'      => $radar,
            'themes'     => array_intersect_key($themes, array_flip($usedThemes)),
            'owners'     => $owners,
            'frameworks' => MaturityAssessment::frameworkOptions(),
        ];
    }

    private static function showGaps(self $campaign): void
    {
        $canEdit = $campaign->can($campaign->getID(), UPDATE);
        TemplateRenderer::getInstance()->display('@grcmanager/maturity_campaign_gaps_tab.html.twig', self::gapData($campaign) + [
            'labels'             => self::scaleLabels(),
            'can_create_finding' => $canEdit && Nonconformity::canCreate(),
            'can_edit'           => $canEdit,
            'finding_url'        => Nonconformity::getFormURL(),
            'pdf_url'            => self::pdfURL($campaign->getID()),
        ]);
    }

    /**
     * Comparaison de la campagne avec une autre de la même entité (niveaux actuels).
     *
     * @return array<string, mixed>|null null sans autre campagne
     */
    public static function comparisonData(self $campaign, int $otherId): ?array
    {
        $other = $otherId > 0 ? self::load($otherId) : null;
        if ($other === null || (int) $other->fields['entities_id'] !== (int) $campaign->fields['entities_id']) {
            return null;
        }
        // La campagne la plus ancienne est l'état « avant ».
        $mineFirst = (string) $campaign->fields['campaign_date'] < (string) $other->fields['campaign_date']
            || (
                (string) $campaign->fields['campaign_date'] === (string) $other->fields['campaign_date']
                && $campaign->getID() < $other->getID()
            );
        [$before, $after] = $mineFirst ? [$campaign, $other] : [$other, $campaign];
        $compare = Rules::compare(
            MaturityAssessment::currentLevels($before->getID()),
            MaturityAssessment::currentLevels($after->getID())
        );
        $labels = self::scaleLabels();
        $lines = [];
        foreach ($compare['lines'] as $code => $line) {
            $lines[] = $line + [
                'code'         => $code,
                'title'        => Control::getControlTitle($code),
                'before_label' => $line['before'] === null ? '' : $labels[$line['before']],
                'before_class' => Rules::levelBadgeClass($line['before']),
                'after_label'  => $line['after'] === null ? '' : $labels[$line['after']],
                'after_class'  => Rules::levelBadgeClass($line['after']),
            ];
        }

        return [
            'before_name' => $before->getName(),
            'before_date' => (string) Html::convDate((string) $before->fields['campaign_date']),
            'after_name'  => $after->getName(),
            'after_date'  => (string) Html::convDate((string) $after->fields['campaign_date']),
            'lines'       => $lines,
            'improved'    => $compare['improved'],
            'declined'    => $compare['declined'],
            'unchanged'   => $compare['unchanged'],
            'compared'    => $compare['compared'],
        ];
    }

    private static function showComparison(self $campaign): void
    {
        $otherId = self::comparedCampaignId($campaign);
        TemplateRenderer::getInstance()->display('@grcmanager/maturity_campaign_compare_tab.html.twig', [
            'campaign_id' => $campaign->getID(),
            'others'      => self::otherCampaigns($campaign),
            'other_id'    => $otherId,
            'comparison'  => self::comparisonData($campaign, $otherId),
            'action'      => self::getFormURL(),
            'can_create'  => self::canCreate(),
            'add_url'     => self::getFormURL(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Liste et fiche
    // ---------------------------------------------------------------------------------------------

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
            'id' => 3, 'table' => $table, 'field' => 'campaign_date', 'name' => __('Assessment date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 4, 'table' => User::getTable(), 'field' => 'name', 'name' => __('Campaign lead', 'grcmanager'),
            'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 8, 'table' => $table, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 19, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => Entity::getTable(), 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $summary = null;
        $previous = '';
        if ($this->isNewItem()) {
            $this->fields['campaign_date'] = date('Y-m-d');
            $this->fields['users_id'] = (int) Session::getLoginUserID();
        } else {
            $summary = Rules::summary(self::levelRows(MaturityAssessment::getLines($this->getID())));
            $previousId = self::previousCampaignId($this);
            $previous = $previousId > 0 ? (string) (self::load($previousId)?->getName() ?? '') : '';
        }

        TemplateRenderer::getInstance()->display('@grcmanager/maturity_campaign_form.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'summary'      => $summary,
            'previous'     => $previous,
            'pdf_url'      => $this->isNewItem() ? '' : self::pdfURL($this->getID()),
            'maturity_url' => Maturity::getSearchURL(),
        ]);

        return true;
    }

    // ---------------------------------------------------------------------------------------------
    // Rapport PDF
    // ---------------------------------------------------------------------------------------------

    public static function pdfURL(int $campaignId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/maturitycampaign.pdf.php?id=' . $campaignId;
    }

    /**
     * Rapport de la campagne pour la revue de direction : synthèse, niveaux moyens par thème, écarts
     * triés et comparaison avec la campagne précédente. Calculé à la demande avec les droits de la
     * personne (l'appelant a fait check(READ)), rien n'est conservé.
     */
    public static function renderPdf(self $campaign): string
    {
        $gaps = self::gapData($campaign);
        $lead = (int) ($campaign->fields['users_id'] ?? 0);
        $comparison = self::comparisonData($campaign, self::previousCampaignId($campaign));

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/maturity_campaign_report.html.twig', [
            'report_title'       => sprintf(__('Maturity by control: %s', 'grcmanager'), $campaign->getName()),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'perimeter'          => EntityPerimeter::isMultiEntity()
                ? Dropdown::getDropdownName('glpi_entities', (int) $campaign->fields['entities_id'])
                : '',
            'campaign_date'      => empty($campaign->fields['campaign_date'])
                ? '' : (string) Html::convDate((string) $campaign->fields['campaign_date']),
            'lead'               => $lead > 0 ? getUserName($lead) : '',
            'comment'            => (string) ($campaign->fields['comment'] ?? ''),
            'summary'            => $gaps['summary'],
            'radar'              => $gaps['radar'],
            'rows'               => $gaps['rows'],
            'comparison'         => $comparison,
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
        ]);
    }
}
