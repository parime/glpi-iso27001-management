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

use Notification;
use NotificationTarget;
use Override;

/**
 * Issue #778 : rappel de la réévaluation annuelle de la sécurité physique d'un site (tâche
 * PhysicalSecurityAssessment::cronReassessmentreminder()), envoyé à l'évaluateur.
 */
class NotificationTargetPhysicalSecurityAssessment extends NotificationTarget
{
    use \GlpiPlugin\Grcmanager\Traits\CoreNotificationTargetFormTrait;

    #[Override]
    public function getEvents()
    {
        return [
            PhysicalSecurityAssessment::REMINDER_EVENT => __('Physical security reassessment due', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Assessor', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var PhysicalSecurityAssessment $item */
        $item = $this->obj;

        $this->data['##physicalsecurity.action##'] = $events[$event] ?? '';
        $this->data['##physicalsecurity.name##'] = $item->getName();
        $this->data['##physicalsecurity.site##'] = PhysicalSecurityAssessment::siteName(
            (int) ($item->fields['locations_id'] ?? 0)
        );
        $this->data['##physicalsecurity.nextdate##'] = $item->nextAssessmentLabel();
        $this->data['##physicalsecurity.url##'] = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            PhysicalSecurityAssessment::class . '_' . $item->getID()
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
            'physicalsecurity.action'   => _n('Event', 'Events', 1),
            'physicalsecurity.name'     => __('Name'),
            'physicalsecurity.site'     => __('Site', 'grcmanager'),
            'physicalsecurity.nextdate' => __('Next assessment', 'grcmanager'),
            'physicalsecurity.url'      => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
