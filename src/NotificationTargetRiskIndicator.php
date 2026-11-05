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

use Dropdown;
use Entity;
use Html;
use NotificationTarget;
use Override;

/**
 * Issue #378, deuxième étape : alerte d'un KRI passé au rouge (ou à l'orange, selon le réglage) et
 * retour au vert (KriAlert::check()), au responsable du KRI et au groupe RSSI
 * (AuditTeam::getCisoGroupId(), fourni aussi par Configuration GLPI Auto).
 *
 * Aucun destinataire ne reçoit le contenu s'il ne peut pas lire le KRI et les données qu'il mesure :
 * validateSendTo() exige, sur un profil valable dans l'entité du KRI, le droit des registres
 * sensibles et, pour une source automatique, les droits de lecture des données mesurées dans
 * l'entité et toutes ses sous-entités (KriAlert::canReceive()), quelle que soit la cible.
 */
class NotificationTargetRiskIndicator extends NotificationTarget
{
    // Hors de la plage des cibles du cœur : sinon GLPI n'appelle jamais addSpecificTargets().
    public const TARGET_OWNER = 1378;
    public const TARGET_CISO_GROUP = 1832;

    #[Override]
    public function getEvents()
    {
        return [
            KriAlert::EVENT_ALERT   => __('Risk indicator (KRI) in alert', 'grcmanager'),
            KriAlert::EVENT_CLEARED => __('Risk indicator (KRI) back to green', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_OWNER, __('Risk indicator owner', 'grcmanager'));
        $this->addTarget(self::TARGET_CISO_GROUP, __('CISO group (GRC Manager)', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        switch ((int) ($data['items_id'] ?? 0)) {
            case self::TARGET_OWNER:
                $usersId = (int) ($this->obj->fields['users_id'] ?? 0);
                if ($usersId > 0) {
                    $this->addToRecipientsList(['users_id' => $usersId] + NotificationSupport::userInfo($usersId));
                }
                break;

            case self::TARGET_CISO_GROUP:
                $groupsId = AuditTeam::getCisoGroupId();
                if ($groupsId > 0) {
                    $this->addForGroup(0, $groupsId);
                }
                break;
        }
    }

    /**
     * Revue de sécurité : l'alerte n'est envoyée qu'à un utilisateur GLPI qui peut lire le KRI et
     * les données qu'il mesure.
     */
    #[Override]
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        if (
            !$this->obj instanceof RiskIndicator
            || !KriAlert::canReceive((int) ($infos['users_id'] ?? 0), $this->obj)
        ) {
            return false;
        }

        return parent::validateSendTo($event, $infos, $notify_me, $emitter);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var RiskIndicator $kri */
        $kri = $this->obj;
        $statuses = RiskIndicator::getStatuses();
        $unit = (string) ($kri->fields['unit'] ?? '');
        $number = static fn (mixed $value): string => $value === null || $value === ''
            ? ''
            : trim(Html::formatNumber((float) $value) . ' ' . $unit);
        $ownerId = (int) ($kri->fields['users_id'] ?? 0);
        $previous = (string) ($options['previous_status'] ?? '');

        $this->data['##kri.action##']      = $events[$event] ?? '';
        $this->data['##kri.name##']        = (string) ($kri->fields['name'] ?? '');
        $this->data['##kri.status##']      = $statuses[(string) ($kri->fields['status'] ?? '')] ?? '';
        $this->data['##kri.previousstatus##'] = $statuses[$previous] ?? '';
        $this->data['##kri.value##']       = $number($kri->fields['last_value'] ?? null);
        $this->data['##kri.date##']        = (string) Html::convDate($kri->fields['last_value_date'] ?? null);
        $this->data['##kri.warning##']     = $number($kri->fields['threshold_warning'] ?? null);
        $this->data['##kri.critical##']    = $number($kri->fields['threshold_critical'] ?? null);
        $this->data['##kri.direction##']   = RiskIndicator::getDirections()[(string) ($kri->fields['direction'] ?? '')]
            ?? '';
        $this->data['##kri.source##']      = RiskIndicator::getSources()[(string) ($kri->fields['source'] ?? '')] ?? '';
        $this->data['##kri.category##']    = Risk::getCategories()[(string) ($kri->fields['category'] ?? '')] ?? '';
        $this->data['##kri.entity##']      = (string) Dropdown::getDropdownName(
            Entity::getTable(),
            (int) ($kri->fields['entities_id'] ?? 0)
        );
        $this->data['##kri.owner##']       = $ownerId > 0 ? getUserName($ownerId) : '';
        $this->data['##kri.brandcolor##']  = NotificationSupport::brandColor();
        $this->data['##kri.url##']         = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            RiskIndicator::class . '_' . $kri->getID()
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
            'kri.action'         => _n('Event', 'Events', 1),
            'kri.name'           => __('Name'),
            'kri.status'         => __('Status', 'grcmanager'),
            'kri.previousstatus' => __('Previous status', 'grcmanager'),
            'kri.value'          => __('Last value', 'grcmanager'),
            'kri.date'           => __('Last measured on', 'grcmanager'),
            'kri.warning'        => __('Orange threshold', 'grcmanager'),
            'kri.critical'       => __('Red threshold', 'grcmanager'),
            'kri.direction'      => __('Direction', 'grcmanager'),
            'kri.source'         => __('Source', 'grcmanager'),
            'kri.category'       => __('Category', 'grcmanager'),
            'kri.entity'         => Entity::getTypeName(1),
            'kri.owner'          => __('Risk indicator owner', 'grcmanager'),
            'kri.brandcolor'     => __('Notification colour', 'grcmanager'),
            'kri.url'            => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
