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

use Change;
use Change_Item;
use CommonDBTM;
use CommonGLPI;
use CommonITILObject;
use Computer;
use Glpi\Application\View\TemplateRenderer;
use Item_Problem;
use NetworkEquipment;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use Problem;
use Session;
use Software;
use Supplier;
use User;

/**
 * Issue #372 : onglet « Incidents de sécurité » sur les éléments liés (ordinateurs, matériels
 * réseau, logiciels, utilisateurs, fournisseurs, problèmes, changements), avec le bouton
 * « Déclarer un incident » qui crée un incident déjà lié à l'élément. Le ticket garde son propre
 * onglet (SecurityIncidentTicket), dont « Qualifier en incident de sécurité » fait la même chose.
 *
 * Un incident est lié à l'élément par ses éléments (PluginGrcmanagerSecurityIncident_Item) et,
 * pour un utilisateur ou un fournisseur, aussi comme acteur. Seuls les incidents que l'utilisateur
 * peut lire (entité, cloisonnement de l'équipe d'intervention) sont comptés et listés : ni le
 * compteur ni la liste ne trahissent un incident invisible.
 *
 * Simple fournisseur d'onglets (CommonGLPI), comme SecurityIncidentTicket : rien à stocker de plus.
 */
class SecurityIncidentLinkedItem extends CommonGLPI
{
    /**
     * Types d'éléments qui portent l'onglet.
     */
    public const ITEMTYPES = [
        Computer::class,
        NetworkEquipment::class,
        Software::class,
        User::class,
        Supplier::class,
        Problem::class,
        Change::class,
    ];

    /**
     * Table des acteurs de l'incident, pour les types qui peuvent en être.
     */
    private const ACTOR_TABLES = [
        User::class     => ['glpi_plugin_grcmanager_securityincidents_users', 'users_id'],
        Supplier::class => ['glpi_plugin_grcmanager_securityincidents_suppliers', 'suppliers_id'],
    ];

    /**
     * Liens éléments des problèmes et changements, repris sur l'incident déclaré (comme la
     * qualification d'un ticket reprend ses éléments).
     */
    /**
     * Types liés que l'onglet « Éléments » natif de l'incident n'affiche pas (il s'en tient aux
     * types du parc pris en charge par l'assistance) : l'incident les montre dans son propre onglet
     * « Autres éléments liés ».
     */
    public const NON_ASSET_ITEMTYPES = [User::class, Supplier::class, Problem::class, Change::class];

    private const ITIL_ITEM_LINKS = [
        Problem::class => [Item_Problem::class, 'problems_id'],
        Change::class  => [Change_Item::class, 'changes_id'],
    ];

    public static function getTypeName($nb = 0)
    {
        return PluginGrcmanagerSecurityIncident::getTypeName($nb);
    }

    public static function getIcon()
    {
        return PluginGrcmanagerSecurityIncident::getIcon();
    }

    /**
     * Ids de tous les incidents liés à l'élément, sans contrôle de droit (usage interne).
     *
     * @return list<int>
     */
    public static function getIncidentIdsForItem(CommonDBTM $item): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request([
            'SELECT' => ['plugin_grcmanager_securityincidents_id'],
            'FROM'   => PluginGrcmanagerSecurityIncident_Item::getTable(),
            'WHERE'  => ['itemtype' => $item::class, 'items_id' => (int) $item->getID()],
        ]);
        foreach ($rows as $row) {
            $ids[] = (int) $row['plugin_grcmanager_securityincidents_id'];
        }

