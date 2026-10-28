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
use CommonITILActor;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
use Html;
use Item_Ticket;
use ITILFollowup;
use MassiveAction;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use Ticket;
use Ticket_User;

/**
 * Issue #128 : qualifier un ticket GLPI en incident de sécurité, et lien dans les deux sens.
 *
 * Onglet « Incident de sécurité » sur le ticket (incidents liés, bouton « Qualifier en incident de
 * sécurité ») et onglet « Tickets liés » sur l'incident. La qualification crée l'incident à partir
 * du ticket (titre, description, date, entité, urgence/impact, demandeur, éléments liés) et ajoute
 * un suivi privé au ticket ; le ticket lui-même n'est pas modifié (ni statut ni attribution).
 *
 * Simple fournisseur d'onglets (CommonGLPI) plutôt qu'un CommonDBRelation : les propriétés héritées
 * de ce dernier sont typées en GLPI 12 et non en GLPI 11 (voir src/Compatibility/Base), et ce lien
 * n'a besoin ni de formulaire ni d'historique propres — la table est lue et écrite directement.
 */
class SecurityIncidentTicket extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_securityincidents_tickets';

    public static function getTypeName($nb = 0)
    {
        return PluginGrcmanagerSecurityIncident::getTypeName($nb);
    }

    /**
     * @return list<int>
     */
    public static function getIncidentIdsForTicket(int $ticketsId): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request([
            'SELECT' => ['plugin_grcmanager_securityincidents_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['tickets_id' => $ticketsId],
        ]);
        foreach ($rows as $row) {
            $ids[] = (int) $row['plugin_grcmanager_securityincidents_id'];
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    public static function getTicketIdsForIncident(int $incidentsId): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['plugin_grcmanager_securityincidents_id' => $incidentsId],
        ]);
        foreach ($rows as $row) {
            $ids[] = (int) $row['tickets_id'];
        }

        return $ids;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!PluginGrcmanagerSecurityIncident::canView()) {
            return '';
        }
        if ($item instanceof Ticket) {
            $count = count(self::getIncidentIdsForTicket((int) $item->getID()));

            $icon = PluginGrcmanagerSecurityIncident::getIcon();

            return self::createTabEntry(self::getTypeName(1), $count, null, $icon);
        }
        if ($item instanceof PluginGrcmanagerSecurityIncident) {
            $count = count(self::getTicketIdsForIncident((int) $item->getID()));

            return self::createTabEntry(__('Linked tickets', 'grcmanager'), $count, null, Ticket::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : même contrôle
        // ici (trouvé par le contrôle commun des onglets, parime/glpi-plugin-security).
        if (!PluginGrcmanagerSecurityIncident::canView()) {
            return false;
        }

        if ($item instanceof Ticket) {
            $rows = [];
            foreach (self::getIncidentIdsForTicket((int) $item->getID()) as $id) {
                $incident = new PluginGrcmanagerSecurityIncident();
                // Un incident n'est listé qu'à qui peut le lire (entité, acteur pour « mes incidents »).
                if ($incident->can($id, READ)) {
                    $rows[] = [
                        'id'       => $id,
                        'name'     => (string) $incident->fields['name'],
                        'url'      => PluginGrcmanagerSecurityIncident::getFormURLWithID($id),
                        'status'   => PluginGrcmanagerSecurityIncident::getStatus((int) $incident->fields['status']),
                        'severity' => PluginGrcmanagerSecurityIncident::getSeverities()[
                            (string) $incident->fields['severity']
                        ] ?? '',
                    ];
                }
            }
            TemplateRenderer::getInstance()->display('@grcmanager/tabs/ticket_security_incidents.html.twig', [
                'rows'       => $rows,
                'can_create' => PluginGrcmanagerSecurityIncident::canCreate(),
                'action'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/securityincidentticket.form.php',
                'tickets_id' => (int) $item->getID(),
            ]);

            return true;
        }

        if ($item instanceof PluginGrcmanagerSecurityIncident) {
            $rows = [];
            foreach (self::getTicketIdsForIncident((int) $item->getID()) as $id) {
                $ticket = new Ticket();
                // Un ticket lié n'est listé qu'à qui peut le lire.
                if ($ticket->can($id, READ)) {
                    $rows[] = [
                        'id'     => $id,
                        'name'   => (string) $ticket->fields['name'],
                        'url'    => Ticket::getFormURLWithID($id),
                        'status' => Ticket::getStatus((int) $ticket->fields['status']),
                    ];
                }
            }
            TemplateRenderer::getInstance()->display(
                '@grcmanager/tabs/security_incident_tickets.html.twig',
                ['rows' => $rows]
            );

            return true;
        }

        return false;
    }

    /**
     * Issue #165 : action groupée « Qualifier en incident de sécurité » sur la liste des tickets
     * (plugin_grcmanager_MassiveActions()). Aucun réglage : un bouton de confirmation.
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        echo Html::submit(__('Qualify as a security incident', 'grcmanager'), ['name' => 'massiveaction']);

        return true;
    }

    /**
     * Même contrôle que l'onglet du ticket : droit de créer un incident, et lecture de chaque ticket.
     *
     * @param array<int, mixed> $ids
     */
    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids)
    {
        foreach ($ids as $id) {
            $ticket = new Ticket();
            if (
                $ma->getAction() !== 'qualify'
                || !PluginGrcmanagerSecurityIncident::canCreate()
                || !$ticket->getFromDB((int) $id)
                || !$ticket->can((int) $id, READ)
            ) {
                $ma->itemDone(Ticket::class, $id, MassiveAction::ACTION_NORIGHT);
                continue;
            }
            $ma->itemDone(
                Ticket::class,
                $id,
                self::qualify($ticket) > 0 ? MassiveAction::ACTION_OK : MassiveAction::ACTION_KO
            );
        }
    }

    /**
     * Crée l'incident de sécurité à partir du ticket et les relie. Un ticket déjà qualifié renvoie
     * son incident existant (pas de doublon).
     *
     * @return int id de l'incident (0 si la création a échoué)
     */
    public static function qualify(Ticket $ticket): int
    {
        global $DB;

        $existing = self::getIncidentIdsForTicket((int) $ticket->getID());
        if ($existing !== []) {
            return $existing[0];
        }

        $input = [
            'name'        => (string) $ticket->fields['name'],
            'content'     => (string) $ticket->fields['content'],
            'date'        => (string) $ticket->fields['date'],
            'entities_id' => (int) $ticket->fields['entities_id'],
            'urgency'     => (int) $ticket->fields['urgency'],
            'impact'      => (int) $ticket->fields['impact'],
        ];
        $requesters = (new Ticket_User())->find([
            'tickets_id' => (int) $ticket->getID(),
            'type'       => CommonITILActor::REQUESTER,
        ]);
        foreach ($requesters as $actor) {
            if ((int) $actor['users_id'] > 0) {
                $input['_users_id_requester'] = (int) $actor['users_id'];
                break;
            }
        }

        $incidentsId = (int) (new PluginGrcmanagerSecurityIncident())->add($input);
        if ($incidentsId <= 0) {
            return 0;
        }

        $DB->insert(self::TABLE, [
            'plugin_grcmanager_securityincidents_id' => $incidentsId,
            'tickets_id'                             => (int) $ticket->getID(),
            'date_creation'                          => date('Y-m-d H:i:s'),
        ]);
        foreach ((new Item_Ticket())->find(['tickets_id' => (int) $ticket->getID()]) as $link) {
            (new PluginGrcmanagerSecurityIncident_Item())->add([
                'plugin_grcmanager_securityincidents_id' => $incidentsId,
                'itemtype'                               => (string) $link['itemtype'],
                'items_id'                               => (int) $link['items_id'],
            ]);
        }

        (new ITILFollowup())->add([
            'itemtype'   => Ticket::class,
            'items_id'   => (int) $ticket->getID(),
            'is_private' => 1,
            'content'    => Fragment::ticketContent(
                [sprintf(
                    __(
                        'This ticket was qualified as security incident no. %d: its security handling (severity, '
                            . 'impact, NIS2 / GDPR notification deadlines) continues on the incident.',
                        'grcmanager'
                    ),
                    $incidentsId
                )],
                PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentsId),
                sprintf(__('Open security incident no. %d', 'grcmanager'), $incidentsId)
            ),
        ]);

        return $incidentsId;
    }
}
