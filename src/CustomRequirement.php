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

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager;

use GlpiPlugin\Grcmanager\Compatibility\Base\CustomRequirementBase;

/**
 * Issue #537 : exigence d'un référentiel personnalisé. Code, thème, intitulé, description et
 * correspondance Annexe A viennent du fichier importé ; le statut et le commentaire sont saisis dans
 * le plugin et survivent aux réimports. Une exigence absente d'une nouvelle version est marquée
 * retirée (is_removed), jamais supprimée.
 */
class CustomRequirement extends CustomRequirementBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const STATUS_NONE = '';
    public const STATUS_COMPLIANT = 'compliant';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_NON_COMPLIANT = 'non_compliant';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_customrequirements';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Custom requirement', 'Custom requirements', $nb, 'grcmanager');
    }

    /**
     * Statuts d'auto-évaluation, avec leur couleur de badge.
     *
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_NONE           => ['label' => __('Not assessed', 'grcmanager'), 'class' => 'bg-secondary-lt',
                'icon' => 'ti ti-circle-dashed'],
            self::STATUS_COMPLIANT      => ['label' => __('Compliant', 'grcmanager'), 'class' => 'bg-green-lt',
                'icon' => 'ti ti-circle-check'],
            self::STATUS_PARTIAL        => ['label' => __('Partially compliant', 'grcmanager'),
                'class' => 'bg-yellow-lt', 'icon' => 'ti ti-circle-half-2'],
            self::STATUS_NON_COMPLIANT  => ['label' => __('Non-compliant', 'grcmanager'), 'class' => 'bg-red-lt',
                'icon' => 'ti ti-circle-x'],
            self::STATUS_NOT_APPLICABLE => ['label' => __('Not applicable', 'grcmanager'), 'class' => 'bg-azure-lt',
                'icon' => 'ti ti-circle-minus'],
        ];
    }

    public function prepareInputForAdd($input)
    {
        return $this->cleanStatus($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->cleanStatus($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function cleanStatus(array $input): array
    {
        // Énumération fermée : une valeur inconnue revient à « non évaluée ».
        if (array_key_exists('status', $input) && !array_key_exists((string) $input['status'], self::getStatuses())) {
            $input['status'] = self::STATUS_NONE;
        }

        return $input;
    }
}