        if (isset(self::ACTOR_TABLES[$item::class])) {
            [$table, $field] = self::ACTOR_TABLES[$item::class];
            $rows = $DB->request([
                'SELECT' => ['plugin_grcmanager_securityincidents_id'],
                'FROM'   => $table,
                'WHERE'  => [$field => (int) $item->getID()],
            ]);
            foreach ($rows as $row) {
                $ids[] = (int) $row['plugin_grcmanager_securityincidents_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Incidents liés à l'élément que l'utilisateur peut lire (entité, « Voir tout » ou acteur,
     * équipe d'intervention), hors corbeille, du plus récent au plus ancien.
     *
     * @return list<PluginGrcmanagerSecurityIncident>
     */
    public static function getVisibleIncidents(CommonDBTM $item): array
    {
        if (!PluginGrcmanagerSecurityIncident::canView()) {
            return [];
        }

        $incidents = [];
        foreach (self::getIncidentIdsForItem($item) as $id) {
            $incident = new PluginGrcmanagerSecurityIncident();
            if ($incident->can($id, READ) && !$incident->isDeleted()) {
                $incidents[] = $incident;
            }
        }
        usort(
            $incidents,
            static fn (PluginGrcmanagerSecurityIncident $a, PluginGrcmanagerSecurityIncident $b): int
                => strcmp((string) $b->fields['date'], (string) $a->fields['date'])
        );

        return $incidents;
    }

    /**
     * « Déclarer un incident » : créer un incident exige ce droit, et l'élément doit être lu par
     * l'utilisateur.
     */
    public static function canDeclareFor(CommonDBTM $item): bool
    {
        return in_array($item::class, self::ITEMTYPES, true)
            && PluginGrcmanagerSecurityIncident::canCreate()
            && !$item->isNewItem()
            && $item->can((int) $item->getID(), READ);
    }

    /**
     * Utilisateurs, fournisseurs, problèmes et changements liés à l'incident, que l'utilisateur
     * peut lire.
     *
     * @return list<array{type: string, name: string, url: string}>
     */
    public static function getVisibleOtherItems(PluginGrcmanagerSecurityIncident $incident): array
    {
        global $DB;

        $rows = [];
        $links = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => PluginGrcmanagerSecurityIncident_Item::getTable(),
            'WHERE'  => [
                'plugin_grcmanager_securityincidents_id' => (int) $incident->getID(),
                'itemtype'                               => self::NON_ASSET_ITEMTYPES,
            ],
            'ORDER'  => ['itemtype', 'items_id'],
        ]);
        foreach ($links as $link) {
            $other = getItemForItemtype((string) $link['itemtype']);
            if ($other instanceof CommonDBTM && $other->can((int) $link['items_id'], READ)) {
                $rows[] = [
                    'type' => (string) $other::getTypeName(1),
                    'name' => (string) $other->getFriendlyName(),
                    'url'  => (string) $other->getLinkURL(),
                ];
            }
        }

        return $rows;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof PluginGrcmanagerSecurityIncident) {
            if ($withtemplate || $item->isNewItem() || !$item->canViewItem()) {
                return '';
            }
            $others = self::getVisibleOtherItems($item);

            // Onglet montré seulement s'il a quelque chose à montrer.
            return $others === []
                ? ''
                : self::createTabEntry(__('Other linked items', 'grcmanager'), count($others), null, 'ti ti-link');
        }

        if (
            $withtemplate
            || !$item instanceof CommonDBTM
            || !in_array($item::class, self::ITEMTYPES, true)
            || $item->isNewItem()
            || !PluginGrcmanagerSecurityIncident::canView()
        ) {
            return '';
        }

        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            // Les seuls incidents lisibles : le compteur ne révèle rien d'un incident invisible.
            $count = count(self::getVisibleIncidents($item));
        }

        return self::createTabEntry(
            PluginGrcmanagerSecurityIncident::getTypeName(Session::getPluralNumber()),
            $count,
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        if ($item instanceof PluginGrcmanagerSecurityIncident) {
            if ($item->isNewItem() || !$item->canViewItem()) {
                return false;
            }
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/security_incident_other_items.html.twig', [
                'rows' => self::getVisibleOtherItems($item),
            ]);

