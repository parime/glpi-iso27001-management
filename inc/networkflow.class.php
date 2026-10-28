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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\NetworkFlowBase;
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowCsv;
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowPfsense;
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;

/**
 * Issue #144 : matrice des flux réseau autorisés (A.8.20 à A.8.22). Un flux relie une source et
 * une destination (un actif GLPI, ou un réseau, une zone, une adresse décrits en clair), sur un
 * protocole et des ports, avec une justification, un propriétaire et une date de fin éventuelle.
 *
 * Les contrôles automatiques (NetworkFlowRules::checks()) signalent les flux sans justification ou
 * sans propriétaire, expirés ou proches de leur fin, et les services d'administration publiés sur
 * Internet. Ils ne modifient jamais un flux : la revue se fait par le ticket récurrent de la revue
 * périodique « matrice des flux » (#141).
 *
 * Registre global, comme les autres registres du plugin. Un actif lié n'est nommé qu'à qui peut le
 * voir dans GLPI.
 */
class PluginGrcmanagerNetworkFlow extends NetworkFlowBase
{
    // Registre sensible : droit dédié, voir Installer::SENSITIVE_RIGHT.
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_networkflows';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Network flow', 'Network flow matrix', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-arrows-exchange';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            'internal'             => __('Internal flow', 'grcmanager'),
            'internet_publication' => __('Published on the Internet (inbound)', 'grcmanager'),
            'internet_outbound'    => __('Internet access (outbound)', 'grcmanager'),
            'site_tunnel'          => __('Site-to-site tunnel', 'grcmanager'),
            'partner_tunnel'       => __('Tunnel to a partner', 'grcmanager'),
            'admin_access'         => __('Administration access', 'grcmanager'),
            'backup'               => __('Backup', 'grcmanager'),
            'other'                => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getProtocols(): array
    {
        return [
            'tcp'     => 'TCP',
            'udp'     => 'UDP',
            'tcp_udp' => 'TCP + UDP',
            'icmp'    => 'ICMP',
            'ipsec'   => 'IPsec',
            'any'     => __('All', 'grcmanager'),
        ];
    }

    public static function getCheckLabel(string $check): string
    {
        return match ($check) {
            NetworkFlowRules::CHECK_NO_JUSTIFICATION  => __('No justification', 'grcmanager'),
            NetworkFlowRules::CHECK_NO_OWNER          => __('No owner', 'grcmanager'),
            NetworkFlowRules::CHECK_EXPIRED           => _x('masculine', 'Expired', 'grcmanager'),
            NetworkFlowRules::CHECK_EXPIRING          => __('Expires soon', 'grcmanager'),
            NetworkFlowRules::CHECK_ADMIN_EXPOSED     => __('Administration exposed on the Internet', 'grcmanager'),
            NetworkFlowRules::CHECK_ANY_PORT_INTERNET => __('All ports open from the Internet', 'grcmanager'),
            NetworkFlowRules::CHECK_PARTNER_OVERLAP   => __(
                'Partner network conflicting with an internal network',
                'grcmanager'
            ),
            default                                   => $check,
        };
    }

