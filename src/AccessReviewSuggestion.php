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
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewSuggestionRules as Rules;
use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;
use Html;
use Plugin;
use Profile;

/**
 * Issue #866 : revue des accès pré-remplie par des règles (A.5.18, A.8.2).
 *
 * Lit les faits de chaque compte déjà connus de GLPI et du plugin, sans rien appeler à
 * l'extérieur :
 * - compte : supprimé de l'annuaire (synchronisation LDAP native), date de fin, dernière connexion,
 *   création (comme l'hygiène des comptes) ;
 * - départ enregistré dans assetsign (fiche de départ non annulée), si ce plugin est actif ;
 * - changement de groupes sans revue des profils (historique GLPI, hygiène des comptes) ;
 * - profils à privilèges et couverture MFA (MfaCoverage, #852).
 *
 * La suggestion et ses raisons sont figées sur la ligne au lancement, puis recalculables pour les
 * lignes encore à revoir. Rien n'est décidé ni modifié : le relecteur choisit.
 */
final class AccessReviewSuggestion
{
    public const DEPARTURES_TABLE = 'glpi_plugin_assetsign_departures';
    private const DEPARTURE_CANCELLED = 2;

    /**
     * @return array<string, array{label: string, short: string, color: string, icon: string}>
     */
    public static function suggestionInfos(): array
    {
        return [
            Rules::REMOVE  => [
                'label' => __('Suggested: remove', 'grcmanager'),
                'short' => __('Remove', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-user-x',
            ],
            Rules::EXAMINE => [
                'label' => __('Suggested: examine', 'grcmanager'),
                'short' => __('Examine', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-zoom-question',
            ],
            Rules::KEEP    => [
                'label' => __('Suggested: keep', 'grcmanager'),
                'short' => __('Keep', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-user-check',
            ],
        ];
    }

    /**
     * Texte d'une raison (codes et paramètres stockés sur la ligne).
     *
     * @param array<string, mixed> $params
     */
    public static function reasonLabel(string $code, array $params): string
    {
        // Date stockée par les règles (AAAA-MM-JJ) ; toute autre valeur est ignorée.
        $rawDate = (string) ($params['date'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) === 1 ? (string) Html::convDate($rawDate) : '';
        $days = (int) ($params['days'] ?? 0);
        $profileNames = is_array($params['profiles'] ?? null) ? $params['profiles'] : [];
        $profiles = implode(', ', array_map('strval', $profileNames));

        return match ($code) {
            Rules::R_DIRECTORY_DELETED => __('Deleted from the directory', 'grcmanager'),
            Rules::R_EXPIRED           => sprintf(__('Account end date passed (%s)', 'grcmanager'), $date),
            Rules::R_DEPARTED          => sprintf(__('Departure recorded on %s', 'grcmanager'), $date),
            Rules::R_LEAVING           => sprintf(__('Leaving on %1$s (within %2$d days)', 'grcmanager'), $date, $days),
            Rules::R_INACTIVE          => sprintf(
                __('No login for more than %1$d days (last on %2$s)', 'grcmanager'),
                $days,
                $date
            ),
            Rules::R_NEVER_USED        => sprintf(
                __('Never used (created more than %d days ago)', 'grcmanager'),
                $days
            ),
            Rules::R_GROUP_CHANGED     => sprintf(
                __('Groups changed less than %d days ago, profiles not reviewed', 'grcmanager'),
                $days
            ),
            Rules::R_PRIVILEGED        => sprintf(__('Privileged profile: %s', 'grcmanager'), $profiles),
            Rules::R_PRIVILEGED_NO_MFA => sprintf(
                __('Privileged profile without known MFA: %s', 'grcmanager'),
                $profiles
            ),
            Rules::R_NO_SIGNAL         => $date !== ''
                ? sprintf(__('No warning sign (last login on %s)', 'grcmanager'), $date)
                : __('No warning sign', 'grcmanager'),
            default                    => $code,
        };
    }

    /**
     * Raisons qui font « retirer » : affichées en rouge.
     */
    public static function isRemovalReason(string $code): bool
    {
        return in_array($code, Rules::REMOVE_REASONS, true);
    }

    /**
     * Champs d'affichage d'une ligne (onglet, « Mes revues d'accès », rapport PDF).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decorate(array $row): array
    {
        $suggestion = (string) ($row['suggestion'] ?? '');
        $info = self::suggestionInfos()[$suggestion] ?? null;
        $reasons = json_decode((string) ($row['suggestion_reasons'] ?? ''), true);
        $labels = [];
        foreach (is_array($reasons) ? $reasons : [] as $reason) {
            if (!is_array($reason) || !isset($reason['code'])) {
                continue;
            }
            $labels[] = [
                'label'   => self::reasonLabel(
                    (string) $reason['code'],
                    is_array($reason['params'] ?? null) ? $reason['params'] : []
                ),
                'removal' => self::isRemovalReason((string) $reason['code']),
            ];
        }

        return [
            'suggestion'         => $info !== null ? $suggestion : '',
            'suggestion_label'   => $info['label'] ?? '',
            'suggestion_color'   => $info['color'] ?? 'secondary',
            'suggestion_icon'    => $info['icon'] ?? '',
            'suggestion_reasons_list' => $labels,
            // Bouton de décision mis en avant : celui que la suggestion propose (aucun pour « à examiner »).
            'suggested_decision' => match ($suggestion) {
                Rules::KEEP   => AccessReviewLogic::DECISION_KEEP,
                Rules::REMOVE => AccessReviewLogic::DECISION_REMOVE,
                default       => '',
            },
            'differs'            => (string) ($row['decision'] ?? '') !== AccessReviewLogic::DECISION_PENDING
                && Rules::differs($suggestion, (string) ($row['decision'] ?? '')),
        ];
    }

    /**
     * Réglages d'une campagne (champ JSON), aux valeurs par défaut si absents.
     *
     * @param array<string, mixed> $fields
     * @return array{inactive_days: int, leaving_days: int, privileged: bool, mfa: bool, group_change: bool}
     */
    public static function settings(array $fields): array
    {
        return Rules::normalize($fields['suggestion_rules'] ?? null);
    }

    /**
     * Réglages envoyés par le formulaire de la campagne (cases décochées comprises), en JSON.
     *
     * @param array<string, mixed> $input
     */
    public static function settingsFromInput(array $input): string
    {
        $posted = is_array($input['suggestion_rules'] ?? null) ? $input['suggestion_rules'] : [];
        foreach (['privileged', 'mfa', 'group_change'] as $flag) {
            $posted[$flag] = (int) ($posted[$flag] ?? 0);
        }

        return (string) json_encode(Rules::normalize($posted));
    }

    /**
     * Faits de chaque compte, pour les entités de la campagne.
     *
     * @param list<int> $usersIds
     * @param list<int> $entities
     * @param array{inactive_days: int, leaving_days: int, privileged: bool, mfa: bool, group_change: bool} $settings
     * @return array<int, array<string, mixed>>
     */
    public static function facts(array $usersIds, array $entities, array $settings, string $now): array
    {
        global $DB;

        $usersIds = array_values(array_unique(array_filter(array_map('intval', $usersIds))));
        if ($usersIds === []) {
            return [];
        }
        $facts = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'is_deleted_ldap', 'end_date', 'last_login', 'date_creation'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $usersIds],
            ]) as $row
        ) {
            $facts[(int) $row['id']] = [
                'is_deleted_ldap'     => (int) $row['is_deleted_ldap'],
                'end_date'            => $row['end_date'],
                'last_login'          => $row['last_login'],
                'date_creation'       => $row['date_creation'],
                'departure_date'      => null,
                'group_changed'       => false,
                'privileged_profiles' => [],
                'mfa_level'           => null,
            ];
        }

        foreach (self::departures(array_keys($facts)) as $id => $date) {
            $facts[$id]['departure_date'] = $date;
        }

        if ($settings['group_change']) {
            [$groups, $profiles] = AccountHygiene::lastGroupAndProfileChanges(array_keys($facts));
            foreach ($facts as $id => $fact) {
                $facts[$id]['group_changed'] = AccountHygieneRules::groupChangedWithoutProfileReview(
                    $groups[$id] ?? null,
                    $profiles[$id] ?? null,
                    $fact['date_creation'] ?? null,
                    $now,
                    Rules::GROUP_CHANGE_DAYS
                );
            }
        }

        $privileged = $settings['privileged'] ? MfaCoverage::privilegedProfiles() : [];
        if ($privileged !== [] && $entities !== []) {
            foreach (
                $DB->request([
                    'SELECT'   => ['users_id', 'profiles_id'],
                    'DISTINCT' => true,
                    'FROM'     => 'glpi_profiles_users',
                    'WHERE'    => [
                        'users_id'    => array_keys($facts),
                        'profiles_id' => $privileged,
                        'entities_id' => $entities,
                    ],
                ]) as $row
            ) {
                $name = (string) Dropdown::getDropdownName(Profile::getTable(), (int) $row['profiles_id']);
                if (!in_array($name, $facts[(int) $row['users_id']]['privileged_profiles'], true)) {
                    $facts[(int) $row['users_id']]['privileged_profiles'][] = $name;
                }
            }
            if ($settings['mfa']) {
                foreach (MfaCoverage::accounts($entities) as $account) {
                    if (isset($facts[(int) $account['id']])) {
                        $facts[(int) $account['id']]['mfa_level'] = (string) $account['level'];
                    }
                }
            }
        }

        return $facts;
    }

