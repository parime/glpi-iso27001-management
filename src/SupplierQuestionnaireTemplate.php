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
use GlpiPlugin\Grcmanager\Compat\Base\SupplierQuestionnaireTemplateBase;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\Services\Risk\WeightedQuestionnaire as Rules;
use Log;
use Session;

/**
 * Issue #864 : modèle de questionnaire fournisseur (sécurité, données personnelles, IA...), avec
 * ses sections et ses questions pondérées (SupplierQuestionnaireQuestion).
 *
 * Mêmes droits que le registre des risques fournisseurs (droit des registres sensibles), et mêmes
 * règles d'entité (issue #536) : un modèle appartient à une entité, éventuellement visible des
 * sous-entités ; une évaluation ne propose que les modèles visibles depuis l'entité du risque.
 *
 * Chaque changement des questions incrémente la version du modèle (historisée). Une évaluation
 * garde une copie des questions : modifier ou supprimer un modèle ne change aucune évaluation faite.
 * Le modèle « Questionnaire historique » (reprise des douze questions fixes) se désactive, mais ne
 * se supprime pas : ses évaluations reprises le citent.
 */
class SupplierQuestionnaireTemplate extends SupplierQuestionnaireTemplateBase
{
    public const RIGHTNAME = SupplierRisk::RIGHTNAME;

    public static function getTable($classname = null)
    {
        return Tables::TEMPLATES;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Supplier questionnaire template', 'Supplier questionnaire templates', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-forms';
    }

    /**
     * @return array<string, string>
     */
    public static function getDomains(): array
    {
        return [
            Rules::DOMAIN_SECURITY   => __('Information security', 'grcmanager'),
            Rules::DOMAIN_PRIVACY    => __('Personal data', 'grcmanager'),
            Rules::DOMAIN_AI         => __('Artificial intelligence', 'grcmanager'),
            Rules::DOMAIN_CONTINUITY => __('Business continuity', 'grcmanager'),
            Rules::DOMAIN_OTHER      => __('Other', 'grcmanager'),
        ];
    }

    /**
     * Badge de chaque domaine : [classes, icône].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function getDomainBadges(): array
    {
        return [
            Rules::DOMAIN_SECURITY   => ['bg-blue-subtle text-blue-emphasis', 'ti-shield-lock'],
            Rules::DOMAIN_PRIVACY    => ['bg-purple-subtle text-purple-emphasis', 'ti-user-shield'],
            Rules::DOMAIN_AI         => ['bg-cyan-subtle text-cyan-emphasis', 'ti-brain'],
            Rules::DOMAIN_CONTINUITY => ['bg-teal-subtle text-teal-emphasis', 'ti-refresh-alert'],
            Rules::DOMAIN_OTHER      => ['bg-secondary-subtle text-secondary-emphasis', 'ti-forms'],
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(self::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof self || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return self::createTabEntry(
            _n('Question', 'Questions', Session::getPluralNumber(), 'grcmanager'),
            count(SupplierQuestionnaireQuestion::getQuestions($item->getID())),
            null,
            'ti ti-list-details'
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : mêmes contrôles.
        if (!$item instanceof self || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        SupplierQuestionnaireQuestion::showForTemplate($item);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $input = EntityPerimeter::prepareAdd($input);
        if ($input === false) {
            return false;
        }
        $input['version'] = 1;
        $input['is_legacy'] = 0;

        return $this->checkInput($input, true);
    }

    public function prepareInputForUpdate($input)
    {
        $input = EntityPerimeter::guardUpdate($input);
        // Repère de la reprise : jamais modifié depuis un formulaire.
        unset($input['is_legacy']);
        if (!isset($input['_grc_version'])) {
            unset($input['version']);
        }
        unset($input['_grc_version']);

        return $this->checkInput($input, false);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkInput(array $input, bool $isNew)
    {
        if ($isNew || array_key_exists('name', $input)) {
            $input['name'] = Rules::cleanText($input['name'] ?? '', Rules::MAX_NAME_LENGTH);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(
                    htmlescape(__('The template name is required.', 'grcmanager')),
                    false,
                    ERROR
                );

                return false;
            }
        }
        if ($isNew || array_key_exists('domain', $input)) {
            $input['domain'] = Rules::normalizeDomain($input['domain'] ?? '');
        }
        if (array_key_exists('comment', $input)) {
            $input['comment'] = Rules::cleanText($input['comment']);
        }

        return $input;
    }

    public function canPurgeItem(): bool
    {
        return empty($this->fields['is_legacy']) && parent::canPurgeItem();
    }

    public function cleanDBonPurge()
    {
        global $DB;

        $DB->delete(Tables::QUESTIONS, [Tables::TEMPLATE_FK => $this->getID()]);
        // Les évaluations gardent leurs questions copiées et le nom du modèle.
        $DB->update(Tables::EVALUATIONS, [Tables::TEMPLATE_FK => 0], [Tables::TEMPLATE_FK => $this->getID()]);
    }

    /**
     * Version suivante après un changement des questions (historisée sur le modèle).
     */
    public function bumpVersion(): void
    {
        $this->update([
            'id'           => $this->getID(),
            'version'      => (int) $this->fields['version'] + 1,
            '_grc_version' => true,
        ]);
    }

