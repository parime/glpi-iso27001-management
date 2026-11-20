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

use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\RecyfRequirementBase;
use GlpiPlugin\Grcmanager\Install\RecyfTables;
use GlpiPlugin\Grcmanager\Services\Acn\AcnRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Perimeter\PerimeterRules;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfCatalogDefaults as Catalog;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfRules as Rules;
use Log;
use Session;

/**
 * Issue #599 : registre du Référentiel Cyber France (ReCyF, NIS2 France). Une fiche par mesure
 * (« 10.B.3-EI/EE ») pour chaque entité GLPI réglée comme entité importante ou essentielle, sur le
 * modèle du registre NIS2 Italia (AcnRequirement, #703).
 *
 * - Suivi propre, sans passer par l'Annexe A : applicabilité, état de mise en œuvre, responsable,
 *   échéance, notes et documents (onglet natif). Les renvois vers NIS2 (UE) sont indicatifs.
 * - Rattaché au référentiel NIS2 : visible quand il est activé ; l'évaluation par référentiel
 *   (FrameworkAssessment, #789) mesure NIS2 sur ces fiches dès qu'un registre est accessible.
 * - Cloisonnement des périmètres (#536) : les fiches appartiennent à l'entité réglée, visibles de ses
 *   sous-entités (récursives) ; droits d'entité standards du cœur (check()). Une sous-entité sans
 *   catégorie suit le registre de l'entité parente la plus proche qui en a une.
 * - L'entité reçoit les 152 mesures : passer d'importante à essentielle (ou l'inverse) ne supprime
 *   ni ne recrée rien, seul le catalogue affiché change.
 * - Catalogue fixe : ni création ni suppression depuis l'interface.
 */
