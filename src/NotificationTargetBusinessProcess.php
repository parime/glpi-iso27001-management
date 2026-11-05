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
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use Html;
use NotificationTarget;
use Override;

/**
 * Issue #377, quatrième étape : rappel d'une BIA à revoir et d'un test de reprise à refaire
 * (BusinessProcessReminder::run()), au responsable du processus et au groupe RSSI
 * (AuditTeam::getCisoGroupId(), fourni aussi par Configuration GLPI Auto).
 *
 * Aucun destinataire ne reçoit le contenu s'il ne peut pas lire le processus : validateSendTo()
 * exige le droit de la continuité sur un profil valable dans l'entité du processus, quelle que soit
 * la cible (y compris celles ajoutées à la main par un administrateur).
 */
class NotificationTargetBusinessProcess extends NotificationTarget
{
    // Hors de la plage des cibles du cœur : sinon GLPI n'appelle jamais addSpecificTargets().
    public const TARGET_OWNER = 1377;
    public const TARGET_CISO_GROUP = 1832;

    #[Override]
    public function getEvents()
    {
        return [
            BusinessProcessReminder::EVENT_BIA      => __('Business impact analysis to review', 'grcmanager'),
            BusinessProcessReminder::EVENT_RECOVERY => __('Recovery test due', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_OWNER, __('Business process owner', 'grcmanager'));
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
     * Revue de sécurité : le rappel n'est envoyé qu'à un utilisateur GLPI qui peut lire le processus.
     */
    #[Override]
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        if (
            !$this->obj instanceof BusinessProcess
            || !BusinessProcessReminder::canReceive((int) ($infos['users_id'] ?? 0), $this->obj)
        ) {
            return false;
        }

        return parent::validateSendTo($event, $infos, $notify_me, $emitter);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var BusinessProcess $process */
        $process = $this->obj;
        $fields = $process->fields;
        $processId = (int) $process->getID();
        $durations = BusinessProcess::getDurationOptions();
        $last = BusinessProcessReminder::lastTests()[$processId] ?? null;
        $isBia = $event === BusinessProcessReminder::EVENT_BIA;
        $due = (string) ($options['due_date'] ?? ($isBia
            ? BusinessProcessReminder::biaDueDate($fields)
            : BusinessProcessReminder::recoveryDueDate($last['test_date'] ?? null)));
        $status = (string) ($options['due_status'] ?? ControlTestRules::dueStatus($due, date('Y-m-d')));
        $ownerId = (int) ($fields['users_id'] ?? 0);
        $criticity = (int) ($fields['businesscriticities_id'] ?? 0);
        $tab = $isBia ? BusinessImpact::class : BusinessProcessRecovery::class;

        $this->data['##businessprocess.action##']     = $events[$event] ?? '';
        $this->data['##businessprocess.name##']       = (string) ($fields['name'] ?? '');
        $this->data['##businessprocess.entity##']     = (string) Dropdown::getDropdownName(
            Entity::getTable(),
            (int) ($fields['entities_id'] ?? 0)
        );
        $this->data['##businessprocess.owner##']      = $ownerId > 0 ? getUserName($ownerId) : '';
        $this->data['##businessprocess.criticity##']  = $criticity > 0
            ? (string) Dropdown::getDropdownName('glpi_businesscriticities', $criticity)
            : '';
        $this->data['##businessprocess.duestatus##']  = $status === ControlTestRules::DUE_OVERDUE
            ? _x('deadline passed', 'Overdue', 'grcmanager')
            : __('Due soon', 'grcmanager');
        $this->data['##businessprocess.duedate##']    = (string) Html::convDate($due);
        $this->data['##businessprocess.biadate##']    = (string) Html::convDate($fields['bia_date'] ?? null);
        $this->data['##businessprocess.lasttest##']   = $last !== null ? (string) Html::convDate($last['test_date']) : '';
        $this->data['##businessprocess.measured##']   = $last !== null
            ? BusinessProcessRecovery::formatMinutes($last['measured_minutes'])
            : '';
        $this->data['##businessprocess.rto##']        = $durations[(int) ($fields['rto_hours'] ?? 0)] ?? '';
        $this->data['##businessprocess.brandcolor##'] = NotificationSupport::brandColor();
        $this->data['##businessprocess.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            BusinessProcess::class . '_' . $processId . '_' . $tab . '$1'
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
            'businessprocess.action'     => _n('Event', 'Events', 1),
            'businessprocess.name'       => BusinessProcess::getTypeName(1),
            'businessprocess.entity'     => Entity::getTypeName(1),
            'businessprocess.owner'      => __('Business process owner', 'grcmanager'),
            'businessprocess.criticity'  => __('Business criticality', 'grcmanager'),
            'businessprocess.duestatus'  => __('Deadline', 'grcmanager'),
            'businessprocess.duedate'    => __('Due date', 'grcmanager'),
            'businessprocess.biadate'    => __('Last business impact analysis', 'grcmanager'),
            'businessprocess.lasttest'   => __('Last recovery test', 'grcmanager'),
            'businessprocess.measured'   => __('Recovery time measured at the last test', 'grcmanager'),
            'businessprocess.rto'        => 'RTO',
            'businessprocess.brandcolor' => __('Notification colour', 'grcmanager'),
            'businessprocess.url'        => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
