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

namespace GlpiPlugin\Grcmanager\Services\Sbom;

/**
 * Issue #869 : règles des déclarations VEX, sans base de données.
 *
 * Quatre statuts, ceux d'OpenVEX (et de CISA « Minimum Requirements for VEX ») : non affecté, affecté,
 * corrigé, en cours d'analyse. Les états et justifications de CycloneDX sont ramenés à ces statuts et
 * aux cinq justifications d'OpenVEX.
 */
final class VexRules
{
    public const STATUS_NOT_AFFECTED = 'not_affected';

    public const STATUS_AFFECTED = 'affected';

    public const STATUS_FIXED = 'fixed';

    public const STATUS_UNDER_INVESTIGATION = 'under_investigation';

    public const STATUSES = [
        self::STATUS_NOT_AFFECTED, self::STATUS_AFFECTED, self::STATUS_FIXED, self::STATUS_UNDER_INVESTIGATION,
    ];

    public const JUSTIFICATIONS = [
        'component_not_present',
        'vulnerable_code_not_present',
        'vulnerable_code_not_in_execute_path',
        'vulnerable_code_cannot_be_controlled_by_adversary',
        'inline_mitigations_already_exist',
    ];

    public const SOURCE_MANUAL = 'manual';

    private const CYCLONEDX_STATES = [
        'resolved'               => self::STATUS_FIXED,
        'resolved_with_pedigree' => self::STATUS_FIXED,
        'exploitable'            => self::STATUS_AFFECTED,
        'in_triage'              => self::STATUS_UNDER_INVESTIGATION,
        'false_positive'         => self::STATUS_NOT_AFFECTED,
        'not_affected'           => self::STATUS_NOT_AFFECTED,
    ];

    private const CYCLONEDX_JUSTIFICATIONS = [
        'code_not_present'                => 'vulnerable_code_not_present',
        'code_not_reachable'              => 'vulnerable_code_not_in_execute_path',
        'requires_configuration'          => 'vulnerable_code_cannot_be_controlled_by_adversary',
        'requires_dependency'             => 'vulnerable_code_cannot_be_controlled_by_adversary',
        'requires_environment'            => 'vulnerable_code_cannot_be_controlled_by_adversary',
        'protected_by_compiler'           => 'inline_mitigations_already_exist',
        'protected_at_runtime'            => 'inline_mitigations_already_exist',
        'protected_at_perimeter'          => 'inline_mitigations_already_exist',
        'protected_by_mitigating_control' => 'inline_mitigations_already_exist',
    ];

    public static function fromCycloneDxState(string $state): string
    {
        return self::CYCLONEDX_STATES[$state] ?? '';
    }

    public static function fromCycloneDxJustification(string $justification): string
    {
        return self::CYCLONEDX_JUSTIFICATIONS[$justification] ?? self::justification($justification);
    }

    public static function fromOpenVexStatus(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : '';
    }

    public static function justification(string $justification): string
    {
        return in_array($justification, self::JUSTIFICATIONS, true) ? $justification : '';
    }

    /**
     * Erreur d'une déclaration saisie, ou chaîne vide. « Non affecté » demande une justification
     * ou une explication : sans elle, la déclaration ne vaut rien devant un client ou un auditeur.
     */
    public static function validate(string $vulnerabilityId, string $status, string $justification, string $detail): string
    {
        if (SbomParser::vulnerabilityId($vulnerabilityId) === '') {
            return 'vulnerability';
        }
        if (!in_array($status, self::STATUSES, true)) {
            return 'status';
        }
        if ($justification !== '' && self::justification($justification) === '') {
            return 'justification';
        }
        if ($status === self::STATUS_NOT_AFFECTED && $justification === '' && trim($detail) === '') {
            return 'not_affected_without_reason';
        }

        return '';
    }

    /**
     * Déclaration qui s'applique à une vulnérabilité d'un composant d'une version : la plus précise
     * (composant nommé plutôt que produit entier, version nommée plutôt que toutes), puis la plus
     * récente (ordre des déclarations, la dernière gagne).
     *
     * @param list<array<string, mixed>> $statements déclarations de l'application, plus anciennes d'abord
     * @param array<string, string> $component bom_ref, purl et name
     * @return array<string, mixed>|null
     */
    public static function statementFor(array $statements, string $vulnerabilityId, array $component, string $version): ?array
    {
        $keys = array_filter([
            (string) ($component['bom_ref'] ?? ''),
            (string) ($component['purl'] ?? ''),
            (string) ($component['name'] ?? ''),
            self::purlWithoutVersion((string) ($component['purl'] ?? '')),
        ], static fn (string $key): bool => $key !== '');
        $best = null;
        $bestScore = -1;
        foreach ($statements as $statement) {
            if ((string) $statement['vulnerability_id'] !== $vulnerabilityId) {
                continue;
            }
            $statementVersion = (string) $statement['product_version'];
            $statementComponent = (string) $statement['component'];
            if ($statementVersion !== '' && $statementVersion !== $version) {
                continue;
            }
            if ($statementComponent !== '' && !in_array($statementComponent, $keys, true)) {
                continue;
            }
            $score = ($statementComponent !== '' ? 2 : 0) + ($statementVersion !== '' ? 1 : 0);
            if ($score >= $bestScore) {
                $best = $statement;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** purl sans version ni qualificatifs : pkg:npm/lodash@4.17.21?x=y devient pkg:npm/lodash. */
    public static function purlWithoutVersion(string $purl): string
    {
        if (!str_starts_with($purl, 'pkg:')) {
            return '';
        }
        $cut = strcspn($purl, '@?#');

        return substr($purl, 0, $cut);
    }

    /**
     * Fabricant et produit d'une CPE 2.3 (cpe:2.3:a:openssl:openssl:3.0.1:...) ou 2.2
     * (cpe:/a:openssl:openssl:3.0.1).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function cpeVendorProduct(string $cpe): ?array
    {
        if (str_starts_with($cpe, 'cpe:2.3:')) {
            $parts = explode(':', $cpe);
            $vendor = $parts[3] ?? '';
            $product = $parts[4] ?? '';
        } elseif (str_starts_with($cpe, 'cpe:/')) {
            $parts = explode(':', substr($cpe, 5));
            $vendor = $parts[1] ?? '';
            $product = $parts[2] ?? '';
        } else {
            return null;
        }
        if ($vendor === '' || $product === '' || in_array('*', [$vendor, $product], true)) {
            return null;
        }

        return [strtolower($vendor), strtolower($product)];
    }

    /**
     * Indicateurs d'une liste de vulnérabilités rapprochées, chacune avec son statut VEX (vide sans
     * déclaration).
     *
     * @param list<array<string, mixed>> $findings
     * @return array{total: int, without_statement: int, affected: int, not_affected: int, fixed: int,
     *     under_investigation: int}
     */
    public static function indicators(array $findings): array
    {
        $counts = [
            'total' => count($findings), 'without_statement' => 0, self::STATUS_AFFECTED => 0,
            self::STATUS_NOT_AFFECTED => 0, self::STATUS_FIXED => 0, self::STATUS_UNDER_INVESTIGATION => 0,
        ];
        foreach ($findings as $finding) {
            $status = (string) ($finding['status'] ?? '');
            if (isset($counts[$status]) && $status !== 'total') {
                $counts[$status]++;
            } else {
                $counts['without_statement']++;
            }
        }

        return $counts;
    }
}
