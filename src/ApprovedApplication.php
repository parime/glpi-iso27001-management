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

use Appliance;
use CommonDBTM;
use CommonGLPI;
use CommonITILObject;
use CronTask;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ApprovedApplicationBase;
use GlpiPlugin\Grcmanager\Install\ApprovedApplicationTables;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\ApprovedApplication\ApprovedApplicationRules as Rules;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
use Html;
use Preference;
use Session;
use Software;
use Supplier;
use Ticket;
use User;

/**
 * Issue #622 : applications et services en ligne approuvés (mesure A.5.23), usages et données
 * autorisés. Complète le registre des applications du cœur (Appliance) déjà utilisé par le
 * développement sécurisé (#777) : une fiche « Usage autorisé » en onglet de l'application, jamais
 * une application en double.
 *
 * - Fiche : statut (en évaluation, approuvée, approuvée avec restrictions, refusée), fournisseur et
 *   logiciel du cœur, responsable, services utilisateurs, hébergement, dates d'usage, cas d'usage
 *   et restrictions, niveau maximal de confidentialité des données (échelle C/I/D du plugin),
 *   dérogations accordées (registre des dérogations), cycle de revue.
 * - Page « Applications approuvées » (sous-page de la rubrique Risques) : registre, indicateurs et
 *   rapprochement avec l'inventaire logiciel (logiciels refusés installés, logiciels hors registre),
 *   sans aucune action automatique.
 * - Onglet « Applications autorisées » des préférences : liste en lecture seule pour tous les
 *   utilisateurs, même sans droit sur le plugin (entités de la session).
 * - Tâche automatique : à l'échéance de la revue, un ticket de réévaluation pour le responsable
 *   (un seul tant qu'il reste ouvert). Le statut n'est jamais changé automatiquement.
 *
 * Droits : droit général du plugin (lecture pour l'onglet et la page, création ou modification
 * pour la fiche), lecture native des applications et de leur entité ; inventaire avec la lecture
 * native des logiciels.
 */
