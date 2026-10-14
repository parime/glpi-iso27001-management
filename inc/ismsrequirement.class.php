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

use GlpiPlugin\Grcmanager\Compatibility\Base\IsmsRequirementBase;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;

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
        return _n('Exigence SMSI (articles 4 à 10)', 'Exigences SMSI (articles 4 à 10)', $nb, 'grcmanager');
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
     *
     * @return array<string, string>
     */
    public static function getClauseTitles(): array
    {
        return [
            '4'     => __('Contexte de l\'organisation', 'grcmanager'),
            '4.1'   => __('Organisation et son contexte', 'grcmanager'),
            '4.2'   => __('Parties intéressées', 'grcmanager'),
            '4.3'   => __('Domaine d\'application du SMSI', 'grcmanager'),
            '4.4'   => __('Système de management de la sécurité de l\'information', 'grcmanager'),
            '5'     => __('Leadership', 'grcmanager'),
            '5.1'   => __('Leadership et engagement', 'grcmanager'),
            '5.2'   => __('Politique', 'grcmanager'),
            '5.3'   => __('Rôles, responsabilités et autorités', 'grcmanager'),
            '6'     => __('Planification', 'grcmanager'),
            '6.1.1' => __('Risques et opportunités : généralités', 'grcmanager'),
            '6.1.2' => __('Appréciation des risques', 'grcmanager'),
            '6.1.3' => __('Traitement des risques', 'grcmanager'),
            '6.2'   => __('Objectifs de sécurité de l\'information', 'grcmanager'),
            '6.3'   => __('Planification des modifications', 'grcmanager'),
            '7'     => __('Support', 'grcmanager'),
            '7.1'   => __('Ressources', 'grcmanager'),
            '7.2'   => __('Compétences', 'grcmanager'),
            '7.3'   => __('Sensibilisation', 'grcmanager'),
            '7.4'   => __('Communication', 'grcmanager'),
            '7.5.1' => __('Informations documentées : généralités', 'grcmanager'),
            '7.5.2' => __('Création et mise à jour', 'grcmanager'),
            '7.5.3' => __('Maîtrise des informations documentées', 'grcmanager'),
            '8'     => __('Fonctionnement', 'grcmanager'),
            '8.1'   => __('Planification et maîtrise opérationnelles', 'grcmanager'),
            '8.2'   => __('Appréciation des risques (réalisation)', 'grcmanager'),
            '8.3'   => __('Traitement des risques (mise en œuvre)', 'grcmanager'),
            '9'     => __('Évaluation des performances', 'grcmanager'),
            '9.1'   => __('Surveillance, mesure, analyse et évaluation', 'grcmanager'),
            '9.2.1' => __('Audit interne : généralités', 'grcmanager'),
            '9.2.2' => __('Programme d\'audit interne', 'grcmanager'),
            '9.3.1' => __('Revue de direction : généralités', 'grcmanager'),
            '9.3.2' => __('Éléments d\'entrée de la revue de direction', 'grcmanager'),
            '9.3.3' => __('Résultats de la revue de direction', 'grcmanager'),
            '10'    => __('Amélioration', 'grcmanager'),
            '10.1'  => __('Amélioration continue', 'grcmanager'),
            '10.2'  => __('Non-conformité et action corrective', 'grcmanager'),
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
            IsmsClauseCatalog::STATUS_NOT_STARTED => __('Non commencé', 'grcmanager'),
            IsmsClauseCatalog::STATUS_IN_PROGRESS => __('En cours', 'grcmanager'),
            IsmsClauseCatalog::STATUS_COMPLIANT   => __('Conforme', 'grcmanager'),
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

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
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
            Session::addMessageAfterRedirect(__('Statut invalide.', 'grcmanager'), false, ERROR);

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
            'name'             => __('Exigence', 'grcmanager'),
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
            'name'     => __('Responsable', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'comment',
            'name'     => __('Commentaire', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'date_mod',
            'name'     => __('Dernière modification', 'grcmanager'),
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
                $label = '<strong>' . htmlescape($code) . '</strong> - ' . htmlescape(self::getClauseTitle($code));
                $id    = (int) ($values['id'] ?? 0);

                return $id > 0
                    ? '<a href="' . htmlescape(self::getFormURLWithID($id)) . '">' . $label . '</a>'
                    : $label;

            case 'clause':
                $clause = (string) ($values[$field] ?? '');

                return $clause === ''
                    ? ''
                    : '<span class="badge bg-azure-lt">' . htmlescape($clause . ' ' . self::getClauseTitle($clause))
                        . '</span>';

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
        $this->showFormHeader($options);

        $code   = (string) ($this->fields['code'] ?? '');
        $clause = (string) ($this->fields['clause'] ?? '');

        echo '<tr class="tab_bg_1"><td>' . __('Exigence', 'grcmanager') . '</td>';
        echo '<td><strong>' . htmlescape($code) . '</strong> - ' . htmlescape(self::getClauseTitle($code)) . '</td>';
        echo '<td>' . __('Article', 'grcmanager') . '</td>';
        echo '<td><span class="badge bg-azure-lt">' . htmlescape($clause . ' ' . self::getClauseTitle($clause))
            . '</span></td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Statut') . '</td><td>';
        Dropdown::showFromArray('status', self::getStatuses(), [
            'value' => $this->fields['status'] ?? IsmsClauseCatalog::STATUS_NOT_STARTED,
        ]);
        echo '</td>';
        echo '<td>' . __('Responsable', 'grcmanager') . '</td><td>';
        User::dropdown([
            'name'  => 'users_id',
            'value' => $this->fields['users_id'] ?? 0,
            'right' => 'all',
        ]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Commentaire', 'grcmanager') . '</td>';
        echo '<td colspan="3"><textarea name="comment" class="form-control" rows="4">'
            . htmlescape($this->fields['comment'] ?? '') . '</textarea>';
        echo '<small class="form-hint">' . __(
            'Comment l\'exigence est satisfaite, où trouver les preuves. '
                . 'Joignez les documents dans l\'onglet Documents.',
            'grcmanager'
        ) . '</small></td></tr>';

        $module = self::getLinkedModule($code);
        if ($module !== null) {
            echo '<tr class="tab_bg_1"><td><i class="ti ti-link me-1"></i>' . __('Module lié', 'grcmanager') . '</td>';
            echo '<td colspan="3"><a href="' . htmlescape($module['url']) . '">'
                . htmlescape($module['label']) . '</a> ';
            echo '<span class="badge bg-blue-lt">'
                . htmlescape(sprintf(__('%d enregistrement(s)', 'grcmanager'), $module['count'])) . '</span>';
            echo '<small class="form-hint d-block mt-1">' . __(
                'Ce module du plugin produit déjà des preuves pour cette exigence.',
                'grcmanager'
            ) . '</small></td></tr>';
        }

        $this->showFormButtons($options + ['candel' => false]);

        return true;
    }
}
