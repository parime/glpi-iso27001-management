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
 * Issue #538 : notifications des demandes de preuves d'un audit. La personne désignée reçoit la
 * demande, les relances et le renvoi « à compléter » ; l'auditeur et le responsable de l'audit
 * reçoivent la soumission.
 */
class NotificationTargetAuditEvidenceRequest extends NotificationTarget
{
    // Hors de la plage des cibles du cœur (voir NotificationTargetEvidence::TARGET_RESPONSIBLE).
    public const TARGET_ASSIGNEE = 1200;
    public const TARGET_AUDIT_LEADS = 1210;

    #[Override]
    public function getEvents()
    {
        return [
            AuditEvidenceRequest::NEW_EVENT         => __('Audit evidence to provide', 'grcmanager'),
            AuditEvidenceRequest::REMINDER_EVENT    => __('Audit evidence reminder', 'grcmanager'),
            AuditEvidenceRequest::SUBMITTED_EVENT   => __('Audit evidence submitted', 'grcmanager'),
            AuditEvidenceRequest::TO_COMPLETE_EVENT => __('Audit evidence to complete', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_ASSIGNEE, __('Person who provides the evidence', 'grcmanager'));
        $this->addTarget(self::TARGET_AUDIT_LEADS, __('Auditor and owner of the audit', 'grcmanager'));
    }

    /**
     * Un audit est global (sans entité) : la personne désignée est prévenue quelle que soit
     * l'entité de ses habilitations, sous-entités comprises.
     */
    protected function isTargetItemRecursive(): bool
    {
        return true;
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        $target = (int) ($data['items_id'] ?? 0);
        $users = [];
        if ($target === self::TARGET_ASSIGNEE) {
            $users[] = (int) ($this->obj->fields['users_id'] ?? 0);
        } elseif ($target === self::TARGET_AUDIT_LEADS) {
            $audit = new Audit();
            if ($audit->getFromDB((int) ($this->obj->fields['plugin_grcmanager_audits_id'] ?? 0))) {
                $users[] = (int) $audit->fields['users_id'];
                $users[] = (int) ($audit->fields['users_id_responsible'] ?? 0);
            }
        } else {
            return;
        }
        foreach (array_unique(array_filter($users)) as $usersId) {
            $user = new User();
            if (!$user->getFromDB($usersId)) {
                continue;
            }
            $email = UserEmail::getDefaultForUser($usersId);
            if (empty($email)) {
                continue;
            }
            $this->addToRecipientsList([
                'language' => $user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? 'en_GB'),
                'name'     => formatUserName(0, $user->fields['name'], $user->fields['realname'], $user->fields['firstname']),
                'email'    => $email,
                'users_id' => $usersId,
            ]);
        }
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $fields = $this->obj->fields;
        $audit = new Audit();
        $audit->getFromDB((int) ($fields['plugin_grcmanager_audits_id'] ?? 0));

        $this->data['##evidencerequest.action##']        = $events[$event] ?? '';
        $this->data['##evidencerequest.reference##']     = AuditEvidenceRequest::label($fields);
        $this->data['##evidencerequest.description##']   = (string) ($fields['description'] ?? '');
        $this->data['##evidencerequest.control##']       = AuditEvidenceRequest::controlLabel(
            (int) ($fields['plugin_grcmanager_controls_id'] ?? 0)
        );
        $this->data['##evidencerequest.audit##']         = (string) ($audit->fields['title'] ?? '');
        $this->data['##evidencerequest.duedate##']       = (string) Html::convDate($fields['due_date'] ?? null);
        $this->data['##evidencerequest.status##']        = AuditEvidenceRequest::getStatusLabel((string) ($fields['status'] ?? ''));
        $this->data['##evidencerequest.assignee##']      = (int) ($fields['users_id'] ?? 0) > 0
            ? (string) getUserName((int) $fields['users_id'])
            : '';
        $this->data['##evidencerequest.reviewcomment##'] = (string) ($fields['review_comment'] ?? '');
        $this->data['##evidencerequest.comment##']       = (string) ($fields['submission_comment'] ?? '');
        $this->data['##evidencerequest.mineurl##']       = AuditEvidenceRequest::getMineAbsoluteURL();
        $this->data['##evidencerequest.auditurl##']      = $audit->isNewItem() ? '' : $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            Audit::class . '_' . $audit->getID() . '_' . AuditEvidenceRequest::class . '$1'
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
            'evidencerequest.action'        => _n('Event', 'Events', 1),
            'evidencerequest.reference'     => __('Reference', 'grcmanager'),
            'evidencerequest.description'   => __('Description', 'grcmanager'),
            'evidencerequest.control'       => __('Control', 'grcmanager'),
            'evidencerequest.audit'         => __('Audit', 'grcmanager'),
            'evidencerequest.duedate'       => __('Due date', 'grcmanager'),
            'evidencerequest.status'        => __('Status', 'grcmanager'),
            'evidencerequest.assignee'      => __('Person who provides the evidence', 'grcmanager'),
            'evidencerequest.reviewcomment' => __('Reason', 'grcmanager'),
            'evidencerequest.comment'       => __('Comment', 'grcmanager'),
            'evidencerequest.mineurl'       => __('My evidence requests page URL', 'grcmanager'),
            'evidencerequest.auditurl'      => __('Audit URL', 'grcmanager'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
