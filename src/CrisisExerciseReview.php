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

use CommonDBTM;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Exercise\ExerciseReview as Rules;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use Session;

/**
 * Issue #732 : bilan après exercice de crise (A.5.29 / A.5.30). Objectifs définis avant ou au
 * lancement et évalués à la clôture, constats (écarts) avec leur gravité, plan d'amélioration
 * (une non-conformité ou une action de traitement de risque créée depuis un constat, toujours à la
 * demande de l'utilisateur) et validation du bilan par un responsable disposant du droit dédié.
 * Un bilan validé n'est plus modifiable sans repasser en brouillon, avec un motif tracé.
 *
 * Le bilan ne concerne que les exercices (incident fictif) : une cellule de crise réelle a son
 * rapport post-incident.
 */
final class CrisisExerciseReview
{
    /** Droit « Valider les bilans d'exercice », porté par le droit de la continuité d'activité. */
    public const VALIDATE = 1024;

    public const OBJECTIVES_TABLE = 'glpi_plugin_grcmanager_crisisexerciseobjectives';
    public const FINDINGS_TABLE = 'glpi_plugin_grcmanager_crisisexercisefindings';
    public const REVIEWS_TABLE = 'glpi_plugin_grcmanager_crisisexercisereviews';

    public const ACTION_NONCONFORMITY = 'nonconformity';
    public const ACTION_TREATMENT = 'treatment';

    private const EXERCISE_FIELD = 'plugin_grcmanager_crisisexercises_id';

    public static function canValidate(): bool
    {
        return Session::haveRight(Installer::CONTINUITY_RIGHT, self::VALIDATE);
    }

    public static function isValidated(CrisisExercise $exercise): bool
    {
        return !empty($exercise->fields['validated_at']);
    }

    /**
     * Bilan modifiable : exercice (pas une crise réelle) dont le bilan n'est pas validé.
     */
    public static function isEditable(CrisisExercise $exercise): bool
    {
        return $exercise->isDrill() && !self::isValidated($exercise);
    }

    private static function isEnded(CrisisExercise $exercise): bool
    {
        return !empty($exercise->fields['ended_at']);
    }

    /**
     * @return array<string, string>
     */
    public static function getOutcomes(): array
    {
        return [
            Rules::OUTCOME_MET     => __('Achieved', 'grcmanager'),
            Rules::OUTCOME_PARTIAL => __('Partially achieved', 'grcmanager'),
            Rules::OUTCOME_NOT_MET => __('Not achieved', 'grcmanager'),
        ];
    }

    /**
     * Types d'action d'amélioration que l'utilisateur peut créer.
     *
     * @return array<string, string>
     */
    public static function getActionTypes(): array
    {
        $types = [];
        if (Nonconformity::canCreate()) {
            $types[self::ACTION_NONCONFORMITY] = Nonconformity::getTypeName(1);
        }
        if (RiskTreatmentAction::canCreate()) {
            $types[self::ACTION_TREATMENT] = RiskTreatmentAction::getTypeName(1);
        }

        return $types;
    }

