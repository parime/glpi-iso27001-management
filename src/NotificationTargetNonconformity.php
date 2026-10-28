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

use Html;
use Notification;
use NotificationTarget;
use Override;

/**
 * GLPI notification target for Nonconformity (Sprint 4 overdue CAPA reminders, see
 * src/Services/Capa/OverdueCapaService.php and its Cron entry point
 * Nonconformity::cronOverduecapa()). Class name/file location follow the exact same
 * GLPI core naming convention already confirmed live for the sibling risk register, see
 * inc/notificationtargetrisk.class.php's own docblock: for a plugin item
 * "Nonconformity", GLPI expects a global class
 * "NotificationTargetNonconformity" in "inc/notificationtargetnonconformity.class.php".
 */
class NotificationTargetNonconformity extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [
            Nonconformity::CAPA_OVERDUE_EVENT => __(
                'Overdue corrective/preventive action',
                'grcmanager'
            ),
        ];
    }

    /**
     * Default recipient: the non-conformity's own responsible owner (`users_id`), same generic
     * "item owner" resolution already used by NotificationTargetRisk.
     */
    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Non-conformity owner', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var Nonconformity $nc */
        $nc     = $this->obj;

        $severities = Nonconformity::getSeverities();

        $this->data['##nc.action##']   = $events[$event] ?? '';
        $this->data['##nc.title##']    = $nc->fields['title'] ?? '';
        $this->data['##nc.severity##'] = $severities[$nc->fields['severity'] ?? ''] ?? '';
        $this->data['##nc.duedate##']  = Html::convDate($nc->fields['due_date'] ?? null);
        $this->data['##nc.audit##']    = Nonconformity::getAuditTitle(
            (int) ($nc->fields['plugin_grcmanager_audits_id'] ?? 0)
        );
        $this->data['##nc.url##']      = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            Nonconformity::class . '_' . $nc->getID()
        );

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    #[Override]
    public function getTags()
    {
        $tags = [
            'nc.action'   => _n('Event', 'Events', 1),
            'nc.title'    => __('Title', 'grcmanager'),
            'nc.severity' => __('Severity', 'grcmanager'),
            'nc.duedate'  => __('Due date', 'grcmanager'),
            'nc.audit'    => Audit::getTypeName(1),
            'nc.url'      => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
