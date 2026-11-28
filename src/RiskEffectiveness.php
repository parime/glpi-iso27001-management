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

use GlpiPlugin\Grcmanager\Services\Risk\TreatmentEffectivenessRules as Rules;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use Session;

/**
 * Issue #1182 : efficacité du traitement des risques et acceptations à renouveler (lecture seule).
 *
 * Calcule, pour un risque ou pour le registre des entités de la session, l'état décrit par
 * TreatmentEffectivenessRules (risque cible atteint ou non une fois le plan terminé, acceptation
 * valable, à renouveler ou expirée), et fournit les deux cartes du tableau de bord. Les cartes ne
 * renvoient rien sans la lecture du registre des risques (droit des registres sensibles).
 */
final class RiskEffectiveness
{
    /**
     * Efficacité du traitement d'un risque, d'après sa ligne en base et les actions de son plan.
     *
     * @param array<string, mixed>                  $risk
     * @param array<int, array<string, mixed>>|null $actions actions du plan (lues si null)
     *
     * @return array{state: string, total: int, done: int}
     */
    public static function forRisk(array $risk, ?array $actions = null): array
    {
        $id = (int) ($risk['id'] ?? 0);
        $actions ??= $id > 0 ? RiskTreatmentAction::getActionsForRisk($id) : [];
        $progress = Rules::planProgress(array_values(array_map(
            static fn(array $action): string => (string) ($action['status'] ?? ''),
            $actions
        )));

        return [
            'state' => Rules::state(
                (string) ($risk['target_level'] ?? ''),
                (string) ($risk['residual_level'] ?? ''),
                (string) ($risk['treatment'] ?? ''),
                $progress['total'],
                $progress['done']
            ),
            'total' => $progress['total'],
            'done'  => $progress['done'],
        ];
    }

    /**
     * État de l'acceptation signée d'un risque (aucune, valable, à renouveler, expirée).
     *
     * @param array<string, mixed> $risk
     */
    public static function acceptanceState(array $risk, ?string $today = null): string
    {
        $signed = (int) ($risk['users_id_acceptance'] ?? 0) > 0 && ($risk['treatment'] ?? '') === 'accept';
        $expiry = $risk['date_acceptance_expiry'] ?? null;

        return Rules::acceptanceState($signed, is_string($expiry) ? $expiry : null, $today ?? date('Y-m-d'));
    }

    /**
     * Badge et explication d'un état d'efficacité.
     *
     * @return array{class: string, icon: string, label: string, help: string}
     */
    public static function stateStyle(string $state): array
    {
        return match ($state) {
            Rules::REACHED => [
                'class' => 'bg-green-lt', 'icon' => 'ti-circle-check',
                'label' => __('Target reached', 'grcmanager'),
                'help'  => __(
                    'The treatment plan is completed and the residual risk is at or below the target.',
                    'grcmanager'
                ),
            ],
            Rules::NOT_REACHED => [
                'class' => 'bg-red-lt', 'icon' => 'ti-alert-octagon',
                'label' => __('Plan completed, target not reached', 'grcmanager'),
                'help'  => __(
                    'Every action of the treatment plan is completed but the residual risk is still above the '
                        . 'target: the treatment was not effective enough. Add actions, or record a decision.',
                    'grcmanager'
                ),
            ],
            Rules::NOT_ASSESSED => [
                'class' => 'bg-orange-lt', 'icon' => 'ti-alert-triangle',
                'label' => __('Plan completed, residual risk to assess', 'grcmanager'),
                'help'  => __(
                    'Every action of the treatment plan is completed: assess the residual probability and '
                        . 'impact to check that the target is reached.',
                    'grcmanager'
                ),
            ],
            Rules::PLAN_OPEN => [
                'class' => 'bg-blue-lt', 'icon' => 'ti-progress',
                'label' => __('Treatment plan in progress', 'grcmanager'),
                'help'  => __('The target will be checked once every action of the plan is completed.', 'grcmanager'),
            ],
            Rules::NO_PLAN => [
                'class' => 'bg-secondary-lt', 'icon' => 'ti-list-check',
                'label' => __('No treatment plan', 'grcmanager'),
                'help'  => __(
                    'The target is checked for a risk to mitigate or to transfer once its treatment plan is completed.',
                    'grcmanager'
                ),
            ],
            default => [
                'class' => 'bg-secondary-lt', 'icon' => 'ti-target-off',
                'label' => __('No target', 'grcmanager'),
                'help'  => __('Choose the target risk level that the treatment must reach.', 'grcmanager'),
            ],
        };
    }

