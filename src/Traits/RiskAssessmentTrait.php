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

namespace GlpiPlugin\Grcmanager\Traits;

use Dropdown;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;

/**
 * Shared probability x impact risk-assessment fields (category/probability/impact/risk_level/
 * computed_score/treatment/status) and their rendering, used by every risk-register itemtype in
 * this plugin: the generic register (\GlpiPlugin\Grcmanager\Risk, Sprint 1-2) and the supplier/third-party
 * register (\GlpiPlugin\Grcmanager\SupplierRisk, Sprint 5). Extracted here at Sprint 5 rather than left
 * duplicated across both classes, specifically so the probability x impact -> risk_level scoring
 * (computeRiskLevel() below) can never drift out of sync between the two registers: both always go
 * through the exact same RiskScoringService/RiskMatrixConfig call, one source of truth, one matrix
 * (front/config.php), one implementation to test.
 *
 * A composing class only needs to provide its own `rawSearchOptions()` (its own field IDs) and its
 * own `getSpecificValueToDisplay()`/`getSpecificValueToSelect()` overrides that call into
 * commonValueToDisplay()/commonValueToSelect() below for these shared fields, falling through to
 * whatever class-specific fields it has (e.g. \GlpiPlugin\Grcmanager\SupplierRisk's `suppliers_id`) and
 * finally to `parent::`.
 *
 * NOTE: depends on GLPI's runtime global $DB indirectly via RiskMatrixConfig::load(), and on GLPI
 * core classes/functions (Dropdown, __(), htmlescape()) only available inside a running GLPI
 * instance, not unit-tested in isolation, same exclusion rationale as
 * src/Services/Risk/RiskMatrixConfig.php, see phpstan.neon.dist. The pure lookup logic
 * (RiskScoringService) IS unit-tested independently.
 */
trait RiskAssessmentTrait
{
    /**
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            'people'       => __('People', 'grcmanager'),
            'process'      => __('Process', 'grcmanager'),
            'physical'     => __('Physical', 'grcmanager'),
            'third_party'  => __('Third party / supplier', 'grcmanager'),
            'technical'    => __('Technical', 'grcmanager'),
        ];
    }

    /**
     * Issue #706 : valeurs de probabilité de la taille de matrice configurée (ou de $size), avec les
     * libellés personnalisés de la configuration quand ils existent.
     *
     * @return array<string, string>
     */
    public static function getProbabilities(?int $size = null): array
    {
        return RiskScale::labelsFor(
            self::getDefaultProbabilityLabels(),
            RiskMatrixConfig::labels()['probability'],
            RiskScale::probabilityKeys($size ?? RiskMatrixConfig::size())
        );
    }

    /**
     * Issue #706 : valeurs d'impact de la taille de matrice configurée (ou de $size), avec les
     * libellés personnalisés de la configuration quand ils existent.
     *
     * @return array<string, string>
     */
    public static function getImpacts(?int $size = null): array
    {
        return RiskScale::labelsFor(
            self::getDefaultImpactLabels(),
            RiskMatrixConfig::labels()['impact'],
            RiskScale::impactKeys($size ?? RiskMatrixConfig::size())
        );
    }

    /**
     * Libellés par défaut, traduits, de toutes les probabilités (toutes tailles).
     *
     * @return array<string, string>
     */
    public static function getDefaultProbabilityLabels(): array
    {
        return [
            'very_rare' => __('Very rare', 'grcmanager'),
            'rare'      => __('Rare', 'grcmanager'),
            'possible'  => __('Possible', 'grcmanager'),
            'probable'  => __('Probable', 'grcmanager'),
            'certain'   => __('Almost certain', 'grcmanager'),
        ];
    }

    /**
     * Libellés par défaut, traduits, de tous les impacts (toutes tailles).
     *
     * @return array<string, string>
     */
    public static function getDefaultImpactLabels(): array
    {
        return [
            'negligible' => __('Negligible', 'grcmanager'),
            'low'        => __('Low', 'grcmanager'),
            'medium'     => __('Medium', 'grcmanager'),
            'high'       => __('High', 'grcmanager'),
            'critical'   => __('Critical', 'grcmanager'),
        ];
    }

