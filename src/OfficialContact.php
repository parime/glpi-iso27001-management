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
use Document;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules;
use GlpiPlugin\Grcmanager\Services\Context\OfficialContactRules as Rules;
use Html;
use NotificationEvent;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #771 : registre des contacts officiels (ISO 27001, A.5.5 relations avec les autorités, A.5.6
 * relations avec des groupes d'intérêt).
 *
 * Pas d'objet ni de table à part : un contact officiel est une partie intéressée du contexte de
 * l'organisation (ContextItem) marquée « contact officiel ». Cette classe n'en est que la vue :
 * - le registre (liste, export PDF pour l'avoir hors ligne pendant une crise) ;
 * - l'onglet « Contacts officiels » des incidents de sécurité et des violations de données, avec
 *   les contacts proposés selon la catégorie de l'incident (lot 3) ;
 * - la preuve affichée sur les mesures A.5.5 et A.5.6 de la SoA ;
 * - la relance des contacts non vérifiés depuis 12 mois (tâche automatique).
 *
 * Droits : ceux du contexte de l'organisation (droit général du plugin) et ses entités, partout.
 * L'onglet d'un incident ou d'une violation exige en plus de pouvoir lire l'incident ou la
 * violation, et ne montre que les contacts de son entité ou des entités parentes (fiche récursive).
 */
class OfficialContact extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return _n('Official contact', 'Official contacts', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-address-book';
    }

    public static function canView(): bool
    {
        return ContextItem::canView();
    }

    public static function getRegisterURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/officialcontact.php';
    }

    public static function getExportURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/officialcontact.export.php';
    }

    /**
     * Sous-page de la rubrique « Conformité » (Menu::sections()), avec les droits du contexte.
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getRegisterURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Contacts officiels (hors corbeille) des entités de la session, éventuellement restreints.
     *
     * @param array<int|string, mixed> $where
     * @return list<array<string, mixed>>
     */
    public static function visibleContacts(array $where = []): array
    {
        global $DB;

        $table = ContextItem::getTable();
        if (!ContextItem::canView() || !$DB->fieldExists($table, 'is_official_contact')) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => $table,
                'WHERE' => array_merge(
                    ['is_deleted' => 0, 'is_official_contact' => 1, 'kind' => ContextItemRules::KIND_PARTY],
                    $where,
                    EntityPerimeter::criteria($table)
                ),
                'ORDER' => ['contact_category', 'name'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Ligne affichable d'un contact (registre, onglet, PDF).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row, string $today): array
    {
        $verified = isset($row['contact_verified_date']) ? (string) $row['contact_verified_date'] : null;
        $categories = ContextItem::getContactCategories();
        $groupId = (int) ($row['groups_id_internal'] ?? 0);

        return [
            'id'              => (int) $row['id'],
            'url'             => ContextItem::getFormURLWithID((int) $row['id']),
            'name'            => (string) $row['name'],
            'category'        => $categories[(string) ($row['contact_category'] ?? '')] ?? $categories['other'],
            'details'         => (string) ($row['contact_details'] ?? ''),
            'when'            => (string) ($row['when_to_contact'] ?? ''),
            'delay'           => (string) ($row['contact_delay'] ?? ''),
            'delay_hours'     => Rules::normalizeDelayHours($row['contact_delay_hours'] ?? 0),
            'obligations'     => self::linkedObligations((int) $row['id']),
            'internal'        => (int) ($row['users_id_internal'] ?? 0) > 0
                ? getUserName((int) $row['users_id_internal']) : '',
            'internal_group'  => $groupId > 0 ? Dropdown::getDropdownName('glpi_groups', $groupId) : '',
            'on_incident'     => !empty($row['on_security_incident']),
            'on_breach'       => !empty($row['on_data_breach']),
            // Lot 3 : catégories d'incident retenues (vide : tous les incidents).
            'incident_types'  => array_values(array_intersect_key(
                PluginGrcmanagerSecurityIncident::getCategories(),
                array_flip(Rules::normalizeIncidentCategories($row['contact_incident_categories'] ?? ''))
            )),
            'verified'        => $verified ?? '',
            'status'          => Rules::verificationStatus($verified, $today),
            'next'            => Rules::nextVerificationDate($verified) ?? '',
            'entity'          => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
        ];
    }

    /**
     * Issue #771, lot 2 : obligations de notification liées au contact (liens de la partie
     * intéressée), visibles seulement avec le droit des obligations.
     *
     * @return list<array{name: string, url: string}>
     */
    public static function linkedObligations(int $contactId): array
    {
        if (!ComplianceObligation::canView()) {
            return [];
        }
        $obligations = [];
        foreach (ContextItem::getLinkedIds($contactId, ComplianceObligation::class) as $id) {
            $obligation = new ComplianceObligation();
            if ($obligation->getFromDB($id)) {
                $obligations[] = [
                    'name' => (string) $obligation->fields['title'],
                    'url'  => ComplianceObligation::getFormURLWithID($id),
                ];
            }
        }

        return $obligations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function presentAll(array $rows): array
    {
        $today = date('Y-m-d');

        return array_map(static fn (array $row): array => self::present($row, $today), $rows);
    }

    /**
     * Page du registre : synthèse, boutons et liste par catégorie.
     */
    public static function showRegister(): void
    {
        $rows = self::visibleContacts();
        TemplateRenderer::getInstance()->display('@grcmanager/official_contact_register.html.twig', [
            'contacts'   => self::presentAll($rows),
            'summary'    => Rules::summary($rows, date('Y-m-d')),
            'can_create' => ContextItem::canCreate(),
            'add_url'    => ContextItem::getFormURL() . '?kind=' . ContextItemRules::KIND_PARTY . '&official=1',
            'export_url' => self::getExportURL(),
            'search_url' => ContextItem::getSearchURL(),
            'multi'      => EntityPerimeter::isMultiEntity(),
            // Lot 3 : archivage daté du registre sur les mesures A.5.5 et A.5.6.
            'can_archive'  => self::canArchive(),
            'register_url' => self::getRegisterURL(),
            'archives'     => self::recentArchives(),
        ]);
    }

    // ------------------------------------------------------------------
    // Lot 3 : archivage daté du registre, preuve des mesures A.5.5 et A.5.6
    // ------------------------------------------------------------------

    /** Archiver crée un document lié aux mesures : droit de modifier le contexte de l'organisation. */
    public static function canArchive(): bool
    {
        return self::canView() && ContextItem::canUpdate();
    }

    /**
     * Archive le registre en PDF daté, joint aux mesures A.5.5 et A.5.6 de la SoA suivie par
     * l'entité active. Renvoie l'identifiant du document, 0 en cas d'échec.
     */
    public static function archive(): int
    {
        if (!self::canArchive()) {
            return 0;
        }
        $fileName = sprintf('contacts-officiels-%s.pdf', date('Ymd-His'));
        try {
            $pdf = PdfRenderer::renderHtmlToPdf(self::reportHtml());
        } catch (\Throwable $e) {
            return 0;
        }
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(__('Register of official contacts - %s', 'grcmanager'), date('Y-m-d')),
            'entities_id'   => EntityPerimeter::soaEntity(),
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        foreach (self::controlIds() as $controlId) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => Control::class,
                'items_id'     => $controlId,
            ]);
        }

        return $documentsId;
    }

    /**
     * Mesures A.5.5 et A.5.6 de la SoA suivie par l'entité active.
     *
     * @return list<int>
     */
    public static function controlIds(): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Control::getTable(),
                'WHERE'  => ['code' => array_keys(Rules::CONTROL_CATEGORIES)] + EntityPerimeter::controlCriteria(),
            ]) as $control
        ) {
            $ids[] = (int) $control['id'];
        }

        return $ids;
    }

    /**
     * Derniers registres archivés sur ces mesures (documents lisibles par la personne).
     *
     * @return list<array{name: string, date: string, url: string}>
     */
    public static function recentArchives(int $limit = 5): array
    {
        global $DB;

        $controlIds = self::controlIds();
        if ($controlIds === [] || !Document::canView()) {
            return [];
        }
        $documents = Document::getTable();
        $links = Document_Item::getTable();
        $archives = [];
        foreach (
            $DB->request([
                'SELECT'     => ["$documents.id", "$documents.name", "$documents.date_creation"],
                'DISTINCT'   => true,
                'FROM'       => $documents,
                'INNER JOIN' => [$links => ['FKEY' => [$links => 'documents_id', $documents => 'id']]],
                'WHERE'      => [
                    "$links.itemtype"       => Control::class,
                    "$links.items_id"       => $controlIds,
                    "$documents.filename"   => ['LIKE', '%contacts-officiels-%'],
                    "$documents.is_deleted" => 0,
                ] + getEntitiesRestrictCriteria($documents, '', '', true),
                'ORDER'      => "$documents.id DESC",
                'LIMIT'      => $limit,
            ]) as $row
        ) {
            $archives[] = [
                'name' => (string) $row['name'],
                'date' => (string) $row['date_creation'],
                'url'  => Document::getFormURLWithID((int) $row['id']),
            ];
        }

        return $archives;
    }

    /**
     * Rapport PDF du registre (HTML pour PdfRenderer), à imprimer pour l'avoir hors ligne.
     */
    public static function reportHtml(): string
    {
        $rows = self::visibleContacts();

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/official_contacts_report.html.twig', [
            'report_title'       => __('Register of official contacts', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'perimeter'          => EntityPerimeter::isMultiEntity()
                ? Dropdown::getDropdownName('glpi_entities', Session::getActiveEntity())
                : '',
            'summary'            => Rules::summary($rows, date('Y-m-d')),
            'contacts'           => self::presentAll($rows),
        ]);
    }

    /**
     * Lot 3 : contacts officiels du plan de continuité (PCA / PRA) en PDF : ceux que la personne
     * peut lire, et pour un seul processus ceux de son entité ou d'une entité parente.
     *
     * @return list<array<string, mixed>>
     */
    public static function forContinuityPlan(?CommonDBTM $process = null): array
    {
        if (!self::canView()) {
            return [];
        }
        $where = [];
        if ($process !== null && !$process->isNewItem()) {
            $where[] = getEntitiesRestrictCriteria(
                ContextItem::getTable(),
                '',
                (int) $process->fields['entities_id'],
                true
            );
        }

        return self::presentAll(self::visibleContacts($where));
    }

    // ------------------------------------------------------------------
    // Onglet « Contacts officiels » des incidents de sécurité et des violations de données
    // ------------------------------------------------------------------

    /**
     * Type d'événement de l'objet affiché, null s'il n'a pas d'onglet.
     */
    private static function contextFor(CommonGLPI $item): ?string
    {
        if ($item instanceof PluginGrcmanagerSecurityIncident) {
            return Rules::CONTEXT_INCIDENT;
        }
        if ($item instanceof DataBreach) {
            return Rules::CONTEXT_BREACH;
        }

        return null;
    }

    /**
     * Contacts à prévenir pour cet incident ou cette violation : contacts lisibles par la personne,
     * de l'entité de l'objet ou d'une entité parente (fiche visible des sous-entités).
     *
     * @return list<array<string, mixed>>
     */
    public static function concernedBy(CommonDBTM $item): array
    {
        $context = self::contextFor($item);
        if ($context === null || $item->isNewItem()) {
            return [];
        }
        $table = ContextItem::getTable();
        // Lot 3 : contacts proposés selon la catégorie de l'incident (Rules::concerns()).
        $category = $context === Rules::CONTEXT_INCIDENT ? (string) ($item->fields['category'] ?? '') : null;
        $rows = self::visibleContacts([
            ['OR' => [['on_security_incident' => 1], ['on_data_breach' => 1]]],
            getEntitiesRestrictCriteria($table, '', (int) $item->fields['entities_id'], true),
        ]);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => Rules::concerns($row, $context, $category === '' ? null : $category)
        ));
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            self::contextFor($item) === null
            || !$item instanceof CommonDBTM
            || $item->isNewItem()
            || !self::canView()
            || !$item->canViewItem()
        ) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            count(self::concernedBy($item)),
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : mêmes contrôles.
        if (
            self::contextFor($item) === null
            || !$item instanceof CommonDBTM
            || $item->isNewItem()
            || !self::canView()
            || !$item->can($item->getID(), READ)
        ) {
            return false;
        }
        $breach = self::contextFor($item) === Rules::CONTEXT_BREACH;
        $concerned = self::concernedBy($item);
        // Issue #771, lot 2 : contacts déjà tracés sans être cochés pour ce type d'événement
        // (un CSIRT prévenu pour une violation, par exemple) : affichés aussi.
        $shown = array_column($concerned, 'id');
        $tracedIds = array_column(
            OfficialContactNotification::rowsFor($item),
            OfficialContactNotification::CONTACT_FK
        );
        $traced = array_diff(array_map('intval', $tracedIds), array_map('intval', $shown));
        if ($traced !== []) {
            $concerned = array_merge(
                $concerned,
                OfficialContactNotification::reachableContacts($item, ['id' => array_values(array_unique($traced))])
            );
        }
        $choices = [];
        foreach (OfficialContactNotification::reachableContacts($item) as $contact) {
            $choices[(int) $contact['id']] = (string) $contact['name'];
        }
        $start = OfficialContactNotification::startOf($item);
        TemplateRenderer::getInstance()->display('@grcmanager/official_contact_tab.html.twig', [
            'contacts'     => self::presentAll($concerned),
            'notices'      => OfficialContactNotification::noticesFor($item, $concerned),
            'traces'       => OfficialContactNotification::tracesFor($item),
            'choices'      => $choices,
            'decisions'    => OfficialContactNotification::decisionLabels(),
            'can_record'   => $item->canUpdateItem() && $choices !== [],
            'form_url'     => OfficialContactNotification::getFormURL(),
            'itemtype'     => $item::class,
            'items_id'     => (int) $item->getID(),
            'contact_fk'   => OfficialContactNotification::CONTACT_FK,
            'start'        => $start ?? '',
            'start_label'  => $breach
                ? __('Awareness of the breach', 'grcmanager')
                : __('Detection of the incident', 'grcmanager'),
            'now'          => date('Y-m-d\TH:i'),
            'intro'        => $breach
                ? __(
                    'Official contacts to inform for a personal data breach, with the time limit and the person '
                        . 'allowed to contact them.',
                    'grcmanager'
                )
                : __(
                    'Official contacts to inform for a security incident, with the time limit and the person '
                        . 'allowed to contact them.',
                    'grcmanager'
                ),
            'register_url' => self::getRegisterURL(),
            'export_url'   => self::getExportURL(),
            'can_create'   => ContextItem::canCreate(),
            'add_url'      => ContextItem::getFormURL() . '?kind=' . ContextItemRules::KIND_PARTY . '&official=1',
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Preuve des mesures A.5.5 et A.5.6
    // ------------------------------------------------------------------

    /**
     * Preuve affichée sur la fiche d'une mesure : contacts de la catégorie de la mesure, vérifiés ou
     * à vérifier, dans les entités de la personne. Null pour les autres mesures ou sans droit.
     *
     * @return array{total: int, verified: int, to_verify: int, notified: int, url: string, export_url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        $categories = Rules::categoriesForControl($code);
        if ($categories === null || !self::canView()) {
            return null;
        }
        $rows = self::visibleContacts(['contact_category' => $categories]);
        $since = (new \DateTimeImmutable('today'))->modify('-' . Rules::VERIFICATION_MONTHS . ' months');

        return Rules::summary($rows, date('Y-m-d')) + [
            // Issue #771, lot 2 : prévenances tracées depuis 12 mois (incidents et violations).
            'notified'   => OfficialContactNotification::countNotifiedSince(
                $categories,
                $since->format('Y-m-d 00:00:00')
            ),
            'url'        => self::getRegisterURL(),
            'export_url' => self::getExportURL(),
        ];
    }

    // ------------------------------------------------------------------
    // Relance des contacts non vérifiés depuis 12 mois
    // ------------------------------------------------------------------

    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Reminds the owner of each official contact not verified for 12 months', 'grcmanager'),
        ];
    }

    /**
     * Tâche automatique : prévient le propriétaire de chaque contact officiel jamais vérifié ou
     * vérifié il y a plus de 12 mois, au plus une fois par mois. Toutes les entités (pas de session),
     * fiches de la corbeille exclues.
     */
    public static function cronContactverification(CronTask $task): int
    {
        $result = self::sendReminders(date('Y-m-d'));
        $task->addVolume($result['notified']);
        $task->log(sprintf(
            __('%1$d official contact(s) to verify, %2$d reminder(s) sent.', 'grcmanager'),
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

        $table = ContextItem::getTable();
        $due = 0;
        $notified = 0;
        if (!$DB->fieldExists($table, 'is_official_contact')) {
            return ['due' => 0, 'notified' => 0];
        }
        foreach (
            $DB->request([
                'FROM'  => $table,
                'WHERE' => ['is_deleted' => 0, 'is_official_contact' => 1, 'kind' => ContextItemRules::KIND_PARTY],
            ]) as $row
        ) {
            $verified = isset($row['contact_verified_date']) ? (string) $row['contact_verified_date'] : null;
            if (Rules::verificationStatus($verified, $today) === Rules::STATUS_OK) {
                continue;
            }
            $due++;
            $reminder = isset($row['contact_reminder_date']) ? (string) $row['contact_reminder_date'] : null;
            if (!Rules::shouldRemind($verified, $reminder, $today)) {
                continue;
            }
            $item = new ContextItem();
            if (!$item->getFromDB((int) $row['id'])) {
                continue;
            }
            if (NotificationEvent::raiseEvent(ContextItem::CONTACT_VERIFICATION_EVENT, $item)) {
                $notified++;
            }
            // Une relance par mois au plus, envoyée ou non (aucune notification active).
            $DB->update($table, ['contact_reminder_date' => $today . ' ' . date('H:i:s')], ['id' => (int) $row['id']]);
        }

        return ['due' => $due, 'notified' => $notified];
    }
}
