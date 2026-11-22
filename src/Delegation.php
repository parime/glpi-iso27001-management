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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\DelegationBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Delegation\DelegationConfig;
use GlpiPlugin\Grcmanager\Services\Delegation\DelegationRules;
use Html;
use Session;

/**
 * Issue #618 : délégation des décisions du SMSI pendant une absence.
 *
 * Les suppléants sont lus dans GLPI (table des suppléants de validation et période d'absence de
 * l'utilisateur, renseignées par chacun dans ses préférences) : aucun nouvel écran de saisie. Le
 * plugin vérifie à chaque demande que la période couvre l'instant présent (DelegationRules) : la
 * délégation cesse d'elle-même à sa fin, sans tâche ni modification de droits.
 *
 * Chaque décision prise par un suppléant est tracée ici (qui, pour le compte de qui, quand, sur
 * quoi, avec la période d'absence en vigueur) et dans le journal d'audit ; la décision elle-même
 * garde le suppléant pour l'afficher « par X pour le compte de Y » (historique, PDF, notifications).
 */
class Delegation extends DelegationBase
{
    /** Droit lu par la classe parente (Compat\Base\DelegationBase) : lecture du plugin. */
    public const RIGHTNAME = 'plugin_grcmanager';

    public const TABLE = 'glpi_plugin_grcmanager_delegateddecisions';
    private const SUBSTITUTES_TABLE = 'glpi_validatorsubstitutes';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Delegated decision', 'Delegated decisions', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-user-share';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/delegation.php';
    }

    /** Journal en lecture seule : les lignes ne viennent que des décisions elles-mêmes. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    private static function now(): string
    {
        return (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
    }

    /**
     * Titulaires absents dont $substituteId est le suppléant en ce moment, pour un type de décision
     * délégable : id => période d'absence. Vide si le type n'est pas délégable.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public static function holdersFor(int $substituteId, string $type): array
    {
        global $DB;

        if ($substituteId <= 0 || !DelegationConfig::isDelegable($type) || !$DB->tableExists(self::SUBSTITUTES_TABLE)) {
            return [];
        }
        $holders = [];
        foreach (
            $DB->request([
                'SELECT'     => ['users.id', 'users.substitution_start_date', 'users.substitution_end_date'],
                'FROM'       => self::SUBSTITUTES_TABLE . ' AS substitutes',
                'INNER JOIN' => [
                    'glpi_users AS users' => ['FKEY' => ['substitutes' => 'users_id', 'users' => 'id']],
                ],
                'WHERE'      => [
                    'substitutes.users_id_substitute' => $substituteId,
                    'users.is_active'                 => 1,
                    'users.is_deleted'                => 0,
                    ['NOT' => ['users.substitution_start_date' => null]],
                    ['NOT' => ['users.substitution_end_date' => null]],
                ],
            ]) as $row
        ) {
            $holderId = (int) $row['id'];
            if (
                $holderId !== $substituteId
                && DelegationRules::isWithinPeriod(
                    $row['substitution_start_date'],
                    $row['substitution_end_date'],
                    self::now()
                )
            ) {
                $holders[$holderId] = [
                    'start' => (string) $row['substitution_start_date'],
                    'end'   => (string) $row['substitution_end_date'],
                ];
            }
        }

        return $holders;
    }

    /** $substituteId remplace-t-il $holderId en ce moment pour ce type de décision ? */
    public static function isSubstituteOf(int $substituteId, int $holderId, string $type): bool
    {
        return isset(self::holdersFor($substituteId, $type)[$holderId]);
    }

    /**
     * Délégations en cours de $substituteId, tous types délégables confondus (Mon espace GRC).
     *
     * @return list<array{holder: string, end: string, types: list<string>}>
     */
    public static function activeFor(int $substituteId): array
    {
        $infos = DelegationConfig::typeInfos();
        $list = [];
        foreach (DelegationConfig::types() as $type) {
            foreach (self::holdersFor($substituteId, $type) as $holderId => $period) {
                $list[$holderId] ??= [
                    'holder' => getUserName($holderId),
                    'end'    => (string) Html::convDateTime($period['end']),
                    'types'  => [],
                ];
                $list[$holderId]['types'][] = $infos[$type]['label'];
            }
        }

        return array_values($list);
    }

    /** « X pour le compte de Y » : nom affiché d'une décision prise par un suppléant. */
    public static function onBehalfLabel(int $substituteId, int $holderId): string
    {
        return sprintf(
            __('%1$s on behalf of %2$s', 'grcmanager'),
            getUserName($substituteId),
            getUserName($holderId)
        );
    }

    /**
     * Trace une décision prise par un suppléant, ici et dans le journal d'audit.
     */
    public static function record(
        string $type,
        string $itemtype,
        int $itemsId,
        int $entitiesId,
        string $itemLabel,
        string $decision,
        int $holderId,
        int $substituteId
    ): void {
        $period = self::holdersFor($substituteId, $type)[$holderId] ?? ['start' => null, 'end' => null];
        (new self())->add([
            'entities_id'         => $entitiesId,
            'decision_type'       => $type,
            'itemtype'            => $itemtype,
            'items_id'            => $itemsId,
            'item_label'          => mb_substr($itemLabel, 0, 255),
            'decision'            => mb_substr($decision, 0, 32),
            'users_id_holder'     => $holderId,
            'users_id_substitute' => $substituteId,
            'date_start'          => $period['start'],
            'date_end'            => $period['end'],
        ]);
        AuditLog::record('delegated_decision', $itemtype, $itemsId, 0, 'success', [
            'type'       => $type,
            'decision'   => $decision,
            'holder'     => $holderId,
            'substitute' => $substituteId,
        ]);
    }

    /**
     * Journal des décisions déléguées des entités de la session, les plus récentes d'abord.
     *
     * @return list<array<string, string>>
     */
    public static function journal(int $limit = 200): array
    {
        global $DB;

        $infos = DelegationConfig::typeInfos();
        $decisions = AccessReview::getDecisionLabels() + SoaVersion::getAnswerLabels();
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => getEntitiesRestrictCriteria(self::TABLE, '', '', false),
                'ORDER' => 'id DESC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $type = (string) $row['decision_type'];
            $rows[] = [
                'date'       => (string) Html::convDateTime($row['date_creation']),
                'type'       => $infos[$type]['label'] ?? $type,
                'icon'       => $infos[$type]['icon'] ?? 'ti-point',
                'item'       => (string) $row['item_label'],
                'decision'   => (string) ($decisions[$row['decision']] ?? $row['decision']),
                'holder'     => getUserName((int) $row['users_id_holder']),
                'substitute' => getUserName((int) $row['users_id_substitute']),
                'period'     => $row['date_start'] !== null && $row['date_end'] !== null
                    ? sprintf(
                        __('%1$s - %2$s'),
                        Html::convDateTime($row['date_start']),
                        Html::convDateTime($row['date_end'])
                    )
                    : '',
            ];
        }

        return $rows;
    }

    /** Page du journal (front/delegation.php). */
    public static function showJournal(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/delegation_journal.html.twig', [
            'rows'       => self::journal(),
            'types'      => DelegationConfig::typeInfos(),
            'enabled'    => DelegationConfig::types(),
            'config_url' => self::configURL(),
        ]);
    }

    public static function configURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grc-delegation-config';
    }

    /** Réglage global (toutes les entités) : droit de modifier le plugin et la configuration GLPI. */
    public static function canConfigure(): bool
    {
        return (bool) Session::haveRight(Installer::RIGHT_NAME, UPDATE)
            && (bool) Session::haveRight('config', UPDATE);
    }

    /**
     * Données de l'encadré de la configuration (templates/delegation_config.html.twig).
     *
     * @return array<string, mixed>
     */
    public static function configData(): array
    {
        return [
            'types'       => DelegationConfig::typeInfos(),
            'enabled'     => DelegationConfig::types(),
            'action'      => self::getSearchURL(),
            'can_edit'    => self::canConfigure(),
        ];
    }
}
