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

use NotificationTarget;
use Override;

/**
 * Issue #371 : alertes d'accès au module des incidents de sécurité, au groupe RSSI.
 */
class NotificationTargetIncidentAccessWatch extends NotificationTarget
{
    public const TARGET_CISO_GROUP = 1831;

    #[Override]
    public function getEvents()
    {
        return [
            IncidentAccessWatch::ACCESS_CHANGED_EVENT  => __(
                'Access to the security incidents module changed',
                'grcmanager'
            ),
            IncidentAccessWatch::MODULE_DISABLED_EVENT => __('Security incidents module disabled', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_CISO_GROUP, __('CISO group (GRC Manager)', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        $groupsId = AuditTeam::getCisoGroupId();
        if ((int) ($data['items_id'] ?? 0) === self::TARGET_CISO_GROUP && $groupsId > 0) {
            $this->addForGroup(0, $groupsId);
        }
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $lines = array_map(
            static fn (array $change): string => IncidentAccessWatch::describe($change),
            (array) ($options['changes'] ?? [])
        );
        $this->data['##accesswatch.action##'] = $events[$event] ?? '';
        $this->data['##accesswatch.changes##'] = implode("\n", $lines);
        $this->data['##accesswatch.count##'] = (string) count($lines);
        global $CFG_GLPI;

        $this->data['##accesswatch.url##'] = $CFG_GLPI['url_base'] . '/plugins/grcmanager/front/auditlog.php';

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
            'accesswatch.action'  => _n('Event', 'Events', 1),
            'accesswatch.changes' => __('Changes', 'grcmanager'),
            'accesswatch.count'   => __('Number of changes', 'grcmanager'),
            'accesswatch.url'     => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
    }
}
