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
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Grcmanager\Compatibility\Base\WaiverBase;
use GlpiPlugin\Grcmanager\Services\Waiver\WaiverRules;
use Html;
use Item_Ticket;
use Log;
use NotificationEvent;
use GlpiPlugin\Grcmanager\AccessReview;
use PluginGrcmanagerControl;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\Risk;
use Session;
use Ticket;

/**
 * Issue #170 : registre des dérogations de sécurité. Une dérogation accepte, pour un temps limité,
 * une exception à une politique ou à une mesure de l'Annexe A pour des actifs donnés (un serveur
 * qui ne peut pas encore être mis à jour, par exemple), avec sa justification, ses mesures
 * compensatoires et la personne qui en accepte le risque.
 *
 * Le responsable est prévenu 15 jours avant la fin ; à l'échéance, un ticket de réévaluation est
 * ouvert. La dérogation n'est jamais prolongée ni close automatiquement (règle commune) : c'est
 * l'équipe qui décide. Registre global, comme les autres registres du plugin ; un actif lié n'est
 * nommé qu'à qui peut le voir dans GLPI.
 */
class Waiver extends WaiverBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const EXPIRING_EVENT = 'waiver_expiring';

    public const ITEMS_TABLE = 'glpi_plugin_grcmanager_waivers_items';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_waivers';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Waiver', 'Waivers', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-alert';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            WaiverRules::STATUS_REQUESTED => __('Requested', 'grcmanager'),
            WaiverRules::STATUS_APPROVED  => _x('validated', 'Approved', 'grcmanager'),
            WaiverRules::STATUS_REJECTED  => __('Rejected', 'grcmanager'),
            WaiverRules::STATUS_CLOSED    => _x('ended, feminine', 'Closed', 'grcmanager'),
        ];
    }

    /**
     * @return array{label: string, class: string}
     */
    public static function stateBadge(string $status, ?string $dateEnd): array
    {
        $state = WaiverRules::state($status, $dateEnd, date('Y-m-d'));

        return match ($state) {
            WaiverRules::STATE_ACTIVE   => ['label' => __('In progress', 'grcmanager'), 'class' => 'bg-green-lt'],
            WaiverRules::STATE_EXPIRING => ['label' => __('Ending soon', 'grcmanager'), 'class' => 'bg-orange-lt'],
            WaiverRules::STATE_EXPIRED  => ['label' => _x(
                'due date passed, feminine',
                'Expired',
                'grcmanager'
            ), 'class' => 'bg-red-lt'],
            WaiverRules::STATUS_REJECTED => ['label' => __('Rejected', 'grcmanager'), 'class' => 'bg-secondary-lt'],
            WaiverRules::STATUS_CLOSED  => ['label' => _x(
                'ended, feminine',
                'Closed',
                'grcmanager'
            ), 'class' => 'bg-secondary-lt'],
            default                     => ['label' => __('Requested', 'grcmanager'), 'class' => 'bg-blue-lt'],
        };
    }

    /**
     * Actifs auxquels une dérogation peut s'appliquer : les mêmes que pour le registre des risques.
     *
     * @return array<int, string>
     */
    public static function getLinkableItemtypes(): array
    {
        return Risk::getLinkableItemtypes();
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            $this->fields['status'] = WaiverRules::STATUS_REQUESTED;
            $this->fields['date_begin'] = date('Y-m-d');
        }

        $linked = [];
        foreach (self::getWaiverItems((int) $ID) as $link) {
            // Un actif invisible pour l'utilisateur n'est jamais nommé par la liste déroulante.
            if (self::canSeeLinkedItem($link['itemtype'], $link['items_id'])) {
                $linked[$link['itemtype']][] = $link['items_id'];
            }
        }
        // Issue #170 : « Nouvelle dérogation » depuis l'onglet d'un actif le présélectionne (options
        // transmises par front/waiver.form.php à l'onglet, chargé à part).
        $preset = (string) ($options['link_itemtype'] ?? '');
        $presetId = (int) ($options['link_items_id'] ?? 0);
        if ($this->isNewItem() && self::canSeeLinkedItem($preset, $presetId)) {
            $linked[$preset][] = $presetId;
        }
        $groups = [];
        foreach (self::getLinkableItemtypes() as $itemtype) {
            if (!is_a($itemtype, CommonDBTM::class, true) || !$itemtype::canView()) {
                continue;
            }
            $groups[] = [
                'itemtype' => $itemtype,
                'field'    => self::linkedFieldName($itemtype),
                'label'    => $itemtype::getTypeName(Session::getPluralNumber()),
                'values'   => $linked[$itemtype] ?? [],
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/waiver_form.html.twig', [
            'item'     => $this,
            'params'   => $options,
            'statuses' => self::getStatuses(),
            'state'    => self::stateBadge(
                (string) ($this->fields['status'] ?? ''),
                $this->fields['date_end'] ?? null
            ),
            'groups'   => $groups,
            'controls' => AccessReview::getControlChoices(),
            'policies' => self::getPolicyChoices(),
            'approver' => (int) ($this->fields['users_id_approver'] ?? 0) > 0
                ? getUserName((int) $this->fields['users_id_approver']) : '',
            'requester' => (int) ($this->fields['users_id_requester'] ?? 0) > 0
                ? getUserName((int) $this->fields['users_id_requester']) : '',
        ]);

        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function getPolicyChoices(): array
    {
        global $DB;

        $choices = [0 => Dropdown::EMPTY_VALUE];
        foreach ($DB->request(['SELECT' => ['id', 'title'], 'FROM' => Policy::getTable()]) as $row) {
            $choices[(int) $row['id']] = (string) $row['title'];
        }
        asort($choices);

        return $choices;
    }

    public function prepareInputForAdd($input)
    {
        // Le demandeur est la personne qui crée la dérogation, jamais une valeur saisie.
        $input['users_id_requester'] = (int) Session::getLoginUserID();

        return $this->checkInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        unset($input['users_id_requester']);

        return $this->checkInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkInput(array $input)
    {
        $merged = $input + $this->fields;
        $errors = WaiverRules::validate(
            (string) ($merged['name'] ?? ''),
            isset($merged['date_begin']) ? (string) $merged['date_begin'] : null,
            isset($merged['date_end']) && $merged['date_end'] !== '' ? (string) $merged['date_end'] : null
        );
        if ($errors !== []) {
            $messages = [
                'name_required'         => __('Give the waiver a name.', 'grcmanager'),
                'date_end_required'     => __('A waiver is temporary: its end date is required.', 'grcmanager'),
                'date_end_before_begin' => __('The end date is before the start date.', 'grcmanager'),
            ];
            foreach ($errors as $error) {
                Session::addMessageAfterRedirect(htmlescape($messages[$error]), false, ERROR);
            }

            return false;
        }

        if (isset($input['status']) && !in_array($input['status'], WaiverRules::STATUSES, true)) {
            $input['status'] = WaiverRules::STATUS_REQUESTED;
        }
        // Validation : qui l'a donnée et quand, pour l'auditeur.
        $wasApproved = ($this->fields['status'] ?? '') === WaiverRules::STATUS_APPROVED;
        if (($input['status'] ?? null) === WaiverRules::STATUS_APPROVED && !$wasApproved) {
            // Séparation des tâches : une dérogation accepte formellement un risque, son
            // demandeur ne peut pas l'approuver lui-même (comme pour la SoA).
            $requester = (int) ($input['users_id_requester'] ?? $this->fields['users_id_requester'] ?? 0);
            if ($requester > 0 && $requester === (int) Session::getLoginUserID()) {
                Session::addMessageAfterRedirect(htmlescape(__(
                    'You cannot approve a waiver you requested.',
                    'grcmanager'
                )), false, ERROR);

                return false;
            }
            $input['users_id_approver'] = (int) Session::getLoginUserID();
            $input['date_approval'] = date('Y-m-d H:i:s');
        }
        // Une nouvelle date de fin ouvre un nouveau cycle de rappel et de réévaluation.
        if (
            !$this->isNewItem() && isset($input['date_end'])
            && substr((string) $input['date_end'], 0, 10) !== substr((string) ($this->fields['date_end'] ?? ''), 0, 10)
        ) {
            $input['date_notified'] = null;
            $input['tickets_id'] = 0;
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->syncLinkedItemsFromInput();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->syncLinkedItemsFromInput();
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(self::ITEMS_TABLE, ['plugin_grcmanager_waivers_id' => $this->fields['id']]);
    }

    public static function linkedFieldName(string $itemtype): string
    {
        return 'linked_items_' . str_replace('\\', '_', $itemtype);
    }

    /**
     * L'utilisateur courant peut-il lire cet actif (droit du type, entité) ? Un lien vers un
     * actif qu'il ne voit pas n'est ni créé, ni affiché, ni retiré par lui.
     */
    public static function canSeeLinkedItem(string $itemtype, int $itemsId): bool
    {
        if ($itemsId <= 0 || !in_array($itemtype, self::getLinkableItemtypes(), true)) {
            return false;
        }
        // Type validé par la liste blanche getLinkableItemtypes() ci-dessus.
        // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
        $item = new $itemtype();

        return $item instanceof CommonDBTM && $item->can($itemsId, READ);
    }

    /**
     * Les listes d'actifs du formulaire envoient un champ « _<nom>_defined » même vides : seul le
     * vrai formulaire remplace les liens, jamais un appel qui ne les connaît pas. Seuls les
     * actifs que l'utilisateur peut lire sont acceptés ; les liens existants vers des actifs
     * qu'il ne voit pas restent en place (revue de sécurité 2.11.0).
     */
    private function syncLinkedItemsFromInput(): void
    {
        global $DB;

        $pairs = [];
        $submitted = false;
        foreach (self::getLinkableItemtypes() as $itemtype) {
            $field = self::linkedFieldName($itemtype);
            if (!isset($this->input['_' . $field . '_defined']) && !isset($this->input[$field])) {
                continue;
            }
            $submitted = true;
            foreach ((array) ($this->input[$field] ?? []) as $itemsId) {
                if (self::canSeeLinkedItem($itemtype, (int) $itemsId)) {
                    $pairs[$itemtype . ':' . (int) $itemsId] = ['itemtype' => $itemtype, 'items_id' => (int) $itemsId];
                }
            }
        }
        if (!$submitted) {
            return;
        }

        $waiverId = (int) $this->fields['id'];
        foreach (self::getWaiverItems($waiverId) as $existing) {
            $key = $existing['itemtype'] . ':' . $existing['items_id'];
            if (isset($pairs[$key])) {
                unset($pairs[$key]);
                continue;
            }
            if (self::canSeeLinkedItem($existing['itemtype'], $existing['items_id'])) {
                $DB->delete(self::ITEMS_TABLE, [
                    'plugin_grcmanager_waivers_id' => $waiverId,
                    'itemtype'                     => $existing['itemtype'],
                    'items_id'                     => $existing['items_id'],
                ]);
            }
        }
        foreach ($pairs as $pair) {
            $DB->insert(self::ITEMS_TABLE, [
                'plugin_grcmanager_waivers_id' => $waiverId,
                'itemtype'                     => $pair['itemtype'],
                'items_id'                     => $pair['items_id'],
                'date_creation'                => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function getWaiverItems(int $waiverId): array
    {
        global $DB;

        $links = [];
        if ($waiverId <= 0) {
            return $links;
        }
        $where = ['plugin_grcmanager_waivers_id' => $waiverId];
        foreach ($DB->request(['FROM' => self::ITEMS_TABLE, 'WHERE' => $where]) as $row) {
            $links[] = ['itemtype' => (string) $row['itemtype'], 'items_id' => (int) $row['items_id']];
        }

        return $links;
    }

    /**
     * Dérogations liées à un élément : un actif (table de liens), une mesure ou une politique
     * (référence directe).
     *
     * @return list<array<string, mixed>>
     */
    public static function getForItem(CommonDBTM $item): array
    {
        global $DB;

        $table = self::getTable();
        $where = match ($item::class) {
            PluginGrcmanagerControl::class => ["$table.plugin_grcmanager_controls_id" => $item->getID()],
            Policy::class  => ["$table.plugin_grcmanager_policies_id" => $item->getID()],
            default                        => [
                "$table.id" => new QuerySubQuery([
                    'SELECT' => 'plugin_grcmanager_waivers_id',
                    'FROM'   => self::ITEMS_TABLE,
                    'WHERE'  => ['itemtype' => $item::class, 'items_id' => $item->getID()],
                ]),
            ],
        };

        $rows = [];
        $criteria = ['FROM' => $table, 'WHERE' => $where + ['is_deleted' => 0], 'ORDER' => 'date_end'];
        foreach ($DB->request($criteria) as $row) {
            $rows[] = $row + [
                'state' => self::stateBadge((string) $row['status'], $row['date_end']),
                'url'   => self::getFormURLWithID((int) $row['id']),
                'owner' => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
            ];
        }

        return $rows;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem() || !Session::haveRight(self::RIGHTNAME, READ)) {
            return '';
        }
        $supported = array_merge(
            self::getLinkableItemtypes(),
            [PluginGrcmanagerControl::class, Policy::class]
        );
        if (!in_array($item::class, $supported, true)) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), count(self::getForItem($item)));
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : même contrôle
        // ici (trouvé par le contrôle commun des onglets, parime/glpi-plugin-security).
        if (!$item instanceof CommonDBTM || !Session::haveRight(self::RIGHTNAME, READ)) {
            return false;
        }
        $newUrl = '';
        if (Session::haveRight(self::RIGHTNAME, CREATE) && in_array($item::class, self::getLinkableItemtypes(), true)) {
            $newUrl = self::getFormURL() . '?'
                . http_build_query(['link_itemtype' => $item::class, 'link_items_id' => $item->getID()]);
        }
        TemplateRenderer::getInstance()->display('@grcmanager/waiver_tab.html.twig', [
            'waivers'  => self::getForItem($item),
            'new_url'  => $newUrl,
            'statuses' => self::getStatuses(),
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(1)];
        $tab[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'name', 'name' => __('Nom'),
            'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = ['id' => 2, 'table' => self::getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number'];
        $tab[] = [
            'id' => 3, 'table' => self::getTable(), 'field' => 'status', 'name' => __('Statut'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'date_end', 'name' => __('End date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 5, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => __('Owner (accepts the risk)', 'grcmanager'), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 6, 'table' => PluginGrcmanagerControl::getTable(), 'field' => 'code',
            'name' => _x('measure', 'Annex A control', 'grcmanager'), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 7, 'table' => Policy::getTable(), 'field' => 'title',
            'name' => __('Policy', 'grcmanager'), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => 8, 'table' => self::getTable(), 'field' => 'date_begin', 'name' => __('Start date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 9, 'table' => self::getTable(), 'field' => 'compensating_measures',
            'name' => __('Compensating controls', 'grcmanager'), 'datatype' => 'text',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            return htmlescape(self::getStatuses()[(string) ($values[$field] ?? '')] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'status') {
            return Dropdown::showFromArray($name, self::getStatuses(), ['display' => false] + $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * @return array{description: string}|array{}
     */
    public static function cronInfo($name)
    {
        return $name === 'waiverreminder'
            ? ['description' => __(
                'Warns the owner before a waiver ends, then opens a re-assessment ticket',
                'grcmanager'
            )]
            : [];
    }

    public static function cronWaiverreminder(CronTask $task): int
    {
        $result = self::runReminders(date('Y-m-d'));
        $task->addVolume($result['notified'] + $result['tickets']);

        return ($result['notified'] + $result['tickets']) > 0 ? 1 : 0;
    }

    /**
     * @return array{notified: int, tickets: int}
     */
    public static function runReminders(string $today): array
    {
        global $DB;

        $result = ['notified' => 0, 'tickets' => 0];
        $where = ['status' => WaiverRules::STATUS_APPROVED, 'is_deleted' => 0, 'NOT' => ['date_end' => null]];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where]) as $row) {
            $waiver = new self();
            if (!$waiver->getFromDB((int) $row['id'])) {
                continue;
            }
            if (WaiverRules::reminderDue($row['status'], $row['date_end'], $row['date_notified'], $today)) {
                NotificationEvent::raiseEvent(self::EXPIRING_EVENT, $waiver);
                $waiver->update(['id' => $waiver->getID(), 'date_notified' => date('Y-m-d H:i:s')]);
                $result['notified']++;
            }
            if (WaiverRules::ticketDue($row['status'], $row['date_end'], (int) $row['tickets_id'], $today)) {
                $ticketsId = $waiver->openReevaluationTicket();
                if ($ticketsId > 0) {
                    $waiver->update(['id' => $waiver->getID(), 'tickets_id' => $ticketsId]);
                    $result['tickets']++;
                }
            }
        }

        return $result;
    }

    /**
     * Ticket « Réévaluer la dérogation », attribué au responsable et lié aux actifs concernés.
     */
    private function openReevaluationTicket(): int
    {
        global $CFG_GLPI;

        $items = [];
        $entitiesId = 0;
        foreach (self::getWaiverItems((int) $this->getID()) as $link) {
            $itemtype = $link['itemtype'];
            if (!is_a($itemtype, CommonDBTM::class, true)) {
                continue;
            }
            $item = new $itemtype();
            if ($item->getFromDB($link['items_id'])) {
                $items[] = $link;
                $entitiesId = $entitiesId ?: (int) ($item->fields['entities_id'] ?? 0);
            }
        }
        $content = TemplateRenderer::getInstance()->render('@grcmanager/waiver_ticket.html.twig', [
            'name'         => $this->fields['name'],
            'date_end'     => Html::convDate($this->fields['date_end']),
            'justification' => (string) ($this->fields['description'] ?? ''),
            'measures'     => (string) ($this->fields['compensating_measures'] ?? ''),
            'url'          => rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
                . self::getFormURLWithID((int) $this->getID(), false),
        ]);
        $input = [
            'name'        => sprintf(__('Re-assess the waiver "%s"', 'grcmanager'), $this->fields['name']),
            'content'     => $content,
            'entities_id' => $entitiesId,
            'type'        => Ticket::DEMAND_TYPE,
        ];
        if ((int) $this->fields['users_id'] > 0) {
            $input['_users_id_assign'] = (int) $this->fields['users_id'];
        }
        $ticketsId = (int) (new Ticket())->add($input);
        foreach ($items as $link) {
            if ($ticketsId > 0) {
                (new Item_Ticket())->add([
                    'tickets_id' => $ticketsId,
                    'itemtype'   => $link['itemtype'],
                    'items_id'   => $link['items_id'],
                ]);
            }
        }

        return $ticketsId;
    }
}