            return true;
        }

        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : droits revérifiés
        // ici (lecture des incidents, et de l'élément lui-même).
        if (
            !$item instanceof CommonDBTM
            || !in_array($item::class, self::ITEMTYPES, true)
            || $item->isNewItem()
            || !PluginGrcmanagerSecurityIncident::canView()
            || !$item->can((int) $item->getID(), READ)
        ) {
            return false;
        }

        $severities = PluginGrcmanagerSecurityIncident::getSeverities();
        $rows = [];
        foreach (self::getVisibleIncidents($item) as $incident) {
            $id = (int) $incident->getID();
            $rows[] = [
                'id'        => $id,
                'reference' => (string) ($incident->fields['reference'] ?? ''),
                'name'      => (string) $incident->fields['name'],
                'url'       => PluginGrcmanagerSecurityIncident::getFormURLWithID($id),
                'date'      => (string) $incident->fields['date'],
                'status'    => PluginGrcmanagerSecurityIncident::getStatus((int) $incident->fields['status']),
                'severity'  => $severities[(string) $incident->fields['severity']] ?? '',
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/tabs/item_security_incidents.html.twig', [
            'rows'        => $rows,
            'can_declare' => self::canDeclareFor($item),
            'action'      => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/securityincidentlinkeditem.form.php',
            'itemtype'    => $item::class,
            'items_id'    => (int) $item->getID(),
        ]);

        return true;
    }

    /**
     * Crée un incident de sécurité lié à l'élément. Un problème ou un changement donne son titre,
     * sa description, sa date, ses urgence et impact, sa catégorie (classification déduite d'une
     * catégorie « Sécurité SI » de Configuration GLPI Auto) et ses éléments, comme la
     * qualification d'un ticket ; un autre élément donne seulement son nom au titre.
     *
     * L'incident est créé dans l'entité de l'élément quand l'utilisateur y a accès, sinon dans son
     * entité active ; le droit de créer un incident dans cette entité est vérifié ici, comme la
     * lecture de l'élément.
     *
     * @return int id de l'incident (0 si la création est refusée ou a échoué)
     */
    public static function declare(CommonDBTM $item): int
    {
        if (!self::canDeclareFor($item)) {
            return 0;
        }

        $entity = (int) ($item->fields['entities_id'] ?? -1);
        if ($entity < 0 || !Session::haveAccessToEntity($entity)) {
            $entity = (int) Session::getActiveEntity();
        }

        $input = ['entities_id' => $entity];
        if ($item instanceof CommonITILObject) {
            $input += [
                'name'    => (string) $item->fields['name'],
                'content' => (string) $item->fields['content'],
                'date'    => (string) $item->fields['date'],
                'urgency' => (int) $item->fields['urgency'],
                'impact'  => (int) $item->fields['impact'],
            ];
            $itilcategoriesId = (int) ($item->fields['itilcategories_id'] ?? 0);
            if ($itilcategoriesId > 0) {
                $input['itilcategories_id'] = $itilcategoriesId;
                $classification = SecurityCategoryLink::classificationFor($itilcategoriesId);
                if ($classification !== null) {
                    $input['category'] = $classification;
                }
            }
        } else {
            $input['name'] = sprintf(__('Security incident on %s', 'grcmanager'), $item->getFriendlyName());
        }

        $incident = new PluginGrcmanagerSecurityIncident();
        if (!$incident->can(-1, CREATE, $input)) {
            return 0;
        }
        $incidentsId = (int) $incident->add($input);
        if ($incidentsId <= 0) {
            return 0;
        }

        $link = new PluginGrcmanagerSecurityIncident_Item();
        $link->add([
            'plugin_grcmanager_securityincidents_id' => $incidentsId,
            'itemtype'                               => $item::class,
            'items_id'                               => (int) $item->getID(),
        ]);
        if (isset(self::ITIL_ITEM_LINKS[$item::class])) {
            [$linkClass, $field] = self::ITIL_ITEM_LINKS[$item::class];
            foreach ((new $linkClass())->find([$field => (int) $item->getID()]) as $row) {
                (new PluginGrcmanagerSecurityIncident_Item())->add([
                    'plugin_grcmanager_securityincidents_id' => $incidentsId,
                    'itemtype'                               => (string) $row['itemtype'],
                    'items_id'                               => (int) $row['items_id'],
                ]);
            }
        }

        return $incidentsId;
    }
}
