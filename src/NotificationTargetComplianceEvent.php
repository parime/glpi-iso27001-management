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
use UserEmail;

/**
 * Issue #142, phase 2 : prévient le responsable 15 jours avant un événement du calendrier de
 * conformité (ComplianceEvent::runReminders()).
 */
class NotificationTargetComplianceEvent extends NotificationTarget
{
    // Hors de la plage des cibles du cœur (1 = administrateur global) : sinon GLPI envoie à
    // l'administrateur et n'appelle jamais addSpecificTargets() (revue de sécurité 2.11.0).
    public const TARGET_RESPONSIBLE = 1150;

    #[Override]
    public function getEvents()
    {
        return [
            ComplianceEvent::REMINDER_EVENT => __('Upcoming compliance event', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_RESPONSIBLE, __('Event owner', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::TARGET_RESPONSIBLE) {
            return;
        }
        $usersId = (int) ($this->obj->fields['users_id'] ?? 0);
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
        /** @var ComplianceEvent $complianceEvent */
        $complianceEvent = $this->obj;
        $fields = $complianceEvent->fields;
        $types = ComplianceEvent::getEventTypes();

        $this->data['##complianceevent.action##'] = $events[$event] ?? '';
        $this->data['##complianceevent.name##']   = (string) ($fields['name'] ?? '');
        $this->data['##complianceevent.type##']   = (string) ($types[$fields['event_type'] ?? ''] ?? '');
        $this->data['##complianceevent.begin##']  = (string) Html::convDateTime($fields['date_begin'] ?? null);
        $this->data['##complianceevent.scope##']  = (string) ($complianceEvent->fields['scope'] ?? '');
        $this->data['##complianceevent.url##']    = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            ComplianceEvent::class . '_' . $complianceEvent->getID()
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
            'complianceevent.action' => _n('Event', 'Events', 1),
            'complianceevent.name'   => __('Name', 'grcmanager'),
            'complianceevent.type'   => __('Type', 'grcmanager'),
            'complianceevent.begin'  => __('Start', 'grcmanager'),
            'complianceevent.scope'  => __('Scope', 'grcmanager'),
            'complianceevent.url'    => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
