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
use GlpiPlugin\Grcmanager\Compat\Base\AcnRequirementBase;
use GlpiPlugin\Grcmanager\Services\Acn\AcnCatalogDefaults as Catalog;
use GlpiPlugin\Grcmanager\Services\Acn\AcnRules as Rules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Perimeter\PerimeterRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Log;
use Session;

/**
 * Issue #703 : registre NIS2 Italia - ACN. Une fiche par exigence (« DE.CM-01, punto 2 ») des
 * misure di sicurezza di base (AcnCatalogDefaults), pour chaque entité GLPI réglée comme soggetto
 * importante (allegato 1) ou essenziale (allegato 2).
 *
 * - Point de départ indépendant de l'Annexe A : applicabilité, état de mise en œuvre, responsable,
 *   échéance, notes, approbation des organes de direction quand le point l'exige, documents (onglet
 *   natif) et politiques du plugin liées. Les renvois vers NIS2 (UE), le NIST CSF 2.0 et l'Annexe A
 *   sont affichés à titre de correspondance, jamais imposés.
 * - Cloisonnement des périmètres (#536) : les points appartiennent à l'entité réglée, visibles de ses
 *   sous-entités (récursifs) ; droits d'entité standards du cœur (check()). Une sous-entité sans
 *   catégorie suit le registre de l'entité parente la plus proche qui en a une.
 * - L'entité reçoit les points des deux allegati : passer d'importante à essentielle (ou l'inverse)
 *   ne supprime rien ni ne recrée rien, seul le catalogue affiché change.
 * - Catalogue fixe : ni création ni suppression depuis l'interface.
 */
