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
 * GLPI notification target for PluginGrcmanagerSupplierRisk (Sprint 5 review-date reminders, see
 * src/Services/Risk/ReviewReminderService.php and its Cron entry point
 * PluginGrcmanagerSupplierRisk::cronReviewreminder()). Class name/file location follow the exact
 * same GLPI core convention already confirmed live for the generic risk register, see
 * inc/notificationtargetrisk.class.php's own docblock: for itemtype "PluginGrcmanagerSupplierRisk",
 * GLPI expects a global class "PluginGrcmanagerNotificationTargetSupplierRisk" in
 * "inc/notificationtargetsupplierrisk.class.php".
 */
class PluginGrcmanagerNotificationTargetSupplierRisk extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [
            PluginGrcmanagerSupplierRisk::REVIEW_DUE_EVENT => __(
                'Revue de risque fournisseur à échéance',
                'grcmanager'
            ),
            // Issue #167 : nouvel avis du CERT-FR qui cite le fournisseur.
            PluginGrcmanagerSecurityWatch::NOTIFY_EVENT => __(
                'Nouvel avis de sécurité concernant le fournisseur',
                'grcmanager'
            ),
        ];
    }

    /**
     * Default recipient: the risk's own owner (`users_id`), same generic "item owner" resolution
     * as PluginGrcmanagerNotificationTargetRisk::addAdditionalTargets().
     */
    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Propriétaire du risque fournisseur', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $risk   = $this->obj;

        $categories = PluginGrcmanagerSupplierRisk::getCategories();
        $levels     = PluginGrcmanagerSupplierRisk::getImpacts();

        $this->data['##supplierrisk.action##']     = $events[$event] ?? '';
        $this->data['##supplierrisk.title##']      = $risk->fields['title'] ?? '';
        $this->data['##supplierrisk.supplier##']   = Dropdown::getDropdownName(
            'glpi_suppliers',
            $risk->fields['suppliers_id'] ?? 0
        );
        $this->data['##supplierrisk.category##']   = $categories[$risk->fields['category'] ?? ''] ?? '';
        $this->data['##supplierrisk.risklevel##']  = $levels[$risk->fields['risk_level'] ?? ''] ?? '';
        $this->data['##supplierrisk.reviewdate##'] = Html::convDate($risk->fields['review_date'] ?? null);
        $this->data['##supplierrisk.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            PluginGrcmanagerSupplierRisk::class . '_' . $risk->getID()
        );

        $this->data['##supplierrisk.notices##'] = implode("\n", array_map(
            static fn (array $notice): string => $notice['ref'] . ' — ' . $notice['title'] . ' — ' . $notice['link'],
            $options['notices'] ?? []
        ));
        // Registre sensible : e-mail sans détail par défaut (SensitiveNotification).
        if (\GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification::isMinimal()) {
            $this->data = \GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification::reduce($this->data, [
                '##supplierrisk.title##' => sprintf(
                    __('Risque fournisseur n° %d', 'grcmanager'),
                    (int) ($risk->fields['id'] ?? 0)
                ),
            ], [
                '##supplierrisk.supplier##', '##supplierrisk.category##', '##supplierrisk.risklevel##',
                '##supplierrisk.notices##',
            ]);
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
            'supplierrisk.action'     => _n('Event', 'Events', 1),
            'supplierrisk.title'      => __('Titre', 'grcmanager'),
            'supplierrisk.supplier'   => Supplier::getTypeName(1),
            'supplierrisk.category'   => __('Catégorie', 'grcmanager'),
            'supplierrisk.risklevel'  => __('Niveau de risque', 'grcmanager'),
            'supplierrisk.reviewdate' => __('Date de revue', 'grcmanager'),
            'supplierrisk.url'        => __('URL'),
            'supplierrisk.notices'    => __('Avis de sécurité', 'grcmanager'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
