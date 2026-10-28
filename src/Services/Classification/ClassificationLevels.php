<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Classification;

/**
 * Pure validation/decision logic behind the C/I/D (Confidentialité/Intégrité/Disponibilité) asset
 * classification register (issue #26), see \GlpiPlugin\Grcmanager\AssetClassification. Kept
 * GLPI-independent (no $DB, no CommonDBTM, no __()) so every zero/some/all-axes-set scenario can
 * be unit tested directly (tests/Unit/Services/Classification/ClassificationLevelsTest.php), the
 * same split already used for the risk <-> CMDB item link (RiskItemLinkNormalizer, issue #25) and
 * the risk-scoring logic (RiskAssessmentTrait): pure decision logic here, thin $DB/HTML wrapper on
 * the CommonDBTM class itself (inc/assetclassification.class.php).
 *
 * Deliberately a 3-level scale (low/medium/high), NOT the 4-level probability x impact scale
 * (RiskAssessmentTrait::getImpacts(), low/medium/high/critical) it otherwise resembles: C/I/D
 * classification is a property of the asset itself (ISO/IEC 27001:2022 A.5.9/A.5.12/A.8.2), not a
 * risk score, and the issue explicitly asks for "Faible/Moyen/Élevé" only.
 */
final class ClassificationLevels
{
    public const LOW    = 'low';
    public const MEDIUM = 'medium';
    public const HIGH   = 'high';

    /**
     * @var array<int, string>
     */
    public const ALLOWED = [self::LOW, self::MEDIUM, self::HIGH];

    /**
     * @var array<int, string> the three C/I/D axes, in the order they're always displayed.
     */
    public const AXES = ['confidentiality', 'integrity', 'availability'];

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::ALLOWED, true);
    }

    /**
     * Never trusts a raw request value as-is (same defensive posture as
     * front/config.php's own risk-matrix sanitization): an invalid/missing value always falls back
     * to "not set" (empty string on the DB column, see src/Install/Installer.php), never stored as
     * garbage and never silently defaulted to a specific level that wasn't actually chosen.
     */
    public static function sanitize(?string $value): ?string
    {
        return self::isValid($value) ? $value : null;
    }

    /**
     * An asset is considered "classified" as soon as at least one of the three axes carries a
     * real value: partial classification (e.g. only `confidentiality` set for a HR database whose
     * integrity/availability sensitivity hasn't been assessed yet) is a valid, expected state, not
     * an all-or-nothing requirement. Drives the reverse tab's empty-state vs. summary rendering
     * (\GlpiPlugin\Grcmanager\AssetClassification::displayTabContentForItem()).
     *
     * @param array<string, mixed>|null $classification raw DB row (or null: no row at all yet).
     */
    public static function isClassified(?array $classification): bool
    {
        if ($classification === null) {
            return false;
        }

        foreach (self::AXES as $axis) {
            if (self::isValid($classification[$axis] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any of the three axes is at the HIGH level. Backs the risk form's optional, purely
     * informational suggestion (inc/risk.class.php showForm(): "a linked asset carries a high C/I/D
     * classification, consider reviewing this risk's impact") — never auto-changes the risk's own
     * `impact` field, only ever a non-blocking hint.
     *
     * @param array<string, mixed>|null $classification
     */
    public static function hasHighAxis(?array $classification): bool
    {
        if ($classification === null) {
            return false;
        }

        foreach (self::AXES as $axis) {
            if (($classification[$axis] ?? null) === self::HIGH) {
                return true;
            }
        }

        return false;
    }
}
