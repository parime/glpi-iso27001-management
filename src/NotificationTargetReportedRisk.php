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
use User;
use UserEmail;

/**
 * Issue #872 : la personne qui a signalé un risque est prévenue de la décision du tri. Le message
 * ne cite que son propre signalement, la décision et la réponse écrite : ni le registre ni le
 * risque retenu (la personne n'a souvent aucun droit sur le plugin).
 */
class NotificationTargetReportedRisk extends NotificationTarget
{
    use \GlpiPlugin\Grcmanager\Traits\CoreNotificationTargetFormTrait;

    // Hors de la plage des cibles du cœur (voir NotificationTargetEvidence::TARGET_RESPONSIBLE).
    public const TARGET_AUTHOR = 1872;

    #[Override]
    public function getEvents()
    {
        return [
            ReportedRisk::DECIDED_EVENT => __('Your risk report has been examined', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_AUTHOR, __('Person who reported the risk', 'grcmanager'));
    }

    /** @return array<string, mixed> */
    private function reportFields(): array
    {
        return $this->obj instanceof ReportedRisk ? $this->obj->fields : [];
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::TARGET_AUTHOR) {
            return;
        }
        $usersId = (int) ($this->reportFields()['users_id'] ?? 0);
        $user = new User();
        if ($usersId <= 0 || !$user->getFromDB($usersId)) {
            return;
        }
        $email = UserEmail::getDefaultForUser($usersId);
        if (empty($email)) {
            return;
        }
        $this->addToRecipientsList([
            'language' => $user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? 'en_GB'),
            'name'     => formatUserName(
                0,
                $user->fields['name'],
                $user->fields['realname'],
                $user->fields['firstname']
            ),
            'email'    => $email,
            'users_id' => $usersId,
        ]);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $fields = $this->reportFields();
        $styles = ReportedRisk::statusStyles();

        $this->data['##reportedrisk.action##']  = $events[$event] ?? '';
        $this->data['##reportedrisk.name##']    = (string) ($fields['name'] ?? '');
        $this->data['##reportedrisk.status##']  = $styles[(string) ($fields['status'] ?? '')]['mine'] ?? '';
        $this->data['##reportedrisk.comment##'] = (string) ($fields['decision_comment'] ?? '');
        $this->data['##reportedrisk.mineurl##'] = ReportedRisk::getMineAbsoluteURL();

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
            'reportedrisk.action'  => _n('Event', 'Events', 1),
            'reportedrisk.name'    => __('Title', 'grcmanager'),
            'reportedrisk.status'  => __('Decision', 'grcmanager'),
            'reportedrisk.comment' => __('Answer from the person in charge of risks', 'grcmanager'),
            'reportedrisk.mineurl' => __('My risk reports page URL', 'grcmanager'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
