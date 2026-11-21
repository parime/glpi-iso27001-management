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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\SupplierEvaluationBase;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;
use Log;
use Session;

/**
 * Issue #864 : évaluation d'un fournisseur avec un modèle de questionnaire (sécurité, données
 * personnelles, IA...). Un risque fournisseur peut en avoir plusieurs, chacune avec son score
 * pondéré et son historique ; l'onglet « Questionnaires » du risque les liste.
 *
 * - À la création, les questions du modèle sont copiées dans les réponses
 *   (SupplierEvaluationAnswer) : modifier le modèle ensuite ne change pas l'évaluation.
 * - Droits et entité : ceux du risque fournisseur (droit des registres sensibles, entité du risque) ;
 *   répondre exige de pouvoir modifier le risque.
 * - Une évaluation terminée ne se modifie plus, sauf à la rouvrir ; la dernière évaluation terminée
 *   du domaine « sécurité » alimente le score affiché sur le risque et le tableau de bord. Le niveau
 *   de risque reste celui saisi par l'équipe.
 */
class SupplierEvaluation extends SupplierEvaluationBase
{
    public const RIGHTNAME = SupplierRisk::RIGHTNAME;

    public const FK = Tables::EVALUATION_FK;

    public static function getTable($classname = null)
    {
        return Tables::EVALUATIONS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Supplier evaluation', 'Supplier evaluations', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-clipboard-check';
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> statut => [libellé, classes, icône]
     */
    public static function getStatuses(): array
    {
        return [
            Rules::STATUS_IN_PROGRESS => [
                __('In progress', 'grcmanager'),
                'bg-yellow-subtle text-yellow-emphasis',
                'ti-hourglass',
            ],
            Rules::STATUS_COMPLETED   => [
                __('Completed', 'grcmanager'),
                'bg-green-subtle text-green-emphasis',
                'ti-circle-check',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> niveau => [libellé, classes]
     */
    public static function getLevels(): array
    {
        return [
            'good' => [__('Good level', 'grcmanager'), 'bg-green-subtle text-green-emphasis'],
            'fair' => [__('Needs improvement', 'grcmanager'), 'bg-orange-subtle text-orange-emphasis'],
            'poor' => [__('Insufficient level', 'grcmanager'), 'bg-red-subtle text-red-emphasis'],
        ];
    }

    public function getName($options = [])
    {
        $name = (string) ($this->fields['name'] ?? '');

        return $name !== '' ? $name : (string) ($this->fields['template_name'] ?? parent::getName($options));
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(self::class, $tabs, $options)
            ->addStandardTab(SupplierEvaluationFile::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function isCompleted(): bool
    {
        return ($this->fields['status'] ?? '') === Rules::STATUS_COMPLETED;
    }

    public function getRisk(): ?SupplierRisk
    {
        $risk = new SupplierRisk();
        $riskId = (int) ($this->fields[Tables::RISK_FK] ?? 0);

        return $riskId > 0 && $risk->getFromDB($riskId) ? $risk : null;
    }

    // ---------------------------------------------------------------------------------------------
    // Droits : ceux du risque fournisseur
    // ---------------------------------------------------------------------------------------------

    public function canViewItem(): bool
    {
        $risk = $this->getRisk();

        return $risk !== null && $risk->canViewItem() && parent::canViewItem();
    }

    public function canCreateItem(): bool
    {
        $risk = $this->getRisk();

        return $risk !== null && $risk->can($risk->getID(), UPDATE) && parent::canCreateItem();
    }

    public function canUpdateItem(): bool
    {
        $risk = $this->getRisk();

        return $risk !== null && $risk->can($risk->getID(), UPDATE) && parent::canUpdateItem();
    }

    public function canPurgeItem(): bool
    {
        $risk = $this->getRisk();

        return $risk !== null && $risk->can($risk->getID(), UPDATE) && parent::canPurgeItem();
    }

    // ---------------------------------------------------------------------------------------------
    // Saisie
    // ---------------------------------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        $risk = new SupplierRisk();
        $riskId = (int) ($input[Tables::RISK_FK] ?? 0);
        $templateId = (int) ($input[Tables::TEMPLATE_FK] ?? 0);
        if ($riskId <= 0 || !$risk->getFromDB($riskId)) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Choose the supplier risk to evaluate.', 'grcmanager')),
                false,
                ERROR
            );

            return false;
        }
        $entityId = (int) ($risk->fields['entities_id'] ?? 0);
        $template = new SupplierQuestionnaireTemplate();
        if (
            !$template->getFromDB($templateId)
            || !array_key_exists($templateId, SupplierQuestionnaireTemplate::availableFor($entityId))
        ) {
            Session::addMessageAfterRedirect(
                htmlescape(__(
                    'Choose an active questionnaire template available in the entity of the supplier risk.',
                    'grcmanager'
                )),
                false,
                ERROR
            );

            return false;
        }
        $name = Rules::cleanText($input['name'] ?? '', Rules::MAX_NAME_LENGTH);

        return [
            'entities_id'      => $entityId,
            'is_recursive'     => 0,
            Tables::RISK_FK    => $riskId,
            'suppliers_id'     => (int) $risk->fields['suppliers_id'],
            Tables::TEMPLATE_FK => $templateId,
            'template_name'    => (string) $template->fields['name'],
            'template_version' => (int) $template->fields['version'],
            'domain'           => (string) $template->fields['domain'],
            'name'             => $name !== '' ? $name : sprintf(
                __('%1$s - %2$s', 'grcmanager'),
                (string) $template->fields['name'],
                date('Y')
            ),
            'status'           => Rules::STATUS_IN_PROGRESS,
            'source'           => Rules::SOURCE_MANUAL,
            'users_id'         => (int) ($input['users_id'] ?? Session::getLoginUserID()),
            'evaluation_date'  => empty($input['evaluation_date']) ? date('Y-m-d') : (string) $input['evaluation_date'],
            'comment'          => Rules::cleanText($input['comment'] ?? ''),
        ];
    }

    public function post_addItem()
    {
        parent::post_addItem();
        SupplierEvaluationAnswer::createFromTemplate($this);
    }

    public function prepareInputForUpdate($input)
    {
        // Rattachements, copie du modèle et score : jamais depuis un formulaire.
        foreach (
            [
                'entities_id', 'is_recursive', Tables::RISK_FK, 'suppliers_id', Tables::TEMPLATE_FK,
                'template_name', 'template_version', 'domain', 'source', 'date_completed',
            ] as $field
        ) {
            unset($input[$field]);
        }
        if (!isset($input['_grc_internal'])) {
            unset($input['status'], $input['score']);
            if ($this->isCompleted()) {
                Session::addMessageAfterRedirect(
                    htmlescape(__('This evaluation is completed: reopen it to change it.', 'grcmanager')),
                    false,
                    ERROR
                );

                return false;
            }
        }
        unset($input['_grc_internal']);
        if (array_key_exists('name', $input)) {
            $input['name'] = Rules::cleanText($input['name'], Rules::MAX_NAME_LENGTH);
        }
        if (array_key_exists('comment', $input)) {
            $input['comment'] = Rules::cleanText($input['comment']);
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        // Les pièces justificatives restent dans l'onglet Documents du risque fournisseur.
        $DB->delete(Tables::ANSWERS, [Tables::EVALUATION_FK => $this->getID()]);
    }

    public function post_purgeItem()
    {
        parent::post_purgeItem();
        $risk = $this->getRisk();
        if ($risk !== null) {
            self::syncRiskScore($risk);
        }
    }

    /**
     * Score recalculé depuis les réponses (historisé s'il change), puis score du risque.
     */
    public function refreshScore(): void
    {
        $score = Rules::score(SupplierEvaluationAnswer::getAnswers($this->getID()));
        if ($score !== ($this->fields['score'] === null ? null : (int) $this->fields['score'])) {
            $this->update([
                'id'            => $this->getID(),
                'score'         => $score ?? 'NULL',
                '_grc_internal' => true,
            ]);
        } else {
            $this->update(['id' => $this->getID(), 'date_mod' => date('Y-m-d H:i:s'), '_grc_internal' => true]);
        }
        $this->getFromDB($this->getID());
    }

    /**
     * Termine l'évaluation : réponses figées, score repris sur le risque (domaine sécurité).
     */
    public function complete(): bool
    {
        if ($this->isCompleted()) {
            return false;
        }
        $ok = (bool) $this->update([
            'id'            => $this->getID(),
            'status'        => Rules::STATUS_COMPLETED,
            '_grc_internal' => true,
        ]);
        // date_completed n'est jamais pris d'un formulaire (prepareInputForUpdate()) : écrit ici.
        if ($ok) {
            global $DB;

            $DB->update(self::getTable(), ['date_completed' => date('Y-m-d H:i:s')], ['id' => $this->getID()]);
            $this->getFromDB($this->getID());
            $risk = $this->getRisk();
            if ($risk !== null) {
                self::syncRiskScore($risk);
            }
        }

        return $ok;
    }

    public function reopen(): bool
    {
        if (!$this->isCompleted()) {
            return false;
        }
        $ok = (bool) $this->update([
            'id'            => $this->getID(),
            'status'        => Rules::STATUS_IN_PROGRESS,
            '_grc_internal' => true,
        ]);
        if ($ok) {
            $this->getFromDB($this->getID());
            $risk = $this->getRisk();
            if ($risk !== null) {
                self::syncRiskScore($risk);
            }
        }

        return $ok;
    }

    /**
     * Score de sécurité du risque fournisseur (colonnes de l'issue #127, lues par le tableau de
     * bord) : celui de la dernière évaluation terminée du domaine « sécurité ». Rien ne change s'il
     * n'y en a aucune. Le niveau de risque n'est jamais modifié.
     */
    public static function syncRiskScore(SupplierRisk $risk): void
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['score', 'date_completed', 'users_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                Tables::RISK_FK => $risk->getID(),
                'domain'        => Rules::DOMAIN_SECURITY,
                'status'        => Rules::STATUS_COMPLETED,
            ],
            'ORDER'  => ['date_completed DESC', 'id DESC'],
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return;
        }
        $fields = [
            'questionnaire_score'    => $row['score'] === null ? null : (int) $row['score'],
            'questionnaire_date'     => $row['date_completed'],
            'questionnaire_users_id' => (int) $row['users_id'],
        ];
        $current = [
            'questionnaire_score'    => $risk->fields['questionnaire_score'] === null
                ? null
                : (int) $risk->fields['questionnaire_score'],
            'questionnaire_date'     => $risk->fields['questionnaire_date'],
            'questionnaire_users_id' => (int) $risk->fields['questionnaire_users_id'],
        ];
        if ($fields !== $current) {
            $DB->update(SupplierRisk::getTable(), $fields, ['id' => $risk->getID()]);
        }
    }

    /**
     * Évaluations d'un risque fournisseur, de la plus récente à la plus ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public static function forRisk(int $riskId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [Tables::RISK_FK => $riskId],
            'ORDER' => ['evaluation_date DESC', 'id DESC'],
        ]), false));
    }

    // ---------------------------------------------------------------------------------------------
    // Onglet « Questionnaire » de l'évaluation
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof self || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $summary = Rules::summary(SupplierEvaluationAnswer::getAnswers($item->getID()));

        return self::createTabEntry(
            __('Questionnaire', 'grcmanager'),
            $summary['total'] - $summary['answered'],
            null,
            'ti ti-checklist'
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof self || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        SupplierEvaluationAnswer::showForEvaluation($item);

        return true;
    }

    public function showForm($ID, array $options = [])
    {
        global $CFG_GLPI;

        $this->initForm($ID, $options);
        $risk = $this->getRisk();
        $summary = Rules::summary(SupplierEvaluationAnswer::getAnswers($this->getID()));
        TemplateRenderer::getInstance()->display('@grcmanager/supplier_evaluation_form.html.twig', [
            'item'          => $this,
            'params'        => $options + ['canedit' => !$this->isCompleted() && $this->canUpdateItem()],
            'summary'       => $summary,
            'statuses'      => self::getStatuses(),
            'levels'        => self::getLevels(),
            'domains'       => SupplierQuestionnaireTemplate::getDomains(),
            'badges'        => SupplierQuestionnaireTemplate::getDomainBadges(),
            'risk_name'     => $risk !== null ? (string) $risk->fields['title'] : '',
            'risk_url'      => $risk !== null ? SupplierRisk::getFormURLWithID($risk->getID()) : '',
            'supplier_name' => \Dropdown::getDropdownName('glpi_suppliers', (int) $this->fields['suppliers_id']),
            'template_url'  => (int) $this->fields[Tables::TEMPLATE_FK] > 0 && SupplierQuestionnaireTemplate::canView()
                ? SupplierQuestionnaireTemplate::getFormURLWithID((int) $this->fields[Tables::TEMPLATE_FK])
                : '',
            'completed'     => $this->isCompleted(),
            'action'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/supplierevaluation.form.php',
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => $table, 'field' => 'score', 'name' => __('Score', 'grcmanager'),
            'datatype' => 'integer', 'unit' => '%', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'evaluation_date', 'name' => __('Evaluation date', 'grcmanager'),
            'datatype' => 'date', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'template_name', 'name' => __('Template', 'grcmanager'),
            'datatype' => 'string', 'massiveaction' => false,
        ];

        return $tab;
    }
}
