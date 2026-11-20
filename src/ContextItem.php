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
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ContextItemBase;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules as Rules;
use GlpiPlugin\Grcmanager\Services\Context\OfficialContactRules as ContactRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Group;
use Html;
use Log;
use Session;
use Supplier;

/**
 * Issue #608 : contexte de l'organisation (ISO 27001, articles 4.1 et 4.2). Une fiche est un enjeu
 * interne ou externe, ou une partie intéressée avec ses attentes et ce que le SMSI en retient.
 *
 * - Droit général du plugin et entité de la fiche (check() du cœur), comme les autres registres
 *   du SMSI.
 * - Une partie intéressée peut être un fournisseur ou un groupe de GLPI (jamais un objet propre au
 *   plugin), ou un simple nom.
 * - Les liens vers les risques, les obligations et les mesures de la SoA sont des renvois vers les
 *   registres existants (table de liaison), sans recopie. Seuls les éléments que la personne peut
 *   lire sont proposés, ajoutés ou retirés.
 * - L'enjeu « changement climatique » répond à l'amendement de 2024 : tant qu'il n'est pas tranché
 *   et justifié, l'exigence 4.1 ne peut pas passer à « Conforme ».
 */
class ContextItem extends ContextItemBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const REVIEW_DUE_EVENT = 'review_due';

    public const LINKS_TABLE = 'glpi_plugin_grcmanager_contextitems_items';

    public const FK = 'plugin_grcmanager_contextitems_id';

    /** Bouton « Enregistrer la revue » de la fiche (lot 2 : revue périodique tracée). */
    public const REVIEW_MARKER = '_mark_reviewed';

    /** Issue #771 : relance d'un contact officiel non vérifié depuis 12 mois. */
    public const CONTACT_VERIFICATION_EVENT = 'contact_verification_due';

    /** Issue #771 : bouton « Enregistrer la vérification » d'un contact officiel. */
    public const CONTACT_VERIFIED_MARKER = '_mark_contact_verified';

    /** Marqueur du formulaire : une liste vide n'envoie rien, seul lui la remplace. */
    public const LINKS_MARKER = '_context_links_defined';

    /** Champ du formulaire => type lié. */
    public const LINK_FIELDS = [
        '_linked_risks'       => Risk::class,
        '_linked_obligations' => ComplianceObligation::class,
        '_linked_controls'    => Control::class,
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_contextitems';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Issue or interested party', 'Issues and interested parties', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-building-community';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => __('Context of the organization', 'grcmanager'),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => self::canCreate() ? self::getFormURL(false) : '',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function getKinds(): array
    {
        return [
            Rules::KIND_ISSUE => __('Internal or external issue', 'grcmanager'),
            Rules::KIND_PARTY => __('Interested party', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function getNatures(): array
    {
        return [
            'internal' => __('Internal', 'grcmanager'),
            'external' => __('External', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function getCategories(): array
    {
        return [
            'regulatory'           => __('Regulatory', 'grcmanager'),
            'technological'        => __('Technological', 'grcmanager'),
            'economic'             => __('Economic', 'grcmanager'),
            'human'                => __('Human', 'grcmanager'),
            'organizational'       => __('Organisational', 'grcmanager'),
            'political'            => __('Political', 'grcmanager'),
            'environmental'        => __('Environmental', 'grcmanager'),
            Rules::CATEGORY_CLIMATE => __('Climate change', 'grcmanager'),
            'other'                => __('Other', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function getPartyTypes(): array
    {
        return [
            'customer'    => __('Customer', 'grcmanager'),
            'authority'   => __('Authority or regulator', 'grcmanager'),
            'employee'    => __('Employees', 'grcmanager'),
            'supplier'    => __('Supplier or service provider', 'grcmanager'),
            'shareholder' => __('Shareholder or owner', 'grcmanager'),
            'partner'     => __('Partner', 'grcmanager'),
            'insurer'     => __('Insurer', 'grcmanager'),
            'community'   => __('Neighbours and public', 'grcmanager'),
            'other'       => __('Other', 'grcmanager'),
        ];
    }

    /**
     * Issue #771 : catégories des contacts officiels (A.5.5 : autorités ; A.5.6 : groupes d'intérêt).
     *
     * @return array<string, string>
     */
    public static function getContactCategories(): array
    {
        return [
            'supervisory_authority' => __('Supervisory authority (e.g. data protection)', 'grcmanager'),
            'csirt'                 => __('CSIRT or national cyber security agency', 'grcmanager'),
            'law_enforcement'       => __('Law enforcement', 'grcmanager'),
            'local_authority'       => __('Local or state authority', 'grcmanager'),
            'sector_regulator'      => __('Sector regulator', 'grcmanager'),
            'interest_group'        => __('Special interest group (CERT, association, forum)', 'grcmanager'),
            'insurer'               => __('Insurer', 'grcmanager'),
            'hosting_provider'      => __('Hosting provider', 'grcmanager'),
            'other'                 => __('Other', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function getRelevances(): array
    {
        return [
            Rules::RELEVANCE_TO_ASSESS    => __('To be assessed', 'grcmanager'),
            Rules::RELEVANCE_RETAINED     => __('Retained by the ISMS', 'grcmanager'),
            Rules::RELEVANCE_NOT_RETAINED => __('Not retained', 'grcmanager'),
        ];
    }

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
        $input += [
            'kind'      => Rules::KIND_ISSUE,
            'relevance' => Rules::RELEVANCE_TO_ASSESS,
        ];
        unset($input['last_review_date'], $input['users_id_lastreviewer']);
        // Sans entité dans la saisie : l'entité active (celle que check() a contrôlée).
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = Session::getActiveEntity();
        }
        $input = $this->prepareContactVerification($input, []);

        return $this->normalizeInput($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        // Jamais déplacée vers une entité que la personne ne voit pas.
        if (array_key_exists('entities_id', $input) && !Session::haveAccessToEntity((int) $input['entities_id'])) {
            unset($input['entities_id']);
        }
        // Revue tracée : jamais saisie à la main, seulement par le bouton (date du jour, personne
        // connectée) ; la prochaine revue passe à un an. L'historique de la fiche garde chaque revue.
        unset($input['last_review_date'], $input['users_id_lastreviewer']);
        if (!empty($input[self::REVIEW_MARKER])) {
            $today = date('Y-m-d');
            $input['last_review_date'] = $today;
            $input['users_id_lastreviewer'] = (int) Session::getLoginUserID();
            $input['review_date'] = Rules::nextReviewDate($today);
        }
        $input = $this->prepareContactVerification($input, $this->fields);

        return $this->normalizeInput($input, $this->fields);
    }

    /**
     * Issue #771 : vérification d'un contact officiel. L'auteur et la date de relance ne sont jamais
     * repris de la saisie : le bouton « Enregistrer la vérification » fixe la date du jour, et toute
     * date de vérification nouvelle est attribuée à la personne connectée. L'historique de la fiche
     * garde chaque vérification.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function prepareContactVerification(array $input, array $current): array
    {
        unset($input['users_id_contactverifier'], $input['contact_reminder_date']);
        if (!empty($input[self::CONTACT_VERIFIED_MARKER])) {
            $input['contact_verified_date'] = date('Y-m-d');
        }
        if (!array_key_exists('contact_verified_date', $input)) {
            return $input;
        }
        $date = trim((string) $input['contact_verified_date']);
        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date > date('Y-m-d')) {
            // Date vide, mal formée ou future : pas de vérification (jamais une vérification à venir).
            $input['contact_verified_date'] = 'NULL';

            return $input;
        }
        $input['contact_verified_date'] = $date;
        if ($date !== (string) ($current['contact_verified_date'] ?? '')) {
            $input['users_id_contactverifier'] = (int) Session::getLoginUserID();
            $input['contact_reminder_date'] = 'NULL';
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
        $enums = [
            'kind'       => Rules::normalizeKind(...),
            'nature'     => Rules::normalizeNature(...),
            'category'   => Rules::normalizeCategory(...),
            'party_type' => Rules::normalizePartyType(...),
            'relevance'  => Rules::normalizeRelevance(...),
            'contact_category' => ContactRules::normalizeCategory(...),
        ];
        foreach ($enums as $field => $normalize) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $normalize($input[$field]);
            }
        }
        foreach (['is_official_contact', 'on_security_incident', 'on_data_breach'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = empty($input[$field]) ? 0 : 1;
            }
        }
        // Issue #771 : seule une partie intéressée peut être un contact officiel.
        if (($input['kind'] ?? $current['kind'] ?? Rules::KIND_ISSUE) !== Rules::KIND_PARTY) {
            $input['is_official_contact'] = 0;
        }
        if (array_key_exists('users_id_internal', $input)) {
            $input['users_id_internal'] = max(0, (int) $input['users_id_internal']);
        }
        // Fournisseur et groupe du cœur : seulement ceux que la personne peut lire.
        $coreLinks = [
            'suppliers_id' => Supplier::class, 'groups_id' => Group::class, 'groups_id_internal' => Group::class,
        ];
        foreach ($coreLinks as $field => $class) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $id = (int) $input[$field];
            $input[$field] = $id > 0 && (new $class())->can($id, READ) ? $id : 0;
        }
        if (array_key_exists('review_date', $input) && in_array($input['review_date'], ['', null], true)) {
            $input['review_date'] = 'NULL';
        }

        // Partie intéressée choisie parmi les fournisseurs ou les groupes : son nom par défaut.
        $merged = array_merge($current, $input);
        if (trim((string) ($merged['name'] ?? '')) === '' && ($merged['kind'] ?? '') === Rules::KIND_PARTY) {
            foreach (['suppliers_id' => Supplier::getTable(), 'groups_id' => Group::getTable()] as $field => $table) {
                if ((int) ($merged[$field] ?? 0) > 0) {
                    $input['name'] = $merged['name'] = Dropdown::getDropdownName($table, (int) $merged[$field]);
                    break;
                }
            }
        }

        $errors = Rules::errors($merged);
        if ($errors !== []) {
            $messages = [
                Rules::ERROR_NAME          => __('Give the issue or the interested party a name.', 'grcmanager'),
                Rules::ERROR_JUSTIFICATION => Rules::isClimate($merged)
                    ? __(
                        'Explain why climate change is or is not a relevant issue: auditors check this justification.',
                        'grcmanager'
                    )
                    : __('Explain why this item is not retained by the ISMS.', 'grcmanager'),
            ];
            foreach ($errors as $error) {
                Session::addMessageAfterRedirect($messages[$error], false, ERROR);
            }

            return false;
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->syncLinksFromInput();
        $this->progressClause();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->syncLinksFromInput();
        $this->progressClause();
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(self::LINKS_TABLE, [self::FK => (int) $this->getID()]);
    }

    /**
     * Une fiche renseignée fait passer son exigence (4.1 ou 4.2) de « Non commencé » à « En cours ».
     * Jamais plus loin : « Conforme » reste une décision humaine, et un statut déjà avancé n'est pas
     * modifié.
     */
    private function progressClause(): void
    {
        if (!FrameworkConfig::isEnabled(FrameworkSelection::ISO27001)) {
            return;
        }
        $requirement = new IsmsRequirement();
        $code = Rules::clauseFor((string) ($this->fields['kind'] ?? ''));
        if (
            $requirement->getFromDBByCrit(['code' => $code])
            && $requirement->fields['status'] === IsmsClauseCatalog::STATUS_NOT_STARTED
        ) {
            $requirement->update([
                'id'     => (int) $requirement->getID(),
                'status' => IsmsClauseCatalog::STATUS_IN_PROGRESS,
            ]);
        }
    }

    /**
     * Identifiants liés à la fiche, pour un type.
     *
     * @param class-string<CommonDBTM> $itemtype
     * @return list<int>
     */
    public static function getLinkedIds(int $contextId, string $itemtype): array
    {
        global $DB;

        if ($contextId <= 0) {
            return [];
        }
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'items_id',
                'FROM'   => self::LINKS_TABLE,
                'WHERE'  => [self::FK => $contextId, 'itemtype' => $itemtype],
                'ORDER'  => 'items_id',
            ]) as $row
        ) {
            $ids[] = (int) $row['items_id'];
        }

        return $ids;
    }

    /**
     * Éléments que la personne peut lier, pour un type : risques et mesures de ses entités (la SoA
     * qui s'applique à l'entité de la fiche), obligations du registre. Rien sans le droit du type.
     *
     * @param class-string<CommonDBTM> $itemtype
     * @return array<int, string>
     */
    public static function linkChoices(string $itemtype, ?int $entityId = null): array
    {
        global $DB;

        if (!$itemtype::canView()) {
            return [];
        }
        $choices = [];
        if ($itemtype === Control::class) {
            $byCode = EntityPerimeter::controlIdsByCode($entityId);
            uksort($byCode, IsmsClauseCatalog::compareCodes(...));
            foreach ($byCode as $code => $id) {
                $choices[$id] = $code . ' ' . Control::getControlTitle((string) $code);
            }

            return $choices;
        }
        $table = $itemtype::getTable();
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => $table,
                // Obligations : registre commun à toutes les entités (pas de colonne d'entité).
                'WHERE'  => (new $itemtype())->isEntityAssign() ? EntityPerimeter::criteria($table) : [],
                'ORDER'  => 'title',
            ]) as $row
        ) {
            $choices[(int) $row['id']] = (string) $row['title'];
        }

        return $choices;
    }

    /**
     * Le formulaire envoie le marqueur : seule cette saisie remplace les liens. Seuls les éléments
     * proposés à la personne et lisibles par elle sont ajoutés ou retirés ; les autres liens restent.
     */
    private function syncLinksFromInput(): void
    {
        global $DB;

        if (!isset($this->input[self::LINKS_MARKER])) {
            return;
        }
        $contextId = (int) $this->getID();
        $entityId = (int) ($this->fields['entities_id'] ?? 0);
        foreach (self::LINK_FIELDS as $field => $itemtype) {
            $choices = self::linkChoices($itemtype, $entityId);
            $wanted = array_values(array_unique(array_map('intval', (array) ($this->input[$field] ?? []))));
            $existing = self::getLinkedIds($contextId, $itemtype);
            $manageable = static fn (int $id): bool => isset($choices[$id]) && (new $itemtype())->can($id, READ);
            foreach (array_diff($existing, $wanted) as $id) {
                if ($manageable($id)) {
                    $DB->delete(self::LINKS_TABLE, [
                        self::FK => $contextId, 'itemtype' => $itemtype, 'items_id' => $id,
                    ]);
                }
            }
            foreach (array_diff($wanted, $existing) as $id) {
                if ($manageable($id)) {
                    $DB->insert(self::LINKS_TABLE, [
                        self::FK        => $contextId,
                        'itemtype'      => $itemtype,
                        'items_id'      => $id,
                        'date_creation' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }
    }

    /**
     * Risque, obligation ou mesure supprimés définitivement : plus cités par le contexte.
     */
    public static function forgetLinkedItem(CommonDBTM $item): void
    {
        global $DB;

        if ($DB->tableExists(self::LINKS_TABLE)) {
            $DB->delete(self::LINKS_TABLE, ['itemtype' => $item::class, 'items_id' => (int) $item->getID()]);
        }
    }

    /**
     * Fiches du contexte (hors corbeille) des entités de la session.
     *
     * @param array<string, mixed> $where
     * @param list<string>|string   $order
     * @return list<array<string, mixed>>
     */
    private static function visibleRows(array $where = [], array|string $order = 'date_mod DESC'): array
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => array_merge(['is_deleted' => 0] + $where, EntityPerimeter::criteria(self::getTable())),
                'ORDER' => $order,
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * La question du changement climatique a-t-elle une réponse dans les entités de la session ?
     */
    public static function isClimateAnswered(): bool
    {
        return Rules::climateAnswered(self::climateRows());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function climateRows(): array
    {
        return self::visibleRows(['kind' => Rules::KIND_ISSUE, 'category' => Rules::CATEGORY_CLIMATE]);
    }

    /**
     * État de la question climatique pour l'encadré de la page.
     *
     * @return array{answered: bool, relevance: string, url: string}
     */
    public static function climateStatus(): array
    {
        $rows = self::climateRows();
        $answered = Rules::climateAnswered($rows);
        $row = $rows[0] ?? null;

        return [
            'answered'  => $answered,
            'relevance' => $row === null ? '' : (self::getRelevances()[$row['relevance']] ?? ''),
            'url'       => $row === null
                ? self::getFormURL() . '?kind=' . Rules::KIND_ISSUE . '&category=' . Rules::CATEGORY_CLIMATE
                : self::getFormURLWithID((int) $row['id']),
        ];
    }

    /**
     * Synthèse pour la revue de direction (9.3.2 b et c) et les exigences 4.1 et 4.2 : nombre
     * d'enjeux et de parties, fiches modifiées depuis $since, revues dépassées, question climatique.
     *
     * @return array{issues: int, parties: int, changed: int, overdue: int, climate: bool, since: string, url: string,
     *     export_url: string}
     */
    public static function summary(?string $since = null): array
    {
        $today = date('Y-m-d');
        $summary = [
            'issues' => 0, 'parties' => 0, 'changed' => 0, 'overdue' => 0,
            'climate' => false, 'since' => (string) $since, 'url' => self::getSearchURL(),
            'export_url' => self::getExportURL('pdf'),
        ];
        $rows = self::visibleRows();
        foreach ($rows as $row) {
            $summary[$row['kind'] === Rules::KIND_PARTY ? 'parties' : 'issues']++;
            if ($since !== null && $since !== '' && (string) $row['date_mod'] >= $since) {
                $summary['changed']++;
            }
            if (Rules::isOverdue($row['review_date'] ?? null, $today)) {
                $summary['overdue']++;
            }
        }
        $summary['climate'] = Rules::climateAnswered($rows);

        return $summary;
    }

    /**
     * Synthèse affichée sur la fiche des exigences 4.1 et 4.2, null pour les autres ou sans droit.
     *
     * @return array{issues: int, parties: int, changed: int, overdue: int, climate: bool, since: string,
     *     url: string, export_url: string, code: string}|null
     */
    public static function clauseReadiness(string $code): ?array
    {
        if (!in_array($code, ['4.1', '4.2'], true) || !self::canView()) {
            return null;
        }

        return self::summary() + ['code' => $code];
    }

    // ------------------------------------------------------------------
    // Lot 2 : onglet « Contexte de l'organisation » des risques, obligations et mesures
    // ------------------------------------------------------------------

    /**
     * Fiches du contexte (lisibles) qui citent cet élément.
     *
     * @return list<array<string, mixed>>
     */
    public static function linkedTo(CommonDBTM $item): array
    {
        global $DB;

        if (!in_array($item::class, self::LINK_FIELDS, true) || $item->isNewItem() || !self::canView()) {
            return [];
        }
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => self::FK,
                'FROM'   => self::LINKS_TABLE,
                'WHERE'  => ['itemtype' => $item::class, 'items_id' => (int) $item->getID()],
            ]) as $row
        ) {
            $ids[] = (int) $row[self::FK];
        }
        if ($ids === []) {
            return [];
        }

        return self::visibleRows(['id' => $ids], ['kind', 'name']);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM
            || !in_array($item::class, self::LINK_FIELDS, true)
            || $item->isNewItem()
            || !self::canView()
        ) {
            return '';
        }

        return self::createTabEntry(
            __('Context of the organization', 'grcmanager'),
            count(self::linkedTo($item)),
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (
            !$item instanceof CommonDBTM
            || !in_array($item::class, self::LINK_FIELDS, true)
            || $item->isNewItem()
            || !self::canView()
            || !$item->can($item->getID(), READ)
        ) {
            return false;
        }
        self::showForLinkedItem($item);

        return true;
    }

    private static function showForLinkedItem(CommonDBTM $item): void
    {
        $today = date('Y-m-d');
        $rows = [];
        foreach (self::linkedTo($item) as $row) {
            $isParty = $row['kind'] === Rules::KIND_PARTY;
            $rows[] = [
                'url'             => self::getFormURLWithID((int) $row['id']),
                'name'            => (string) $row['name'],
                'is_party'        => $isParty,
                'kind'            => self::getKinds()[$row['kind']] ?? '',
                'detail'          => $isParty
                    ? (self::getPartyTypes()[$row['party_type']] ?? '')
                    : (self::getCategories()[$row['category']] ?? ''),
                'relevance'       => (string) $row['relevance'],
                'relevance_label' => self::getRelevances()[$row['relevance']] ?? '',
                'owner'           => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'review'          => (string) ($row['review_date'] ?? ''),
                'overdue'         => Rules::isOverdue($row['review_date'] ?? null, $today),
            ];
        }
        $field = (string) array_search($item::class, self::LINK_FIELDS, true);
        $linkType = (string) array_search($field, Rules::LINK_TYPES, true);
        $addUrl = self::getFormURL() . '?link_type=' . $linkType . '&link_id=' . (int) $item->getID() . '&kind=';

        TemplateRenderer::getInstance()->display('@grcmanager/context_linked_tab.html.twig', [
            'rows'                   => $rows,
            'can_create'             => self::canCreate(),
            'add_issue'              => $addUrl . Rules::KIND_ISSUE,
            'add_party'              => $addUrl . Rules::KIND_PARTY,
            'list_url'               => self::getSearchURL(),
            'relevance_retained'     => Rules::RELEVANCE_RETAINED,
            'relevance_not_retained' => Rules::RELEVANCE_NOT_RETAINED,
        ]);
    }

    // ------------------------------------------------------------------
    // Lot 2 : export du registre pour l'auditeur (CSV et PDF)
    // ------------------------------------------------------------------

    public static function getExportURL(string $format): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/contextitem.export.php?format='
            . ($format === 'pdf' ? 'pdf' : 'csv');
    }

    /**
     * Libellés des éléments liés que la personne peut lire. Les autres ne sont pas nommés.
     *
     * @return array<string, list<string>> champ du formulaire => libellés
     */
    private static function linkedLabels(int $contextId): array
    {
        $labels = [];
        foreach (self::LINK_FIELDS as $field => $itemtype) {
            $labels[$field] = [];
            if (!$itemtype::canView()) {
                continue;
            }
            foreach (self::getLinkedIds($contextId, $itemtype) as $id) {
                $linked = new $itemtype();
                if (!$linked->can($id, READ)) {
                    continue;
                }
                $labels[$field][] = $linked instanceof Control
                    ? trim($linked->fields['code'] . ' ' . Control::getControlTitle((string) $linked->fields['code']))
                    : (string) $linked->fields['title'];
            }
            sort($labels[$field], SORT_NATURAL);
        }

        return $labels;
    }

    /**
     * Lignes du registre exporté : fiches hors corbeille des entités de la session.
     *
     * @return list<array<string, mixed>>
     */
    public static function exportLines(): array
    {
        $today = date('Y-m-d');
        $lines = [];
        foreach (self::visibleRows([], ['kind', 'name']) as $row) {
            $isParty = $row['kind'] === Rules::KIND_PARTY;
            $links = self::linkedLabels((int) $row['id']);
            $lines[] = [
                'is_party'      => $isParty,
                'kind'          => self::getKinds()[$row['kind']] ?? '',
                'name'          => (string) $row['name'],
                'nature'        => $isParty ? '' : (self::getNatures()[$row['nature']] ?? ''),
                'category'      => $isParty ? '' : (self::getCategories()[$row['category']] ?? ''),
                'party_type'    => $isParty ? (self::getPartyTypes()[$row['party_type']] ?? '') : '',
                'expectations'  => (string) ($row['expectations'] ?? ''),
                'requirements'  => (string) ($row['requirements'] ?? ''),
                'effect'        => (string) ($row['effect'] ?? ''),
                'relevance'     => self::getRelevances()[$row['relevance']] ?? '',
                'justification' => (string) ($row['justification'] ?? ''),
                'risks'         => $links['_linked_risks'],
                'obligations'   => $links['_linked_obligations'],
                'controls'      => $links['_linked_controls'],
                'owner'         => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'review_date'   => (string) ($row['review_date'] ?? ''),
                'overdue'       => Rules::isOverdue($row['review_date'] ?? null, $today),
                'last_review'   => (string) ($row['last_review_date'] ?? ''),
                'last_reviewer' => (int) ($row['users_id_lastreviewer'] ?? 0) > 0
                    ? getUserName((int) $row['users_id_lastreviewer']) : '',
                'entity'        => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
            ];
        }

        return $lines;
    }

    /**
     * Lignes de l'export CSV (en-tête compris), cellules neutralisées contre les formules.
     *
     * @return list<list<string>>
     */
    public static function csvRows(): array
    {
        $rows = [[
            __('Kind', 'grcmanager'),
            __('Name'),
            __('Internal or external', 'grcmanager'),
            __('Category', 'grcmanager'),
            __('Type of interested party', 'grcmanager'),
            __('Needs and expectations', 'grcmanager'),
            __('Requirements retained by the ISMS', 'grcmanager'),
            __('Effect on the ISMS', 'grcmanager'),
            __('Decision', 'grcmanager'),
            __('Justification', 'grcmanager'),
            __('Related risks', 'grcmanager'),
            __('Related obligations', 'grcmanager'),
            __('Controls that respond', 'grcmanager'),
            _x('owner', 'Owner', 'grcmanager'),
            __('Review date', 'grcmanager'),
            __('Last review', 'grcmanager'),
            __('Last reviewed by', 'grcmanager'),
            Entity::getTypeName(1),
        ]];
        foreach (self::exportLines() as $line) {
            $rows[] = array_map(Rules::csvCell(...), [
                $line['kind'], $line['name'], $line['nature'], $line['category'], $line['party_type'],
                $line['expectations'], $line['requirements'], $line['effect'], $line['relevance'],
                $line['justification'], implode(' | ', $line['risks']), implode(' | ', $line['obligations']),
                implode(' | ', $line['controls']), $line['owner'], $line['review_date'], $line['last_review'],
                $line['last_reviewer'], $line['entity'],
            ]);
        }

        return $rows;
    }

    /**
     * Rapport PDF du registre du contexte (HTML pour PdfRenderer) : synthèse, réponse sur le
     * changement climatique, enjeux puis parties intéressées.
     */
    public static function reportHtml(): string
    {
        $lines = self::exportLines();
        $climate = null;
        $climateRow = self::climateRows()[0] ?? null;
        if ($climateRow !== null) {
            $climate = [
                'relevance'     => self::getRelevances()[$climateRow['relevance']] ?? '',
                'justification' => (string) ($climateRow['justification'] ?? ''),
            ];
        }

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/context_report.html.twig', [
            'report_title'       => __('Context of the organization', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'perimeter'          => EntityPerimeter::isMultiEntity()
                ? Dropdown::getDropdownName('glpi_entities', Session::getActiveEntity())
                : '',
            'summary'            => self::summary(),
            'climate'            => $climate,
            'climate_answered'   => self::isClimateAnswered(),
            'issues'             => array_values(array_filter($lines, static fn (array $l): bool => !$l['is_party'])),
            'parties'            => array_values(array_filter($lines, static fn (array $l): bool => $l['is_party'])),
        ]);
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
        $specific = [
            3 => ['kind', __('Kind', 'grcmanager')],
            4 => ['nature', __('Internal or external', 'grcmanager')],
            5 => ['category', __('Category', 'grcmanager')],
            6 => ['party_type', __('Type of interested party', 'grcmanager')],
            7 => ['relevance', __('Decision', 'grcmanager')],
        ];
        foreach ($specific as $id => [$field, $name]) {
            $tab[] = [
                'id' => $id, 'table' => $table, 'field' => $field, 'name' => $name,
                'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'],
            ];
        }
        $tab[] = [
            'id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => _x('owner', 'Owner', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 9, 'table' => $table, 'field' => 'review_date',
            'name' => __('Review date', 'grcmanager'), 'datatype' => 'date',
        ];
        $texts = [
            10 => ['expectations', __('Needs and expectations', 'grcmanager')],
            11 => ['requirements', __('Requirements retained by the ISMS', 'grcmanager')],
            12 => ['effect', __('Effect on the ISMS', 'grcmanager')],
            13 => ['justification', __('Justification', 'grcmanager')],
        ];
        foreach ($texts as $id => [$field, $name]) {
            $tab[] = ['id' => $id, 'table' => $table, 'field' => $field, 'name' => $name, 'datatype' => 'text'];
        }
        $tab[] = [
            'id' => 14, 'table' => Supplier::getTable(), 'field' => 'name', 'linkfield' => 'suppliers_id',
            'name' => Supplier::getTypeName(1), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 15, 'table' => Group::getTable(), 'field' => 'completename', 'linkfield' => 'groups_id',
            'name' => Group::getTypeName(1), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 16, 'table' => $table, 'field' => 'date_mod',
            'name' => __('Last update', 'grcmanager'), 'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 17, 'table' => $table, 'field' => 'last_review_date',
            'name' => __('Last review', 'grcmanager'), 'datatype' => 'date', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 18, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_lastreviewer',
            'name' => __('Last reviewed by', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
            'massiveaction' => false,
        ];
        // Issue #771 : contacts officiels.
        $tab[] = [
            'id' => 19, 'table' => $table, 'field' => 'is_official_contact',
            'name' => _n('Official contact', 'Official contacts', 1, 'grcmanager'), 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => 20, 'table' => $table, 'field' => 'contact_category',
            'name' => __('Contact category', 'grcmanager'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 21, 'table' => $table, 'field' => 'contact_verified_date',
            'name' => __('Last verification', 'grcmanager'), 'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 22, 'table' => $table, 'field' => 'contact_delay',
            'name' => __('Time limit', 'grcmanager'), 'datatype' => 'string',
        ];
        $tab[] = [
            'id' => 23, 'table' => $table, 'field' => 'on_security_incident',
            'name' => __('To contact for a security incident', 'grcmanager'), 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => 24, 'table' => $table, 'field' => 'on_data_breach',
            'name' => __('To contact for a personal data breach', 'grcmanager'), 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function enumLabels(): array
    {
        return [
            'kind'       => self::getKinds(),
            'nature'     => self::getNatures(),
            'category'   => self::getCategories(),
            'party_type' => self::getPartyTypes(),
            'relevance'  => self::getRelevances(),
            'contact_category' => self::getContactCategories(),
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $value = (string) ($values[$field] ?? '');
        switch ($field) {
            case 'kind':
                return $value === Rules::KIND_PARTY
                    ? Badge::html('bg-purple-lt', 'ti-users-group', self::getKinds()[$value])
                    : Badge::html('bg-azure-lt', 'ti-world', self::getKinds()[Rules::KIND_ISSUE]);
            case 'relevance':
                return self::relevanceBadge($value);
            case 'nature':
            case 'category':
            case 'party_type':
            case 'contact_category':
                return htmlescape(self::enumLabels()[$field][$value] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value'] = $values[$field] ?? '';
        if (isset(self::enumLabels()[$field])) {
            return Dropdown::showFromArray($name, self::enumLabels()[$field], $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function relevanceBadge(string $value): string
    {
        $map = [
            Rules::RELEVANCE_TO_ASSESS    => ['bg-secondary-lt', 'ti-help'],
            Rules::RELEVANCE_RETAINED     => ['bg-green-lt', 'ti-circle-check'],
            Rules::RELEVANCE_NOT_RETAINED => ['bg-orange-lt', 'ti-circle-minus'],
        ];
        [$class, $icon] = $map[$value] ?? $map[Rules::RELEVANCE_TO_ASSESS];

        $labels = self::getRelevances();

        return Badge::html($class, $icon, $labels[$value] ?? $labels[Rules::RELEVANCE_TO_ASSESS]);
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            // Création depuis les boutons de la page : enjeu, partie intéressée ou question climatique.
            $this->fields['kind'] = Rules::normalizeKind($options['kind'] ?? Rules::KIND_ISSUE);
            $climate = ($options['category'] ?? '') === Rules::CATEGORY_CLIMATE;
            if ($climate && $this->fields['kind'] === Rules::KIND_ISSUE) {
                $this->fields['category'] = Rules::CATEGORY_CLIMATE;
                $this->fields['nature'] = 'external';
                $this->fields['name'] = __('Climate change', 'grcmanager');
            }
            // Issue #771 : « Ajouter un contact officiel » depuis le registre des contacts.
            if (!empty($options['official'])) {
                $this->fields['kind'] = Rules::KIND_PARTY;
                $this->fields['party_type'] = 'authority';
                $this->fields['relevance'] = Rules::RELEVANCE_RETAINED;
                $this->fields['is_official_contact'] = 1;
                $this->fields['contact_verified_date'] = date('Y-m-d');
            }
        }
        $entityId = $this->isNewItem() ? Session::getActiveEntity() : (int) $this->fields['entities_id'];
        $links = [];
        foreach (self::LINK_FIELDS as $field => $itemtype) {
            $choices = self::linkChoices($itemtype, $entityId);
            $linked = $this->isNewItem() ? [] : self::getLinkedIds((int) $ID, $itemtype);
            // Lot 2 : création depuis l'onglet d'un risque, d'une obligation ou d'une mesure.
            if ($this->isNewItem() && Rules::linkFieldFor($options['link_type'] ?? null) === $field) {
                $linked = [(int) ($options['link_id'] ?? 0)];
            }
            $links[$field] = [
                'choices' => $choices,
                'values'  => array_values(array_filter($linked, static fn (int $id): bool => isset($choices[$id]))),
                'hidden'  => $this->isNewItem()
                    ? 0 : count(array_filter($linked, static fn (int $id): bool => !isset($choices[$id]))),
                'allowed' => $itemtype::canView(),
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/context_item_form.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'is_party'     => ($this->fields['kind'] ?? '') === Rules::KIND_PARTY,
            'is_climate'   => Rules::isClimate($this->fields),
            'kinds'        => self::getKinds(),
            'natures'      => self::getNatures(),
            'categories'   => self::getCategories(),
            'party_types'  => self::getPartyTypes(),
            'relevances'   => self::getRelevances(),
            'links'        => $links,
            'links_marker' => self::LINKS_MARKER,
            'review_marker' => self::REVIEW_MARKER,
            'can_review'   => !$this->isNewItem() && $this->can((int) $ID, UPDATE),
            'last_reviewer' => (int) ($this->fields['users_id_lastreviewer'] ?? 0) > 0
                ? getUserName((int) $this->fields['users_id_lastreviewer']) : '',
            // Issue #771 : contact officiel.
            'contact_categories'      => self::getContactCategories(),
            'contact_verified_marker' => self::CONTACT_VERIFIED_MARKER,
            'contact_status'          => ContactRules::verificationStatus(
                isset($this->fields['contact_verified_date']) ? (string) $this->fields['contact_verified_date'] : null,
                date('Y-m-d')
            ),
            'contact_next'            => ContactRules::nextVerificationDate(
                isset($this->fields['contact_verified_date']) ? (string) $this->fields['contact_verified_date'] : null
            ),
            'contact_verifier'        => (int) ($this->fields['users_id_contactverifier'] ?? 0) > 0
                ? getUserName((int) $this->fields['users_id_contactverifier']) : '',
            'contacts_url'            => OfficialContact::getRegisterURL(),
        ]);

        return true;
    }

    /**
     * Encadré en tête de la liste : question du changement climatique et boutons d'ajout.
     */
    public static function showOverview(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/context_overview.html.twig', [
            'climate'    => self::climateStatus(),
            'can_create' => self::canCreate(),
            'form_url'   => self::getFormURL(),
            'kind_issue' => Rules::KIND_ISSUE,
            'kind_party' => Rules::KIND_PARTY,
            'export_csv' => self::getExportURL('csv'),
            'export_pdf' => self::getExportURL('pdf'),
        ]);
    }

    /**
     * Tâche automatique : prévient le propriétaire d'une fiche dont la date de revue approche ou est
     * passée (même service que les risques et les obligations). Fiches de la corbeille exclues.
     */
    public static function cronReviewreminder(CronTask $task): int
    {
        $result = (new ReviewReminderService(self::class, ['is_deleted' => 0]))->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            __('%d context item(s) awaiting review, %d notification(s) raised.', 'grcmanager'),
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
