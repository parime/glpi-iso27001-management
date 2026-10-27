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
 * GLPI notification target for PluginGrcmanagerRiskTreatmentAction (issue #31, overdue treatment
 * action reminders, see src/Services/Risk/OverdueTreatmentActionService.php and its Cron entry
 * point PluginGrcmanagerRiskTreatmentAction::cronOverduetreatmentaction()). Class name/file
 * location follow the exact same GLPI core naming convention already confirmed live for the other
 * itemtypes of this plugin, see inc/notificationtargetrisk.class.php's own docblock: for a plugin
 * item "PluginGrcmanagerRiskTreatmentAction", GLPI expects a global class
 * "PluginGrcmanagerNotificationTargetRiskTreatmentAction" in
 * "inc/notificationtargetrisktreatmentaction.class.php".
 *
 * The notification's own URL (##treatmentaction.url##) points at the PARENT risk's form, not a
 * form of this itemtype's own: a treatment action has no own menu entry or form URL (see
 * inc/risktreatmentaction.class.php's docblock), it only ever appears inline on
 * PluginGrcmanagerRisk::showTreatmentPlan(), so that is where a notified user needs to land.
 */
class PluginGrcmanagerNotificationTargetRiskTreatmentAction extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [
            PluginGrcmanagerRiskTreatmentAction::OVERDUE_EVENT => __(
                'Action de traitement de risque en retard',
                'grcmanager'
            ),
        ];
    }

    /**
     * Default recipient: the treatment action's own responsible owner (`users_id`), same generic
     * "item owner" resolution already used by every other NotificationTarget of this plugin.
     */
    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Responsable de l\'action', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $action = $this->obj;

        $riskId = (int) ($action->fields['plugin_grcmanager_risks_id'] ?? 0);
        $risk   = new PluginGrcmanagerRisk();
        $riskTitle = $risk->getFromDB($riskId) ? ($risk->fields['title'] ?? '') : '';

        $this->data['##treatmentaction.action##']     = $events[$event] ?? '';
        $this->data['##treatmentaction.risktitle##']  = $riskTitle;
        $this->data['##treatmentaction.description##'] = $action->fields['description'] ?? '';
        $this->data['##treatmentaction.duedate##']    = Html::convDate($action->fields['due_date'] ?? null);
        $this->data['##treatmentaction.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            PluginGrcmanagerRisk::class . '_' . $riskId
        );
        // Registre sensible : e-mail sans détail par défaut (SensitiveNotification).
        if (\GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification::isMinimal()) {
            $this->data = \GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification::reduce($this->data, [
                '##treatmentaction.risktitle##' => sprintf(__('Risque n° %d', 'grcmanager'), $riskId),
            ], ['##treatmentaction.description##']);
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
            'treatmentaction.action'      => _n('Event', 'Events', 1),
            'treatmentaction.risktitle'   => PluginGrcmanagerRisk::getTypeName(1),
            'treatmentaction.description' => __('Description de l\'action', 'grcmanager'),
            'treatmentaction.duedate'     => __('Échéance', 'grcmanager'),
            'treatmentaction.url'         => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
