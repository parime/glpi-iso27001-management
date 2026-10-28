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

/**
 * Issue #170 : prévient le responsable qu'une dérogation arrive à sa fin
 * (PluginGrcmanagerWaiver::runReminders()).
 */
class PluginGrcmanagerNotificationTargetWaiver extends NotificationTarget
{
    // Hors de la plage des cibles du cœur (1 = administrateur global) : sinon GLPI envoie à
    // l'administrateur et n'appelle jamais addSpecificTargets() (revue de sécurité 2.11.0).
    public const TARGET_RESPONSIBLE = 1160;

    #[Override]
    public function getEvents()
    {
        return [
            PluginGrcmanagerWaiver::EXPIRING_EVENT => __('Waiver ending soon', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_RESPONSIBLE, __('Owner (accepts the risk)', 'grcmanager'));
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
        /** @var PluginGrcmanagerWaiver $waiver */
        $waiver = $this->obj;

        $this->data['##waiver.action##']  = $events[$event] ?? '';
        $this->data['##waiver.name##']    = (string) ($waiver->fields['name'] ?? '');
        $this->data['##waiver.dateend##'] = (string) Html::convDate($waiver->fields['date_end'] ?? null);
        $this->data['##waiver.url##']     = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            PluginGrcmanagerWaiver::class . '_' . $waiver->getID()
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
            'waiver.action'  => _n('Event', 'Events', 1),
            'waiver.name'    => __('Nom'),
            'waiver.dateend' => __('End date', 'grcmanager'),
            'waiver.url'     => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
