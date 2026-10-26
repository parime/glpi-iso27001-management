<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Traits;

use Dropdown;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;

/**
 * Shared probability x impact risk-assessment fields (category/probability/impact/risk_level/
 * computed_score/treatment/status) and their rendering, used by every risk-register itemtype in
 * this plugin: the generic register (PluginGrcmanagerRisk, Sprint 1-2) and the supplier/third-party
 * register (PluginGrcmanagerSupplierRisk, Sprint 5). Extracted here at Sprint 5 rather than left
 * duplicated across both classes, specifically so the probability x impact -> risk_level scoring
 * (computeRiskLevel() below) can never drift out of sync between the two registers: both always go
 * through the exact same RiskScoringService/RiskMatrixConfig call, one source of truth, one matrix
 * (front/config.php), one implementation to test.
 *
 * A composing class only needs to provide its own `rawSearchOptions()` (its own field IDs) and its
 * own `getSpecificValueToDisplay()`/`getSpecificValueToSelect()` overrides that call into
 * commonValueToDisplay()/commonValueToSelect() below for these shared fields, falling through to
 * whatever class-specific fields it has (e.g. PluginGrcmanagerSupplierRisk's `suppliers_id`) and
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
            'people'       => __('Humain', 'grcmanager'),
            'process'      => __('Processus', 'grcmanager'),
            'physical'     => __('Physique', 'grcmanager'),
            'third_party'  => __('Tiers / fournisseur', 'grcmanager'),
            'technical'    => __('Technique', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getProbabilities(): array
    {
        return [
            'rare'     => __('Rare', 'grcmanager'),
            'possible' => __('Possible', 'grcmanager'),
            'probable' => __('Probable', 'grcmanager'),
            'certain'  => __('Quasi certaine', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getImpacts(): array
    {
        return [
            'low'      => __('Faible', 'grcmanager'),
            'medium'   => __('Moyen', 'grcmanager'),
            'high'     => __('Élevé', 'grcmanager'),
            'critical' => __('Critique', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getTreatments(): array
    {
        return [
            ''         => __('Aucune décision', 'grcmanager'),
            'accept'   => __('Accepter', 'grcmanager'),
            'mitigate' => __('Mitiger', 'grcmanager'),
            'transfer' => __('Transférer', 'grcmanager'),
            'avoid'    => __('Éviter', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'identified'   => __('Identifié', 'grcmanager'),
            'in_treatment' => __('En traitement', 'grcmanager'),
            'accepted'     => __('Accepté', 'grcmanager'),
            'closed'       => __('Clôturé', 'grcmanager'),
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
        $probability = $input['probability'] ?? ($this->fields['probability'] ?? null);
        $impact      = $input['impact'] ?? ($this->fields['impact'] ?? null);

        if ($probability !== null && $impact !== null) {
            // The probability x impact -> risk_level mapping is administrable (see
            // front/config.php, RiskMatrixConfig), loaded fresh on every add/update so an admin's
            // edit takes effect immediately, never just for risks saved after the edit. Shared by
            // every risk-register itemtype using this trait: one matrix, never two copies that
            // could drift apart.
            $scoringService = new RiskScoringService(RiskMatrixConfig::load());

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
            'probability'          => self::plainBadge('bg-blue-lt', self::getProbabilities(), $value),
            'impact', 'risk_level' => self::riskLevelBadge($value),
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
            'impact', 'risk_level' => Dropdown::showFromArray($name, self::getImpacts(), $options),
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
     * Shared low/medium/high/critical scale, used for both `impact` and `risk_level`.
     */
    public static function riskLevelBadge(?string $value): string
    {
        $levels = [
            'low'      => ['bg-green-lt', __('Faible', 'grcmanager')],
            'medium'   => ['bg-yellow-lt', __('Moyen', 'grcmanager')],
            'high'     => ['bg-orange-lt', __('Élevé', 'grcmanager')],
            'critical' => ['bg-red-lt', __('Critique', 'grcmanager')],
        ];

        [$class, $label] = $levels[$value] ?? ['bg-secondary-lt', (string) $value];
        $icon = in_array($value, ['high', 'critical'], true) ? 'ti-alert-triangle' : null;

        return Badge::html($class, $icon, $label);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            'identified'   => ['bg-secondary-lt', 'ti-eye', __('Identifié', 'grcmanager')],
            'in_treatment' => ['bg-blue-lt', 'ti-tool', __('En traitement', 'grcmanager')],
            'accepted'     => ['bg-green-lt', 'ti-shield-check', __('Accepté', 'grcmanager')],
            'closed'       => ['bg-dark-lt', 'ti-check', __('Clôturé', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }
}
