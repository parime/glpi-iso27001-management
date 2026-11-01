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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentIocBase;
use GlpiPlugin\Grcmanager\Services\Incident\IocParser;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #369 : indicateur de compromission (IP, domaine, URL, hash, e-mail, fichier) relevé sur un
 * incident de sécurité. Mêmes droits que l'incident, comme les références CVE : ajouter, qualifier
 * ou retirer un IOC exige de pouvoir modifier l'incident.
 */
class SecurityIncidentIoc extends SecurityIncidentIocBase
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public static function getTypeName($nb = 0)
    {
        return _n('Indicator of compromise', 'Indicators of compromise', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-fingerprint';
    }

    /**
     * @return array<string, string>
     */
    public static function getVerdicts(): array
    {
        return [
            ''           => __('To be qualified', 'grcmanager'),
            'malicious'  => __('Malicious', 'grcmanager'),
            'suspicious' => __('Suspicious', 'grcmanager'),
            'benign'     => __('False positive', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function getTypes(): array
    {
        return [
            'ipv4'     => ['label' => __('IPv4 address', 'grcmanager'), 'icon' => 'ti ti-network'],
            'ipv6'     => ['label' => __('IPv6 address', 'grcmanager'), 'icon' => 'ti ti-network'],
            'domain'   => ['label' => __('Domain', 'grcmanager'), 'icon' => 'ti ti-world'],
            'url'      => ['label' => __('URL', 'grcmanager'), 'icon' => 'ti ti-link'],
            'email'    => ['label' => __('Email address', 'grcmanager'), 'icon' => 'ti ti-mail'],
            'md5'      => ['label' => 'MD5', 'icon' => 'ti ti-hash'],
            'sha1'     => ['label' => 'SHA-1', 'icon' => 'ti ti-hash'],
            'sha256'   => ['label' => 'SHA-256', 'icon' => 'ti ti-hash'],
            'filename' => ['label' => __('File name', 'grcmanager'), 'icon' => 'ti ti-file-alert'],
        ];
    }

    /**
     * Ajoute les IOC d'une saisie multiligne. Un IOC déjà présent sur l'incident n'est pas
     * dupliqué : sa date de dernière observation est mise à jour.
     *
     * @return array{added: int, updated: int, rejected: list<string>}
     */
    public static function addFromText(int $incidentsId, string $raw, string $verdict = ''): array
    {
        $parsed = IocParser::parse($raw);
        $result = ['added' => 0, 'updated' => 0, 'rejected' => $parsed['rejected']];
        if (!self::canEditIncident($incidentsId)) {
            return $result;
        }
        $verdict = array_key_exists($verdict, self::getVerdicts()) ? $verdict : '';
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach ($parsed['iocs'] as $ioc) {
            $existing = new self();
            if ($existing->getFromDBByCrit([
                'plugin_grcmanager_securityincidents_id' => $incidentsId,
                'ioc_hash' => self::hashOf($ioc['type'], $ioc['value']),
            ])) {
                $existing->update(['id' => $existing->getID(), 'last_seen' => $now]);
                $result['updated']++;
                continue;
            }
            $row = new self();
            if ($row->add([
                'plugin_grcmanager_securityincidents_id' => $incidentsId,
                'type'       => $ioc['type'],
                'value'      => $ioc['value'],
                'verdict'    => $verdict,
                'first_seen' => $now,
                'last_seen'  => $now,
            ])) {
                $result['added']++;
            }
        }

        return $result;
    }

    public static function hashOf(string $type, string $value): string
    {
        return hash('sha256', $type . "\0" . $value);
    }

    /**
     * Recherche transverse : pour chaque IOC de l'incident, les autres incidents où il apparaît,
     * limités à ceux que l'utilisateur peut voir.
     *
     * @param list<string> $hashes
     * @return array<string, list<array{id: int, reference: string, name: string}>>
     */
    public static function findInOtherIncidents(int $incidentsId, array $hashes): array
    {
        global $DB;

        if ($hashes === []) {
            return [];
        }
        $table = self::getTable();
        $incidents = PluginGrcmanagerSecurityIncident::getTable();
        $found = [];
        $visible = [];
        foreach ($DB->request([
            'SELECT'     => ["$table.ioc_hash", "$incidents.id", "$incidents.reference", "$incidents.name"],
            'FROM'       => $table,
            'INNER JOIN' => [
                $incidents => ['FKEY' => [$table => 'plugin_grcmanager_securityincidents_id', $incidents => 'id']],
            ],
            'WHERE'      => [
                "$table.ioc_hash" => $hashes,
                "$incidents.is_deleted" => 0,
                'NOT' => ["$incidents.id" => $incidentsId],
            ],
            'ORDER'      => ["$incidents.date DESC"],
        ]) as $row) {
            $id = (int) $row['id'];
            if (!isset($visible[$id])) {
                $incident = new PluginGrcmanagerSecurityIncident();
                $visible[$id] = $incident->getFromDB($id) && $incident->canViewItem();
            }
            if ($visible[$id]) {
                $found[(string) $row['ioc_hash']][] = [
                    'id' => $id,
                    'reference' => (string) $row['reference'],
                    'name' => (string) $row['name'],
                ];
            }
        }

        return $found;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident) || !PluginGrcmanagerSecurityIncident::canView()) {
            return '';
        }
        $count = $item->isNewID($item->getID()) ? 0 : countElementsInTable(
            self::getTable(),
            ['plugin_grcmanager_securityincidents_id' => $item->getID()]
        );

        return self::createTabEntry(__('IOC', 'grcmanager'), $count, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
            return false;
        }
        $iocs = $item->isNewID($item->getID()) ? [] : array_values((new self())->find(
            ['plugin_grcmanager_securityincidents_id' => $item->getID()],
            ['type ASC', 'value ASC']
        ));
        $links = [];
        foreach ($iocs as $ioc) {
            $links[$ioc['id']] = IocParser::lookupLinks((string) $ioc['type'], (string) $ioc['value']);
        }
        $counts = array_count_values(array_map(static fn (array $ioc): string => (string) $ioc['verdict'], $iocs));

        TemplateRenderer::getInstance()->display('@grcmanager/tabs/ioc.html.twig', [
            'item'     => $item,
            'iocs'     => $iocs,
            'types'    => self::getTypes(),
            'verdicts' => self::getVerdicts(),
            'counts'   => $counts,
            'links'    => $links,
            'seen'     => self::findInOtherIncidents((int) $item->getID(), array_column($iocs, 'ioc_hash')),
            'can_edit' => $item->canUpdateItem(),
            'form_url' => self::getFormURL(),
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        if (!self::canEditIncident((int) ($input['plugin_grcmanager_securityincidents_id'] ?? 0))) {
            return false;
        }
        $ioc = IocParser::detect((string) ($input['value'] ?? ''));
        if ($ioc === null) {
            return false;
        }
        $input['type'] = $ioc['type'];
        $input['value'] = $ioc['value'];
        $input['ioc_hash'] = self::hashOf($ioc['type'], $ioc['value']);

        return $this->prepareVerdict($input);
    }

    public function prepareInputForUpdate($input)
    {
        // L'incident, le type et la valeur d'un IOC ne changent pas : on en ajoute un autre.
        unset($input['plugin_grcmanager_securityincidents_id'], $input['type'], $input['value'], $input['ioc_hash']);

        return $this->prepareVerdict($input);
    }

    public static function canEditIncident(int $incidentsId): bool
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incidentsId > 0 && $incident->can($incidentsId, UPDATE);
    }

    public function canViewItem(): bool
    {
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incident->getFromDB((int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0))
            && $incident->canViewItem();
    }

    public function canCreateItem(): bool
    {
        return self::canEditIncident((int) ($this->input['plugin_grcmanager_securityincidents_id'] ?? 0));
    }

    public function canUpdateItem(): bool
    {
        return self::canEditIncident((int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0));
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function prepareVerdict(array $input): array
    {
        if (isset($input['verdict']) && !array_key_exists((string) $input['verdict'], self::getVerdicts())) {
            Session::addMessageAfterRedirect(__('Unknown verdict.', 'grcmanager'), false, ERROR);
            unset($input['verdict']);
        }

        return $input;
    }
}
