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
 * Issue #608 : rappel de revue d'un enjeu ou d'une partie intéressée (tâche
 * ContextItem::cronReviewreminder(), même service que les risques et les obligations).
 */
class NotificationTargetContextItem extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [
            ContextItem::REVIEW_DUE_EVENT => __('Context review due', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Owner of the issue or interested party', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var ContextItem $item */
        $item = $this->obj;

        $this->data['##contextitem.action##']     = $events[$event] ?? '';
        $this->data['##contextitem.name##']       = $item->fields['name'] ?? '';
        $this->data['##contextitem.kind##']       = ContextItem::getKinds()[$item->fields['kind'] ?? ''] ?? '';
        $relevances = ContextItem::getRelevances();
        $this->data['##contextitem.decision##']   = $relevances[$item->fields['relevance'] ?? ''] ?? '';
        $this->data['##contextitem.reviewdate##'] = Html::convDate($item->fields['review_date'] ?? null);
        $this->data['##contextitem.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            ContextItem::class . '_' . $item->getID()
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
            'contextitem.action'     => _n('Event', 'Events', 1),
            'contextitem.name'       => __('Name'),
            'contextitem.kind'       => __('Kind', 'grcmanager'),
            'contextitem.decision'   => __('Decision', 'grcmanager'),
            'contextitem.reviewdate' => __('Review date', 'grcmanager'),
            'contextitem.url'        => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
