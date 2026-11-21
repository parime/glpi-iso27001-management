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

namespace GlpiPlugin\Grcmanager\Services\Access;

/**
 * Issue #835 : règles métier de l'audit de sécurité de l'Active Directory, sans base de données.
 *
 * - Score PingCastle de 0 (aucun point de risque) à 100 (le pire) : niveau de lecture du plugin
 *   (bon, à surveiller, préoccupant), le même pour le score global et par catégorie.
 * - Gravité d'une règle selon ses points.
 * - Mesures de l'Annexe A concernées : par catégorie, complétées selon l'identifiant de la règle
 *   (mots de passe, systèmes obsolètes, protocoles réseau).
 * - Comparaison de deux imports d'un domaine : règles apparues, résolues, toujours présentes.
 *
 * Lot 2 : Purple Knight (indicateurs d'exposition et de compromission de l'AD), Maester et
 * ScubaGear (configuration d'Entra ID et de Microsoft 365). Tous les scores gardés suivent la
 * convention de PingCastle (0 le meilleur, 100 le pire) : le score de Purple Knight (100 le
 * meilleur) est retourné, celui de Maester et ScubaGear est la part des contrôles en échec.
 */
final class AdAuditRules
{
    public const TOOL_PINGCASTLE = 'pingcastle';
    public const TOOL_PURPLEKNIGHT = 'purpleknight';
    public const TOOL_MAESTER = 'maester';
    public const TOOL_SCUBAGEAR = 'scubagear';

    /** Outils qui auditent l'annuaire Active Directory (score de l'AD, KRI, carte). */
    public const AD_TOOLS = [self::TOOL_PINGCASTLE, self::TOOL_PURPLEKNIGHT];

    /** Outils qui auditent la configuration d'Entra ID et de Microsoft 365. */
    public const CLOUD_TOOLS = [self::TOOL_MAESTER, self::TOOL_SCUBAGEAR];

    public const CATEGORY_STALE = 'stale';
    public const CATEGORY_PRIVILEGED = 'privileged';
    public const CATEGORY_TRUST = 'trust';
    public const CATEGORY_ANOMALY = 'anomaly';
    public const CATEGORY_OTHER = 'other';
    // Lot 2 : catégories de Purple Knight, puis d'Entra ID et de Microsoft 365.
    public const CATEGORY_ACCOUNTS = 'accounts';
    public const CATEGORY_DELEGATION = 'delegation';
    public const CATEGORY_INFRASTRUCTURE = 'infrastructure';
    public const CATEGORY_GPO = 'gpo';
    public const CATEGORY_KERBEROS = 'kerberos';
    public const CATEGORY_IDENTITY = 'identity';
    public const CATEGORY_CLOUD = 'cloud';

    public const LEVEL_GOOD = 'good';
    public const LEVEL_WATCH = 'watch';
    public const LEVEL_POOR = 'poor';

    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_INFO = 'info';

    /** Mesures dont la fiche montre la preuve de l'audit de l'AD. */
    public const EVIDENCE_CONTROLS = ['A.5.15', 'A.5.17', 'A.5.18', 'A.5.23', 'A.8.2', 'A.8.5'];

    /** Mesures de l'Annexe A concernées par chaque catégorie de PingCastle. */
    private const CATEGORY_CONTROLS = [
        self::CATEGORY_STALE      => ['A.5.18', 'A.8.8'],
        self::CATEGORY_PRIVILEGED => ['A.8.2', 'A.5.15'],
        self::CATEGORY_TRUST      => ['A.5.15', 'A.8.2'],
        self::CATEGORY_ANOMALY    => ['A.8.5', 'A.8.9'],
        self::CATEGORY_OTHER      => ['A.8.9'],
        self::CATEGORY_ACCOUNTS       => ['A.5.17', 'A.5.18', 'A.8.5'],
        self::CATEGORY_DELEGATION     => ['A.5.15', 'A.8.2'],
        self::CATEGORY_INFRASTRUCTURE => ['A.8.9', 'A.8.20'],
        self::CATEGORY_GPO            => ['A.8.9'],
        self::CATEGORY_KERBEROS       => ['A.5.17', 'A.8.5'],
        self::CATEGORY_IDENTITY       => ['A.5.15', 'A.5.17', 'A.8.5'],
        self::CATEGORY_CLOUD          => ['A.5.23', 'A.8.9'],
    ];

    /** Mesures ajoutées selon l'identifiant de la règle. */
    private const RULE_CONTROLS = [
        '/pwd|password|krbtgt|laps|kerberoast|asrep|reversible|lmhash/i' => ['A.5.17', 'A.8.5'],
        '/^S-OS-|obsolete|notupdated|^S-DC-/i'                           => ['A.8.8'],
        '/llmnr|netbios|smb|ntlm|ldapsigning|ldaps|signing|spooler/i'    => ['A.8.20'],
        '/admin|delegat|dcsync|privileg|operators|^P-/i'                 => ['A.8.2'],
        '/mfa|multi-?factor|phishing-resistant|authenticat|conditional/i' => ['A.8.5'],
        '/guest|external|sharing/i'                                       => ['A.5.15', 'A.5.23'],
    ];

