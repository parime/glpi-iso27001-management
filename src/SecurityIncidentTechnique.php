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
use GlpiPlugin\Grcmanager\Compatibility\Base\SecurityIncidentTechniqueBase;
use GlpiPlugin\Grcmanager\Services\Incident\MitreAttack;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #369 : technique MITRE ATT&CK observée sur un incident, rattachée à une tactique (une
 * technique comme « Valid Accounts » sert plusieurs tactiques). Mêmes droits que l'incident.
 */
class SecurityIncidentTechnique extends SecurityIncidentTechniqueBase
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public static function getTypeName($nb = 0)
    {
        return _n('MITRE ATT&CK technique', 'MITRE ATT&CK techniques', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-target-arrow';
    }

    /**
     * Noms des tactiques traduits (les noms des techniques restent ceux, officiels, de MITRE).
     *
     * @return array<string, string>
     */
    public static function translatedTactics(): array
    {
        $names = [
            'TA0043' => __('Reconnaissance', 'grcmanager'),
            'TA0042' => __('Resource Development', 'grcmanager'),
            'TA0001' => __('Initial Access', 'grcmanager'),
            'TA0002' => __('Execution', 'grcmanager'),
            'TA0003' => __('Persistence', 'grcmanager'),
            'TA0004' => __('Privilege Escalation', 'grcmanager'),
            'TA0005' => __('Stealth', 'grcmanager'),
            'TA0112' => __('Defense Impairment', 'grcmanager'),
            'TA0006' => __('Credential Access', 'grcmanager'),
            'TA0007' => __('Discovery', 'grcmanager'),
            'TA0008' => __('Lateral Movement', 'grcmanager'),
            'TA0009' => __('Collection', 'grcmanager'),
            'TA0011' => __('Command and Control', 'grcmanager'),
            'TA0010' => __('Exfiltration', 'grcmanager'),
            'TA0040' => __('Impact', 'grcmanager'),
        ];
        $tactics = [];
        foreach (MitreAttack::tactics() as $id => $name) {
            $tactics[$id] = $names[$id] ?? $name;
        }

        return $tactics;
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

        return self::createTabEntry('MITRE ATT&CK', $count, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
            return false;
        }
        $rows = $item->isNewID($item->getID()) ? [] : array_values((new self())->find(
            ['plugin_grcmanager_securityincidents_id' => $item->getID()],
            ['technique ASC']
        ));
        $tactics = self::translatedTactics();
        $matrix = array_fill_keys(array_keys($tactics), []);
        foreach ($rows as $row) {
            $row['full_name'] = MitreAttack::fullName((string) $row['technique']);
            $row['url'] = MitreAttack::url((string) $row['technique']);
            $matrix[(string) $row['tactic']][] = $row;
        }

        TemplateRenderer::getInstance()->display('@grcmanager/tabs/mitre_attack.html.twig', [
            'item'     => $item,
            'tactics'  => $tactics,
            'matrix'   => $matrix,
            'count'    => count($rows),
            'options'  => MitreAttack::dropdownOptions(),
            'source'   => MitreAttack::source(),
            'notice'   => MitreAttack::notice(),
            'can_edit' => $item->canUpdateItem(),
            'form_url' => self::getFormURL(),
        ]);

        return true;
    }

    /**
     * Ajoute une technique depuis la liste (« T1078|TA0001 ») ou une saisie libre normalisée
     * (« t1003/001 », « LSASS Memory »). Sans tactique précisée, la technique est rattachée à
     * chacune de ses tactiques.
     *
     * @return int Nombre de lignes ajoutées.
     */
    public static function addFromInput(int $incidentsId, string $choice, string $comment = ''): int
    {
        [$techniqueInput, $tactic] = array_pad(explode('|', trim($choice), 2), 2, '');
        $normalized = MitreAttack::normalize($techniqueInput);
        if ($normalized === null || $normalized['kind'] !== 'technique') {
            Session::addMessageAfterRedirect(sprintf(
                __('"%s" is not a known MITRE ATT&CK technique (T1566, T1003.001 or its name).', 'grcmanager'),
                htmlescape($techniqueInput)
            ), false, ERROR);

            return 0;
        }
        $technique = MitreAttack::technique($normalized['id']);
        $tactics = $technique['tactics'] ?? [];
        if ($tactic !== '') {
            $tactics = in_array($tactic, $tactics, true) ? [$tactic] : [];
        }
        $added = 0;
        foreach ($tactics as $tacticId) {
            $row = new self();
            $exists = $row->getFromDBByCrit([
                'plugin_grcmanager_securityincidents_id' => $incidentsId,
                'technique' => $normalized['id'],
                'tactic' => $tacticId,
            ]);
            if ($exists) {
                continue;
            }
            $id = $row->add([
                'plugin_grcmanager_securityincidents_id' => $incidentsId,
                'technique' => $normalized['id'],
                'tactic'    => $tacticId,
                'comment'   => $comment,
            ]);
            $added += $id ? 1 : 0;
        }

        return $added;
    }

    /**
     * Répartition par tactique et techniques les plus fréquentes, sur les incidents visibles
     * (tableau de bord, issue #369).
     *
     * @param list<int> $incidentIds
     * @return array{tactics: array<string, int>, techniques: array<string, int>}
     */
    public static function statistics(array $incidentIds): array
    {
        global $DB;

        $stats = ['tactics' => [], 'techniques' => []];
        if ($incidentIds === []) {
            return $stats;
        }
        $seen = [];
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_grcmanager_securityincidents_id' => $incidentIds],
        ]);
        // Une tactique ou une technique compte une fois par incident.
        foreach ($rows as $row) {
            $incident = (int) $row['plugin_grcmanager_securityincidents_id'];
            $keys = ['tactics' => (string) $row['tactic'], 'techniques' => (string) $row['technique']];
            foreach ($keys as $kind => $id) {
                if (!isset($seen[$kind][$incident][$id])) {
                    $stats[$kind][$id] = ($stats[$kind][$id] ?? 0) + 1;
                    $seen[$kind][$incident][$id] = true;
                }
            }
        }
        arsort($stats['techniques']);

        return $stats;
    }

    public function prepareInputForAdd($input)
    {
        if (!self::canEditIncident((int) ($input['plugin_grcmanager_securityincidents_id'] ?? 0))) {
            return false;
        }
        $technique = MitreAttack::technique((string) ($input['technique'] ?? ''));
        if ($technique === null || !in_array((string) ($input['tactic'] ?? ''), $technique['tactics'], true)) {
            return false;
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // Seul le commentaire change : une autre technique est une autre ligne.
        return array_intersect_key($input, ['id' => true, 'comment' => true]);
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
}
