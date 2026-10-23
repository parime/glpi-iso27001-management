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
 * Issue #145 : prévient le responsable du renouvellement qu'une preuve arrive à échéance
 * (PluginGrcmanagerEvidence::runReminders()).
 */
class PluginGrcmanagerNotificationTargetEvidence extends NotificationTarget
{
    public const TARGET_RESPONSIBLE = 1;

    #[Override]
    public function getEvents()
    {
        return [
            PluginGrcmanagerEvidence::EXPIRING_EVENT => __('Preuve à renouveler', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_RESPONSIBLE, __('Responsable du renouvellement', 'grcmanager'));
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
            'name'     => formatUserName(0, $user->fields['name'], $user->fields['realname'], $user->fields['firstname']),
            'email'    => $email,
            'users_id' => $usersId,
        ]);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var PluginGrcmanagerEvidence $evidence */
        $evidence = $this->obj;
        $parent = $evidence->getParent();

        $this->data['##evidence.action##']     = $events[$event] ?? '';
        $this->data['##evidence.document##']   = $evidence->getDocumentName();
        $this->data['##evidence.item##']       = $parent !== null ? $parent::getTypeName(1) . ' — ' . $parent->getName() : '';
        $this->data['##evidence.validuntil##'] = (string) Html::convDate($evidence->fields['valid_until'] ?? null);
        $this->data['##evidence.url##']        = $parent !== null
            ? $this->formatURL($options['additionnaloption']['usertype'] ?? self::GLPI_USER, $parent->getType() . '_' . $parent->getID())
            : '';

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
            'evidence.action'     => _n('Event', 'Events', 1),
            'evidence.document'   => __('Document', 'grcmanager'),
            'evidence.item'       => __('Élément', 'grcmanager'),
            'evidence.validuntil' => __('Valide jusqu\'au', 'grcmanager'),
            'evidence.url'        => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
