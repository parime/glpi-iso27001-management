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

use GlpiPlugin\Grcmanager\Services\Risk\EbiosRules;
use Supplier;

/**
 * Issue #376, atelier 3 : partie prenante de l'écosystème (fournisseur, partenaire, client...), cotée
 * sur la dépendance, la pénétration, la maturité et la confiance pour la cartographie de la menace.
 */
class EbiosStakeholder extends EbiosElement
{
    private const SCALES = ['dependence', 'penetration', 'maturity', 'trust'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosstakeholders';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Stakeholder', 'Stakeholders', $nb, 'grcmanager');
    }

    /**
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            'supplier' => __('Supplier', 'grcmanager'),
            'partner'  => __('Partner', 'grcmanager'),
            'customer' => __('Customer', 'grcmanager'),
            'internal' => __('Internal team', 'grcmanager'),
            'other'    => __('Other', 'grcmanager'),
        ];
    }

    protected static function allowedFields(): array
    {
        return array_merge(['name', 'category', 'suppliers_id'], self::SCALES);
    }

    protected function normalize(array $input)
    {
        foreach (self::SCALES as $scale) {
            if (isset($input[$scale])) {
                $input[$scale] = EbiosRules::level($input[$scale]);
            }
        }
        if (isset($input['category']) && !array_key_exists((string) $input['category'], self::getCategories())) {
            $input['category'] = 'other';
        }
        $suppliersId = (int) ($input['suppliers_id'] ?? 0);
        if ($suppliersId > 0) {
            // Un fournisseur réellement lisible ; son nom sert de libellé quand aucun n'est saisi.
            $supplier = new Supplier();
            if (!$supplier->can($suppliersId, READ)) {
                return false;
            }
            if (trim((string) ($input['name'] ?? '')) === '') {
                $input['name'] = (string) $supplier->getName();
            }
        }

        return $input;
    }

    /**
     * Retirer une partie prenante retire les scénarios stratégiques qui passent par elle.
     */
    public function cleanDBonPurge()
    {
        (new EbiosStrategicScenario())->deleteByCriteria([self::getForeignKeyField() => $this->getID()]);
    }
}