class ApprovedApplication extends ApprovedApplicationBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const CRON_NAME = 'approvedappreview';

    /** Logiciels hors registre montrés au plus (les plus installés d'abord). */
    public const UNLISTED_LIMIT = 50;

    /** Logiciels inventoriés lus au plus pour le rapprochement. */
    private const INVENTORY_LIMIT = 5000;

    public static function getTable($classname = null)
    {
        return ApprovedApplicationTables::SHEETS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Approved application', 'Approved applications', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-cloud-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/approvedapplication.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/approvedapplication.form.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(Installer::RIGHT_NAME, READ);
    }

    public static function canViewRegister(): bool
    {
        return self::canView() && (bool) Appliance::canView();
    }

    /**
     * Sous-page de la rubrique « Risques » (Menu::sections()), avec le droit général du plugin.
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
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            Rules::STATUS_EVALUATION => __('Under evaluation', 'grcmanager'),
            Rules::STATUS_APPROVED   => __('Approved', 'grcmanager'),
            Rules::STATUS_RESTRICTED => __('Approved with restrictions', 'grcmanager'),
            Rules::STATUS_REFUSED    => __('Refused', 'grcmanager'),
        ];
    }

    /**
     * Badge de chaque statut : classe de couleur (public/css/grcmanager.css) et icône Tabler.
     *
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function statusBadges(): array
    {
        $labels = self::statusLabels();

        return [
            Rules::STATUS_EVALUATION => [
                'label' => $labels[Rules::STATUS_EVALUATION],
                'class' => 'bg-blue-subtle text-blue-emphasis',
                'icon'  => 'ti-hourglass',
            ],
            Rules::STATUS_APPROVED   => [
                'label' => $labels[Rules::STATUS_APPROVED],
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-circle-check',
            ],
            Rules::STATUS_RESTRICTED => [
                'label' => $labels[Rules::STATUS_RESTRICTED],
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-alert-circle',
            ],
            Rules::STATUS_REFUSED    => [
                'label' => $labels[Rules::STATUS_REFUSED],
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-ban',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function hostingLabels(): array
    {
        return [
            Rules::HOSTING_ONLINE    => __('Online (cloud service, SaaS)', 'grcmanager'),
            Rules::HOSTING_ONPREMISE => __('On premises (our own servers)', 'grcmanager'),
        ];
    }

    /**
     * Niveau maximal de confidentialité des données, sur l'échelle de la classification C/I/D.
     *
     * @return array<string, string>
     */
    public static function levelLabels(): array
    {
        return [
            'low'    => __('Low: public or internal data only', 'grcmanager'),
            'medium' => __('Medium: up to restricted data (customers, staff)', 'grcmanager'),
            'high'   => __('High: confidential data allowed', 'grcmanager'),
        ];
    }

    /** @return array<string, array{label: string, class: string, icon: string}> */
    public static function reviewBadges(): array
    {
        return [
            Rules::REVIEW_OK      => [
                'label' => __('Review up to date', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-calendar-check',
            ],
            Rules::REVIEW_SOON    => [
                'label' => __('Review due soon', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-calendar-time',
            ],
            Rules::REVIEW_OVERDUE => [
                'label' => __('Review overdue', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-calendar-exclamation',
            ],
            Rules::REVIEW_NONE    => [
                'label' => __('No periodic review', 'grcmanager'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-calendar-off',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function gapLabels(): array
    {
        return [
            Rules::GAP_NO_OWNER        => __('No owner', 'grcmanager'),
            Rules::GAP_NO_USES         => __('Allowed uses not described', 'grcmanager'),
            Rules::GAP_NO_LEVEL        => __('Allowed data level not set', 'grcmanager'),
            Rules::GAP_NO_RESTRICTIONS => __('Restrictions not described', 'grcmanager'),
        ];
    }

    // ------------------------------------------------------------------ Fiche.

    /**
     * @return array<string, mixed>|null fiche de l'application, null si elle n'en a pas
     */
    public static function getForAppliance(int $appliancesId): ?array
    {
        global $DB;

        if ($appliancesId <= 0 || !$DB->tableExists(self::getTable())) {
            return null;
        }
        $criteria = ['FROM' => self::getTable(), 'WHERE' => ['appliances_id' => $appliancesId], 'LIMIT' => 1];
        foreach ($DB->request($criteria) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Valeurs reçues du formulaire, toutes filtrées : listes fermées, dates valides, responsable,
     * fournisseur, logiciel et dérogations existants. L'application elle-même n'est jamais prise ici.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $owner = (int) ($input['users_id_owner'] ?? 0);
        if ($owner > 0 && !(new User())->getFromDB($owner)) {
            $owner = 0;
        }
        $supplier = (int) ($input['suppliers_id'] ?? 0);
        if ($supplier > 0 && !(new Supplier())->getFromDB($supplier)) {
            $supplier = 0;
        }
        $software = (int) ($input['softwares_id'] ?? 0);
        if ($software > 0 && !(new Software())->getFromDB($software)) {
            $software = 0;
        }
        $waivers = array_values(array_filter(
            Rules::sanitizeIds($input['waivers'] ?? []),
            static fn (int $id): bool => (new Waiver())->getFromDB($id)
        ));
        $start = Rules::sanitizeDate($input['date_start'] ?? null);
        $end = Rules::sanitizeDate($input['date_end'] ?? null);
        if ($start !== null && $end !== null && $end < $start) {
            $end = null;
        }
        $status = Rules::sanitizeStatus($input['status'] ?? '');

        return [
            'status'           => $status,
            'hosting'          => Rules::sanitizeHosting($input['hosting'] ?? ''),
            'suppliers_id'     => max(0, $supplier),
            'softwares_id'     => max(0, $software),
            'users_id_owner'   => max(0, $owner),
            'user_services'    => trim((string) ($input['user_services'] ?? '')),
            'date_start'       => $start,
            'date_end'         => $end,
            'allowed_uses'     => trim((string) ($input['allowed_uses'] ?? '')),
            // Les restrictions n'ont de sens que pour une application approuvée avec restrictions.
            'restrictions'     => $status === Rules::STATUS_RESTRICTED
                ? trim((string) ($input['restrictions'] ?? ''))
                : '',
            'max_data_level'   => Rules::sanitizeLevel($input['max_data_level'] ?? ''),
            'waivers'          => Rules::encodeIds($waivers),
            'review_months'    => Rules::sanitizeMonths($input['review_months'] ?? ''),
            'last_review_date' => Rules::sanitizeDate($input['last_review_date'] ?? null),
            'comment'          => trim((string) ($input['comment'] ?? '')),
        ];
    }

    /**
     * Enregistre la fiche de l'application (création ou mise à jour). L'appelant a vérifié les droits.
     *
     * @param array<string, mixed> $input
     * @return string|null message d'erreur, null si enregistré
     */
    public static function saveFor(int $appliancesId, array $input): ?string
    {
        $values = self::sanitize($input);
        if (!empty($input['reviewed_today'])) {
            $values['last_review_date'] = date('Y-m-d');
        }
        $existing = self::getForAppliance($appliancesId);
        $sheet = new self();
        $saved = $existing !== null
            ? $sheet->update(['id' => (int) $existing['id']] + $values)
            : $sheet->add(['appliances_id' => $appliancesId] + $values);

        return $saved ? null : __('The approved use sheet could not be saved.', 'grcmanager');
    }

    /**
     * Hooks::ITEM_PURGE (hook.php) : la fiche d'une application supprimée définitivement disparaît
     * avec elle.
     */
    public static function forgetItem(CommonDBTM $item): void
    {
        global $DB;

        if ($item instanceof Appliance && $DB->tableExists(self::getTable())) {
            $DB->delete(self::getTable(), ['appliances_id' => (int) $item->getID()]);
        }
    }

    // ------------------------------------------------------------------ Onglets.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            return self::createTabEntry(__('Authorised applications', 'grcmanager'), 0, null, 'ti ti-cloud-check');
        }
        if (!$item instanceof Appliance || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return self::createTabEntry(__('Approved use', 'grcmanager'), 0, null, 'ti ti-cloud-check');
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : les droits sont
     * vérifiés ici aussi (droit du plugin, application lisible avec son entité).
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            self::showMine();

            return true;
        }
        if (!$item instanceof Appliance || $item->isNewItem() || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        self::showForAppliance($item);

        return true;
    }

    private static function showForAppliance(Appliance $item): void
    {
        $appliancesId = (int) $item->getID();
        $sheet = self::getForAppliance($appliancesId);
        $today = date('Y-m-d');
        $next = $sheet !== null ? Rules::nextReview($sheet, $today) : null;
        $classification = AssetClassification::getByItem(Appliance::class, $appliancesId);
        $confidentiality = (string) ($classification['confidentiality'] ?? '');
        $ticketId = (int) ($sheet['review_tickets_id'] ?? 0);
        $waiverIds = Rules::decodeIds(isset($sheet['waivers']) ? (string) $sheet['waivers'] : null);

        TemplateRenderer::getInstance()->display('@grcmanager/approved_application_tab.html.twig', [
            'appliances_id'   => $appliancesId,
            'entities_id'     => (int) $item->fields['entities_id'],
            'sheet'           => $sheet ?? ['review_months' => Rules::DEFAULT_REVIEW_MONTHS],
            'has_sheet'       => $sheet !== null,
            'can_edit'        => (bool) Session::haveRight(self::$rightname, $sheet === null ? CREATE : UPDATE),
            'action'          => self::getFormURL(),
            'register_url'    => self::getSearchURL(),
            'statuses'        => self::statusLabels(),
            'status_badges'   => self::statusBadges(),
            'hostings'        => self::hostingLabels(),
            'levels'          => self::levelLabels(),
            'review_badges'   => self::reviewBadges(),
            'gap_labels'      => self::gapLabels(),
            'gaps'            => $sheet !== null ? Rules::gaps($sheet) : [],
            'next_review'     => $next !== null ? (string) Html::convDate($next) : '',
            'review_state'    => $sheet !== null ? Rules::reviewState($next, $today) : Rules::REVIEW_NONE,
            'ended'           => $sheet !== null && Rules::hasEnded(
                isset($sheet['date_end']) ? (string) $sheet['date_end'] : null,
                $today
            ),
            'level_exceeded'  => $sheet !== null
                && Rules::levelExceeded((string) $sheet['max_data_level'], $confidentiality),
            'classification'  => $confidentiality,
            'class_levels'    => AssetClassification::getLevels(),
            'waiver_choices'  => self::waiverChoices(),
            'waiver_values'   => $waiverIds,
            'waivers'         => self::waiverRows($waiverIds),
            'owner_name'      => (int) ($sheet['users_id_owner'] ?? 0) > 0
                ? getUserName((int) $sheet['users_id_owner']) : '',
            'supplier_name'   => self::dropdownName(Supplier::getTable(), (int) ($sheet['suppliers_id'] ?? 0)),
            'software_name'   => self::dropdownName(Software::getTable(), (int) ($sheet['softwares_id'] ?? 0)),
            'ticket_url'      => $ticketId > 0 ? Ticket::getFormURLWithID($ticketId) : '',
            'ticket_id'       => $ticketId,
            'default_months'  => Rules::DEFAULT_REVIEW_MONTHS,
            'max_months'      => Rules::MAX_REVIEW_MONTHS,
        ]);
    }

    private static function dropdownName(string $table, int $id): string
    {
        return $id > 0 ? (string) Dropdown::getDropdownName($table, $id) : '';
    }

    /**
     * Dérogations du registre que la personne peut choisir (entités de la session).
     *
     * @return array<int, string>
     */
    public static function waiverChoices(): array
    {
        global $DB;

        $choices = [];
        if (!Waiver::canView()) {
            return $choices;
        }
        $table = Waiver::getTable();
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => $table,
                'WHERE'  => ['is_deleted' => 0] + EntityPerimeter::criteria($table),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $choices[(int) $row['id']] = (string) $row['name'];
        }

        return $choices;
    }

    /**
     * Dérogations accordées à l'application, visibles par la personne, avec leur état.
     *
     * @param list<int> $ids
     * @return list<array{name: string, url: string, state: array{label: string, class: string}}>
     */
    public static function waiverRows(array $ids): array
    {
        global $DB;

        if ($ids === [] || !Waiver::canView()) {
            return [];
        }
        $table = Waiver::getTable();
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => $table,
                'WHERE' => ['id' => $ids, 'is_deleted' => 0] + EntityPerimeter::criteria($table),
                'ORDER' => 'name',
            ]) as $row
        ) {
            $rows[] = [
                'name'  => (string) $row['name'],
                'url'   => Waiver::getFormURLWithID((int) $row['id']),
                'state' => Waiver::stateBadge((string) $row['status'], $row['date_end']),
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ Registre.

    /**
     * Fiches des applications visibles par la personne (entités de la session, hors corbeille).
     *
     * @return list<array<string, mixed>>
     */
    public static function sheets(): array
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $sheets = self::getTable();
        $rows = $DB->request([
            'SELECT'     => [
                $sheets . '.*',
                'glpi_appliances.name AS appliance_name',
                'glpi_appliances.entities_id',
                'glpi_appliances.users_id_tech',
            ],
            'FROM'       => $sheets,
            'INNER JOIN' => [
                'glpi_appliances' => ['FKEY' => ['glpi_appliances' => 'id', $sheets => 'appliances_id']],
            ],
            'WHERE'      => ['glpi_appliances.is_deleted' => 0]
                + getEntitiesRestrictCriteria('glpi_appliances', '', '', true),
            'ORDER'      => 'glpi_appliances.name',
        ]);
        $list = [];
        foreach ($rows as $row) {
            $list[] = $row;
        }

        return $list;
    }

    /**
     * Lignes du registre, prêtes pour l'affichage.
     *
     * @param list<array<string, mixed>> $sheets
     * @return list<array<string, mixed>>
     */
    public static function registerRows(array $sheets, string $today): array
    {
        $classifications = AssetClassification::getByItemtype(Appliance::class);
        $rows = [];
        foreach ($sheets as $sheet) {
            $appliancesId = (int) $sheet['appliances_id'];
            $next = Rules::nextReview($sheet, $today);
            $owner = (int) $sheet['users_id_owner'] > 0
                ? (int) $sheet['users_id_owner']
                : (int) $sheet['users_id_tech'];
            $end = isset($sheet['date_end']) ? (string) $sheet['date_end'] : null;
            $waivers = Rules::decodeIds(isset($sheet['waivers']) ? (string) $sheet['waivers'] : null);
            $confidentiality = (string) ($classifications[$appliancesId]['confidentiality'] ?? '');
            $rows[] = [
                'name'           => (string) $sheet['appliance_name'],
                'url'            => Appliance::getFormURLWithID($appliancesId),
                'entity'         => self::dropdownName('glpi_entities', (int) $sheet['entities_id']),
                'status'         => Rules::sanitizeStatus($sheet['status']),
                'hosting'        => (string) $sheet['hosting'],
                'supplier'       => self::dropdownName(Supplier::getTable(), (int) $sheet['suppliers_id']),
                'software'       => self::dropdownName(Software::getTable(), (int) $sheet['softwares_id']),
                'owner'          => $owner > 0 ? getUserName($owner) : '',
                'allowed_uses'   => (string) ($sheet['allowed_uses'] ?? ''),
                'restrictions'   => (string) ($sheet['restrictions'] ?? ''),
                'level'          => (string) $sheet['max_data_level'],
                'level_exceeded' => Rules::levelExceeded((string) $sheet['max_data_level'], $confidentiality),
                'next_review'    => $next !== null ? (string) Html::convDate($next) : '',
                'review_state'   => Rules::reviewState($next, $today),
                'ended'          => Rules::hasEnded($end, $today),
                'gaps'           => Rules::gaps($sheet),
                'waivers'        => count($waivers),
            ];
        }

        return $rows;
    }

    /**
     * Applications publiées aux utilisateurs (onglet des préférences) : approuvées, usage en cours,
     * dans les entités de la session. Aucun droit sur le plugin ni sur les applications n'est
     * demandé : c'est la liste que chacun doit pouvoir consulter. Les applications refusées sont
     * données à part (à ne pas utiliser).
     *
     * @return array{allowed: list<array<string, string>>, refused: list<array<string, string>>}
     */
    public static function published(string $today): array
    {
        global $DB;

        $result = ['allowed' => [], 'refused' => []];
        if (!$DB->tableExists(self::getTable()) || Session::getLoginUserID() === false) {
            return $result;
        }
        $sheets = self::getTable();
        $rows = $DB->request([
            'SELECT'     => [
                $sheets . '.status', $sheets . '.hosting', $sheets . '.allowed_uses', $sheets . '.restrictions',
                $sheets . '.max_data_level', $sheets . '.date_start', $sheets . '.date_end',
                $sheets . '.suppliers_id', 'glpi_appliances.name AS appliance_name',
            ],
            'FROM'       => $sheets,
            'INNER JOIN' => [
                'glpi_appliances' => ['FKEY' => ['glpi_appliances' => 'id', $sheets => 'appliances_id']],
            ],
            'WHERE'      => [
                'glpi_appliances.is_deleted' => 0,
                $sheets . '.status'          => [
                    Rules::STATUS_APPROVED, Rules::STATUS_RESTRICTED, Rules::STATUS_REFUSED,
                ],
            ] + getEntitiesRestrictCriteria('glpi_appliances', '', '', true),
            'ORDER'      => 'glpi_appliances.name',
        ]);
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $start = isset($row['date_start']) ? (string) $row['date_start'] : null;
            $end = isset($row['date_end']) ? (string) $row['date_end'] : null;
            $line = [
                'name'         => (string) $row['appliance_name'],
                'status'       => $status,
                'hosting'      => (string) $row['hosting'],
                'allowed_uses' => (string) ($row['allowed_uses'] ?? ''),
                'restrictions' => (string) ($row['restrictions'] ?? ''),
                'level'        => (string) $row['max_data_level'],
                'supplier'     => self::dropdownName(Supplier::getTable(), (int) $row['suppliers_id']),
            ];
            if ($status === Rules::STATUS_REFUSED) {
                if (!Rules::hasEnded($end, $today)) {
                    $result['refused'][] = $line;
                }
            } elseif (Rules::isPublished($status, $start, $end, $today)) {
                $result['allowed'][] = $line;
            }
        }

        return $result;
    }

    public static function showMine(): void
    {
        $published = self::published(date('Y-m-d'));
        TemplateRenderer::getInstance()->display('@grcmanager/approved_application_mine.html.twig', [
            'allowed'       => $published['allowed'],
            'refused'       => $published['refused'],
            'status_badges' => self::statusBadges(),
            'hostings'      => self::hostingLabels(),
            'levels'        => self::levelLabels(),
        ]);
    }

    // ------------------------------------------------------------------ Inventaire.

    /**
     * Rapprochement avec l'inventaire logiciel (lecture seule, aucune action automatique) :
     * - logiciels installés qui correspondent à une application refusée ou encore en évaluation ;
     * - logiciels installés absents du registre, les plus installés d'abord (catégorie de logiciels
     *   facultative pour réduire la liste).
     * Lecture native des logiciels, installations des entités de la session.
     *
     * @param list<array<string, mixed>> $sheets
     * @return array{available: bool, not_approved: list<array<string, mixed>>,
     *     unlisted: list<array<string, mixed>>, unlisted_total: int}
     */
    public static function inventory(array $sheets, int $categoryId = 0): array
    {
        global $DB;

        $result = ['available' => false, 'not_approved' => [], 'unlisted' => [], 'unlisted_total' => 0];
        if (!Software::canView()) {
            return $result;
        }
        $result['available'] = true;
        $bySoftware = [];
        $byName = [];
        foreach ($sheets as $sheet) {
            if ((int) $sheet['softwares_id'] > 0) {
                $bySoftware[(int) $sheet['softwares_id']] = $sheet;
            }
            $byName[] = $sheet;
        }
        $where = [
            'glpi_items_softwareversions.is_deleted'       => 0,
            'glpi_items_softwareversions.is_deleted_item'  => 0,
            'glpi_items_softwareversions.is_template_item' => 0,
            'glpi_softwares.is_deleted'                    => 0,
            'glpi_softwares.is_template'                   => 0,
        ] + getEntitiesRestrictCriteria('glpi_items_softwareversions', '', '', true);
        if ($categoryId > 0) {
            $where['glpi_softwares.softwarecategories_id'] = $categoryId;
        }
        $rows = $DB->request([
            'SELECT'     => [
                'glpi_softwares.id', 'glpi_softwares.name',
                'COUNT' => 'glpi_items_softwareversions.id AS installs',
            ],
            'FROM'       => 'glpi_items_softwareversions',
            'INNER JOIN' => [
                'glpi_softwareversions' => ['FKEY' => [
                    'glpi_softwareversions'       => 'id',
                    'glpi_items_softwareversions' => 'softwareversions_id',
                ]],
                'glpi_softwares'        => ['FKEY' => [
                    'glpi_softwares'        => 'id',
                    'glpi_softwareversions' => 'softwares_id',
                ]],
            ],
            'WHERE'      => $where,
            'GROUPBY'    => ['glpi_softwares.id', 'glpi_softwares.name'],
            'ORDER'      => ['installs DESC', 'glpi_softwares.name'],
            'LIMIT'      => self::INVENTORY_LIMIT,
        ]);
        foreach ($rows as $row) {
            $softwareId = (int) $row['id'];
            $name = (string) $row['name'];
            $sheet = $bySoftware[$softwareId] ?? null;
            if ($sheet === null) {
                foreach ($byName as $candidate) {
                    if (Rules::nameMatches($name, (string) $candidate['appliance_name'])) {
                        $sheet = $candidate;
                        break;
                    }
                }
            }
            $line = [
                'name'     => $name,
                'url'      => Software::getFormURLWithID($softwareId),
                'installs' => (int) $row['installs'],
            ];
            if ($sheet === null) {
                $result['unlisted_total']++;
                if (count($result['unlisted']) < self::UNLISTED_LIMIT) {
                    $result['unlisted'][] = $line;
                }
                continue;
            }
            $status = Rules::sanitizeStatus($sheet['status']);
            if (in_array($status, [Rules::STATUS_REFUSED, Rules::STATUS_EVALUATION], true)) {
                $result['not_approved'][] = $line + [
                    'status'      => $status,
                    'application' => (string) $sheet['appliance_name'],
                    'app_url'     => Appliance::getFormURLWithID((int) $sheet['appliances_id']),
                    'by_name'     => (int) $sheet['softwares_id'] !== $softwareId,
                ];
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------ Page.

    public static function showPage(int $categoryId = 0): void
    {
        $today = date('Y-m-d');
        $canSee = self::canViewRegister();
        $sheets = $canSee ? self::sheets() : [];
        TemplateRenderer::getInstance()->display('@grcmanager/approved_application_page.html.twig', [
            'can_see'        => $canSee,
            'indicators'     => Rules::indicators($sheets, $today),
            'rows'           => self::registerRows($sheets, $today),
            'inventory'      => $canSee ? self::inventory($sheets, $categoryId) : ['available' => false],
            'category_id'    => $categoryId,
            'page_url'       => self::getSearchURL(),
            'appliances_url' => Appliance::getSearchURL(),
            'status_badges'  => self::statusBadges(),
            'hostings'       => self::hostingLabels(),
            'levels'         => self::levelLabels(),
            'review_badges'  => self::reviewBadges(),
            'gap_labels'     => self::gapLabels(),
            'unlisted_limit' => self::UNLISTED_LIMIT,
        ]);
    }

    // ------------------------------------------------------------------ Preuve A.5.23.

    /**
     * Cadre de preuve de la mesure A.5.23 (fiche de la mesure), dans les entités de la personne.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if ($code !== 'A.5.23' || !self::canViewRegister()) {
            return null;
        }
        $today = date('Y-m-d');

        return [
            'indicators' => Rules::indicators(self::sheets(), $today),
            'url'        => self::getSearchURL(),
            'date'       => Html::convDate($today),
        ];
    }

    // ------------------------------------------------------------------ Revue périodique.

    public static function cronInfo(string $name): array
    {
        return [
            'description' => __(
                'Opens a reassessment ticket for each approved application whose review is due',
                'grcmanager'
            ),
        ];
    }

    public static function cronApprovedappreview(CronTask $task): int
    {
        $result = self::openReviewTickets(date('Y-m-d'));
        $task->addVolume($result['opened']);
        $task->log(sprintf(
            __('%1$d application(s) to review, %2$d ticket(s) opened.', 'grcmanager'),
            $result['due'],
            $result['opened']
        ));

        return $result['due'] > 0 ? 1 : 0;
    }

    /**
     * À l'échéance de la revue (date atteinte ou dépassée), un ticket « Réévaluer l'application »
     * pour le responsable (à défaut, le responsable technique de l'application), dans l'entité de
     * l'application. Un seul ticket tant qu'il reste ouvert ; rien n'est modifié sur la fiche, hormis
     * le lien vers le ticket. Toutes les entités (pas de session).
     *
     * @return array{due: int, opened: int}
     */
    public static function openReviewTickets(string $today): array
    {
        global $DB;

        $due = 0;
        $opened = 0;
        if (!$DB->tableExists(self::getTable())) {
            return ['due' => 0, 'opened' => 0];
        }
        $sheets = self::getTable();
        $rows = $DB->request([
            'SELECT'     => [
                $sheets . '.*', 'glpi_appliances.name AS appliance_name',
                'glpi_appliances.entities_id', 'glpi_appliances.users_id_tech',
            ],
            'FROM'       => $sheets,
            'INNER JOIN' => [
                'glpi_appliances' => ['FKEY' => ['glpi_appliances' => 'id', $sheets => 'appliances_id']],
            ],
            'WHERE'      => [
                'glpi_appliances.is_deleted' => 0,
                'NOT'                        => [$sheets . '.status' => Rules::STATUS_REFUSED],
            ],
        ]);
        foreach ($rows as $row) {
            $next = Rules::nextReview($row, $today);
            if (Rules::reviewState($next, $today) !== Rules::REVIEW_OVERDUE) {
                continue;
            }
            $due++;
            $ticketsId = (int) ($row['review_tickets_id'] ?? 0);
            $existing = new Ticket();
            if (
                $ticketsId > 0 && $existing->getFromDB($ticketsId)
                && !in_array(
                    (int) $existing->fields['status'],
                    [CommonITILObject::SOLVED, CommonITILObject::CLOSED],
                    true
                )
            ) {
                continue;
            }
            $name = (string) $row['appliance_name'];
            $input = [
                'name'        => sprintf(__('Reassess the approved application %s', 'grcmanager'), $name),
                'content'     => Fragment::ticketContent(
                    [
                        sprintf(
                            __('The periodic review of the application "%1$s" was due on %2$s.', 'grcmanager'),
                            $name,
                            Html::convDate((string) $next)
                        ),
                        __(
                            'Check that the allowed uses, the data level and the supplier are still right, then '
                                . 'record the review in the "Approved use" tab of the application.',
                            'grcmanager'
                        ),
                    ],
                    Appliance::getFormURLWithID((int) $row['appliances_id']),
                    __('Open the application', 'grcmanager')
                ),
                'type'        => Ticket::DEMAND_TYPE,
                'entities_id' => (int) $row['entities_id'],
            ];
            $owner = (int) $row['users_id_owner'] > 0 ? (int) $row['users_id_owner'] : (int) $row['users_id_tech'];
            if ($owner > 0) {
                $input['_users_id_requester'] = $owner;
                $input['_users_id_assign'] = $owner;
            }
            $newTicket = (int) (new Ticket())->add($input);
            if ($newTicket > 0) {
                $DB->update(self::getTable(), ['review_tickets_id' => $newTicket], ['id' => (int) $row['id']]);
                $opened++;
            }
        }

        return ['due' => $due, 'opened' => $opened];
    }
}
