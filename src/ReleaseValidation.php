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

use Appliance;
use Change;
use Change_Item;
use CommonDBTM;
use CommonGLPI;
use CommonITILValidation;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ReleaseValidationBase;
use GlpiPlugin\Grcmanager\Install\SecureDevelopmentTables;
use GlpiPlugin\Grcmanager\Services\SecureDevelopment\SecureDevelopmentRules as Rules;
use Log;
use Session;

/**
 * Issue #777 : validation sécurité de la mise en production, en onglet d'un changement de GLPI
 * (Change) lié à au moins une application (Appliance). Le changement reste celui du cœur : le
 * plugin n'y ajoute qu'une liste de contrôle (tests passés, revue faite, vulnérabilités traitées)
 * et la décision de la DSI ou du RSSI, tracée par son auteur et sa date, et inscrite dans
 * l'historique du changement.
 *
 * Droits :
 * - onglet : droit de lecture du plugin, changement visible par la personne (droit natif et entité) ;
 * - liste de contrôle : droit de modifier (ou créer) du plugin ;
 * - décision et réouverture : droit dédié « Décider des mises en production », attribuable depuis
 *   l'onglet GRC Manager des profils.
 * Une fois la décision prise, la liste de contrôle est figée : il faut rouvrir la décision.
 */
