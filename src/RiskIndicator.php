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

use CronTask;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\RiskIndicatorBase;
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Log;
use Session;
use Ticket;

/**
 * Issue #378 : indicateur de risque (KRI). Une valeur mesurée dans le temps, avec deux seuils
 * (orange, rouge) et un sens (une valeur haute ou basse est mauvaise), rattachée à une catégorie
 * de risque et, si on le souhaite, à un risque du registre.
 *
 * La valeur est saisie à la main, ou relevée automatiquement depuis les données du plugin et de
 * GLPI (contrôles techniques, vulnérabilités du parc, registre des risques) : chaque jour par la
 * tâche planifiée, ou à la demande. Chaque relevé reste dans l'historique (RiskIndicatorValue).
 *
 * Contrairement au registre des risques, un KRI appartient à une entité (non récursif) : il mesure
 * son entité et ses sous-entités et n'est visible que de qui accède à son entité. Choisir une source
 * automatique exige les droits de lecture sur les données mesurées et l'accès à tout ce périmètre :
 * le plugin ne fait pas compter ce que la personne ne pourrait pas voir. Droit : celui des registres
 * sensibles.
 *
 * Un KRI au rouge propose un ticket (jamais créé automatiquement), attribué au groupe RSSI et rangé
 * dans la catégorie « Sécurité SI » de Configuration GLPI Auto quand elles existent.
 */
