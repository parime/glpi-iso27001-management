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

use GlpiPlugin\Grcmanager\Compatibility\Base\NetworkFlowBase;
use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowCsv;
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
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_networkflows';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Flux réseau', 'Matrice des flux', $nb, 'grcmanager');
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
            'internal'             => __('Flux interne', 'grcmanager'),
            'internet_publication' => __('Publication sur Internet (entrant)', 'grcmanager'),
            'internet_outbound'    => __('Accès à Internet (sortant)', 'grcmanager'),
            'site_tunnel'          => __('Tunnel entre sites', 'grcmanager'),
            'partner_tunnel'       => __('Tunnel vers un partenaire', 'grcmanager'),
            'admin_access'         => __('Accès d\'administration', 'grcmanager'),
            'backup'               => __('Sauvegarde', 'grcmanager'),
            'other'                => __('Autre', 'grcmanager'),
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
            'any'     => __('Tous', 'grcmanager'),
        ];
    }

    public static function getCheckLabel(string $check): string
    {
        return match ($check) {
            NetworkFlowRules::CHECK_NO_JUSTIFICATION  => __('Sans justification', 'grcmanager'),
            NetworkFlowRules::CHECK_NO_OWNER          => __('Sans propriétaire', 'grcmanager'),
            NetworkFlowRules::CHECK_EXPIRED           => __('Expiré', 'grcmanager'),
            NetworkFlowRules::CHECK_EXPIRING          => __('Expire bientôt', 'grcmanager'),
            NetworkFlowRules::CHECK_ADMIN_EXPOSED     => __('Administration exposée sur Internet', 'grcmanager'),
            NetworkFlowRules::CHECK_ANY_PORT_INTERNET => __('Tous les ports ouverts depuis Internet', 'grcmanager'),
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
                    'Ports invalides : saisissez des numéros ou des plages séparés par des virgules (ex. 80, 443, '
                        . '8000-8100), ou laissez vide pour tous les ports.',
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
                'Indiquez la source et la destination du flux : un actif GLPI, ou un réseau, une zone ou une adresse.',
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
            $message = __('Actif introuvable ou non autorisé.', 'grcmanager');
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
        $tab[] = ['id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Nom', 'grcmanager'),
            'datatype' => 'itemlink', 'itemtype' => self::class];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'flow_type', 'name' => __('Type', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 3, 'table' => $table, 'field' => 'source_label', 'name' => __('Source', 'grcmanager'),
            'datatype' => 'specific', 'additionalfields' => ['source_itemtype', 'source_items_id']];
        $tab[] = ['id' => 4, 'table' => $table, 'field' => 'destination_label',
            'name' => __('Destination', 'grcmanager'), 'datatype' => 'specific',
            'additionalfields' => ['destination_itemtype', 'destination_items_id']];
        $tab[] = ['id' => 5, 'table' => $table, 'field' => 'protocol', 'name' => __('Protocole', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 6, 'table' => $table, 'field' => 'ports', 'name' => __('Ports', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 7, 'table' => $table, 'field' => 'justification',
            'name' => __('Justification', 'grcmanager'), 'datatype' => 'text'];
        $tab[] = ['id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'name' => __('Propriétaire', 'grcmanager'),
            'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => 9, 'table' => $table, 'field' => 'date_end', 'name' => __('Date de fin', 'grcmanager'),
            'datatype' => 'date'];
        $tab[] = ['id' => 10, 'table' => $table, 'field' => 'is_active', 'name' => __('Actif', 'grcmanager'),
            'datatype' => 'bool'];
        // Contrôles calculés à l'affichage à partir des champs de la ligne (non triables).
        $tab[] = ['id' => 11, 'table' => $table, 'field' => 'id', 'name' => __('Contrôles', 'grcmanager'),
            'datatype' => 'specific', 'nosearch' => true, 'nosort' => true, 'massiveaction' => false,
            'additionalfields' => ['flow_type', 'ports', 'justification', 'users_id', 'date_end', 'is_active']];
        $tab[] = ['id' => 12, 'table' => $table, 'field' => 'comment', 'name' => __('Commentaires', 'grcmanager'),
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
                    return self::checksHtml(NetworkFlowRules::checks($values, date('Y-m-d')));
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

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    /**
     * @param list<string> $checks
     */
    public static function checksHtml(array $checks): string
    {
        if ($checks === []) {
            return '<span class="badge bg-green-lt"><i class="ti ti-circle-check me-1"></i>'
                . htmlescape(__('Conforme', 'grcmanager')) . '</span>';
        }
        $html = '';
        foreach ($checks as $check) {
            $class = NetworkFlowRules::isBlocking($check) ? 'bg-red-lt' : 'bg-yellow-lt';
            $html .= '<span class="badge ' . $class . ' me-1"><i class="ti ti-alert-triangle me-1"></i>'
                . htmlescape(self::getCheckLabel($check)) . '</span>';
        }

        return $html;
    }

    /**
     * Source ou destination : le libellé saisi, et l'actif lié s'il est visible de la personne.
     */
    public static function endpointHtml(string $itemtype, int $itemsId, string $label): string
    {
        $parts = [];
        if ($itemtype !== '' && $itemsId > 0) {
            $item = getItemForItemtype($itemtype);
            if ($item instanceof CommonDBTM && $item->getFromDB($itemsId) && $item->canViewItem()) {
                $parts[] = '<a href="' . htmlescape($item::getFormURLWithID($itemsId)) . '"><i class="'
                    . htmlescape($item::getIcon()) . ' me-1"></i>' . htmlescape($item->getName()) . '</a>';
            } else {
                $parts[] = '<span class="text-muted">' . htmlescape(__('Actif non visible', 'grcmanager')) . '</span>';
            }
        }
        if ($label !== '') {
            $parts[] = htmlescape($label);
        }

        return implode(' — ', $parts);
    }

    public function getChecks(): array
    {
        return NetworkFlowRules::checks($this->fields, date('Y-m-d'));
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo '<tr class="tab_bg_1"><td>' . htmlescape(__('Nom', 'grcmanager')) . '</td><td colspan="3">';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 80]);
        echo self::hint(__('Laissé vide, il est construit à partir de la source et de la destination.', 'grcmanager'));
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . htmlescape(__('Type', 'grcmanager')) . '</td><td>';
        Dropdown::showFromArray('flow_type', self::getTypes(), [
            'value' => $this->fields['flow_type'] ?? NetworkFlowRules::DEFAULT_TYPE,
        ]);
        echo '</td><td>' . htmlescape(__('Actif', 'grcmanager')) . '</td><td>';
        Dropdown::showYesNo('is_active', $this->isNewItem() ? 1 : (int) $this->fields['is_active']);
        echo '</td></tr>';

        $sides = ['source' => __('Source', 'grcmanager'), 'destination' => __('Destination', 'grcmanager')];
        foreach ($sides as $side => $title) {
            echo '<tr class="tab_bg_1"><td>' . htmlescape($title) . '</td><td>';
            Dropdown::showSelectItemFromItemtypes([
                'itemtype_name'    => $side . '_itemtype',
                'items_id_name'    => $side . '_items_id',
                'itemtypes'        => self::getEndpointItemtypes(),
                'default_itemtype' => $this->fields[$side . '_itemtype'] ?: 0,
                'default_items_id' => (int) ($this->fields[$side . '_items_id'] ?? 0),
                'checkright'       => true,
                'width'            => '100%',
            ]);
            echo self::hint(__('Actif GLPI (facultatif).', 'grcmanager'));
            echo '</td><td>' . htmlescape(__('Réseau, zone ou adresse', 'grcmanager')) . '</td><td>';
            echo Html::input($side . '_label', ['value' => $this->fields[$side . '_label'] ?? '', 'size' => 40]);
            $example = __('Ex. « Internet », « VLAN serveurs », « 10.0.0.0/24 », « Partenaire X ».', 'grcmanager');
            echo self::hint($example);
            echo '</td></tr>';
        }

        echo '<tr class="tab_bg_1"><td>' . htmlescape(__('Protocole', 'grcmanager')) . '</td><td>';
        Dropdown::showFromArray('protocol', self::getProtocols(), [
            'value' => $this->fields['protocol'] ?? NetworkFlowRules::DEFAULT_PROTOCOL,
        ]);
        echo '</td><td>' . htmlescape(__('Ports', 'grcmanager')) . '</td><td>';
        $ports = (string) ($this->fields['ports'] ?? '');
        echo Html::input('ports', ['value' => $ports === 'any' ? '' : $ports, 'size' => 30,
            'placeholder' => '80, 443, 8000-8100']);
        echo self::hint(__('Vide : tous les ports.', 'grcmanager'));
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . htmlescape(__('Justification', 'grcmanager')) . '</td><td colspan="3">';
        echo '<textarea name="justification" class="form-control" rows="3">'
            . htmlescape($this->fields['justification'] ?? '') . '</textarea>';
        echo self::hint(__(
            'Pourquoi ce flux est nécessaire : application, besoin métier, demande ou ticket d\'origine.',
            'grcmanager'
        ));
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . htmlescape(__('Propriétaire', 'grcmanager')) . '</td><td>';
        User::dropdown(['name' => 'users_id', 'value' => (int) ($this->fields['users_id'] ?? 0), 'right' => 'all']);
        echo '</td><td>' . htmlescape(__('Date de fin', 'grcmanager')) . '</td><td>';
        Html::showDateField('date_end', ['value' => $this->fields['date_end'] ?? '']);
        echo self::hint(__('Pour un flux temporaire (migration, prestataire).', 'grcmanager'));
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . htmlescape(__('Commentaires', 'grcmanager')) . '</td><td colspan="3">';
        echo '<textarea name="comment" class="form-control" rows="2">' . htmlescape($this->fields['comment'] ?? '')
            . '</textarea></td></tr>';

        if (!$this->isNewItem()) {
            echo '<tr class="tab_bg_1"><td>' . htmlescape(__('Contrôles', 'grcmanager')) . '</td><td colspan="3">'
                . self::checksHtml($this->getChecks()) . '</td></tr>';
        }

        $this->showFormButtons($options);

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
            foreach (NetworkFlowRules::checks($row, $today) as $check) {
                $byCheck[$check] = ($byCheck[$check] ?? 0) + 1;
            }
        }
        echo '<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-2">';
        echo '<span class="me-2"><i class="ti ti-arrows-exchange me-1"></i>'
            . htmlescape(sprintf(_n('%d flux actif', '%d flux actifs', $active, 'grcmanager'), $active)) . '</span>';
        if ($byCheck === [] && $active > 0) {
            echo self::checksHtml([]);
        }
        foreach ($byCheck as $check => $count) {
            $class = NetworkFlowRules::isBlocking($check) ? 'bg-red-lt' : 'bg-yellow-lt';
            echo '<span class="badge ' . $class . '">' . htmlescape(self::getCheckLabel($check))
                . ' : ' . $count . '</span>';
        }
        if (Session::haveRight(self::RIGHTNAME, CREATE)) {
            echo '<a class="btn btn-sm btn-outline-primary ms-auto" href="' . htmlescape(self::getImportURL()) . '">'
                . '<i class="ti ti-file-import me-1"></i>'
                . htmlescape(__('Importer un fichier CSV', 'grcmanager')) . '</a>';
        }
        echo '</div></div>';
    }

    public static function portsLabel(string $ports): string
    {
        return $ports === 'any' || $ports === '' ? __('Tous', 'grcmanager') : str_replace(',', ', ', $ports);
    }

    private static function hint(string $text): string
    {
        return '<small class="form-hint">' . htmlescape($text) . '</small>';
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
        $parsed = NetworkFlowCsv::parse($content);
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
                        __('Propriétaire « %s » introuvable : flux importé sans propriétaire.', 'grcmanager'),
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
            'missing_columns'  => __('En-tête sans colonnes « source » et « destination ».', 'grcmanager'),
            'empty'            => __('Fichier vide.', 'grcmanager'),
            'missing_endpoint' => __('Source ou destination manquante : ligne ignorée.', 'grcmanager'),
            'invalid_ports'    => __('Ports invalides : ligne ignorée.', 'grcmanager'),
            'invalid_date'     => __('Date de fin invalide : ligne ignorée.', 'grcmanager'),
            default            => $code,
        };
    }
}
