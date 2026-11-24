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

use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules;
use Html;
use NotificationTarget;
use Override;
use User;

/**
 * Issue #611 : notifications de l'approbation des politiques. Demande : aux approbateurs désignés
 * qui n'ont pas encore répondu. Décision (approuvée ou refusée) : au rédacteur qui a soumis la
 * version et au responsable de la politique. Modèles modifiables dans GLPI comme les autres.
 */
class NotificationTargetPolicyVersion extends NotificationTarget
{
    use \GlpiPlugin\Grcmanager\Traits\CoreNotificationTargetFormTrait;

    /** Identifiants des cibles propres (hors plage des constantes de Notification). */
    public const APPROVERS_TARGET = 1220;
    public const REQUESTER_TARGET = 1221;
    public const OWNER_TARGET = 1222;

    #[Override]
    public function getEvents()
    {
        return [
            PolicyVersion::REQUEST_EVENT  => __('Policy approval requested', 'grcmanager'),
            PolicyVersion::DECISION_EVENT => __('Policy approval decision', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::APPROVERS_TARGET, __('Designated approvers', 'grcmanager'));
        $this->addTarget(self::REQUESTER_TARGET, __('Person who submitted the version', 'grcmanager'));
        $this->addTarget(self::OWNER_TARGET, __('Policy owner', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        $versionId = (int) ($this->obj->fields['id'] ?? 0);
        $users = [];
        switch ((int) ($data['items_id'] ?? 0)) {
            case self::APPROVERS_TARGET:
                foreach (PolicyVersion::getApprovers($versionId) as $approver) {
                    if ($approver['status'] === PolicyApprovalRules::ANSWER_PENDING) {
                        $users[] = $approver['users_id'];
                    }
                }
                break;
            case self::REQUESTER_TARGET:
                $users[] = (int) ($this->obj->fields['users_id'] ?? 0);
                break;
            case self::OWNER_TARGET:
                $policy = new Policy();
                if ($policy->getFromDB((int) ($this->obj->fields['plugin_grcmanager_policies_id'] ?? 0))) {
                    $users[] = (int) $policy->fields['users_id'];
                }
                break;
            default:
                return;
        }
        foreach (array_unique($users) as $usersId) {
            $user = new User();
            if ($usersId <= 0 || !$user->getFromDB($usersId)) {
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
        $events  = $this->getAllEvents();
        $version = $this->obj;
        $policy  = new Policy();
        $policy->getFromDB((int) ($version->fields['plugin_grcmanager_policies_id'] ?? 0));

        $comments = [];
        foreach (PolicyVersion::getApprovers((int) ($version->fields['id'] ?? 0)) as $approver) {
            if ($approver['comment'] !== '') {
                $comments[] = sprintf(__('%1$s: %2$s'), $approver['name'], $approver['comment']);
            }
        }
        $statuses = PolicyVersion::getStatuses();

        $this->data['##policyapproval.action##']      = $events[$event] ?? '';
        $this->data['##policyapproval.title##']       = (string) ($policy->fields['title'] ?? '');
        $this->data['##policyapproval.version##']     = (string) ($version->fields['version'] ?? '');
        $this->data['##policyapproval.requester##']   = getUserName((int) ($version->fields['users_id'] ?? 0));
        $this->data['##policyapproval.date##']        = Html::convDateTime($version->fields['date_creation'] ?? null);
        $this->data['##policyapproval.comment##']     = (string) ($version->fields['comment'] ?? '');
        $this->data['##policyapproval.decision##']    = $statuses[$version->fields['status'] ?? ''] ?? '';
        $this->data['##policyapproval.comments##']    = implode("\n", $comments);
        $this->data['##policyapproval.fingerprint##'] = substr((string) ($version->fields['fingerprint'] ?? ''), 0, 16);
        $this->data['##policyapproval.url##']         = PolicyVersion::getPageURL(true);
        $this->data['##policyapproval.policyurl##']   = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            Policy::class . '_' . (int) ($version->fields['plugin_grcmanager_policies_id'] ?? 0)
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
            'policyapproval.action'      => _n('Event', 'Events', 1),
            'policyapproval.title'       => __('Title', 'grcmanager'),
            'policyapproval.version'     => __('Version', 'grcmanager'),
            'policyapproval.requester'   => __('Requester', 'grcmanager'),
            'policyapproval.date'        => __('Request date', 'grcmanager'),
            'policyapproval.comment'     => __('Comment', 'grcmanager'),
            'policyapproval.decision'    => __('Decision', 'grcmanager'),
            'policyapproval.comments'    => __('Comments of the approvers', 'grcmanager'),
            'policyapproval.fingerprint' => __('Content fingerprint (SHA-256)', 'grcmanager'),
            'policyapproval.url'         => __('Approval page URL', 'grcmanager'),
            'policyapproval.policyurl'   => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
