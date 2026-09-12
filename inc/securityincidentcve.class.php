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

/**
 * One CVE reference associated to a PluginGrcmanagerSecurityIncident — several per
 * incident, native reference feature ported from the sibling plugin `glpi-vulnerability-manager`'s
 * own `cve_id varchar(20)` column precedent (see this plugin's own project plan). Deliberately a
 * standalone plugin table, no dependency on `glpi-vulnerability-manager` itself (that plugin is
 * being retired).
 *
 * Uses `PluginGrcmanagerSecurityIncident`'s own right rather than a dedicated one: a CVE
 * reference has no meaningful access boundary of its own, separate from the incident it documents.
 */
class PluginGrcmanagerSecurityIncidentCve extends CommonDBTM
{
    // Same literal as PluginGrcmanagerSecurityIncident::$rightname — a static property of
    // another class cannot be used as a property default value (not a constant expression), so
    // this is duplicated rather than referenced.
    public static $rightname = 'plugin_grcmanager_securityincident';

    public static function getTypeName($nb = 0)
    {
        return _n('CVE reference', 'CVE references', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-bug';
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

        return self::createTabEntry(_n('CVE', 'CVEs', $count, 'grcmanager'), $count, $item::class);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
            return false;
        }

        $cves = [];
        if (!$item->isNewID($item->getID())) {
            $cve = new self();
            $cves = $cve->find(['plugin_grcmanager_securityincidents_id' => $item->getID()], ['cve_id ASC']);
        }

        Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/tabs/cve.html.twig', [
           'item' => $item,
           'cves' => $cves,
           'can_edit' => $item->canUpdateItem(),
        ]);

        return true;
    }

    /**
     * Same "trust nothing free-text beyond a shape check" reasoning as every sibling plugin in
     * this author's own ecosystem: a CVE ID has a fixed, well-known shape (`CVE-YYYY-NNNN...`),
     * reject anything else rather than storing an arbitrary string under a field whose whole
     * purpose is cross-referencing a real, externally-verifiable identifier.
     */
    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input);
    }

    /**
     * Splits a textarea's raw content (one identifier per line, and/or comma-separated) into a
     * deduplicated list of uppercased candidate identifiers — shape validation of each one still
     * happens per-row in `prepareInput()` when it's actually added, this only tokenizes the input.
     *
     * @return list<string>
     */
    public static function splitIdentifiers(string $raw): array
    {
        $tokens = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map('strtoupper', $tokens)));
    }

    /**
     * @return array<string, mixed>|false
     */
    private function prepareInput(array $input)
    {
        if (isset($input['cve_id'])) {
            $cveId = strtoupper(trim((string) $input['cve_id']));
            if (!preg_match('/^CVE-\d{4}-\d{4,}$/', $cveId)) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('"%s" is not a valid CVE identifier (expected format: CVE-YYYY-NNNN).', 'grcmanager'),
                        $input['cve_id']
                    ),
                    false,
                    ERROR
                );

                return false;
            }
            $input['cve_id'] = $cveId;
        }

        return $input;
    }
}
