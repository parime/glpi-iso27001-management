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

use CronTask;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\ComplianceObligationBase;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceObligationRules;
use GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
use GlpiPlugin\Grcmanager\Risk;

/**
 * Registre des obligations légales, réglementaires et contractuelles (issue #30, ISO 27001 clause
 * 4.2 "parties intéressées et leurs exigences", Annexe A A.5.31-36). Ces contrôles n'existaient
 * jusqu'ici que comme lignes à cocher dans la Déclaration d'Applicabilité
 * (PluginGrcmanagerControl, voir A.5.31/A.5.34) : ce registre couvre le besoin réel qu'un
 * RSSI/DPO finit souvent par gérer dans un tableur séparé - la liste concrète des obligations
 * qui s'appliquent (RGPD, contrat client avec clause de sécurité, réglementation sectorielle...)
 * et le suivi de leur statut de conformité dans le temps, indépendamment de la SoA.
 *
 * Mêmes conventions que les autres registres de ce plugin : enums en varchar avec commentaire SQL
 * (voir src/Install/Installer.php), badges colorés traduits (voir
 * PluginGrcmanagerControl::applicabilityBadge()/Nonconformity::severityBadge()),
 * showForm() en HTML/PHP manuel (TECH_DEBT.md, choix assumé depuis le Sprint 1), rappel de revue
 * via le même Cron/NotificationTarget que le registre de risques (voir cronReviewreminder()
 * ci-dessous).
 *
 * Lien optionnel vers un risque (0 ou 1, jamais plus) : `plugin_grcmanager_risks_id` est une
 * colonne directe sur cette table, PAS une table de liaison many-to-many comme
 * glpi_plugin_grcmanager_controls_risks (voir PluginGrcmanagerControl::getLinkedRisks()/
 * syncLinkedRisks(), TECH_DEBT.md Sprint 3, "simples méthodes statiques, pas une vraie classe
 * CommonDBRelation") : la cardinalité demandée par l'issue #30 ("une obligation correspond au
 * plus à une entrée de risque précise, si tant est qu'il y en ait une") est plus simple que celle
 * de l'issue #25 (un risque <-> plusieurs actifs CMDB), donc pas de table de liaison séparée,
 * exactement le même choix déjà fait pour `users_id` (propriétaire) ailleurs dans ce plugin.
 * Voir GlpiPlugin\Grcmanager\Services\Compliance\ComplianceObligationRules pour la logique pure
 * (normalisation type/statut, zéro-ou-un lien, fenêtre de rappel de revue), testée en isolation.
 */