class AcnRequirement extends AcnRequirementBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const ENTITIES_TABLE = 'glpi_plugin_grcmanager_acnentities';

    public const LINKS_TABLE = 'glpi_plugin_grcmanager_acnrequirements_items';

    public const FK = 'plugin_grcmanager_acnrequirements_id';

    /** Marqueur du formulaire : une liste vide n'envoie rien, seul lui la remplace. */
    public const LINKS_MARKER = '_acn_links_defined';

    /** @var array<int, string>|null entité => catégorie, lu une fois par requête */
    private static ?array $categories = null;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_acnrequirements';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('NIS2 Italy requirement (ACN)', 'NIS2 Italy requirements (ACN)', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-flag';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/acnrequirement.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/acnrequirement.form.php';
    }

    /** Aucune entrée de menu : le registre s'ouvre depuis la page Référentiels et la configuration. */
    public static function getMenuContent()
    {
        return false;
    }

    public static function getNameField()
    {
        return 'code';
    }

    public static function isFrameworkEnabled(): bool
    {
        return FrameworkConfig::isEnabled(FrameworkSelection::ACN_IT);
    }

    public static function canView(): bool
    {
        return self::isFrameworkEnabled() && parent::canView();
    }

    public static function canUpdate(): bool
    {
        return self::isFrameworkEnabled() && parent::canUpdate();
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
            Catalog::CATEGORY_IMPORTANT => __('Important entity (annex 1)', 'grcmanager'),
            Catalog::CATEGORY_ESSENTIAL => __('Essential entity (annex 2)', 'grcmanager'),
        ];
    }

    public static function categoryBadge(string $category): string
    {
        return $category === Catalog::CATEGORY_ESSENTIAL
            ? Badge::html('bg-red-subtle text-red-emphasis', 'ti-shield-star', self::getCategories()[$category])
            : Badge::html(
                'bg-blue-subtle text-blue-emphasis',
                'ti-shield',
                self::getCategories()[Catalog::CATEGORY_IMPORTANT]
            );
    }

    public static function statusBadge(string $status): string
    {
        $map = [
            'not_started' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-player-stop'],
            'in_progress' => ['bg-blue-subtle text-blue-emphasis', 'ti-tool'],
            'implemented' => ['bg-green-subtle text-green-emphasis', 'ti-circle-check'],
            'verified'    => ['bg-teal-subtle text-teal-emphasis', 'ti-rosette-discount-check'],
        ];
        $status = Rules::normalizeStatus($status);
        [$class, $icon] = $map[$status];

        return Badge::html($class, $icon, Control::getImplementationStatuses()[$status]);
    }

    public static function applicabilityBadge(string $applicability): string
    {
        $map = [
            'yes'     => ['bg-green-subtle text-green-emphasis', 'ti-check'],
            'partial' => ['bg-yellow-subtle text-yellow-emphasis', 'ti-circle-half-2'],
            'no'      => ['bg-secondary-subtle text-secondary-emphasis', 'ti-circle-minus'],
        ];
        $applicability = Rules::normalizeApplicability($applicability);
        [$class, $icon] = $map[$applicability];

        return Badge::html($class, $icon, Control::getApplicabilities()[$applicability]);
    }

    // ------------------------------------------------------------------
    // Catégorie par entité et création des points
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
     * Règle la catégorie d'une entité ('' la retire) et crée les points qui lui manquent. Retirer la
     * catégorie ne supprime aucun point : le registre réapparaît tel quel si elle est remise.
     *
     * @return int nombre de points créés
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
     * Crée les points absents du registre de l'entité (les deux allegati). Rejouable.
     *
     * @return int nombre de points créés
     */
    public static function seed(int $entityId): int
    {
        global $DB;

        $existing = [];
        foreach (
            $DB->request([
                'SELECT' => ['code', 'point'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['entities_id' => $entityId],
            ]) as $row
        ) {
            $existing[] = [(string) $row['code'], (int) $row['point']];
        }
        $created = 0;
        foreach (Rules::missingPoints($existing) as [$code, $point]) {
            $requirement = new self();
            if (
                $requirement->add([
                    'entities_id'           => $entityId,
                    'code'                  => $code,
                    'point'                 => $point,
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
        if (!isset(Catalog::MEASURES[(string) ($input['code'] ?? '')]['points'][(int) ($input['point'] ?? 0)])) {
            return false;
        }

        return $this->normalizeInput($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        // Le point identifie l'exigence dans le catalogue et dans le registre de son entité.
        unset($input['code'], $input['point'], $input['entities_id'], $input['is_recursive']);

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
            $input['applicability'] = Rules::normalizeApplicability($input['applicability']);
        }
        if (array_key_exists('implementation_status', $input)) {
            $input['implementation_status'] = Rules::normalizeStatus($input['implementation_status']);
        }
        if (array_key_exists('users_id', $input)) {
            $input['users_id'] = max(0, (int) $input['users_id']);
        }
        foreach (['due_date', 'approval_date'] as $field) {
            if (array_key_exists($field, $input) && in_array($input[$field], ['', null, 'NULL'], true)) {
                $input[$field] = 'NULL';
            }
        }
        if (array_key_exists('approval_reference', $input)) {
            $input['approval_reference'] = mb_substr(trim((string) $input['approval_reference']), 0, 255);
        }
        if (Rules::errors(array_merge($current, $input)) !== []) {
            Session::addMessageAfterRedirect(
                htmlescape(__(
                    'A justification is required whenever a requirement is not fully applicable.',
                    'grcmanager'
                )),
                false,
                ERROR
            );

            return false;
        }

        return $input;
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->syncLinksFromInput();
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(self::LINKS_TABLE, [self::FK => (int) $this->getID()]);
    }

    // ------------------------------------------------------------------
    // Politiques liées
    // ------------------------------------------------------------------

    /**
     * @return list<int>
     */
    public static function getLinkedPolicyIds(int $requirementId): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'items_id',
                'FROM'   => self::LINKS_TABLE,
                'WHERE'  => [self::FK => $requirementId, 'itemtype' => Policy::class],
                'ORDER'  => 'items_id',
            ]) as $row
        ) {
            $ids[] = (int) $row['items_id'];
        }

        return $ids;
    }

    /**
     * Politiques que la personne peut lier (registre commun, sans entité). Rien sans le droit.
     *
     * @return array<int, string>
     */
    public static function policyChoices(): array
    {
        global $DB;

        if (!Policy::canView()) {
            return [];
        }
        $choices = [];
        $request = ['SELECT' => ['id', 'title'], 'FROM' => Policy::getTable(), 'ORDER' => 'title'];
        foreach ($DB->request($request) as $row) {
            $choices[(int) $row['id']] = (string) $row['title'];
        }

        return $choices;
    }

    /**
     * Politiques liées, avec leur état (approuvée, brouillon, archivée) et leur date d'approbation.
     *
     * @return list<array{id: int, title: string, status: string, approved: bool, approval_date: string, url: string}>
     */
    public static function linkedPolicies(int $requirementId): array
    {
        global $DB;

        $ids = self::getLinkedPolicyIds($requirementId);
        if ($ids === [] || !Policy::canView()) {
            return [];
        }
        $statuses = Policy::getStatuses();
        $policies = [];
        foreach ($DB->request(['FROM' => Policy::getTable(), 'WHERE' => ['id' => $ids], 'ORDER' => 'title']) as $row) {
            $policies[] = [
                'id'            => (int) $row['id'],
                'title'         => (string) $row['title'],
                'status'        => $statuses[(string) $row['status']] ?? (string) $row['status'],
                'approved'      => (string) $row['status'] === PolicyLifecycle::STATUS_APPROVED,
                'approval_date' => (string) ($row['approval_date'] ?? ''),
                'url'           => Policy::getFormURLWithID((int) $row['id']),
            ];
        }

        return $policies;
    }

    /**
     * Le formulaire envoie le marqueur : seule cette saisie remplace les liens, et seulement pour
     * les politiques que la personne peut lire.
     */
    private function syncLinksFromInput(): void
    {
        global $DB;

        if (!isset($this->input[self::LINKS_MARKER]) || !Policy::canView()) {
            return;
        }
        $requirementId = (int) $this->getID();
        $choices = self::policyChoices();
        $wanted = array_values(array_unique(array_map('intval', (array) ($this->input['_linked_policies'] ?? []))));
        $existing = self::getLinkedPolicyIds($requirementId);
        foreach (array_diff($existing, $wanted) as $id) {
            if (isset($choices[$id])) {
                $DB->delete(self::LINKS_TABLE, [
                    self::FK => $requirementId, 'itemtype' => Policy::class, 'items_id' => $id,
                ]);
            }
        }
        foreach (array_diff($wanted, $existing) as $id) {
            if (isset($choices[$id])) {
                $DB->insert(self::LINKS_TABLE, [
                    self::FK        => $requirementId,
                    'itemtype'      => Policy::class,
                    'items_id'      => $id,
                    'date_creation' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /** Politique supprimée définitivement : plus citée par le registre. */
    public static function forgetPolicy(int $policyId): void
    {
        global $DB;

        if ($DB->tableExists(self::LINKS_TABLE)) {
            $DB->delete(self::LINKS_TABLE, ['itemtype' => Policy::class, 'items_id' => $policyId]);
        }
    }

    // ------------------------------------------------------------------
    // Registre, synthèse et exports
    // ------------------------------------------------------------------

    /**
     * Points du registre d'une entité, dans le catalogue de sa catégorie.
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
     * Données du tableau de bord du registre : synthèse générale, par fonction, et lignes du tableau.
     *
     * @return array<string, mixed>
     */
    public static function dashboard(int $entityId): array
    {
        $today = date('Y-m-d');
        $rows = self::registerRows($entityId);
        $byFunction = [];
        foreach ($rows as $row) {
            $byFunction[Catalog::functionOf((string) $row['code'])][] = $row;
        }
        $functions = [];
        foreach (array_keys(Catalog::FUNCTIONS) as $function) {
            if (!isset($byFunction[$function])) {
                continue;
            }
            $lines = [];
            foreach ($byFunction[$function] as $row) {
                $lines[] = self::lineFor($row, $today);
            }
            $functions[] = [
                'code'    => $function,
                'name'    => Catalog::functionName($function),
                'summary' => Rules::summary($byFunction[$function], $today),
                'lines'   => $lines,
            ];
        }

        return [
            'entity'    => Dropdown::getDropdownName(Entity::getTable(), $entityId),
            'category'  => self::categoryOf($entityId),
            'annex'     => Catalog::annexFor(self::categoryOf($entityId)),
            'summary'   => Rules::summary($rows, $today),
            'functions' => $functions,
            'measures'  => count(Catalog::measuresFor(self::categoryOf($entityId))),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function lineFor(array $row, string $today): array
    {
        $code = (string) $row['code'];
        $point = (int) $row['point'];
        $usersId = (int) $row['users_id'];

        return [
            'id'                => (int) $row['id'],
            'url'               => self::getFormURLWithID((int) $row['id']),
            'code'              => $code,
            'point'             => $point,
            'reference'         => Catalog::reference($code, $point),
            'measure'           => Catalog::measureTitle($code),
            'summary'           => Catalog::pointSummary($code, $point),
            'applicability'     => (string) $row['applicability'],
            'status'            => (string) $row['implementation_status'],
            'applicability_label' => Control::getApplicabilities()[
                Rules::normalizeApplicability($row['applicability'])
            ],
            'status_label'      => Control::getImplementationStatuses()[
                Rules::normalizeStatus($row['implementation_status'])
            ],
            'owner'             => $usersId > 0 ? getUserName($usersId) : '',
            'due_date'          => (string) ($row['due_date'] ?? ''),
            'overdue'           => Rules::isOverdue($row, $today),
            'approval_required' => Catalog::requiresApproval($code, $point),
            'approval_missing'  => Rules::isApprovalMissing($row),
            'approval_date'     => (string) ($row['approval_date'] ?? ''),
            'essential_only'    => Catalog::isEssentialOnly($code, $point),
            'applicable'        => Rules::isApplicable($row),
        ];
    }

    /**
     * Carte de la page Référentiels : catalogue par fonction (mesures et points par allegato) et
     * accès au registre de l'entité active.
     *
     * @return array<string, mixed>
     */
    public static function referentialCard(): array
    {
        global $CFG_GLPI;

        $functions = [];
        foreach (Catalog::MEASURES as $code => $measure) {
            $all = count($measure['points']);
            $essentialOnly = count(array_filter(
                $measure['points'],
                static fn (array $p): bool => !empty($p['essential'])
            ));
            $functions[Catalog::functionOf($code)]['measures'][] = [
                'code'      => $code,
                'title'     => Catalog::measureTitle($code),
                'important' => $all - $essentialOnly,
                'essential' => $all,
            ];
        }
        foreach ($functions as $function => &$entry) {
            $entry['name'] = Catalog::functionName($function);
        }
        unset($entry);
        $own = self::registerEntity();

        return [
            'functions'        => $functions,
            'important'        => [
                count(Catalog::measuresFor(Catalog::CATEGORY_IMPORTANT)),
                count(Catalog::pointsFor(Catalog::CATEGORY_IMPORTANT)),
            ],
            'essential'        => [
                count(Catalog::measuresFor(Catalog::CATEGORY_ESSENTIAL)),
                count(Catalog::pointsFor(Catalog::CATEGORY_ESSENTIAL)),
            ],
            'register_url'     => self::getSearchURL(),
            'has_register'     => self::availableRegisters() !== [],
            'own_category'     => $own !== null ? (self::getCategories()[self::categoryOf($own)] ?? '') : '',
            'config_url'       => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grcmanager-acn',
            'can_configure'    => Session::haveRight(Install\Installer::RIGHT_NAME, UPDATE),
            'determination'    => Catalog::DETERMINATION,
            'source_annex_1'   => Catalog::SOURCE_ANNEX_1,
            'source_annex_2'   => Catalog::SOURCE_ANNEX_2,
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
            __('Requirement', 'grcmanager'),
            __('Measure', 'grcmanager'),
            __('Summary', 'grcmanager'),
            __('Annex', 'grcmanager'),
            __('Applicability', 'grcmanager'),
            __('Justification', 'grcmanager'),
            __('Implementation status', 'grcmanager'),
            __('Owner', 'grcmanager'),
            __('Due date', 'grcmanager'),
            __('Management approval', 'grcmanager'),
            __('Notes', 'grcmanager'),
            'NIS2',
            'ISO 27001',
        ]];
        $today = date('Y-m-d');
        foreach (self::registerRows($entityId) as $row) {
            $line = self::lineFor($row, $today);
            $approval = '';
            if ($line['approval_required']) {
                $approval = $line['approval_date'] !== ''
                    ? trim($line['approval_date'] . ' ' . (string) ($row['approval_reference'] ?? ''))
                    : __('Required, not recorded', 'grcmanager');
            }
            $lines[] = [
                $line['reference'],
                $line['measure'],
                $line['summary'],
                $line['essential_only'] ? '2' : '1, 2',
                $line['applicability_label'],
                (string) ($row['justification'] ?? ''),
                $line['status_label'],
                $line['owner'],
                $line['due_date'],
                $approval,
                (string) ($row['comment'] ?? ''),
                implode(', ', array_keys(Catalog::nis2For($line['code']))),
                implode(', ', Catalog::annexAFor($line['code'])),
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

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/acn_report.html.twig', $dashboard + [
            'report_title'       => sprintf(
                __('NIS2 Italy compliance (ACN determination %s)', 'grcmanager'),
                Catalog::DETERMINATION
            ),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                \Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'perimeter'          => $dashboard['entity'],
            'category_label'     => self::getCategories()[$dashboard['category']] ?? '',
            'determination'      => Catalog::DETERMINATION,
            'law'                => Catalog::LAW,
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
            'id' => 3, 'table' => $table, 'field' => 'point', 'name' => __('Point', 'grcmanager'),
            'datatype' => 'number', 'massiveaction' => false,
        ];
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
        $point = (int) ($this->fields['point'] ?? 0);
        $entityId = (int) ($this->fields['entities_id'] ?? 0);
        $category = self::categoryOf($entityId);
        $policies = self::policyChoices();
        $linked = self::getLinkedPolicyIds((int) $ID);

        // Mesures de l'Annexe A liées par le NIST, dans la SoA suivie par l'entité (si ISO 27001 actif).
        $annexA = [];
        $showIso = FrameworkConfig::isEnabled(FrameworkSelection::ISO27001) && Control::canView();
        $controlIds = $showIso ? EntityPerimeter::controlIdsByCode($entityId) : [];
        foreach (Catalog::annexAFor($code) as $annexCode) {
            $annexA[] = [
                'code'  => $annexCode,
                'title' => Control::getControlTitle($annexCode),
                'url'   => isset($controlIds[$annexCode]) ? Control::getFormURLWithID($controlIds[$annexCode]) : '',
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/acn_requirement_form.html.twig', [
            'item'             => $this,
            'params'           => $options + ['candel' => false],
            'reference'        => Catalog::reference($code, $point),
            'measure'          => Catalog::measureTitle($code),
            'summary'          => Catalog::pointSummary($code, $point),
            'function'         => Catalog::functionName(Catalog::functionOf($code)),
            'annexes'          => Catalog::annexesOf($code, $point),
            'in_scope'         => Catalog::inScope($code, $point, $category),
            'category_label'   => self::getCategories()[$category] ?? '',
            'determination'    => Catalog::DETERMINATION,
            'law'              => Catalog::LAW,
            'source_url'       => Catalog::isEssentialOnly($code, $point) || $category === Catalog::CATEGORY_ESSENTIAL
                ? Catalog::SOURCE_ANNEX_2 : Catalog::SOURCE_ANNEX_1,
            'approval_required' => Catalog::requiresApproval($code, $point),
            'applicabilities'  => Control::getApplicabilities(),
            'statuses'         => Control::getImplementationStatuses(),
            'nis2'             => FrameworkConfig::isEnabled(FrameworkSelection::NIS2) ? Catalog::nis2For($code) : [],
            'nist_text'        => FrameworkConfig::isEnabled(FrameworkSelection::NIST_CSF)
                ? Catalog::nistText($code) : '',
            'annex_a'          => $showIso ? $annexA : [],
            'policy_choices'   => $policies,
            'policy_values'    => array_values(
                array_filter($linked, static fn (int $id): bool => isset($policies[$id]))
            ),
            'policy_hidden'    => count(array_filter($linked, static fn (int $id): bool => !isset($policies[$id]))),
            'policies'         => self::linkedPolicies((int) $ID),
            'can_link_policy'  => Policy::canView(),
            'links_marker'     => self::LINKS_MARKER,
            'register_url'     => self::getSearchURL() . '?entities_id=' . $entityId,
        ]);

        return true;
    }
}
