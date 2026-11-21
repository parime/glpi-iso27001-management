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
use CronTask;
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\PhysicalSecurityAssessmentBase;
use GlpiPlugin\Grcmanager\Install\PhysicalSecurityTables;
use GlpiPlugin\Grcmanager\Services\Physical\PhysicalSecurityRules as Rules;
use Html;
use Location;
use Log;
use NotificationEvent;
use Session;
use User;

/**
 * Issue #778 : évaluation de la sécurité physique d'un site (ISO 27001, mesures A.7.1 à A.7.14).
 *
 * Le site est un lieu de GLPI (Location) : pas de second référentiel des sites. Une évaluation
 * porte une ligne par mesure A.7.x (état, constat, preuves, action CAPA), les zones du site avec
 * leurs contrôles d'accès, une date, un évaluateur et l'échéance de réévaluation annuelle. La
 * politique de sécurité physique reste un document de la bibliothèque des politiques, liée ici.
 *
 * À la création, les 14 lignes sont ajoutées (« non applicable » pour une mesure exclue de la SoA
 * de l'entité) et les zones de la dernière évaluation du même site, dans la même entité, sont
 * reprises.
 *
 * Droits : droit général du plugin et entité de l'évaluation (check() du cœur), partout. Les lignes
 * et les zones se modifient avec le droit de modifier l'évaluation. Les pièces jointes (photos d'un
 * local technique) ne se lisent qu'avec le droit de lire l'évaluation (RestrictedDocument).
 */
