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

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\RiskBase;
use GlpiPlugin\Grcmanager\Services\Classification\ClassificationLevels;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidence;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Risk\LinkableItemtypes;
use GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService;
use GlpiPlugin\Grcmanager\Services\Risk\RiskAppetiteRules;
use GlpiPlugin\Grcmanager\Services\Risk\RiskItemLinkNormalizer;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules;
use GlpiPlugin\Grcmanager\Traits\RiskAssessmentTrait;
use Log;
use Session;
use User;

/**
 * Generic organizational risk register (ISO 27001 clause 6.1.2/8.2), see issue #89 on the sibling
 * repository glpi-vulnerability-manager for the full scope this plugin covers. Deliberately
 * broader than that sibling plugin's own CVE-specific risk model: a row here can describe a
 * people, process, physical, third-party or technical risk, not just a scored vulnerability.
 *
 * The probability x impact scoring, treatment/status enums and their badge rendering are shared
 * with the Sprint 5 supplier/third-party risk register (SupplierRisk) via
 * RiskAssessmentTrait, see its own docblock: one implementation, never two that could drift apart.
 *
 * Issue #31 (plan d'action de traitement des risques, clause 8.3/6.1.3) adds a genuine, trackable
 * treatment plan for a `mitigate`/`transfer` decision (see TreatmentPlanRules,
 * RiskTreatmentAction, showTreatmentPlan() below) - `treatment` and `justification`
 * alone only ever recorded the DECISION, never whether it was actually carried out.
 */
class Risk extends RiskBase
{
    use RiskAssessmentTrait;

    // Registre sensible : droit dédié, voir Installer::SENSITIVE_RIGHT.
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    /**
     * GLPI notification event name (see inc/notificationtargetrisk.class.php and
     * src/Services/Risk/ReviewReminderService.php), shared here as a single source of truth so
     * the event string never drifts between the NotificationTarget, the reminder service, and the
     * Cron entry point below.
     */
    public const REVIEW_DUE_EVENT = 'review_due';

    /** Issue #378 : champs du risque résiduel => champ du risque initial dont ils reprennent l'affichage. */
    private const RESIDUAL_FIELDS = [
        'residual_probability' => 'probability',
        'residual_impact'      => 'impact',
        'residual_level'       => 'risk_level',
    ];