class ReleaseValidation extends ReleaseValidationBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /**
     * Droit dédié (bit du droit général du plugin) : valider ou refuser une mise en production.
     * Bit distinct de la validation des bilans d'exercice (1024, droit de la continuité) : la
     * matrice des profils regroupe les colonnes par bit, une seule colonne par droit dédié.
     */
    public const DECIDE = 8192;

    public static function getTable($classname = null)
    {
        return SecureDevelopmentTables::RELEASES;
    }

    public static function getTypeName($nb = 0)
    {
        return __('Security release validation', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-rocket';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/releasevalidation.form.php';
    }

    public static function canDecide(): bool
    {
        return (bool) Session::haveRight(self::$rightname, self::DECIDE);
    }

    /**
     * @return array<string, string>
     */
    public static function checklistLabels(): array
    {
        return [
            'tests_passed'           => __('Tests passed (including security tests)', 'grcmanager'),
            'review_done'            => __('Code review done', 'grcmanager'),
            'vulnerabilities_treated' => __('Vulnerabilities found treated (SAST, DAST, dependencies)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            Rules::ROLE_CIO  => __('CIO (IT department)', 'grcmanager'),
            Rules::ROLE_CISO => __('CISO', 'grcmanager'),
        ];
    }

    /**
     * Libellé, couleur et icône de chaque état d'une mise en production.
     *
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function releaseStateInfos(): array
    {
        return [
            Rules::RELEASE_VALIDATED      => [
                'label' => __('Validated before release', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-circle-check',
            ],
            Rules::RELEASE_VALIDATED_LATE => [
                'label' => __('Validated after release', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-clock-exclamation',
            ],
            Rules::RELEASE_REJECTED       => [
                'label' => __('Released although rejected', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-circle-x',
            ],
            Rules::RELEASE_PENDING        => [
                'label' => __('Released without decision', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-hourglass',
            ],
            Rules::RELEASE_NONE           => [
                'label' => __('Released without security checklist', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-alert-octagon',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null liste de contrôle du changement, null s'il n'en a pas
     */
    public static function getForChange(int $changesId): ?array
    {
        global $DB;

        if ($changesId <= 0 || !$DB->tableExists(self::getTable())) {
            return null;
        }
        $criteria = ['FROM' => self::getTable(), 'WHERE' => ['changes_id' => $changesId], 'LIMIT' => 1];
        foreach ($DB->request($criteria) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Applications liées au changement (éléments du changement), avec leur fiche éventuelle.
     *
     * @return list<array{id: int, name: string, entities_id: int, has_profile: bool}>
     */
    public static function linkedApplications(int $changesId): array
    {
        global $DB;

        $rows = $DB->request([
            'SELECT'     => [
                'glpi_appliances.id', 'glpi_appliances.name', 'glpi_appliances.entities_id',
                SecureDevelopmentTables::APPLICATIONS . '.id AS profile_id',
            ],
            'FROM'       => Change_Item::getTable(),
            'INNER JOIN' => [
                'glpi_appliances' => ['FKEY' => [
                    'glpi_appliances'      => 'id',
                    Change_Item::getTable() => 'items_id',
                ]],
            ],
            'LEFT JOIN'  => [
                SecureDevelopmentTables::APPLICATIONS => ['FKEY' => [
                    SecureDevelopmentTables::APPLICATIONS => 'appliances_id',
                    'glpi_appliances'                     => 'id',
                ]],
            ],
            'WHERE'      => [
                Change_Item::getTable() . '.changes_id' => $changesId,
                Change_Item::getTable() . '.itemtype'   => Appliance::class,
                'glpi_appliances.is_deleted'            => 0,
            ],
            'ORDER'      => 'glpi_appliances.name',
        ]);
        $applications = [];
        foreach ($rows as $row) {
            $applications[] = [
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'],
                'entities_id' => (int) $row['entities_id'],
                'has_profile' => $row['profile_id'] !== null,
            ];
        }

        return $applications;
    }

    /** Le changement est concerné : il est lié à au moins une application. */
    public static function concerns(Change $change): bool
    {
        return !$change->isNewItem() && self::linkedApplications((int) $change->getID()) !== [];
    }

    /**
     * Date de mise en production d'un changement : résolution, sinon clôture ('' si aucune).
     *
     * @param array<string, mixed> $change
     */
    public static function releaseDate(array $change): string
    {
        foreach (['solvedate', 'closedate'] as $field) {
            if (!empty($change[$field])) {
                return (string) $change[$field];
            }
        }

        return '';
    }

    /** @return list<int> statuts d'un changement mis en production */
    public static function releasedStatuses(): array
    {
        return [Change::SOLVED, Change::OBSERVED, Change::CLOSED];
    }

    // ------------------------------------------------------------------ Enregistrement.

    /**
     * Enregistre la liste de contrôle (l'appelant a vérifié les droits). Refusé une fois la décision
     * prise.
     *
     * @param array<string, mixed> $input
     * @return string|null message d'erreur, null si enregistré
     */
    public static function saveChecklist(int $changesId, array $input): ?string
    {
        $existing = self::getForChange($changesId);
        if ($existing !== null && (string) $existing['decision'] !== Rules::DECISION_PENDING) {
            return __('The decision has been taken: reopen it before changing the checklist.', 'grcmanager');
        }
        $values = ['checklist_comment' => trim((string) ($input['checklist_comment'] ?? ''))];
        foreach (Rules::CHECKLIST as $item) {
            $values[$item] = Rules::flag($input[$item] ?? 0);
        }
        $validation = new self();
        $saved = $existing !== null
            ? $validation->update(['id' => (int) $existing['id']] + $values)
            : $validation->add(['changes_id' => $changesId] + $values);

        return $saved ? null : __('The checklist could not be saved.', 'grcmanager');
    }

    /**
     * Décision de la DSI ou du RSSI (l'appelant a vérifié le droit de décider). L'auteur et la date
     * viennent de la session, jamais du formulaire.
     *
     * @return string|null message d'erreur, null si la décision est enregistrée
     */
    public static function decide(
        int $changesId,
        string $decision,
        string $role,
        string $reason,
        ?string $now = null
    ): ?string {
        $existing = self::getForChange($changesId) ?? ['decision' => Rules::DECISION_PENDING];
        $refusal = Rules::decisionRefusal($existing, $decision, $role, $reason);
        if ($refusal !== null) {
            return self::refusalMessage($refusal);
        }
        $values = [
            'decision'          => $decision,
            'validator_role'    => Rules::sanitizeRole($role),
            'decision_comment'  => trim($reason),
            'users_id_decision' => (int) Session::getLoginUserID(),
            'date_decision'     => $now ?? (string) $_SESSION['glpi_currenttime'],
        ];
        $validation = new self();
        $saved = isset($existing['id'])
            ? $validation->update(['id' => (int) $existing['id']] + $values)
            : $validation->add(['changes_id' => $changesId] + $values);
        if (!$saved) {
            return __('The decision could not be saved.', 'grcmanager');
        }
        self::traceOnChange($changesId, sprintf(
            $decision === Rules::DECISION_APPROVED
                ? __('Security release validation: approved by %1$s (%2$s)', 'grcmanager')
                : __('Security release validation: rejected by %1$s (%2$s)', 'grcmanager'),
            getUserName((int) Session::getLoginUserID()),
            self::roleLabels()[$values['validator_role']]
        ));

        return null;
    }

    /**
     * Rouvre la décision (droit de décider) : la liste de contrôle redevient modifiable. La décision
     * précédente reste dans l'historique du changement.
     */
    public static function reopen(int $changesId): ?string
    {
        $existing = self::getForChange($changesId);
        if ($existing === null || (string) $existing['decision'] === Rules::DECISION_PENDING) {
            return __('There is no decision to reopen.', 'grcmanager');
        }
        $saved = (new self())->update([
            'id'                => (int) $existing['id'],
            'decision'          => Rules::DECISION_PENDING,
            'validator_role'    => '',
            'decision_comment'  => '',
            'users_id_decision' => 0,
            'date_decision'     => 'NULL',
        ]);
        if (!$saved) {
            return __('The decision could not be reopened.', 'grcmanager');
        }
        self::traceOnChange($changesId, sprintf(
            __('Security release validation: decision reopened by %s', 'grcmanager'),
            getUserName((int) Session::getLoginUserID())
        ));

        return null;
    }

    private static function traceOnChange(int $changesId, string $message): void
    {
        Log::history($changesId, Change::class, [0, '', $message], self::class, Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    private static function refusalMessage(string $refusal): string
    {
        return match ($refusal) {
            Rules::REFUSED_CHECKLIST => __(
                'Every item of the checklist must be ticked before approving the release.',
                'grcmanager'
            ),
            Rules::REFUSED_ROLE      => __('Say whether you decide as CIO or as CISO.', 'grcmanager'),
            Rules::REFUSED_REASON    => __('Give the reason for the rejection.', 'grcmanager'),
            Rules::REFUSED_DECIDED   => __('The decision has already been taken: reopen it first.', 'grcmanager'),
            default                  => __('Unknown decision.', 'grcmanager'),
        };
    }

    /**
     * Hooks::ITEM_PURGE (hook.php) : la liste de contrôle d'un changement supprimé définitivement
     * disparaît avec lui.
     */
    public static function forgetItem(CommonDBTM $item): void
    {
        global $DB;

        if ($item instanceof Change && $DB->tableExists(self::getTable())) {
            $DB->delete(self::getTable(), ['changes_id' => (int) $item->getID()]);
        }
    }

    // ------------------------------------------------------------------ Onglet du changement.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Change || !Session::haveRight(self::$rightname, READ) || !self::concerns($item)) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1));
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : droits vérifiés ici
     * aussi (droit du plugin, changement lisible avec son entité).
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (
            !$item instanceof Change || $item->isNewItem() || !Session::haveRight(self::$rightname, READ)
            || !$item->canViewItem()
        ) {
            return false;
        }
        $changesId = (int) $item->getID();
        $validation = self::getForChange($changesId);
        $decided = $validation !== null && (string) $validation['decision'] !== Rules::DECISION_PENDING;
        $released = in_array((int) $item->fields['status'], self::releasedStatuses(), true);
        $releaseState = $released ? Rules::releaseState($validation, self::releaseDate($item->fields)) : null;
        $applications = array_map(static function (array $application): array {
            $appliance = new Appliance();
            $visible = $appliance->getFromDB($application['id']) && $appliance->canViewItem();

            return $application + [
                'url'  => $visible ? Appliance::getFormURLWithID($application['id']) : '',
                'name' => $visible ? $application['name'] : __('Application not visible to you', 'grcmanager'),
            ];
        }, self::linkedApplications($changesId));

        TemplateRenderer::getInstance()->display('@grcmanager/release_validation_tab.html.twig', [
            'changes_id'        => $changesId,
            'validation'        => $validation ?? [],
            'decided'           => $decided,
            'unchecked'         => Rules::uncheckedItems($validation ?? []),
            'applications'      => $applications,
            'checklist'         => self::checklistLabels(),
            'roles'             => self::roleLabels(),
            'can_edit'          => !$decided
                && Session::haveRight(self::$rightname, $validation === null ? CREATE : UPDATE),
            'can_decide'        => self::canDecide(),
            'decider_name'      => $decided ? getUserName((int) $validation['users_id_decision']) : '',
            'release_state'     => $releaseState !== null ? self::releaseStateInfos()[$releaseState] : null,
            'release_ok'        => $releaseState === Rules::RELEASE_VALIDATED,
            'glpi_approval'     => CommonITILValidation::getStatus(
                (int) ($item->fields['global_validation'] ?? CommonITILValidation::NONE)
            ),
            'action'            => self::getFormURL(),
            'register_url'      => SecureDevelopment::getPageURL(),
        ]);

        return true;
    }
}
