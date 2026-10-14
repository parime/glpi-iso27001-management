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

use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;

/**
 * Issue #112 : prévient les approbateurs désignés qu'une version de la SoA attend leur approbation.
 * Cible propre « Approbateurs désignés » : les utilisateurs liés à la version qui n'ont pas encore
 * répondu (glpi_plugin_grcmanager_soaversions_users).
 */
class PluginGrcmanagerNotificationTargetSoaVersion extends NotificationTarget
{
    /** Identifiant de la cible « Approbateurs désignés » (hors plage des constantes de Notification). */
    public const APPROVERS_TARGET = 1120;

    #[Override]
    public function getEvents()
    {
        return [
            PluginGrcmanagerSoaVersion::REQUEST_EVENT => __('Approbation de la SoA demandée', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::APPROVERS_TARGET, __('Approbateurs désignés', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::APPROVERS_TARGET) {
            return;
        }

        foreach (PluginGrcmanagerSoaVersion::getApprovers($this->obj->getID()) as $approver) {
            if ($approver['status'] !== SoaApprovalLogic::ANSWER_PENDING) {
                continue;
            }
            $user = new User();
            if (!$user->getFromDB($approver['users_id'])) {
                continue;
            }
            $this->addToRecipientsList([
                'users_id' => $approver['users_id'],
                'email'    => $user->getDefaultEmail(),
                'language' => (string) ($user->fields['language'] ?? ''),
            ]);
        }
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        $events  = $this->getAllEvents();
        $version = $this->obj;

        $this->data['##soa.action##']      = $events[$event] ?? '';
        $this->data['##soa.version##']     = (string) ($version->fields['version'] ?? '');
        $this->data['##soa.requester##']   = getUserName((int) ($version->fields['users_id'] ?? 0));
        $this->data['##soa.date##']        = Html::convDateTime($version->fields['date_creation'] ?? null);
        $this->data['##soa.comment##']     = (string) ($version->fields['comment'] ?? '');
        $this->data['##soa.fingerprint##'] = substr((string) ($version->fields['fingerprint'] ?? ''), 0, 16);
        $this->data['##soa.url##']         = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/soaapproval.php';

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
            'soa.action'      => _n('Event', 'Events', 1),
            'soa.version'     => __('Version', 'grcmanager'),
            'soa.requester'   => __('Demandeur', 'grcmanager'),
            'soa.date'        => __('Date de la demande', 'grcmanager'),
            'soa.comment'     => __('Commentaire', 'grcmanager'),
            'soa.fingerprint' => __('Empreinte du contenu (SHA-256)', 'grcmanager'),
            'soa.url'         => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
