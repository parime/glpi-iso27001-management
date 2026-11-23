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
use CronTask;
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\NonconformityBase;
use GlpiPlugin\Grcmanager\Services\Audit\BodyResponseRules;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules;
use GlpiPlugin\Grcmanager\Services\Capa\CapaRequirementService;
use GlpiPlugin\Grcmanager\Services\Capa\CveCapaRules;
use GlpiPlugin\Grcmanager\Services\Capa\GapStockRules;
use GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService;
use GlpiPlugin\Grcmanager\Services\Cve\InventoryCveMatcher;
use GlpiPlugin\Grcmanager\Services\Cve\NvdCveEnrichmentService;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
use Html;
use Log;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * A finding (non-conformity or observation) raised during an internal audit
 * (Audit), carrying the full corrective/preventive action (CAPA) workflow ISO
 * 27001 clause 10.2 requires: root cause, corrective action, preventive action, a responsible
 * owner, a due date, and a closure verification date once the action is confirmed effective.
 *
 * `finding_type` and `severity` are two independent axes, matching ISO 19011 audit vocabulary
 * (issue #27, résout TECH_DEBT.md Sprint 4) : `finding_type` distinguishes a genuine non-conformity
 * (a real gap against a requirement, CAPA mandatory to close/verify, see
 * CapaRequirementService::isCapaMandatory()) from a lesser observation/remark (CAPA optional,
 * still trackable through the exact same workflow), while `severity` (minor/major/critical) still
 * grades either one: a single overlapping ordinal scale was kept for these two axes in the first
 * version, this is what issue #27 resolves.
 */
class Nonconformity extends NonconformityBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /**
     * GLPI notification event name (see inc/notificationtargetnonconformity.class.php and
     * src/Services/Capa/OverdueCapaService.php), same single-source-of-truth pattern as
     * Risk::REVIEW_DUE_EVENT.
     */
    public const CAPA_OVERDUE_EVENT = 'capa_overdue';

    /**
     * A non-conformity counts as "closed" (no longer overdue-eligible) once it reaches either of
     * these statuses, shared here so the dashboard card, the overdue Cron and the form validation
     * below never drift apart on the definition.
     *
     * @var array<int, string>
     */
    public const CLOSED_STATUSES = ['closed', 'verified'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_nonconformities';
    }

    /**
     * Le titre tient lieu de nom : sans cela, l'en-tête de la fiche et les listes déroulantes
     * affichaient « N/A » (même correctif que Policy::getNameField()).
     */
    public static function getNameField()
    {
        return 'title';
    }

    /** Issue #1038 : entrée de la rubrique « Non-conformités et amélioration » (src/Navigation/Section/). */
    public static function getMenuContent()
    {
        return Menu::withSubPages(self::class, parent::getMenuContent());
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Non-conformity', 'Non-conformities', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-alert-hexagon';
    }

    /**
     * @return array<string, string>
     */
    public static function getSeverities(): array
    {
        return [
            'minor'    => __('Minor', 'grcmanager'),
            'major'    => __('Major', 'grcmanager'),
            'critical' => __('Critical', 'grcmanager'),
        ];
    }

    /**
     * ISO 19011 vocabulary (issue #27, see class docblock): a genuine non-conformity vs. a lesser
     * observation/remark, independent from the `severity` axis.
     *
     * @return array<string, string>
     */
    public static function getFindingTypes(): array
    {
        return [
            // Reuses the existing entity type name translation rather than a second, colliding
            // gettext entry for the exact same singular French string "Non-conformité" (already
            // registered as a plural-form msgid via getTypeName()/_n()).
            'nonconformity' => self::getTypeName(1),
            'observation'   => __('Observation / remark', 'grcmanager'),
            // Issue #772 : vocabulaire de l'organisme de certification, CAPA facultatif.
            CertificationAuditRules::FINDING_TYPE_SENSITIVE_POINT => __('Area of concern', 'grcmanager'),
            CertificationAuditRules::FINDING_TYPE_IMPROVEMENT     => __('Opportunity for improvement', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'open'        => __('Open', 'grcmanager'),
            'in_progress' => __('In treatment', 'grcmanager'),
            'closed'      => __('Closed', 'grcmanager'),
            'verified'    => _x('feminine', 'Verified', 'grcmanager'),
        ];
    }

    /**
     * Enforces ISO 27001 clause 10.2 in practice: a genuine non-conformity (`finding_type` =
     * 'nonconformity', see CapaRequirementService) cannot be marked closed or verified without a
     * documented corrective action ; a mere observation is exempt from this (issue #27), a
     * corrective action stays optional for it even at closure/verification. Reaching "verified"
     * without an explicit closure verification date auto-stamps it with today rather than silently
     * leaving it blank (same "auto-populate a real date rather than force an extra manual step"
     * choice as Audit's own `actual_date`).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $input = $this->assignEntity($input, true);

        return $input === false ? false : $this->validateAndNormalize($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        $input = $this->assignEntity($input, false);

        return $input === false ? false : $this->validateAndNormalize($input);
    }

    /**
     * Issue #536 : une non-conformité appartient à l'entité de son audit ; sans audit, à celle de
     * l'incident de sécurité à son origine, sinon à l'entité choisie (par défaut l'entité active).
     * L'audit doit être lisible, l'entité dans la session ; une modification en masse vers une
     * entité hors de la session est ignorée. Ni statut, ni gravité, ni responsable ne changent.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function assignEntity(array $input, bool $isNew)
    {
        $session = EntityPerimeter::hasSession();
        $auditId = (int) ($input['plugin_grcmanager_audits_id'] ?? 0);
        $auditChanged = array_key_exists('plugin_grcmanager_audits_id', $input)
            && ($isNew || $auditId !== (int) ($this->fields['plugin_grcmanager_audits_id'] ?? 0));

        if ($auditChanged && $auditId > 0) {
            $audit = new Audit();
            if (!$audit->getFromDB($auditId) || ($session && !$audit->can($auditId, READ))) {
                Session::addMessageAfterRedirect(__('Audit not found.', 'grcmanager'), false, ERROR);

                return false;
            }
            $input['entities_id']  = (int) $audit->fields['entities_id'];
            $input['is_recursive'] = (int) $audit->fields['is_recursive'];
        } elseif ($isNew) {
            $incident = new PluginGrcmanagerSecurityIncident();
            $incidentId = (int) ($input['plugin_grcmanager_securityincidents_id'] ?? 0);
            if ($incidentId > 0 && $incident->getFromDB($incidentId)) {
                $input['entities_id'] = (int) $incident->fields['entities_id'];
            }
            $input['entities_id']  = (int) ($input['entities_id'] ?? EntityPerimeter::activeEntity());
            $input['is_recursive'] = (int) (bool) ($input['is_recursive'] ?? 0);
        }

        if (!$session || !isset($input['entities_id']) || Session::haveAccessToEntity((int) $input['entities_id'])) {
            return $input;
        }
        if ($isNew) {
            Session::addMessageAfterRedirect(
                __('You cannot create a non-conformity in this entity.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }
        unset($input['entities_id'], $input['is_recursive']);

        return $input;
    }

    /**
     * Issue #606 : constat enregistré depuis une ligne de la grille d'audit : la ligne y est liée.
     */
    public function post_addItem()
    {
        parent::post_addItem();
        $lineId = (int) ($this->input['_grcmanager_checklistitem'] ?? 0);
        if ($lineId > 0) {
            AuditChecklist::attachFinding($lineId, $this);
        }
        // Issue #778 : action enregistrée depuis une ligne de l'évaluation de la sécurité physique.
        $physicalLineId = (int) ($this->input['_grcmanager_physicalsecuritycheck'] ?? 0);
        if ($physicalLineId > 0) {
            PhysicalSecurityCheck::attachFinding($physicalLineId, $this);
        }
        // Issue #603 : action enregistrée depuis un écart de maturité d'une mesure.
        $maturityLineId = (int) ($this->input['_grcmanager_maturityassessment'] ?? 0);
        if ($maturityLineId > 0) {
            MaturityAssessment::attachFinding($maturityLineId, $this);
        }
        // Issue #835 : action enregistrée depuis une règle de l'audit de sécurité de l'AD.
        $adRuleId = (int) ($this->input['_grcmanager_adauditrule'] ?? 0);
        if ($adRuleId > 0) {
            AdSecurityAudit::attachFinding($adRuleId, $this);
        }
    }

    /** Issue #606 : constat purgé, les lignes de grille qui le citaient restent sans lien. */
    public function cleanDBonPurge()
    {
        AuditChecklist::unlinkFinding($this->getID());
        PhysicalSecurityCheck::unlinkFinding($this->getID());
        MaturityAssessment::unlinkFinding($this->getID());
        AdSecurityAudit::unlinkFinding($this->getID());
    }

    /**
     * Issue #536 : quand un audit change d'entité, ses constats restés dans l'ancienne le suivent
     * (entité seulement).
     */
    public static function followAudit(int $auditId, int $fromEntity, int $toEntity): void
    {
        global $DB;

        $DB->update(self::getTable(), ['entities_id' => $toEntity], [
            'plugin_grcmanager_audits_id' => $auditId,
            'entities_id'                 => $fromEntity,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateAndNormalize(array $input)
    {
        // Issue #772 : constat d'un audit de certification saisi avec le vocabulaire de l'organisme
        // (NC majeure ou mineure, point sensible, piste de progrès), enregistré sur les deux axes
        // existants ; valeur inconnue ignorée.
        $category = CertificationAuditRules::fieldsFor((string) ($input['_certification_category'] ?? ''));
        if ($category !== null) {
            $input = array_merge($input, $category);
        }
        foreach (['body_reference', 'audited_process', 'requirement_reference'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = mb_substr(trim((string) $input[$field]), 0, 255);
            }
        }
        if (array_key_exists('locations_id', $input)) {
            $input['locations_id'] = max(0, (int) $input['locations_id']);
        }
        // Issue #772, lot 3 : réponse à l'organisme ; date vide ou invalide : aucune, décision
        // inconnue : en attente. Rien d'autre n'est modifié (statut, responsable, échéance).
        foreach (['body_response_date', 'body_evidence_date'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = BodyResponseRules::normalizeDate($input[$field]);
            }
        }
        if (array_key_exists('body_decision', $input)) {
            $input['body_decision'] = BodyResponseRules::normalizeDecision((string) $input['body_decision']);
        }

        $status           = (string) ($input['status'] ?? ($this->fields['status'] ?? 'open'));
        $findingType       = (string) (
            $input['finding_type'] ?? ($this->fields['finding_type'] ?? 'nonconformity')
        );
        $correctiveAction  = trim((string) (
            $input['corrective_action'] ?? ($this->fields['corrective_action'] ?? '')
        ));

        $capaMandatory = CapaRequirementService::isCapaMandatory($findingType);

        if ($capaMandatory && in_array($status, self::CLOSED_STATUSES, true) && $correctiveAction === '') {
            Session::addMessageAfterRedirect(
                __('A corrective action is required to close or verify a non-conformity.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        // Issue #163 : origine incident/CVE, jamais une valeur libre.
        if (isset($input['plugin_grcmanager_securityincidents_id'])) {
            $input['plugin_grcmanager_securityincidents_id'] = max(
                0,
                (int) $input['plugin_grcmanager_securityincidents_id']
            );
        }
        if (isset($input['cve_id'])) {
            $cve = strtoupper(trim((string) $input['cve_id']));
            $input['cve_id'] = preg_match('/^CVE-\d{4}-\d{4,}$/', $cve) === 1 ? $cve : '';
        }

        $verificationDate = $input['closure_verification_date']
            ?? ($this->fields['closure_verification_date'] ?? null);

        if ($status === 'verified' && empty($verificationDate)) {
            $input['closure_verification_date'] = date('Y-m-d');
        }

        // Issue #870 : date de clôture (délai moyen du rapport du stock d'écarts), jamais le statut.
        $stamp = GapStockRules::closureStamp(
            (string) ($this->fields['status'] ?? ''),
            $status,
            isset($this->fields['date_closed']) ? (string) $this->fields['date_closed'] : null,
            self::CLOSED_STATUSES,
            date('Y-m-d H:i:s')
        );
        if ($stamp['set']) {
            $input['date_closed'] = $stamp['value'];
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

        // id 13: fresh id (max pre-existing id in this list was 12), issue #27, never reuse an id
        // already assigned above/below, saved searches and glpi_displaypreferences persist them.
        $tab[] = [
            'id'       => 13,
            'table'    => $this->getTable(),
            'field'    => 'finding_type',
            'name'     => __('Finding type', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'severity',
            'name'     => __('Severity', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status', 'grcmanager'),
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
            'field'    => 'due_date',
            'name'     => __('Due date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'closure_verification_date',
            'name'     => __('Closure verification date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'plugin_grcmanager_audits_id',
            'name'     => Audit::getTypeName(1),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'root_cause',
            'name'     => __('Root cause', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'corrective_action',
            'name'     => __('Corrective action', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 11,
            'table'    => $this->getTable(),
            'field'    => 'preventive_action',
            'name'     => __('Preventive action', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family.
        $tab[] = [
            'id'       => 12,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        // Issue #772 : références des constats de l'organisme de certification (identifiants 30 à 33).
        $tab[] = [
            'id'       => 30,
            'table'    => $this->getTable(),
            'field'    => 'body_reference',
            'name'     => __('Certification body reference', 'grcmanager'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 31,
            'table'    => $this->getTable(),
            'field'    => 'audited_process',
            'name'     => __('Audited process', 'grcmanager'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 32,
            'table'    => $this->getTable(),
            'field'    => 'requirement_reference',
            'name'     => __('Clause or control', 'grcmanager'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 33,
            'table'    => 'glpi_locations',
            'field'    => 'completename',
            'name'     => __('Site', 'grcmanager'),
            'datatype' => 'dropdown',
        ];
        // Issue #772, lot 3 : réponse à l'organisme (identifiants 34 à 37).
        $tab[] = [
            'id'       => 34,
            'table'    => $this->getTable(),
            'field'    => 'body_response_date',
            'name'     => __('Action plan sent to the certification body on', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => 35,
            'table'    => $this->getTable(),
            'field'    => 'body_evidence_date',
            'name'     => __('Evidence sent to the certification body on', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => 36,
            'table'    => $this->getTable(),
            'field'    => 'body_decision',
            'name'     => __('Decision of the certification body', 'grcmanager'),
            'datatype' => 'specific',
        ];
        $tab[] = [
            'id'       => 37,
            'table'    => $this->getTable(),
            'field'    => 'correction',
            'name'     => __('Correction', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Issue #536 : périmètre de la non-conformité (entité GLPI) et visibilité dans les
        // sous-entités.
        $tab[] = [
            'id'       => 80,
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => Entity::getTypeName(1),
            'datatype' => 'dropdown',
            // Modification en masse permise : ranger les non-conformités migrées dans leurs entités.
            'massiveaction' => true,
        ];
        $tab[] = [
            'id'       => 86,
            'table'    => $this->getTable(),
            'field'    => 'is_recursive',
            'name'     => __('Child entities'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'finding_type':
                return self::findingTypeBadge($values[$field] ?? null);

            case 'severity':
                return self::severityBadge($values[$field] ?? null);

            case 'status':
                return self::statusBadge($values[$field] ?? null);

            case 'plugin_grcmanager_audits_id':
                return self::auditLink((int) ($values[$field] ?? 0));

            case 'body_decision':
                return CertificationResponse::decisionBadge((string) ($values[$field] ?? ''));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widgets for severity/status, same lesson as
     * Risk::getSpecificValueToSelect(). `plugin_grcmanager_audits_id` is left to
     * the parent's default (free-text search on the raw numeric ID): looking it up through a real
     * GLPI dropdown join was judged not worth the added complexity for a first version, see
     * TECH_DEBT.md Sprint 4.
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
            case 'finding_type':
                return Dropdown::showFromArray($name, self::getFindingTypes(), $options);

            case 'severity':
                return Dropdown::showFromArray($name, self::getSeverities(), $options);

            case 'status':
                return Dropdown::showFromArray($name, self::getStatuses(), $options);

            case 'body_decision':
                return Dropdown::showFromArray($name, CertificationResponse::getDecisions(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function findingTypeBadge(?string $value): string
    {
        $map = [
            'nonconformity' => ['bg-red-lt', 'ti-alert-hexagon', self::getTypeName(1)],
            'observation'   => ['bg-azure-lt', 'ti-notes', __('Observation / remark', 'grcmanager')],
            CertificationAuditRules::FINDING_TYPE_SENSITIVE_POINT => [
                'bg-yellow-lt', 'ti-eye-exclamation', __('Area of concern', 'grcmanager'),
            ],
            CertificationAuditRules::FINDING_TYPE_IMPROVEMENT     => [
                'bg-azure-lt', 'ti-bulb', __('Opportunity for improvement', 'grcmanager'),
            ],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function severityBadge(?string $value): string
    {
        $map = [
            'minor'    => ['bg-yellow-lt', 'ti-alert-circle', __('Minor', 'grcmanager')],
            'major'    => ['bg-orange-lt', 'ti-alert-triangle', __('Major', 'grcmanager')],
            'critical' => ['bg-red-lt', 'ti-alert-octagon', __('Critical', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            'open'        => ['bg-red-lt', 'ti-flag', __('Open', 'grcmanager')],
            'in_progress' => ['bg-blue-lt', 'ti-tool', __('In treatment', 'grcmanager')],
            'closed'      => ['bg-dark-lt', 'ti-check', __('Closed', 'grcmanager')],
            'verified'    => ['bg-green-lt', 'ti-shield-check', _x('feminine', 'Verified', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function auditLink(int $auditId): string
    {
        if ($auditId <= 0) {
            return '';
        }

        $title = self::getAuditTitle($auditId);

        if ($title === '') {
            return '';
        }

        return Fragment::link(Audit::getFormURLWithID($auditId), $title);
    }

    /**
     * Direct-lookup helper, same pattern as Control::getLinkedRisks() (a real
     * CommonDBTM::getFromDB() would work too, but this avoids instantiating a whole object just to
     * read one column).
     */
    public static function getAuditTitle(int $auditId): string
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['title'],
            'FROM'   => Audit::getTable(),
            'WHERE'  => ['id' => $auditId],
        ]);

        foreach ($rows as $row) {
            return (string) $row['title'];
        }

        return '';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        // Issue #140 : preuves du constat (captures, extraits, rapport de vérification).
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * Issue #140 : onglet « Constats » de la fiche d'un audit.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Audit || $item->isNewItem() || !self::canView()) {
            return '';
        }
        // Issue #536 : constats des entités de la session.
        $count = countElementsInTable(self::getTable(), array_merge(
            ['plugin_grcmanager_audits_id' => $item->getID()],
            EntityPerimeter::criteria(self::getTable())
        ));

        return self::createTabEntry(__('Findings', 'grcmanager'), $count, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Audit) {
            return false;
        }
        $findings = Audit::getFindings($item->getID());
        // Issue #772 : audit de certification : référence et vocabulaire de l'organisme, état de levée.
        $certification = CertificationAuditRules::isExternal((string) ($item->fields['audit_type'] ?? ''));
        $external = [];
        if ($certification) {
            foreach (CertificationFinding::findings([$item->getID()]) as $row) {
                $external[$row['id']] = $row;
            }
        }
        foreach ($findings as &$finding) {
            $finding['url'] = self::getFormURLWithID($finding['id']);
            $finding['certification'] = $external[$finding['id']] ?? null;
        }
        unset($finding);
        TemplateRenderer::getInstance()->display('@grcmanager/audit_findings_tab.html.twig', [
            'certification' => $certification,
            'findings_url'  => $certification && CertificationFinding::canView()
                ? CertificationFinding::getSearchURL() . '?audit=' . $item->getID()
                : '',
            'findings' => $findings,
            'add_url'  => self::canCreate() ? self::getFormURL() . '?audit=' . $item->getID() : '',
        ]);

        return true;
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB;

        $this->initForm($ID, $options);
        // Issue #163 : « Proposer un plan d'action » depuis l'onglet CVE d'un incident pré-remplit
        // une nouvelle fiche, relue avant tout enregistrement.
        if ($this->isNewItem() && (int) ($options['from_incident'] ?? 0) > 0) {
            $this->fields = self::presetFromCve(
                (int) $options['from_incident'],
                (string) ($options['cve'] ?? '')
            ) + $this->fields;
        }
        // Issue #140 : « Ajouter un constat » depuis l'onglet Constats d'un audit.
        if ($this->isNewItem() && (int) ($options['audit'] ?? 0) > 0) {
            $this->fields['plugin_grcmanager_audits_id'] = (int) $options['audit'];
        }
        // Issue #606 : « Créer le constat » depuis une ligne de la grille d'audit : fiche pré-remplie,
        // relue et enregistrée par l'utilisateur (rien n'est créé avant).
        $checklistDraft = null;
        if ($this->isNewItem() && (int) ($options['checklistitem'] ?? 0) > 0) {
            $checklistDraft = AuditChecklist::findingDraft((int) $options['checklistitem']);
            if ($checklistDraft !== null) {
                $preset = ['plugin_grcmanager_audits_id', 'title', 'description', 'finding_type', 'severity'];
                foreach ($preset as $field) {
                    $this->fields[$field] = $checklistDraft[$field];
                }
            }
        }
        // Issue #778 : « Créer l'action » depuis une ligne de l'évaluation de la sécurité physique d'un
        // site : fiche pré-remplie (site, mesure, constat), relue et enregistrée par l'utilisateur.
        $physicalDraft = null;
        if ($this->isNewItem() && (int) ($options['physicalsecuritycheck'] ?? 0) > 0) {
            $physicalDraft = PhysicalSecurityCheck::findingDraft((int) $options['physicalsecuritycheck']);
            if ($physicalDraft !== null) {
                $preset = [
                    'entities_id', 'is_recursive', 'locations_id', 'requirement_reference', 'title', 'description',
                    'finding_type', 'severity',
                ];
                foreach ($preset as $field) {
                    $this->fields[$field] = $physicalDraft[$field];
                }
            }
        }
        // Issue #603 : « Créer l'action » depuis un écart de maturité d'une mesure : piste
        // d'amélioration pré-remplie (mesure, niveaux, échéance, responsable), relue et enregistrée.
        $maturityDraft = null;
        if ($this->isNewItem() && (int) ($options['maturityassessment'] ?? 0) > 0) {
            $maturityDraft = MaturityAssessment::findingDraft((int) $options['maturityassessment']);
            if ($maturityDraft !== null) {
                $preset = [
                    'entities_id', 'is_recursive', 'requirement_reference', 'title', 'description', 'finding_type',
                    'severity', 'due_date', 'users_id',
                ];
                foreach ($preset as $field) {
                    $this->fields[$field] = $maturityDraft[$field];
                }
            }
        }
        // Issue #835 : « Proposer une action » depuis une règle de l'audit de sécurité de l'AD : fiche
        // pré-remplie (domaine, règle, mesures), relue et enregistrée par l'utilisateur.
        $adAuditDraft = null;
        if ($this->isNewItem() && (int) ($options['adauditrule'] ?? 0) > 0) {
            $adAuditDraft = AdSecurityAudit::findingDraft((int) $options['adauditrule']);
            if ($adAuditDraft !== null) {
                $preset = [
                    'entities_id', 'is_recursive', 'requirement_reference', 'title', 'description', 'finding_type',
                    'severity',
                ];
                foreach ($preset as $field) {
                    $this->fields[$field] = $adAuditDraft[$field];
                }
            }
        }

        $auditTitles = [0 => Dropdown::EMPTY_VALUE];
        // Issue #536 : audits des entités de la session seulement.
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => Audit::getTable(),
                'WHERE'  => EntityPerimeter::criteria(Audit::getTable()),
                'ORDER'  => 'title',
            ]) as $row
        ) {
            $auditTitles[(int) $row['id']] = $row['title'];
        }
        $incident = null;
        $incidentId = (int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0);
        $source = new PluginGrcmanagerSecurityIncident();
        if ($incidentId > 0 && $source->getFromDB($incidentId) && $source->canViewItem()) {
            $incident = [
                'id'   => $incidentId,
                'name' => (string) $source->fields['name'],
                'url'  => PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentId),
            ];
        }

        // Issue #27 : le hint reflete la meme regle que CapaRequirementService::isCapaMandatory()
        // cote serveur, jamais un second endroit ou cette regle pourrait diverger.
        $findingType = (string) ($this->fields['finding_type'] ?? 'nonconformity');
        // Issue #772 : constat d'un audit de certification, saisi avec le vocabulaire de l'organisme.
        $certification = CertificationFinding::isExternalAudit(
            (int) ($this->fields['plugin_grcmanager_audits_id'] ?? 0)
        );
        TemplateRenderer::getInstance()->display('@grcmanager/nonconformity_form.html.twig', [
            'certification'          => $certification,
            'certification_categories' => CertificationFinding::getCategories(),
            // Issue #772, lot 3 : décision de l'organisme sur la réponse.
            'body_decisions'         => CertificationResponse::getDecisions(),
            // Issue #772, lot 2 : processus du programme d'audit, proposés pour le processus audité.
            'process_labels'         => array_values(array_unique(array_filter(
                array_column(CertificationSampling::processes(), 'label')
            ))),
            'certification_category' => CertificationAuditRules::categoryOf(
                $findingType ?: 'nonconformity',
                (string) ($this->fields['severity'] ?: 'minor')
            ) ?? CertificationAuditRules::CATEGORY_SENSITIVE_POINT,
            'item'            => $this,
            'params'          => $options,
            'incident'        => $incident,
            'finding_types'   => self::getFindingTypes(),
            'severities'      => self::getSeverities(),
            'statuses'        => self::getStatuses(),
            'audits'          => $auditTitles,
            'audit_label'     => Audit::getTypeName(1),
            'checklist_draft' => $checklistDraft,
            'checklist_lines' => $this->isNewItem() ? [] : AuditChecklist::linesForFinding($this->getID()),
            'physical_draft'  => $physicalDraft,
            'maturity_draft'  => $maturityDraft,
            'maturity_lines'  => $this->isNewItem() ? [] : MaturityAssessment::linesForFinding($this->getID()),
            // Issue #835 : action proposée depuis une règle de l'audit de sécurité de l'AD.
            'ad_audit_draft'  => $adAuditDraft,
            'physical_lines'  => $this->isNewItem() ? [] : PhysicalSecurityCheck::linesForFinding($this->getID()),
            'corrective_hint' => CapaRequirementService::isCapaMandatory($findingType)
                ? __('Required to close or verify this non-conformity.', 'grcmanager')
                : __('Optional for an observation: it may still be filled in if useful.', 'grcmanager'),
        ]);

        return true;
    }

    /**
     * Issue #163 : non-conformité déjà ouverte pour cette CVE de cet incident (0 sinon).
     */
    public static function findForIncidentCve(int $incidentId, string $cveId): int
    {
        // Issue #536 : non-conformités des entités de la session.
        $found = (new self())->find(array_merge([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'cve_id'                                 => $cveId,
        ], EntityPerimeter::criteria(self::getTable())), ['id DESC'], 1);

        return $found === [] ? 0 : (int) array_key_first($found);
    }

    /**
     * Issue #163 : plan d'action proposé pour une CVE d'un incident, à partir de NVD (correctifs,
     * avis de l'éditeur), du catalogue CISA KEV et des actifs de l'inventaire qui la portent.
     * Vide si l'incident n'est pas visible ou si la CVE ne lui est pas rattachée.
     *
     * @return array<string, mixed>
     */
    public static function presetFromCve(int $incidentId, string $cveId): array
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        if (
            !$incident->getFromDB($incidentId) || !$incident->canViewItem()
            || countElementsInTable(SecurityIncidentCve::getTable(), [
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'cve_id'                                 => $cveId,
            ]) === 0
        ) {
            return [];
        }
        $enrichment = NvdCveEnrichmentService::getForCve($cveId);
        $signal = ParkVulnerabilitySync::signalsFor([$cveId])[$cveId] ?? null;
        $cvss = $enrichment['cvss_score'] ?? null;
        $isKev = (bool) ($signal['is_kev'] ?? false);
        $kevDue = (string) ($signal['kev_due'] ?? '');
        $severity = CveCapaRules::severity($cvss, $isKev);
        $links = CveCapaRules::groupLinks($enrichment['patch_links'] ?? []);
        $assets = $enrichment !== null && $enrichment['affected_cpes'] !== []
            ? InventoryCveMatcher::findMatchingAssets($enrichment['affected_cpes'])
            : [];

        $corrective = [];
        foreach ($links['patch'] as $url) {
            $corrective[] = sprintf(__('Apply the vendor patch: %s', 'grcmanager'), $url);
        }
        if ($links['patch'] === []) {
            $corrective[] = __('Find and apply the vendor patch (no patch link published in NVD yet).', 'grcmanager');
        }
        foreach ($links['advisory'] as $url) {
            $corrective[] = sprintf(__('Follow the vendor security advisory: %s', 'grcmanager'), $url);
        }
        if ($assets !== []) {
            $names = array_values(array_unique(array_column($assets, 'item_name')));
            $shown = array_slice($names, 0, 20);
            $corrective[] = sprintf(
                __('Update the affected assets (%1$d): %2$s', 'grcmanager'),
                count($names),
                implode(', ', $shown) . (count($names) > 20 ? ' (…)' : '')
            );
        } else {
            $corrective[] = __(
                'Identify the affected assets (no matching asset found in the inventory).',
                'grcmanager'
            );
        }
        if ($isKev && $kevDue !== '') {
            $corrective[] = sprintf(
                __('Actively exploited vulnerability: remediation due date set by CISA on %s.', 'grcmanager'),
                Html::convDate($kevDue)
            );
        }
        $corrective[] = __(
            'Check effectiveness: verify that the vulnerability no longer appears on the assets (incident CVE tab '
                . 'or "Fleet vulnerabilities").',
            'grcmanager'
        );

        $description = sprintf(
            __('Action plan proposed for %1$s, found on the security incident "%2$s".', 'grcmanager'),
            $cveId,
            $incident->fields['name']
        );
        if ($cvss !== null) {
            $description .= ' CVSS ' . $cvss . '.';
        }
        if (($enrichment['description'] ?? '') !== '') {
            $description .= "\n\n" . mb_substr((string) $enrichment['description'], 0, 600);
        }

        return [
            'title'                                  => mb_substr(sprintf(
                __('Fix %1$s (incident "%2$s")', 'grcmanager'),
                $cveId,
                $incident->fields['name']
            ), 0, 255),
            'finding_type'                           => 'nonconformity',
            'severity'                               => $severity,
            'status'                                 => 'open',
            'due_date'                               => CveCapaRules::dueDate($severity, $kevDue, date('Y-m-d')),
            'description'                            => $description,
            'corrective_action'                      => implode("\n", array_map(
                static fn (string $line, int $i): string => ($i + 1) . '. ' . $line,
                $corrective,
                array_keys($corrective)
            )),
            'preventive_action'                      => implode("\n", [
                '- ' . __(
                    'Watch the affected software in "Software to watch" to be alerted of future vulnerabilities.',
                    'grcmanager'
                ),
                '- ' . __('Include this software in the regular patching cycle.', 'grcmanager'),
            ]),
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'cve_id'                                 => $cveId,
        ];
    }

    /**
     * GLPI Cron entry point, same structure as Risk::cronReviewreminder().
     * Notifies the responsible owner of every non-conformity whose due date has passed and that
     * is not yet closed/verified (see OverdueCapaService, inc/notificationtargetnonconformity.class.php).
     *
     * @return int 0 if no CAPA was overdue, 1 otherwise
     */
    public static function cronOverduecapa(CronTask $task): int
    {
        $result = (new OverdueCapaService())->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            __('%d overdue corrective/preventive action(s), %d notification(s) raised.', 'grcmanager'),
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