    public static function normalizeCategory(string $category): string
    {
        return match (strtolower(trim($category))) {
            'staleobjects', 'stale', 'staleobject'                       => self::CATEGORY_STALE,
            'privilegiedaccounts', 'privilegedaccounts', 'privileged',
            'privilegiedgroup', 'privilegedgroup'                        => self::CATEGORY_PRIVILEGED,
            'trusts', 'trust'                                            => self::CATEGORY_TRUST,
            'anomalies', 'anomaly'                                       => self::CATEGORY_ANOMALY,
            'accounts', 'account', 'accountsecurity'                     => self::CATEGORY_ACCOUNTS,
            'delegation', 'addelegation'                                 => self::CATEGORY_DELEGATION,
            'infrastructure', 'adinfrastructure', 'adinfrastructuresecurity',
            'infrastructuresecurity'                                     => self::CATEGORY_INFRASTRUCTURE,
            'gpo', 'grouppolicy', 'grouppolicysecurity'                  => self::CATEGORY_GPO,
            'kerberos', 'kerberossecurity'                               => self::CATEGORY_KERBEROS,
            'identity', 'entraid', 'azuread', 'aad', 'hybrid', 'hybridad', 'entraidsecurity',
            'okta', 'oktasecurity'                                       => self::CATEGORY_IDENTITY,
            'cloud', 'microsoft365', 'm365', 'exo', 'exchange', 'exchangeonline', 'defender',
            'sharepoint', 'teams', 'powerplatform', 'powerbi'            => self::CATEGORY_CLOUD,
            default                                                      => self::CATEGORY_OTHER,
        };
    }

    /**
     * Catégorie lue sur un libellé libre (Purple Knight : « AD Delegation », « Kerberos Security »).
     */
    public static function categoryFromLabel(string $label): string
    {
        return self::normalizeCategory((string) preg_replace('/[^a-z0-9]/', '', strtolower($label)));
    }

    /**
     * Points d'une règle selon la gravité annoncée par l'outil (Purple Knight, Maester), pour garder
     * la même lecture de gravité que PingCastle (severity()).
     */
    public static function pointsForSeverity(string $severity): int
    {
        return match (strtolower(trim($severity))) {
            'critical', 'critique'           => 40,
            'high', 'élevée', 'elevee'       => 30,
            'warning', 'medium', 'moderate' => 10,
            'low', 'faible'                  => 5,
            default                          => 0,
        };
    }

    /**
     * Score à la convention du plugin (0 le meilleur) depuis un score où 100 est le meilleur
     * (Purple Knight).
     */
    public static function riskFromSecurityScore(int $securityScore): int
    {
        return 100 - self::clampScore($securityScore);
    }

    /**
     * Part des contrôles en échec, de 0 (tous réussis) à 100 ; null sans contrôle évalué (Maester,
     * ScubaGear).
     */
    public static function failureRate(int $failed, int $evaluated): ?int
    {
        if ($evaluated <= 0) {
            return null;
        }

        return self::clampScore((int) round(100 * max(0, $failed) / $evaluated));
    }

    public static function clampScore(int $score): int
    {
        return max(0, min(100, $score));
    }

    /**
     * Lecture du score (0 le meilleur, 100 le pire) : au plus 20 bon, au plus 50 à surveiller,
     * au-delà préoccupant. Seuil propre au plugin, pas celui de l'outil.
     */
    public static function scoreLevel(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return $score <= 20 ? self::LEVEL_GOOD : ($score <= 50 ? self::LEVEL_WATCH : self::LEVEL_POOR);
    }

    public static function severity(int $points): string
    {
        return match (true) {
            $points >= 30 => self::SEVERITY_HIGH,
            $points >= 10 => self::SEVERITY_MEDIUM,
            $points > 0   => self::SEVERITY_LOW,
            default       => self::SEVERITY_INFO,
        };
    }

    /**
     * Mesures de l'Annexe A concernées par une règle, triées.
     *
     * @return list<string>
     */
    public static function controls(string $category, string $ruleId): array
    {
        $codes = self::CATEGORY_CONTROLS[$category] ?? self::CATEGORY_CONTROLS[self::CATEGORY_OTHER];
        foreach (self::RULE_CONTROLS as $pattern => $extra) {
            if (preg_match($pattern, $ruleId) === 1) {
                $codes = array_merge($codes, $extra);
            }
        }
        $codes = array_values(array_unique($codes));
        usort($codes, static fn (string $a, string $b): int => strnatcmp($a, $b));

        return $codes;
    }

    /**
     * Pré-remplissage d'une non-conformité (ou d'une observation) proposée depuis une règle.
     *
     * @return array{finding_type: string, severity: string}
     */
    public static function findingPreset(int $points): array
    {
        return match (self::severity($points)) {
            self::SEVERITY_HIGH   => ['finding_type' => 'nonconformity', 'severity' => 'major'],
            self::SEVERITY_MEDIUM => ['finding_type' => 'nonconformity', 'severity' => 'minor'],
            default               => ['finding_type' => 'observation', 'severity' => 'minor'],
        };
    }

    /**
     * Comparaison avec l'import précédent du même domaine.
     *
     * @param list<string> $current identifiants des règles de l'import
     * @param list<string>|null $previous identifiants de l'import précédent, null s'il n'y en a pas
     * @return array{new: list<string>, resolved: list<string>, unchanged: list<string>}
     */
    public static function diff(array $current, ?array $previous): array
    {
        $current = array_values(array_unique($current));
        if ($previous === null) {
            return ['new' => [], 'resolved' => [], 'unchanged' => $current];
        }
        $previous = array_values(array_unique($previous));

        return [
            'new'       => array_values(array_diff($current, $previous)),
            'resolved'  => array_values(array_diff($previous, $current)),
            'unchanged' => array_values(array_intersect($current, $previous)),
        ];
    }

    /**
     * Évolution du score global d'un import au suivant : négatif = amélioration.
     */
    public static function trend(int $score, ?int $previous): ?int
    {
        return $previous === null ? null : $score - $previous;
    }

    /**
     * Nom du domaine accepté : lettres, chiffres, tirets et points ; vide sinon.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain) === 1
            ? mb_substr($domain, 0, 255)
            : '';
    }
}