    /**
     * Empreinte des questions actuelles (issue #865 : reconnaître un fichier retourné).
     */
    public function fingerprint(): string
    {
        return Rules::fingerprint(SupplierQuestionnaireQuestion::getQuestions($this->getID()));
    }

    /**
     * Copie du modèle et de ses questions (version 1), dans l'entité active. 0 en cas d'échec.
     */
    public function duplicate(): int
    {
        global $DB;

        if ($this->isNewItem()) {
            return 0;
        }
        $copy = new self();
        $copyId = (int) $copy->add([
            'name'         => sprintf(__('Copy of %s', 'grcmanager'), (string) $this->fields['name']),
            'domain'       => (string) $this->fields['domain'],
            'is_active'    => 1,
            'is_recursive' => (int) $this->fields['is_recursive'],
            'comment'      => (string) ($this->fields['comment'] ?? ''),
        ]);
        if ($copyId <= 0) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        foreach (SupplierQuestionnaireQuestion::getQuestions($this->getID()) as $question) {
            unset($question['id']);
            $question[Tables::TEMPLATE_FK] = $copyId;
            $question['date_creation'] = $now;
            $question['date_mod'] = $now;
            $DB->insert(Tables::QUESTIONS, $question);
        }

        return $copyId;
    }

    /**
     * Modèles actifs visibles depuis une entité (ceux de l'entité et ceux, récursifs, de ses
     * parents), parmi les entités de la session.
     *
     * @return array<int, string> id => nom
     */
    public static function availableFor(int $entityId): array
    {
        global $DB;

        $templates = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'domain'],
                'FROM'   => self::getTable(),
                'WHERE'  => array_merge(
                    ['is_active' => 1, getEntitiesRestrictCriteria(self::getTable(), '', $entityId, true)],
                    EntityPerimeter::criteria(self::getTable())
                ),
                'ORDER'  => ['name', 'id'],
            ]) as $row
        ) {
            $templates[(int) $row['id']] = (string) $row['name'];
        }

        return $templates;
    }

    /**
     * Évaluations faites avec ce modèle, dans les entités de la session.
     */
    public static function evaluationCount(int $templateId): int
    {
        return countElementsInTable(
            Tables::EVALUATIONS,
            array_merge([Tables::TEMPLATE_FK => $templateId], EntityPerimeter::criteria(Tables::EVALUATIONS))
        );
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            $this->fields['domain'] = Rules::DOMAIN_SECURITY;
            $this->fields['is_active'] = 1;
        }
        if (!$this->isNewItem() && self::canCreate()) {
            // Bouton du formulaire principal (pas de formulaire imbriqué) : copie du modèle.
            $options['addbuttons'] = [
                'duplicate' => ['text' => __('Duplicate this template', 'grcmanager'), 'icon' => 'ti ti-copy'],
            ];
        }
        $questions = $this->isNewItem() ? [] : SupplierQuestionnaireQuestion::getQuestions($this->getID());
        TemplateRenderer::getInstance()->display('@grcmanager/supplier_questionnaire_template_form.html.twig', [
            'item'        => $this,
            'params'      => $options,
            'domains'     => self::getDomains(),
            'badges'      => self::getDomainBadges(),
            'evaluations' => $this->isNewItem() ? 0 : self::evaluationCount($this->getID()),
            'questions'   => count($questions),
            'fingerprint' => $this->isNewItem() ? '' : Rules::fingerprint($questions),
        ]);

        return true;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
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
            'id' => 3, 'table' => $table, 'field' => 'domain', 'name' => __('Domain', 'grcmanager'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'version', 'name' => __('Version'),
            'datatype' => 'integer', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 5, 'table' => $table, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'comment', 'name' => __('Comments'),
            'datatype' => 'text',
        ];
        foreach (EntityPerimeter::searchOptions($table) as $option) {
            $tab[] = $option;
        }

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'domain') {
            return htmlescape(self::getDomains()[$values[$field] ?? ''] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'domain') {
            return \Dropdown::showFromArray($name, self::getDomains(), [
                'value' => $values[$field] ?? '', 'display' => false,
            ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
