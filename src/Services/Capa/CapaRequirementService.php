<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Capa;

/**
 * Business rule behind \GlpiPlugin\Grcmanager\Nonconformity's `finding_type` axis (inc/nonconformity.class.php),
 * extracted as pure PHP so it is unit-testable without a running GLPI instance, same "logique
 * pure-PHP testable sous src/Services/" convention as GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService.
 *
 * ISO 19011 vocabulary (see TECH_DEBT.md Sprint 4, résolu par cette classe) : a real non-conformity
 * (a genuine gap against a requirement) always requires a documented corrective action before it
 * can be closed/verified (ISO 27001 clause 10.2). A mere observation/remark is tracked through the
 * exact same CAPA workflow but never forces one — an auditor may still add one voluntarily, it is
 * just not a precondition to close/verify.
 */
final class CapaRequirementService
{
    /**
     * Anything other than the explicit 'observation' value is treated as CAPA-mandatory (including
     * an unknown/blank finding type) : the safe default matches this class' own migration default
     * on existing rows (never silently relax a validation rule for data this rule did not exist
     * for yet, see src/Install/Installer.php).
     */
    public static function isCapaMandatory(string $findingType): bool
    {
        return $findingType !== 'observation';
    }
}
