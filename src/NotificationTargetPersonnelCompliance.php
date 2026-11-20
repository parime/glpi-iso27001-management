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

use NotificationTarget;
use Override;
use User;

/**
 * Issue #546 : prévient le responsable hiérarchique d'une personne des écarts de sa fiche de
 * conformité sécurité (tâche planifiée ou bouton de la fiche). Cible propre « Responsable
 * hiérarchique de la personne » : le responsable déclaré sur le compte (champ natif de GLPI).
 */
class NotificationTargetPersonnelCompliance extends NotificationTarget
{
    /** Identifiant de la cible (hors plage des constantes de Notification). */
    public const SUPERVISOR_TARGET = 1230;

    #[Override]
    public function getEvents()
    {
        return [PersonnelCompliance::GAP_EVENT => __('Personnel security compliance gap', 'grcmanager')];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::SUPERVISOR_TARGET, __('Manager of the person', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::SUPERVISOR_TARGET) {
            return;
        }
        $person = new User();
        if (!$person->getFromDB((int) ($this->obj->fields['users_id'] ?? 0))) {
            return;
        }
        $supervisor = new User();
        if (!$supervisor->getFromDB((int) $person->fields['users_id_supervisor'])) {
            return;
        }
        $this->addToRecipientsList([
            'users_id' => $supervisor->getID(),
            'email'    => $supervisor->getDefaultEmail(),
            'language' => (string) ($supervisor->fields['language'] ?? ''),
        ]);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        $usersId = (int) ($this->obj->fields['users_id'] ?? 0);
        $events = $this->getAllEvents();
        $gaps = is_array($options['gaps'] ?? null) ? $options['gaps'] : [];

        $this->data['##personnelcompliance.action##'] = $events[$event] ?? '';
        $this->data['##personnelcompliance.user##'] = (string) ($options['fullname'] ?? getUserName($usersId));
        $this->data['##personnelcompliance.gaps##'] = implode("\n", array_map('strval', $gaps));
        $this->data['##personnelcompliance.url##'] = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/front/user.form.php?id=' . $usersId;

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
            'personnelcompliance.action' => _n('Event', 'Events', 1),
            'personnelcompliance.user'   => __('User', 'grcmanager'),
            'personnelcompliance.gaps'   => __('Gaps', 'grcmanager'),
            'personnelcompliance.url'    => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
