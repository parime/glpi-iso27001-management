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
 * GLPI notification target for PluginGrcmanagerComplianceObligation (issue #30 review-date
 * reminders, see src/Services/Risk/ReviewReminderService.php and its Cron entry point
 * PluginGrcmanagerComplianceObligation::cronReviewreminder()). Class name/file location follow the
 * exact same GLPI core naming convention already confirmed live for the sibling risk register, see
 * inc/notificationtargetrisk.class.php's own docblock: for a plugin item
 * "PluginGrcmanagerComplianceObligation", GLPI expects a global class
 * "PluginGrcmanagerNotificationTargetComplianceObligation" in
 * "inc/notificationtargetcomplianceobligation.class.php".
 */
class PluginGrcmanagerNotificationTargetComplianceObligation extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [
            PluginGrcmanagerComplianceObligation::REVIEW_DUE_EVENT => __('Obligation review due', 'grcmanager'),
        ];
    }

    /**
     * Default recipient: the obligation's own owner (`users_id`), same generic "item owner"
     * resolution already used by PluginGrcmanagerNotificationTargetRisk.
     */
    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Obligation owner', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events     = $this->getAllEvents();
        /** @var PluginGrcmanagerComplianceObligation $obligation */
        $obligation = $this->obj;

        $types    = PluginGrcmanagerComplianceObligation::getTypes();
        $statuses = PluginGrcmanagerComplianceObligation::getComplianceStatuses();

        $this->data['##complianceobligation.action##']     = $events[$event] ?? '';
        $this->data['##complianceobligation.title##']      = $obligation->fields['title'] ?? '';
        $this->data['##complianceobligation.type##']       = $types[$obligation->fields['type'] ?? ''] ?? '';
        $this->data['##complianceobligation.status##']     =
            $statuses[$obligation->fields['compliance_status'] ?? ''] ?? '';
        $this->data['##complianceobligation.reviewdate##'] = Html::convDate(
            $obligation->fields['review_date'] ?? null
        );
        $this->data['##complianceobligation.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            PluginGrcmanagerComplianceObligation::class . '_' . $obligation->getID()
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
            'complianceobligation.action'     => _n('Event', 'Events', 1),
            'complianceobligation.title'      => __('Title', 'grcmanager'),
            'complianceobligation.type'       => __('Type', 'grcmanager'),
            'complianceobligation.status'     => __('Compliance status', 'grcmanager'),
            'complianceobligation.reviewdate' => __('Review date', 'grcmanager'),
            'complianceobligation.url'        => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
