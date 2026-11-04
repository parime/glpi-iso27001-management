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

use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\DataSubjectRequestBase;
use GlpiPlugin\Grcmanager\Services\Privacy\DataSubjectRequestRules as Rules;
use Html;
use Log;
use Session;
use Ticket;

/**
 * Issue #375 : demande d'exercice des droits (RGPD, articles 15 à 22) et son délai de réponse (article
 * 12.3). Droit RGPD dédié et entité de la fiche, comme le registre des traitements.
 *
 * Donnée personnelle : l'identité du demandeur reste sur cette fiche. Le ticket de suivi et le calendrier
 * de conformité, plus largement visibles, ne reprennent ni elle ni le titre libre.
 */
class DataSubjectRequest extends DataSubjectRequestBase
{
    public const RIGHTNAME = ProcessingActivity::RIGHTNAME;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_datasubjectrequests';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Data subject request', 'Data subject requests', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-user-question';
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
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            'access'        => __('Access (article 15)', 'grcmanager'),
            'rectification' => __('Rectification (article 16)', 'grcmanager'),
            'erasure'       => __('Erasure (article 17)', 'grcmanager'),
            'restriction'   => __('Restriction of processing (article 18)', 'grcmanager'),
            'portability'   => __('Portability (article 20)', 'grcmanager'),
            'objection'     => __('Objection (article 21)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'received'       => __('Received', 'grcmanager'),
            'identity_check' => __('Identity being checked', 'grcmanager'),
            'in_progress'    => __('In progress', 'grcmanager'),
            'answered'       => __('Answered', 'grcmanager'),
            'refused'        => __('Refused', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> État => classe du badge, libellé.
     */
    public static function getStateBadges(): array
    {
        return [
            Rules::STATE_OK          => ['bg-green-lt', __('On time', 'grcmanager')],
            Rules::STATE_SOON        => ['bg-yellow-lt', __('Deadline approaching', 'grcmanager')],
            Rules::STATE_OVERDUE     => ['bg-red-lt', __('Overdue', 'grcmanager')],
            Rules::STATE_CLOSED      => ['bg-secondary-lt', __('Closed on time', 'grcmanager')],
            Rules::STATE_LATE_ANSWER => ['bg-orange-lt', __('Closed late', 'grcmanager')],
        ];
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
        // Sans entité dans la saisie : l'entité active, celle que check() a contrôlée, jamais la racine.
        $input += [
            'entities_id'   => Session::getActiveEntity(),
            'request_type'  => Rules::DEFAULT_TYPE,
            'status'        => Rules::DEFAULT_STATUS,
            'date_received' => date('Y-m-d', strtotime((string) (Session::getCurrentTime() ?: 'now'))),
        ];

        return $this->normalizeInput($input);
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
        // L'échéance et le ticket ne se saisissent jamais.
        unset($input['date_due'], $input['tickets_id']);
        if (array_key_exists('request_type', $input)) {
            $input['request_type'] = Rules::normalizeType($input['request_type']);
        }
        if (array_key_exists('status', $input)) {
            $input['status'] = Rules::normalizeStatus($input['status']);
        }
        foreach (['date_received', 'date_answered'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = Rules::normalizeDate($input[$field]);
            }
        }
        if (array_key_exists('is_extended', $input)) {
            $input['is_extended'] = empty($input['is_extended']) ? 0 : 1;
        }
        $activityKey = ProcessingActivity::getForeignKeyField();
        if (array_key_exists($activityKey, $input)) {
            // Seule une fiche du registre que la personne peut lire.
            $activityId = (int) $input[$activityKey];
            $input[$activityKey] = $activityId > 0 && (new ProcessingActivity())->can($activityId, READ)
                ? $activityId : 0;
        }

        $merged = $input + $this->fields;
        $received = Rules::normalizeDate($merged['date_received'] ?? null);
        if ($received === null) {
            Session::addMessageAfterRedirect(__('The date of receipt is required.', 'grcmanager'), false, ERROR);

            return false;
        }
        $status = (string) ($merged['status'] ?? '');
        // Une réponse sans date prend celle du jour : c'est elle qui prouve le respect du délai.
        if (Rules::isClosed($status) && Rules::normalizeDate($merged['date_answered'] ?? null) === null) {
            $input['date_answered'] = date('Y-m-d', strtotime((string) (Session::getCurrentTime() ?: 'now')));
            $merged['date_answered'] = $input['date_answered'];
        }
        $messages = [
            'extension_reason' => __('An extension of the deadline must be justified.', 'grcmanager'),
            'refusal_reason'   => __('A refusal must be justified.', 'grcmanager'),
            'date_answered'    => __('The date of the answer is required.', 'grcmanager'),
        ];
        $problems = Rules::problems($merged);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                Session::addMessageAfterRedirect($messages[$problem], false, ERROR);
            }

            return false;
        }
        $input['date_due'] = Rules::dueDate($received, !empty($merged['is_extended']));
        if (trim((string) ($merged['name'] ?? '')) === '') {
            $type = self::getTypes()[(string) ($merged['request_type'] ?? '')] ?? self::getTypeName(1);
            $input['name'] = sprintf(__('%1$s: %2$s'), $type, $received);
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        AuditLog::record('create', self::class, (int) $this->getID());
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        if ($this->updates !== []) {
            AuditLog::record('update', self::class, (int) $this->getID(), 0, 'success', [
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
        parent::post_purgeItem();
        AuditLog::record('purge', self::class, (int) $this->getID());
    }

    /**
     * État de la demande à ce jour.
     *
     * @return array{state: string, class: string, label: string, days_left: ?int}
     */
    public static function stateOf(array $fields): array
    {
        $today = date('Y-m-d', strtotime((string) (Session::getCurrentTime() ?: 'now')));
        $due = Rules::normalizeDate($fields['date_due'] ?? null);
        $state = Rules::state(
            (string) ($fields['status'] ?? ''),
            $due,
            Rules::normalizeDate($fields['date_answered'] ?? null),
            $today
        );
        [$class, $label] = self::getStateBadges()[$state];

        return [
            'state'     => $state,
            'class'     => $class,
            'label'     => $label,
            'days_left' => $due !== null && !Rules::isClosed((string) ($fields['status'] ?? ''))
                ? Rules::daysLeft($due, $today) : null,
        ];
    }

    /**
     * Ticket de suivi, à la demande et une seule fois. Il ne reprend ni l'identité du demandeur ni le
     * titre libre : un ticket est lu par bien plus de monde que le registre RGPD.
     */
    public function createTicket(): int
    {
        global $DB;

        if (!$this->can($this->getID(), UPDATE) || !Session::haveRight(Ticket::$rightname, CREATE)) {
            return 0;
        }
        $existing = new Ticket();
        if ((int) $this->fields['tickets_id'] > 0 && $existing->getFromDB((int) $this->fields['tickets_id'])) {
            return (int) $existing->getID();
        }
        $type = self::getTypes()[(string) $this->fields['request_type']] ?? '';
        $input = [
            'name'        => sprintf(__('GDPR request no. %1$d: %2$s', 'grcmanager'), $this->getID(), $type),
            'content'     => TemplateRenderer::getInstance()->render(
                '@grcmanager/ticket/data_subject_request.html.twig',
                [
                    'type' => $type,
                    'due'  => Html::convDate((string) $this->fields['date_due']),
                    'url'  => self::getFormURLWithID((int) $this->getID()),
                ]
            ),
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => (int) $this->fields['entities_id'],
        ];
        if ((int) $this->fields['users_id'] > 0) {
            $input['_users_id_assign'] = (int) $this->fields['users_id'];
        }
        $ticketsId = (int) (new Ticket())->add($input);
        if ($ticketsId <= 0) {
            return 0;
        }
        $DB->update(self::getTable(), ['tickets_id' => $ticketsId], ['id' => (int) $this->getID()]);
        $this->fields['tickets_id'] = $ticketsId;
        AuditLog::record('ticket_create', Ticket::class, $ticketsId, 0, 'success', [
            'itemtype' => self::class, 'items_id' => (int) $this->getID(),
        ]);

        return $ticketsId;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $activities = [0 => Dropdown::EMPTY_VALUE];
        $visible = getEntitiesRestrictCriteria(ProcessingActivity::getTable());
        foreach ((new ProcessingActivity())->find($visible, ['name ASC']) as $row) {
            $activities[(int) $row['id']] = (string) $row['name'];
        }
        $ticket = new Ticket();
        $ticketId = (int) ($this->fields['tickets_id'] ?? 0);
        $hasTicket = $ticketId > 0 && $ticket->getFromDB($ticketId) && $ticket->canViewItem();

        TemplateRenderer::getInstance()->display('@grcmanager/data_subject_request_form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'types'           => self::getTypes(),
            'statuses'        => self::getStatuses(),
            'activities'      => $activities,
            'state'           => $this->isNewItem() ? null : self::stateOf($this->fields),
            'due_label'       => $this->isNewItem() ? '' : Html::convDate((string) $this->fields['date_due']),
            'ticket_url'      => $hasTicket ? Ticket::getFormURLWithID($ticketId) : '',
            'ticket_name'     => $hasTicket ? (string) $ticket->fields['name'] : '',
            'can_ticket'      => !$this->isNewItem() && !$hasTicket && $this->canUpdateItem()
                && Session::haveRight(Ticket::$rightname, CREATE),
        ]);

        return true;
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
            'id' => 3, 'table' => $table, 'field' => 'request_type',
            'name' => __('Right exercised', 'grcmanager'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'status', 'name' => __('Status'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'date_received',
            'name' => __('Date of receipt', 'grcmanager'), 'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'date_due', 'name' => __('Deadline', 'grcmanager'),
            'datatype' => 'specific', 'massiveaction' => false, 'searchtype' => ['contains'],
            'additionalfields' => ['status', 'date_answered'],
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'date_answered',
            'name' => __('Date of the answer', 'grcmanager'), 'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => __('Handled by', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 9, 'table' => $table, 'field' => 'requester', 'name' => __('Requester', 'grcmanager'),
            'datatype' => 'string',
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
            case 'request_type':
                return htmlescape(self::getTypes()[(string) ($values[$field] ?? '')] ?? '');
            case 'status':
                return htmlescape(self::getStatuses()[(string) ($values[$field] ?? '')] ?? '');
            case 'date_due':
                $state = self::stateOf($values);

                return TemplateRenderer::getInstance()->render(
                    '@grcmanager/_data_subject_request_deadline.html.twig',
                    ['due' => Html::convDate((string) ($values['date_due'] ?? '')), 'state' => $state]
                );
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $choices = match ($field) {
            'request_type' => self::getTypes(),
            'status'       => self::getStatuses(),
            default        => null,
        };
        if ($choices !== null) {
            return Dropdown::showFromArray($name, $choices, ['value' => $values[$field] ?? '', 'display' => false]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