    /**
     * Issue #25 (lien registre de risques <-> actifs GLPI/CMDB) : table de liaison polymorphe
     * (itemtype/items_id), voir src/Install/Installer.php pour le schéma et son commentaire, et
     * getLinkedAssets()/syncLinkedAssets()/getRisksLinkedToItem() ci-dessous. Gérée en accès direct
     * $DB par de simples méthodes statiques, même simplification assumée depuis le Sprint 3 pour
     * les autres liens de ce plugin (glpi_plugin_grcmanager_controls_risks, etc.), voir
     * TECH_DEBT.md : pas une vraie classe CommonDBRelation, un nombre d'actifs liés par risque
     * toujours faible en pratique.
     */
    private const ITEMS_LINK_TABLE = 'glpi_plugin_grcmanager_risks_items';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_risks';
    }

    /**
     * Le titre tient lieu de nom : sans cela, l'en-tête de la fiche et les listes déroulantes
     * affichaient « N/A » (même correctif que Policy::getNameField()).
     */
    public static function getNameField()
    {
        return 'title';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Risk', 'Risks', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-exclamation';
    }

    /**
     * Rubrique « Risques » du menu : risques fournisseurs, analyse d'impact, effet domino et
     * dérogations en sous-pages (Menu::sections()).
     */
    public static function getMenuContent()
    {
        return Menu::withSubPages(self::class, parent::getMenuContent());
    }

    /**
     * The one validation this class adds on top of RiskAssessmentTrait's shared scoring (issue #31,
     * ISO 27001 clause 8.3/6.1.3) : a risk being closed while its decision is `mitigate`/`transfer`
     * (see TreatmentPlanRules::isTreatmentPlanRelevant()) must have at least one recorded treatment
     * action - mirrors Nonconformity's own "corrective action mandatory to
     * close/verify" pattern (CapaRequirementService::isCapaMandatory()), applied here to closing a
     * risk instead of closing a non-conformity. Delegates the actual probability x impact scoring
     * to the trait's own computeRiskLevel() so this override never duplicates that logic, same
     * pattern as SupplierRisk::validateSupplierAndComputeRiskLevel().
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->validateTreatmentPlanAndComputeRiskLevel($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateTreatmentPlanAndComputeRiskLevel($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateTreatmentPlanAndComputeRiskLevel(array $input)
    {
        $status    = (string) ($input['status'] ?? ($this->fields['status'] ?? 'identified'));
        $treatment = (string) ($input['treatment'] ?? ($this->fields['treatment'] ?? ''));
        $riskId    = (int) ($this->fields['id'] ?? 0);

        if (
            $status === 'closed'
            && TreatmentPlanRules::isTreatmentPlanRelevant($treatment)
            && ($riskId <= 0 || RiskTreatmentAction::countForRisk($riskId) === 0)
        ) {
            Session::addMessageAfterRedirect(
                __(
                    'A treatment plan (at least one action) is required to close a risk being mitigated or '
                        . 'transferred.',
                    'grcmanager'
                ),
                false,
                ERROR
            );

            return false;
        }

        $input = $this->computeRiskLevel($input);
        $input = $this->computeResidualLevel($input);

        return $this->applyAppetite($input);
    }

    /**
     * Issue #378 : risque résiduel, après traitement. Probabilité et impact facultatifs (vides : non
     * évalué), niveau calculé avec la même matrice que le risque initial ; jamais saisi directement.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function computeResidualLevel(array $input): array
    {
        unset($input['residual_level']);
        if (!array_key_exists('residual_probability', $input) && !array_key_exists('residual_impact', $input)) {
            return $input;
        }
        $probability = (string) ($input['residual_probability'] ?? ($this->fields['residual_probability'] ?? ''));
        $impact = (string) ($input['residual_impact'] ?? ($this->fields['residual_impact'] ?? ''));
        $probability = array_key_exists($probability, self::getProbabilities()) ? $probability : '';
        $impact = array_key_exists($impact, self::getImpacts()) ? $impact : '';
        $input['residual_probability'] = $probability;
        $input['residual_impact'] = $impact;
        $input['residual_level'] = $probability !== '' && $impact !== ''
            ? (new RiskScoringService(RiskMatrixConfig::load()))->level($probability, $impact)
            : '';

        return $input;
    }

    /**
     * Niveau comparé à l'appétence : résiduel s'il est évalué, sinon initial.
     *
     * @param array<string, mixed> $fields
     */
    public static function appetiteLevel(array $fields): string
    {
        return RiskAppetiteRules::retainedLevel(
            (string) ($fields['residual_level'] ?? ''),
            (string) ($fields['risk_level'] ?? '')
        );
    }

    /**
     * Issue #378 : un risque accepté alors qu'il dépasse l'appétence de sa catégorie est une
     * acceptation formelle. Elle doit être justifiée, et la personne qui l'enregistre (droit de
     * modifier le registre) la signe : son identifiant et la date sont conservés. Rien ne change
     * tant que la catégorie n'a pas d'appétence (RiskAppetite).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function applyAppetite(array $input)
    {
        unset($input['users_id_acceptance'], $input['date_acceptance'], $input['is_direction_acceptance']);
        $category  = (string) ($input['category'] ?? ($this->fields['category'] ?? ''));
        $level     = self::appetiteLevel($input + $this->fields);
        $treatment = (string) ($input['treatment'] ?? ($this->fields['treatment'] ?? ''));
        $position  = RiskAppetite::positionFor($category, $level);
        $alreadySigned = (int) ($this->fields['users_id_acceptance'] ?? 0) > 0
            && ($this->fields['treatment'] ?? '') === 'accept';

        if (RiskAppetiteRules::isFormalAcceptance($position, $treatment)) {
            $justification = trim((string) ($input['justification'] ?? ($this->fields['justification'] ?? '')));
            if ($justification === '') {
                Session::addMessageAfterRedirect(
                    __(
                        'This risk exceeds the appetite of its category: accepting it requires a justification.',
                        'grcmanager'
                    ),
                    false,
                    ERROR
                );

                return false;
            }
            $signer = (int) Session::getLoginUserID();
            $directionId = RiskAppetite::getDirectionGroupId();
            $signedByDirection = (int) ($this->fields['is_direction_acceptance'] ?? 0) === 1;
            $decision = RiskAppetiteRules::acceptance(
                $position,
                $treatment,
                $directionId > 0,
                RiskAppetite::isDirection($signer)
            );
            // Une acceptation déjà signée reste valable ; seule la direction peut la reprendre à son compte.
            $resign = !$alreadySigned
                || ($decision === RiskAppetiteRules::ACCEPT_DIRECTION && !$signedByDirection);
            if ($decision === RiskAppetiteRules::ACCEPT_REFUSED && !$alreadySigned) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __(
                            'This risk exceeds the tolerance of its category: only a member of the management group '
                                . '(%s) can accept it. Choose a treatment, or ask management to sign the acceptance.',
                            'grcmanager'
                        ),
                        Dropdown::getDropdownName('glpi_groups', $directionId)
                    ),
                    false,
                    ERROR
                );
                AuditLog::record('risk_acceptance', self::class, (int) ($this->fields['id'] ?? 0), 0, 'denied', [
                    'category' => $category, 'level' => $level, 'position' => $position,
                ]);

                return false;
            }
            if ($resign) {
                $input['users_id_acceptance']     = $signer;
                $input['date_acceptance']         = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
                $input['is_direction_acceptance'] = (int) ($decision === RiskAppetiteRules::ACCEPT_DIRECTION);
                AuditLog::record('risk_acceptance', self::class, (int) ($this->fields['id'] ?? 0), 0, 'success', [
                    'category' => $category, 'level' => $level, 'position' => $position,
                    'direction' => $decision === RiskAppetiteRules::ACCEPT_DIRECTION,
                ]);
            }
        } elseif ($treatment !== 'accept' && (int) ($this->fields['users_id_acceptance'] ?? 0) > 0) {
            // Décision changée : la signature d'acceptation ne vaut plus.
            $input['users_id_acceptance']     = 0;
            $input['date_acceptance']         = null;
            $input['is_direction_acceptance'] = 0;
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
            'field'    => 'category',
            'name'     => __('Category', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'probability',
            'name'     => __('Probability', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'impact',
            'name'     => __('Impact', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'risk_level',
            'name'     => __('Risk level', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'treatment',
            'name'     => __('Treatment', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'review_date',
            'name'     => __('Review date', 'grcmanager'),
            'datatype' => 'date',
        ];

        // Row link (a list with no way back to showForm() is not self-explanatory, lesson learned
        // on the sibling plugin glpi-vulnerability-manager, see its own inc/risk.class.php and
        // TECH_DEBT.md, applied here from the very first commit rather than fixed after the fact).
        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        // Issue #378 : acceptation formelle d'un risque au-dessus de l'appétence.
        $tab[] = [
            'id'        => 11,
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'users_id_acceptance',
            'name'      => __('Formally accepted by', 'grcmanager'),
            'datatype'  => 'dropdown',
            'right'     => 'all',
        ];
        $tab[] = [
            'id'       => 12,
            'table'    => $this->getTable(),
            'field'    => 'date_acceptance',
            'name'     => __('Formal acceptance date', 'grcmanager'),
            'datatype' => 'datetime',
        ];
        // Issue #378 : risque résiduel.
        foreach (
            [
                13 => ['residual_probability', __('Residual probability', 'grcmanager')],
                14 => ['residual_impact', __('Residual impact', 'grcmanager')],
                15 => ['residual_level', __('Residual risk level', 'grcmanager')],
            ] as $id => [$field, $name]
        ) {
            $tab[] = [
                'id'            => $id,
                'table'         => $this->getTable(),
                'field'         => $field,
                'name'          => $name,
                'datatype'      => 'specific',
                'massiveaction' => false,
            ];
        }

        return $tab;
    }

    /**
     * Translates the raw DB enum values into color-coded Tabler badges instead of showing the
     * untranslated raw string, same UX lesson already applied on the sibling plugin
     * glpi-vulnerability-manager after a real live-instance review found its lists unusable
     * without it, see TECH_DEBT.md there. Applied here from the start. Every column here is shared
     * with the Sprint 5 supplier risk register, see RiskAssessmentTrait::commonValueToDisplay().
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        // Issue #378 : risque résiduel, vide tant qu'il n'est pas évalué.
        $residual = self::RESIDUAL_FIELDS;
        if (isset($residual[$field])) {
            $value = (string) ($values[$field] ?? '');

            return $value === '' ? htmlescape(__('To be assessed', 'grcmanager'))
                : (string) self::commonValueToDisplay($residual[$field], $value);
        }
        $common = self::commonValueToDisplay($field, $values[$field] ?? null);
        if ($common !== null) {
            return $common;
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Renders a real `<select>` filter widget in the search form for every fixed-enum column
     * (category/probability/impact/risk_level/treatment/status), instead of GLPI's default
     * free-text box for `datatype => 'specific'` fields (confirmed by reading GLPI 11 core,
     * src/Glpi/Search/Input/QueryBuilder.php: 'specific' falls through to the same generic
     * text-input pattern as 'string' unless this hook is overridden). Sprint 1 shipped translated,
     * color-coded values in the *list*, but left every one of these columns filterable only by
     * typing the raw untranslated DB key (e.g. "third_party") into a text box, genuinely
     * filterable in the sense that GLPI's search does apply it, but not self-explanatory for a
     * non-technical user, and not what Sprint 2 asks for. Sorting was never affected (GLPI sorts
     * by the raw SQL column for any datatype), only filtering needed this. Every column here is
     * shared with the Sprint 5 supplier risk register, see RiskAssessmentTrait::commonValueToSelect().
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        $residual = self::RESIDUAL_FIELDS;
        $common = self::commonValueToSelect($residual[$field] ?? $field, $name, $options);
        if ($common !== null) {
            return $common;
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Issue #171 : le risque s'affiche avec ses onglets (formulaire, puis ceux enregistrés par
     * d'autres classes, comme « Effet domino »).
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            // Issue #377 : processus métier menacés par ce risque (droit de la continuité requis).
            ->addStandardTab(BusinessProcessRisk::class, $tabs, $options)
            // Registre sensible : modifications historisées.
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB, $CFG_GLPI;

        $this->initForm($ID, $options);
        $isNew = $this->isNewID($ID);
        $treatment = (string) ($this->fields['treatment'] ?? '');
        $planRelevant = TreatmentPlanRules::isTreatmentPlanRelevant($treatment);

        // Issue #25 : une sélection multiple par type d'actif liable, seulement pour les types qui
        // ont des actifs visibles. Issue #26 : chaque option porte sa classification C/I/D (lecture
        // seule, l'édition reste sur l'onglet « Classification C/I/D » de l'actif).
        $linkedIdsByType = $isNew ? [] : self::getLinkedAssetIdsByType((int) $ID);
        $assetGroups = [];
        foreach (self::getLinkableItemtypes() as $itemtype) {
            if (!is_a($itemtype, CommonDBTM::class, true) || !$itemtype::canView()) {
                continue;
            }
            $classificationsByItemsId = AssetClassification::getByItemtype($itemtype);

            // Le risque est global, les actifs non : seulement ceux que l'utilisateur voit (entités
            // de la session), hors corbeille et gabarits.
            $prototype = new $itemtype();
            $table     = $itemtype::getTable();
            $where     = getEntitiesRestrictCriteria($table, '', '', $prototype->maybeRecursive())
                + $itemtype::getSystemSQLCriteria($table);
            if ($prototype->isField('is_deleted')) {
                $where["$table.is_deleted"] = 0;
            }
            if ($prototype->isField('is_template')) {
                $where["$table.is_template"] = 0;
            }

            $assetOptions = [];
            foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => 'name']) as $row) {
                $itemsId = (int) $row['id'];
                $label   = $row['name'] !== '' ? $row['name'] : sprintf('#%d', $itemsId);

                $classification = $classificationsByItemsId[$itemsId] ?? null;
                if (ClassificationLevels::isClassified($classification)) {
                    $label .= ' ' . sprintf(
                        __('(C: %1$s, I: %2$s, A: %3$s)', 'grcmanager'),
                        self::classificationLevelLabel($classification['confidentiality'] ?? null),
                        self::classificationLevelLabel($classification['integrity'] ?? null),
                        self::classificationLevelLabel($classification['availability'] ?? null)
                    );
                }
                $assetOptions[$itemsId] = $label;
            }
            if ($assetOptions === []) {
                continue;
            }
            $assetGroups[] = [
                'field'   => self::linkedAssetFieldName($itemtype),
                'label'   => $itemtype::getTypeName(2),
                'options' => $assetOptions,
                'values'  => $linkedIdsByType[$itemtype] ?? [],
            ];
        }

        // Issue #368 : alertes Wazuh récentes des ordinateurs liés (déjà filtrés sur les droits de
        // l'utilisateur par getLinkedAssets()), lecture seule ; rien si le plugin Wazuh est absent.
        $wazuhAlerts = null;
        if (!$isNew && WazuhEvidenceProvider::isAvailable()) {
            $wazuhAlerts = WazuhEvidenceProvider::fetchRecentAlerts(
                array_map('intval', $linkedIdsByType[\Computer::class] ?? []),
                date('Y-m-d H:i:s', (int) strtotime('-' . WazuhEvidence::ALERT_DAYS . ' days'))
            );
            foreach ($wazuhAlerts ?? [] as $key => $alert) {
                $wazuhAlerts[$key]['computer'] = Dropdown::getDropdownName('glpi_computers', $alert['computers_id']);
                $wazuhAlerts[$key]['computer_url'] = \Computer::getFormURLWithID($alert['computers_id']);
            }
        }

        TemplateRenderer::getInstance()->display('@grcmanager/risk_form.html.twig', [
            'item'                => $this,
            'params'              => $options,
            'is_new'              => $isNew,
            'wazuh_alerts'        => $wazuhAlerts,
            'wazuh_alert_days'    => WazuhEvidence::ALERT_DAYS,
            'wazuh_alert_max'     => WazuhEvidence::MAX_ALERTS,
            'categories'          => self::getCategories(),
            'probabilities'       => self::getProbabilities(),
            'impacts'             => self::getImpacts(),
            'treatments'          => self::getTreatments(),
            'statuses'            => self::getStatuses(),
            'risk_level_badge'    => self::riskLevelBadge($this->fields['risk_level'] ?? null),
            'high_classification' => !$isNew && self::hasHighClassificationAmongLinkedAssets((int) $ID),
            'plan_relevant'       => $planRelevant,
            'asset_groups'        => $assetGroups,
            // Issue #378 : position par rapport à l'appétence, acceptation formelle, KRI liés.
            'appetite_position'   => $isNew ? RiskAppetiteRules::UNDEFINED : RiskAppetite::positionFor(
                (string) $this->fields['category'],
                self::appetiteLevel($this->fields)
            ),
            'residual_probabilities' => ['' => __('To be assessed', 'grcmanager')] + self::getProbabilities(),
            'residual_impacts'    => ['' => __('To be assessed', 'grcmanager')] + self::getImpacts(),
            'residual_badge'      => (string) ($this->fields['residual_level'] ?? '') !== ''
                ? self::riskLevelBadge((string) $this->fields['residual_level']) : '',
            'appetite_on_residual' => (string) ($this->fields['residual_level'] ?? '') !== '',
            'appetite_url'        => RiskAppetite::getSearchURL(),
            'accepted_by'         => (int) ($this->fields['users_id_acceptance'] ?? 0) > 0
                ? getUserName((int) $this->fields['users_id_acceptance']) : '',
            'accepted_on'         => (string) ($this->fields['date_acceptance'] ?? ''),
            'accepted_direction'  => (int) ($this->fields['is_direction_acceptance'] ?? 0) === 1,
            'direction_group'     => RiskAppetite::getDirectionGroupId() > 0
                ? Dropdown::getDropdownName('glpi_groups', RiskAppetite::getDirectionGroupId()) : '',
            'needs_direction'     => !$isNew && RiskAppetiteRules::needsDirectionSignature(
                RiskAppetite::positionFor((string) $this->fields['category'], self::appetiteLevel($this->fields)),
                (string) $this->fields['treatment'],
                RiskAppetite::getDirectionGroupId() > 0,
                (int) ($this->fields['is_direction_acceptance'] ?? 0) === 1
            ),
            'indicators'          => $isNew ? [] : RiskIndicator::forRisk((int) $ID),
        ]);

        // Issue #31 : le plan de traitement est un vrai enfant (ajout, statut, suppression par
        // ligne) qui poste vers front/risktreatmentaction.form.php, donc hors du formulaire du risque.
        if ($planRelevant) {
            $actions = [];
            if (!$isNew) {
                foreach (RiskTreatmentAction::getActionsForRisk((int) $ID) as $action) {
                    $actions[] = [
                        'id'              => (int) $action['id'],
                        'description'     => (string) ($action['description'] ?? ''),
                        'user'            => getUserName((int) $action['users_id']),
                        'due_date'        => (string) ($action['due_date'] ?? ''),
                        'completion_date' => (string) ($action['completion_date'] ?? ''),
                        'status'          => $action['status'] ?? TreatmentPlanRules::DEFAULT_STATUS,
                        'status_html'     => RiskTreatmentAction::statusBadge(
                            $action['status'] ?? null
                        ),
                        'overdue'         => TreatmentPlanRules::isOverdue(
                            $action['due_date'] ?? null,
                            (string) $action['status']
                        ),
                    ];
                }
            }
            $canEdit = Session::haveRight(self::$rightname, UPDATE);
            TemplateRenderer::getInstance()->display('@grcmanager/risk_treatment_plan.html.twig', [
                'saved'           => !$isNew,
                'risk_id'         => (int) $ID,
                'can_edit'        => $canEdit,
                'action'          => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/risktreatmentaction.form.php',
                'actions'         => $actions,
                'action_statuses' => RiskTreatmentAction::getStatuses(),
                'user_dropdown'   => $canEdit && !$isNew
                    ? User::dropdown(['name' => 'users_id', 'value' => 0, 'right' => 'all', 'display' => false])
                    : '',
            ]);
        }

        return true;
    }

    /**
     * @return array<int, string> itemtype class names a risk may be linked to. The fixed default
     *         list (LinkableItemtypes::DEFAULT_ITEMTYPES) plus any active GLPI custom asset
     *         definition, enumerated dynamically at each call so an asset type created or
     *         activated after this plugin's install is usable without reinstalling it: same
     *         dynamic-discovery approach and same `glpi_assets_assetdefinitions` query as the
     *         sibling plugin assetsign-glpi's own Config::getAllManageableItemtypes().
     */
    public static function getLinkableItemtypes(): array
    {
        global $DB;

        $itemtypes = LinkableItemtypes::DEFAULT_ITEMTYPES;

        if ($DB->tableExists('glpi_assets_assetdefinitions')) {
            foreach ($DB->request(['FROM' => 'glpi_assets_assetdefinitions', 'WHERE' => ['is_active' => 1]]) as $row) {
                $itemtypes[] = 'Glpi\\CustomAsset\\' . $row['system_name'] . 'Asset';
            }
        }

        return $itemtypes;
    }

    /**
     * @return array<int, array{itemtype:string, items_id:int, name:string, url:string}>
     */
    public static function getLinkedAssets(int $riskId): array
    {
        global $DB;

        $items = [];

        $rows = $DB->request([
            'FROM'  => self::ITEMS_LINK_TABLE,
            'WHERE' => ['plugin_grcmanager_risks_id' => $riskId],
        ]);

        foreach ($rows as $row) {
            $itemtype = (string) $row['itemtype'];
            $itemsId  = (int) $row['items_id'];

            if (!is_a($itemtype, CommonDBTM::class, true)) {
                // Classe disparue depuis la création du lien (ex. désinstallation du plugin qui
                // fournissait un actif personnalisé) : ignorée plutôt que de faire échouer tout
                // l'affichage du formulaire pour un seul lien orphelin.
                continue;
            }

            $item = new $itemtype();
            $exists = $item->getFromDB($itemsId);
            // Le risque est global à l'instance, ses actifs liés non : un actif d'une entité hors
            // du périmètre de l'utilisateur n'est pas affiché (ni son nom, ni son lien).
            if ($exists && !$item->canViewItem()) {
                continue;
            }
            $name = $exists
                ? $item->getName()
                : sprintf(__('%1$s #%2$d (deleted)', 'grcmanager'), $itemtype::getTypeName(1), $itemsId);

            $items[] = [
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
                'name'     => $name,
                'url'      => $item->getFormURLWithID($itemsId),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, array<int, int>> itemtype => linked item ids, to pre-select each
     *         per-itemtype multi-select in showForm() above.
     */
    public static function getLinkedAssetIdsByType(int $riskId): array
    {
        $byType = [];
        foreach (self::getLinkedAssets($riskId) as $item) {
            $byType[$item['itemtype']][] = $item['items_id'];
        }

        return $byType;
    }

    /**
     * Issue #26 : soft, non-blocking suggestion backing the hint shown next to the Impact field in
     * showForm() above - true as soon as ONE of this risk's currently linked CMDB assets carries a
     * HIGH classification on any of its three C/I/D axes (pure decision in
     * ClassificationLevels::hasHighAxis(), unit tested there). Never used to change `impact`
     * itself, only to decide whether to print the hint.
     */
    private static function hasHighClassificationAmongLinkedAssets(int $riskId): bool
    {
        foreach (self::getLinkedAssets($riskId) as $asset) {
            $classification = AssetClassification::getByItem($asset['itemtype'], $asset['items_id']);
            if (ClassificationLevels::hasHighAxis($classification)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translated label for one raw C/I/D level value, used only for the inline read-only hint next
     * to a linked asset's multi-select option above - the color-coded badge
     * (AssetClassification::levelBadge()) is HTML and cannot be embedded in a plain
     * `<option>` label text, hence this plain-text equivalent kept in sync with the same three
     * levels (ClassificationLevels::ALLOWED).
     */
    private static function classificationLevelLabel(?string $value): string
    {
        return AssetClassification::getLevels()[$value] ?? __('Not classified', 'grcmanager');
    }

    /**
     * Reverse lookup (issue #25: "inversement partir d'un risque pour voir les actifs concernés",
     * and its mirror direction): every risk currently linked to one specific CMDB item, for the
     * read-only "Risques" tab this plugin adds on each linkable itemtype (see setup.php,
     * getTabNameForItem()/displayTabContentForItem() below).
     *
     * @return array<int, array{id:int, title:string, risk_level:?string}>
     */
    public static function getRisksLinkedToItem(string $itemtype, int $itemsId): array
    {
        global $DB;

        // Filtre SQL uniquement sur itemtype (indexé, voir la clé `item` du schéma) : le nombre de
        // liens pour un seul type reste faible en pratique, le filtre exact sur items_id est fait
        // ensuite en PHP par RiskItemLinkNormalizer::findRiskIdsForItem(), la même logique que
        // celle couverte par les tests unitaires (voir
        // tests/Unit/Services/Risk/RiskItemLinkNormalizerTest.php), pour ne jamais diverger entre
        // le code réellement exécuté et celui testé.
        $links = iterator_to_array($DB->request([
            'FROM'  => self::ITEMS_LINK_TABLE,
            'WHERE' => ['itemtype' => $itemtype],
        ]));

        $riskIds = RiskItemLinkNormalizer::findRiskIdsForItem($links, $itemtype, $itemsId);
        if ($riskIds === []) {
            return [];
        }

        $risks = [];

        $rows = $DB->request([
            'SELECT' => ['id', 'title', 'risk_level'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['id' => $riskIds],
        ]);

        foreach ($rows as $row) {
            $risks[] = [
                'id'         => (int) $row['id'],
                'title'      => $row['title'],
                'risk_level' => $row['risk_level'],
            ];
        }

        return $risks;
    }

    /**
     * Replaces the full set of CMDB items linked to this risk with exactly $pairs (delete then
     * re-insert), same "supprimer-puis-réinsérer" convention already used by every other simple
     * link table in this plugin (Control::syncLinkedRisks(),
     * Audit::syncLinkedControls()...), see TECH_DEBT.md. An empty $pairs array is
     * the expected, non-error baseline for a purely organizational risk with no CMDB counterpart
     * (issue #25, "processus de recrutement").
     *
     * @param array<int, array{itemtype:string, items_id:int}> $pairs
     */
    private static function syncLinkedAssets(int $riskId, array $pairs): void
    {
        global $DB;

        $allowed = self::getLinkableItemtypes();

        // Le formulaire ne propose que les actifs visibles par l'utilisateur (ses entités) : il ne
        // remplace donc que ces liens-là. Les liens vers des actifs qu'il ne voit pas (posés par
        // quelqu'un d'une autre entité) sont conservés, au lieu d'être effacés en silence.
        $kept = [];
        $links = $DB->request([
            'FROM'  => self::ITEMS_LINK_TABLE,
            'WHERE' => ['plugin_grcmanager_risks_id' => $riskId],
        ]);
        foreach ($links as $row) {
            $itemtype = (string) $row['itemtype'];
            $item = is_a($itemtype, CommonDBTM::class, true) ? new $itemtype() : null;
            if ($item !== null && $item->getFromDB((int) $row['items_id']) && !$item->canViewItem()) {
                $kept[$itemtype . ':' . (int) $row['items_id']] = true;
                continue;
            }
            $DB->delete(self::ITEMS_LINK_TABLE, ['id' => (int) $row['id']]);
        }

        foreach ($pairs as $pair) {
            $itemtype = (string) ($pair['itemtype'] ?? '');
            $itemsId  = (int) ($pair['items_id'] ?? 0);

            if (
                !RiskItemLinkNormalizer::isLinkable($itemtype, $itemsId, $allowed)
                || isset($kept[$itemtype . ':' . $itemsId])
            ) {
                continue;
            }
            // Jamais un actif invisible pour l'utilisateur (id forgé dans le POST).
            $item = new $itemtype(); // nosemgrep
            if (!$item->can($itemsId, READ)) {
                continue;
            }

            $DB->insert(self::ITEMS_LINK_TABLE, [
                'plugin_grcmanager_risks_id' => $riskId,
                'itemtype'                   => $itemtype,
                'items_id'                   => $itemsId,
                'date_creation'              => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Rebuilds the candidate itemtype/items_id pairs from the per-itemtype multi-selects rendered
     * in showForm() above, then delegates to syncLinkedAssets(). Does nothing if NONE of the
     * `linked_items_<Itemtype>` fields are present in the submitted input: an HTML multi-select
     * with zero selections submits no field at all, indistinguishable from "this form section
     * wasn't rendered" (e.g. a future programmatic/API caller that doesn't know about this block) -
     * only ever wipes existing links when the real form was genuinely submitted.
     */
    private function syncLinkedAssetsFromInput(): void
    {
        $itemtypes = self::getLinkableItemtypes();

        $formWasSubmitted = false;
        foreach ($itemtypes as $itemtype) {
            if (array_key_exists(self::linkedAssetFieldName($itemtype), $this->input)) {
                $formWasSubmitted = true;
                break;
            }
        }

        if (!$formWasSubmitted) {
            return;
        }

        $pairs = [];
        foreach ($itemtypes as $itemtype) {
            foreach ((array) ($this->input[self::linkedAssetFieldName($itemtype)] ?? []) as $itemsId) {
                $pairs[] = ['itemtype' => $itemtype, 'items_id' => (int) $itemsId];
            }
        }

        self::syncLinkedAssets((int) $this->fields['id'], $pairs);
    }

    private static function linkedAssetFieldName(string $itemtype): string
    {
        return 'linked_items_' . str_replace('\\', '_', $itemtype);
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->syncLinkedAssetsFromInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->syncLinkedAssetsFromInput();
    }

    /**
     * Purges this risk's own rows in the polymorphic link table when the risk itself is purged, so
     * a deleted risk never leaves orphaned `glpi_plugin_grcmanager_risks_items` rows behind. Not
     * strictly forced by a real GLPI foreign key (same reason as every other simple link table in
     * this plugin, see TECH_DEBT.md), but cheap and worth doing here since this table can grow
     * unboundedly with real CMDB items, unlike this plugin's small fixed-catalog link tables
     * (controls_risks, audits_controls).
     */
    public function post_purgeItem()
    {
        parent::post_purgeItem();

        global $DB;
        $DB->delete(self::ITEMS_LINK_TABLE, ['plugin_grcmanager_risks_id' => $this->fields['id']]);

        // Issue #31 : contrairement à ObjectiveMeasurement (jamais nettoyée quand
        // son objectif parent est supprimé, voir TECH_DEBT.md issue #32), on nettoie ici : ce hook
        // existe déjà pour ITEMS_LINK_TABLE ci-dessus, le coût marginal d'un second $DB->delete()
        // est nul, et une action de traitement orpheline ne serait, elle, plus jamais visible ni
        // supprimable (aucun menu, aucun écran de recherche propre à cet itemtype).
        $DB->delete(
            RiskTreatmentAction::getTable(),
            ['plugin_grcmanager_risks_id' => $this->fields['id']]
        );
        // Issue #377 : le risque n'est plus lié à aucun processus métier.
        $DB->delete(BusinessProcessRisk::getTable(), ['plugin_grcmanager_risks_id' => $this->fields['id']]);
    }

    /**
     * Issue #25, reverse view: read-only "Risques" tab on every linkable itemtype's own page (see
     * setup.php, Plugin::registerClass()/addtabon). Guarded to only fire for a genuinely linkable
     * $item (this method is invoked by GLPI core for every plugin class registered via addtabon,
     * regardless of which item is currently displayed) and behind the plugin's own read right,
     * same right as every other screen of this plugin.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || !in_array($item->getType(), self::getLinkableItemtypes(), true)) {
            return '';
        }

        if (!Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        $count = count(self::getRisksLinkedToItem($item->getType(), (int) $item->getID()));

        return self::createTabEntry(self::getTypeName(2), $count);
    }

    /**
     * Minimal read-only list (title + risk level badge, linked to the risk's own form): this
     * plugin registers no other reverse tab anywhere else (confirmed by inspecting every other
     * inc/*.class.php file), so there is no richer existing convention to match here, and the
     * "many risks per asset" cardinality this issue describes (issue #25, "panne électrique du
     * site") stays small enough that a full itemtype/list-search screen would be over-engineering
     * for what this issue actually asks for.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : même contrôle
        // ici (revue de sécurité 2.11.0, trouvé par la matrice d'accès de bout en bout).
        if (!$item instanceof CommonDBTM || !Session::haveRight(self::$rightname, READ)) {
            return false;
        }
        $rows = [];
        foreach (self::getRisksLinkedToItem($item->getType(), (int) $item->getID()) as $risk) {
            $rows[] = [
                'url'        => self::getFormURLWithID($risk['id']),
                'title'      => $risk['title'],
                // Badge déjà échappé, rendu par Services\Ui\Badge.
                'level_html' => self::riskLevelBadge($risk['risk_level']),
            ];
        }
        // Issue #199 : onglet passé de PHP à Twig.
        TemplateRenderer::getInstance()->display('@grcmanager/risk_asset_tab.html.twig', ['risks' => $rows]);

        return true;
    }

    /**
     * GLPI Cron entry point, registered via CronTask::Register() in the plugin installer
     * (src/Install/Installer.php), same structure as the sibling plugin
     * glpi-vulnerability-manager's own cronSynchronize() entry points. Finds risks whose review
     * date has passed or is within the reminder window and raises a real GLPI Notification for
     * each (see ReviewReminderService, inc/notificationtargetrisk.class.php); the dashboard card
     * `grcmanager_risks_pending_review` (Sprint 1) already gives a permanent in-app signal
     * regardless of whether GLPI notifications are enabled; this cron adds the active, per-risk
     * one (email to the risk owner, when notifications are configured).
     *
     * @return int 0 if no risk was due, 1 otherwise
     */
    public static function cronReviewreminder(CronTask $task): int
    {
        $result = (new ReviewReminderService(self::class))->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            __('%d risk(s) awaiting review, %d notification(s) raised.', 'grcmanager'),
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
