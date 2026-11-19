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

use GlpiPlugin\Grcmanager\Compat\Base\ManagementReviewActionBase;
use GlpiPlugin\Grcmanager\Services\Review\ManagementReviewDossierRules as Rules;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Session;

/**
 * Issue #542 : action décidée en revue de direction (9.3 : « décisions et actions »), avec un
 * responsable, une échéance et un statut, suivie jusqu'à sa réalisation. Le dossier préparatoire de
 * la revue suivante reprend l'état de ces actions (9.3.2 a).
 *
 * Même modèle que RiskTreatmentAction (table enfant, ajoutée et mise à jour depuis la fiche de son
 * parent, sans menu ni écran de recherche propres) et mêmes statuts (TreatmentPlanRules). Les
 * actions se créent à la main ou à partir des lignes du champ « Décisions et actions » de la revue,
 * toujours sur demande explicite : rien n'est créé, réaffecté ni clôturé automatiquement.
 */
class ManagementReviewAction extends ManagementReviewActionBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_managementreviewactions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Management review action', 'Management review actions', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            TreatmentPlanRules::STATUS_PLANNED     => __('Planned', 'grcmanager'),
            TreatmentPlanRules::STATUS_IN_PROGRESS => __('In progress', 'grcmanager'),
            TreatmentPlanRules::STATUS_DONE        => __('Done', 'grcmanager'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->validateAndNormalize($input, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateAndNormalize($input, false);
    }

    /**
     * Revue existante, description non vide, statut connu, échéance vide ramenée à NULL, date de
     * réalisation posée quand l'action passe à « Fait » (comme RiskTreatmentAction).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateAndNormalize(array $input, bool $isNew)
    {
        $reviewId = (int) ($input['plugin_grcmanager_managementreviews_id']
            ?? ($this->fields['plugin_grcmanager_managementreviews_id'] ?? 0));
        if ($reviewId <= 0 || !(new ManagementReview())->getFromDB($reviewId)) {
            Session::addMessageAfterRedirect(__('Management review not found.', 'grcmanager'), false, ERROR);

            return false;
        }
        // Une action reste attachée à sa revue.
        $input['plugin_grcmanager_managementreviews_id'] = $reviewId;

        if ($isNew || array_key_exists('description', $input)) {
            $description = trim((string) ($input['description'] ?? ''));
            if ($description === '') {
                Session::addMessageAfterRedirect(__('Describe the action.', 'grcmanager'), false, ERROR);

                return false;
            }
            $input['description'] = mb_substr($description, 0, Rules::MAX_ACTION_LENGTH);
        }
        if (array_key_exists('users_id', $input)) {
            $input['users_id'] = max(0, (int) $input['users_id']);
        }

        $input['status'] = TreatmentPlanRules::normalizeStatus(
            $input['status'] ?? ($this->fields['status'] ?? null)
        );
        if (array_key_exists('due_date', $input)) {
            $input['due_date'] = Rules::validDate(is_string($input['due_date']) ? $input['due_date'] : null);
        }
        $completionDate = $input['completion_date'] ?? ($this->fields['completion_date'] ?? null);
        if ($input['status'] === TreatmentPlanRules::STATUS_DONE && empty($completionDate)) {
            $input['completion_date'] = date('Y-m-d');
        }
        if ($input['status'] !== TreatmentPlanRules::STATUS_DONE) {
            $input['completion_date'] = null;
        }

        return $input;
    }

    /**
     * @return array{class: string, icon: string, label: string}
     */
    public static function statusBadgeData(?string $value): array
    {
        $map = [
            TreatmentPlanRules::STATUS_PLANNED     => ['bg-secondary-subtle text-secondary-emphasis', 'ti-calendar-event'],
            TreatmentPlanRules::STATUS_IN_PROGRESS => ['bg-info-subtle text-info-emphasis', 'ti-progress'],
            TreatmentPlanRules::STATUS_DONE        => ['bg-success-subtle text-success-emphasis', 'ti-check'],
        ];
        [$class, $icon] = $map[$value] ?? ['bg-secondary-subtle text-secondary-emphasis', 'ti-help'];

        return ['class' => $class, 'icon' => $icon, 'label' => self::getStatuses()[$value] ?? (string) $value];
    }

    public static function statusBadge(?string $value): string
    {
        $badge = self::statusBadgeData($value);

        return Badge::html($badge['class'], $badge['icon'], $badge['label']);
    }

    /**
     * Actions d'une revue, échéance la plus proche d'abord, prêtes pour l'affichage.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForReview(int $reviewId): array
    {
        return self::decorate(self::rows(['plugin_grcmanager_managementreviews_id' => $reviewId]));
    }

    /**
     * Actions décidées lors des revues antérieures à celle-ci (date de revue plus ancienne, ou
     * revue sans date créée avant), pour le point 9.3.2 a du dossier.
     *
     * @return list<array<string, mixed>>
     */
    public static function fromPreviousReviews(ManagementReview $review): array
    {
        global $DB;

        $reviewIds = [];
        $reference = Rules::validDate((string) ($review->fields['review_date'] ?? '')) ?? date('Y-m-d');
        foreach (
            $DB->request([
                'SELECT' => ['id', 'review_date'],
                'FROM'   => ManagementReview::getTable(),
                'WHERE'  => ['NOT' => ['id' => (int) $review->getID()]],
            ]) as $row
        ) {
            $date = Rules::validDate((string) ($row['review_date'] ?? ''));
            if ($date !== null ? $date < $reference : (int) $row['id'] < (int) $review->getID()) {
                $reviewIds[] = (int) $row['id'];
            }
        }
        if ($reviewIds === []) {
            return [];
        }

        return self::decorate(self::rows(['plugin_grcmanager_managementreviews_id' => $reviewIds]));
    }

    /**
     * Crée une action « Planifiée », sans responsable ni échéance, pour chaque ligne des décisions
     * de la revue qui n'en a pas encore. Renvoie le nombre d'actions créées.
     */
    public static function createFromDecisions(ManagementReview $review): int
    {
        $lines = Rules::newDecisionLines(
            Rules::decisionLines((string) ($review->fields['decisions'] ?? '')),
            array_map(
                static fn (array $row): string => (string) $row['description'],
                self::rows(['plugin_grcmanager_managementreviews_id' => (int) $review->getID()])
            )
        );
        $created = 0;
        foreach ($lines as $line) {
            $id = (new self())->add([
                'plugin_grcmanager_managementreviews_id' => (int) $review->getID(),
                'description'                            => $line,
                'status'                                 => TreatmentPlanRules::STATUS_PLANNED,
            ]);
            if ($id) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function rows(array $where): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => $where,
                'ORDER' => ['plugin_grcmanager_managementreviews_id ASC', 'due_date ASC', 'id ASC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function decorate(array $rows): array
    {
        $today = date('Y-m-d');
        $titles = [];
        $actions = [];
        foreach ($rows as $row) {
            $reviewId = (int) $row['plugin_grcmanager_managementreviews_id'];
            if (!isset($titles[$reviewId])) {
                $review = new ManagementReview();
                $titles[$reviewId] = $review->getFromDB($reviewId) ? (string) $review->fields['title'] : '';
            }
            $status = (string) $row['status'];
            $actions[] = [
                'id'              => (int) $row['id'],
                'review_id'       => $reviewId,
                'review_title'    => $titles[$reviewId],
                'description'     => (string) $row['description'],
                'users_id'        => (int) $row['users_id'],
                'user'            => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'due_date'        => $row['due_date'] === null ? null : (string) $row['due_date'],
                'status'          => $status,
                'badge'           => self::statusBadgeData($status),
                'completion_date' => $row['completion_date'] === null ? null : (string) $row['completion_date'],
                'overdue'         => Rules::isOverdue($row['due_date'] ?? null, $status, $today),
            ];
        }

        return $actions;
    }

    /**
     * Purge d'une revue : ses actions partent avec elle.
     */
    public static function purgeForReview(int $reviewId): void
    {
        global $DB;

        $DB->delete(self::getTable(), ['plugin_grcmanager_managementreviews_id' => $reviewId]);
    }
}
