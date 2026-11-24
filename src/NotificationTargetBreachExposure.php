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

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager;

use GlpiPlugin\Grcmanager\Services\Notification\IsmsRoleTargets;
use NotificationTarget;
use Override;

/**
 * Issue #183 : « nouvelles expositions à des fuites de données », envoyée par la tâche planifiée
 * breachmonitor. Contenu minimal (registre sensible) : le nombre et le lien vers le registre, jamais
 * d'adresse, d'utilisateur ni de nom de fuite. Destinataires : le groupe RSSI de GRC Manager (cible
 * propre, hors de la plage du cœur) et, par défaut, l'administrateur GLPI. Issue #1054 : aussi le
 * groupe DPO (par défaut) et, au choix de l'administrateur, les adresses génériques du RSSI et du
 * DPO (rôles du SMSI ; le contenu minimal permet d'écrire à une adresse qui n'est pas un compte).
 */
class NotificationTargetBreachExposure extends NotificationTarget
{
    use \GlpiPlugin\Grcmanager\Traits\CoreNotificationTargetFormTrait;

    use IsmsRoleTargets;

    public const TARGET_CISO_GROUP = 1830;
    // Issue #1054 : cibles des rôles du SMSI (communes, voir IsmsRoleTargets), déclarées ici aussi
    // pour le contrôle des cibles de la classe (SecurityReviewTest, contrôles dans un vrai GLPI).
    public const TARGET_CISO_EMAIL = IsmsRoles::TARGET_CISO_EMAIL;
    public const TARGET_DPO_GROUP = IsmsRoles::TARGET_DPO_GROUP;
    public const TARGET_DPO_EMAIL = IsmsRoles::TARGET_DPO_EMAIL;

    #[Override]
    public function getEvents()
    {
        return [
            BreachExposure::NEW_EXPOSURES_EVENT => __('New data breach exposures', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_CISO_GROUP, __('CISO group (GRC Manager)', 'grcmanager'));
        $this->addIsmsRoleTargets(true);
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        $groupsId = AuditTeam::getCisoGroupId();
        if ((int) ($data['items_id'] ?? 0) === self::TARGET_CISO_GROUP && $groupsId > 0) {
            $this->addForGroup(0, $groupsId);
        }
        $this->addIsmsRoleRecipients((array) $data);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $this->data['##breachexposure.action##'] = $events[$event] ?? '';
        $this->data['##breachexposure.count##'] = (string) (int) ($options['new_count'] ?? 0);
        $this->data['##breachexposure.url##'] = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            BreachExposure::class
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
            'breachexposure.action' => _n('Event', 'Events', 1),
            'breachexposure.count'  => __('New exposures', 'grcmanager'),
            'breachexposure.url'    => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
    }
}