class ComplianceObligation extends ComplianceObligationBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /**
     * GLPI notification event name (see inc/notificationtargetcomplianceobligation.class.php and
     * cronReviewreminder() ci-dessous), même convention de source unique que
     * Risk::REVIEW_DUE_EVENT (même chaîne d'événement 'review_due' que le registre
     * de risques et le registre de risques fournisseurs : GLPI distingue les destinataires par la
     * classe de l'objet notifié via NotificationEvent::raiseEvent(), pas par une chaîne
     * d'événement unique par itemtype, voir ReviewReminderService).
     */
    public const REVIEW_DUE_EVENT = 'review_due';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_complianceobligations';
    }

    public static function getTypeName($nb = 0)
    {
        return _n(
            'Legal, regulatory or contractual obligation',
            'Legal, regulatory and contractual obligations',
            $nb,
            'grcmanager'
        );
    }

    public static function getIcon()
    {
        return 'ti ti-gavel';
    }

    /**
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            'legal'       => __('Legal', 'grcmanager'),
            'regulatory'  => __('Regulatory', 'grcmanager'),
            'contractual' => __('Contractual', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getComplianceStatuses(): array
    {
        return [
            'compliant'           => __('Compliant', 'grcmanager'),
            'partially_compliant' => __('Partially compliant', 'grcmanager'),
            'non_compliant'       => __('Non-compliant', 'grcmanager'),
            'not_assessed'        => __('Not assessed', 'grcmanager'),
        ];
    }

    /**
     * Normalise `type`/`compliance_status` via ComplianceObligationRules (jamais une valeur hors
     * énumération enregistrée en base) avant add()/update(), même endroit que
     * PluginGrcmanagerControl::validateAndMarkReviewed()/Nonconformity::
     * validateAndNormalize() pour la validation d'entrée de ce plugin.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepareInputForAdd($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepareInputForUpdate($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        if (array_key_exists('type', $input)) {
            $input['type'] = ComplianceObligationRules::normalizeType($input['type']);
        }

        if (array_key_exists('compliance_status', $input)) {
            $input['compliance_status'] = ComplianceObligationRules::normalizeComplianceStatus(
                $input['compliance_status']
            );
        }

        // Registre sensible : un formulaire forgé sans son droit ne modifie pas le risque lié.
        if (array_key_exists('plugin_grcmanager_risks_id', $input) && !Risk::canView()) {
            unset($input['plugin_grcmanager_risks_id']);
        }
        if (array_key_exists('plugin_grcmanager_risks_id', $input)) {
            $input['plugin_grcmanager_risks_id'] = ComplianceObligationRules::normalizeLinkedRiskId(
                $input['plugin_grcmanager_risks_id']
            );
        }

        return $input;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'       => 1,
            'table'    => $this->getTable(),
            'field'    => 'title',
            'name'     => __('Title', 'grcmanager'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'type',
            'name'     => __('Type', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'reference_source',
            'name'     => __('Reference / source', 'grcmanager'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'compliance_status',
            'name'     => __('Compliance status', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'review_date',
            'name'     => __('Review date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'plugin_grcmanager_risks_id',
            'name'     => Risk::getTypeName(1),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description / notes', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family, see Risk::rawSearchOptions().
        $tab[] = [
            'id'       => 9,
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
            case 'type':
                return self::typeBadge($values[$field] ?? null);

            case 'compliance_status':
                return self::complianceStatusBadge($values[$field] ?? null);

            case 'plugin_grcmanager_risks_id':
                return self::riskLink((int) ($values[$field] ?? 0));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widgets for type/compliance_status, same lesson as
     * Risk::getSpecificValueToSelect(). `plugin_grcmanager_risks_id` is left to
     * the parent's default (free-text search on the raw numeric id), same choice already made for
     * `plugin_grcmanager_audits_id` on Nonconformity (see its own
     * getSpecificValueToSelect(), TECH_DEBT.md Sprint 4).
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        switch ($field) {
            case 'type':
                return Dropdown::showFromArray($name, self::getTypes(), $options);

            case 'compliance_status':
                return Dropdown::showFromArray($name, self::getComplianceStatuses(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function typeBadge(?string $value): string
    {
        $map = [
            'legal'       => ['bg-indigo-lt', 'ti-gavel', __('Legal', 'grcmanager')],
            'regulatory'  => ['bg-blue-lt', 'ti-building-bank', __('Regulatory', 'grcmanager')],
            'contractual' => ['bg-teal-lt', 'ti-file-text', __('Contractual', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function complianceStatusBadge(?string $value): string
    {
        $map = [
            'compliant'           => ['bg-green-lt', 'ti-circle-check', __('Compliant', 'grcmanager')],
            'partially_compliant' => ['bg-yellow-lt', 'ti-circle-half-2', __('Partially compliant', 'grcmanager')],
            'non_compliant'       => ['bg-red-lt', 'ti-circle-x', __('Non-compliant', 'grcmanager')],
            'not_assessed'        => ['bg-secondary-lt', 'ti-help', __('Not assessed', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function riskLink(int $riskId): string
    {
        // Registre sensible : le titre du risque lié n'apparaît pas dans la liste sans son droit.
        if (!ComplianceObligationRules::isLinkedToRisk($riskId) || !Risk::canView()) {
            return '';
        }

        $risk = new Risk();
        if (!$risk->getFromDB($riskId)) {
            return '';
        }

        return Fragment::link($risk->getFormURLWithID($riskId), (string) $risk->fields['title']);
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB;

        $this->initForm($ID, $options);

        // Lien optionnel zéro-ou-un vers un risque du registre (issue #30 : "si le non-respect de
        // cette obligation constitue un risque identifié"), colonne directe (voir docblock de
        // classe) avec une option "Aucun" (id 0).
        // Registre sensible : titres des risques seulement avec son droit ; sans le champ, le lien
        // existant n'est pas modifié à l'enregistrement.
        $canViewRisks = Risk::canView();
        $riskTitles = [0 => Dropdown::EMPTY_VALUE];
        if ($canViewRisks) {
            foreach ($DB->request(['SELECT' => ['id', 'title'], 'FROM' => Risk::getTable()]) as $row) {
                $riskTitles[(int) $row['id']] = $row['title'];
            }
        }

        TemplateRenderer::getInstance()->display('@grcmanager/compliance_obligation_form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'types'          => self::getTypes(),
            'statuses'       => self::getComplianceStatuses(),
            'risks'          => $riskTitles,
            'can_view_risks' => $canViewRisks,
            'default_type'   => ComplianceObligationRules::DEFAULT_TYPE,
            'default_status' => ComplianceObligationRules::DEFAULT_STATUS,
        ]);

        return true;
    }

    /**
     * GLPI Cron entry point, même structure que Risk::cronReviewreminder() /
     * SupplierRisk::cronReviewreminder() (voir src/Install/Installer.php pour
     * l'enregistrement CronTask::Register()). Réutilise ReviewReminderService (généralisé par
     * cette issue avec un second argument optionnel $excludeCriteria) sans exclusion de statut :
     * contrairement à `Risk::status` (qui exclut 'closed'), `compliance_status` ne
     * décrit pas si l'obligation elle-même est encore suivie, seulement son niveau de conformité -
     * même une obligation `compliant` reste due à sa date de revue.
     *
     * @return int 0 si aucune obligation n'était due, 1 sinon
     */
    public static function cronReviewreminder(CronTask $task): int
    {
        $result = (new ReviewReminderService(self::class, []))->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            '%d obligation(s) en attente de revue, %d notification(s) déclenchée(s).',
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
