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
use NotificationTarget;
use Override;
use User;

/**
 * Issue #126 : prévient les responsables qu'une campagne de revue des accès attend leur
 * validation, puis relance ceux qui sont en retard. Cible propre « Responsables à relancer » :
 * les responsables qui ont encore des comptes à revoir (pour une relance, seulement ceux que la
 * tâche planifiée a désignés, option « reviewers »).
 */
class NotificationTargetAccessReview extends NotificationTarget
{
    /** Identifiant de la cible (hors plage des constantes de Notification). */
    public const REVIEWERS_TARGET = 1130;

    #[Override]
    public function getEvents()
    {
        return [
            AccessReview::REQUEST_EVENT  => __('Access review to complete', 'grcmanager'),
            AccessReview::REMINDER_EVENT => __('Access review reminder', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::REVIEWERS_TARGET, __('Reviewers with accounts to review', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::REVIEWERS_TARGET) {
            return;
        }

        $reviewers = AccessReview::getPendingReviewerIds((int) ($this->obj->fields['id'] ?? 0));
        if (isset($options['reviewers']) && is_array($options['reviewers'])) {
            $reviewers = array_values(array_intersect($reviewers, array_map('intval', $options['reviewers'])));
        }
        foreach ($reviewers as $usersId) {
            $user = new User();
            if (!$user->getFromDB($usersId)) {
                continue;
            }
            $this->addToRecipientsList([
                'users_id' => $usersId,
                'email'    => $user->getDefaultEmail(),
                'language' => (string) ($user->fields['language'] ?? ''),
            ]);
        }
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        $events = $this->getAllEvents();
        $campaign = $this->obj;

        $this->data['##accessreview.action##'] = $events[$event] ?? '';
        $this->data['##accessreview.name##'] = (string) ($campaign->fields['name'] ?? '');
        $this->data['##accessreview.owner##'] = getUserName((int) ($campaign->fields['users_id'] ?? 0));
        $this->data['##accessreview.duedate##'] = Html::convDate($campaign->fields['due_date'] ?? null);
        $this->data['##accessreview.comment##'] = (string) ($campaign->fields['comment'] ?? '');
        $this->data['##accessreview.url##'] = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/accessreview.mine.php';

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
            'accessreview.action'  => _n('Event', 'Events', 1),
            'accessreview.name'    => __('Campaign', 'grcmanager'),
            'accessreview.owner'   => _x('process pilot', 'Owner', 'grcmanager'),
            'accessreview.duedate' => __('Due date', 'grcmanager'),
            'accessreview.comment' => __('Comment', 'grcmanager'),
            'accessreview.url'     => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
