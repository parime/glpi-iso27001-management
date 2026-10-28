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
use GlpiPlugin\Grcmanager\Compatibility\Base\IsmsRequirementBase;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;

/**
 * Issue #113 : suivi des exigences du système de management (ISO/IEC 27001:2022, articles 4 à
 * 10), une ligne par sous-article, seedée une fois à l'installation (Installer::seedClauses(),
 * numéros dans IsmsClauseCatalog) — même modèle que PluginGrcmanagerControl pour l'Annexe A.
 *
 * Seuls le numéro et des titres courts rédigés par ce projet sont affichés, jamais le texte des
 * exigences (voir la section « Avertissement » du README). Les preuves sont des Documents GLPI
 * liés (onglet natif Documents), le sous-article renvoie automatiquement vers l'écran du plugin
 * qui produit déjà ces preuves quand il existe (registre des risques pour 6.1.2, SoA pour 6.1.3,
 * audits pour 9.2, etc.).
 */
class PluginGrcmanagerIsmsRequirement extends IsmsRequirementBase
{
    // Même droit que la SoA : ces deux écrans décrivent le même SMSI, aucune frontière d'accès
    // propre (même raisonnement que front/referentiels.php).
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ismsrequirements';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('ISMS requirement (clauses 4 to 10)', 'ISMS requirements (clauses 4 to 10)', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    // Catalogue fixe de 30 sous-articles : ni création ni suppression depuis l'interface, comme
    // les 93 contrôles de l'Annexe A.
    public static function canCreate(): bool
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
     * Titres courts rédigés par ce projet (articles et sous-articles), jamais le texte de la norme.
     * PHP convertit les clés « 4 » à « 10 » en entiers : lire par $titles[$code], jamais par
     * comparaison stricte des clés.
     *
     * @return array<int|string, string>
     */
    public static function getClauseTitles(): array
    {
        return [
            '4'     => __('Context of the organization', 'grcmanager'),
            '4.1'   => __('Organization and its context', 'grcmanager'),
            '4.2'   => __('Interested parties', 'grcmanager'),
            '4.3'   => __('ISMS scope', 'grcmanager'),
            '4.4'   => __('Information security management system', 'grcmanager'),
            '5'     => __('Leadership', 'grcmanager'),
            '5.1'   => __('Leadership and commitment', 'grcmanager'),
            '5.2'   => __('Policy', 'grcmanager'),
            '5.3'   => __('Roles, responsibilities and authorities', 'grcmanager'),
            '6'     => __('Planning', 'grcmanager'),
            '6.1.1' => __('Risks and opportunities: general', 'grcmanager'),
            '6.1.2' => __('Risk assessment', 'grcmanager'),
            '6.1.3' => __('Risk treatment', 'grcmanager'),
            '6.2'   => __('Information security objectives', 'grcmanager'),
            '6.3'   => __('Planning of changes', 'grcmanager'),
            '7'     => __('Support', 'grcmanager'),
            '7.1'   => __('Resources', 'grcmanager'),
            '7.2'   => __('Competence', 'grcmanager'),
            '7.3'   => __('Awareness', 'grcmanager'),
            '7.4'   => __('Communication', 'grcmanager'),
            '7.5.1' => __('Documented information: general', 'grcmanager'),
            '7.5.2' => __('Creating and updating', 'grcmanager'),
            '7.5.3' => __('Control of documented information', 'grcmanager'),
            '8'     => __('Operation', 'grcmanager'),
            '8.1'   => __('Operational planning and control', 'grcmanager'),
            '8.2'   => __('Risk assessment (performing)', 'grcmanager'),
            '8.3'   => __('Risk treatment (implementing)', 'grcmanager'),
            '9'     => __('Performance evaluation', 'grcmanager'),
            '9.1'   => __('Monitoring, measurement, analysis and evaluation', 'grcmanager'),
            '9.2.1' => __('Internal audit: general', 'grcmanager'),
            '9.2.2' => __('Internal audit programme', 'grcmanager'),
            '9.3.1' => __('Management review: general', 'grcmanager'),
            '9.3.2' => __('Management review inputs', 'grcmanager'),
            '9.3.3' => __('Management review results', 'grcmanager'),
            '10'    => __('Improvement', 'grcmanager'),
            '10.1'  => __('Continual improvement', 'grcmanager'),
            '10.2'  => __('Nonconformity and corrective action', 'grcmanager'),
        ];
    }

