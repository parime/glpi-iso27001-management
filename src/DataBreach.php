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

use CommonDBTM;
use CommonGLPI;
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\DataBreachBase;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use GlpiPlugin\Grcmanager\Services\Privacy\DataBreachRules as Rules;
use GlpiPlugin\Grcmanager\Services\Privacy\ProcessingActivityRules;
use Html;
use Log;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #620 : registre des violations de données personnelles (RGPD, article 33.5). Chaque violation y
 * est documentée, notifiée ou non : faits, effets, mesures, décision motivée de notifier l'autorité
 * (article 33) et d'informer les personnes (article 34).
 *
 * Construit sur les incidents de sécurité : une fiche se crée depuis un incident qualifié de violation
 * de données (proposition préremplie, jamais automatique), et l'incident n'est jamais modifié par le
 * registre. Droit « Protection des données personnelles » et entité de la fiche, comme le registre des
 * traitements ; les documents joints suivent la fiche (registre des documents restreints).
 */
class DataBreach extends DataBreachBase
{
    public const RIGHTNAME = ProcessingActivity::RIGHTNAME;

    public const INCIDENT_FK = 'plugin_grcmanager_securityincidents_id';

    /** Traitements concernés (registre des activités de traitement). */
    public const ACTIVITIES_TABLE = 'glpi_plugin_grcmanager_databreaches_processingactivities';

    public const ACTIVITIES_FIELD = '_processing_activities';

    /** Marqueur du formulaire : une liste vide n'envoie rien, seul lui la remplace. */
    public const LISTS_MARKER = '_breach_lists_defined';