    /**
     * Niveaux de risque calculés (initial et résiduel), sur quatre valeurs quelle que soit la taille
     * de la matrice (issue #706).
     *
     * @return array<string, string>
     */
    public static function getRiskLevels(): array
    {
        return [
            'low'      => __('Low', 'grcmanager'),
            'medium'   => __('Medium', 'grcmanager'),
            'high'     => __('High', 'grcmanager'),
            'critical' => __('Critical', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getTreatments(): array
    {
        return [
            ''         => __('No decision', 'grcmanager'),
            'accept'   => __('Accept', 'grcmanager'),
            'mitigate' => __('Mitigate', 'grcmanager'),
            'transfer' => __('Transfer', 'grcmanager'),
            'avoid'    => __('Avoid', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'identified'   => __('Identified', 'grcmanager'),
            'in_treatment' => __('In treatment', 'grcmanager'),
            'accepted'     => __('Accepted', 'grcmanager'),
            'closed'       => _x('masculine', 'Closed', 'grcmanager'),
        ];
    }

    /**
     * Keeps `computed_score`/`risk_level` derived from `probability`/`impact` at all times, see
     * RiskScoringService, neither field is ever entered manually.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->computeRiskLevel($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->computeRiskLevel($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function computeRiskLevel(array $input): array
    {
        // Issue #706 : une valeur hors de l'échelle configurée (formulaire forgé, valeur d'une autre
        // taille de matrice) n'est pas enregistrée ; la valeur actuelle est gardée.
        foreach (['probability' => self::getProbabilities(), 'impact' => self::getImpacts()] as $field => $allowed) {
            if (isset($input[$field]) && !array_key_exists((string) $input[$field], $allowed)) {
                unset($input[$field]);
            }
        }
        $probability = $input['probability'] ?? ($this->fields['probability'] ?? null);
        $impact      = $input['impact'] ?? ($this->fields['impact'] ?? null);

        if ($probability !== null && $impact !== null) {
            // The probability x impact -> risk_level mapping is administrable (see
            // front/config.php, RiskMatrixConfig), loaded fresh on every add/update so an admin's
            // edit takes effect immediately, never just for risks saved after the edit. Shared by
            // every risk-register itemtype using this trait: one matrix, never two copies that
            // could drift apart.
            $scoringService = RiskMatrixConfig::scoring();

            $input['computed_score'] = $scoringService->score((string) $probability, (string) $impact);
            $input['risk_level']     = $scoringService->level((string) $probability, (string) $impact);
        }

        return $input;
    }

    /**
     * Translates the raw DB enum values shared by every risk-register itemtype into color-coded
     * Tabler badges instead of showing the untranslated raw string. Returns null for any field this
     * trait doesn't own, so the composing class' own getSpecificValueToDisplay() can fall through to
     * its class-specific fields and finally to parent::getSpecificValueToDisplay().
     */
    protected static function commonValueToDisplay(string $field, $value): ?string
    {
        return match ($field) {
            'category'             => self::plainBadge('bg-azure-lt', self::getCategories(), $value),
            // Toutes les tailles : une valeur d'une autre taille reste lisible.
            'probability'          => self::plainBadge('bg-blue-lt', self::getProbabilities(5), $value),
            'impact'               => self::impactBadge($value),
            'risk_level'           => self::riskLevelBadge($value),
            'treatment'            => self::plainBadge('bg-purple-lt', self::getTreatments(), $value),
            'status'               => self::statusBadge($value),
            default                => null,
        };
    }

    /**
     * Renders a real `<select>` filter widget in the search form for every fixed-enum column this
     * trait owns (category/probability/impact/risk_level/treatment/status), instead of GLPI's
     * default free-text box for `datatype => 'specific'` fields, same lesson as
     * commonValueToDisplay() above. Returns null for any field this trait doesn't own. `$options`
     * must already carry `display => false`/`name`/`value`, same contract as GLPI core's own
     * getSpecificValueToSelect().
     *
     * @param array<string, mixed> $options
     */
    protected static function commonValueToSelect(string $field, string $name, array $options): ?string
    {
        return match ($field) {
            'category'             => Dropdown::showFromArray($name, self::getCategories(), $options),
            'probability'          => Dropdown::showFromArray($name, self::getProbabilities(), $options),
            'impact'               => Dropdown::showFromArray($name, self::getImpacts(), $options),
            'risk_level'           => Dropdown::showFromArray($name, self::getRiskLevels(), $options),
            'treatment'            => Dropdown::showFromArray($name, self::getTreatments(), $options),
            'status'               => Dropdown::showFromArray($name, self::getStatuses(), $options),
            default                => null,
        };
    }

    /**
     * @param array<string, string> $labels
     */
    private static function plainBadge(string $class, array $labels, ?string $value): string
    {
        $label = $labels[$value] ?? (string) $value;

        if ($label === '') {
            return '';
        }

        return Badge::html($class, null, $label);
    }

    /**
     * Impact : mêmes couleurs que le niveau de risque, plus « négligeable » en matrice 5x5, avec le
     * libellé configuré (issue #706).
     */
    public static function impactBadge(?string $value): string
    {
        $labels = self::getImpacts(5);
        if ($value === null || !isset($labels[$value])) {
            return self::riskLevelBadge($value);
        }
        $class = [
            'negligible' => 'bg-teal-lt',
            'low'        => 'bg-green-lt',
            'medium'     => 'bg-yellow-lt',
            'high'       => 'bg-orange-lt',
            'critical'   => 'bg-red-lt',
        ][$value];
        $icon = in_array($value, ['high', 'critical'], true) ? 'ti-alert-triangle' : null;

        return Badge::html($class, $icon, $labels[$value]);
    }

    /**
     * Shared low/medium/high/critical scale, used for both `impact` and `risk_level`.
     */
    public static function riskLevelBadge(?string $value): string
    {
        $levels = [
            'low'      => ['bg-green-lt', __('Low', 'grcmanager')],
            'medium'   => ['bg-yellow-lt', __('Medium', 'grcmanager')],
            'high'     => ['bg-orange-lt', __('High', 'grcmanager')],
            'critical' => ['bg-red-lt', __('Critical', 'grcmanager')],
        ];

        [$class, $label] = $levels[$value] ?? ['bg-secondary-lt', (string) $value];
        $icon = in_array($value, ['high', 'critical'], true) ? 'ti-alert-triangle' : null;

        return Badge::html($class, $icon, $label);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            'identified'   => ['bg-secondary-lt', 'ti-eye', __('Identified', 'grcmanager')],
            'in_treatment' => ['bg-blue-lt', 'ti-tool', __('In treatment', 'grcmanager')],
            'accepted'     => ['bg-green-lt', 'ti-shield-check', __('Accepted', 'grcmanager')],
            'closed'       => ['bg-dark-lt', 'ti-check', _x('masculine', 'Closed', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }
}