    public static function getClauseTitle(string $code): string
    {
        return self::getClauseTitles()[$code] ?? $code;
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            IsmsClauseCatalog::STATUS_NOT_STARTED => _x('not begun', 'Not started', 'grcmanager'),
            IsmsClauseCatalog::STATUS_IN_PROGRESS => __('In progress', 'grcmanager'),
            IsmsClauseCatalog::STATUS_COMPLIANT   => __('Compliant', 'grcmanager'),
        ];
    }

    public static function statusBadge(?string $value): string
    {
        $map = [
            IsmsClauseCatalog::STATUS_NOT_STARTED => ['bg-secondary-lt', 'ti-player-stop'],
            IsmsClauseCatalog::STATUS_IN_PROGRESS => ['bg-blue-lt', 'ti-tool'],
            IsmsClauseCatalog::STATUS_COMPLIANT   => ['bg-green-lt', 'ti-circle-check'],
        ];
        [$class, $icon] = $map[$value] ?? ['bg-secondary-lt', 'ti-help'];
        $label = self::getStatuses()[$value] ?? (string) $value;

        return Badge::html($class, $icon, $label);
    }

    /**
     * Statuts de tous les sous-articles, pour la barre de complétude et le rapport PDF.
     *
     * @return array{not_started: int, in_progress: int, compliant: int, total: int, percent: int}
     */
    public static function getCompletion(): array
    {
        global $DB;

        $statuses = [];
        foreach ($DB->request(['SELECT' => ['status'], 'FROM' => self::getTable()]) as $row) {
            $statuses[] = (string) $row['status'];
        }

        return IsmsClauseCatalog::completion($statuses);
    }

    /**
     * Lien automatique vers l'écran du plugin qui couvre ce sous-article, avec le nombre
     * d'enregistrements qu'il contient (null si aucun écran ne le couvre ou si l'utilisateur n'a
     * pas le droit de le consulter).
     *
     * @return array{label: string, url: string, count: int}|null
     */
    public static function getLinkedModule(string $code): ?array
    {
        $itemtype = IsmsClauseCatalog::SUBCLAUSES[$code]['module'] ?? null;
        if ($itemtype === null || !class_exists($itemtype) || !$itemtype::canView()) {
            return null;
        }

        return [
            'label' => $itemtype::getTypeName(2),
            'url'   => $itemtype::getSearchURL(),
            'count' => countElementsInTable($itemtype::getTable()),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        // Le numéro identifie le sous-article dans le catalogue fixe : jamais modifiable.
        unset($input['code']);

        if (isset($input['status']) && !in_array($input['status'], IsmsClauseCatalog::STATUSES, true)) {
            Session::addMessageAfterRedirect(__('Invalid status.', 'grcmanager'), false, ERROR);

            return false;
        }

        return $input;
    }

    /**
     * Onglet natif « Documents » : les preuves (procès-verbaux, périmètre, matrices...) sont des
     * Documents GLPI liés, comme pour PluginGrcmanagerPolicy.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'               => 1,
            'table'            => $this->getTable(),
            'field'            => 'code',
            'name'             => __('Requirement', 'grcmanager'),
            'datatype'         => 'specific',
            'additionalfields' => ['id'],
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'clause',
            'name'     => __('Article', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Statut'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Owner', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'comment',
            'name'     => __('Comment', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update', 'grcmanager'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'code':
                $code = (string) ($values[$field] ?? '');
                if ($code === '') {
                    return '';
                }
                $id = (int) ($values['id'] ?? 0);

                return Fragment::link($id > 0 ? self::getFormURLWithID($id) : '', self::getClauseTitle($code), $code);

            case 'clause':
                $clause = (string) ($values[$field] ?? '');

                return $clause === ''
                    ? ''
                    : Badge::html('bg-azure-lt', null, $clause . ' ' . self::getClauseTitle($clause));

            case 'status':
                return self::statusBadge($values[$field] ?? null);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        switch ($field) {
            case 'status':
                return Dropdown::showFromArray($name, self::getStatuses(), $options);

            case 'clause':
                $clauses = [];
                foreach (IsmsClauseCatalog::CLAUSES as $clause) {
                    $clauses[$clause] = $clause . ' ' . self::getClauseTitle($clause);
                }

                return Dropdown::showFromArray($name, $clauses, $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $code   = (string) ($this->fields['code'] ?? '');
        $clause = (string) ($this->fields['clause'] ?? '');
        TemplateRenderer::getInstance()->display('@grcmanager/isms_requirement_form.html.twig', [
            'item'           => $this,
            // Exigences du catalogue de la norme : jamais supprimées depuis la fiche.
            'params'         => $options + ['candel' => false],
            'code'           => $code,
            'code_title'     => self::getClauseTitle($code),
            'clause'         => $clause,
            'clause_title'   => self::getClauseTitle($clause),
            'statuses'       => self::getStatuses(),
            'default_status' => IsmsClauseCatalog::STATUS_NOT_STARTED,
            'module'         => self::getLinkedModule($code),
        ]);

        return true;
    }
}
