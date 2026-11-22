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
use GlpiPlugin\Grcmanager\Services\Risk\SupplierQuestionnaire;
use Session;
use Supplier;

/**
 * Issue #1040 : onglet « Sécurité » de la fiche Fournisseur de GLPI. Les risques fournisseurs du
 * fournisseur, leur niveau, leur dernière évaluation (questionnaire), la prochaine réévaluation, et
 * les liens pour en créer. Lecture réservée aux droits actuels de SupplierRisk (registre sensible)
 * et limitée aux entités de la session ; rien n'est modifié ici.
 */
class SupplierSecurityTab extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Security', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-check';
    }

    public static function canView(): bool
    {
        return (bool) SupplierRisk::canView();
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Supplier || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = (bool) ($_SESSION['glpishow_count_on_tabs'] ?? false) ? count(self::risks($item->getID())) + count(Risk::getRisksLinkedToSupplier($item->getID())) : 0;

        return self::createTabEntry(self::getTypeName(), $count, $item::class, self::getIcon());
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : les droits sont
     * vérifiés ici aussi.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Supplier || $item->isNewItem() || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/tabs/supplier_security.html.twig',
            self::tabData($item->getID(), date('Y-m-d'))
        );

        return true;
    }

    /**
     * Données de l'onglet : risques du fournisseur visibles, prochaine réévaluation, liens.
     *
     * @return array<string, mixed>
     */
    public static function tabData(int $suppliersId, string $today): array
    {
        $risks = [];
        $nextReview = null;
        foreach (self::risks($suppliersId) as $row) {
            $evaluations = SupplierEvaluation::forRisk((int) $row['id']);
            $last = $evaluations[0] ?? null;
            $score = $last !== null && $last['score'] !== null ? (int) $last['score'] : null;
            $review = (string) ($row['review_date'] ?? '');
            $open = (string) $row['status'] !== 'closed';
            if ($open && $review !== '' && ($nextReview === null || $review < $nextReview)) {
                $nextReview = $review;
            }
            $riskUrl = SupplierRisk::getFormURLWithID((int) $row['id']);
            $risks[] = [
                'id'              => (int) $row['id'],
                'title'           => (string) $row['title'],
                'url'             => $riskUrl,
                'questionnaires'  => $riskUrl . '&forcetab=' . rawurlencode(SupplierAssessment::class . '$1'),
                'risk_level'      => (string) $row['risk_level'],
                'status'          => (string) $row['status'],
                'review_date'     => $review,
                'review_overdue'  => $open && $review !== '' && $review < $today,
                'evaluations'     => count($evaluations),
                'last_evaluation' => $last === null ? null : [
                    'name'   => (string) ($last['name'] ?? ''),
                    'url'    => SupplierEvaluation::getFormURLWithID((int) $last['id']),
                    'date'   => (string) ($last['evaluation_date'] ?? ''),
                    'status' => (string) ($last['status'] ?? ''),
                    'score'  => $score,
                    'level'  => SupplierQuestionnaire::level($score),
                ],
            ];
        }

        return [
            'risks'          => $risks,
            'next_review'    => $nextReview,
            'review_overdue' => $nextReview !== null && $nextReview < $today,
            'levels'         => self::riskLevels(),
            'statuses'       => SupplierRisk::getStatuses(),
            'eval_statuses'  => SupplierEvaluation::getStatuses(),
            'eval_levels'    => SupplierEvaluation::getLevels(),
            'create_url'     => SupplierRisk::canCreate()
                ? SupplierRisk::getFormURL() . '?suppliers_id=' . $suppliersId : '',
            'register_url'   => SupplierRisk::getSearchURL(),
            // Issue #1095 : risques du registre général liés à ce fournisseur (fiche du risque,
            // rubrique « Fournisseurs liés »), dans une section séparée.
            'general_risks'  => array_map(static fn (array $risk): array => [
                'title'      => (string) $risk['title'],
                'url'        => Risk::getFormURLWithID($risk['id']),
                'risk_level' => (string) $risk['risk_level'],
            ], Risk::getRisksLinkedToSupplier($suppliersId)),
            'risk_register_url' => Risk::getSearchURL(),
        ];
    }

    /**
     * Risques fournisseurs de ce fournisseur, dans les entités de la session, du plus grave au moins grave.
     *
     * @return list<array<string, mixed>>
     */
    public static function risks(int $suppliersId): array
    {
        global $DB;

        $table = SupplierRisk::getTable();
        if ($suppliersId <= 0 || !$DB->tableExists($table)) {
            return [];
        }
        $where = ['suppliers_id' => $suppliersId] + getEntitiesRestrictCriteria($table, '', '', true);
        $rows = iterator_to_array($DB->request([
            'FROM'  => $table,
            'WHERE' => $where,
            'ORDER' => ['computed_score DESC', 'id DESC'],
        ]), false);

        return array_values($rows);
    }

    /**
     * Niveau de risque => [libellé, classes du badge].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function riskLevels(): array
    {
        return [
            'low'      => [__('Low', 'grcmanager'), 'bg-green-subtle text-green-emphasis'],
            'medium'   => [__('Medium', 'grcmanager'), 'bg-yellow-subtle text-yellow-emphasis'],
            'high'     => [__('High', 'grcmanager'), 'bg-orange-subtle text-orange-emphasis'],
            'critical' => [__('Critical', 'grcmanager'), 'bg-red-subtle text-red-emphasis'],
        ];
    }
}
