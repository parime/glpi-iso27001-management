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
use Contract;
use Contract_Supplier;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Risk\SupplierQuestionnaire;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire;

/**
 * Issue #127 : évaluation de la sécurité d'un fournisseur, en onglets du risque fournisseur.
 *
 * - « Questionnaires » (issue #864) : évaluations du fournisseur avec des modèles configurables
 *   (SupplierEvaluation), chacune avec son score pondéré, indicatif : le niveau de risque reste
 *   celui saisi par l'équipe. Les douze questions fixes d'origine (getQuestionLabels()) sont
 *   reprises dans le modèle « Questionnaire historique » ;
 * - « Contrats du fournisseur » : contrats GLPI du fournisseur, avec leurs échéances.
 *
 * Les preuves (questionnaire signé, certificats, pièces justificatives des questions) sont dans
 * l'onglet Documents natif du risque fournisseur. Simple fournisseur d'onglets (CommonGLPI).
 */
class SupplierAssessment extends CommonGLPI
{
    private const TAB_QUESTIONNAIRE = 1;
    private const TAB_CONTRACTS = 2;

    public static function getTypeName($nb = 0)
    {
        return __('Security questionnaire', 'grcmanager');
    }

    /**
     * @return array<string, string> question => libellé
     */
    public static function getQuestionLabels(): array
    {
        return [
            'certification' => __(
                'The supplier holds a valid security certification (ISO 27001, SOC 2, HDS…).',
                'grcmanager'
            ),
            'security_policy' => __('It has a formal, up-to-date information security policy.', 'grcmanager'),
            'data_location' => __('Our data is hosted and processed in the European Union.', 'grcmanager'),
            'encryption' => __('Our data is encrypted at rest and in transit.', 'grcmanager'),
            'mfa' => __('Access to our data requires multi-factor authentication.', 'grcmanager'),
            'subcontractors' => __(
                'Its list of subcontractors is shared with us and our requirements are passed on to them.',
                'grcmanager'
            ),
            'incident_notification' => __(
                'It commits to notifying us of its security incidents within a contractual deadline.',
                'grcmanager'
            ),
            'backup_continuity' => __('Its backups are tested and it has a business continuity plan.', 'grcmanager'),
            'audits' => __('It performs regular audits or penetration tests, or agrees to be audited.', 'grcmanager'),
            'vulnerability_management' => __(
                'It manages its vulnerabilities and applies patches within defined timeframes.',
                'grcmanager'
            ),
            'reversibility' => __(
                'The return and deletion of our data at the end of the contract are provided for.',
                'grcmanager'
            ),
            'contract_clauses' => __('The contract includes security and confidentiality clauses.', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string> réponse => libellé
     */
    public static function getAnswerLabels(): array
    {
        return [
            SupplierQuestionnaire::ANSWER_YES     => __('Yes', 'grcmanager'),
            SupplierQuestionnaire::ANSWER_PARTIAL => __('Partial', 'grcmanager'),
            SupplierQuestionnaire::ANSWER_NO      => __('No', 'grcmanager'),
            SupplierQuestionnaire::ANSWER_NA      => _x('nothing to assess', 'Not applicable', 'grcmanager'),
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof SupplierRisk || $item->isNewItem()) {
            return '';
        }

        return [
            self::TAB_QUESTIONNAIRE => self::createTabEntry(
                __('Questionnaires', 'grcmanager'),
                count(SupplierEvaluation::forRisk($item->getID())),
                null,
                'ti ti-checklist'
            ),
            self::TAB_CONTRACTS     => self::createTabEntry(
                __('Supplier contracts', 'grcmanager'),
                0,
                null,
                Contract::getIcon()
            ),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof SupplierRisk) {
            return false;
        }

        if ((int) $tabnum === self::TAB_CONTRACTS) {
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_contracts.html.twig', [
                'contracts' => self::getContracts((int) $item->fields['suppliers_id']),
            ]);

            return true;
        }

        if (!$item->can($item->getID(), READ)) {
            return false;
        }
        self::showEvaluations($item);

        return true;
    }

    /**
     * Issue #864 : évaluations du risque fournisseur (plusieurs modèles : sécurité, données
     * personnelles, IA...), avec leur score et leur historique, et création d'une évaluation.
     */
    private static function showEvaluations(SupplierRisk $risk): void
    {
        global $CFG_GLPI;

        $rows = [];
        $history = [];
        foreach (SupplierEvaluation::forRisk($risk->getID()) as $row) {
            $score = $row['score'] === null ? null : (int) $row['score'];
            $row['level'] = SupplierQuestionnaire::level($score);
            $row['url'] = SupplierEvaluation::getFormURLWithID((int) $row['id']);
            $row['evaluator'] = (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '';
            $rows[] = $row;
            if ($score !== null && $row['status'] === WeightedQuestionnaire::STATUS_COMPLETED) {
                $history[(string) $row['domain']][] = $score;
            }
        }
        // Évolution par domaine : écart entre les deux dernières évaluations terminées.
        $trends = [];
        foreach ($history as $domain => $scores) {
            if (count($scores) >= 2) {
                $trends[$domain] = $scores[0] - $scores[1];
            }
        }
        $entityId = (int) ($risk->fields['entities_id'] ?? 0);
        $canCreate = $risk->can($risk->getID(), UPDATE) && SupplierEvaluation::canCreate();
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_questionnaire.html.twig', [
            'risk'          => $risk,
            'evaluations'   => $rows,
            'trends'        => $trends,
            'statuses'      => SupplierEvaluation::getStatuses(),
            'levels'        => SupplierEvaluation::getLevels(),
            'domains'       => SupplierQuestionnaireTemplate::getDomains(),
            'badges'        => SupplierQuestionnaireTemplate::getDomainBadges(),
            'templates'     => $canCreate ? SupplierQuestionnaireTemplate::availableFor($entityId) : [],
            'can_create'    => $canCreate,
            'templates_url' => SupplierQuestionnaireTemplate::canView()
                ? SupplierQuestionnaireTemplate::getSearchURL()
                : '',
            'entity_id'     => $entityId,
            'action'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/supplierevaluation.form.php',
        ]);
    }

