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

use Html;
use Notification;
use NotificationTarget;
use Override;

/**
 * Issue #761 : étapes de la préparation à la certification à risque ou en retard
 * (ReadinessAlert::runAlerts()), envoyées au responsable de la préparation.
 */
class NotificationTargetReadinessAlert extends NotificationTarget
{
    #[Override]
    public function getEvents()
    {
        return [
            ReadinessAlert::EVENT => __('Certification step at risk', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Owner of the certification readiness', 'grcmanager'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        $events = $this->getAllEvents();
        /** @var ReadinessAlert $alert */
        $alert = $this->obj;
        $texts = CertificationReadiness::getStepTexts();
        $styles = CertificationReadiness::getDeadlineStyles();

        $lines = [];
        foreach ($alert->steps() as $step) {
            $key = (string) ($step['step'] ?? '');
            if (!isset($texts[$key])) {
                continue;
            }
            $lines[] = sprintf(
                __('- %1$s: advised deadline %2$s (%3$s)', 'grcmanager'),
                $texts[$key]['title'],
                Html::convDate((string) ($step['deadline'] ?? '')),
                $styles[(string) ($step['state'] ?? '')]['label'] ?? ''
            );
        }

        $this->data['##readinessalert.action##'] = $events[$event] ?? '';
        $this->data['##readinessalert.target##'] = (string) Html::convDate($alert->fields['target_date'] ?? null);
        $this->data['##readinessalert.steps##']  = implode("\n", $lines);
        $this->data['##readinessalert.count##']  = (string) count($lines);
        $this->data['##readinessalert.url##']    = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/certificationreadiness.php';

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
            'readinessalert.action' => _n('Event', 'Events', 1),
            'readinessalert.target' => __('Target date of the certification audit', 'grcmanager'),
            'readinessalert.steps'  => __('Steps at risk', 'grcmanager'),
            'readinessalert.count'  => __('Number of steps', 'grcmanager'),
            'readinessalert.url'    => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
