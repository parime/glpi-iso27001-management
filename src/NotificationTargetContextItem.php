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
    use \GlpiPlugin\Grcmanager\Traits\CoreNotificationTargetFormTrait;

    // Issue #771, lot 2 : interlocuteur interne habilité d'un contact officiel (personne et groupe).
    // Hors de la plage des cibles du cœur : sinon GLPI n'appelle jamais addSpecificTargets().
    public const TARGET_INTERNAL_USER = 1771;
    public const TARGET_INTERNAL_GROUP = 1772;

    #[Override]
    public function getEvents()
    {
        return [
            ContextItem::REVIEW_DUE_EVENT => __('Context review due', 'grcmanager'),
            // Issue #771 : contact officiel non vérifié depuis 12 mois.
            ContextItem::CONTACT_VERIFICATION_EVENT => __('Official contact to verify', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Owner of the issue or interested party', 'grcmanager'));
        if ($event === '' || $event === ContextItem::CONTACT_VERIFICATION_EVENT) {
            $this->addTarget(self::TARGET_INTERNAL_USER, __('Authorised internal contact', 'grcmanager'));
            $this->addTarget(self::TARGET_INTERNAL_GROUP, __('Authorised internal group', 'grcmanager'));
        }
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        switch ((int) ($data['items_id'] ?? 0)) {
            case self::TARGET_INTERNAL_USER:
                $usersId = (int) ($this->obj->fields['users_id_internal'] ?? 0);
                if ($usersId > 0) {
                    $this->addToRecipientsList(['users_id' => $usersId] + NotificationSupport::userInfo($usersId));
                }
                break;

            case self::TARGET_INTERNAL_GROUP:
                $groupsId = (int) ($this->obj->fields['groups_id_internal'] ?? 0);
                if ($groupsId > 0) {
                    $this->addForGroup(0, $groupsId);
                }
                break;
        }
    }

    /**
     * Issue #771, lot 2 : la relance d'un contact officiel n'est envoyée qu'à un utilisateur GLPI qui
     * peut lire la fiche (droit du contexte dans son entité), quelle que soit la cible.
     */
    #[Override]
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        if ($event === ContextItem::CONTACT_VERIFICATION_EVENT) {
            $fields = $this->obj->fields ?? [];
            if (
                !NotificationSupport::canReceive(
                    (int) ($infos['users_id'] ?? 0),
                    (int) ($fields['entities_id'] ?? 0),
                    !empty($fields['is_recursive']),
                    [ContextItem::RIGHTNAME => READ]
                )
            ) {
                return false;
            }
        }

        return parent::validateSendTo($event, $infos, $notify_me, $emitter);
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
        $this->data['##contextitem.contactcategory##'] = ContextItem::getContactCategories()[
            (string) ($item->fields['contact_category'] ?? '')
        ] ?? '';
        $verified = $item->fields['contact_verified_date'] ?? null;
        $this->data['##contextitem.contactverified##'] = empty($verified)
            ? __('Never verified', 'grcmanager')
            : Html::convDate($verified);
        $this->data['##contextitem.url##']       = $this->formatURL(
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
            'contextitem.contactcategory' => __('Contact category', 'grcmanager'),
            'contextitem.contactverified' => __('Last verification', 'grcmanager'),
            'contextitem.url'        => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