    /** Champs listes (JSON) et leur catalogue. */
    private const LIST_FIELDS = [
        'nature'          => Rules::NATURES,
        'data_subjects'   => ProcessingActivityRules::DATA_SUBJECTS,
        'data_categories' => ProcessingActivityRules::DATA_CATEGORIES,
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_databreaches';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Recorded data breach', 'Recorded data breaches', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-alert';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => __('Breach register', 'grcmanager'),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getNatures(): array
    {
        return [
            'confidentiality' => __('Confidentiality (disclosure or unauthorised access)', 'grcmanager'),
            'integrity'       => __('Integrity (unauthorised alteration)', 'grcmanager'),
            'availability'    => __('Availability (loss or destruction)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getRiskLevels(): array
    {
        return [
            ''         => __('Not assessed yet', 'grcmanager'),
            'unlikely' => __('Unlikely to result in a risk', 'grcmanager'),
            'risk'     => __('Risk for the people concerned', 'grcmanager'),
            'high'     => __('High risk for the people concerned', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getAuthorityDecisions(): array
    {
        return [
            'pending'      => __('Decision pending', 'grcmanager'),
            'notified'     => __('Notified to the supervisory authority', 'grcmanager'),
            'not_notified' => __('Not notified', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSubjectsDecisions(): array
    {
        return [
            'pending'      => __('Decision pending', 'grcmanager'),
            'informed'     => __('People informed', 'grcmanager'),
            'not_informed' => __('People not informed', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> État => classe, icône, libellé.
     */
    public static function getStateBadges(): array
    {
        return [
            Rules::STATE_PENDING       => ['bg-blue-lt', 'ti-clock', __('Decision pending', 'grcmanager')],
            Rules::STATE_SOON          => [
                'bg-orange-lt', 'ti-clock-exclamation', __('Deadline approaching', 'grcmanager'),
            ],
            Rules::STATE_OVERDUE       => ['bg-red-lt', 'ti-alert-octagon', __('72 h deadline passed', 'grcmanager')],
            Rules::STATE_NOTIFIED      => ['bg-green-lt', 'ti-circle-check', __('Notified on time', 'grcmanager')],
            Rules::STATE_NOTIFIED_LATE => ['bg-orange-lt', 'ti-clock-pause', __('Notified late', 'grcmanager')],
            Rules::STATE_NOT_NOTIFIED  => [
                'bg-secondary-lt', 'ti-file-check', __('Not notified (decision recorded)', 'grcmanager'),
            ],
        ];
    }

    public static function getCheckLabel(string $check): string
    {
        return match ($check) {
            Rules::CHECK_NO_NATURE => __('The nature of the breach is not set.', 'grcmanager'),
            Rules::CHECK_NO_COUNTS => __(
                'Give at least an approximate number of people or records concerned.',
                'grcmanager'
            ),
            Rules::CHECK_NO_RISK   => __('The risk for the people concerned is not assessed yet.', 'grcmanager'),
            Rules::CHECK_NOT_NOTIFIED_DESPITE_RISK => __(
                'Not notified although a risk was found: only a breach unlikely to result in a risk may stay '
                    . 'unnotified (article 33.1).',
                'grcmanager'
            ),
            Rules::CHECK_HIGH_RISK_SUBJECTS_PENDING => __(
                'High risk: decide whether the people concerned must be informed (article 34).',
                'grcmanager'
            ),
            Rules::CHECK_NO_MEASURES => __('No measure taken or planned is described.', 'grcmanager'),
            default => $check,
        };
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        $now = (string) (Session::getCurrentTime() ?: date('Y-m-d H:i:s'));
        $input += [
            'authority_decision' => Rules::DEFAULT_DECISION,
            'subjects_decision'  => Rules::DEFAULT_DECISION,
            'date_awareness'     => $now,
        ];
        foreach (array_keys(self::LIST_FIELDS) as $field) {
            $input[$field] ??= [];
        }
        $input = $this->normalizeInput($input);
        if ($input === false) {
            return false;
        }
        // Sans entité dans la saisie : celle de l'incident lié si la personne la voit, sinon l'entité
        // active (celle que check() a contrôlée), jamais la racine.
        if (!isset($input['entities_id'])) {
            $incident = self::readableIncident((int) ($input[self::INCIDENT_FK] ?? 0));
            $incidentEntity = $incident !== null ? (int) $incident->fields['entities_id'] : -1;
            $input['entities_id'] = $incidentEntity >= 0 && Session::haveAccessToEntity($incidentEntity)
                ? $incidentEntity : Session::getActiveEntity();
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // Jamais déplacée vers une entité que la personne ne voit pas.
        if (array_key_exists('entities_id', $input) && !Session::haveAccessToEntity((int) $input['entities_id'])) {
            unset($input['entities_id']);
        }

        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input)
    {
        $enums = [
            'risk_level'         => Rules::normalizeRiskLevel(...),
            'authority_decision' => Rules::normalizeAuthorityDecision(...),
            'subjects_decision'  => Rules::normalizeSubjectsDecision(...),
        ];
        foreach ($enums as $field => $normalize) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $normalize($input[$field]);
            }
        }
        foreach (self::LIST_FIELDS as $field => $catalog) {
            if (array_key_exists($field, $input) || isset($input[self::LISTS_MARKER])) {
                $input[$field] = ProcessingActivityRules::encodeList($input[$field] ?? [], $catalog);
            }
        }
        foreach (['subjects_count', 'records_count'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = Rules::normalizeCount($input[$field]) ?? 'NULL';
            }
        }
        foreach (['date_awareness', 'date_start', 'date_notified'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = Rules::normalizeDateTime($input[$field]) ?? 'NULL';
            }
        }
        if (array_key_exists('date_subjects_informed', $input)) {
            $input['date_subjects_informed'] = Rules::normalizeDate($input['date_subjects_informed']) ?? 'NULL';
        }
        if (array_key_exists(self::INCIDENT_FK, $input)) {
            $incidentId = (int) $input[self::INCIDENT_FK];
            // Seul un incident que la personne peut lire, et pas déjà inscrit au registre.
            if ($incidentId > 0 && self::readableIncident($incidentId) === null) {
                $incidentId = 0;
            }
            if ($incidentId > 0 && self::breachIdForIncident($incidentId, (int) ($this->fields['id'] ?? 0)) > 0) {
                Session::addMessageAfterRedirect(
                    __(
                        'This incident is already recorded in the breach register (look in the trash bin too).',
                        'grcmanager'
                    ),
                    false,
                    ERROR
                );

                return false;
            }
            $input[self::INCIDENT_FK] = $incidentId;
        }

        $merged = $input + $this->fields;
        foreach ($merged as $field => $value) {
            if ($value === 'NULL') {
                $merged[$field] = null;
            }
        }
        $messages = [
            Rules::PROBLEM_NO_AWARENESS => __(
                'The date and time the breach became known are required: the 72 h deadline runs from them.',
                'grcmanager'
            ),
            Rules::PROBLEM_NO_AUTHORITY_REASON => __(
                'A breach that is not notified needs the reason for the decision (GDPR, article 33.5).',
                'grcmanager'
            ),
            Rules::PROBLEM_NO_NOTIFICATION_DATE => __('Enter the date and time of the notification.', 'grcmanager'),
            Rules::PROBLEM_NOTIFIED_BEFORE_AWARENESS => __(
                'The notification cannot be dated before the breach became known.',
                'grcmanager'
            ),
            Rules::PROBLEM_NO_DELAY_REASON => __(
                'Notified more than 72 h after the breach became known: give the reasons for the delay (article 33.1).',
                'grcmanager'
            ),
            Rules::PROBLEM_NO_SUBJECTS_REASON => __(
                'Give the reason why the people concerned are not informed (article 34).',
                'grcmanager'
            ),
        ];
        $problems = Rules::problems($merged);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                Session::addMessageAfterRedirect(htmlescape($messages[$problem]), false, ERROR);
            }

            return false;
        }
        if (Rules::isBlank($merged['name'] ?? null)) {
            $input['name'] = sprintf(
                __('Breach known on %s', 'grcmanager'),
                Html::convDateTime((string) Rules::normalizeDateTime($merged['date_awareness'] ?? null))
            );
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->syncActivitiesFromInput();
        AuditLog::record('create', self::class, (int) $this->getID(), (int) ($this->fields[self::INCIDENT_FK] ?? 0));
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->syncActivitiesFromInput();
        if ($this->updates !== []) {
            $incidentId = (int) ($this->fields[self::INCIDENT_FK] ?? 0);
            AuditLog::record('update', self::class, (int) $this->getID(), $incidentId, 'success', [
                'fields' => array_values($this->updates),
            ]);
        }
    }

    public function post_deleteItem()
    {
        parent::post_deleteItem();
        AuditLog::record('delete', self::class, (int) $this->getID());
    }

    public function post_restoreItem()
    {
        parent::post_restoreItem();
        AuditLog::record('restore', self::class, (int) $this->getID());
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(self::ACTIVITIES_TABLE, ['plugin_grcmanager_databreaches_id' => (int) $this->getID()]);
        AuditLog::record('purge', self::class, (int) $this->getID());
    }

    /**
     * Incident que l'utilisateur courant peut lire, ou null.
     */
    private static function readableIncident(int $incidentId): ?PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incidentId > 0 && $incident->can($incidentId, READ) ? $incident : null;
    }

    /**
     * Fiche du registre qui documente un incident (corbeille comprise), 0 si aucune. Sans contrôle de
     * droit : à ne pas afficher telle quelle.
     */
    public static function breachIdForIncident(int $incidentId, int $exceptId = 0): int
    {
        global $DB;

        if ($incidentId <= 0 || !$DB->tableExists(self::getTable())) {
            return 0;
        }
        $where = [self::INCIDENT_FK => $incidentId];
        if ($exceptId > 0) {
            $where['NOT'] = ['id' => $exceptId];
        }
        $row = $DB->request(['SELECT' => 'id', 'FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1])->current();

        return $row === null ? 0 : (int) $row['id'];
    }

    /**
     * Valeurs proposées pour une fiche créée depuis un incident : à relire et compléter avant
     * l'enregistrement, rien n'est inscrit sans l'action de la personne. L'incident reste inchangé.
     *
     * @return array<string, mixed>
     */
    public static function prefillFromIncident(int $incidentId): array
    {
        $incident = self::readableIncident($incidentId);
        if ($incident === null || self::breachIdForIncident($incidentId) > 0) {
            return [];
        }
        $fields = $incident->fields;
        $prefill = [
            self::INCIDENT_FK => $incidentId,
            'entities_id'     => (int) $fields['entities_id'],
            'name'            => (string) $fields['name'],
            // La date d'ouverture de l'incident : celle dont partent déjà ses échéances réglementaires.
            'date_awareness'  => (string) ($fields['date'] ?? ''),
            'nature'          => ProcessingActivityRules::encodeList(
                explode(',', (string) ($fields['cia_impact'] ?? '')),
                Rules::NATURES
            ),
            '_activity_ids'   => array_column(ProcessingActivity::getForItem($incident), 'id'),
        ];
        // Notification déjà saisie sur l'incident : reprise comme proposition.
        if (!empty($fields['gdpr_notification_at'])) {
            $prefill['authority_decision'] = 'notified';
            $prefill['date_notified'] = (string) $fields['gdpr_notification_at'];
        }

        return $prefill;
    }

    /**
     * @return list<int>
     */
    public static function getActivityIds(int $breachId): array
    {
        global $DB;

        if ($breachId <= 0) {
            return [];
        }
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'plugin_grcmanager_processingactivities_id',
                'FROM'   => self::ACTIVITIES_TABLE,
                'WHERE'  => ['plugin_grcmanager_databreaches_id' => $breachId],
            ]) as $row
        ) {
            $ids[] = (int) $row['plugin_grcmanager_processingactivities_id'];
        }

        return $ids;
    }

    /**
     * Traitements liés que l'utilisateur peut lire (nom et lien), et le nombre des autres.
     *
     * @return array{visible: list<array{id: int, name: string, url: string}>, hidden: int}
     */
    public static function getVisibleActivities(int $breachId): array
    {
        $visible = [];
        $hidden = 0;
        foreach (self::getActivityIds($breachId) as $activityId) {
            $activity = new ProcessingActivity();
            if (!$activity->getFromDB($activityId)) {
                continue;
            }
            if (!$activity->can($activityId, READ)) {
                $hidden++;
                continue;
            }
            $visible[] = [
                'id'   => $activityId,
                'name' => (string) $activity->fields['name'],
                'url'  => ProcessingActivity::getFormURLWithID($activityId),
            ];
        }

        return ['visible' => $visible, 'hidden' => $hidden];
    }

    /**
     * Fiches du registre des traitements que la personne peut lire (hors corbeille).
     *
     * @return array<int, string>
     */
    private static function activityChoices(): array
    {
        global $DB;

        $table = ProcessingActivity::getTable();
        $choices = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => $table,
                'WHERE'  => ['is_deleted' => 0] + getEntitiesRestrictCriteria($table, '', '', true),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $choices[(int) $row['id']] = (string) $row['name'];
        }

        return $choices;
    }

    /**
     * Le formulaire envoie le marqueur des listes : seule cette saisie remplace les traitements liés.
     * Seules les fiches lisibles sont ajoutées ou retirées ; les liens vers des fiches invisibles pour
     * la personne restent en place.
     */
    private function syncActivitiesFromInput(): void
    {
        global $DB;

        if (!isset($this->input[self::LISTS_MARKER]) && !isset($this->input[self::ACTIVITIES_FIELD])) {
            return;
        }
        $breachId = (int) $this->getID();
        $wanted = array_values(array_unique(array_map('intval', (array) ($this->input[self::ACTIVITIES_FIELD] ?? []))));
        $existing = self::getActivityIds($breachId);
        $readable = static fn (int $id): bool => $id > 0 && (new ProcessingActivity())->can($id, READ);
        foreach (array_diff($existing, $wanted) as $activityId) {
            if ($readable($activityId)) {
                $DB->delete(self::ACTIVITIES_TABLE, [
                    'plugin_grcmanager_databreaches_id'         => $breachId,
                    'plugin_grcmanager_processingactivities_id' => $activityId,
                ]);
            }
        }
        foreach (array_diff($wanted, $existing) as $activityId) {
            if ($readable($activityId)) {
                $DB->insert(self::ACTIVITIES_TABLE, [
                    'plugin_grcmanager_databreaches_id'         => $breachId,
                    'plugin_grcmanager_processingactivities_id' => $activityId,
                    'date_creation'                             => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /**
     * Hooks::ITEM_PURGE : un incident supprimé définitivement ne laisse pas de lien mort ; la fiche du
     * registre reste (l'article 33.5 en impose la conservation).
     */
    public static function forgetIncident(CommonDBTM $item): void
    {
        global $DB;

        if ($item instanceof PluginGrcmanagerSecurityIncident && $DB->tableExists(self::getTable())) {
            $DB->update(self::getTable(), [self::INCIDENT_FK => 0], [self::INCIDENT_FK => (int) $item->getID()]);
        }
    }

    /**
     * Fiche du registre des traitements supprimée définitivement : plus citée par les violations.
     */
    public static function forgetActivity(int $activityId): void
    {
        global $DB;

        if ($DB->tableExists(self::ACTIVITIES_TABLE)) {
            $DB->delete(self::ACTIVITIES_TABLE, ['plugin_grcmanager_processingactivities_id' => $activityId]);
        }
    }

    /**
     * Échéance RGPD d'incidents dont la violation est inscrite au registre avec la décision motivée de
     * ne pas notifier : elle n'est plus « à faire » (bandeaux, alertes, tableau de bord). Rien n'est
     * écrit sur l'incident.
     *
     * @param list<array<string, mixed>> $incidents
     * @return list<array<string, mixed>>
     */
    public static function withAuthorityDecisions(array $incidents): array
    {
        global $DB;

        $ids = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $incidents
        )));
        if ($ids === [] || !$DB->tableExists(self::getTable())) {
            return $incidents;
        }
        $notNotified = [];
        foreach (
            $DB->request([
                'SELECT' => self::INCIDENT_FK,
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    self::INCIDENT_FK    => $ids,
                    'authority_decision' => 'not_notified',
                    'is_deleted'         => 0,
                ],
            ]) as $row
        ) {
            $notNotified[(int) $row[self::INCIDENT_FK]] = true;
        }
        foreach ($incidents as $key => $row) {
            if (isset($notNotified[(int) ($row['id'] ?? 0)])) {
                $incidents[$key][RegulatoryDeadlines::GDPR_NOT_NOTIFIED_FLAG] = true;
            }
        }

        return $incidents;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function withAuthorityDecision(array $fields): array
    {
        return self::withAuthorityDecisions([$fields])[0];
    }

    /**
     * Situation de la notification, avec badge.
     *
     * @param array<string, mixed> $fields
     * @return array{state: string, class: string, icon: string, label: string, deadline: ?string, hours_left: ?int}
     */
    public static function stateOf(array $fields): array
    {
        $now = (string) (Session::getCurrentTime() ?: date('Y-m-d H:i:s'));
        $state = Rules::state($fields, $now);
        [$class, $icon, $label] = self::getStateBadges()[$state];
        $awareness = Rules::normalizeDateTime($fields['date_awareness'] ?? null);
        $pending = in_array($state, [Rules::STATE_PENDING, Rules::STATE_SOON, Rules::STATE_OVERDUE], true);

        return [
            'state'      => $state,
            'class'      => $class,
            'icon'       => $icon,
            'label'      => $label,
            'deadline'   => $awareness !== null ? Rules::deadline($awareness) : null,
            'hours_left' => $awareness !== null && $pending ? Rules::hoursLeft($awareness, $now) : null,
        ];
    }

    /**
     * Ce qu'affichent le formulaire et l'onglet de l'incident à propos de l'incident lié.
     *
     * @return array{name: string, url: string, readable: bool}|null
     */
    private static function incidentSummary(int $incidentId): ?array
    {
        if ($incidentId <= 0) {
            return null;
        }
        $incident = new PluginGrcmanagerSecurityIncident();
        if (!$incident->getFromDB($incidentId)) {
            return null;
        }
        $readable = $incident->can($incidentId, READ);

        return [
            'name'     => $readable
                ? trim(((string) ($incident->fields['reference'] ?? '')) . ' ' . $incident->fields['name'])
                : '',
            'url'      => $readable ? PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentId) : '',
            'readable' => $readable,
        ];
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $activityIds = $this->isNewItem() ? [] : self::getActivityIds((int) $ID);
        // Création depuis un incident : proposition préremplie, à relire avant d'enregistrer.
        $fromIncident = $this->isNewItem() ? (int) ($options[self::INCIDENT_FK] ?? 0) : 0;
        $prefilled = false;
        if ($fromIncident > 0) {
            $prefill = self::prefillFromIncident($fromIncident);
            if ($prefill !== []) {
                $activityIds = $prefill['_activity_ids'];
                unset($prefill['_activity_ids']);
                $this->fields = $prefill + $this->fields;
                $prefilled = true;
            }
        }
        $choices = self::activityChoices();
        // Les liens vers des fiches invisibles pour la personne ne sont pas nommés.
        $activityIds = array_values(array_filter($activityIds, static fn (int $id): bool => isset($choices[$id])));
        $hiddenActivities = $this->isNewItem() ? 0 : self::getVisibleActivities((int) $ID)['hidden'];

        TemplateRenderer::getInstance()->display('@grcmanager/data_breach_form.html.twig', [
            'item'               => $this,
            'params'             => $options,
            'prefilled'          => $prefilled,
            'incident_fk'        => self::INCIDENT_FK,
            'incident'           => self::incidentSummary((int) ($this->fields[self::INCIDENT_FK] ?? 0)),
            'state'              => self::stateOf($this->fields),
            'checks'             => $this->isNewItem() && !$prefilled
                ? [] : array_map(self::getCheckLabel(...), Rules::checks($this->fields)),
            'natures'            => self::getNatures(),
            'risk_levels'        => self::getRiskLevels(),
            'authority_decisions' => self::getAuthorityDecisions(),
            'subjects_decisions' => self::getSubjectsDecisions(),
            'data_subjects'      => ProcessingActivity::getDataSubjects(),
            'data_categories'    => ProcessingActivity::getDataCategories(),
            'values'             => [
                'nature'          => ProcessingActivityRules::decodeList($this->fields['nature'] ?? '', Rules::NATURES),
                'data_subjects'   => ProcessingActivityRules::decodeList(
                    $this->fields['data_subjects'] ?? '',
                    ProcessingActivityRules::DATA_SUBJECTS
                ),
                'data_categories' => ProcessingActivityRules::decodeList(
                    $this->fields['data_categories'] ?? '',
                    ProcessingActivityRules::DATA_CATEGORIES
                ),
            ],
            'lists_marker'       => self::LISTS_MARKER,
            'activities_field'   => self::ACTIVITIES_FIELD,
            'activity_choices'   => $choices,
            'activity_values'    => $activityIds,
            'hidden_activities'  => $hiddenActivities,
            'hours_to_notify'    => Rules::HOURS_TO_NOTIFY,
        ]);

        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerSecurityIncident || !self::isTabShownFor($item)) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(1),
            self::breachIdForIncident((int) $item->getID()) > 0 ? 1 : 0,
            null,
            self::getIcon()
        );
    }

    /**
     * Onglet sur l'incident qualifié de violation de données (ou déjà inscrit au registre), pour qui a
     * le droit du registre.
     */
    private static function isTabShownFor(CommonGLPI $item): bool
    {
        return $item instanceof PluginGrcmanagerSecurityIncident && !$item->isNewItem() && self::canView()
            && (!empty($item->fields['personal_data_breach']) || self::breachIdForIncident((int) $item->getID()) > 0);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : même contrôle ici,
        // plus la lecture de l'incident lui-même.
        if (
            !self::isTabShownFor($item) || !$item instanceof PluginGrcmanagerSecurityIncident
            || !$item->can((int) $item->getID(), READ)
        ) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/tabs/incident_data_breach.html.twig',
            self::incidentLink($item)
        );

        return true;
    }

    /**
     * Fiche du registre d'un incident, vue par l'utilisateur courant (onglet, section des notifications
     * réglementaires, plan de remédiation).
     *
     * @return array<string, mixed>
     */
    public static function incidentLink(PluginGrcmanagerSecurityIncident $incident): array
    {
        $breachId = self::breachIdForIncident((int) $incident->getID());
        $breach = new self();
        $exists = $breachId > 0 && $breach->getFromDB($breachId);
        $readable = $exists && $breach->can($breachId, READ);
        $activities = $readable ? self::getVisibleActivities($breachId)['visible'] : [];

        return [
            'exists'      => $exists,
            'readable'    => $readable,
            'is_deleted'  => $readable && (int) $breach->fields['is_deleted'] === 1,
            'url'         => $readable ? self::getFormURLWithID($breachId) : '',
            'name'        => $readable ? (string) $breach->fields['name'] : '',
            'state'       => $readable ? self::stateOf($breach->fields) : null,
            'authority'   => $readable
                ? (self::getAuthorityDecisions()[(string) $breach->fields['authority_decision']] ?? '') : '',
            'subjects'    => $readable
                ? (self::getSubjectsDecisions()[(string) $breach->fields['subjects_decision']] ?? '') : '',
            'activities'  => $activities,
            'can_create'  => !$exists && self::canCreate() && !empty($incident->fields['personal_data_breach']),
            'create_url'  => self::getFormURL() . '?' . self::INCIDENT_FK . '=' . (int) $incident->getID(),
            'register_url' => self::getSearchURL(),
            'flagged'     => !empty($incident->fields['personal_data_breach']),
        ];
    }

    /**
     * Étape « Inscrire la violation au registre » d'un plan de remédiation (procédure « Fuite de
     * données » fournie, ou tâche équivalente) : phase post-incident, intitulé fourni (dans la langue de
     * l'installation ou en anglais) ou description qui cite l'article 33.5, quelle que soit sa langue
     * (« 33.5 », « 33 Abs. 5 », « 33 ust. 5 »).
     *
     * @param array<string, mixed> $task
     */
    public static function isRegisterStep(array $task): bool
    {
        if ((string) ($task['phase'] ?? '') !== 'post_incident') {
            return false;
        }
        $name = trim((string) ($task['name'] ?? ''));
        $step = 'Record the breach in the breach register';
        if ($name === $step || $name === __($step, 'grcmanager')) {
            return true;
        }

        return preg_match('/\b33\D{1,8}5\b/u', (string) ($task['description'] ?? '')) === 1;
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
            'id' => 3, 'table' => $table, 'field' => 'date_awareness',
            'name' => __('Became known on', 'grcmanager'), 'datatype' => 'datetime',
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'authority_decision',
            'name' => __('Notification to the authority', 'grcmanager'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'], 'additionalfields' => ['date_awareness', 'date_notified'],
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'risk_level',
            'name' => __('Risk for the people concerned', 'grcmanager'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'subjects_decision',
            'name' => __('Information of the people concerned', 'grcmanager'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'subjects_count',
            'name' => __('People concerned (approx.)', 'grcmanager'), 'datatype' => 'number',
        ];
        $tab[] = [
            'id' => 8, 'table' => $table, 'field' => 'date_notified',
            'name' => __('Notified on', 'grcmanager'), 'datatype' => 'datetime',
        ];
        $tab[] = [
            'id' => 9, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => __('Handled by', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 10, 'table' => $table, 'field' => 'nature', 'name' => __('Nature of the breach', 'grcmanager'),
            'datatype' => 'specific', 'massiveaction' => false, 'nosearch' => true,
        ];
        $tab[] = [
            'id' => 11, 'table' => $table, 'field' => 'records_count',
            'name' => __('Records concerned (approx.)', 'grcmanager'), 'datatype' => 'number',
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'authority_decision':
                return TemplateRenderer::getInstance()->render(
                    '@grcmanager/_data_breach_state.html.twig',
                    ['state' => self::stateOf($values)]
                );
            case 'risk_level':
                return htmlescape(self::getRiskLevels()[(string) ($values[$field] ?? '')] ?? '');
            case 'subjects_decision':
                return htmlescape(self::getSubjectsDecisions()[(string) ($values[$field] ?? '')] ?? '');
            case 'nature':
                return htmlescape(implode(', ', array_map(
                    static fn (string $key): string => self::getNatures()[$key] ?? $key,
                    ProcessingActivityRules::decodeList($values[$field] ?? '', Rules::NATURES)
                )));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $choices = match ($field) {
            'authority_decision' => self::getAuthorityDecisions(),
            'risk_level'         => self::getRiskLevels(),
            'subjects_decision'  => self::getSubjectsDecisions(),
            default              => null,
        };
        if ($choices !== null) {
            return Dropdown::showFromArray($name, $choices, ['value' => $values[$field] ?? '', 'display' => false]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Fiches du registre pour l'export (PDF ou CSV) : entités de la session, hors corbeille, la plus
     * récente d'abord ; traitements limités à ceux que l'utilisateur peut lire.
     *
     * @return list<array<string, string>>
     */
    public static function registerRows(): array
    {
        global $DB;

        $table = self::getTable();
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => $table,
                'WHERE' => ["$table.is_deleted" => 0] + getEntitiesRestrictCriteria($table, '', '', true),
                'ORDER' => ['date_awareness DESC', 'id DESC'],
            ]) as $row
        ) {
            $rows[] = self::registerRow($row);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function registerRow(array $row): array
    {
        $id = (int) $row['id'];
        $state = self::stateOf($row);
        $labels = static fn (array $catalog, array $keys): string => implode(', ', array_map(
            static fn (string $key): string => $catalog[$key] ?? $key,
            $keys
        ));
        $date = static fn (mixed $value): string => !empty($value) ? (string) Html::convDateTime((string) $value) : '';
        $count = static fn (mixed $value): string => $value === null || $value === '' ? '' : (string) (int) $value;
        $incident = self::incidentSummary((int) ($row[self::INCIDENT_FK] ?? 0));
        $authority = self::getAuthorityDecisions()[(string) $row['authority_decision']] ?? '';
        if ((string) $row['authority_decision'] === 'notified') {
            $authority .= ' - ' . $date($row['date_notified'])
                . ((string) $row['authority_name'] !== '' ? ' - ' . $row['authority_name'] : '')
                . ((string) $row['authority_reference'] !== '' ? ' (' . $row['authority_reference'] . ')' : '');
        }
        $subjects = self::getSubjectsDecisions()[(string) $row['subjects_decision']] ?? '';
        if ((string) $row['subjects_decision'] === 'informed') {
            $informed = !empty($row['date_subjects_informed'])
                ? ' - ' . Html::convDate((string) $row['date_subjects_informed']) : '';
            $subjects .= $informed . ((string) $row['subjects_means'] !== '' ? ' - ' . $row['subjects_means'] : '');
        }

        return [
            'id'               => (string) $id,
            'name'             => (string) $row['name'],
            'entity'           => Dropdown::getDropdownName(Entity::getTable(), (int) $row['entities_id']),
            'incident'         => $incident !== null && $incident['readable'] ? $incident['name'] : '',
            'date_start'       => $date($row['date_start'] ?? null),
            'date_awareness'   => $date($row['date_awareness']),
            'description'      => (string) $row['description'],
            'nature'           => $labels(
                self::getNatures(),
                ProcessingActivityRules::decodeList($row['nature'] ?? '', Rules::NATURES)
            ),
            'activities'       => implode("\n", array_column(self::getVisibleActivities($id)['visible'], 'name')),
            'data_subjects'    => $labels(
                ProcessingActivity::getDataSubjects(),
                ProcessingActivityRules::decodeList($row['data_subjects'] ?? '', ProcessingActivityRules::DATA_SUBJECTS)
            ),
            'subjects_count'   => $count($row['subjects_count'] ?? null),
            'data_categories'  => $labels(
                ProcessingActivity::getDataCategories(),
                ProcessingActivityRules::decodeList(
                    $row['data_categories'] ?? '',
                    ProcessingActivityRules::DATA_CATEGORIES
                )
            ),
            'records_count'    => $count($row['records_count'] ?? null),
            'data_details'     => (string) $row['data_details'],
            'consequences'     => (string) $row['consequences'],
            'risk_level'       => self::getRiskLevels()[(string) $row['risk_level']] ?? '',
            'measures_taken'   => (string) $row['measures_taken'],
            'measures_planned' => (string) $row['measures_planned'],
            'deadline'         => $state['deadline'] !== null ? $date($state['deadline']) : '',
            'authority'        => $authority,
            'state'            => $state['label'],
            'authority_reason' => (string) $row['authority_reason'],
            'delay_reason'     => (string) $row['delay_reason'],
            'subjects'         => $subjects,
            'subjects_reason'  => (string) $row['subjects_reason'],
            'dpo_opinion'      => (string) $row['dpo_opinion'],
        ];
    }

    /**
     * En-têtes de l'export, dans l'ordre de registerRow().
     *
     * @return array<string, string>
     */
    public static function registerColumns(): array
    {
        return [
            'id'               => __('ID'),
            'name'             => __('Name'),
            'entity'           => Entity::getTypeName(1),
            'incident'         => PluginGrcmanagerSecurityIncident::getTypeName(1),
            'date_start'       => __('Breach started on', 'grcmanager'),
            'date_awareness'   => __('Became known on', 'grcmanager'),
            'description'      => __('Facts and circumstances', 'grcmanager'),
            'nature'           => __('Nature of the breach', 'grcmanager'),
            'activities'       => __('Processing activities concerned', 'grcmanager'),
            'data_subjects'    => __('Categories of people concerned', 'grcmanager'),
            'subjects_count'   => __('People concerned (approx.)', 'grcmanager'),
            'data_categories'  => __('Categories of personal data', 'grcmanager'),
            'records_count'    => __('Records concerned (approx.)', 'grcmanager'),
            'data_details'     => __('Details of the data', 'grcmanager'),
            'consequences'     => __('Likely consequences', 'grcmanager'),
            'risk_level'       => __('Risk for the people concerned', 'grcmanager'),
            'measures_taken'   => __('Measures taken', 'grcmanager'),
            'measures_planned' => __('Measures planned', 'grcmanager'),
            'deadline'         => __('72 h deadline', 'grcmanager'),
            'authority'        => __('Notification to the authority', 'grcmanager'),
            'state'            => __('Situation', 'grcmanager'),
            'authority_reason' => __('Reason for not notifying', 'grcmanager'),
            'delay_reason'     => __('Reasons for the delay', 'grcmanager'),
            'subjects'         => __('Information of the people concerned', 'grcmanager'),
            'subjects_reason'  => __('Reason for not informing them', 'grcmanager'),
            'dpo_opinion'      => __('Opinion of the DPO', 'grcmanager'),
        ];
    }

    public static function getExportURL(string $format): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/databreach.export.php?format=' . $format;
    }
}
