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

use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use Html;
use NotificationTarget;
use Override;

/**
 * Issue #380 : rappel d'un test d'efficacité en retard ou proche de l'échéance
 * (ControlTestReminder::run()), au responsable du plan de test et, selon le réglage, au groupe
 * RSSI (AuditTeam::getCisoGroupId(), fourni aussi par Configuration GLPI Auto).
 *
 * Aucun destinataire ne reçoit le contenu s'il ne peut pas lire la mesure : validateSendTo()
 * exige le droit de lecture du plugin sur un profil valable dans l'entité de la notification,
 * quelle que soit la cible (y compris celles ajoutées à la main par un administrateur).
 */
class NotificationTargetControl extends NotificationTarget
{
    // Hors de la plage des cibles du cœur : sinon GLPI n'appelle jamais addSpecificTargets().
    public const TARGET_TESTER = 1180;
    public const TARGET_CISO_GROUP = 1832;

    #[Override]
    public function getEvents()
    {
        return [
            ControlTestReminder::EVENT => __('Effectiveness test overdue or due soon', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_TESTER, __('Test plan owner', 'grcmanager'));
        $this->addTarget(self::TARGET_CISO_GROUP, __('CISO group (GRC Manager)', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        switch ((int) ($data['items_id'] ?? 0)) {
            case self::TARGET_TESTER:
                $usersId = (int) ($this->obj->fields['users_id_tester'] ?? 0);
                if ($usersId > 0) {
                    $this->addToRecipientsList(['users_id' => $usersId] + $this->userInfo($usersId));
                }
                break;

            case self::TARGET_CISO_GROUP:
                $groupsId = AuditTeam::getCisoGroupId();
                $status = (string) ($options['due_status'] ?? '');
                if ($groupsId > 0 && ControlTestRules::notifiesCiso(ControlTestReminder::cisoMode(), $status)) {
                    $this->addForGroup(0, $groupsId);
                }
                break;
        }
    }

    /**
     * Revue de sécurité : le rappel n'est envoyé qu'à un utilisateur GLPI qui peut lire la mesure.
     */
    #[Override]
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        $usersId = (int) ($infos['users_id'] ?? 0);
        if (!ControlTestReminder::canReceive($usersId, $this->getEntity(), $this->isTargetItemRecursive())) {
            return false;
        }

        return parent::validateSendTo($event, $infos, $notify_me, $emitter);
    }

    /**
     * @return array{language: string, name: string}
     */
    private function userInfo(int $usersId): array
    {
        $user = new \User();
        if (!$user->getFromDB($usersId)) {
            return ['language' => '', 'name' => ''];
        }

        return [
            'language' => (string) ($user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? 'en_GB')),
            'name'     => formatUserName(
                0,
                $user->fields['name'],
                $user->fields['realname'],
                $user->fields['firstname']
            ),
        ];
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        /** @var Control $control */
        $control = $this->obj;
        $controlsId = (int) $control->getID();
        $latest = ControlTest::getForControl($controlsId)[0] ?? null;
        $next = ControlTest::nextDueDate($control->fields, $latest);
        $status = (string) ($options['due_status'] ?? ControlTestRules::dueStatus($next, date('Y-m-d')));
        $results = ControlTest::getResults();
        $testerId = (int) ($control->fields['users_id_tester'] ?? 0);
        $code = (string) ($control->fields['code'] ?? '');

        $this->data['##controltest.action##']     = $events[$event] ?? '';
        $this->data['##controltest.control##']    = $code . ' - ' . Control::getControlTitle($code);
        $this->data['##controltest.duestatus##']  = $status === ControlTestRules::DUE_OVERDUE
            ? _x('deadline passed', 'Overdue', 'grcmanager')
            : __('Due soon', 'grcmanager');
        $this->data['##controltest.nextdate##']   = (string) Html::convDate($next);
        $this->data['##controltest.lastdate##']   = $latest !== null
            ? (string) Html::convDate($latest['test_date'])
            : '';
        $this->data['##controltest.lastresult##'] = $latest !== null
            ? ($results[$latest['result']]['label'] ?? (string) $latest['result'])
            : '';
        $this->data['##controltest.tester##']     = $testerId > 0 ? getUserName($testerId) : '';
        $this->data['##controltest.status##']     = Control::getImplementationStatuses()[
            (string) ($control->fields['implementation_status'] ?? '')
        ] ?? '';
        $this->data['##controltest.withdrawn##']  = !empty($options['withdrawn'])
            ? __('The "Verified" status was withdrawn: the last effectiveness test has expired.', 'grcmanager')
            : '';
        $this->data['##controltest.brandcolor##'] = ControlTestReminder::brandColor();
        $this->data['##controltest.url##']        = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
            Control::class . '_' . $controlsId . '_' . ControlTest::class . '$1'
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
            'controltest.action'     => _n('Event', 'Events', 1),
            'controltest.control'    => __('Control', 'grcmanager'),
            'controltest.duestatus'  => __('Deadline', 'grcmanager'),
            'controltest.nextdate'   => __('Next test', 'grcmanager'),
            'controltest.lastdate'   => __('Last test', 'grcmanager'),
            'controltest.lastresult' => __('Result', 'grcmanager'),
            'controltest.tester'     => __('Test plan owner', 'grcmanager'),
            'controltest.status'     => __('Implementation status', 'grcmanager'),
            'controltest.withdrawn'  => __('Verified status withdrawn', 'grcmanager'),
            'controltest.brandcolor' => __('Notification colour', 'grcmanager'),
            'controltest.url'        => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