class RecyfRequirement extends RecyfRequirementBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const ENTITIES_TABLE = RecyfTables::ENTITIES;

    /** @var array<int, string>|null entité => catégorie, lu une fois par requête */
    private static ?array $categories = null;

    public static function getTable($classname = null)
    {
        return RecyfTables::REQUIREMENTS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('ReCyF measure', 'ReCyF measures', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/recyfrequirement.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/recyfrequirement.form.php';
    }

    /** Aucune entrée de menu : ouvert depuis la page Référentiels, la configuration et l'évaluation. */
    public static function getMenuContent()
    {
        return false;
    }

    public static function getNameField()
    {
        return 'code';
    }

    /** Le registre suit le référentiel NIS2. */
    public static function isFrameworkEnabled(): bool
    {
        return FrameworkConfig::isEnabled(FrameworkSelection::NIS2);
    }

    public static function canView(): bool
    {
        return self::isFrameworkEnabled() && (bool) parent::canView();
    }

    public static function canUpdate(): bool
    {
        return self::isFrameworkEnabled() && (bool) parent::canUpdate();
    }

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

    /** @return array<string, string> */
    public static function getCategories(): array
    {
        return [
            Catalog::CATEGORY_IMPORTANT => __('Important entity', 'grcmanager'),
            Catalog::CATEGORY_ESSENTIAL => __('Essential entity', 'grcmanager'),
        ];
    }

    // ------------------------------------------------------------------
    // Catégorie par entité et création des fiches
    // ------------------------------------------------------------------

    /**
     * Catégorie réglée par entité.
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        global $DB;

        if (self::$categories === null) {
            self::$categories = [];
            if ($DB->tableExists(self::ENTITIES_TABLE)) {
                foreach ($DB->request(['FROM' => self::ENTITIES_TABLE]) as $row) {
                    $category = Rules::normalizeCategory($row['category']);
                    if ($category !== '') {
                        self::$categories[(int) $row['entities_id']] = $category;
                    }
                }
            }
        }

        return self::$categories;
    }

    public static function resetCategories(): void
    {
        self::$categories = null;
    }

    public static function categoryOf(int $entityId): string
    {
        return self::categories()[$entityId] ?? '';
    }

    /**
     * Règle la catégorie d'une entité ('' la retire) et crée les fiches qui lui manquent. Retirer la
     * catégorie ne supprime aucune fiche : le registre réapparaît tel quel si elle est remise.
     *
     * @return int nombre de fiches créées
     */
    public static function setCategory(int $entityId, string $category): int
    {
        global $DB;

        $category = Rules::normalizeCategory($category);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $existing = $DB->request(['FROM' => self::ENTITIES_TABLE, 'WHERE' => ['entities_id' => $entityId]])->current();
        if ($category === '') {
            if ($existing !== null) {
                $DB->delete(self::ENTITIES_TABLE, ['entities_id' => $entityId]);
            }
            self::resetCategories();

            return 0;
        }
        if ($existing === null) {
            $DB->insert(self::ENTITIES_TABLE, [
                'entities_id' => $entityId, 'category' => $category, 'date_creation' => $now, 'date_mod' => $now,
            ]);
        } elseif ((string) $existing['category'] !== $category) {
            $DB->update(
                self::ENTITIES_TABLE,
                ['category' => $category, 'date_mod' => $now],
                ['entities_id' => $entityId]
            );
        }
        self::resetCategories();

        return self::seed($entityId);
    }

    /**
     * Crée les fiches absentes du registre de l'entité (tout le catalogue). Rejouable.
     *
     * @return int nombre de fiches créées
     */
    public static function seed(int $entityId): int
    {
        global $DB;

        $existing = [];
        foreach (
            $DB->request([
                'SELECT' => ['code'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['entities_id' => $entityId],
            ]) as $row
        ) {
            $existing[] = (string) $row['code'];
        }
        $created = 0;
        foreach (Rules::missingCodes($existing) as $code) {
            $requirement = new self();
            if (
                $requirement->add([
                    'entities_id'           => $entityId,
                    'code'                  => $code,
                    'applicability'         => 'yes',
                    'implementation_status' => 'not_started',
                    '_no_history'           => true,
                ])
            ) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Entité dont le registre s'applique à $entityId (par défaut l'entité active), null sans
     * registre.
     */
    public static function registerEntity(?int $entityId = null): ?int
    {
        return Rules::registerEntity(
            PerimeterRules::chain($entityId ?? EntityPerimeter::activeEntity(), EntityPerimeter::parents()),
            self::categories()
        );
    }

    /**
     * Registres que la personne peut ouvrir : celui de l'entité active, puis ceux des entités actives
     * de la session qui ont une catégorie (vue arborescente).
     *
     * @return array<int, array{name: string, category: string}>
     */
    public static function availableRegisters(): array
    {
        $registers = [];
        $own = self::registerEntity();
        $candidates = $own !== null ? [$own] : [];
        foreach ((array) ($_SESSION['glpiactiveentities'] ?? []) as $entityId) {
            if (self::categoryOf((int) $entityId) !== '') {
                $candidates[] = (int) $entityId;
            }
        }
        foreach (array_unique($candidates) as $entityId) {
            if (Session::haveAccessToEntity($entityId, true)) {
                $registers[$entityId] = [
                    'name'     => Dropdown::getDropdownName(Entity::getTable(), $entityId),
                    'category' => self::categoryOf($entityId),
                ];
            }
        }

        return $registers;
    }

    /**
     * Registre ouvert : celui demandé s'il fait partie des registres accessibles, sinon celui de
     * l'entité active, sinon le premier accessible ; null sans aucun registre.
     *
     * @param array<int, array{name: string, category: string}> $registers voir availableRegisters()
     */
    public static function selectedRegister(array $registers, mixed $requested): ?int
    {
        if (is_numeric($requested) && isset($registers[(int) $requested])) {
            return (int) $requested;
        }
        $own = self::registerEntity();
        if ($own !== null && isset($registers[$own])) {
            return $own;
        }
        $first = array_key_first($registers);

        return $first === null ? null : (int) $first;
    }

    /**
     * Entités proposées dans la configuration : celles de la session, avec leur catégorie.
     *
     * @return list<array{id: int, name: string, category: string}>
     */
    public static function configRows(): array
    {
        $rows = [];
        foreach ((array) ($_SESSION['glpiactiveentities'] ?? [0]) as $entityId) {
            $entityId = (int) $entityId;
            $rows[] = [
                'id'       => $entityId,
                'name'     => Dropdown::getDropdownName(Entity::getTable(), $entityId),
                'category' => self::categoryOf($entityId),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return array_slice($rows, 0, 500);
    }

    // ------------------------------------------------------------------
    // Saisie
    // ------------------------------------------------------------------

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        $input['entities_id']  = (int) ($input['entities_id'] ?? EntityPerimeter::activeEntity());
        $input['is_recursive'] = 1;
        if (!isset(Catalog::MEASURES[(string) ($input['code'] ?? '')])) {
            return false;
        }

        return $this->normalizeInput($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        // Le numéro identifie la mesure dans le catalogue et dans le registre de son entité.
        unset($input['code'], $input['entities_id'], $input['is_recursive']);

        return $this->normalizeInput($input, $this->fields);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input, array $current)
    {
        if (array_key_exists('applicability', $input)) {
            $input['applicability'] = AcnRules::normalizeApplicability($input['applicability']);
        }
        if (array_key_exists('implementation_status', $input)) {
            $input['implementation_status'] = AcnRules::normalizeStatus($input['implementation_status']);
        }
        if (array_key_exists('users_id', $input)) {
            $input['users_id'] = max(0, (int) $input['users_id']);
        }
        if (array_key_exists('due_date', $input) && in_array($input['due_date'], ['', null, 'NULL'], true)) {
            $input['due_date'] = 'NULL';
        }
        if (AcnRules::errors(array_merge($current, $input)) !== []) {
            Session::addMessageAfterRedirect(
                htmlescape(__(
                    'A justification is required whenever a measure is not fully applicable.',
                    'grcmanager'
                )),
                false,
                ERROR
            );

            return false;
        }

        return $input;
    }

    // ------------------------------------------------------------------
    // Registre, synthèse et exports
    // ------------------------------------------------------------------

    /**
     * Fiches du registre d'une entité, dans le catalogue de sa catégorie.
     *
     * @return list<array<string, mixed>>
     */
    public static function registerRows(int $entityId): array
    {
        global $DB;

        $category = self::categoryOf($entityId);
        if ($category === '') {
            return [];
        }
        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['entities_id' => $entityId],
        ]), false);

        return Rules::inCatalog($rows, $category);
    }

    /**
     * Données du tableau de bord du registre : synthèse générale, par objectif, et lignes.
     *
     * @return array<string, mixed>
     */
    public static function dashboard(int $entityId): array
    {
        $today = date('Y-m-d');
        $rows = self::registerRows($entityId);
        $byObjective = [];
        foreach ($rows as $row) {
            $byObjective[Catalog::objectiveOf((string) $row['code'])][] = $row;
        }
        $objectives = [];
        foreach (array_keys(Catalog::OBJECTIVES) as $number) {
            if (!isset($byObjective[$number])) {
                continue;
            }
            $lines = [];
            foreach ($byObjective[$number] as $row) {
                $lines[] = self::lineFor($row, $today);
            }
            $objectives[] = [
                'number'    => $number,
                'name'      => Catalog::objectiveTitle($number),
                'essential' => Catalog::OBJECTIVES[$number]['essential'],
                'summary'   => Rules::summary($byObjective[$number], $today),
                'lines'     => $lines,
            ];
        }

        return [
            'entity'     => Dropdown::getDropdownName(Entity::getTable(), $entityId),
            'category'   => self::categoryOf($entityId),
            'summary'    => Rules::summary($rows, $today),
            'objectives' => $objectives,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function lineFor(array $row, string $today): array
    {
        $code = (string) $row['code'];
        $usersId = (int) $row['users_id'];
        $section = Catalog::sectionOf($code);

        return [
            'id'                  => (int) $row['id'],
            'url'                 => self::getFormURLWithID((int) $row['id']),
            'code'                => $code,
            'reference'           => Catalog::reference($code),
            'section'             => $section !== '' ? Catalog::sectionTitle($section) : '',
            'summary'             => Catalog::summary($code),
            'applicability'       => AcnRules::normalizeApplicability($row['applicability']),
            'status'              => AcnRules::normalizeStatus($row['implementation_status']),
            'applicability_label' => Control::getApplicabilities()[
                AcnRules::normalizeApplicability($row['applicability'])
            ],
            'status_label'        => Control::getImplementationStatuses()[
                AcnRules::normalizeStatus($row['implementation_status'])
            ],
            'owner'               => $usersId > 0 ? getUserName($usersId) : '',
            'due_date'            => (string) ($row['due_date'] ?? ''),
            'overdue'             => AcnRules::isOverdue($row, $today),
            'essential_only'      => Catalog::isEssentialOnly($code),
            'applicable'          => AcnRules::isApplicable($row),
        ];
    }

    /**
     * Encart de la carte NIS2 de la page Référentiels : objectifs du ReCyF et accès au registre.
     *
     * @return array<string, mixed>
     */
    public static function referentialCard(): array
    {
        global $CFG_GLPI;

        $objectives = [];
        foreach (Catalog::OBJECTIVES as $number => $objective) {
            $codes = array_filter(
                Catalog::codes(),
                static fn (string $code): bool => Catalog::objectiveOf($code) === $number
            );
            $measures = [];
            foreach ($codes as $code) {
                $measures[] = ['reference' => Catalog::reference($code), 'summary' => Catalog::summary($code)];
            }
            $objectives[] = [
                'number'    => $number,
                'name'      => Catalog::objectiveTitle($number),
                'essential' => $objective['essential'],
                'measures'  => $measures,
            ];
        }
        $own = self::registerEntity();

        return [
            'objectives'      => $objectives,
            'important'       => count(Catalog::codesFor(Catalog::CATEGORY_IMPORTANT)),
            'essential'       => count(Catalog::codesFor(Catalog::CATEGORY_ESSENTIAL)),
            'register_url'    => self::getSearchURL(),
            'has_register'    => self::availableRegisters() !== [],
            'own_category'    => $own !== null ? (self::getCategories()[self::categoryOf($own)] ?? '') : '',
            'config_url'      => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grcmanager-recyf',
            'can_configure'   => (bool) Session::haveRight(Install\Installer::RIGHT_NAME, UPDATE),
            'version'         => Catalog::VERSION,
            'source_page'     => Catalog::SOURCE_PAGE,
            'source_document' => Catalog::SOURCE_DOCUMENT,
        ];
    }

    /**
     * Lignes de l'export CSV de conformité (en-tête compris).
     *
     * @return list<list<string>>
     */
    public static function csvRows(int $entityId): array
    {
        $lines = [[
            __('Measure', 'grcmanager'),
            __('Objective', 'grcmanager'),
            __('Summary', 'grcmanager'),
            __('Applicability', 'grcmanager'),
            __('Justification', 'grcmanager'),
            __('Implementation status', 'grcmanager'),
            __('Owner', 'grcmanager'),
            __('Due date', 'grcmanager'),
            __('Notes', 'grcmanager'),
            'NIS2',
        ]];
        $today = date('Y-m-d');
        foreach (self::registerRows($entityId) as $row) {
            $line = self::lineFor($row, $today);
            $objective = Catalog::objectiveOf($line['code']);
            $lines[] = [
                $line['reference'],
                $objective . '. ' . Catalog::objectiveTitle($objective),
                $line['summary'],
                $line['applicability_label'],
                (string) ($row['justification'] ?? ''),
                $line['status_label'],
                $line['owner'],
                $line['due_date'],
                (string) ($row['comment'] ?? ''),
                implode(', ', array_keys(Catalog::nis2For($line['code']))),
            ];
        }

        return $lines;
    }

    /**
     * Rapport PDF de conformité du registre (HTML pour PdfRenderer).
     */
    public static function reportHtml(int $entityId): string
    {
        $dashboard = self::dashboard($entityId);

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/recyf_report.html.twig', $dashboard + [
            'report_title'       => sprintf(__('NIS2 compliance: ReCyF %s', 'grcmanager'), Catalog::VERSION),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                \Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'perimeter'          => $dashboard['entity'],
            'category_label'     => self::getCategories()[$dashboard['category']] ?? '',
            'version'            => Catalog::VERSION,
        ]);
    }

    // ------------------------------------------------------------------
    // Fiche
    // ------------------------------------------------------------------

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $tab[] = [
            'id' => 1, 'table' => $table, 'field' => 'code', 'name' => __('Measure', 'grcmanager'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 4, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => __('Owner', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'due_date', 'name' => __('Due date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'comment', 'name' => __('Notes', 'grcmanager'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'date_mod', 'name' => __('Last update', 'grcmanager'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $code = (string) ($this->fields['code'] ?? '');
        $entityId = (int) ($this->fields['entities_id'] ?? 0);
        $category = self::categoryOf($entityId);
        $objective = Catalog::objectiveOf($code);
        $section = Catalog::sectionOf($code);

        TemplateRenderer::getInstance()->display('@grcmanager/recyf_requirement_form.html.twig', [
            'item'            => $this,
            'params'          => $options + ['candel' => false],
            'reference'       => Catalog::reference($code),
            'summary'         => Catalog::summary($code),
            'objective'       => $objective . '. ' . Catalog::objectiveTitle($objective),
            'section'         => $section !== '' ? $section . ' ' . Catalog::sectionTitle($section) : '',
            'essential_only'  => Catalog::isEssentialOnly($code),
            'in_scope'        => Catalog::inScope($code, $category),
            'category_label'  => self::getCategories()[$category] ?? '',
            'version'         => Catalog::VERSION,
            'source_document' => Catalog::SOURCE_DOCUMENT,
            'applicabilities' => Control::getApplicabilities(),
            'statuses'        => Control::getImplementationStatuses(),
            'nis2'            => Catalog::nis2For($code),
            'register_url'    => self::getSearchURL() . '?entities_id=' . $entityId,
        ]);

        return true;
    }
}