class PhysicalSecurityAssessment extends PhysicalSecurityAssessmentBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const FK = 'plugin_grcmanager_physicalsecurityassessments_id';

    /** Événement de notification du rappel de réévaluation. */
    public const REMINDER_EVENT = 'physicalsecurity_reassessment';

    public static function getTable($classname = null)
    {
        return PhysicalSecurityTables::ASSESSMENTS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Physical security assessment', 'Physical security assessments', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-building-fortress';
    }

    public function getName($options = [])
    {
        $name = trim((string) ($this->fields['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $site = (int) ($this->fields['locations_id'] ?? 0);

        return $site > 0 ? self::siteName($site) : parent::getName($options);
    }

    public static function siteName(int $locationId): string
    {
        return $locationId > 0 ? (string) Dropdown::getDropdownName(Location::getTable(), $locationId) : '';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(self::class, $tabs, $options)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    // ---------------------------------------------------------------------------------------------
    // Saisie
    // ---------------------------------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = EntityPerimeter::activeEntity();
        }
        if (EntityPerimeter::hasSession() && !Session::haveAccessToEntity((int) $input['entities_id'])) {
            Session::addMessageAfterRedirect(
                __('You cannot create an assessment in this entity.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }
        if (!isset($input['users_id'])) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }
        if (!isset($input['assessment_date'])) {
            $input['assessment_date'] = date('Y-m-d');
        }
        unset($input['reminder_date']);

        return $this->normalizeInput($input, ['status' => Rules::STATUS_IN_PROGRESS]);
    }

    public function prepareInputForUpdate($input)
    {
        // Le site et l'entité ne changent pas : les lignes et les zones lui appartiennent.
        unset($input['entities_id'], $input['locations_id'], $input['reminder_date']);

        $input = $this->normalizeInput($input, $this->fields);
        if (
            is_array($input) && array_key_exists('next_assessment_date', $input)
            && $input['next_assessment_date'] !== ($this->fields['next_assessment_date'] ?? null)
        ) {
            // Nouvelle échéance : le prochain rappel repart de zéro.
            $input['reminder_date'] = 'NULL';
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input, array $current)
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = Rules::cleanText($input['name'], Rules::MAX_NAME_LENGTH);
        }
        if (array_key_exists('comment', $input)) {
            $input['comment'] = Rules::cleanText($input['comment']);
        }
        if (array_key_exists('status', $input)) {
            $input['status'] = Rules::normalizeStatus($input['status']);
        }
        if (array_key_exists('locations_id', $input)) {
            $input['locations_id'] = max(0, (int) $input['locations_id']);
            if (!self::siteIsVisibleFrom((int) $input['locations_id'], (int) ($input['entities_id'] ?? 0))) {
                Session::addMessageAfterRedirect(
                    __('Choose the site (a location of the entity) to assess.', 'grcmanager'),
                    false,
                    ERROR
                );

                return false;
            }
        }
        foreach (['assessment_date', 'next_assessment_date'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = Rules::normalizeDate($input[$field]) ?? 'NULL';
            }
        }
        if (array_key_exists('users_id', $input)) {
            $input['users_id'] = self::isActiveUser((int) $input['users_id']) ? (int) $input['users_id'] : 0;
        }
        if (array_key_exists('plugin_grcmanager_policies_id', $input)) {
            $policyId = max(0, (int) $input['plugin_grcmanager_policies_id']);
            $input['plugin_grcmanager_policies_id'] = isset(self::policyOptions()[$policyId]) ? $policyId : 0;
        }

        // Évaluation terminée sans échéance : réévaluation 12 mois après la date d'évaluation.
        $merged = array_merge($current, $input);
        $next = $merged['next_assessment_date'] ?? null;
        if (
            Rules::normalizeStatus($merged['status'] ?? '') === Rules::STATUS_COMPLETED
            && ($next === null || $next === 'NULL' || $next === '')
        ) {
            $default = Rules::nextAssessmentDate((string) ($merged['assessment_date'] ?? ''));
            if ($default !== null) {
                $input['next_assessment_date'] = $default;
            }
        }

        return $input;
    }

    /**
     * Le site doit être un lieu visible depuis l'entité de l'évaluation (lieu de l'entité, ou lieu
     * récursif d'une entité parente), et un lieu que la personne peut voir.
     */
    public static function siteIsVisibleFrom(int $locationId, int $entityId): bool
    {
        global $DB;

        if ($locationId <= 0) {
            return false;
        }
        $criteria = [
            'COUNT' => 'cpt',
            'FROM'  => Location::getTable(),
            'WHERE' => [
                'id' => $locationId,
                getEntitiesRestrictCriteria(Location::getTable(), '', $entityId, true),
            ],
        ];
        if (EntityPerimeter::hasSession()) {
            $criteria['WHERE'][] = getEntitiesRestrictCriteria(Location::getTable(), '', '', true);
        }

        return (int) ($DB->request($criteria)->current()['cpt'] ?? 0) > 0;
    }

    private static function isActiveUser(int $usersId): bool
    {
        $user = new User();

        return $usersId > 0 && $user->getFromDB($usersId) && (int) $user->fields['is_active'] === 1
            && (int) $user->fields['is_deleted'] === 0;
    }

    /**
     * Politiques de la bibliothèque (hors archivées), pour lier la politique de sécurité physique.
     *
     * @return array<int, string>
     */
    public static function policyOptions(): array
    {
        global $DB;

        $options = [];
        if (!Policy::canView()) {
            return $options;
        }
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => Policy::getTable(),
                'WHERE'  => ['NOT' => ['status' => 'archived']],
                'ORDER'  => ['title', 'id'],
            ]) as $row
        ) {
            $options[(int) $row['id']] = (string) $row['title'];
        }

        return $options;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        PhysicalSecurityCheck::createLines($this);
        $previous = self::previousAssessmentId($this);
        if ($previous > 0) {
            PhysicalSecurityZone::copyZones($previous, $this->getID());
        }
    }

    public function cleanDBonPurge()
    {
        PhysicalSecurityCheck::purgeAssessment($this->getID());
        PhysicalSecurityZone::purgeAssessment($this->getID());
    }

    /**
     * Évaluation précédente du même site dans la même entité (date d'évaluation puis numéro), 0 sinon.
     */
    public static function previousAssessmentId(self $assessment): int
    {
        global $DB;

        if ($assessment->isNewItem()) {
            return 0;
        }
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'locations_id' => (int) $assessment->fields['locations_id'],
                'entities_id'  => (int) $assessment->fields['entities_id'],
                ['id' => ['<', $assessment->getID()]],
            ],
            'ORDER'  => ['assessment_date DESC', 'id DESC'],
            'LIMIT'  => 1,
        ])->current();

        return $row === null ? 0 : (int) $row['id'];
    }

    // ---------------------------------------------------------------------------------------------
    // Onglets : grille A.7, zones (évaluation) ; évaluations du site (lieu de GLPI)
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::canView()) {
            return '';
        }
        if ($item instanceof self && !$item->isNewItem()) {
            $states = array_column(PhysicalSecurityCheck::getLines($item->getID()), 'state');

            return [
                1 => self::createTabEntry(
                    __('Physical security checklist', 'grcmanager'),
                    Rules::summary($states)['findings'],
                    null,
                    'ti ti-list-check'
                ),
                2 => self::createTabEntry(
                    __('Zones of the site', 'grcmanager'),
                    count(PhysicalSecurityZone::getZones($item->getID())),
                    null,
                    'ti ti-map-2'
                ),
            ];
        }
        if ($item instanceof Location && !$item->isNewItem() && $item->canViewItem()) {
            return self::createTabEntry(
                __('Physical security', 'grcmanager'),
                count(self::forSite($item->getID())),
                null,
                self::getIcon()
            );
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : mêmes contrôles.
        if (!self::canView()) {
            return false;
        }
        if ($item instanceof self) {
            if ($item->isNewItem() || !$item->can($item->getID(), READ)) {
                return false;
            }
            if ((int) $tabnum === 2) {
                PhysicalSecurityZone::showForAssessment($item);
            } else {
                PhysicalSecurityCheck::showForAssessment($item);
            }

            return true;
        }
        if ($item instanceof Location) {
            if ($item->isNewItem() || !$item->can($item->getID(), READ)) {
                return false;
            }
            self::showForSite($item);

            return true;
        }

        return false;
    }

    /**
     * Évaluations d'un site dans les entités de la session, de la plus récente à la plus ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public static function forSite(int $locationId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => array_merge(['locations_id' => $locationId], EntityPerimeter::criteria(self::getTable())),
            'ORDER' => ['assessment_date DESC', 'id DESC'],
        ]), false));
    }

    private static function showForSite(Location $location): void
    {
        $today = date('Y-m-d');
        $rows = [];
        foreach (self::forSite($location->getID()) as $row) {
            $rows[] = PhysicalSecurity::presentAssessment($row, $today);
        }
        TemplateRenderer::getInstance()->display('@grcmanager/physical_security_site_tab.html.twig', [
            'rows'       => $rows,
            'can_create' => self::canCreate(),
            'add_url'    => self::getFormURL() . '?locations_id=' . $location->getID(),
            'compare_url' => PhysicalSecurity::getPageURL(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Liste et fiche
    // ---------------------------------------------------------------------------------------------

    public static function getStatuses(): array
    {
        return [
            Rules::STATUS_IN_PROGRESS => __('In progress', 'grcmanager'),
            Rules::STATUS_COMPLETED   => _x('feminine', 'Completed', 'grcmanager'),
        ];
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => Location::getTable(), 'field' => 'completename', 'name' => __('Site', 'grcmanager'),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => $table, 'field' => 'status', 'name' => __('Status', 'grcmanager'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'assessment_date', 'name' => __('Assessment date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'next_assessment_date',
            'name' => __('Next assessment', 'grcmanager'), 'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 7, 'table' => User::getTable(), 'field' => 'name', 'name' => __('Assessor', 'grcmanager'),
            'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 8, 'table' => $table, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 19, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => Entity::getTable(), 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return htmlescape(self::getStatuses()[(string) $values[$field]] ?? (string) $values[$field]);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value'] = $values[$field] ?? '';

            return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            $this->fields['assessment_date'] = date('Y-m-d');
            $this->fields['users_id'] = (int) Session::getLoginUserID();
            $this->fields['status'] = Rules::STATUS_IN_PROGRESS;
            if ((int) ($options['locations_id'] ?? 0) > 0) {
                $this->fields['locations_id'] = (int) $options['locations_id'];
            }
        }
        $summary = null;
        $due = Rules::DUE_NONE;
        $policy = null;
        if (!$this->isNewItem()) {
            $summary = Rules::summary(array_column(PhysicalSecurityCheck::getLines($this->getID()), 'state'));
            $due = Rules::dueStatus(
                isset($this->fields['next_assessment_date']) ? (string) $this->fields['next_assessment_date'] : null,
                date('Y-m-d')
            );
            $policyId = (int) ($this->fields['plugin_grcmanager_policies_id'] ?? 0);
            $policy = $policyId > 0 && Policy::canView() ? Policy::getFormURLWithID($policyId) : null;
        }

        TemplateRenderer::getInstance()->display('@grcmanager/physical_security_assessment_form.html.twig', [
            'item'        => $this,
            'params'      => $options,
            'statuses'    => self::getStatuses(),
            'site_name'   => self::siteName((int) ($this->fields['locations_id'] ?? 0)),
            'policies'    => [0 => Dropdown::EMPTY_VALUE] + self::policyOptions(),
            'policy_url'  => $policy,
            'summary'     => $summary,
            'due'         => $due,
            'due_labels'  => PhysicalSecurity::dueLabels(),
            'compare_url' => PhysicalSecurity::getPageURL(),
            'pdf_url'     => self::pdfURL($this->getID()),
            'months'      => Rules::REASSESSMENT_MONTHS,
        ]);

        return true;
    }

    // ---------------------------------------------------------------------------------------------
    // Rappel de réévaluation annuelle
    // ---------------------------------------------------------------------------------------------

    public static function cronInfo(string $name): array
    {
        return [
            'description' => __(
                'Reminds the assessor of the yearly physical security reassessment of each site',
                'grcmanager'
            ),
        ];
    }

    /**
     * Tâche automatique : pour la dernière évaluation de chaque site, prévient l'évaluateur 30 jours
     * avant l'échéance de réévaluation, puis au plus une fois par mois tant qu'aucune nouvelle
     * évaluation n'est faite. Toutes les entités (pas de session).
     */
    public static function cronReassessmentreminder(CronTask $task): int
    {
        $result = self::sendReminders(date('Y-m-d'));
        $task->addVolume($result['notified']);
        $task->log(sprintf(
            __('%1$d site(s) to reassess, %2$d reminder(s) sent.', 'grcmanager'),
            $result['due'],
            $result['notified']
        ));

        return $result['due'] > 0 ? 1 : 0;
    }

    /**
     * @return array{due: int, notified: int}
     */
    public static function sendReminders(string $today): array
    {
        global $DB;

        $due = 0;
        $notified = 0;
        foreach (PhysicalSecurity::latestAssessments([]) as $row) {
            $next = isset($row['next_assessment_date']) ? (string) $row['next_assessment_date'] : null;
            if (!in_array(Rules::dueStatus($next, $today), [Rules::DUE_SOON, Rules::DUE_OVERDUE], true)) {
                continue;
            }
            $due++;
            $last = isset($row['reminder_date']) ? (string) $row['reminder_date'] : null;
            if (!Rules::shouldRemind($next, $last, $today) || (int) $row['users_id'] <= 0) {
                continue;
            }
            $assessment = new self();
            if (!$assessment->getFromDB((int) $row['id'])) {
                continue;
            }
            if (NotificationEvent::raiseEvent(self::REMINDER_EVENT, $assessment)) {
                $notified++;
            }
            // Un rappel par mois au plus, envoyé ou non (aucune notification active).
            $DB->update(
                self::getTable(),
                ['reminder_date' => $today . ' ' . date('H:i:s')],
                ['id' => (int) $row['id']]
            );
        }

        return ['due' => $due, 'notified' => $notified];
    }

    /** Échéance lisible (notification). */
    public function nextAssessmentLabel(): string
    {
        $next = $this->fields['next_assessment_date'] ?? null;

        return empty($next) ? '' : (string) Html::convDate((string) $next);
    }

    // ---------------------------------------------------------------------------------------------
    // Rapport PDF
    // ---------------------------------------------------------------------------------------------

    /**
     * Rapport de l'évaluation d'un site pour l'auditeur (issue #778, lot 2) : en-tête, score, grille
     * A.7.1 à A.7.14 avec constats, preuves et actions, puis les zones et leurs contrôles d'accès.
     * Calculé à la demande avec les droits de la personne (l'appelant a fait check(READ)), rien
     * n'est conservé.
     */
    public static function pdfURL(int $assessmentId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/physicalsecurityassessment.pdf.php?id=' . $assessmentId;
    }

    public static function renderPdf(self $assessment): string
    {
        $rows = PhysicalSecurityCheck::present($assessment);
        $policyId = (int) ($assessment->fields['plugin_grcmanager_policies_id'] ?? 0);
        $policies = self::policyOptions();
        $assessor = (int) ($assessment->fields['users_id'] ?? 0);
        $statuses = self::getStatuses();
        $status = Rules::normalizeStatus($assessment->fields['status'] ?? '');
        $next = $assessment->fields['next_assessment_date'] ?? null;

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/physical_security_report.html.twig', [
            'report_title'       => sprintf(
                __('Physical security of the site: %s', 'grcmanager'),
                self::siteName((int) ($assessment->fields['locations_id'] ?? 0)) ?: $assessment->getName()
            ),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'perimeter'          => EntityPerimeter::isMultiEntity()
                ? Dropdown::getDropdownName('glpi_entities', (int) $assessment->fields['entities_id'])
                : '',
            'name'               => (string) ($assessment->fields['name'] ?? ''),
            'assessment_date'    => empty($assessment->fields['assessment_date'])
                ? '' : (string) Html::convDate((string) $assessment->fields['assessment_date']),
            'assessor'           => $assessor > 0 ? getUserName($assessor) : '',
            'status'             => $statuses[$status] ?? '',
            'next_date'          => empty($next) ? '' : (string) Html::convDate((string) $next),
            'policy'             => $policies[$policyId] ?? '',
            'comment'            => (string) ($assessment->fields['comment'] ?? ''),
            'summary'            => Rules::summary(array_column($rows, 'state')),
            'states'             => PhysicalSecurity::stateBadges(),
            'rows'               => $rows,
            'zones'              => PhysicalSecurityZone::present($assessment->getID()),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
        ]);
    }
}