    /**
     * Objectifs ajoutés tant que l'exercice n'est pas terminé (ils se fixent avant le déroulé).
     *
     * @param list<string> $names
     * @return int nombre d'objectifs ajoutés
     */
    public static function addObjectives(CrisisExercise $exercise, array $names): int
    {
        global $DB;

        if (!self::isEditable($exercise) || self::isEnded($exercise)) {
            return 0;
        }
        $rank = countElementsInTable(self::OBJECTIVES_TABLE, [self::EXERCISE_FIELD => $exercise->getID()]);
        $added = 0;
        foreach (Rules::splitObjectives(implode("\n", $names)) as $name) {
            if ($rank >= Rules::MAX_OBJECTIVES) {
                break;
            }
            $DB->insert(self::OBJECTIVES_TABLE, [
                self::EXERCISE_FIELD => $exercise->getID(),
                'rank'               => $rank++,
                'name'               => $name,
                'users_id'           => (int) Session::getLoginUserID(),
                'date_creation'      => Session::getCurrentTime(),
                'date_mod'           => Session::getCurrentTime(),
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * Évaluation d'un objectif, une fois l'exercice terminé.
     */
    public static function evaluateObjective(
        CrisisExercise $exercise,
        int $objectiveId,
        string $outcome,
        string $comment
    ): bool {
        global $DB;

        $outcome = Rules::normalizeOutcome($outcome);
        if ($outcome === '' || !self::isEditable($exercise) || !self::isEnded($exercise)) {
            return false;
        }
        $criteria = ['id' => $objectiveId, self::EXERCISE_FIELD => $exercise->getID()];
        if (countElementsInTable(self::OBJECTIVES_TABLE, $criteria) === 0) {
            return false;
        }
        $DB->update(self::OBJECTIVES_TABLE, [
            'outcome'  => $outcome,
            'comment'  => trim($comment),
            'date_mod' => Session::getCurrentTime(),
        ], $criteria);

        return true;
    }

    /**
     * Un objectif se retire tant que l'exercice n'est pas terminé.
     */
    public static function deleteObjective(CrisisExercise $exercise, int $objectiveId): bool
    {
        global $DB;

        if (!self::isEditable($exercise) || self::isEnded($exercise)) {
            return false;
        }
        $criteria = ['id' => $objectiveId, self::EXERCISE_FIELD => $exercise->getID()];
        if (countElementsInTable(self::OBJECTIVES_TABLE, $criteria) === 0) {
            return false;
        }
        $DB->delete(self::OBJECTIVES_TABLE, $criteria);

        return true;
    }

    /**
     * Constat relevé pendant ou après l'exercice.
     *
     * @return int identifiant du constat, 0 si refusé
     */
    public static function addFinding(
        CrisisExercise $exercise,
        string $name,
        string $description,
        string $severity
    ): int {
        global $DB;

        $name = Rules::shorten($name);
        if ($name === '' || !self::isEditable($exercise)) {
            return 0;
        }
        $DB->insert(self::FINDINGS_TABLE, [
            self::EXERCISE_FIELD => $exercise->getID(),
            'name'               => $name,
            'description'        => trim($description),
            'severity'           => Rules::normalizeSeverity($severity),
            'users_id'           => (int) Session::getLoginUserID(),
            'date_creation'      => Session::getCurrentTime(),
            'date_mod'           => Session::getCurrentTime(),
        ]);

        return (int) $DB->insertId();
    }

    /**
     * Un constat sans action d'amélioration se retire tant que le bilan n'est pas validé.
     */
    public static function deleteFinding(CrisisExercise $exercise, int $findingId): bool
    {
        global $DB;

        $finding = self::finding($exercise, $findingId);
        if ($finding === null || !self::isEditable($exercise) || (string) $finding['itemtype'] !== '') {
            return false;
        }
        $DB->delete(self::FINDINGS_TABLE, ['id' => $findingId]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function finding(CrisisExercise $exercise, int $findingId): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::FINDINGS_TABLE,
            'WHERE' => ['id' => $findingId, self::EXERCISE_FIELD => $exercise->getID()],
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Plan d'amélioration : depuis un constat, crée à la demande de l'utilisateur une
     * non-conformité (rattachée à l'incident de l'exercice) ou une action de traitement d'un
     * risque, avec un responsable et une échéance. Les contrôles d'entité et de droits de ces
     * objets s'appliquent (un risque non modifiable est refusé).
     *
     * @return int identifiant de l'action créée, 0 si refusé
     */
    public static function createAction(
        CrisisExercise $exercise,
        int $findingId,
        string $type,
        int $usersId,
        string $dueDate,
        int $riskId = 0
    ): int {
        global $DB;

        $finding = self::finding($exercise, $findingId);
        if (
            $finding === null || !self::isEditable($exercise) || (string) $finding['itemtype'] !== ''
            || !isset(self::getActionTypes()[$type])
        ) {
            return 0;
        }
        $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) === 1 ? $dueDate : null;
        $origin = sprintf(
            __('Finding of the crisis exercise #%1$d (%2$s).', 'grcmanager'),
            $exercise->getID(),
            CrisisExercise::getScenarios()[$exercise->fields['scenario']]['label'] ?? $exercise->fields['scenario']
        );
        $text = trim((string) $finding['name'] . "\n\n" . (string) $finding['description'] . "\n\n" . $origin);
        if ($type === self::ACTION_NONCONFORMITY) {
            $item = new Nonconformity();
            $itemId = (int) $item->add([
                'title'                                  => (string) $finding['name'],
                'description'                            => $text,
                'severity'                               => Rules::normalizeSeverity((string) $finding['severity']),
                'plugin_grcmanager_securityincidents_id' => (int) $exercise->fields[
                    'plugin_grcmanager_securityincidents_id'
                ],
                'users_id'                               => max(0, $usersId),
                'due_date'                               => $dueDate,
            ]);
        } else {
            $item = new RiskTreatmentAction();
            $itemId = (int) $item->add([
                'plugin_grcmanager_risks_id' => $riskId,
                'description'                => $text,
                'users_id'                   => max(0, $usersId),
                'due_date'                   => $dueDate,
            ]);
        }
        if ($itemId <= 0) {
            return 0;
        }
        $DB->update(self::FINDINGS_TABLE, [
            'itemtype' => $item::class,
            'items_id' => $itemId,
            'date_mod' => Session::getCurrentTime(),
        ], ['id' => $findingId]);

        return $itemId;
    }

    /**
     * Risques auxquels l'utilisateur peut ajouter une action de traitement (entités actives).
     *
     * @return array<int, string>
     */
    public static function riskChoices(): array
    {
        global $DB;

        if (!RiskTreatmentAction::canCreate() || !Risk::canUpdate()) {
            return [];
        }
        $choices = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => Risk::getTable(),
                'WHERE'  => getEntitiesRestrictCriteria(Risk::getTable(), '', '', true),
                'ORDER'  => 'title',
                'LIMIT'  => 500,
            ]) as $row
        ) {
            $choices[(int) $row['id']] = (string) $row['title'];
        }

        return $choices;
    }

    /**
     * Validation du bilan : date et responsable retenus, événement tracé.
     *
     * @return string motif du refus (ExerciseReview::REFUSED_*), '' si validé
     */
    public static function validate(CrisisExercise $exercise, string $comment): string
    {
        global $DB;

        if (!$exercise->isDrill()) {
            return Rules::REFUSED_NOT_ENDED;
        }
        $refusal = Rules::validationRefusal(
            self::isEnded($exercise),
            self::isValidated($exercise),
            array_column(self::objectives($exercise), 'outcome')
        );
        if ($refusal !== '') {
            return $refusal;
        }
        $now = Session::getCurrentTime();
        $DB->update(CrisisExercise::getTable(), [
            'validated_at'       => $now,
            'users_id_validator' => (int) Session::getLoginUserID(),
        ], ['id' => $exercise->getID()]);
        self::trace($exercise, Rules::EVENT_VALIDATED, $comment, $now);
        $exercise->getFromDB($exercise->getID());

        return '';
    }

    /**
     * Retour en brouillon d'un bilan validé : le motif est obligatoire et tracé.
     */
    public static function reopen(CrisisExercise $exercise, string $reason): bool
    {
        global $DB;

        $reason = trim($reason);
        if ($reason === '' || !self::isValidated($exercise)) {
            return false;
        }
        $DB->update(CrisisExercise::getTable(), [
            'validated_at'       => null,
            'users_id_validator' => 0,
        ], ['id' => $exercise->getID()]);
        self::trace($exercise, Rules::EVENT_REOPENED, $reason, Session::getCurrentTime());
        $exercise->getFromDB($exercise->getID());

        return true;
    }

    private static function trace(CrisisExercise $exercise, string $action, string $comment, string $date): void
    {
        global $DB;

        $DB->insert(self::REVIEWS_TABLE, [
            self::EXERCISE_FIELD => $exercise->getID(),
            'action'             => $action,
            'users_id'           => (int) Session::getLoginUserID(),
            'date'               => $date,
            'comment'            => trim($comment),
        ]);
    }

    public static function refusalMessage(string $refusal): string
    {
        return match ($refusal) {
            Rules::REFUSED_VALIDATED    => __('The review is already validated.', 'grcmanager'),
            Rules::REFUSED_NO_OBJECTIVE => __(
                'Define at least one objective before validating the review.',
                'grcmanager'
            ),
            Rules::REFUSED_UNEVALUATED  => __(
                'Evaluate every objective (achieved, partially achieved or not achieved) before validating the review.',
                'grcmanager'
            ),
            default                     => __('Finish the exercise before validating its review.', 'grcmanager'),
        };
    }

    /**
     * @return list<array{id: int, name: string, outcome: string, comment: string}>
     */
    private static function objectives(CrisisExercise $exercise): array
    {
        global $DB;

        $objectives = [];
        foreach (
            $DB->request([
                'FROM'  => self::OBJECTIVES_TABLE,
                'WHERE' => [self::EXERCISE_FIELD => $exercise->getID()],
                'ORDER' => ['rank', 'id'],
            ]) as $row
        ) {
            $objectives[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'outcome' => Rules::normalizeOutcome((string) $row['outcome']),
                'comment' => (string) ($row['comment'] ?? ''),
            ];
        }

        return $objectives;
    }

    /**
     * Action d'amélioration d'un constat : avancement lu en direct sur la non-conformité ou
     * l'action de traitement. Une action que l'utilisateur ne peut pas lire n'est pas détaillée.
     *
     * @return array<string, mixed>|null
     */
    private static function action(string $itemtype, int $itemsId, string $today): ?array
    {
        if ($itemtype === '' || $itemsId <= 0) {
            return null;
        }
        $isNonconformity = $itemtype === Nonconformity::class;
        $item = $isNonconformity ? new Nonconformity() : new RiskTreatmentAction();
        $base = [
            'type'  => $isNonconformity ? self::ACTION_NONCONFORMITY : self::ACTION_TREATMENT,
            'label' => $item::getTypeName(1),
            'id'    => $itemsId,
        ];
        $known = in_array($itemtype, [Nonconformity::class, RiskTreatmentAction::class], true);
        if (!$known || !$item->getFromDB($itemsId)) {
            return $base + ['state' => Rules::ACTION_MISSING];
        }
        if (!self::canRead($item)) {
            return $base + ['state' => Rules::ACTION_HIDDEN];
        }
        $status = (string) $item->fields['status'];
        $closed = $isNonconformity
            ? in_array($status, Nonconformity::CLOSED_STATUSES, true)
            : $status === TreatmentPlanRules::STATUS_DONE;
        $dueDate = $item->fields['due_date'] ?? null;
        $usersId = (int) $item->fields['users_id'];

        return $base + [
            'state'       => Rules::actionState($closed, is_string($dueDate) ? $dueDate : null, $today),
            'status'      => ($isNonconformity ? Nonconformity::getStatuses() : RiskTreatmentAction::getStatuses())[
                $status
            ] ?? $status,
            'title'       => $isNonconformity
                ? (string) $item->fields['title']
                : mb_substr(strtok((string) $item->fields['description'], "\n") ?: '', 0, 255),
            'url'         => $isNonconformity
                ? Nonconformity::getFormURLWithID($itemsId)
                : Risk::getFormURLWithID((int) $item->fields['plugin_grcmanager_risks_id']),
            'responsible' => $usersId > 0 ? getUserName($usersId) : '',
            'due_date'    => is_string($dueDate) ? $dueDate : '',
        ];
    }

    private static function canRead(CommonDBTM $item): bool
    {
        return !Session::getLoginUserID() || $item->can($item->getID(), READ);
    }

    /**
     * Données du bilan pour la salle de crise et le rapport PDF.
     *
     * @return array<string, mixed>
     */
    public static function collect(CrisisExercise $exercise): array
    {
        global $DB;

        $today = date('Y-m-d', (int) strtotime(Session::getCurrentTime()));
        $objectives = self::objectives($exercise);
        $findings = [];
        foreach (
            $DB->request([
                'FROM'  => self::FINDINGS_TABLE,
                'WHERE' => [self::EXERCISE_FIELD => $exercise->getID()],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $findings[] = [
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'severity'    => Rules::normalizeSeverity((string) $row['severity']),
                'author'      => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'action'      => self::action((string) $row['itemtype'], (int) $row['items_id'], $today),
            ];
        }
        $history = [];
        foreach (
            $DB->request([
                'FROM'  => self::REVIEWS_TABLE,
                'WHERE' => [self::EXERCISE_FIELD => $exercise->getID()],
                'ORDER' => ['date', 'id'],
            ]) as $row
        ) {
            $history[] = [
                'action'  => (string) $row['action'],
                'date'    => (string) $row['date'],
                'user'    => getUserName((int) $row['users_id']),
                'comment' => (string) ($row['comment'] ?? ''),
            ];
        }
        $validated = self::isValidated($exercise);
        $refusal = Rules::validationRefusal(
            self::isEnded($exercise),
            $validated,
            array_column($objectives, 'outcome')
        );

        return [
            'validated'          => $validated,
            'validated_at'       => (string) ($exercise->fields['validated_at'] ?? ''),
            'validator'          => $validated ? getUserName((int) $exercise->fields['users_id_validator']) : '',
            'objectives'         => $objectives,
            'objectives_summary' => Rules::objectivesSummary(array_column($objectives, 'outcome')),
            'findings'           => $findings,
            'plan_summary'       => Rules::planSummary(array_map(
                static fn (array $f): string => $f['action'] === null ? '' : (string) $f['action']['state'],
                $findings
            )),
            'history'            => $history,
            'refusal'            => $refusal,
            'refusal_message'    => $refusal === '' ? '' : self::refusalMessage($refusal),
        ];
    }

    /**
     * Retrait des lignes du bilan d'un exercice supprimé.
     */
    public static function purgeFor(int $exerciseId): void
    {
        global $DB;

        foreach ([self::OBJECTIVES_TABLE, self::FINDINGS_TABLE, self::REVIEWS_TABLE] as $table) {
            $DB->delete($table, [self::EXERCISE_FIELD => $exerciseId]);
        }
    }
}
