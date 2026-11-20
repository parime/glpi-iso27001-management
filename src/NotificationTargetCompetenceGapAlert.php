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

use Notification;
use NotificationTarget;
use Override;
use User;

/**
 * Issue #774, lot 3 : écarts de compétence sans formation planifiée (CompetenceGapAlert::runAlerts()),
 * envoyés au responsable de la préparation à la certification et, au choix, au groupe RSSI.
 *
 * L'alerte cite des personnes de tout le SMSI : validateSendTo() n'accepte qu'un compte GLPI qui lit
 * le plugin et les utilisateurs sur toutes les entités, quelle que soit la cible.
 */
class NotificationTargetCompetenceGapAlert extends NotificationTarget
{
    // Hors de la plage des cibles du cœur : sinon GLPI n'appelle jamais addSpecificTargets().
    public const TARGET_CISO_GROUP = 1836;

    #[Override]
    public function getEvents()
    {
        return [
            CompetenceGapAlert::EVENT => __('Competence gaps without planned training', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_USER, __('Owner of the certification readiness', 'grcmanager'));
        $this->addTarget(self::TARGET_CISO_GROUP, __('CISO group (GRC Manager)', 'grcmanager'));
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        $groupsId = AuditTeam::getCisoGroupId();
        if ((int) ($data['items_id'] ?? 0) === self::TARGET_CISO_GROUP && $groupsId > 0) {
            $this->addForGroup(0, $groupsId);
        }
    }

    #[Override]
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        $rights = [CompetenceGapAlert::RIGHTNAME => READ, User::$rightname => READ];
        if (!NotificationSupport::canReceive((int) ($infos['users_id'] ?? 0), 0, true, $rights)) {
            return false;
        }

        return parent::validateSendTo($event, $infos, $notify_me, $emitter);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        $events = $this->getAllEvents();
        /** @var CompetenceGapAlert $alert */
        $alert = $this->obj;
        $gaps = $alert->gaps();
        $competenceIds = array_values(array_unique(array_column($gaps, 'competence_id')));
        $competences = Competence::labelsFor($competenceIds);
        $trainingIds = [];
        foreach ($gaps as $gap) {
            $trainingIds = array_merge($trainingIds, $gap['trainings']);
        }
        $titles = Competence::trainingTitles(array_values(array_unique($trainingIds)));
        $levels = IsmsFunction::levelLabels();

        $lines = [];
        foreach (array_slice($gaps, 0, CompetenceGapAlert::MAX_LISTED) as $gap) {
            $proposed = array_values(array_filter(array_map(
                static fn (int $id): string => $titles[$id] ?? '',
                $gap['trainings']
            )));
            $name = (string) getUserName($gap['person']);
            $competence = $competences[$gap['competence_id']] ?? '';
            $level = $levels[$gap['level']] ?? '';
            $lines[] = $proposed !== []
                ? sprintf(
                    __('- %1$s: %2$s, expected level %3$s (training to plan: %4$s)', 'grcmanager'),
                    $name,
                    $competence,
                    $level,
                    implode(', ', $proposed)
                )
                : sprintf(
                    __(
                        '- %1$s: %2$s, expected level %3$s (no training gives it yet: link one, or declare a proof)',
                        'grcmanager'
                    ),
                    $name,
                    $competence,
                    $level
                );
        }
        $more = count($gaps) - count($lines);
        if ($more > 0) {
            $lines[] = sprintf(_n('... and %d more gap.', '... and %d more gaps.', $more, 'grcmanager'), $more);
        }

        $this->data['##competencegaps.action##'] = $events[$event] ?? '';
        $this->data['##competencegaps.gaps##']   = implode("\n", $lines);
        $this->data['##competencegaps.count##']  = (string) count($gaps);
        $this->data['##competencegaps.total##']  = (string) (int) ($alert->fields['total'] ?? count($gaps));
        $this->data['##competencegaps.url##']    = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/grcmanager/front/ismsfunction.php';

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
            'competencegaps.action' => _n('Event', 'Events', 1),
            'competencegaps.gaps'   => __('New gaps without planned training', 'grcmanager'),
            'competencegaps.count'  => __('Number of new gaps', 'grcmanager'),
            'competencegaps.total'  => __('Number of gaps without planned training', 'grcmanager'),
            'competencegaps.url'    => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }
}
