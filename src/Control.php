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
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ControlBase;
use GlpiPlugin\Grcmanager\Services\Control\AnssiHygieneCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidence;
use GlpiPlugin\Grcmanager\Services\Control\CatalogLanguage;
use GlpiPlugin\Grcmanager\Services\Control\ControlAttributeCatalog;
use GlpiPlugin\Grcmanager\Services\Control\ControlAttributeSummary;
use GlpiPlugin\Grcmanager\Services\Control\AssetsignEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Control\DockerInventoryEvidence;
use GlpiPlugin\Grcmanager\Services\Control\DockerInventoryEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Control\CisControlsCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCrosswalkDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Control\DoraCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso22301CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso27701CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso42001CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\PciDssCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\AiActCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\HdsCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\Soc2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\SoaSelectionRules;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidence;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
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
 *
 * Issue #609 : the justification stays the free-text "why"; reasons for selection (several, ticked
 * from the linked risks and obligations while nobody has set them) and, for an exclusion, its
 * category, compensating controls (other controls of the same SoA, see
 * glpi_plugin_grcmanager_controls_compensations), compensating measures and residual risk complete
 * it with values one can filter and count (Services\Control\SoaSelectionRules).
 */
class Control extends ControlBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    private const LINK_TABLE = 'glpi_plugin_grcmanager_controls_risks';

    /** Issue #609 : mesures compensatoires d'une exclusion (autres mesures de la même SoA). */
    public const COMPENSATIONS_TABLE = 'glpi_plugin_grcmanager_controls_compensations';

    /** Issue #609 : options de recherche des motifs de sélection et du détail des exclusions. */
    public const SEARCH_SELECTION_REASONS = 16;
    public const SEARCH_EXCLUSION_CATEGORY = 17;
    public const SEARCH_COMPENSATING_MEASURES = 18;
    public const SEARCH_RESIDUAL_RISK = 19;
    public const SEARCH_COMPENSATING_CONTROLS = 20;

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
     * preuves (Menu::sections()).
     */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if (is_array($menu) && $menu !== []) {
            $menu['section_label'] = $menu['title'];
            $menu['title'] = __('Compliance', 'grcmanager');
        }

        return Menu::withSubPages(self::class, $menu);
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
     * Issue #609 : motifs de sélection d'une mesure (ISO/IEC 27001:2022, 6.1.3).
     *
     * @return array<string, string>
     */
    public static function getSelectionReasons(): array
    {
        return [
            SoaSelectionRules::REASON_RISK_ASSESSMENT => __('Risk assessment result', 'grcmanager'),
            SoaSelectionRules::REASON_LEGAL           => __('Legal or regulatory obligation', 'grcmanager'),
            SoaSelectionRules::REASON_CONTRACTUAL     => __('Contractual requirement', 'grcmanager'),
            SoaSelectionRules::REASON_STAKEHOLDER     => __('Interested party expectation', 'grcmanager'),
            SoaSelectionRules::REASON_BEST_PRACTICE   => __('Good practice', 'grcmanager'),
        ];
    }

    /**
     * Issue #609 : catégories d'exclusion d'une mesure non applicable.
     *
     * @return array<string, string>
     */
    public static function getExclusionCategories(): array
    {
        return [
            'activity_absent'    => __('Activity not carried out', 'grcmanager'),
            'certified_provider' => __('Handled by a certified provider', 'grcmanager'),
            'out_of_scope'       => __('Out of the ISMS scope', 'grcmanager'),
        ];
    }

    /**
     * Issue #609 : niveaux du risque résiduel d'une exclusion, ceux du registre de risques.
     *
     * @return array<string, string>
     */
    public static function getResidualRiskLevels(): array
    {
        return [
            'low'      => __('Low', 'grcmanager'),
            'medium'   => __('Medium', 'grcmanager'),
            'high'     => __('High', 'grcmanager'),
            'critical' => __('Critical', 'grcmanager'),
        ];
    }

    /**
     * Issue #609 : libellés des motifs stockés, séparés par des virgules (exports).
     */
    public static function selectionReasonLabels(?string $stored): string
    {
        $labels = self::getSelectionReasons();

        return implode(', ', array_map(
            static fn (string $reason): string => $labels[$reason] ?? $reason,
            SoaSelectionRules::decodeReasons($stored)
        ));
    }

    /**
     * Issue #609 : une pastille par motif de sélection.
     */
    public static function selectionReasonBadges(?string $stored): string
    {
        $labels = self::getSelectionReasons();
        $badges = [];
        foreach (SoaSelectionRules::decodeReasons($stored) as $reason) {
            $badges[] = Badge::html('bg-blue-lt me-1', 'ti-target', $labels[$reason] ?? $reason);
        }

        return implode('', $badges);
    }

    /**
     * Issue #609 : synthèse par thème de la SoA suivie par l'entité (par défaut l'entité active) :
     * mesures applicables, exclues, mises en œuvre, en cours et non démarrées, avec le lien vers
     * la liste filtrée sur le thème.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, int>}
     */
    public static function themeSummary(?int $entityId = null): array
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['theme', 'applicability', 'implementation_status'],
            'FROM'   => self::getTable(),
            'WHERE'  => EntityPerimeter::controlCriteria($entityId),
        ]);
        $themes = self::getThemes();
        $lines = SoaSelectionRules::themeSummary($rows, array_keys($themes));
        $withLabels = [];
        foreach ($lines as $line) {
            $withLabels[] = $line + [
                'label' => $themes[$line['theme']],
                'url'   => self::getSearchURL() . '?' . http_build_query([
                    'criteria' => [['field' => 2, 'searchtype' => 'equals', 'value' => $line['theme']]],
                ]),
            ];
        }

        return ['lines' => $withLabels, 'totals' => SoaSelectionRules::themeTotals($lines)];
    }

    /**
     * Export CSV de la SoA suivie par l'entité (par défaut l'entité active), en-tête compris
     * (ROADMAP « Version 1.5 ») : les six premières colonnes se réimportent telles quelles ; les
     * attributs (issue #610), les motifs de sélection et le détail des exclusions (issue #609)
     * sont en lecture seule, ignorés à l'import comme le titre.
     *
     * @return list<list<string>>
     */
    public static function csvExportRows(?int $entityId = null): array
    {
        global $DB;

        $titles = self::getControlTitles();
        $themes = self::getThemes();
        $applicabilities = self::getApplicabilities();
        $implementationStatuses = self::getImplementationStatuses();
        $exclusionCategories = self::getExclusionCategories();
        $residualLevels = self::getResidualRiskLevels();
        $attributeColumns = [
            ControlAttributeCatalog::CONTROL_TYPES            => 'type_mesure',
            ControlAttributeCatalog::SECURITY_PROPERTIES      => 'proprietes',
            ControlAttributeCatalog::CYBERSECURITY_CONCEPTS   => 'concepts',
            ControlAttributeCatalog::OPERATIONAL_CAPABILITIES => 'capacites',
            ControlAttributeCatalog::SECURITY_DOMAINS         => 'domaines',
        ];

        $lines = [array_merge(
            ['code', 'theme', 'titre', 'applicabilite', 'etat_mise_en_oeuvre', 'justification'],
            array_values($attributeColumns),
            [
                'motifs_selection', 'categorie_exclusion', 'mesures_compensatoires_liees',
                'mesures_compensatoires', 'risque_residuel',
            ]
        )];

        // Texte saisi librement : une cellule commencant par = + - @ (ou tabulation/retour)
        // serait executee comme formule a l'ouverture du fichier dans un tableur.
        $csvText = static fn (?string $value): string => preg_match('/^[=+\-@\t\r]/', (string) $value)
            ? "'" . $value
            : (string) $value;

        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => EntityPerimeter::controlCriteria($entityId),
            'ORDER' => 'code ASC',
        ]), false);
        $compensating = self::compensatingCodesByControl(array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows
        ));
        foreach ($rows as $row) {
            $isExclusion = SoaSelectionRules::isExclusion((string) $row['applicability']);
            $lines[] = [
                $csvText($row['code']),
                $themes[$row['theme']] ?? $csvText($row['theme']),
                $titles[$row['code']] ?? $csvText($row['code']),
                $applicabilities[$row['applicability']] ?? $csvText($row['applicability']),
                $implementationStatuses[$row['implementation_status']] ?? $csvText($row['implementation_status']),
                $csvText($row['justification']),
                ...array_map(
                    static fn (string $attribute): string => self::attributeLabels(
                        $attribute,
                        (string) ($row[$attribute] ?? '')
                    ),
                    array_keys($attributeColumns)
                ),
                self::selectionReasonLabels($row['selection_reasons'] ?? null),
                $isExclusion ? ($exclusionCategories[$row['exclusion_category'] ?? ''] ?? '') : '',
                $isExclusion ? implode(', ', $compensating[(int) $row['id']] ?? []) : '',
                $isExclusion ? $csvText($row['compensating_measures'] ?? '') : '',
                $isExclusion ? ($residualLevels[$row['residual_risk'] ?? ''] ?? '') : '',
            ];
        }

        return $lines;
    }

    /**
     * Issue #609 : codes des mesures compensatoires liées, par mesure exclue (exports, empreinte).
     *
     * @param list<int>|null $controlIds mesures voulues (par défaut toutes)
     * @return array<int, list<string>> id de la mesure exclue => codes, triés
     */
    public static function compensatingCodesByControl(?array $controlIds = null): array
    {
        global $DB;

        if ($controlIds === []) {
            return [];
        }
        $criteria = [
            'SELECT'     => ['links.plugin_grcmanager_controls_id AS control_id', 'compensating.code AS code'],
            'FROM'       => self::COMPENSATIONS_TABLE . ' AS links',
            'INNER JOIN' => [
                self::getTable() . ' AS compensating' => [
                    'FKEY' => [
                        'links'        => 'plugin_grcmanager_controls_id_compensating',
                        'compensating' => 'id',
                    ],
                ],
            ],
        ];
        if ($controlIds !== null) {
            $criteria['WHERE'] = ['links.plugin_grcmanager_controls_id' => $controlIds];
        }

        $codes = [];
        foreach ($DB->request($criteria) as $row) {
            $codes[(int) $row['control_id']][] = (string) $row['code'];
        }
        foreach ($codes as &$list) {
            usort($list, 'strnatcmp');
        }
        unset($list);

        return $codes;
    }

    /**
     * Issue #609 : mesures compensatoires liées à une mesure, id => code.
     *
     * @return array<int, string>
     */
    public static function getCompensatingControls(int $controlId): array
    {
        global $DB;

        $controls = [];
        foreach (
            $DB->request([
                'SELECT'     => ['compensating.id', 'compensating.code'],
                'FROM'       => self::COMPENSATIONS_TABLE . ' AS links',
                'INNER JOIN' => [
                    self::getTable() . ' AS compensating' => [
                        'FKEY' => [
                            'links'        => 'plugin_grcmanager_controls_id_compensating',
                            'compensating' => 'id',
                        ],
                    ],
                ],
                'WHERE'      => ['links.plugin_grcmanager_controls_id' => $controlId],
            ]) as $row
        ) {
            $controls[(int) $row['id']] = (string) $row['code'];
        }
        uasort($controls, 'strnatcmp');

        return $controls;
    }

    /**
     * Issue #609 : mesures proposées comme compensatoires d'une mesure : celles de la même SoA
     * (même entité), applicables, hors la mesure elle-même. Jamais une mesure d'une autre SoA.
     *
     * @return array<int, string> id => « code intitulé »
     */
    public static function compensatingOptions(int $entityId, int $controlId): array
    {
        global $DB;

        $options = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'entities_id'   => $entityId,
                    'NOT'           => ['id' => $controlId],
                    'applicability' => ['yes', 'partial'],
                ],
            ]) as $row
        ) {
            $code = (string) $row['code'];
            $options[(int) $row['id']] = $code . ' ' . self::getControlTitle($code);
        }
        uasort($options, 'strnatcmp');

        return $options;
    }

    /**
     * Issue #609 : remplace les mesures compensatoires liées par $controlIds, limitées aux mesures
     * proposées (même SoA, applicables) : un formulaire forgé ne lie pas une mesure d'une autre
     * entité.
     *
     * @param array<int|string, mixed> $controlIds
     */
    private static function syncCompensatingControls(int $controlId, int $entityId, array $controlIds): void
    {
        global $DB;

        $DB->delete(self::COMPENSATIONS_TABLE, ['plugin_grcmanager_controls_id' => $controlId]);

        $allowed = array_keys(self::compensatingOptions($entityId, $controlId));
        $controlIds = array_intersect(
            array_unique(array_map('intval', $controlIds)),
            $allowed
        );
        foreach ($controlIds as $compensatingId) {
            $DB->insert(self::COMPENSATIONS_TABLE, [
                'plugin_grcmanager_controls_id'              => $controlId,
                'plugin_grcmanager_controls_id_compensating' => $compensatingId,
                'date_creation'                              => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Issue #609 : copie les mesures compensatoires d'une SoA vers une autre, par code (création
     * de la SoA propre à une entité, EntityPerimeter::createSoa()).
     *
     * @param array<string, int> $sourceIds code => id dans la SoA d'origine
     * @param array<string, int> $targetIds code => id dans la nouvelle SoA
     */
    public static function copyCompensations(array $sourceIds, array $targetIds): void
    {
        global $DB;

        $sourceCodes = array_flip($sourceIds);
        foreach (self::compensatingCodesByControl(array_values($sourceIds)) as $sourceId => $codes) {
            $targetId = $targetIds[$sourceCodes[$sourceId] ?? ''] ?? 0;
            foreach ($codes as $code) {
                $compensatingId = $targetIds[$code] ?? 0;
                if ($targetId <= 0 || $compensatingId <= 0) {
                    continue;
                }
                $DB->insert(self::COMPENSATIONS_TABLE, [
                    'plugin_grcmanager_controls_id'              => $targetId,
                    'plugin_grcmanager_controls_id_compensating' => $compensatingId,
                    'date_creation'                              => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /**
     * Issue #609 : motifs déduits des liens de la mesure : risques liés (visibles avec le droit du
     * registre des risques, dans les entités de la session) et types des obligations du registre
     * rattachées à ces risques.
     *
     * @param list<int> $riskIds risques liés visibles
     * @return list<string>
     */
    public static function suggestedReasons(array $riskIds): array
    {
        global $DB;

        $obligationTypes = [];
        if ($riskIds !== []) {
            foreach (
                $DB->request([
                    'SELECT'   => ['type'],
                    'DISTINCT' => true,
                    'FROM'     => ComplianceObligation::getTable(),
                    'WHERE'    => ['plugin_grcmanager_risks_id' => $riskIds],
                ]) as $row
            ) {
                $obligationTypes[] = (string) $row['type'];
            }
        }

        return SoaSelectionRules::suggestedReasons($riskIds !== [], $obligationTypes);
    }

    /**
     * Issue #610 : noms des cinq attributs des mesures (ISO/IEC 27002:2022), par colonne.
     *
     * @return array<string, string>
     */
    public static function getAttributeNames(): array
    {
        return [
            ControlAttributeCatalog::CONTROL_TYPES            => __('Control type', 'grcmanager'),
            ControlAttributeCatalog::SECURITY_PROPERTIES      => __('Security properties', 'grcmanager'),
            ControlAttributeCatalog::CYBERSECURITY_CONCEPTS   => __('Cybersecurity concepts', 'grcmanager'),
            ControlAttributeCatalog::OPERATIONAL_CAPABILITIES => __('Operational capabilities', 'grcmanager'),
            ControlAttributeCatalog::SECURITY_DOMAINS         => __('Security domains', 'grcmanager'),
        ];
    }

    /**
     * Issue #610 : libellés des valeurs d'un attribut, dans l'ordre du catalogue.
     *
     * @return array<string, string>
     */
    public static function getAttributeValues(string $attribute): array
    {
        $labels = [
            ControlAttributeCatalog::CONTROL_TYPES => [
                'preventive' => __('Preventive', 'grcmanager'),
                'detective'  => __('Detective', 'grcmanager'),
                'corrective' => __('Corrective', 'grcmanager'),
            ],
            ControlAttributeCatalog::SECURITY_PROPERTIES => [
                'confidentiality' => __('Confidentiality', 'grcmanager'),
                'integrity'       => __('Integrity', 'grcmanager'),
                'availability'    => __('Availability', 'grcmanager'),
            ],
            ControlAttributeCatalog::CYBERSECURITY_CONCEPTS => [
                'identify' => __('Identify', 'grcmanager'),
                'protect'  => __('Protect', 'grcmanager'),
                'detect'   => __('Detect', 'grcmanager'),
                'respond'  => __('Respond', 'grcmanager'),
                'recover'  => __('Recover', 'grcmanager'),
            ],
            ControlAttributeCatalog::OPERATIONAL_CAPABILITIES => [
                'governance'                      => __('Governance', 'grcmanager'),
                'asset_management'                => __('Asset management', 'grcmanager'),
                'information_protection'          => __('Information protection', 'grcmanager'),
                'human_resource_security'         => __('Human resource security', 'grcmanager'),
                'physical_security'               => __('Physical security', 'grcmanager'),
                'system_network_security'         => __('System and network security', 'grcmanager'),
                'application_security'            => __('Application security', 'grcmanager'),
                'secure_configuration'            => __('Secure configuration', 'grcmanager'),
                'identity_access_management'      => __('Identity and access management', 'grcmanager'),
                'threat_vulnerability_management' => __('Threat and vulnerability management', 'grcmanager'),
                'continuity'                      => __('Continuity', 'grcmanager'),
                'supplier_relationships_security' => __('Supplier relationships security', 'grcmanager'),
                'legal_compliance'                => __('Legal and compliance', 'grcmanager'),
                'event_management'                => __('Information security event management', 'grcmanager'),
                'assurance'                       => __('Information security assurance', 'grcmanager'),
            ],
            ControlAttributeCatalog::SECURITY_DOMAINS => [
                'governance_ecosystem' => __('Governance and ecosystem', 'grcmanager'),
                'protection'           => __('Protection', 'grcmanager'),
                'defence'              => __('Defence', 'grcmanager'),
                'resilience'           => __('Resilience', 'grcmanager'),
            ],
        ];

        return $labels[$attribute] ?? [];
    }

    /**
     * Issue #610 : numéro de l'option de recherche de chaque attribut (11 à 15).
     *
     * @return array<int, string>
     */
    public static function attributeSearchOptions(): array
    {
        return array_combine(range(11, 15), ControlAttributeCatalog::attributes());
    }

    /**
     * Issue #610 : adresse de la liste des mesures applicables filtrée sur une valeur d'attribut.
     */
    public static function attributeSearchURL(string $attribute, string $value): string
    {
        $option = array_search($attribute, self::attributeSearchOptions(), true);

        return self::getSearchURL() . '?' . http_build_query([
            'criteria' => [
                ['field' => $option, 'searchtype' => 'equals', 'value' => $value],
                ['link' => 'AND', 'field' => 3, 'searchtype' => 'notequals', 'value' => 'no'],
            ],
        ]);
    }

    /**
     * Issue #610 : critère de recherche d'une option d'attribut (11 à 15), null pour une autre
     * option ou une valeur inconnue. Les valeurs d'un même attribut ne se contiennent pas l'une
     * l'autre (ControlAttributeCatalog::VALUES) : la recherche dans la liste stockée suffit.
     *
     * @return array<string, mixed>|null
     */
    public static function attributeSearchCriteria(int $option, string $value, string $searchtype, bool $nott): ?array
    {
        // Issue #609 : motifs de sélection, stockés de la même façon que les attributs.
        $lists = [self::SEARCH_SELECTION_REASONS => SoaSelectionRules::REASONS];
        foreach (self::attributeSearchOptions() as $attributeOption => $attributeName) {
            $lists[$attributeOption] = ControlAttributeCatalog::VALUES[$attributeName];
        }
        $attribute = $option === self::SEARCH_SELECTION_REASONS
            ? 'selection_reasons'
            : (self::attributeSearchOptions()[$option] ?? null);
        if (
            $attribute === null
            || !in_array($value, $lists[$option], true)
            || !in_array($searchtype, ['equals', 'notequals'], true)
        ) {
            return null;
        }
        $exclude = ($searchtype === 'notequals') !== $nott;
        $column = self::getTable() . '.' . $attribute;

        return $exclude
            ? ['OR' => [[$column => null], [$column => ['NOT LIKE', '%' . $value . '%']]]]
            : [$column => ['LIKE', '%' . $value . '%']];
    }

    /**
     * Issue #610 : synthèse de la SoA suivie par l'entité (par défaut l'entité active) pour chaque
     * attribut : par valeur, mesures applicables, mises en œuvre, taux et lien vers la liste filtrée.
     *
     * @param list<string>|null $attributes attributs voulus (par défaut les cinq)
     * @return list<array{attribute: string, label: string, class: string, icon: string,
     *     lines: list<array{value: string, label: string, url: string, total: int, applicable: int,
     *     implemented: int, in_progress: int, rate: ?float}>}>
     */
    public static function attributeSummary(?int $entityId = null, ?array $attributes = null): array
    {
        global $DB;

        $attributes ??= ControlAttributeCatalog::attributes();
        $rows = iterator_to_array($DB->request([
            'SELECT' => array_merge(['applicability', 'implementation_status'], $attributes),
            'FROM'   => self::getTable(),
            'WHERE'  => EntityPerimeter::controlCriteria($entityId),
        ]), false);

        $names = self::getAttributeNames();
        $summary = [];
        foreach ($attributes as $attribute) {
            $labels = self::getAttributeValues($attribute);
            [$class, $icon] = self::attributeStyle($attribute);
            $lines = [];
            foreach (ControlAttributeSummary::byValue($rows, $attribute) as $line) {
                $lines[] = $line + [
                    'label' => $labels[$line['value']] ?? $line['value'],
                    'url'   => self::attributeSearchURL($attribute, $line['value']),
                ];
            }
            $summary[] = [
                'attribute' => $attribute,
                'label'     => $names[$attribute] ?? $attribute,
                'class'     => $class,
                'icon'      => $icon,
                'lines'     => $lines,
            ];
        }

        return $summary;
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
        // Issue #536 : une mesure appartient à la SoA d'une entité, visible de ses sous-entités.
        $input['entities_id']  = (int) ($input['entities_id'] ?? EntityPerimeter::activeEntity());
        $input['is_recursive'] = 1;

        // Issue #610 : une mesure créée (SoA d'une entité) reçoit les attributs livrés du catalogue.
        foreach (ControlAttributeCatalog::defaultsFor((string) ($input['code'] ?? '')) as $attribute => $stored) {
            if (!array_key_exists($attribute, $input)) {
                $input[$attribute] = $stored;
            }
        }

        return $this->validateAndMarkReviewed($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        // Issue #536 : une mesure ne change ni d'entité ni de code (un code par SoA d'entité), et
        // reste visible des sous-entités qui suivent cette SoA.
        unset($input['entities_id'], $input['is_recursive'], $input['code']);

        // Issue #609 : un risque nouvellement lié coche le motif « appréciation des risques »
        // (registre sensible : seulement avec son droit, comme la synchronisation des liens).
        if (!array_key_exists('selection_reasons', $input) && !empty($input['_grcmanager_selection'])) {
            $input['selection_reasons'] = '';
        }
        if (array_key_exists('linked_risks', $input) && Risk::canView() && !$this->isNewItem()) {
            $current = self::getLinkedRiskIds((int) $this->getID());
            $added = array_diff(self::visibleRiskIds((array) $input['linked_risks']), $current);
            if ($added !== []) {
                $stored = array_key_exists('selection_reasons', $input)
                    ? SoaSelectionRules::encodeReasons($input['selection_reasons'])
                    : ($this->fields['selection_reasons'] ?? null);
                $input['selection_reasons'] = SoaSelectionRules::addReason(
                    $stored,
                    SoaSelectionRules::REASON_RISK_ASSESSMENT
                );
            }
        }

        return $this->validateAndMarkReviewed($input);
    }

    /**
     * Issue #536 : la recherche, les listes déroulantes et l'API ne montrent que les mesures des
     * SoA suivies par les entités actives (dans une entité qui tient sa SoA, les siennes ; à la
     * racine en vue arborescente, toutes). Sans cela, une entité dotée de sa SoA verrait chaque
     * mesure deux fois : la sienne et celle, récursive, de la SoA commune.
     */
    public static function getSystemSQLCriteria(?string $tablename = null): array
    {
        if (!EntityPerimeter::hasSession()) {
            return [];
        }
        $field = ($tablename !== null && $tablename !== '' ? $tablename . '.' : '') . 'entities_id';

        return [$field => EntityPerimeter::activeSoaEntities()];
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

        // Issue #610 : attributs saisis dans le formulaire (listes à choix multiples). Une liste
        // vidée n'est pas envoyée par le navigateur : le marqueur du formulaire la remet à vide.
        foreach (ControlAttributeCatalog::attributes() as $attribute) {
            if (array_key_exists($attribute, $input)) {
                $input[$attribute] = ControlAttributeCatalog::encode($attribute, $input[$attribute]);
            } elseif (!empty($input['_grcmanager_attributes'])) {
                $input[$attribute] = '';
            }
        }

        // Issue #609 : motifs de sélection (cases à cocher ; toutes décochées, rien n'est envoyé :
        // le marqueur du formulaire les remet à vide) et détail d'une exclusion, vidé quand la
        // mesure redevient pleinement applicable.
        if (array_key_exists('selection_reasons', $input)) {
            $input['selection_reasons'] = SoaSelectionRules::encodeReasons($input['selection_reasons']);
        } elseif (!empty($input['_grcmanager_selection'])) {
            $input['selection_reasons'] = '';
        }
        if (array_key_exists('applicability', $input) && !SoaSelectionRules::isExclusion($applicability)) {
            foreach (SoaSelectionRules::EXCLUSION_FIELDS as $field) {
                $input[$field] = '';
            }
        } else {
            if (array_key_exists('exclusion_category', $input)) {
                $input['exclusion_category'] = SoaSelectionRules::normalizeChoice(
                    $input['exclusion_category'],
                    SoaSelectionRules::EXCLUSION_CATEGORIES
                );
            }
            if (array_key_exists('residual_risk', $input)) {
                $input['residual_risk'] = SoaSelectionRules::normalizeChoice(
                    $input['residual_risk'],
                    SoaSelectionRules::RESIDUAL_LEVELS
                );
            }
            if (array_key_exists('compensating_measures', $input)) {
                $input['compensating_measures'] = trim((string) $input['compensating_measures']);
            }
        }

        // Issue #380 : plan de test d'efficacité (fréquence, méthode, responsable).
        if (array_key_exists('test_frequency_months', $input)) {
            $input['test_frequency_months'] = ControlTestRules::normalizeFrequency($input['test_frequency_months']);
        }
        if (array_key_exists('test_method', $input)) {
            $input['test_method'] = ControlTestRules::normalizePlanMethod($input['test_method']);
        }
        if (array_key_exists('users_id_tester', $input)) {
            $input['users_id_tester'] = max(0, (int) $input['users_id_tester']);
        }

        // Issue #380 : le plan de test et l'état posé par un test (ControlTest) ne sont pas une
        // revue de la SoA. Issue #536 : une SoA d'entité qui vient d'être créée non plus.
        if (!empty($input['_grcmanager_from_test']) || !empty($input['_grcmanager_new_soa'])) {
            return $input;
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
            // user would naturally click: real user feedback on this exact list.
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

        // Issue #380 : dernier test d'efficacité et échéance du suivant, calculés par la base
        // (mêmes règles que ControlTest::nextDueDate() : échéance saisie avec le dernier test,
        // sinon sa date plus la fréquence du plan ; DATE_ADD ramène le 31 au dernier jour du mois).
        // TABLE est remplacé par la table de la recherche (SQLProvider, option « computation »).
        $tests = ControlTest::getTable();
        $tab[] = [
            'id'            => 9,
            'table'         => $this->getTable(),
            'field'         => 'id',
            'name'          => __('Last test', 'grcmanager'),
            'datatype'      => 'date',
            'massiveaction' => false,
            'computation'   => "(SELECT MAX(`t`.`test_date`) FROM `{$tests}` AS `t`"
                . ' WHERE `t`.`plugin_grcmanager_controls_id` = TABLE.`id`)',
        ];
        $tab[] = [
            'id'            => 10,
            'table'         => $this->getTable(),
            'field'         => 'id',
            'name'          => __('Next test', 'grcmanager'),
            'datatype'      => 'date',
            'massiveaction' => false,
            'computation'   => '(SELECT COALESCE(`t`.`next_test_date`, IF(TABLE.`test_frequency_months` > 0,'
                . ' DATE_ADD(`t`.`test_date`, INTERVAL TABLE.`test_frequency_months` MONTH), NULL))'
                . " FROM `{$tests}` AS `t` WHERE `t`.`plugin_grcmanager_controls_id` = TABLE.`id`"
                . ' ORDER BY `t`.`test_date` DESC, `t`.`id` DESC LIMIT 1)',
        ];

        // Issue #610 : attributs des mesures ; filtre « est » / « n'est pas » sur une valeur
        // (plugin_grcmanager_addWhere() la cherche dans la liste stockée).
        $attributeNames = self::getAttributeNames();
        foreach (self::attributeSearchOptions() as $option => $attribute) {
            $tab[] = [
                'id'            => $option,
                'table'         => $this->getTable(),
                'field'         => $attribute,
                'name'          => $attributeNames[$attribute],
                'datatype'      => 'specific',
                'searchtype'    => ['equals', 'notequals'],
                'massiveaction' => false,
            ];
        }

        // Issue #609 : motifs de sélection (filtre « est » / « n'est pas » sur un motif, comme les
        // attributs) et détail des exclusions.
        $tab[] = [
            'id'            => self::SEARCH_SELECTION_REASONS,
            'table'         => $this->getTable(),
            'field'         => 'selection_reasons',
            'name'          => __('Reasons for selection', 'grcmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => self::SEARCH_EXCLUSION_CATEGORY,
            'table'         => $this->getTable(),
            'field'         => 'exclusion_category',
            'name'          => __('Exclusion category', 'grcmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => self::SEARCH_COMPENSATING_MEASURES,
            'table'         => $this->getTable(),
            'field'         => 'compensating_measures',
            'name'          => __('Compensating measures', 'grcmanager'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => self::SEARCH_RESIDUAL_RISK,
            'table'         => $this->getTable(),
            'field'         => 'residual_risk',
            'name'          => __('Residual risk', 'grcmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        // Codes des mesures compensatoires liées (même SoA), calculés par la base.
        $compensations = self::COMPENSATIONS_TABLE;
        $tab[] = [
            'id'            => self::SEARCH_COMPENSATING_CONTROLS,
            'table'         => $this->getTable(),
            'field'         => 'id',
            'name'          => __('Compensating controls', 'grcmanager'),
            'datatype'      => 'string',
            'nosearch'      => true,
            'massiveaction' => false,
            'computation'   => '(SELECT GROUP_CONCAT(`c`.`code` ORDER BY `c`.`code` SEPARATOR \', \')'
                . " FROM `{$compensations}` AS `l` INNER JOIN `" . self::getTable() . '` AS `c`'
                . ' ON `c`.`id` = `l`.`plugin_grcmanager_controls_id_compensating`'
                . ' WHERE `l`.`plugin_grcmanager_controls_id` = TABLE.`id`)',
        ];

        // Issue #536 : entité de la SoA à laquelle appartient la mesure.
        $tab[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
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
                // the rest of this plugin family: but here the fix is making this column (the
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

            // Issue #609 : motifs de sélection et détail des exclusions.
            case 'selection_reasons':
                return self::selectionReasonBadges($values[$field] ?? null);

            case 'exclusion_category':
                $category = (string) ($values[$field] ?? '');

                return $category === ''
                    ? ''
                    : Badge::html('bg-secondary-lt', 'ti-ban', self::getExclusionCategories()[$category] ?? $category);

            case 'residual_risk':
                $level = (string) ($values[$field] ?? '');

                return $level === '' ? '' : Risk::riskLevelBadge($level);
        }

        if (ControlAttributeCatalog::isAttribute((string) $field)) {
            return self::attributeBadges((string) $field, (string) ($values[$field] ?? ''));
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

            case 'selection_reasons':
                return Dropdown::showFromArray($name, self::getSelectionReasons(), $options);

            case 'exclusion_category':
                return Dropdown::showFromArray($name, self::getExclusionCategories(), $options);

            case 'residual_risk':
                return Dropdown::showFromArray($name, self::getResidualRiskLevels(), $options);
        }

        if (ControlAttributeCatalog::isAttribute((string) $field)) {
            return Dropdown::showFromArray($name, self::getAttributeValues((string) $field), $options);
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

    /**
     * Issue #610 : couleur et icône Tabler de chaque attribut.
     *
     * @return array{string, string}
     */
    public static function attributeStyle(string $attribute): array
    {
        return [
            ControlAttributeCatalog::CONTROL_TYPES            => ['bg-indigo-lt', 'ti-shield-half'],
            ControlAttributeCatalog::SECURITY_PROPERTIES      => ['bg-teal-lt', 'ti-lock'],
            ControlAttributeCatalog::CYBERSECURITY_CONCEPTS   => ['bg-orange-lt', 'ti-target-arrow'],
            ControlAttributeCatalog::OPERATIONAL_CAPABILITIES => ['bg-cyan-lt', 'ti-tool'],
            ControlAttributeCatalog::SECURITY_DOMAINS         => ['bg-purple-lt', 'ti-layout-grid'],
        ][$attribute] ?? ['bg-secondary-lt', 'ti-tag'];
    }

    /**
     * Issue #610 : valeurs d'un attribut, une pastille par valeur.
     */
    public static function attributeBadges(string $attribute, ?string $stored): string
    {
        [$class, $icon] = self::attributeStyle($attribute);
        $labels = self::getAttributeValues($attribute);
        $badges = [];
        foreach (ControlAttributeCatalog::decode($attribute, $stored) as $value) {
            $badges[] = Badge::html($class . ' me-1', $icon, $labels[$value] ?? $value);
        }

        return implode('', $badges);
    }

    /**
     * Issue #610 : libellés des valeurs d'un attribut, séparés par des virgules (exports).
     */
    public static function attributeLabels(string $attribute, ?string $stored): string
    {
        $labels = self::getAttributeValues($attribute);

        return implode(', ', array_map(
            static fn (string $value): string => $labels[$value] ?? $value,
            ControlAttributeCatalog::decode($attribute, $stored)
        ));
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
            'WHERE' => array_merge(
                ['links.plugin_grcmanager_controls_id' => $controlId],
                EntityPerimeter::criteria('risks')
            ),
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

        // Issue #536 : seuls les liens vers des risques visibles (entités de la session) sont
        // remplacés ; un lien vers un risque d'une autre entité est conservé, jamais créé.
        $visibleLinked = self::getLinkedRiskIds($controlId);
        if ($visibleLinked !== []) {
            $DB->delete(self::LINK_TABLE, [
                'plugin_grcmanager_controls_id' => $controlId,
                'plugin_grcmanager_risks_id'    => $visibleLinked,
            ]);
        }

        foreach (self::visibleRiskIds($riskIds) as $riskId) {
            $link = ['plugin_grcmanager_controls_id' => $controlId, 'plugin_grcmanager_risks_id' => $riskId];
            if (countElementsInTable(self::LINK_TABLE, $link) > 0) {
                continue;
            }
            $DB->insert(self::LINK_TABLE, [
                'plugin_grcmanager_controls_id' => $controlId,
                'plugin_grcmanager_risks_id'    => $riskId,
                'date_creation'                 => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Risques parmi $riskIds qui existent et sont visibles (entités de la session), sans doublon.
     *
     * @param array<int|string, mixed> $riskIds
     * @return list<int>
     */
    private static function visibleRiskIds(array $riskIds): array
    {
        global $DB;

        $riskIds = array_values(array_unique(array_filter(
            array_map('intval', $riskIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($riskIds === []) {
            return [];
        }
        $visible = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Risk::getTable(),
                'WHERE'  => array_merge(['id' => $riskIds], EntityPerimeter::criteria(Risk::getTable())),
            ]) as $row
        ) {
            $visible[] = (int) $row['id'];
        }

        return array_values(array_intersect($riskIds, $visible));
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);

        // Registre sensible : un formulaire forgé sans son droit ne modifie pas les liens.
        if (array_key_exists('linked_risks', $this->input) && Risk::canView()) {
            self::syncLinkedRisks((int) $this->fields['id'], (array) $this->input['linked_risks']);
        }

        // Issue #609 : mesures compensatoires, retirées quand la mesure redevient applicable.
        // Même droit que le reste de la mesure (update() n'arrive ici qu'après son contrôle).
        $controlId = (int) $this->fields['id'];
        if (!SoaSelectionRules::isExclusion((string) ($this->fields['applicability'] ?? 'yes'))) {
            global $DB;
            $DB->delete(self::COMPENSATIONS_TABLE, ['plugin_grcmanager_controls_id' => $controlId]);
        } elseif (
            array_key_exists('compensating_controls', $this->input)
            || !empty($this->input['_grcmanager_exclusion'])
        ) {
            self::syncCompensatingControls(
                $controlId,
                (int) $this->fields['entities_id'],
                (array) ($this->input['compensating_controls'] ?? [])
            );
        }
    }

    /**
     * Issue #610 : attributs de la mesure pour le formulaire (listes à choix multiples).
     *
     * @param array<string, mixed> $fields
     * @return list<array{field: string, label: string, options: array<string, string>, values: list<string>}>
     */
    private static function formAttributes(array $fields): array
    {
        $attributes = [];
        foreach (self::getAttributeNames() as $attribute => $label) {
            $attributes[] = [
                'field'   => $attribute,
                'label'   => $label,
                'options' => self::getAttributeValues($attribute),
                'values'  => ControlAttributeCatalog::decode($attribute, (string) ($fields[$attribute] ?? '')),
            ];
        }

        return $attributes;
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
            $risks = $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => Risk::getTable(),
                'WHERE'  => EntityPerimeter::criteria(Risk::getTable()),
            ]);
            foreach ($risks as $row) {
                $riskTitles[(int) $row['id']] = $row['title'];
            }
        }

        // Issue #403 : correspondances des référentiels désactivés masquées.
        $showNis2 = FrameworkConfig::isEnabled(FrameworkSelection::NIS2);
        $showDora = FrameworkConfig::isEnabled(FrameworkSelection::DORA);
        $showIso27701 = FrameworkConfig::isEnabled(FrameworkSelection::ISO27701);
        $showIso22301 = FrameworkConfig::isEnabled(FrameworkSelection::ISO22301);
        $showIso42001 = FrameworkConfig::isEnabled(FrameworkSelection::ISO42001);
        $showPciDss = FrameworkConfig::isEnabled(FrameworkSelection::PCI_DSS);
        $showSoc2 = FrameworkConfig::isEnabled(FrameworkSelection::SOC2);
        $showAiAct = FrameworkConfig::isEnabled(FrameworkSelection::AI_ACT);
        $showHds = FrameworkConfig::isEnabled(FrameworkSelection::HDS);
        $showNist = FrameworkConfig::isEnabled(FrameworkSelection::NIST_CSF);
        // Issue #537 : exigences des référentiels personnalisés actifs qui citent cette mesure.
        $customFrameworks = [];
        foreach (CustomFrameworkCatalog::all() as $customKey => $customFramework) {
            $customCodes = FrameworkConfig::isEnabled($customKey)
                ? CustomFrameworkCatalog::requirementsForControl($customKey, $code)
                : [];
            if ($customCodes !== []) {
                $customFrameworks[] = [
                    'label' => $customFramework['label'],
                    'codes' => $customCodes,
                    'url'   => CustomFramework::getFormURL() . '?id=' . $customFramework['id'],
                ];
            }
        }
        $measures = FrameworkConfig::isEnabled(FrameworkSelection::ANSSI)
            ? AnssiHygieneCatalogDefaults::measuresForControl($code)
            : [];

        // Issue #111 : mesures du guide d'hygiène ANSSI, intitulé dans la langue de la session (issue
        // #582 : sinon l'anglais, voir CatalogLanguage).
        $anssi = [];
        foreach ($measures as $measureNumber) {
            $anssi[] = [
                'number' => $measureNumber,
                'title'  => CatalogLanguage::pick(AnssiHygieneCatalogDefaults::MEASURES[$measureNumber]),
            ];
        }

        // Issue #143 : contrôles CIS (correspondance indicative), intitulés officiels non traduits.
        $cis = [];
        if (FrameworkConfig::isEnabled(FrameworkSelection::CIS_CONTROLS)) {
            foreach (CisControlsCatalogDefaults::controlsForAnnexA($code) as $cisNumber) {
                $cis[] = ['number' => $cisNumber, 'title' => CisControlsCatalogDefaults::CONTROLS[$cisNumber]];
            }
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

        // Issue #368 : couverture Wazuh (A.8.16, A.8.7), ligne absente si le plugin Wazuh n'est pas exploitable.
        $wazuh = WazuhEvidence::isRelevantControl($code) ? WazuhEvidenceProvider::fetchCoverage() : null;
        // Issue #707 : couverture SentinelOne, d'après la dernière lecture de la tâche automatique.
        $sentinelone = WazuhEvidence::isRelevantControl($code)
            ? \GlpiPlugin\Grcmanager\Services\Control\SentinelOneEvidenceProvider::fetchCoverage()
            : null;

        // Docker Inventory #10 : preuves du plugin Inventaire Docker (A.5.9, A.8.8, A.8.9, A.8.32), lues
        // par son API ; ligne absente si le plugin n'est pas exploitable.
        $docker = DockerInventoryEvidence::isRelevantControl($code)
            ? DockerInventoryEvidenceProvider::fetchForControl($code)
            : null;

        // Issue #609 : motifs de sélection (déduits des risques et obligations liés tant que
        // personne ne les a renseignés) et mesures compensatoires de la même SoA.
        $linkedRiskIds = $this->isNewID($ID) || !$canViewRisks ? [] : self::getLinkedRiskIds((int) $ID);
        $suggestedReasons = self::suggestedReasons($linkedRiskIds);
        $storedReasons = $this->fields['selection_reasons'] ?? null;
        $entityId = (int) ($this->fields['entities_id'] ?? EntityPerimeter::soaEntity());

        TemplateRenderer::getInstance()->display('@grcmanager/control_form.html.twig', [
            'item'                    => $this,
            'selection_reasons'       => self::getSelectionReasons(),
            'checked_reasons'         => SoaSelectionRules::formReasons(
                $storedReasons === null ? null : (string) $storedReasons,
                $suggestedReasons
            ),
            'suggested_reasons'       => $suggestedReasons,
            'reasons_never_set'       => $storedReasons === null,
            'exclusion_categories'    => self::getExclusionCategories(),
            'residual_levels'         => self::getResidualRiskLevels(),
            'compensating_options'    => self::compensatingOptions($entityId, (int) ($this->fields['id'] ?? 0)),
            'compensating_controls'   => $this->isNewID($ID)
                ? []
                : array_keys(self::getCompensatingControls((int) $ID)),
            // Les 93 mesures sont un catalogue fixe semé à l'installation : jamais supprimées.
            'params'                  => $options + ['candel' => false],
            'code'                    => $code,
            'title'                   => self::getControlTitle($code),
            'theme_badge'             => self::themeBadge((string) ($this->fields['theme'] ?? '')),
            'applicabilities'         => self::getApplicabilities(),
            'implementation_statuses' => self::getImplementationStatuses(),
            'attributes'              => self::formAttributes($this->fields),
            'risks'                   => $riskTitles,
            'linked_risks'            => $linkedRiskIds,
            'can_view_risks'          => $canViewRisks,
            'nist'                    => $showNist
                ? (ControlCrosswalkDefaults::CROSSWALK[substr($code, 2)]['nist_csf'] ?? [])
                : [],
            'nis2'                    => $showNis2 ? array_map(
                static fn (string $nis2Code): string => Nis2CatalogDefaults::reference($nis2Code),
                Nis2CatalogDefaults::requirementsForControl($code)
            ) : [],
            'dora'                    => $showDora ? array_map(
                static fn (string $doraCode): string => DoraCatalogDefaults::reference($doraCode),
                DoraCatalogDefaults::requirementsForControl($code)
            ) : [],
            'iso27701'                => $showIso27701 ? Iso27701CatalogDefaults::objectivesForControl($code) : [],
            'iso22301'                => $showIso22301 ? Iso22301CatalogDefaults::requirementsForControl($code) : [],
            'iso42001'                => $showIso42001 ? Iso42001CatalogDefaults::objectivesForControl($code) : [],
            'pci_dss'                 => $showPciDss ? PciDssCatalogDefaults::requirementsForControl($code) : [],
            'soc2'                    => $showSoc2 ? Soc2CatalogDefaults::criteriaForControl($code) : [],
            'ai_act'                  => $showAiAct ? AiActCatalogDefaults::articlesForControl($code) : [],
            'hds'                     => $showHds ? HdsCatalogDefaults::requirementsForControl($code) : [],
            'custom_frameworks'       => $customFrameworks,
            'anssi'                   => $anssi,
            'cis'                     => $cis,
            'assetsign_sheets'        => $sheets,
            'assetsign_charter'       => $charter,
            'campaigns_url'           => $charter !== null ? AssetsignEvidenceProvider::campaignsUrl() : null,
            'wazuh'                   => $wazuh,
            'sentinelone_coverage'    => $sentinelone,
            'wazuh_agents_url'        => $wazuh !== null ? WazuhEvidenceProvider::agentsUrl() : null,
            'docker'                  => $docker,
            // Issue #704 : fin de support du parc (endoflife.date), preuve des mesures A.8.8 et A.8.9.
            'endoflife'               => EndOfLife::evidence($code),
            // Issue #782 : fin de garantie du matériel, preuve des mesures A.7.13 et A.8.9.
            'hardware_warranty'       => HardwareWarranty::evidence($code),
            // Issue #771 : registre des contacts officiels, preuve des mesures A.5.5 et A.5.6.
            'official_contacts'       => OfficialContact::evidence($code),
            // Issue #778 : évaluations de la sécurité physique des sites, preuve des mesures A.7.1 à A.7.14.
            'physical_security'       => PhysicalSecurity::evidence($code),
            // Issue #616 : communication de crise (modèles, listes de diffusion, traces), mesure A.5.26.
            'crisis_communication'    => CrisisCommunication::evidence($code),
            // Issue #776 : scénarios de crise sécurité de l'information du PCA, mesure A.5.29.
            'crisis_scenarios'        => CrisisScenario::evidence($code),
            // Issue #775 : registre des procédures d'exploitation, preuve de la mesure A.5.37.
            'operating_procedures'    => OperatingProcedure::evidence($code),
            // Issue #779 : conformité des licences logicielles, preuve de la mesure A.5.32.
            'software_licenses'       => SoftwareLicenseCompliance::evidence($code),
            'referentials_url'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/referentiels.php',
        ]);

        return true;
    }
}
