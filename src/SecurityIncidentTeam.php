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

use CommonGLPI;
use CommonITILActor;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentTeamBase;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #371 : équipe d'intervention d'un incident. Un intervenant agit sur l'incident, un
 * observateur le lit seulement. Seuls les pilotes (droit « Voir tout », rédacteur de l'incident,
 * technicien ou groupe assigné) composent l'équipe. Retirer un membre lui coupe l'accès.
 */
class SecurityIncidentTeam extends SecurityIncidentTeamBase
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public const RESPONDER = 'responder';
    public const OBSERVER = 'observer';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_securityincidentteams';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Response team member', 'Response team', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-users-group';
    }

    /**
     * @return array<string, string>
     */
    public static function getRoles(): array
    {
        return [
            self::RESPONDER => __('Responder (acts on the incident)', 'grcmanager'),
            self::OBSERVER  => __('Observer (read only)', 'grcmanager'),
        ];
    }

    /**
     * Rôle de l'utilisateur dans l'équipe de l'incident, ou null s'il n'en fait pas partie.
     */
    public static function roleOf(int $incidentId, int $usersId): ?string
    {
        global $DB;

        if ($incidentId <= 0 || $usersId <= 0) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['role'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_grcmanager_securityincidents_id' => $incidentId, 'users_id' => $usersId],
            'LIMIT'  => 1,
        ])->current();

        return $row === null ? null : (string) $row['role'];
    }

    /**
     * Pilote de l'incident : peut composer l'équipe, mettre à la corbeille ou supprimer.
     */
    public static function isPilot(PluginGrcmanagerSecurityIncident $incident): bool
    {
        $me = (int) Session::getLoginUserID();
        $right = PluginGrcmanagerSecurityIncident::$rightname;
        if (Session::haveRight($right, PluginGrcmanagerSecurityIncident::READALL)) {
            return true;
        }

        return (int) ($incident->fields['users_id_recipient'] ?? 0) === $me
            || $incident->isUser(CommonITILActor::ASSIGN, $me)
            || (isset($_SESSION['glpigroups'])
                && $incident->haveAGroup(CommonITILActor::ASSIGN, $_SESSION['glpigroups']));
    }

    /**
     * Hooks::ADD_DEFAULT_WHERE : sans le droit « Voir tout », la liste et la recherche des
     * incidents ne montrent que ceux où l'utilisateur est acteur, rédacteur ou membre de l'équipe
     * (GLPI ne le fait lui-même que pour ses tickets, changements et problèmes).
     *
     * @param array<int|string, mixed> $criteria
     * @return array<int|string, mixed>
     */
    public static function restrictSearch(array $criteria): array
    {
        $right = PluginGrcmanagerSecurityIncident::$rightname;
        if (Session::haveRight($right, PluginGrcmanagerSecurityIncident::READALL)) {
            return $criteria;
        }
        $table = PluginGrcmanagerSecurityIncident::getTable();
        if (!Session::haveRight($right, PluginGrcmanagerSecurityIncident::READMY)) {
            $criteria[] = new QueryExpression('false');

            return $criteria;
        }
        $me = (int) Session::getLoginUserID();
        $fk = 'plugin_grcmanager_securityincidents_id';
        $or = [
            "$table.users_id_recipient" => $me,
            ["$table.id" => new QuerySubQuery([
                'SELECT' => $fk,
                'FROM'   => \PluginGrcmanagerSecurityIncident_User::getTable(),
                'WHERE'  => ['users_id' => $me],
            ])],
            ["$table.id" => new QuerySubQuery([
                'SELECT' => $fk,
                'FROM'   => self::getTable(),
                'WHERE'  => ['users_id' => $me],
            ])],
        ];
        if (!empty($_SESSION['glpigroups'])) {
            $or[] = ["$table.id" => new QuerySubQuery([
                'SELECT' => $fk,
                'FROM'   => \PluginGrcmanagerSecurityIncident_Group::getTable(),
                'WHERE'  => ['groups_id' => $_SESSION['glpigroups']],
            ])];
        }
        $criteria[] = ['OR' => $or];

        return $criteria;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident) || !PluginGrcmanagerSecurityIncident::canView()) {
            return '';
        }
        $count = $item->isNewID($item->getID()) ? 0 : countElementsInTable(self::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $item->getID(),
        ]);

        return self::createTabEntry(__('Response team', 'grcmanager'), $count, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
            return false;
        }
        $members = array_values((new self())->find(
            ['plugin_grcmanager_securityincidents_id' => $item->getID()],
            ['role ASC', 'id ASC']
        ));
        foreach ($members as &$member) {
            $member['user_name'] = getUserName((int) $member['users_id']);
            $member['added_by'] = getUserName((int) $member['users_id_added']);
        }

        TemplateRenderer::getInstance()->display('@grcmanager/tabs/incident_team.html.twig', [
            'item'       => $item,
            'members'    => $members,
            'roles'      => self::getRoles(),
            'can_manage' => $item->canUpdateItem() && self::isPilot($item),
            'form_url'   => self::getFormURL(),
            'entity'     => (int) $item->fields['entities_id'],
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $incidentId = (int) ($input['plugin_grcmanager_securityincidents_id'] ?? 0);
        if (!$incident->getFromDB($incidentId) || !$incident->canUpdateItem() || !self::isPilot($incident)) {
            Session::addMessageAfterRedirect(
                __('Only the incident pilots can compose the response team.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }
        $input['users_id'] = (int) ($input['users_id'] ?? 0);
        if ($input['users_id'] <= 0 || self::roleOf($incidentId, $input['users_id']) !== null) {
            return false;
        }
        $input['role'] = ($input['role'] ?? '') === self::OBSERVER ? self::OBSERVER : self::RESPONDER;
        $input['users_id_added'] = (int) Session::getLoginUserID();

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // Seul le rôle change.
        $input = array_intersect_key($input, ['id' => true, 'role' => true]);
        if (isset($input['role'])) {
            $input['role'] = $input['role'] === self::OBSERVER ? self::OBSERVER : self::RESPONDER;
        }

        return $input;
    }

    public function canCreateItem(): bool
    {
        return true;
    }

    public function canUpdateItem(): bool
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incident->getFromDB((int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0))
            && $incident->canUpdateItem()
            && self::isPilot($incident);
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }

    public function canViewItem(): bool
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incident->getFromDB((int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0))
            && $incident->canViewItem();
    }
}
