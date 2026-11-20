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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Continuity\CrisisScenarioRules as Rules;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #776, lot 2 : onglet « Préparation » d'un scénario de crise sécurité (A.5.29). Il rassemble,
 * sans rien dupliquer, ce qui prouve que le scénario est prêt :
 * - les exercices de crise du scénario et le bilan du dernier (objectifs, constats, plan
 *   d'amélioration, validation : #732), avec les communications, prises de contact et tests de
 *   reprise tracés pendant l'exercice ;
 * - le dernier test de reprise de chaque processus impacté (#733) ;
 * - les modèles de messages de crise de la situation et leur liste de diffusion (#616, #800) ;
 * - les contacts officiels à prévenir (#771).
 *
 * Chaque objet garde ses propres droits : un exercice ne se voit qu'avec la lecture des incidents,
 * un modèle avec son droit, un contact avec celui du contexte de l'organisme. Rien n'est modifié
 * depuis cet onglet, sauf le lancement d'un exercice (formulaire de la page des exercices).
 */
class CrisisScenarioReadiness extends CommonGLPI
{
    /** Exercices listés sur l'onglet, les plus récents d'abord. */
    private const DRILL_LIMIT = 10;

    public static function getTypeName($nb = 0)
    {
        return __('Preparedness', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CrisisScenario || $item->isNewItem() || !CrisisScenario::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés.
        if (
            !$item instanceof CrisisScenario
            || $item->isNewItem()
            || !CrisisScenario::canView()
            || !$item->canViewItem()
        ) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/tabs/crisis_scenario_readiness.html.twig',
            self::collect($item)
        );

        return true;
    }

    /**
     * Données de l'onglet pour un scénario lisible.
     *
     * @return array<string, mixed>
     */
    public static function collect(CrisisScenario $scenario): array
    {
        $id = (int) $scenario->getID();
        $presented = null;
        foreach (CrisisScenario::register() as $row) {
            if ($row['id'] === $id) {
                $presented = $row;
            }
        }
        $exerciseKey = (string) $scenario->fields['exercise_scenario'];
        $situation = (string) $scenario->fields['communication_situation'];
        $entity = (int) $scenario->fields['entities_id'];
        $canIncidents = PluginGrcmanagerSecurityIncident::canView();
        $drills = $canIncidents && $exerciseKey !== '' ? self::drills($exerciseKey) : [];
        $lastReview = self::lastReview($drills);

        return [
            'scenario'          => $presented,
            'exercise_key'      => $exerciseKey,
            'exercise_label'    => CrisisExercise::getScenarios()[$exerciseKey]['label'] ?? '',
            'can_incidents'     => $canIncidents,
            'can_launch'        => $exerciseKey !== '' && CrisisExercise::canCreate(),
            'exercises_url'     => $canIncidents ? CrisisExercise::getSearchURL() : '',
            // Les objets restent côté PHP : le gabarit ne reçoit que des valeurs.
            'drills'            => array_map(
                static fn (array $drill): array => array_diff_key($drill, ['exercise' => true, 'incident' => true]),
                $drills
            ),
            'last_review'       => $lastReview,
            'outside_date'      => (string) ($scenario->fields['last_exercise_date'] ?? ''),
            'processes'         => $presented['processes'] ?? [],
            'can_processes'     => BusinessProcess::canView(),
            'programme_url'     => BusinessProcess::canView() ? BusinessProcessRecovery::getProgrammeURL() : '',
            'can_templates'     => CrisisMessageTemplate::canView(),
            'situation'         => CrisisMessageTemplate::getSituations()[$situation] ?? '',
            'templates'         => self::templates($situation, $entity),
            'templates_url'     => CrisisMessageTemplate::canView() ? CrisisMessageTemplate::getSearchURL() : '',
            'can_contacts'      => OfficialContact::canView(),
            'contacts'          => self::contacts($situation, $entity),
            'contacts_url'      => OfficialContact::canView() ? OfficialContact::getRegisterURL() : '',
            'with_breach'       => in_array('on_data_breach', Rules::contactFlags($situation), true),
        ];
    }

    /**
     * Exercices terminés du scénario d'exercice, lisibles (incident fictif visible), les plus récents
     * d'abord, avec l'état de leur bilan.
     *
     * @return list<array<string, mixed>>
     */
    public static function drills(string $exerciseKey): array
    {
        global $DB;

        $exercises = CrisisExercise::getTable();
        $drills = [];
        foreach (
            $DB->request([
                'FROM'  => $exercises,
                'WHERE' => array_merge([
                    'scenario' => $exerciseKey,
                    ['NOT' => ['ended_at' => null]],
                // Les exercices ne sont pas récursifs (pas de colonne is_recursive).
                ], EntityPerimeter::criteria($exercises, false)),
                'ORDER' => ['ended_at DESC', 'id DESC'],
                'LIMIT' => self::DRILL_LIMIT,
            ]) as $row
        ) {
            $exercise = new CrisisExercise();
            $exercise->getFromResultSet($row);
            $incident = $exercise->getIncident();
            if ($incident === null || empty($incident->fields['is_exercise']) || !$incident->canViewItem()) {
                continue;
            }
            $drills[] = [
                'exercise'  => $exercise,
                'incident'  => $incident,
                'id'        => (int) $row['id'],
                'url'       => CrisisExercise::getFormURLWithID((int) $row['id']),
                'ended_at'  => (string) $row['ended_at'],
                'validated' => CrisisExerciseReview::isValidated($exercise),
                // Issue #733 : type d'exercice (sur table, fonctionnel, test technique...).
                'type'      => BusinessProcessRecovery::typeLabel((string) ($row['exercise_type'] ?? '')),
            ];
        }

        return $drills;
    }

    /**
     * Bilan du dernier exercice : objectifs, constats, plan d'amélioration et validation, avec ce qui
     * a été tracé pendant l'exercice (communications, prises de contact officielles, tests de reprise).
     *
     * @param list<array<string, mixed>> $drills
     * @return array<string, mixed>|null
     */
    private static function lastReview(array $drills): ?array
    {
        $last = $drills[0] ?? null;
        if ($last === null) {
            return null;
        }
        /** @var CrisisExercise $exercise */
        $exercise = $last['exercise'];
        /** @var PluginGrcmanagerSecurityIncident $incident */
        $incident = $last['incident'];
        $review = CrisisExerciseReview::collect($exercise);

        return [
            'url'                => $last['url'],
            'type'               => $last['type'],
            'ended_at'           => $last['ended_at'],
            'validated'          => $review['validated'],
            'validated_at'       => $review['validated_at'],
            'validator'          => $review['validator'],
            'objectives_summary' => $review['objectives_summary'],
            'plan_summary'       => $review['plan_summary'],
            'communications'     => CrisisCommunication::canView()
                ? count(CrisisCommunication::historyFor($incident))
                : null,
            'contact_traces'     => OfficialContact::canView()
                ? count(OfficialContactNotification::rowsFor($incident))
                : null,
            'recovery_tests'     => BusinessProcess::canView()
                ? count(BusinessProcessRecovery::forExercise($exercise)['rows'])
                : null,
        ];
    }

    /**
     * Modèles de messages actifs de la situation, avec le nombre de destinataires que la personne peut
     * voir depuis l'entité du scénario.
     *
     * @return list<array{name: string, url: string, recipients: int, hidden: int}>
     */
    public static function templates(string $situation, int $entity): array
    {
        if ($situation === '' || !CrisisMessageTemplate::canView()) {
            return [];
        }
        $templates = [];
        $rows = (new CrisisMessageTemplate())->find(['is_active' => 1, 'situation' => $situation], ['name ASC']);
        foreach ($rows as $row) {
            $list = CrisisMessageRecipient::listFor((int) $row['id'], $entity);
            $templates[] = [
                'name'       => (string) $row['name'],
                'url'        => CrisisMessageTemplate::getFormURLWithID((int) $row['id']),
                'recipients' => count($list['recipients']),
                'hidden'     => $list['hidden'],
            ];
        }

        return $templates;
    }

    /**
     * Contacts officiels à prévenir pour le scénario, visibles depuis son entité (Rules::contactFlags).
     *
     * @return list<array<string, mixed>>
     */
    public static function contacts(string $situation, int $entity): array
    {
        if (!OfficialContact::canView()) {
            return [];
        }
        $flags = [];
        foreach (Rules::contactFlags($situation) as $field) {
            $flags[] = [$field => 1];
        }
        $rows = OfficialContact::visibleContacts([
            ['OR' => $flags],
            getEntitiesRestrictCriteria(ContextItem::getTable(), '', $entity, true),
        ]);
        $today = date('Y-m-d');

        return array_map(static fn (array $row): array => OfficialContact::present($row, $today), $rows);
    }
}
