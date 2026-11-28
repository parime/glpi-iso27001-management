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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\RiskReviewBase;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentEffectivenessRules as Rules;
use Html;
use Session;

/**
 * Issue #1182 : revues datées d'un risque (ISO/IEC 27001, clauses 6.1.3, 8.3 et 9.1).
 *
 * Chaque revue garde un instantané de la cotation au moment où elle est enregistrée : probabilité,
 * impact, niveaux et scores initial et résiduel, risque cible, traitement, statut et avancement du
 * plan, avec le constat de la personne qui revoit. L'instantané est pris côté serveur sur le risque
 * enregistré (jamais dans la requête) et n'est plus modifié ensuite : l'onglet « Revues » trace la
 * courbe d'évolution du niveau, preuve de l'efficacité du traitement. Enregistrer une revue demande
 * le droit de modifier le risque (registre sensible, entité du risque).
 */
class RiskReview extends RiskReviewBase
{
    // Registre sensible : droit dédié, voir Installer::SENSITIVE_RIGHT.
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    /** Longueur maximale du constat d'une revue. */
    public const COMMENT_MAX_LENGTH = 10000;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_riskreviews';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Risk review', 'Risk reviews', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-stats';
    }

    /** Enregistrer une revue, c'est modifier le registre des risques. */
    public static function canCreate(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    /** Une revue est un instantané : jamais modifiée ni supprimée seule (elle part avec son risque). */
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

    /**
     * Instantané pris sur le risque enregistré : seuls le risque, le constat et la prochaine date de
     * revue viennent de la requête.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $riskId = (int) ($input['plugin_grcmanager_risks_id'] ?? 0);
        $risk = new Risk();
        if ($riskId <= 0 || !$risk->getFromDB($riskId)) {
            Session::addMessageAfterRedirect(__('Risk not found.', 'grcmanager'), false, ERROR);

            return false;
        }
        if (EntityPerimeter::hasSession() && !$risk->can($riskId, UPDATE)) {
            Session::addMessageAfterRedirect(__('You cannot review this risk.', 'grcmanager'), false, ERROR);

            return false;
        }

        return self::snapshot($risk->fields) + [
            'plugin_grcmanager_risks_id' => $riskId,
            'users_id'                   => (int) Session::getLoginUserID(),
            'date_review'                => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
            'comment'                    => mb_substr(
                trim((string) ($input['comment'] ?? '')),
                0,
                self::COMMENT_MAX_LENGTH
            ),
            '_next_review_date'          => $input['next_review_date'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return false
     */
    public function prepareInputForUpdate($input)
    {
        return false;
    }

    /**
     * Valeurs figées d'un risque au moment de la revue.
     *
     * @param array<string, mixed> $risk ligne du risque
     * @return array<string, mixed>
     */
    public static function snapshot(array $risk): array
    {
        $effectiveness = RiskEffectiveness::forRisk($risk);
        $residualScore = $risk['residual_score'] ?? null;

        return [
            'entities_id'          => (int) ($risk['entities_id'] ?? 0),
            'is_recursive'         => (int) ($risk['is_recursive'] ?? 0),
            'probability'          => (string) ($risk['probability'] ?? ''),
            'impact'               => (string) ($risk['impact'] ?? ''),
            'risk_level'           => (string) ($risk['risk_level'] ?? ''),
            'computed_score'       => (float) ($risk['computed_score'] ?? 0),
            'residual_probability' => (string) ($risk['residual_probability'] ?? ''),
            'residual_impact'      => (string) ($risk['residual_impact'] ?? ''),
            'residual_level'       => (string) ($risk['residual_level'] ?? ''),
            'residual_score'       => $residualScore === null || $residualScore === '' ? null : (float) $residualScore,
            'target_level'         => Rules::normalizeTarget($risk['target_level'] ?? ''),
            'treatment'            => (string) ($risk['treatment'] ?? ''),
            'status'               => (string) ($risk['status'] ?? ''),
            'actions_total'        => $effectiveness['total'],
            'actions_done'         => $effectiveness['done'],
            'effectiveness'        => $effectiveness['state'],
        ];
    }

    /**
     * Prochaine revue : la date choisie devient la date de revue du risque (seul ce champ change).
     */
    public function post_addItem()
    {
        parent::post_addItem();
        $next = $this->input['_next_review_date'] ?? null;
        if (is_string($next) && Rules::normalizeExpiry($next, date('Y-m-d')) !== null) {
            (new Risk())->update([
                'id'          => (int) $this->fields['plugin_grcmanager_risks_id'],
                'review_date' => $next,
            ]);
        }
    }

    /**
     * Revues d'un risque, de la plus ancienne à la plus récente.
     *
     * @return list<array<string, mixed>>
     */
    public static function forRisk(int $riskId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_risks_id' => $riskId],
                'ORDER' => ['date_review ASC', 'id ASC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function countForRisk(int $riskId): int
    {
        return countElementsInTable(self::getTable(), ['plugin_grcmanager_risks_id' => $riskId]);
    }

    /** Les revues partent avec leur risque (Risk::post_purgeItem()). */
    public static function forgetRisk(int $riskId): void
    {
        global $DB;

        $DB->delete(self::getTable(), ['plugin_grcmanager_risks_id' => $riskId]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Risk || $item->isNewItem() || !Session::haveRight(self::RIGHTNAME, READ)) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            self::countForRisk($item->getID()),
            $item::class,
            self::getIcon()
        );
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : droits vérifiés ici,
     * sur le risque lui-même (entité comprise).
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (
            !$item instanceof Risk || $item->isNewItem()
            || !Session::haveRight(self::RIGHTNAME, READ) || !$item->can($item->getID(), READ)
        ) {
            return false;
        }
        $levels = Risk::getRiskLevels();
        $rows = [];
        $points = ['initial' => [], 'residual' => []];
        foreach (self::forRisk($item->getID()) as $review) {
            $date = (string) $review['date_review'];
            $points['initial'][] = [$date, $levels[$review['risk_level']] ?? null];
            if ((string) $review['residual_level'] !== '') {
                $points['residual'][] = [$date, $levels[$review['residual_level']] ?? null];
            }
            $rows[] = [
                'date'           => Html::convDateTime($date),
                'user'           => getUserName((int) $review['users_id']),
                'initial_level'  => RiskTrajectory::levelBadge((string) $review['risk_level']),
                'initial_score'  => Risk::formatScore($review['computed_score']),
                'residual_level' => RiskTrajectory::levelBadge((string) $review['residual_level']),
                'residual_score' => $review['residual_score'] === null
                    ? null : Risk::formatScore($review['residual_score']),
                'target_level'   => RiskTrajectory::levelBadge((string) $review['target_level']),
                'treatment'      => Risk::getTreatments()[$review['treatment']] ?? (string) $review['treatment'],
                'actions'        => (int) $review['actions_total'] > 0
                    ? sprintf(
                        __('%1$d of %2$d', 'grcmanager'),
                        (int) $review['actions_done'],
                        (int) $review['actions_total']
                    )
                    : '',
                'effectiveness'  => $review['effectiveness'] !== ''
                    ? RiskEffectiveness::stateStyle((string) $review['effectiveness']) : null,
                'comment'        => (string) ($review['comment'] ?? ''),
            ];
        }
        $target = (string) ($item->fields['target_level'] ?? '');
        $current = RiskEffectiveness::forRisk($item->fields);

        TemplateRenderer::getInstance()->display('@grcmanager/risk_review_tab.html.twig', [
            'risk_id'      => $item->getID(),
            'reviews'      => array_reverse($rows),
            'can_record'   => self::canCreate() && $item->can($item->getID(), UPDATE),
            'action'       => self::getFormURL(),
            'review_date'  => (string) ($item->fields['review_date'] ?? ''),
            'effectiveness' => RiskEffectiveness::stateStyle($current['state']),
            'alert'        => Rules::isAlert($current['state']),
            'chart'        => count($rows) > 0 ? [
                'levels'         => array_values($levels),
                'initial'        => $points['initial'],
                'residual'       => $points['residual'],
                'target'         => $levels[$target] ?? null,
                'initial_label'  => __('Initial risk level', 'grcmanager'),
                'residual_label' => __('Residual risk level', 'grcmanager'),
                'target_label'   => __('Target risk level', 'grcmanager'),
            ] : null,
        ]);

        return true;
    }
}
