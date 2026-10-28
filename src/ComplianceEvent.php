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

use CronTask;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\ComplianceEventBase;
use GlpiPlugin\Grcmanager\Services\Calendar\ComplianceCalendarRules;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
use Log;
use NotificationEvent;
use Planning;
use Session;
use User;

/**
 * Issue #142 : calendrier de conformité. Deux choses à la fois :
 *
 * - un registre d'événements datés saisis à la main (audit de certification ou de surveillance,
 *   pentest, exercice de crise, renouvellement de certificat…), avec prestataire, périmètre,
 *   responsable et documents ;
 * - l'agrégation, sans rien recopier, des échéances déjà portées par les autres registres du
 *   plugin : audits internes, revues de direction, campagnes de revue des accès, prochain ticket
 *   des revues périodiques, revues des politiques et des obligations, fin de validité des preuves.
 *
 * Le tout apparaît dans l'écran « Calendrier de conformité », dans le planning GLPI de chaque
 * responsable (type ajouté à $CFG_GLPI['planning_types'], lecture seule) et en export iCalendar.
 * Aucun statut n'est changé par le calendrier.
 */
class ComplianceEvent extends ComplianceEventBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Issue #142 : événement de notification, voir inc/notificationtargetcomplianceevent.class.php. */
    public const REMINDER_EVENT = 'complianceevent_reminder';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_complianceevents';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Compliance event', 'Compliance events', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-event';
    }

    public static function getCalendarURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/compliancecalendar.php';
    }

    /**
     * Entrée de menu vers le calendrier (la liste des événements saisis reste accessible).
     */
    public static function getMenuContent()
    {
        if (!static::canView()) {
            return false;
        }
        $menu = [
            'title' => __('Compliance calendar', 'grcmanager'),
            'page'  => self::getCalendarURL(false),
            'icon'  => self::getIcon(),
            'links' => ['search' => self::getSearchURL(false)],
        ];
        if (static::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }

        return $menu;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * @return array<string, string>
     */
    public static function getEventTypes(): array
    {
        return [
            'internal_audit'      => __('Internal audit', 'grcmanager'),
            'certification_audit' => __('Certification audit', 'grcmanager'),
            'surveillance_audit'  => __('Surveillance audit', 'grcmanager'),
            'pentest'             => __('Penetration test', 'grcmanager'),
            'crisis_exercise'     => __('Crisis exercise', 'grcmanager'),
            'management_review'   => __('Management review', 'grcmanager'),
            'certificate_renewal' => __('Certificate renewal', 'grcmanager'),
            'other'               => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'planned'   => _x('masculine', 'Planned', 'grcmanager'),
            'done'      => _x('masculine', 'Done', 'grcmanager'),
            'cancelled' => __('Cancelled', 'grcmanager'),
        ];
    }

    /**
     * Libellé et icône de chaque source d'échéance du calendrier.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function getSources(): array
    {
        return [
            'event'             => [__('Event', 'grcmanager'), 'ti-calendar-event'],
            'audit'             => [Audit::getTypeName(1), 'ti-clipboard-check'],
            'management_review' => [ManagementReview::getTypeName(1), 'ti-presentation'],
            'access_review'     => [AccessReview::getTypeName(1), 'ti-user-check'],
            'periodic_review'   => [PeriodicReview::getTypeName(1), 'ti-calendar-repeat'],
            'policy'            => [__('Policy review', 'grcmanager'), 'ti-file-certificate'],
            'obligation'        => [__('Obligation review', 'grcmanager'), 'ti-gavel'],
            'evidence'          => [__('Evidence expiry', 'grcmanager'), 'ti-file-alert'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input): array|false
    {
        if (array_key_exists('event_type', $input)) {
            $input['event_type'] = ComplianceCalendarRules::normalizeType($input['event_type']);
        }
        if (array_key_exists('status', $input)) {
            $input['status'] = ComplianceCalendarRules::normalizeStatus($input['status']);
        }
        if (array_key_exists('date_begin', $input) || array_key_exists('date_end', $input)) {
            $range = ComplianceCalendarRules::normalizeRange(
                $input['date_begin'] ?? $this->fields['date_begin'] ?? null,
                $input['date_end'] ?? $this->fields['date_end'] ?? null
            );
            if ($range === null) {
                Session::addMessageAfterRedirect(
                    htmlescape(__('Enter the start date of the event.', 'grcmanager')),
                    false,
                    ERROR
                );
                return false;
            }
            [$input['date_begin'], $input['date_end']] = $range;
            // Issue #142 : événement déplacé, le responsable sera de nouveau prévenu.
            if (!$this->isNewItem() && $input['date_begin'] !== ($this->fields['date_begin'] ?? null)) {
                $input['date_notified'] = 'NULL';
            }
        }
        if (trim((string) ($input['name'] ?? $this->fields['name'] ?? '')) === '') {
            $input['name'] = self::getEventTypes()[$input['event_type'] ?? $this->fields['event_type'] ?? 'other']
                ?? __('Other', 'grcmanager');
        }

        return $input;
    }

    public function rawSearchOptions()
    {
        $table = $this->getTable();
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $tab[] = ['id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name', 'grcmanager'),
            'datatype' => 'itemlink', 'itemtype' => self::class];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'event_type', 'name' => __('Type', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 3, 'table' => $table, 'field' => 'date_begin', 'name' => __('Start', 'grcmanager'),
            'datatype' => 'datetime'];
        $tab[] = ['id' => 4, 'table' => $table, 'field' => 'date_end', 'name' => __('End', 'grcmanager'),
            'datatype' => 'datetime'];
        $tab[] = ['id' => 5, 'table' => $table, 'field' => 'status', 'name' => __('Status', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 6, 'table' => 'glpi_users', 'field' => 'name', 'name' => __('Owner', 'grcmanager'),
            'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => 7, 'table' => 'glpi_suppliers', 'field' => 'name', 'name' => __('Provider', 'grcmanager'),
            'datatype' => 'dropdown'];
        $tab[] = ['id' => 8, 'table' => $table, 'field' => 'scope', 'name' => __('Scope', 'grcmanager'),
            'datatype' => 'text'];
        $tab[] = ['id' => 9, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink',
            'itemtype' => self::class];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        return match ($field) {
            'event_type' => htmlescape(self::getEventTypes()[(string) ($values[$field] ?? '')] ?? ''),
            'status'     => htmlescape(self::getStatuses()[(string) ($values[$field] ?? '')] ?? ''),
            default      => parent::getSpecificValueToDisplay($field, $values, $options),
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
            'event_type' => Dropdown::showFromArray($name, self::getEventTypes(), $options),
            'status'     => Dropdown::showFromArray($name, self::getStatuses(), $options),
            default      => parent::getSpecificValueToSelect($field, $name, $values, $options),
        };
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@grcmanager/compliance_event_form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'types'          => self::getEventTypes(),
            'statuses'       => self::getStatuses(),
            'default_type'   => ComplianceCalendarRules::DEFAULT_EVENT_TYPE,
            'default_status' => ComplianceCalendarRules::DEFAULT_STATUS,
        ]);

        return true;
    }

    /**
     * Toutes les échéances de la période, chacune lisible par la personne : événements saisis et
     * échéances des autres registres. Avec $usersId, seulement celles dont elle est responsable
     * (planning d'une personne).
     *
     * @return list<array{uid: string, source: string, name: string, begin: string, end: string,
     *     users_id: int, url: string, done: bool, label: string}>
     */
    public static function collect(string $begin, string $end, ?int $usersId = null): array
    {
        $candidates = array_merge(
            self::eventDeadlines(),
            self::auditDeadlines(),
            self::managementReviewDeadlines($usersId),
            self::accessReviewDeadlines(),
            self::periodicReviewDeadlines(),
            self::policyDeadlines(),
            self::obligationDeadlines(),
            self::evidenceDeadlines()
        );

        $events = [];
        foreach ($candidates as $candidate) {
            $range = $candidate['range'];
            if ($range === null || ($usersId !== null && $candidate['users_id'] !== $usersId)) {
                continue;
            }
            if (!ComplianceCalendarRules::overlaps($range[0], $range[1], $begin, $end)) {
                continue;
            }
            $events[] = [
                'uid'      => $candidate['source'] . '-' . $candidate['id'] . '@grcmanager',
                'source'   => $candidate['source'],
                'name'     => $candidate['name'],
                'begin'    => $range[0],
                'end'      => $range[1],
                'users_id' => $candidate['users_id'],
                'url'      => $candidate['url'],
                'done'     => $candidate['done'],
                'label'    => $candidate['label'] ?? self::getSources()[$candidate['source']][0],
            ];
        }

        return ComplianceCalendarRules::sortByDate($events);
    }

    /**
     * @param array{0: string, 1: string}|null $range
     * @return array{
     *     source: string, id: int, name: string, range: array{0: string, 1: string}|null,
     *     users_id: int, url: string, done: bool
     * }
     */
    private static function deadline(
        string $source,
        int $id,
        string $name,
        ?array $range,
        int $usersId,
        string $url,
        bool $done = false
    ): array {
        return [
            'source'   => $source,
            'id'       => $id,
            'name'     => $name,
            'range'    => $range,
            'users_id' => $usersId,
            'url'      => $url,
            'done'     => $done,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function eventDeadlines(): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $list = [];
        $rows = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['status' => ['planned', 'done']]]);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $deadline = self::deadline(
                'event',
                $id,
                (string) $row['name'],
                [(string) $row['date_begin'], (string) $row['date_end']],
                (int) $row['users_id'],
                self::getFormURLWithID($id),
                $row['status'] === 'done'
            );
            // Un événement saisi s'affiche sous son type (« Audit de surveillance »), pas « Événement ».
            $deadline['label'] = self::getEventTypes()[(string) $row['event_type']] ?? $deadline['name'];
            $list[] = $deadline;
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function auditDeadlines(): array
    {
        global $DB;

        if (!Audit::canView()) {
            return [];
        }
        $list = [];
        foreach ($DB->request(['FROM' => Audit::getTable()]) as $row) {
            $id = (int) $row['id'];
            $list[] = self::deadline(
                'audit',
                $id,
                (string) $row['title'],
                ComplianceCalendarRules::dayRange($row['actual_date'] ?: $row['planned_date']),
                (int) $row['users_id'],
                Audit::getFormURLWithID($id),
                $row['status'] === 'completed'
            );
        }

        return $list;
    }

    /**
     * Une revue de direction concerne ses participants : pour le planning d'une personne, elle n'y
     * figure que si elle y participe.
     *
     * @return list<array<string, mixed>>
     */
    private static function managementReviewDeadlines(?int $usersId): array
    {
        global $DB;

        if (!ManagementReview::canView()) {
            return [];
        }
        $list = [];
        foreach ($DB->request(['FROM' => ManagementReview::getTable()]) as $row) {
            $id = (int) $row['id'];
            $who = 0;
            if ($usersId !== null) {
                $participates = countElementsInTable('glpi_plugin_grcmanager_managementreviews_users', [
                    'plugin_grcmanager_managementreviews_id' => $id,
                    'users_id'                               => $usersId,
                ]) > 0;
                if (!$participates) {
                    continue;
                }
                $who = $usersId;
            }
            $list[] = self::deadline(
                'management_review',
                $id,
                (string) $row['title'],
                ComplianceCalendarRules::dayRange($row['review_date']),
                $who,
                ManagementReview::getFormURLWithID($id),
                $row['status'] === 'completed'
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function accessReviewDeadlines(): array
    {
        global $DB;

        if (!AccessReview::canView()) {
            return [];
        }
        $table = AccessReview::getTable();
        $list = [];
        $rows = $DB->request([
            'FROM'  => $table,
            'WHERE' => ['status' => ['draft', 'open']] + getEntitiesRestrictCriteria($table, '', '', true),
        ]);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $list[] = self::deadline(
                'access_review',
                $id,
                (string) $row['name'],
                ComplianceCalendarRules::dayRange($row['due_date']),
                (int) $row['users_id'],
                AccessReview::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function periodicReviewDeadlines(): array
    {
        global $DB;

        if (!PeriodicReview::canView()) {
            return [];
        }
        $list = [];
        $rows = $DB->request(['FROM' => PeriodicReview::getTable(), 'WHERE' => ['is_active' => 1]]);
        foreach ($rows as $row) {
            $review = new PeriodicReview();
            $review->getFromDB((int) $row['id']);
            $list[] = self::deadline(
                'periodic_review',
                (int) $row['id'],
                PeriodicReview::getLabel($review->getKey()),
                ComplianceCalendarRules::dayRange($review->getNextCreationDate()),
                (int) $row['users_id_assign'],
                PeriodicReview::getSearchURL()
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function policyDeadlines(): array
    {
        global $DB;

        if (!Policy::canView()) {
            return [];
        }
        $list = [];
        $rows = $DB->request(['FROM' => Policy::getTable(), 'WHERE' => ['status' => 'approved']]);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $list[] = self::deadline(
                'policy',
                $id,
                (string) $row['title'],
                ComplianceCalendarRules::dayRange($row['next_review_date']),
                (int) $row['users_id'],
                Policy::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function obligationDeadlines(): array
    {
        global $DB;

        if (!ComplianceObligation::canView()) {
            return [];
        }
        $list = [];
        foreach ($DB->request(['FROM' => ComplianceObligation::getTable()]) as $row) {
            $id = (int) $row['id'];
            $list[] = self::deadline(
                'obligation',
                $id,
                (string) $row['title'],
                ComplianceCalendarRules::dayRange($row['review_date']),
                (int) $row['users_id'],
                ComplianceObligation::getFormURLWithID($id)
            );
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function evidenceDeadlines(): array
    {
        global $DB, $CFG_GLPI;

        if (!Evidence::canView()) {
            return [];
        }
        $table = Evidence::getTable();
        $list = [];
        $rows = $DB->request(['FROM' => $table, 'WHERE' => getEntitiesRestrictCriteria($table, '', '', true)]);
        foreach ($rows as $row) {
            $evidence = new Evidence();
            if (!$evidence->getFromDB((int) $row['id']) || !$evidence->canViewItem()) {
                continue;
            }
            $list[] = self::deadline(
                'evidence',
                (int) $row['id'],
                (string) Dropdown::getDropdownName('glpi_documents', (int) $row['documents_id']),
                ComplianceCalendarRules::dayRange($row['valid_until']),
                (int) $row['users_id'],
                $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/evidence.php'
            );
        }

        return $list;
    }

    /**
     * Planning GLPI : les échéances dont la personne affichée est responsable (lecture seule).
     *
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    public static function populatePlanning($options = []): array
    {
        global $CFG_GLPI;

        $who = (int) ($options['who'] ?? 0);
        if ($who <= 0 || empty($options['begin']) || empty($options['end'])) {
            return [];
        }
        $events = [];
        foreach (self::collect((string) $options['begin'], (string) $options['end'], $who) as $event) {
            if ($event['done'] && empty($options['state_done'])) {
                continue;
            }
            $events['grcmanager_' . $event['uid']] = [
                'color'            => $options['color'] ?? '',
                'event_type_color' => $options['event_type_color'] ?? '',
                'itemtype'         => self::class,
                'id'               => $event['uid'],
                'users_id'         => $who,
                'state'            => $event['done'] ? Planning::DONE : Planning::TODO,
                'name'             => $event['label'] . ' : ' . $event['name'],
                'text'             => '',
                'url'              => ($options['genical'] ?? false)
                    ? $CFG_GLPI['url_base'] . $event['url']
                    : $event['url'],
                'editable'         => false,
                'begin'            => $event['begin'],
                'end'              => $event['end'],
            ];
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $val
     */
    public static function displayPlanningItem(array $val, $who, $type = '', $complete = false): string
    {
        return Fragment::link((string) ($val['url'] ?? ''), (string) ($val['name'] ?? ''), '', 'ti-shield-check');
    }

    /**
     * Écran « Calendrier de conformité » : les échéances des 12 prochains mois (et du mois écoulé),
     * groupées par mois.
     */
    public static function showCalendar(bool $mine): void
    {
        $begin = date('Y-m-01 00:00:00', (int) strtotime('-1 month'));
        $end = date('Y-m-01 00:00:00', (int) strtotime('+12 months'));
        $sources = self::getSources();
        $today = date('Y-m-d');
        $months = [];
        foreach (self::collect($begin, $end, $mine ? Session::getLoginUserID() : null) as $event) {
            $month = substr($event['begin'], 0, 7);
            $months[$month] ??= ['label' => self::monthLabel($month), 'events' => []];
            $months[$month]['events'][] = [
                'date'  => substr($event['begin'], 0, 10),
                'icon'  => $sources[$event['source']][1],
                'label' => $event['label'],
                'url'   => $event['url'],
                'name'  => $event['name'],
                'done'  => $event['done'],
                // Une échéance d'un registre non réalisée et dépassée ; un événement saisi n'est jamais « en retard ».
                'late'  => !$event['done'] && substr($event['end'], 0, 10) <= $today && $event['source'] !== 'event',
                'owner' => $event['users_id'] > 0 ? (string) getUserName($event['users_id']) : '',
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/compliance_calendar.html.twig', [
            'months'     => array_values($months),
            'mine'       => $mine,
            'url'        => self::getCalendarURL(),
            'form_url'   => self::getFormURL(),
            'ics_url'    => self::getIcsURL($mine),
            'subscription_url' => self::getSubscriptionURL($mine),
            'can_create' => self::canCreate(),
        ]);
    }

    private static function monthLabel(string $yearMonth): string
    {
        $months = [__('January', 'grcmanager'), __('February', 'grcmanager'), __('March', 'grcmanager'),
            __('April', 'grcmanager'), __('May', 'grcmanager'), __('June', 'grcmanager'), __('July', 'grcmanager'),
            __('August', 'grcmanager'), __('September', 'grcmanager'), __('October', 'grcmanager'),
            __('November', 'grcmanager'), __('December', 'grcmanager')];

        return $months[(int) substr($yearMonth, 5, 2) - 1] . ' ' . substr($yearMonth, 0, 4);
    }

    /**
     * Issue #142, phase 2 : lien d'abonnement pour un agenda externe, sans session GLPI. Il porte
     * le jeton personnel de l'utilisateur (le même que le flux iCal du planning de GLPI), et le
     * flux se calcule avec ses droits et ses entités. Régénérer ce jeton dans ses préférences
     * invalide le lien.
     */
    public static function getSubscriptionURL(bool $mine): string
    {
        global $CFG_GLPI;

        $token = User::getToken((int) Session::getLoginUserID(), 'personal_token');
        if (!is_string($token) || $token === '') {
            return '';
        }

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/compliancecalendar.ics.php?'
            . http_build_query(['token' => $token] + ($mine ? ['mine' => 1] : []));
    }

    public static function getIcsURL(bool $mine): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/compliancecalendar.php?ics=1'
            . ($mine ? '&mine=1' : '');
    }

    /**
     * Export iCalendar des échéances des 12 prochains mois (et du mois écoulé).
     */
    public static function exportIcs(bool $mine): string
    {
        global $CFG_GLPI;

        $events = [];
        $begin = date('Y-m-01 00:00:00', (int) strtotime('-1 month'));
        $end = date('Y-m-01 00:00:00', (int) strtotime('+12 months'));
        foreach (self::collect($begin, $end, $mine ? Session::getLoginUserID() : null) as $event) {
            $events[] = [
                'uid'   => $event['uid'],
                'name'  => $event['label'] . ' : ' . $event['name'],
                'begin' => $event['begin'],
                'end'   => $event['end'],
                'url'   => rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
                    . preg_replace('#^' . preg_quote((string) $CFG_GLPI['root_doc'], '#') . '#', '', $event['url']),
            ];
        }

        $name = __('Compliance calendar', 'grcmanager');

        return ComplianceCalendarRules::toIcs($events, $name, date('Y-m-d H:i:s'));
    }

    /**
     * @return array{description: string}|array{}
     */
    public static function cronInfo($name)
    {
        return $name === 'complianceeventreminder'
            ? ['description' => __('Notifies the owner 15 days before a compliance calendar event', 'grcmanager')]
            : [];
    }

    public static function cronComplianceeventreminder(CronTask $task): int
    {
        $notified = self::runReminders(date('Y-m-d'));
        $task->addVolume($notified);
        $task->log(sprintf('%d rappel(s) d\'événement de conformité.', $notified));

        return $notified > 0 ? 1 : 0;
    }

    /**
     * Issue #142, phase 2 : un rappel par événement saisi (audit externe, pentest, certification…),
     * sans aucune action automatique sur l'événement. Les échéances des autres registres ont déjà
     * leurs propres rappels.
     */
    public static function runReminders(string $today): int
    {
        global $DB;

        $notified = 0;
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['status' => 'planned', 'date_notified' => null, ['users_id' => ['>', 0]]],
            ]) as $row
        ) {
            $dateBegin = $row['date_begin'] !== null ? (string) $row['date_begin'] : null;
            if (!ComplianceCalendarRules::reminderDue('planned', $dateBegin, (int) $row['users_id'], null, $today)) {
                continue;
            }
            $event = new self();
            if (!$event->getFromDB((int) $row['id'])) {
                continue;
            }
            NotificationEvent::raiseEvent(self::REMINDER_EVENT, $event);
            $DB->update(self::getTable(), ['date_notified' => date('Y-m-d H:i:s')], ['id' => $event->getID()]);
            $notified++;
        }

        return $notified;
    }
}