    /**
     * Types d'actifs proposés comme source ou destination : ceux qui portent des ports réseau,
     * plus les applicatifs.
     *
     * @return list<string>
     */
    public static function getEndpointItemtypes(): array
    {
        global $CFG_GLPI;

        $types = array_values(array_unique(array_merge(
            (array) ($CFG_GLPI['networkport_types'] ?? []),
            ['Appliance']
        )));
        return array_values(array_filter($types, static fn ($type): bool => is_string($type) && class_exists($type)));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input): array|false
    {
        if (array_key_exists('flow_type', $input)) {
            $input['flow_type'] = NetworkFlowRules::normalizeType($input['flow_type']);
        }
        if (array_key_exists('protocol', $input)) {
            $input['protocol'] = NetworkFlowRules::normalizeProtocol($input['protocol']);
        }
        if (array_key_exists('ports', $input)) {
            $ports = NetworkFlowRules::normalizePorts($input['ports']);
            if ($ports === null) {
                Session::addMessageAfterRedirect(htmlescape(__(
                    'Invalid ports: enter numbers or ranges separated by commas (e.g. 80, 443, 8000-8100), or leave '
                        . 'empty for all ports.',
                    'grcmanager'
                )), false, ERROR);
                return false;
            }
            $input['ports'] = $ports;
        }
        if (array_key_exists('date_end', $input) && trim((string) $input['date_end']) === '') {
            $input['date_end'] = 'NULL';
        }
        foreach (['source', 'destination'] as $side) {
            if (!$this->normalizeEndpoint($input, $side)) {
                return false;
            }
        }
        $source = trim((string) ($input['source_label'] ?? $this->fields['source_label'] ?? ''));
        $sourceItem = (int) ($input['source_items_id'] ?? $this->fields['source_items_id'] ?? 0);
        $destination = trim((string) ($input['destination_label'] ?? $this->fields['destination_label'] ?? ''));
        $destinationItem = (int) ($input['destination_items_id'] ?? $this->fields['destination_items_id'] ?? 0);
        if (($source === '' && $sourceItem === 0) || ($destination === '' && $destinationItem === 0)) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Enter the source and destination of the flow: a GLPI asset, or a network, zone or address.',
                'grcmanager'
            )), false, ERROR);
            return false;
        }
        if (trim((string) ($input['name'] ?? $this->fields['name'] ?? '')) === '') {
            $input['name'] = ($source !== '' ? $source : '#' . $sourceItem) . ' → '
                . ($destination !== '' ? $destination : '#' . $destinationItem);
        }

        return $input;
    }

    /**
     * Un actif lié doit être d'un type proposé et visible de la personne qui l'enregistre.
     *
     * @param array<string, mixed> $input
     */
    private function normalizeEndpoint(array &$input, string $side): bool
    {
        $typeKey = $side . '_itemtype';
        $idKey = $side . '_items_id';
        if (!array_key_exists($typeKey, $input) && !array_key_exists($idKey, $input)) {
            return true;
        }
        $itemtype = (string) ($input[$typeKey] ?? '');
        $itemsId = (int) ($input[$idKey] ?? 0);
        if ($itemtype === '' || $itemtype === '0' || $itemsId <= 0) {
            $input[$typeKey] = '';
            $input[$idKey] = 0;
            return true;
        }
        $item = in_array($itemtype, self::getEndpointItemtypes(), true) ? getItemForItemtype($itemtype) : false;
        if (!$item instanceof CommonDBTM || !$item->getFromDB($itemsId) || !$item->can($itemsId, READ)) {
            $message = __('Asset not found or not allowed.', 'grcmanager');
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
            return false;
        }
        $input[$idKey] = $itemsId;

        return true;
    }

    public function rawSearchOptions()
    {
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $table = $this->getTable();
        $tab[] = ['id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name', 'grcmanager'),
            'datatype' => 'itemlink', 'itemtype' => self::class];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'flow_type', 'name' => __('Type', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 3, 'table' => $table, 'field' => 'source_label', 'name' => __('Source', 'grcmanager'),
            'datatype' => 'specific', 'additionalfields' => ['source_itemtype', 'source_items_id']];
        $tab[] = ['id' => 4, 'table' => $table, 'field' => 'destination_label',
            'name' => __('Destination', 'grcmanager'), 'datatype' => 'specific',
            'additionalfields' => ['destination_itemtype', 'destination_items_id']];
        $tab[] = ['id' => 5, 'table' => $table, 'field' => 'protocol', 'name' => __('Protocol', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 6, 'table' => $table, 'field' => 'ports', 'name' => __('Ports', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 7, 'table' => $table, 'field' => 'justification',
            'name' => __('Justification', 'grcmanager'), 'datatype' => 'text'];
        $tab[] = ['id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'name' => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => 9, 'table' => $table, 'field' => 'date_end', 'name' => __('End date', 'grcmanager'),
            'datatype' => 'date'];
        $tab[] = ['id' => 10, 'table' => $table, 'field' => 'is_active', 'name' => __('Active', 'grcmanager'),
            'datatype' => 'bool'];
        // Contrôles calculés à l'affichage à partir des champs de la ligne (non triables).
        $tab[] = ['id' => 11, 'table' => $table, 'field' => 'id', 'name' => __('Checks', 'grcmanager'),
            'datatype' => 'specific', 'nosearch' => true, 'nosort' => true, 'massiveaction' => false,
            'additionalfields' => ['flow_type', 'ports', 'justification', 'users_id', 'date_end', 'is_active']];
        $tab[] = ['id' => 12, 'table' => $table, 'field' => 'comment', 'name' => __('Comments', 'grcmanager'),
            'datatype' => 'text'];
        $tab[] = ['id' => 13, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink',
            'itemtype' => self::class];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'flow_type':
                return self::typeBadge((string) ($values[$field] ?? ''));
            case 'protocol':
                return htmlescape(self::getProtocols()[(string) ($values[$field] ?? '')] ?? '');
            case 'ports':
                return htmlescape(self::portsLabel((string) ($values[$field] ?? '')));
            case 'source_label':
            case 'destination_label':
                $side = $field === 'source_label' ? 'source' : 'destination';
                return self::endpointHtml(
                    (string) ($values[$side . '_itemtype'] ?? ''),
                    (int) ($values[$side . '_items_id'] ?? 0),
                    (string) ($values[$field] ?? '')
                );
            case 'id':
                if (array_key_exists('flow_type', $values)) {
                    return self::checksHtml(NetworkFlowRules::checks($values, date('Y-m-d'), self::internalNetworks()));
                }
                break;
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

        return match ($field) {
            'flow_type' => Dropdown::showFromArray($name, self::getTypes(), $options),
            'protocol'  => Dropdown::showFromArray($name, self::getProtocols(), $options),
            default     => parent::getSpecificValueToSelect($field, $name, $values, $options),
        };
    }

    public static function typeBadge(string $type): string
    {
        $map = [
            'internal'             => ['bg-blue-lt', 'ti-building'],
            'internet_publication' => ['bg-orange-lt', 'ti-world-upload'],
            'internet_outbound'    => ['bg-cyan-lt', 'ti-world-download'],
            'site_tunnel'          => ['bg-indigo-lt', 'ti-route'],
            'partner_tunnel'       => ['bg-purple-lt', 'ti-users-group'],
            'admin_access'         => ['bg-red-lt', 'ti-shield-lock'],
            'backup'               => ['bg-teal-lt', 'ti-database-export'],
            'other'                => ['bg-secondary-lt', 'ti-dots'],
        ];
        [$class, $icon] = $map[$type] ?? $map['other'];
        $label = self::getTypes()[$type] ?? $type;

        return self::renderBadges(['kind' => 'type', 'class' => $class, 'icon' => $icon, 'label' => $label]);
    }

    /**
     * Issue #199 : badges rendus par templates/_network_flow_badges.html.twig.
     *
     * @param array<string, mixed> $vars
     */
    private static function renderBadges(array $vars): string
    {
        return trim(TemplateRenderer::getInstance()->render('@grcmanager/_network_flow_badges.html.twig', $vars));
    }

    /**
     * @param list<string> $checks
     */
    public static function checksHtml(array $checks): string
    {
        $items = [];
        foreach ($checks as $check) {
            $items[] = ['label' => self::getCheckLabel($check), 'blocking' => NetworkFlowRules::isBlocking($check)];
        }

        return self::renderBadges(['kind' => 'checks', 'checks' => $items]);
    }

    /**
     * Source ou destination : le libellé saisi, et l'actif lié s'il est visible de la personne.
     */
    public static function endpointHtml(string $itemtype, int $itemsId, string $label): string
    {
        $linked = null;
        if ($itemtype !== '' && $itemsId > 0) {
            $item = getItemForItemtype($itemtype);
            $visible = $item instanceof CommonDBTM && $item->can($itemsId, READ);
            $linked = $visible
                ? ['visible' => true, 'url' => $item::getFormURLWithID($itemsId), 'icon' => $item::getIcon(),
                    'name' => $item->getName()]
                : ['visible' => false];
        }

        return self::renderBadges(['kind' => 'endpoint', 'item' => $linked, 'label' => $label]);
    }

    public function getChecks(): array
    {
        return NetworkFlowRules::checks($this->fields, date('Y-m-d'), self::internalNetworks());
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $sides = [];
        $titles = ['source' => __('Source', 'grcmanager'), 'destination' => __('Destination', 'grcmanager')];
        foreach ($titles as $side => $title) {
            $sides[] = [
                'key'           => $side,
                'title'         => $title,
                // Sélecteur natif de GLPI (type d'actif puis actif).
                'item_selector' => Dropdown::showSelectItemFromItemtypes([
                    'itemtype_name'    => $side . '_itemtype',
                    'items_id_name'    => $side . '_items_id',
                    'itemtypes'        => self::getEndpointItemtypes(),
                    'default_itemtype' => $this->fields[$side . '_itemtype'] ?: 0,
                    'default_items_id' => (int) ($this->fields[$side . '_items_id'] ?? 0),
                    'checkright'       => true,
                    'width'            => '100%',
                    'display'          => false,
                ]),
            ];
        }
        $ports = (string) ($this->fields['ports'] ?? '');

        TemplateRenderer::getInstance()->display('@grcmanager/network_flow_form.html.twig', [
            'item'             => $this,
            'params'           => $options,
            'is_new'           => $this->isNewItem(),
            'types'            => self::getTypes(),
            'protocols'        => self::getProtocols(),
            'default_type'     => NetworkFlowRules::DEFAULT_TYPE,
            'default_protocol' => NetworkFlowRules::DEFAULT_PROTOCOL,
            'is_active'        => $this->isNewItem() ? 1 : (int) $this->fields['is_active'],
            'sides'            => $sides,
            'ports'            => $ports === 'any' ? '' : $ports,
            'checks'           => $this->isNewItem() ? '' : self::checksHtml($this->getChecks()),
        ]);

        return true;
    }

    /**
     * Synthèse affichée en tête de la liste : nombre de flux actifs et de flux à corriger.
     */
    public static function showSummary(): void
    {
        global $DB;

        $active = 0;
        $byCheck = [];
        $today = date('Y-m-d');
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_active' => 1]]) as $row) {
            $active++;
            foreach (NetworkFlowRules::checks($row, $today, self::internalNetworks()) as $check) {
                $byCheck[$check] ??= [
                    'label'    => self::getCheckLabel($check),
                    'blocking' => NetworkFlowRules::isBlocking($check),
                    'count'    => 0,
                ];
                $byCheck[$check]['count']++;
            }
        }
        TemplateRenderer::getInstance()->display('@grcmanager/network_flow_summary.html.twig', [
            'active'       => $active,
            'active_label' => sprintf(_n('%d active flow', '%d active flows', $active, 'grcmanager'), $active),
            'checks'       => array_values($byCheck),
            'can_import'   => Session::haveRight(self::RIGHTNAME, CREATE),
            'import_url'   => self::getImportURL(),
            'report_url'   => self::getReportURL(),
        ]);
    }

    /**
     * Issue #144 : réseaux IP déclarés dans GLPI (IPv4), pour repérer un réseau partenaire qui
     * chevaucherait un réseau interne. Lus une seule fois par requête.
     *
     * @return list<string> « 10.0.0.0/16 »
     */
    public static function internalNetworks(): array
    {
        global $DB;
        static $networks = null;

        if ($networks === null) {
            $networks = [];
            foreach (
                $DB->request([
                    'SELECT' => ['address', 'netmask'],
                    'FROM'   => IPNetwork::getTable(),
                    'WHERE'  => ['version' => 4],
                ]) as $row
            ) {
                $mask = ip2long((string) $row['netmask']);
                if ($mask === false || ip2long((string) $row['address']) === false) {
                    continue;
                }
                $networks[] = $row['address'] . '/' . substr_count(decbin($mask), '1');
            }
        }

        return $networks;
    }

    public static function getReportURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/networkflow.report.php';
    }

    /**
     * Source ou destination en texte, pour le rapport PDF.
     */
    public static function endpointText(string $itemtype, int $itemsId, string $label): string
    {
        $parts = [];
        if ($itemtype !== '' && $itemsId > 0) {
            $item = getItemForItemtype($itemtype);
            $parts[] = $item instanceof CommonDBTM && $item->can($itemsId, READ)
                ? $item::getTypeName(1) . ' ' . $item->getName()
                : __('Asset not visible', 'grcmanager');
        }
        if ($label !== '') {
            $parts[] = $label;
        }

        return implode(' — ', $parts);
    }

    /**
     * Issue #144, phase 2 : contenu du rapport PDF pour l'auditeur (flux actifs par type, écarts,
     * plan d'adressage à partir des réseaux IP de GLPI).
     *
     * @return array<string, mixed>
     */
    public static function reportData(): array
    {
        global $DB;

        $today = date('Y-m-d');
        $types = self::getTypes();
        $protocols = self::getProtocols();
        $groups = [];
        $byCheck = [];
        $active = 0;
        $inactive = 0;
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => ['flow_type', 'name']]) as $row) {
            if (!(int) $row['is_active']) {
                $inactive++;
                continue;
            }
            $active++;
            $checks = NetworkFlowRules::checks($row, $today, self::internalNetworks());
            foreach ($checks as $check) {
                $byCheck[$check] ??= ['label' => self::getCheckLabel($check), 'count' => 0];
                $byCheck[$check]['count']++;
            }
            $type = (string) $row['flow_type'];
            $groups[$type] ??= ['label' => $types[$type] ?? $type, 'flows' => []];
            $groups[$type]['flows'][] = [
                'name'          => (string) $row['name'],
                'source'        => self::endpointText(
                    (string) $row['source_itemtype'],
                    (int) $row['source_items_id'],
                    (string) $row['source_label']
                ),
                'destination'   => self::endpointText(
                    (string) $row['destination_itemtype'],
                    (int) $row['destination_items_id'],
                    (string) $row['destination_label']
                ),
                'service'       => ($protocols[$row['protocol']] ?? $row['protocol']) . ' '
                    . self::portsLabel((string) $row['ports']),
                'justification' => (string) $row['justification'],
                'owner'         => $row['users_id'] ? getUserName((int) $row['users_id']) : '',
                'date_end'      => $row['date_end'] ? Html::convDate($row['date_end']) : '',
                'checks'        => array_map([self::class, 'getCheckLabel'], $checks),
            ];
        }

        $networks = [];
        foreach (
            $DB->request([
                'SELECT' => ['completename', 'address', 'netmask', 'gateway', 'entities_id'],
                'FROM'   => IPNetwork::getTable(),
                'WHERE'  => getEntitiesRestrictCriteria(IPNetwork::getTable(), '', '', true),
                'ORDER'  => 'completename',
            ]) as $row
        ) {
            $networks[] = [
                'name'    => (string) $row['completename'],
                'address' => $row['address'] . '/' . $row['netmask'],
                'gateway' => (string) $row['gateway'],
                'entity'  => Dropdown::getDropdownName(Entity::getTable(), (int) $row['entities_id']),
            ];
        }

        return [
            'active'   => $active,
            'inactive' => $inactive,
            'checks'   => array_values($byCheck),
            'groups'   => array_values($groups),
            'networks' => $networks,
        ];
    }

    public static function portsLabel(string $ports): string
    {
        return $ports === 'any' || $ports === '' ? __('All', 'grcmanager') : str_replace(',', ', ', $ports);
    }

    public static function getImportURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/networkflow.import.php';
    }

    /**
     * Import d'une matrice CSV (NetworkFlowCsv). En simulation, rien n'est écrit. Une ligne
     * identique à un flux existant (mêmes source, destination, protocole et ports) est ignorée :
     * réimporter le même fichier ne crée pas de doublon.
     *
     * @return array{created: int, skipped: int, errors: list<array{line: int, message: string}>,
     *     rows: list<array<string, mixed>>}
     */
    public static function importCsv(string $content, bool $simulate): array
    {
        // Issue #144, phase 2 : une sauvegarde pfSense (config.xml) est reconnue à son contenu.
        $parsed = str_starts_with(ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content), '<')
            ? NetworkFlowPfsense::parse($content)
            : NetworkFlowCsv::parse($content);
        $result = ['created' => 0, 'skipped' => 0, 'errors' => [], 'rows' => []];
        foreach ($parsed['errors'] as $error) {
            $result['errors'][] = ['line' => $error['line'], 'message' => self::csvErrorLabel($error['message'])];
        }
        foreach ($parsed['rows'] as $row) {
            $usersId = 0;
            if ($row['owner'] !== '') {
                $user = new User();
                if ($user->getFromDBbyName($row['owner'])) {
                    $usersId = $user->getID();
                } else {
                    $result['errors'][] = ['line' => $row['line'], 'message' => sprintf(
                        __('Owner "%s" not found: flow imported without an owner.', 'grcmanager'),
                        $row['owner']
                    )];
                }
            }
            $exists = countElementsInTable(self::getTable(), [
                'source_label'      => $row['source_label'],
                'destination_label' => $row['destination_label'],
                'protocol'          => $row['protocol'],
                'ports'             => $row['ports'],
            ]) > 0;
            $row['status'] = $exists ? 'skipped' : 'created';
            $result['rows'][] = $row;
            if ($exists) {
                $result['skipped']++;
                continue;
            }
            $result['created']++;
            if (!$simulate) {
                (new self())->add([
                    'name'              => $row['name'],
                    'flow_type'         => $row['flow_type'],
                    'source_label'      => $row['source_label'],
                    'destination_label' => $row['destination_label'],
                    'protocol'          => $row['protocol'],
                    'ports'             => $row['ports'],
                    'justification'     => $row['justification'],
                    'users_id'          => $usersId,
                    'date_end'          => $row['date_end'] ?? '',
                    'is_active'         => 1,
                ]);
            }
        }

        return $result;
    }

    private static function csvErrorLabel(string $code): string
    {
        return match ($code) {
            'missing_columns'  => __('Header without "source" and "destination" columns.', 'grcmanager'),
            'empty'            => __('Empty file.', 'grcmanager'),
            'missing_endpoint' => __('Missing source or destination: line skipped.', 'grcmanager'),
            'invalid_ports'    => __('Invalid ports: line skipped.', 'grcmanager'),
            'invalid_date'     => __('Invalid end date: line skipped.', 'grcmanager'),
            'not_pfsense'      => __('XML file that is not a pfSense backup (config.xml).', 'grcmanager'),
            'no_rule'          => __('No filter rule in the pfSense backup.', 'grcmanager'),
            default            => $code,
        };
    }
}
