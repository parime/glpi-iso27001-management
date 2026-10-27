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
 * GLPI notification target for PluginGrcmanagerRisk (Sprint 2 review-date reminders, see
 * src/Services/Risk/ReviewReminderService.php and its Cron entry point
 * PluginGrcmanagerRisk::cronReviewreminder()). Class name/file location are dictated by GLPI core
 * (NotificationTarget::getInstanceClass()): for a plugin item "PluginGrcmanagerRisk", GLPI expects
 * a global class "PluginGrcmanagerNotificationTargetRisk" in "inc/notificationtargetrisk.class.php"
 * (isPluginItemType()'s regex splits "Grcmanager"/"Risk", Toolbox's plugin filename resolution
 * lowercases the remainder), confirmed by reading GLPI 11 core (src/NotificationTarget.php,
 * src/autoload/misc-functions.php), no prior art for this in the sibling plugins of this author.
 */
class PluginGrcmanagerNotificationTargetRisk extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [PluginGrcmanagerRisk::REVIEW_DUE_EVENT => __('Revue de risque à échéance', 'grcmanager')];
    }

    /**
     * Default recipient: the risk's own owner (`users_id`), same generic "item owner" resolution
     * GLPI core uses for any CommonDBTM with a `users_id` field (NotificationTarget::addItemOwner(),
     * confirmed by reading src/NotificationTarget.php), no plugin-specific target logic needed.
     */
    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Propriétaire du risque', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $risk   = $this->obj;

        $categories = PluginGrcmanagerRisk::getCategories();
        $levels     = PluginGrcmanagerRisk::getImpacts();

        $this->data['##risk.action##']     = $events[$event] ?? '';
        $this->data['##risk.title##']      = $risk->fields['title'] ?? '';
        $this->data['##risk.category##']   = $categories[$risk->fields['category'] ?? ''] ?? '';
        $this->data['##risk.risklevel##']  = $levels[$risk->fields['risk_level'] ?? ''] ?? '';
        $this->data['##risk.reviewdate##'] = Html::convDate($risk->fields['review_date'] ?? null);
        $this->data['##risk.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            PluginGrcmanagerRisk::class . '_' . $risk->getID()
        );
        // Registre sensible : e-mail sans détail par défaut (SensitiveNotification).
        if (\GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification::isMinimal()) {
            $this->data = \GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification::reduce($this->data, [
                '##risk.title##' => sprintf(__('Risque n° %d', 'grcmanager'), (int) ($risk->fields['id'] ?? 0)),
            ], ['##risk.category##', '##risk.risklevel##']);
        }

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
            'risk.action'     => _n('Event', 'Events', 1),
            'risk.title'      => __('Titre', 'grcmanager'),
            'risk.category'   => __('Catégorie', 'grcmanager'),
            'risk.risklevel'  => __('Niveau de risque', 'grcmanager'),
            'risk.reviewdate' => __('Date de revue', 'grcmanager'),
            'risk.url'        => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
