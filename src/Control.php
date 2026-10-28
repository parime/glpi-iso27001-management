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

use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\ControlBase;
use GlpiPlugin\Grcmanager\Services\Control\AnssiHygieneCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidence;
use GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Control\ControlCrosswalkDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
use PluginGrcmanagerMenu;
use Session;

/**
 * Declaration of Applicability (SoA, ISO 27001:2022 clause 6.1.3): the 93 ISO/IEC 27001:2022
 * Annex A controls, one row per control, seeded once at install (see
 * src/Install/Installer::seedControls(), src/Services/Control/ControlCatalogDefaults for the pure
 * code/theme data). Only the stable `code` (e.g. "A.5.1") and `theme` are seeded; the translated
 * title is never stored in the database, resolved instead from self::getControlTitles(), the same
 * separation Risk already applies to its own category/probability/impact enums.
 *
 * Per-control fields an admin actually edits: applicability (yes/no/partial), justification
 * (required by this class' own validation whenever applicability isn't "yes", per ISO 27001
 * convention), implementation status, and a link to the risk(s) that justify or drive that
 * control's implementation (many-to-many via glpi_plugin_grcmanager_controls_risks, see
 * getLinkedRiskIds()/syncLinkedRisks() below).
 */
class Control extends ControlBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    private const LINK_TABLE = 'glpi_plugin_grcmanager_controls_risks';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_controls';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Annex A control', 'Annex A controls', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    /** Rubrique « Contrôles Annexe A » : la SoA et ses correspondances (Référentiels). */
    /**
     * Rubrique « Conformité » : SoA, référentiels, exigences du SMSI, obligations, politiques et
     * preuves (PluginGrcmanagerMenu::sections()).
     */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if (is_array($menu) && $menu !== []) {
            $menu['section_label'] = $menu['title'];
            $menu['title'] = __('Compliance', 'grcmanager');
        }

        return PluginGrcmanagerMenu::withSubPages(self::class, $menu);
    }

    /**
     * @return array<string, string>
     */
    public static function getThemes(): array
    {
        return [
            'organizational' => __('Organizational', 'grcmanager'),
            'people'         => __('People', 'grcmanager'),
            'physical'       => __('Physical', 'grcmanager'),
            'technological'  => __('Technological', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getApplicabilities(): array
    {
        return [
            'yes'     => __('Applicable', 'grcmanager'),
            'no'      => __('Not applicable', 'grcmanager'),
            'partial' => __('Partially applicable', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getImplementationStatuses(): array
    {
        return [
            'not_started' => __('Not started', 'grcmanager'),
            'in_progress' => __('In progress', 'grcmanager'),
            'implemented' => __('Implemented', 'grcmanager'),
            'verified'    => __('Verified', 'grcmanager'),
        ];
    }

    /**
     * Real ISO/IEC 27001:2022 Annex A control titles, keyed by code. Deliberately not stored in
     * the database (see this class' docblock): resolved here, through __(), so both the fr_FR and
     * en_GB translations in locales/ are real, reviewable strings rather than machine-translated
     * database content.
     *
     * @return array<string, string>
     */
    public static function getControlTitles(): array
    {
        return [
            // Organizational controls (A.5)
            'A.5.1'  => __('Policies for information security', 'grcmanager'),
            'A.5.2'  => __('Information security roles and responsibilities', 'grcmanager'),
            'A.5.3'  => __('Segregation of duties', 'grcmanager'),
            'A.5.4'  => __('Management responsibilities', 'grcmanager'),
            'A.5.5'  => __('Contact with authorities', 'grcmanager'),
            'A.5.6'  => __('Contact with special interest groups', 'grcmanager'),
            'A.5.7'  => __('Threat intelligence', 'grcmanager'),
            'A.5.8'  => __('Information security in project management', 'grcmanager'),
            'A.5.9'  => __('Inventory of information and other associated assets', 'grcmanager'),
            'A.5.10' => __('Acceptable use of information and other associated assets', 'grcmanager'),
            'A.5.11' => __('Return of assets', 'grcmanager'),
            'A.5.12' => __('Classification of information', 'grcmanager'),
            'A.5.13' => __('Labelling of information', 'grcmanager'),
            'A.5.14' => __('Information transfer', 'grcmanager'),
            'A.5.15' => __('Access control', 'grcmanager'),
            'A.5.16' => __('Identity management', 'grcmanager'),
            'A.5.17' => __('Authentication information', 'grcmanager'),
            'A.5.18' => __('Access rights', 'grcmanager'),
            'A.5.19' => __('Information security in supplier relationships', 'grcmanager'),
            'A.5.20' => __('Addressing information security within supplier agreements', 'grcmanager'),
            'A.5.21' => __('Managing information security in the ICT supply chain', 'grcmanager'),
            'A.5.22' => __('Monitoring, review and change management of supplier services', 'grcmanager'),
            'A.5.23' => __('Information security for use of cloud services', 'grcmanager'),
            'A.5.24' => __('Information security incident management planning and preparation', 'grcmanager'),
            'A.5.25' => __('Assessment and decision on information security events', 'grcmanager'),
            'A.5.26' => __('Response to information security incidents', 'grcmanager'),
            'A.5.27' => __('Learning from information security incidents', 'grcmanager'),
            'A.5.28' => __('Collection of evidence', 'grcmanager'),
            'A.5.29' => __('Information security during disruption', 'grcmanager'),
            'A.5.30' => __('ICT readiness for business continuity', 'grcmanager'),
            'A.5.31' => __('Legal, statutory, regulatory and contractual requirements', 'grcmanager'),
            'A.5.32' => __('Intellectual property rights', 'grcmanager'),
            'A.5.33' => __('Protection of records', 'grcmanager'),
            'A.5.34' => __('Privacy and protection of PII', 'grcmanager'),
            'A.5.35' => __('Independent review of information security', 'grcmanager'),
            'A.5.36' => __('Compliance with policies, rules and standards for information security', 'grcmanager'),
            'A.5.37' => __('Documented operating procedures', 'grcmanager'),

            // People controls (A.6)
            'A.6.1' => __('Screening', 'grcmanager'),
            'A.6.2' => __('Terms and conditions of employment', 'grcmanager'),
            'A.6.3' => __('Information security awareness, education and training', 'grcmanager'),
            'A.6.4' => __('Disciplinary process', 'grcmanager'),
            'A.6.5' => __('Responsibilities after termination or change of employment', 'grcmanager'),
            'A.6.6' => __('Confidentiality or non-disclosure agreements', 'grcmanager'),
            'A.6.7' => __('Remote working', 'grcmanager'),
            'A.6.8' => __('Information security event reporting', 'grcmanager'),

            // Physical controls (A.7)
            'A.7.1'  => __('Physical security perimeters', 'grcmanager'),
            'A.7.2'  => __('Physical entry', 'grcmanager'),
            'A.7.3'  => __('Securing offices, rooms and facilities', 'grcmanager'),
            'A.7.4'  => __('Physical security monitoring', 'grcmanager'),
            'A.7.5'  => __('Protecting against physical and environmental threats', 'grcmanager'),
            'A.7.6'  => __('Working in secure areas', 'grcmanager'),
            'A.7.7'  => __('Clear desk and clear screen', 'grcmanager'),
            'A.7.8'  => __('Equipment siting and protection', 'grcmanager'),
            'A.7.9'  => __('Security of assets off-premises', 'grcmanager'),
            'A.7.10' => __('Storage media', 'grcmanager'),
            'A.7.11' => __('Supporting utilities', 'grcmanager'),
            'A.7.12' => __('Cabling security', 'grcmanager'),
            'A.7.13' => __('Equipment maintenance', 'grcmanager'),
            'A.7.14' => __('Secure disposal or re-use of equipment', 'grcmanager'),

            // Technological controls (A.8)
            'A.8.1'  => __('User endpoint devices', 'grcmanager'),
            'A.8.2'  => __('Privileged access rights', 'grcmanager'),
            'A.8.3'  => __('Information access restriction', 'grcmanager'),
            'A.8.4'  => __('Access to source code', 'grcmanager'),
            'A.8.5'  => __('Secure authentication', 'grcmanager'),
            'A.8.6'  => __('Capacity management', 'grcmanager'),
            'A.8.7'  => __('Protection against malware', 'grcmanager'),
            'A.8.8'  => __('Management of technical vulnerabilities', 'grcmanager'),
            'A.8.9'  => __('Configuration management', 'grcmanager'),
            'A.8.10' => __('Information deletion', 'grcmanager'),
            'A.8.11' => __('Data masking', 'grcmanager'),
            'A.8.12' => __('Data leakage prevention', 'grcmanager'),
            'A.8.13' => __('Information backup', 'grcmanager'),
            'A.8.14' => __('Redundancy of information processing facilities', 'grcmanager'),
            'A.8.15' => __('Logging', 'grcmanager'),
            'A.8.16' => __('Monitoring activities', 'grcmanager'),
            'A.8.17' => __('Clock synchronization', 'grcmanager'),
            'A.8.18' => __('Use of privileged utility programs', 'grcmanager'),
            'A.8.19' => __('Installation of software on operational systems', 'grcmanager'),
            'A.8.20' => __('Networks security', 'grcmanager'),
            'A.8.21' => __('Security of network services', 'grcmanager'),
            'A.8.22' => __('Segregation of networks', 'grcmanager'),
            'A.8.23' => __('Web filtering', 'grcmanager'),
            'A.8.24' => __('Use of cryptography', 'grcmanager'),
            'A.8.25' => __('Secure development life cycle', 'grcmanager'),
            'A.8.26' => __('Application security requirements', 'grcmanager'),
            'A.8.27' => __('Secure system architecture and engineering principles', 'grcmanager'),
            'A.8.28' => __('Secure coding', 'grcmanager'),
            'A.8.29' => __('Security testing in development and acceptance', 'grcmanager'),
            'A.8.30' => __('Outsourced development', 'grcmanager'),
            'A.8.31' => __('Separation of development, test and production environments', 'grcmanager'),
            'A.8.32' => __('Change management', 'grcmanager'),
            'A.8.33' => __('Test information', 'grcmanager'),
            'A.8.34' => __('Protection of information systems during audit testing', 'grcmanager'),
        ];
    }

    public static function getControlTitle(string $code): string
    {
        return self::getControlTitles()[$code] ?? $code;
    }

    /**
     * Enforces the ISO 27001 convention (clause 6.1.3 d): a justification is required as soon as
     * a control is not fully applicable. Returning false here aborts add()/update() the same way
     * GLPI core itself treats a false return from prepareInputForAdd()/prepareInputForUpdate(),
     * with a real, visible error message rather than a silent no-op.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    /**
     * Issue #126 : la fiche passe par displayFullPageForItem() ; son en-tête affiche le code de la
     * mesure (A.5.18…), la table n'ayant pas de colonne `name` (même correctif que Policy).
     */
    public static function getNameField()
    {
        return 'code';
    }

    /**
     * Issue #126 : preuves (Documents, dont le rapport de revue des accès) et campagnes de revue
     * des accès rattachées à la mesure.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(AccessReview::class, $tabs, $options)
            ->addStandardTab(Document_Item::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        return $this->validateAndMarkReviewed($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateAndMarkReviewed($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateAndMarkReviewed(array $input)
    {
        $applicability = (string) ($input['applicability'] ?? ($this->fields['applicability'] ?? 'yes'));
        $justification = trim((string) ($input['justification'] ?? ($this->fields['justification'] ?? '')));

        if (in_array($applicability, ['no', 'partial'], true) && $justification === '') {
            Session::addMessageAfterRedirect(
                __('A justification is required whenever a control is not fully applicable.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        // Any explicit save through this form counts as "reviewed" for the SoA completion
        // dashboard card (DashboardCardService::soaReviewedCount()), regardless of the
        // applicability chosen: an admin actively confirming "yes, applicable, no change" is
        // exactly as much a review as recording "no" with a justification.
        $input['is_reviewed'] = 1;

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
            'id'               => 1,
            'table'            => $this->getTable(),
            'field'            => 'code',
            'name'             => __('Control', 'grcmanager'),
            'datatype'         => 'specific',
            // Same convention as CommonITILObject's own "Title" column (id=1, 'datatype' =>
            // 'itemlink', 'additionalfields' => ['id']): 'id' has to be requested explicitly for
            // a search option to receive it in $values, even for the primary/first column.
            // Without this, the only clickable way back to a control's own form was a separate,
            // barely-readable numeric "ID" column (id 8 below) instead of the actual title text a
            // user would naturally click — real user feedback on this exact list.
            'additionalfields' => ['id'],
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'theme',
            'name'     => __('Theme', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'applicability',
            'name'     => __('Applicability', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'implementation_status',
            'name'     => __('Implementation status', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'justification',
            'name'     => __('Justification', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'is_reviewed',
            'name'     => __('Reviewed', 'grcmanager'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update', 'grcmanager'),
            'datatype' => 'datetime',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family, see Risk::rawSearchOptions().
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
                $code = $values[$field] ?? null;

                if ($code === null || $code === '') {
                    return '';
                }

                // Same "a list with no way back to showForm() is not self-explanatory" lesson as
                // the rest of this plugin family — but here the fix is making this column (the
                // one a user actually reads and clicks) the link, not adding a separate one: this
                // column already had a custom 'specific' display, and a bare numeric "ID" column
                // was the only clickable way back to a control's own form. Real user feedback.
                $id = (int) ($values['id'] ?? 0);

                return Fragment::link(
                    $id > 0 ? self::getFormURLWithID($id) : '',
                    self::getControlTitle((string) $code),
                    (string) $code
                );

            case 'theme':
                return self::themeBadge($values[$field] ?? null);

            case 'applicability':
                return self::applicabilityBadge($values[$field] ?? null);

            case 'implementation_status':
                return self::implementationStatusBadge($values[$field] ?? null);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widgets for theme/applicability/implementation_status, same lesson
     * (and same reason) as Risk::getSpecificValueToSelect(): 'specific' falls
     * through to a free-text box by default, not self-explanatory for a non-technical user.
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
            case 'theme':
                return Dropdown::showFromArray($name, self::getThemes(), $options);

            case 'applicability':
                return Dropdown::showFromArray($name, self::getApplicabilities(), $options);

            case 'implementation_status':
                return Dropdown::showFromArray($name, self::getImplementationStatuses(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function themeBadge(?string $value): string
    {
        $themes = self::getThemes();
        $label  = $themes[$value] ?? (string) $value;

        if ($label === '') {
            return '';
        }

        return Badge::html('bg-azure-lt', null, $label);
    }

    private static function applicabilityBadge(?string $value): string
    {
        $map = [
            'yes'     => ['bg-green-lt', 'ti-circle-check', __('Applicable', 'grcmanager')],
            'no'      => ['bg-secondary-lt', 'ti-circle-x', __('Not applicable', 'grcmanager')],
            'partial' => ['bg-yellow-lt', 'ti-circle-half-2', __('Partially applicable', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function implementationStatusBadge(?string $value): string
    {
        $map = [
            'not_started' => ['bg-secondary-lt', 'ti-player-stop', __('Not started', 'grcmanager')],
            'in_progress' => ['bg-blue-lt', 'ti-tool', __('In progress', 'grcmanager')],
            'implemented' => ['bg-green-lt', 'ti-check', __('Implemented', 'grcmanager')],
            'verified'    => ['bg-teal-lt', 'ti-shield-check', __('Verified', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    /**
     * @return array<int, string> risk id => title, for every risk linked to this control.
     */
    public static function getLinkedRisks(int $controlId): array
    {
        global $DB;

        $risks = [];

        $rows = $DB->request([
            'SELECT' => ['risks.id', 'risks.title'],
            'FROM'   => self::LINK_TABLE . ' AS links',
            'INNER JOIN' => [
                Risk::getTable() . ' AS risks' => [
                    'FKEY' => [
                        'links' => 'plugin_grcmanager_risks_id',
                        'risks' => 'id',
                    ],
                ],
            ],
            'WHERE' => ['links.plugin_grcmanager_controls_id' => $controlId],
        ]);

        foreach ($rows as $row) {
            $risks[(int) $row['id']] = $row['title'];
        }

        return $risks;
    }

    /**
     * @return array<int, int> the linked risk IDs only, for pre-selecting the form's multi-select.
     */
    public static function getLinkedRiskIds(int $controlId): array
    {
        return array_keys(self::getLinkedRisks($controlId));
    }

    /**
     * Replaces the full set of risks linked to this control with exactly $riskIds (delete then
     * re-insert): simpler and safe here given the low cardinality involved (at most a handful of
     * risks per control), unlike a diff-based sync that would only matter at a much larger scale.
     *
     * @param array<int, int> $riskIds
     */
    private static function syncLinkedRisks(int $controlId, array $riskIds): void
    {
        global $DB;

        $DB->delete(self::LINK_TABLE, ['plugin_grcmanager_controls_id' => $controlId]);

        $riskIds = array_unique(array_filter(array_map('intval', $riskIds), static fn ($id) => $id > 0));

        foreach ($riskIds as $riskId) {
            $DB->insert(self::LINK_TABLE, [
                'plugin_grcmanager_controls_id' => $controlId,
                'plugin_grcmanager_risks_id'    => $riskId,
                'date_creation'                 => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);

        // Registre sensible : un formulaire forgé sans son droit ne modifie pas les liens.
        if (array_key_exists('linked_risks', $this->input) && Risk::canView()) {
            self::syncLinkedRisks((int) $this->fields['id'], (array) $this->input['linked_risks']);
        }
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB, $CFG_GLPI;

        $this->initForm($ID, $options);
        $code = (string) ($this->fields['code'] ?? '');

        // Many-to-many link to the risk(s) this control's implementation addresses or is driven
        // by (glpi_plugin_grcmanager_controls_risks), synced in post_updateItem() above.
        // Registre sensible : titres des risques seulement avec son droit. Sans le champ,
        // l'enregistrement ne touche pas aux liens (post_updateItem() ne synchronise que si
        // linked_risks est envoyé).
        $canViewRisks = Risk::canView();
        $riskTitles = [];
        if ($canViewRisks) {
            $risks = $DB->request(['SELECT' => ['id', 'title'], 'FROM' => Risk::getTable()]);
            foreach ($risks as $row) {
                $riskTitles[(int) $row['id']] = $row['title'];
            }
        }

        // Issue #111 : mesures du guide d'hygiène ANSSI, intitulé dans la langue de la session.
        $anssiLang = str_starts_with((string) ($_SESSION['glpilanguage'] ?? 'fr_FR'), 'fr') ? 'fr' : 'en';
        $anssi = [];
        foreach (AnssiHygieneCatalogDefaults::measuresForControl($code) as $measureNumber) {
            $anssi[] = [
                'number' => $measureNumber,
                'title'  => AnssiHygieneCatalogDefaults::MEASURES[$measureNumber][$anssiLang],
            ];
        }

        // Issue #109 : preuves agrégées d'assetsign (fiches signées) pour A.5.9/A.5.10/A.5.11, et
        // taux de signature de la charte (assetsign #204) pour A.5.10 et A.6.3.
        $sheets = [];
        $summary = AssetsignEvidence::isRelevantControl($code) ? AssetsignEvidenceProvider::fetchSummary() : null;
        if ($summary !== null) {
            foreach (AssetsignEvidence::CONTROL_TYPES[$code] as $type) {
                $sheets[] = $summary[$type] + [
                    'label' => $type === AssetsignEvidence::TYPE_RETURN
                        ? __('Returns', 'grcmanager')
                        : __('Handovers', 'grcmanager'),
                    'url'   => AssetsignEvidenceProvider::listUrl($type),
                ];
            }
        }
        $charter = AssetsignEvidence::isCharterControl($code) ? AssetsignEvidenceProvider::fetchCharterSummary() : null;
        if ($charter !== null) {
            $charter = array_map(static fn (array $line): array => $line + [
                'entity' => Dropdown::getDropdownName('glpi_entities', $line['entities_id']),
            ], $charter);
        }

        TemplateRenderer::getInstance()->display('@grcmanager/control_form.html.twig', [
            'item'                    => $this,
            // Les 93 mesures sont un catalogue fixe semé à l'installation : jamais supprimées.
            'params'                  => $options + ['candel' => false],
            'code'                    => $code,
            'title'                   => self::getControlTitle($code),
            'theme_badge'             => self::themeBadge((string) ($this->fields['theme'] ?? '')),
            'applicabilities'         => self::getApplicabilities(),
            'implementation_statuses' => self::getImplementationStatuses(),
            'risks'                   => $riskTitles,
            'linked_risks'            => $this->isNewID($ID) || !$canViewRisks ? [] : self::getLinkedRiskIds((int) $ID),
            'can_view_risks'          => $canViewRisks,
            'nist'                    => ControlCrosswalkDefaults::CROSSWALK[substr($code, 2)]['nist_csf'] ?? [],
            'nis2'                    => array_map(
                static fn (string $nis2Code): string => Nis2CatalogDefaults::reference($nis2Code),
                Nis2CatalogDefaults::requirementsForControl($code)
            ),
            'anssi'                   => $anssi,
            'assetsign_sheets'        => $sheets,
            'assetsign_charter'       => $charter,
            'campaigns_url'           => $charter !== null ? AssetsignEvidenceProvider::campaignsUrl() : null,
            'referentials_url'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/referentiels.php',
        ]);

        return true;
    }
}
