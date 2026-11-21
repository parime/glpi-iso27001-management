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

use CommonGLPI;
use Computer;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\AccessReviewBmcTables;
use GlpiPlugin\Grcmanager\Install\ServerManagementTables;
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewSuggestionRules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcAccountReviewRules as Rules;
use GlpiPlugin\Grcmanager\Services\ServerManagement\BmcFirmwareInventory;
use Html;

/**
 * Issue #843, lot 2 : comptes locaux des contrôleurs de gestion des serveurs (iLO, iDRAC,
 * XClarity, IPMI) repris dans la revue des accès (A.5.18, A.8.2). Au lancement d'une campagne, les
 * comptes lus sur les contrôleurs des mêmes entités sont figés avec une suggestion ; le
 * gestionnaire de la campagne garde ou retire chaque compte. Rien n'est changé sur les
 * contrôleurs : le retrait se fait sur place, la revue en garde la trace.
 *
 * Onglet de la campagne ; droits : revue des accès et lecture des ordinateurs pour voir, mise à
 * jour de CETTE campagne pour décider.
 */
final class AccessReviewBmcAccount extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Server controller accounts', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-server-cog';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/accessreviewbmcaccount.php';
    }

    /** Voir les comptes : revue des accès et contrôleurs (lecture des ordinateurs). */
    public static function canView(): bool
    {
        return AccessReview::canView() && ServerManagement::canView();
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof AccessReview || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = countElementsInTable(AccessReviewBmcTables::LINES, [
            'plugin_grcmanager_accessreviews_id' => $item->getID(),
        ]);

        return [1 => self::createTabEntry(self::getTypeName(2), $count, null, self::getIcon())];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof AccessReview || !self::canView()) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/tabs/access_review_bmc_accounts.html.twig',
            self::tabData($item)
        );

        return true;
    }

    // ------------------------------------------------------------------ Lancement.

    /**
     * Fige les comptes lus des contrôleurs des entités de la campagne (une seule fois).
     *
     * @return int comptes figés (0 si déjà fait, campagne non ouverte ou aucun compte)
     */
    public static function snapshot(AccessReview $campaign): int
    {
        global $DB;

        $campaignId = $campaign->getID();
        if (
            (string) ($campaign->fields['status'] ?? '') !== AccessReviewLogic::STATUS_OPEN
            || countElementsInTable(AccessReviewBmcTables::LINES, [
                'plugin_grcmanager_accessreviews_id' => $campaignId,
            ]) > 0
        ) {
            return 0;
        }
        $controllers = [];
        foreach (
            $DB->request([
                'FROM'  => ServerManagementTables::CONTROLLERS,
                'WHERE' => ['entities_id' => $campaign->getEntities()],
            ]) as $row
        ) {
            $controllers[(int) $row['id']] = $row;
        }
        if ($controllers === []) {
            return 0;
        }
        $servers = BmcFirmwareInventory::servers(array_values(array_map(static fn (array $row): array => [
            'controller_id' => (int) $row['id'],
            'serial'        => (string) $row['serial'],
            'computers_id'  => (int) $row['computers_id'],
        ], $controllers)));
        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach (
            $DB->request([
                'FROM'  => ServerManagementTables::ACCOUNTS,
                'WHERE' => ['plugin_grcmanager_bmccontrollers_id' => array_keys($controllers)],
                'ORDER' => ['plugin_grcmanager_bmccontrollers_id', 'username'],
            ]) as $account
        ) {
            $controllerId = (int) $account['plugin_grcmanager_bmccontrollers_id'];
            $suggested = Rules::suggest(
                (string) $account['username'],
                (string) $account['role'],
                (int) $account['is_enabled'],
                (int) $account['is_locked']
            );
            $DB->insert(AccessReviewBmcTables::LINES, [
                'plugin_grcmanager_accessreviews_id'  => $campaignId,
                'plugin_grcmanager_bmccontrollers_id' => $controllerId,
                'controller_name'                     => (string) $controllers[$controllerId]['name'],
                'computers_id'                        => $servers[$controllerId]['id'] ?? 0,
                'username'                            => (string) $account['username'],
                'role'                                => (string) $account['role'],
                'is_enabled'                          => (int) $account['is_enabled'],
                'is_locked'                           => (int) $account['is_locked'],
                'suggestion'                          => $suggested['suggestion'],
                'reasons'                             => json_encode($suggested['reasons']),
                'decision'                            => AccessReviewLogic::DECISION_PENDING,
                'date_creation'                       => $now,
            ]);
            $count++;
        }

        return $count;
    }

    /** Campagne d'une ligne (0 si inconnue), pour vérifier le droit avant toute décision. */
    public static function campaignOf(int $lineId): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['plugin_grcmanager_accessreviews_id'],
            'FROM'   => AccessReviewBmcTables::LINES,
            'WHERE'  => ['id' => $lineId],
        ])->current();

        return (int) ($row['plugin_grcmanager_accessreviews_id'] ?? 0);
    }

    public static function purgeCampaign(int $campaignId): void
    {
        global $DB;

        $DB->delete(AccessReviewBmcTables::LINES, ['plugin_grcmanager_accessreviews_id' => $campaignId]);
    }

    // ------------------------------------------------------------------ Décision.

    /**
     * Décision du gestionnaire de la campagne sur un compte (garder ou retirer).
     *
     * @return string message d'erreur, chaîne vide si la décision est enregistrée
     */
    public static function decide(int $lineId, string $decision, string $comment, int $actorId): string
    {
        global $DB;

        $line = $DB->request(['FROM' => AccessReviewBmcTables::LINES, 'WHERE' => ['id' => $lineId]])->current();
        $campaign = new AccessReview();
        if (
            $line === null
            || !$campaign->getFromDB((int) $line['plugin_grcmanager_accessreviews_id'])
            || $campaign->fields['status'] !== AccessReviewLogic::STATUS_OPEN
        ) {
            return __('This line does not belong to an ongoing campaign.', 'grcmanager');
        }
        if (!(bool) $campaign->can($campaign->getID(), UPDATE)) {
            return __('You are not allowed to do this.', 'grcmanager');
        }
        if (!Rules::isDecision($decision)) {
            return __('Unknown decision.', 'grcmanager');
        }
        if (AccessReviewSuggestionRules::mustJustify((string) $line['suggestion'], $decision, $comment)) {
            return __(
                'The rules suggest removing this account: explain in the comment why it is kept.',
                'grcmanager'
            );
        }
        $DB->update(AccessReviewBmcTables::LINES, [
            'decision'      => $decision,
            'comment'       => mb_substr(trim($comment), 0, 2000),
            'decided_by'    => $actorId,
            'date_decision' => date('Y-m-d H:i:s'),
        ], ['id' => $lineId]);

        return '';
    }

    // ------------------------------------------------------------------ Affichage.

    public static function reasonLabel(string $code): string
    {
        return match ($code) {
            Rules::R_DEFAULT   => __('Factory account still enabled: replace it with a named account', 'grcmanager'),
            Rules::R_DISABLED  => __('Disabled on the controller: delete it there', 'grcmanager'),
            Rules::R_LOCKED    => __('Locked on the controller', 'grcmanager'),
            Rules::R_ADMIN     => __('Administrator role: full control of the server', 'grcmanager'),
            Rules::R_NO_SIGNAL => __('No warning sign', 'grcmanager'),
            default            => $code,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function tabData(AccessReview $campaign): array
    {
        global $DB;

        $status = (string) $campaign->fields['status'];
        $canUpdate = (bool) $campaign->canUpdateItem();
        $decisions = AccessReview::getDecisionLabels();
        $infos = AccessReviewSuggestion::suggestionInfos();
        $lines = [];
        foreach (
            $DB->request([
                'FROM'  => AccessReviewBmcTables::LINES,
                'WHERE' => ['plugin_grcmanager_accessreviews_id' => $campaign->getID()],
                'ORDER' => ['controller_name', 'username'],
            ]) as $row
        ) {
            $reasons = json_decode((string) ($row['reasons'] ?? '[]'), true);
            $decision = (string) $row['decision'];
            $lines[] = [
                'id'             => (int) $row['id'],
                'controller'     => (string) $row['controller_name'],
                'server'         => (int) $row['computers_id'] > 0 ? [
                    'name' => (string) Dropdown::getDropdownName(Computer::getTable(), (int) $row['computers_id']),
                    'url'  => Computer::getFormURLWithID((int) $row['computers_id']),
                ] : null,
                'username'       => (string) $row['username'],
                'role'           => (string) $row['role'],
                'enabled'        => (int) $row['is_enabled'] === 1,
                'locked'         => (int) $row['is_locked'] === 1,
                'suggestion'     => $infos[(string) $row['suggestion']] ?? null,
                'suggestion_code' => (string) $row['suggestion'],
                'reasons'        => array_map(
                    static fn (mixed $code): string => self::reasonLabel((string) $code),
                    is_array($reasons) ? $reasons : []
                ),
                'decision'       => $decision,
                'decision_label' => $decisions[$decision] ?? $decision,
                'pending'        => $decision === AccessReviewLogic::DECISION_PENDING,
                'comment'        => (string) ($row['comment'] ?? ''),
                'decided_by'     => (int) $row['decided_by'] > 0
                    ? getUserName((int) $row['decided_by']) : '',
                'date_decision'  => (string) ($row['date_decision'] ?? '') !== ''
                    ? (string) Html::convDateTime((string) $row['date_decision']) : '',
            ];
        }
        $pending = count(array_filter($lines, static fn (array $l): bool => $l['pending']));

        return [
            'campaign_id'  => $campaign->getID(),
            'status'       => $status,
            'lines'        => $lines,
            'pending'      => $pending,
            'decided'      => count($lines) - $pending,
            'can_decide'   => $status === AccessReviewLogic::STATUS_OPEN && $canUpdate,
            // Campagne lancée avant ce lot, ou contrôleurs déclarés après le lancement.
            'can_snapshot' => $status === AccessReviewLogic::STATUS_OPEN && $canUpdate && $lines === [],
            'decisions'    => array_intersect_key($decisions, array_flip(Rules::DECISIONS)),
            'action'       => self::getFormURL(),
            'bmc_url'      => ServerManagement::getSearchURL(),
            'is_draft'     => $status === AccessReviewLogic::STATUS_DRAFT,
        ];
    }
}