    /**
     * @return list<array{id: int, name: string, url: string, begin_date: ?string, end_date: ?string}>
     */
    private static function getContracts(int $suppliersId): array
    {
        global $DB;

        $contracts = [];
        // Contrats natifs GLPI : droit `contract` et entités de la session, comme la liste native
        // (le fournisseur évalué est partagé entre entités, ses contrats non).
        if (!Contract::canView()) {
            return $contracts;
        }
        $rows = $DB->request([
            'SELECT'     => [Contract::getTable() . '.*'],
            'FROM'       => Contract::getTable(),
            'INNER JOIN' => [
                Contract_Supplier::getTable() => [
                    'FKEY' => [Contract_Supplier::getTable() => 'contracts_id', Contract::getTable() => 'id'],
                ],
            ],
            'WHERE'      => [
                Contract_Supplier::getTable() . '.suppliers_id' => $suppliersId,
                Contract::getTable() . '.is_deleted'            => 0,
            ] + getEntitiesRestrictCriteria(Contract::getTable(), '', '', true),
            'ORDER'      => Contract::getTable() . '.name',
        ]);
        foreach ($rows as $row) {
            $end = null;
            if (!empty($row['begin_date']) && (int) $row['duration'] > 0) {
                $end = date(
                    'Y-m-d',
                    (int) strtotime(sprintf('%s +%d months', $row['begin_date'], (int) $row['duration']))
                );
            }
            $contracts[] = [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'url'        => Contract::getFormURLWithID((int) $row['id']),
                'begin_date' => $row['begin_date'] ?: null,
                'end_date'   => $end,
            ];
        }

        return $contracts;
    }
}
