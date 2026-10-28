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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Risk\SupplierQuestionnaire;

/**
 * Issue #127 : évaluation de la sécurité d'un fournisseur, en onglets du risque fournisseur.
 *
 * - « Questionnaire de sécurité » : douze questions (oui / partiel / non / sans objet) et leur
 *   score (SupplierQuestionnaire), indicatif — le niveau de risque reste celui saisi par l'équipe ;
 * - « Contrats du fournisseur » : contrats GLPI du fournisseur, avec leurs échéances.
 *
 * Les preuves (questionnaire signé, certificats) s'ajoutent dans l'onglet Documents natif du
 * risque fournisseur. Simple fournisseur d'onglets (CommonGLPI) : les réponses sont stockées sur
 * le risque fournisseur lui-même, avec l'historique GLPI habituel.
 */
class PluginGrcmanagerSupplierAssessment extends CommonGLPI
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
        if (!$item instanceof PluginGrcmanagerSupplierRisk || $item->isNewItem()) {
            return '';
        }

        return [
            self::TAB_QUESTIONNAIRE => self::createTabEntry(self::getTypeName(1), 0, null, 'ti ti-checklist'),
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
        global $CFG_GLPI;

        if (!$item instanceof PluginGrcmanagerSupplierRisk) {
            return false;
        }

        if ((int) $tabnum === self::TAB_CONTRACTS) {
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_contracts.html.twig', [
                'contracts' => self::getContracts((int) $item->fields['suppliers_id']),
            ]);

            return true;
        }

        $answers = json_decode((string) ($item->fields['questionnaire_answers'] ?? ''), true);
        $score = $item->fields['questionnaire_score'] ?? null;
        $authorId = (int) ($item->fields['questionnaire_users_id'] ?? 0);
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/supplier_questionnaire.html.twig', [
            'item'      => $item,
            'questions' => self::getQuestionLabels(),
            'choices'   => self::getAnswerLabels(),
            'answers'   => is_array($answers) ? $answers : [],
            'score'     => $score === null ? null : (int) $score,
            'level'     => SupplierQuestionnaire::level($score === null ? null : (int) $score),
            'date'      => $item->fields['questionnaire_date'] ?? null,
            'author'    => $authorId > 0 ? getUserName($authorId) : '',
            'can_edit'  => $item->canUpdateItem(),
            'action'    => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/supplierassessment.form.php',
        ]);

        return true;
    }

    /**
     * Enregistre le questionnaire sur le risque fournisseur (score recalculé, auteur et date).
     *
     * @param array<mixed> $answers question => réponse
     */
    public static function save(PluginGrcmanagerSupplierRisk $risk, array $answers): bool
    {
        $clean = SupplierQuestionnaire::sanitize($answers);
        $score = SupplierQuestionnaire::score($clean);

        return (bool) $risk->update([
            'id'                     => $risk->getID(),
            'questionnaire_answers'  => json_encode($clean),
            'questionnaire_score'    => $score ?? 'NULL',
            'questionnaire_date'     => date('Y-m-d H:i:s'),
            'questionnaire_users_id' => (int) Session::getLoginUserID(),
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
