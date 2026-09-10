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

/**
 * Declaration of Applicability (SoA, ISO 27001:2022 clause 6.1.3): the 93 ISO/IEC 27001:2022
 * Annex A controls, one row per control, seeded once at install (see
 * src/Install/Installer::seedControls(), src/Services/Control/ControlCatalogDefaults for the pure
 * code/theme data). Only the stable `code` (e.g. "A.5.1") and `theme` are seeded; the translated
 * title is never stored in the database, resolved instead from self::getControlTitles(), the same
 * separation PluginGrcmanagerRisk already applies to its own category/probability/impact enums.
 *
 * Per-control fields an admin actually edits: applicability (yes/no/partial), justification
 * (required by this class' own validation whenever applicability isn't "yes", per ISO 27001
 * convention), implementation status, and a link to the risk(s) that justify or drive that
 * control's implementation (many-to-many via glpi_plugin_grcmanager_controls_risks, see
 * getLinkedRiskIds()/syncLinkedRisks() below).
 */
class PluginGrcmanagerControl extends CommonDBTM
{
    public static $rightname = 'plugin_grcmanager';

    private const LINK_TABLE = 'glpi_plugin_grcmanager_controls_risks';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_controls';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Contrôle Annexe A', 'Contrôles Annexe A', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    /**
     * @return array<string, string>
     */
    public static function getThemes(): array
    {
        return [
            'organizational' => __('Organisationnel', 'grcmanager'),
            'people'         => __('Humain', 'grcmanager'),
            'physical'       => __('Physique', 'grcmanager'),
            'technological'  => __('Technologique', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getApplicabilities(): array
    {
        return [
            'yes'     => __('Applicable', 'grcmanager'),
            'no'      => __('Non applicable', 'grcmanager'),
            'partial' => __('Partiellement applicable', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getImplementationStatuses(): array
    {
        return [
            'not_started' => __('Non démarré', 'grcmanager'),
            'in_progress' => __('En cours', 'grcmanager'),
            'implemented' => __('Mis en œuvre', 'grcmanager'),
            'verified'    => __('Vérifié', 'grcmanager'),
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
            'A.5.1'  => __('Politiques de sécurité de l\'information', 'grcmanager'),
            'A.5.2'  => __('Fonctions et responsabilités liées à la sécurité de l\'information', 'grcmanager'),
            'A.5.3'  => __('Séparation des tâches', 'grcmanager'),
            'A.5.4'  => __('Responsabilités de la direction', 'grcmanager'),
            'A.5.5'  => __('Relations avec les autorités', 'grcmanager'),
            'A.5.6'  => __('Relations avec des groupes de spécialistes', 'grcmanager'),
            'A.5.7'  => __('Renseignement sur les menaces', 'grcmanager'),
            'A.5.8'  => __('La sécurité de l\'information dans la gestion de projet', 'grcmanager'),
            'A.5.9'  => __('Inventaire des informations et autres actifs associés', 'grcmanager'),
            'A.5.10' => __('Utilisation correcte de l\'information et des autres actifs associés', 'grcmanager'),
            'A.5.11' => __('Restitution des actifs', 'grcmanager'),
            'A.5.12' => __('Classification de l\'information', 'grcmanager'),
            'A.5.13' => __('Marquage des informations', 'grcmanager'),
            'A.5.14' => __('Transfert de l\'information', 'grcmanager'),
            'A.5.15' => __('Contrôle d\'accès', 'grcmanager'),
            'A.5.16' => __('Gestion des identités', 'grcmanager'),
            'A.5.17' => __('Informations d\'authentification', 'grcmanager'),
            'A.5.18' => __('Droits d\'accès', 'grcmanager'),
            'A.5.19' => __('Sécurité de l\'information dans les relations avec les fournisseurs', 'grcmanager'),
            'A.5.20' => __(
                'La sécurité de l\'information dans les accords conclus avec les fournisseurs',
                'grcmanager'
            ),
            'A.5.21' => __(
                'Gestion de la sécurité de l\'information dans la chaîne d\'approvisionnement TIC',
                'grcmanager'
            ),
            'A.5.22' => __(
                'Surveillance, revue et gestion des changements des services des fournisseurs',
                'grcmanager'
            ),
            'A.5.23' => __('Sécurité de l\'information pour l\'utilisation de services en nuage', 'grcmanager'),
            'A.5.24' => __(
                'Planification et préparation de la gestion des incidents de sécurité de l\'information',
                'grcmanager'
            ),
            'A.5.25' => __(
                'Appréciation des événements liés à la sécurité de l\'information et prise de décision',
                'grcmanager'
            ),
            'A.5.26' => __('Réponse aux incidents de sécurité de l\'information', 'grcmanager'),
            'A.5.27' => __('Tirer des enseignements des incidents de sécurité de l\'information', 'grcmanager'),
            'A.5.28' => __('Collecte de preuves', 'grcmanager'),
            'A.5.29' => __('Sécurité de l\'information pendant une perturbation', 'grcmanager'),
            'A.5.30' => __('Préparation des TIC pour la continuité d\'activité', 'grcmanager'),
            'A.5.31' => __('Exigences légales, statutaires, réglementaires et contractuelles', 'grcmanager'),
            'A.5.32' => __('Droits de propriété intellectuelle', 'grcmanager'),
            'A.5.33' => __('Protection des enregistrements', 'grcmanager'),
            'A.5.34' => __('Protection de la vie privée et des données à caractère personnel', 'grcmanager'),
            'A.5.35' => __('Revue indépendante de la sécurité de l\'information', 'grcmanager'),
            'A.5.36' => __(
                'Conformité avec les politiques, règles et normes en matière de sécurité de l\'information',
                'grcmanager'
            ),
            'A.5.37' => __('Procédures d\'exploitation documentées', 'grcmanager'),

            // People controls (A.6)
            'A.6.1' => __('Sélection des candidats', 'grcmanager'),
            'A.6.2' => __('Termes et conditions du contrat de travail', 'grcmanager'),
            'A.6.3' => __(
                'Sensibilisation, apprentissage et formation à la sécurité de l\'information',
                'grcmanager'
            ),
            'A.6.4' => __('Processus disciplinaire', 'grcmanager'),
            'A.6.5' => __('Responsabilités après la fin ou le changement d\'un contrat de travail', 'grcmanager'),
            'A.6.6' => __('Accords de confidentialité ou de non-divulgation', 'grcmanager'),
            'A.6.7' => __('Travail à distance', 'grcmanager'),
            'A.6.8' => __('Signalement des événements liés à la sécurité de l\'information', 'grcmanager'),

            // Physical controls (A.7)
            'A.7.1'  => __('Périmètres de sécurité physique', 'grcmanager'),
            'A.7.2'  => __('Entrées physiques', 'grcmanager'),
            'A.7.3'  => __('Sécurisation des bureaux, des salles et des locaux', 'grcmanager'),
            'A.7.4'  => __('Surveillance de la sécurité physique', 'grcmanager'),
            'A.7.5'  => __('Protection contre les menaces physiques et environnementales', 'grcmanager'),
            'A.7.6'  => __('Travail dans les zones sécurisées', 'grcmanager'),
            'A.7.7'  => __('Bureau propre et écran verrouillé', 'grcmanager'),
            'A.7.8'  => __('Emplacement et protection du matériel', 'grcmanager'),
            'A.7.9'  => __('Sécurité des actifs hors des locaux', 'grcmanager'),
            'A.7.10' => __('Supports de stockage', 'grcmanager'),
            'A.7.11' => __('Services généraux', 'grcmanager'),
            'A.7.12' => __('Sécurité du câblage', 'grcmanager'),
            'A.7.13' => __('Maintenance du matériel', 'grcmanager'),
            'A.7.14' => __('Mise au rebut ou recyclage sécurisé du matériel', 'grcmanager'),

            // Technological controls (A.8)
            'A.8.1'  => __('Terminaux finaux des utilisateurs', 'grcmanager'),
            'A.8.2'  => __('Droits d\'accès privilégiés', 'grcmanager'),
            'A.8.3'  => __('Restriction d\'accès à l\'information', 'grcmanager'),
            'A.8.4'  => __('Accès au code source', 'grcmanager'),
            'A.8.5'  => __('Authentification sécurisée', 'grcmanager'),
            'A.8.6'  => __('Gestion de la capacité', 'grcmanager'),
            'A.8.7'  => __('Protection contre les logiciels malveillants', 'grcmanager'),
            'A.8.8'  => __('Gestion des vulnérabilités techniques', 'grcmanager'),
            'A.8.9'  => __('Gestion de la configuration', 'grcmanager'),
            'A.8.10' => __('Suppression des informations', 'grcmanager'),
            'A.8.11' => __('Masquage des données', 'grcmanager'),
            'A.8.12' => __('Prévention de la fuite de données', 'grcmanager'),
            'A.8.13' => __('Sauvegarde des informations', 'grcmanager'),
            'A.8.14' => __('Redondance des moyens de traitement de l\'information', 'grcmanager'),
            'A.8.15' => __('Journalisation', 'grcmanager'),
            'A.8.16' => __('Activités de surveillance', 'grcmanager'),
            'A.8.17' => __('Synchronisation des horloges', 'grcmanager'),
            'A.8.18' => __('Utilisation de programmes utilitaires à privilèges', 'grcmanager'),
            'A.8.19' => __('Installation de logiciels sur des systèmes en exploitation', 'grcmanager'),
            'A.8.20' => __('Sécurité des réseaux', 'grcmanager'),
            'A.8.21' => __('Sécurité des services réseau', 'grcmanager'),
            'A.8.22' => __('Cloisonnement des réseaux', 'grcmanager'),
            'A.8.23' => __('Filtrage web', 'grcmanager'),
            'A.8.24' => __('Utilisation de la cryptographie', 'grcmanager'),
            'A.8.25' => __('Cycle de vie du développement sécurisé', 'grcmanager'),
            'A.8.26' => __('Exigences de sécurité des applications', 'grcmanager'),
            'A.8.27' => __('Principes d\'ingénierie et d\'architecture système sécurisée', 'grcmanager'),
            'A.8.28' => __('Codage sécurisé', 'grcmanager'),
            'A.8.29' => __('Tests de sécurité en cours de développement et de recette', 'grcmanager'),
            'A.8.30' => __('Développement externalisé', 'grcmanager'),
            'A.8.31' => __('Séparation des environnements de développement, de test et de production', 'grcmanager'),
            'A.8.32' => __('Gestion des changements', 'grcmanager'),
            'A.8.33' => __('Informations de test', 'grcmanager'),
            'A.8.34' => __('Protection des systèmes d\'information lors des tests d\'audit', 'grcmanager'),
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
                __(
                    'Une justification est obligatoire lorsqu\'un contrôle n\'est pas pleinement applicable.',
                    'grcmanager'
                ),
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
            'name'             => __('Contrôle', 'grcmanager'),
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
            'name'     => __('Thème', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'applicability',
            'name'     => __('Applicabilité', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'implementation_status',
            'name'     => __('État de mise en œuvre', 'grcmanager'),
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
            'name'     => __('Revu', 'grcmanager'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'date_mod',
            'name'     => __('Dernière modification', 'grcmanager'),
            'datatype' => 'datetime',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family, see PluginGrcmanagerRisk::rawSearchOptions().
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

                $label = '<strong>' . htmlescape((string) $code) . '</strong> - '
                    . htmlescape(self::getControlTitle((string) $code));

                // Same "a list with no way back to showForm() is not self-explanatory" lesson as
                // the rest of this plugin family — but here the fix is making this column (the
                // one a user actually reads and clicks) the link, not adding a separate one: this
                // column already had a custom 'specific' display, and a bare numeric "ID" column
                // was the only clickable way back to a control's own form. Real user feedback.
                $id = $values['id'] ?? null;
                if ($id !== null && (int) $id > 0) {
                    return '<a href="' . htmlescape(self::getFormURLWithID((int) $id)) . '">' . $label . '</a>';
                }

                return $label;

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
     * (and same reason) as PluginGrcmanagerRisk::getSpecificValueToSelect(): 'specific' falls
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

        return '<span class="badge bg-azure-lt">' . htmlescape($label) . '</span>';
    }

    private static function applicabilityBadge(?string $value): string
    {
        $map = [
            'yes'     => ['bg-green-lt', 'ti-circle-check', __('Applicable', 'grcmanager')],
            'no'      => ['bg-secondary-lt', 'ti-circle-x', __('Non applicable', 'grcmanager')],
            'partial' => ['bg-yellow-lt', 'ti-circle-half-2', __('Partiellement applicable', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    private static function implementationStatusBadge(?string $value): string
    {
        $map = [
            'not_started' => ['bg-secondary-lt', 'ti-player-stop', __('Non démarré', 'grcmanager')],
            'in_progress' => ['bg-blue-lt', 'ti-tool', __('En cours', 'grcmanager')],
            'implemented' => ['bg-green-lt', 'ti-check', __('Mis en œuvre', 'grcmanager')],
            'verified'    => ['bg-teal-lt', 'ti-shield-check', __('Vérifié', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
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
                PluginGrcmanagerRisk::getTable() . ' AS risks' => [
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

        if (array_key_exists('linked_risks', $this->input)) {
            self::syncLinkedRisks((int) $this->fields['id'], (array) $this->input['linked_risks']);
        }
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB;

        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $code  = $this->fields['code'] ?? '';
        $theme = $this->fields['theme'] ?? '';

        echo '<tr class="tab_bg_1"><td>' . __('Contrôle', 'grcmanager') . '</td>';
        echo '<td><strong>' . htmlescape((string) $code) . '</strong></td>';
        echo '<td>' . __('Thème', 'grcmanager') . '</td>';
        echo '<td>' . self::themeBadge((string) $theme) . '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Intitulé', 'grcmanager') . '</td>';
        echo '<td colspan="3">' . htmlescape(self::getControlTitle((string) $code)) . '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Applicabilité', 'grcmanager') . '</td><td>';
        Dropdown::showFromArray('applicability', self::getApplicabilities(), [
            'value' => $this->fields['applicability'] ?? 'yes',
        ]);
        echo '</td>';

        echo '<td>' . __('État de mise en œuvre', 'grcmanager') . '</td><td>';
        Dropdown::showFromArray('implementation_status', self::getImplementationStatuses(), [
            'value' => $this->fields['implementation_status'] ?? 'not_started',
        ]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Justification', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        echo '<textarea name="justification" class="form-control" rows="4">'
            . htmlescape($this->fields['justification'] ?? '') . '</textarea>';
        echo '<small class="form-hint">' . __(
            'Obligatoire si le contrôle n\'est pas pleinement applicable.',
            'grcmanager'
        ) . '</small>';
        echo '</td></tr>';

        // Many-to-many link to the risk(s) this control's implementation addresses or is driven
        // by (glpi_plugin_grcmanager_controls_risks), synced in post_updateItem() above.
        $riskTitles = [];
        foreach ($DB->request(['SELECT' => ['id', 'title'], 'FROM' => PluginGrcmanagerRisk::getTable()]) as $row) {
            $riskTitles[(int) $row['id']] = $row['title'];
        }

        echo '<tr class="tab_bg_1"><td>' . __('Risques liés', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        Dropdown::showFromArray('linked_risks', $riskTitles, [
            'values'   => $this->isNewID($ID) ? [] : self::getLinkedRiskIds((int) $ID),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . __(
            'Risque(s) du registre justifiant ou pilotant la mise en œuvre de ce contrôle.',
            'grcmanager'
        ) . '</small>';
        echo '</td></tr>';

        // No delete/purge buttons: the 93 controls are a fixed catalog seeded at install (see
        // Installer::seedControls()), removing one would break the SoA's own completeness
        // invariant ("93/93 controls"), matching how a real GRC tool would forbid it too.
        $this->showFormButtons($options + ['candel' => false]);

        return true;
    }
}