    /**
     * Badge d'un état d'acceptation ; null quand rien n'est signé.
     *
     * @return array{class: string, icon: string, label: string}|null
     */
    public static function acceptanceStyle(string $state): ?array
    {
        return match ($state) {
            Rules::ACCEPTANCE_VALID => [
                'class' => 'bg-green-lt', 'icon' => 'ti-circle-check', 'label' => __('Acceptance valid', 'grcmanager'),
            ],
            Rules::ACCEPTANCE_EXPIRING => [
                'class' => 'bg-orange-lt', 'icon' => 'ti-clock-exclamation',
                'label' => __('Acceptance to renew soon', 'grcmanager'),
            ],
            Rules::ACCEPTANCE_EXPIRED => [
                'class' => 'bg-red-lt', 'icon' => 'ti-alert-octagon', 'label' => __('Acceptance expired', 'grcmanager'),
            ],
            Rules::ACCEPTANCE_NO_EXPIRY => [
                'class' => 'bg-secondary-lt', 'icon' => 'ti-calendar-off',
                'label' => __('Acceptance without expiry date', 'grcmanager'),
            ],
            default => null,
        };
    }

    /**
     * Risques des entités de la session dont le plan de traitement est terminé sans atteindre la
     * cible (ou sans réévaluation du risque résiduel). Vide sans la lecture du registre.
     *
     * @return list<int>
     */
    public static function alertingRiskIds(): array
    {
        global $DB;

        if (!Session::haveRight(Risk::$rightname, READ)) {
            return [];
        }
        $risks = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'target_level', 'residual_level', 'treatment'],
                'FROM'   => Risk::getTable(),
                'WHERE'  => array_merge([
                    ['NOT' => ['target_level' => '']],
                    'treatment' => TreatmentPlanRules::TREATMENTS_REQUIRING_A_PLAN,
                ], EntityPerimeter::criteria(Risk::getTable())),
            ]) as $row
        ) {
            $risks[(int) $row['id']] = $row;
        }
        if ($risks === []) {
            return [];
        }
        $actions = [];
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_risks_id', 'status'],
                'FROM'   => RiskTreatmentAction::getTable(),
                'WHERE'  => ['plugin_grcmanager_risks_id' => array_keys($risks)],
            ]) as $row
        ) {
            $actions[(int) $row['plugin_grcmanager_risks_id']][] = $row;
        }
        $ids = [];
        foreach ($risks as $id => $risk) {
            if (Rules::isAlert(self::forRisk($risk, $actions[$id] ?? [])['state'])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Acceptances signées expirées ou à renouveler dans les EXPIRY_WARNING_DAYS jours, dans les
     * entités de la session. Zéro sans la lecture du registre.
     */
    public static function acceptancesToRenewCount(?string $today = null): int
    {
        global $DB;

        if (!Session::haveRight(Risk::$rightname, READ)) {
            return 0;
        }
        $today ??= date('Y-m-d');
        $limit = date('Y-m-d', (int) strtotime($today . ' +' . Rules::EXPIRY_WARNING_DAYS . ' days'));

        return (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => Risk::getTable(),
            'WHERE' => array_merge([
                'treatment' => 'accept',
                ['users_id_acceptance' => ['>', 0]],
                ['NOT' => ['date_acceptance_expiry' => null]],
                ['date_acceptance_expiry' => ['<=', $limit]],
            ], EntityPerimeter::criteria(Risk::getTable())),
        ])->current()['c'];
    }

    /**
     * Carte « Plans terminés sans atteindre la cible ».
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function targetNotReachedCard(array $params = []): array
    {
        return [
            'number' => count(self::alertingRiskIds()),
            'url'    => Risk::getSearchURL(),
            'label'  => $params['label'] ?? __('Treatment plans completed without reaching the target', 'grcmanager'),
            'icon'   => 'ti ti-target-off',
        ];
    }

    /**
     * Carte « Acceptations de risque à renouveler ».
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function acceptancesToRenewCard(array $params = []): array
    {
        return [
            'number' => self::acceptancesToRenewCount(),
            'url'    => Risk::getSearchURL(),
            'label'  => $params['label'] ?? __('Risk acceptances to renew', 'grcmanager'),
            'icon'   => 'ti ti-calendar-repeat',
        ];
    }
}