class RiskIndicator extends RiskIndicatorBase
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public const SOURCE_MANUAL = 'manual';

    /**
     * Sources automatiques : unité et droits natifs nécessaires pour la choisir.
     *
     * @var array<string, array{unit: string, direction: string, rights: array<string, int>, global: bool}>
     */
    public const SOURCES = [
        'unencrypted_computers'     => [
            'unit' => '%', 'direction' => KriRules::HIGHER_IS_WORSE,
            'rights' => ['computer' => READ], 'global' => false,
        ],
        'antivirus_gaps'            => [
            'unit' => '%', 'direction' => KriRules::HIGHER_IS_WORSE,
            'rights' => ['computer' => READ], 'global' => false,
        ],
        'critical_vulnerabilities'  => [
            'unit' => '', 'direction' => KriRules::HIGHER_IS_WORSE, 'rights' => ['software' => READ], 'global' => false,
        ],
        'risks_above_tolerance'     => [
            'unit' => '', 'direction' => KriRules::HIGHER_IS_WORSE, 'rights' => [], 'global' => true,
        ],
        'overdue_treatment_actions' => [
            'unit' => '', 'direction' => KriRules::HIGHER_IS_WORSE, 'rights' => [], 'global' => true,
        ],
    ];

    /** Score CVSS à partir duquel une vulnérabilité est critique. */
    public const CRITICAL_CVSS = 9.0;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_riskindicators';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Risk indicator (KRI)', 'Risk indicators (KRI)', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-activity-heartbeat';
    }

    /**
     * @return array<string, string>
     */
    public static function getSources(): array
    {
        return [
            self::SOURCE_MANUAL         => __('Manual entry', 'grcmanager'),
            'unencrypted_computers'     => __('Computers with an unencrypted disk (%)', 'grcmanager'),
            'antivirus_gaps'            => __('Computers without an up-to-date antivirus (%)', 'grcmanager'),
            'critical_vulnerabilities'  => __('Open critical vulnerabilities in the fleet', 'grcmanager'),
            'risks_above_tolerance'     => __('Open risks above tolerance', 'grcmanager'),
            'overdue_treatment_actions' => __('Overdue risk treatment actions', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getDirections(): array
    {
        return [
            KriRules::HIGHER_IS_WORSE => __('A high value is bad', 'grcmanager'),
            KriRules::LOWER_IS_WORSE  => __('A low value is bad', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            KriRules::GREEN  => __('Green', 'grcmanager'),
            KriRules::ORANGE => __('Orange', 'grcmanager'),
            KriRules::RED    => __('Red', 'grcmanager'),
            KriRules::NONE   => __('Not measured', 'grcmanager'),
        ];
    }

    /**
     * Badge d'un statut (classe, icône, libellé), pour les gabarits (_badge.html.twig).
     *
     * @return array{class: string, icon: string, label: string}
     */
    public static function statusBadgeData(?string $status): array
    {
        $map = [
            KriRules::GREEN  => ['bg-green-lt', 'ti-circle-check'],
            KriRules::ORANGE => ['bg-orange-lt', 'ti-alert-triangle'],
            KriRules::RED    => ['bg-red-lt', 'ti-alert-octagon'],
            KriRules::NONE   => ['bg-secondary-lt', 'ti-help'],
        ];
        $status = isset($map[$status]) ? (string) $status : KriRules::NONE;

        return ['class' => $map[$status][0], 'icon' => $map[$status][1], 'label' => self::getStatuses()[$status]];
    }

    public static function statusBadge(?string $status): string
    {
        $badge = self::statusBadgeData($status);

        return Badge::html($badge['class'], $badge['icon'], $badge['label']);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => self::canCreate() ? ['add' => self::getFormURL(false)] : [],
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * La source automatique est-elle utilisable par la personne connectée pour cette entité ? Il
     * lui faut les droits natifs de lecture des données mesurées et l'accès à toute l'entité et ses
     * sous-entités, le périmètre que le KRI mesure. Une source globale (registre des risques) ne
     * demande que le droit des registres sensibles, déjà requis.
     */
    public static function canUseSource(string $source, int $entitiesId): bool
    {
        if ($source === self::SOURCE_MANUAL) {
            return true;
        }
        $definition = self::SOURCES[$source] ?? null;
        if ($definition === null) {
            return false;
        }
        foreach ($definition['rights'] as $right => $value) {
            if (!Session::haveRight($right, $value)) {
                return false;
            }
        }
        if ($source === 'critical_vulnerabilities' && !ParkVulnerability::canView()) {
            return false;
        }

        return $definition['global'] || Session::haveAccessToAllOfEntities(self::perimeter($entitiesId));
    }

    /**
     * Entité du KRI et ses sous-entités.
     *
     * @return list<int>
     */
    public static function perimeter(int $entitiesId): array
    {
        return array_values(array_map('intval', getSonsOf(Entity::getTable(), $entitiesId)));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function prepareInput(array $input, bool $isNew)
    {
        // Champs calculés : jamais repris de la requête.
        unset($input['last_value'], $input['last_value_date'], $input['status'], $input['tickets_id']);
        if (array_key_exists('is_recursive', $input)) {
            $input['is_recursive'] = 0;
        }

        $source = (string) ($input['source'] ?? ($this->fields['source'] ?? self::SOURCE_MANUAL));
        if (!array_key_exists($source, self::getSources())) {
            $source = self::SOURCE_MANUAL;
        }
        $input['source'] = $source;
        $entitiesId = (int) ($input['entities_id'] ?? ($this->fields['entities_id'] ?? 0));
        $sourceChanged = $isNew || $source !== ($this->fields['source'] ?? '')
            || $entitiesId !== (int) ($this->fields['entities_id'] ?? 0);
        if ($sourceChanged && !self::canUseSource($source, $entitiesId)) {
            Session::addMessageAfterRedirect(
                __(
                    'You cannot use this automatic source: it measures data you cannot read in this entity and its '
                        . 'sub-entities.',
                    'grcmanager'
                ),
                false,
                ERROR
            );

            return false;
        }
        if ($source !== self::SOURCE_MANUAL) {
            $input['unit'] = self::SOURCES[$source]['unit'];
            $input['direction'] = self::SOURCES[$source]['direction'];
        }

        if (array_key_exists('direction', $input) && !KriRules::isDirection((string) $input['direction'])) {
            $input['direction'] = KriRules::HIGHER_IS_WORSE;
        }
        if (
            array_key_exists('category', $input)
            && !array_key_exists((string) $input['category'], Risk::getCategories())
        ) {
            $input['category'] = '';
        }
        if (array_key_exists('plugin_grcmanager_risks_id', $input)) {
            $riskId = (int) $input['plugin_grcmanager_risks_id'];
            $input['plugin_grcmanager_risks_id'] = $riskId > 0 && (new Risk())->getFromDB($riskId) ? $riskId : 0;
        }
        foreach (['threshold_warning', 'threshold_critical'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = self::toNumber($input[$field]);
            }
        }
        $direction = (string) ($input['direction'] ?? ($this->fields['direction'] ?? KriRules::HIGHER_IS_WORSE));
        $warning = array_key_exists('threshold_warning', $input)
            ? $input['threshold_warning'] : self::toNumber($this->fields['threshold_warning'] ?? null);
        $critical = array_key_exists('threshold_critical', $input)
            ? $input['threshold_critical'] : self::toNumber($this->fields['threshold_critical'] ?? null);
        if (!KriRules::areThresholdsValid($direction, $warning, $critical)) {
            Session::addMessageAfterRedirect(
                __(
                    'The red threshold must be beyond the orange threshold, in the direction of the indicator.',
                    'grcmanager'
                ),
                false,
                ERROR
            );

            return false;
        }
        if ($isNew && trim((string) ($input['name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(__('The indicator needs a name.', 'grcmanager'), false, ERROR);

            return false;
        }

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input, true);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input, false);
    }

    public function post_updateItem($history = true)
    {
        // Seuils ou sens changés : le statut suit.
        if (array_intersect(['threshold_warning', 'threshold_critical', 'direction'], $this->updates) !== []) {
            $this->refresh();
        }
        parent::post_updateItem($history);
    }

    public function cleanDBonPurge()
    {
        (new RiskIndicatorValue())->deleteByCriteria(['plugin_grcmanager_riskindicators_id' => $this->getID()]);
    }

    public static function toNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 'NULL') {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], (string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Statut d'une valeur avec les seuils de ce KRI.
     */
    public function statusFor(?float $value): string
    {
        return KriRules::status(
            $value,
            (string) ($this->fields['direction'] ?? KriRules::HIGHER_IS_WORSE),
            self::toNumber($this->fields['threshold_warning'] ?? null),
            self::toNumber($this->fields['threshold_critical'] ?? null)
        );
    }

    /**
     * Enregistre un relevé puis met à jour la dernière valeur et le statut du KRI.
     */
    public function recordValue(float $value, string $date, string $comment = '', bool $automatic = false): bool
    {
        $id = (new RiskIndicatorValue())->add([
            'plugin_grcmanager_riskindicators_id' => $this->getID(),
            'value_date'   => $date,
            'value'        => $value,
            'status'       => $this->statusFor($value),
            'comment'      => $comment,
            'users_id'     => (int) Session::getLoginUserID(),
            'is_automatic' => $automatic ? 1 : 0,
        ]);
        if (!$id) {
            return false;
        }
        $this->refresh();

        return true;
    }

    /**
     * Recalcule dernière valeur et statut depuis l'historique.
     */
    public function refresh(): void
    {
        $values = RiskIndicatorValue::getValues($this->getID());
        $last = $values === [] ? null : $values[array_key_last($values)];
        $value = $last === null ? null : (float) $last['value'];
        $this->getFromDB($this->getID());
        $status = $this->statusFor($value);
        // Sans historique ni droit : écriture directe des champs calculés.
        global $DB;
        $DB->update(self::getTable(), [
            'last_value'      => $value,
            'last_value_date' => $last['value_date'] ?? null,
            'status'          => $status,
        ], ['id' => $this->getID()]);
        $this->fields['last_value'] = $value;
        $this->fields['last_value_date'] = $last['value_date'] ?? null;
        $this->fields['status'] = $status;
    }

    /**
     * Valeur actuelle d'une source automatique sur le périmètre de ce KRI, null si non mesurable.
     */
    public function computeSourceValue(): ?float
    {
        $source = (string) ($this->fields['source'] ?? self::SOURCE_MANUAL);
        $perimeter = self::perimeter((int) $this->fields['entities_id']);

        return match ($source) {
            'unencrypted_computers'     => ComplianceAudit::gapRate('encryption', $perimeter),
            'antivirus_gaps'            => ComplianceAudit::gapRate('antivirus', $perimeter),
            'critical_vulnerabilities'  => (float) count(array_filter(
                ParkVulnerability::getVulnerabilityRows(
                    false,
                    ['glpi_items_softwareversions.entities_id' => $perimeter]
                ),
                static fn (array $row): bool => ($row['cvss'] !== null && $row['cvss'] >= self::CRITICAL_CVSS)
                    || strtoupper((string) $row['severity']) === 'CRITICAL'
            )),
            'risks_above_tolerance'     => (float) RiskAppetite::countAbove(true),
            'overdue_treatment_actions' => (float) DashboardCardService::overdueTreatmentActionsCount()['number'],
            default                     => null,
        };
    }

    /**
     * Relève maintenant la valeur d'une source automatique. Un seul relevé automatique par jour :
     * un second relevé du même jour remplace le premier.
     */
    public function measure(): bool
    {
        if (($this->fields['source'] ?? self::SOURCE_MANUAL) === self::SOURCE_MANUAL) {
            return false;
        }
        $value = $this->computeSourceValue();
        if ($value === null) {
            return false;
        }
        $today = date('Y-m-d');
        (new RiskIndicatorValue())->deleteByCriteria([
            'plugin_grcmanager_riskindicators_id' => $this->getID(),
            'value_date'   => $today,
            'is_automatic' => 1,
        ]);

        return $this->recordValue($value, $today, '', true);
    }

    public static function cronInfo(string $name): array
    {
        return ['description' => __('Measure automatic risk indicators (KRI)', 'grcmanager')];
    }

    /**
     * Tâche planifiée quotidienne : relève les KRI actifs à source automatique.
     */
    public static function cronRiskindicatormeasure(CronTask $task): int
    {
        global $DB;

        $done = 0;
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['is_active' => 1, 'NOT' => ['source' => self::SOURCE_MANUAL]],
            ]) as $row
        ) {
            $kri = new self();
            if ($kri->getFromDB((int) $row['id']) && $kri->measure()) {
                $done++;
            }
        }
        $task->addVolume($done);

        return $done > 0 ? 1 : 0;
    }

    /**
     * Ticket encore ouvert créé depuis ce KRI, s'il existe.
     */
    public function openTicket(): ?Ticket
    {
        $ticket = new Ticket();
        $ticketsId = (int) ($this->fields['tickets_id'] ?? 0);
        if (
            $ticketsId > 0
            && $ticket->getFromDB($ticketsId)
            && empty($ticket->fields['is_deleted'])
            && !in_array(
                (int) $ticket->fields['status'],
                array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()),
                true
            )
        ) {
            return $ticket;
        }

        return null;
    }

    /**
     * Ticket proposé pour un KRI au rouge : dans l'entité du KRI, attribué au groupe RSSI, rangé
     * dans la catégorie « Sécurité SI » de Configuration GLPI Auto si elle existe. Un ticket encore
     * ouvert est réutilisé.
     *
     * @return int id du ticket, 0 si rien à faire ou refusé
     */
    public function createTicket(): int
    {
        global $DB;

        if (
            !$this->can($this->getID(), UPDATE)
            || !Session::haveRight(Ticket::$rightname, CREATE)
            || ($this->fields['status'] ?? '') !== KriRules::RED
        ) {
            return 0;
        }
        $existing = $this->openTicket();
        if ($existing !== null) {
            return $existing->getID();
        }
        $entitiesId = (int) $this->fields['entities_id'];
        $input = [
            'name'        => sprintf(__('Risk indicator in the red: %s', 'grcmanager'), $this->fields['name']),
            'content'     => TemplateRenderer::getInstance()->render('@grcmanager/ticket/risk_indicator.html.twig', [
                'name'      => (string) $this->fields['name'],
                'value'     => $this->fields['last_value'],
                'unit'      => (string) $this->fields['unit'],
                'date'      => (string) $this->fields['last_value_date'],
                'warning'   => $this->fields['threshold_warning'],
                'critical'  => $this->fields['threshold_critical'],
                'direction' => self::getDirections()[$this->fields['direction']] ?? '',
                'url'       => self::getFormURLWithID($this->getID()),
            ]),
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => $entitiesId,
        ];
        $category = SecurityCategoryLink::forRequest($entitiesId);
        if ($category > 0) {
            $input['itilcategories_id'] = $category;
        }
        $group = AuditTeam::getCisoGroupId();
        if ($group > 0) {
            $input['_groups_id_assign'] = $group;
        }
        $ticketsId = (int) (new Ticket())->add($input);
        if ($ticketsId <= 0) {
            return 0;
        }
        $DB->update(self::getTable(), ['tickets_id' => $ticketsId], ['id' => $this->getID()]);
        $this->fields['tickets_id'] = $ticketsId;
        AuditLog::record('ticket_create', Ticket::class, $ticketsId, 0, 'success', [
            'itemtype' => self::class, 'items_id' => $this->getID(),
        ]);

        return $ticketsId;
    }

    public function rawSearchOptions()
    {
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $table = $this->getTable();
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'itemtype' => self::class,
        ];
        $tab[] = [
            'id' => 2, 'table' => $table, 'field' => 'status',
            'name' => __('Status', 'grcmanager'), 'datatype' => 'specific',
        ];
        $tab[] = [
            'id' => 3, 'table' => $table, 'field' => 'last_value',
            'name' => __('Last value', 'grcmanager'), 'datatype' => 'decimal',
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'last_value_date',
            'name' => __('Last measured on', 'grcmanager'), 'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'threshold_warning',
            'name' => __('Orange threshold', 'grcmanager'), 'datatype' => 'decimal',
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'threshold_critical',
            'name' => __('Red threshold', 'grcmanager'), 'datatype' => 'decimal',
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'source',
            'name' => __('Source', 'grcmanager'), 'datatype' => 'specific',
        ];
        $tab[] = [
            'id' => 8, 'table' => $table, 'field' => 'category',
            'name' => __('Category', 'grcmanager'), 'datatype' => 'specific',
        ];
        $tab[] = [
            'id' => 9, 'table' => 'glpi_users', 'field' => 'name', 'name' => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 10, 'table' => $table, 'field' => 'id', 'name' => __('ID'),
            'datatype' => 'itemlink', 'itemtype' => self::class,
        ];
        $tab[] = [
            'id' => 11, 'table' => $table, 'field' => 'unit',
            'name' => __('Unit', 'grcmanager'), 'datatype' => 'string',
        ];
        $tab[] = [
            'id' => 12, 'table' => $table, 'field' => 'is_active',
            'name' => __('Active'), 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $value = $values[$field] ?? null;

        return match ($field) {
            'status'   => self::statusBadge((string) $value),
            'source'   => htmlescape(self::getSources()[$value] ?? (string) $value),
            'category' => htmlescape(Risk::getCategories()[$value] ?? ''),
            default    => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value'] = $values[$field] ?? '';

        return match ($field) {
            'status'   => Dropdown::showFromArray($name, self::getStatuses(), $options),
            'source'   => Dropdown::showFromArray($name, self::getSources(), $options),
            'category' => Dropdown::showFromArray($name, Risk::getCategories(), $options),
            default    => parent::getSpecificValueToSelect($field, $name, $values, $options),
        };
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB, $CFG_GLPI;

        $this->initForm($ID, $options);
        $isNew = $this->isNewID($ID);
        $risks = [0 => Dropdown::EMPTY_VALUE];
        foreach ($DB->request(['SELECT' => ['id', 'title'], 'FROM' => Risk::getTable(), 'ORDER' => 'title']) as $row) {
            $risks[(int) $row['id']] = (string) $row['title'];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/risk_indicator_form.html.twig', [
            'item'       => $this,
            'params'     => $options,
            'is_new'     => $isNew,
            'sources'    => self::getSources(),
            'directions' => self::getDirections(),
            'categories' => ['' => Dropdown::EMPTY_VALUE] + Risk::getCategories(),
            'risks'      => $risks,
            'status'     => $isNew ? null : self::statusBadgeData((string) $this->fields['status']),
        ]);

        if (!$isNew) {
            $values = RiskIndicatorValue::getValues((int) $ID);
            $trend = KriRules::trend(array_map(static fn (array $row): float => (float) $row['value'], $values));
            $canUpdate = $this->can((int) $ID, UPDATE);
            $ticket = $this->openTicket();
            $rows = [];
            foreach (array_reverse($values) as $row) {
                $rows[] = $row + ['status_badge' => self::statusBadgeData((string) $row['status'])];
            }
            TemplateRenderer::getInstance()->display('@grcmanager/risk_indicator_values.html.twig', [
                'kri_id'       => (int) $ID,
                'action'       => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/riskindicatorvalue.form.php',
                'can_edit'     => $canUpdate,
                'can_purge'    => $canUpdate && Session::haveRight(self::$rightname, PURGE),
                'automatic'    => ($this->fields['source'] ?? self::SOURCE_MANUAL) !== self::SOURCE_MANUAL,
                'unit'         => (string) $this->fields['unit'],
                'today'        => date('Y-m-d'),
                'values'       => $rows,
                'trend'        => $trend,
                'worsening'    => KriRules::isWorsening($trend, (string) $this->fields['direction']),
                'is_red'       => ($this->fields['status'] ?? '') === KriRules::RED,
                'can_ticket'   => $canUpdate && Session::haveRight(Ticket::$rightname, CREATE),
                'ticket_url'   => $ticket === null ? '' : Ticket::getFormURLWithID($ticket->getID()),
                'ticket_id'    => $ticket === null ? 0 : $ticket->getID(),
            ]);
        }

        return true;
    }

    /**
     * Fiche d'un risque : ses KRI visibles (entités de l'utilisateur).
     *
     * @return list<array<string, mixed>>
     */
    public static function forRisk(int $risksId): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_risks_id' => $risksId] + getEntitiesRestrictCriteria(self::getTable()),
                'ORDER' => 'name',
            ]) as $row
        ) {
            $rows[] = [
                'name'        => (string) $row['name'],
                'url'         => self::getFormURLWithID((int) $row['id']),
                'value'       => $row['last_value'],
                'unit'        => (string) $row['unit'],
                'status_badge' => self::statusBadgeData((string) $row['status']),
            ];
        }

        return $rows;
    }

    /**
     * Nombre de KRI actifs par statut, dans les entités de l'utilisateur.
     *
     * @return array<string, int>
     */
    public static function countByStatus(): array
    {
        global $DB;

        $counts = array_fill_keys(KriRules::STATUSES, 0);
        foreach (
            $DB->request([
                'SELECT'  => ['status', 'COUNT' => 'id AS c'],
                'FROM'    => self::getTable(),
                'WHERE'   => ['is_active' => 1] + getEntitiesRestrictCriteria(self::getTable()),
                'GROUPBY' => 'status',
            ]) as $row
        ) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['c'];
            }
        }

        return $counts;
    }
}
