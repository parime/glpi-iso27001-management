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
 * GLPI notification target for PluginGrcmanagerPolicy (issue #28 review-date reminders, see
 * src/Services/Policy/PolicyReviewReminderService.php and its Cron entry point
 * PluginGrcmanagerPolicy::cronReviewreminder()). Class name/file location are dictated by GLPI
 * core (NotificationTarget::getInstanceClass()): for a plugin item "PluginGrcmanagerPolicy", GLPI
 * expects a global class "PluginGrcmanagerNotificationTargetPolicy" in
 * "inc/notificationtargetpolicy.class.php", same convention already confirmed live for
 * inc/notificationtargetrisk.class.php (see its own docblock).
 */
class PluginGrcmanagerNotificationTargetPolicy extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [
            PluginGrcmanagerPolicy::REVIEW_DUE_EVENT => __('Revue de politique à échéance', 'grcmanager'),
        ];
    }

    /**
     * Default recipient: the policy's own owner (`users_id`), same generic "item owner"
     * resolution GLPI core uses for any CommonDBTM with a `users_id` field
     * (NotificationTarget::addItemOwner()), same convention as
     * PluginGrcmanagerNotificationTargetRisk::addAdditionalTargets().
     */
    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Propriétaire de la politique', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var PluginGrcmanagerPolicy $policy */
        $policy = $this->obj;

        $statuses = PluginGrcmanagerPolicy::getStatuses();

        $this->data['##policy.action##']     = $events[$event] ?? '';
        $this->data['##policy.title##']      = $policy->fields['title'] ?? '';
        $this->data['##policy.version##']    = $policy->fields['version'] ?? '';
        $this->data['##policy.status##']     = $statuses[$policy->fields['status'] ?? ''] ?? '';
        $this->data['##policy.reviewdate##'] = Html::convDate($policy->fields['next_review_date'] ?? null);
        $this->data['##policy.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            PluginGrcmanagerPolicy::class . '_' . $policy->getID()
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
            'policy.action'     => _n('Event', 'Events', 1),
            'policy.title'      => __('Titre', 'grcmanager'),
            'policy.version'    => __('Version', 'grcmanager'),
            'policy.status'     => __('Statut', 'grcmanager'),
            'policy.reviewdate' => __('Prochaine revue', 'grcmanager'),
            'policy.url'        => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
