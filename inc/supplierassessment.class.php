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
        return __('Questionnaire de sécurité', 'grcmanager');
    }

    /**
     * @return array<string, string> question => libellé
     */
    public static function getQuestionLabels(): array
    {
        return [
            'certification' => __(
                'Le fournisseur détient une certification de sécurité en cours de validité '
                    . '(ISO 27001, SOC 2, HDS…).',
                'grcmanager'
            ),
            'security_policy' => __(
                'Il dispose d\'une politique de sécurité de l\'information formalisée et à jour.',
                'grcmanager'
            ),
            'data_location' => __(
                'Nos données sont hébergées et traitées dans l\'Union européenne.',
                'grcmanager'
            ),
            'encryption' => __(
                'Nos données sont chiffrées au repos et en transit.',
                'grcmanager'
            ),
            'mfa' => __(
                'Les accès à nos données exigent une authentification multifacteur.',
                'grcmanager'
            ),
            'subcontractors' => __(
                'La liste de ses sous-traitants nous est communiquée et nos exigences leur sont répercutées.',
                'grcmanager'
            ),
            'incident_notification' => __(
                'Il s\'engage à nous notifier ses incidents de sécurité dans un délai contractuel.',
                'grcmanager'
            ),
            'backup_continuity' => __(
                'Ses sauvegardes sont testées et il dispose d\'un plan de continuité.',
                'grcmanager'
            ),
            'audits' => __(
                'Il réalise des audits ou tests d\'intrusion réguliers, ou accepte d\'être audité.',
                'grcmanager'
            ),
            'vulnerability_management' => __(
                'Il gère ses vulnérabilités et applique les correctifs dans des délais définis.',
                'grcmanager'
            ),
            'reversibility' => __(
                'La restitution et la suppression de nos données en fin de contrat sont prévues.',
                'grcmanager'
            ),
            'contract_clauses' => __(
                'Le contrat comporte des clauses de sécurité et de confidentialité.',
                'grcmanager'
            ),
        ];
    }

    /**
     * @return array<string, string> réponse => libellé
     */
    public static function getAnswerLabels(): array
    {
        return [
            SupplierQuestionnaire::ANSWER_YES     => __('Oui', 'grcmanager'),
            SupplierQuestionnaire::ANSWER_PARTIAL => __('Partiel', 'grcmanager'),
            SupplierQuestionnaire::ANSWER_NO      => __('Non', 'grcmanager'),
            SupplierQuestionnaire::ANSWER_NA      => __('Sans objet', 'grcmanager'),
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
                __('Contrats du fournisseur', 'grcmanager'),
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