    /**
     * Date de départ de la fiche de départ la plus récente (non annulée) de chaque compte, si
     * assetsign est actif.
     *
     * @param list<int> $usersIds
     * @return array<int, string>
     */
    public static function departures(array $usersIds): array
    {
        global $DB;

        if (
            $usersIds === []
            || !Plugin::isPluginActive('assetsign')
            || !$DB->tableExists(self::DEPARTURES_TABLE)
        ) {
            return [];
        }
        $dates = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id', 'date_departure'],
                'FROM'   => self::DEPARTURES_TABLE,
                'WHERE'  => [
                    'users_id'       => $usersIds,
                    'NOT'            => ['date_departure' => null],
                    'status'         => ['<>', self::DEPARTURE_CANCELLED],
                ],
                'ORDER'  => ['id DESC'],
            ]) as $row
        ) {
            $dates[(int) $row['users_id']] ??= (string) $row['date_departure'];
        }

        return $dates;
    }

    /**
     * Calcule et enregistre la suggestion des lignes d'une campagne (toutes au lancement, seulement
     * celles encore à revoir ensuite : une ligne décidée garde la suggestion vue par le relecteur).
     *
     * @return int nombre de lignes mises à jour
     */
    public static function refresh(AccessReview $campaign, bool $pendingOnly = true): int
    {
        global $DB;

        $where = ['plugin_grcmanager_accessreviews_id' => $campaign->getID()];
        if ($pendingOnly) {
            $where['decision'] = AccessReviewLogic::DECISION_PENDING;
        }
        $lines = [];
        $rows = $DB->request(['SELECT' => ['id', 'users_id'], 'FROM' => AccessReview::LINES_TABLE, 'WHERE' => $where]);
        foreach ($rows as $row) {
            $lines[(int) $row['id']] = (int) $row['users_id'];
        }
        if ($lines === []) {
            return 0;
        }
        $settings = self::settings($campaign->fields);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $facts = self::facts(array_values($lines), $campaign->getEntities(), $settings, $now);
        $count = 0;
        foreach ($lines as $lineId => $usersId) {
            $result = Rules::suggest($facts[$usersId] ?? [], $settings, $now);
            $DB->update(AccessReview::LINES_TABLE, [
                'suggestion'         => $result['suggestion'],
                'suggestion_reasons' => json_encode($result['reasons']),
                'date_suggestion'    => $now,
            ], ['id' => $lineId]);
            $count++;
        }

        return $count;
    }
}
