<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Clause;

/**
 * Issue #113 : exigences du système de management (ISO/IEC 27001:2022, articles 4 à 10), suivies
 * sous-article par sous-article. Seuls les numéros sont stockés ici ; les titres affichés sont des
 * titres courts rédigés par ce projet (PluginGrcmanagerClause::getClauseTitles()), JAMAIS le texte
 * des exigences de la norme — même règle que pour l'Annexe A, voir la section « Avertissement »
 * du README.
 *
 * Granularité : le niveau le plus fin numéroté par la norme (6.1.1 à 6.1.3, 7.5.1 à 7.5.3, 9.2.1,
 * 9.2.2, 9.3.1 à 9.3.3), soit 30 sous-articles. `module` désigne l'écran du plugin qui produit
 * déjà les preuves de ce sous-article (lien automatique sur la fiche), null s'il n'y en a pas.
 */
final class IsmsClauseCatalog
{
    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLIANT   = 'compliant';

    public const STATUSES = [self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLIANT];

    /** @var list<string> Articles de premier niveau couverts. */
    public const CLAUSES = ['4', '5', '6', '7', '8', '9', '10'];

    /**
     * @var array<string, array{clause: string, module: string|null}>
     */
    public const SUBCLAUSES = [
        '4.1'   => ['clause' => '4', 'module' => null],
        '4.2'   => ['clause' => '4', 'module' => 'GlpiPlugin\\Grcmanager\\ComplianceObligation'],
        '4.3'   => ['clause' => '4', 'module' => null],
        '4.4'   => ['clause' => '4', 'module' => null],
        '5.1'   => ['clause' => '5', 'module' => 'GlpiPlugin\\Grcmanager\\ManagementReview'],
        '5.2'   => ['clause' => '5', 'module' => 'GlpiPlugin\\Grcmanager\\Policy'],
        '5.3'   => ['clause' => '5', 'module' => null],
        '6.1.1' => ['clause' => '6', 'module' => 'GlpiPlugin\\Grcmanager\\Risk'],
        '6.1.2' => ['clause' => '6', 'module' => 'GlpiPlugin\\Grcmanager\\Risk'],
        '6.1.3' => ['clause' => '6', 'module' => 'PluginGrcmanagerControl'],
        '6.2'   => ['clause' => '6', 'module' => 'PluginGrcmanagerObjective'],
        '6.3'   => ['clause' => '6', 'module' => null],
        '7.1'   => ['clause' => '7', 'module' => null],
        '7.2'   => ['clause' => '7', 'module' => 'PluginGrcmanagerTraining'],
        '7.3'   => ['clause' => '7', 'module' => 'PluginGrcmanagerTraining'],
        '7.4'   => ['clause' => '7', 'module' => null],
        '7.5.1' => ['clause' => '7', 'module' => 'GlpiPlugin\\Grcmanager\\Policy'],
        '7.5.2' => ['clause' => '7', 'module' => 'GlpiPlugin\\Grcmanager\\Policy'],
        '7.5.3' => ['clause' => '7', 'module' => 'GlpiPlugin\\Grcmanager\\Policy'],
        '8.1'   => ['clause' => '8', 'module' => 'GlpiPlugin\\Grcmanager\\SupplierRisk'],
        '8.2'   => ['clause' => '8', 'module' => 'GlpiPlugin\\Grcmanager\\Risk'],
        '8.3'   => ['clause' => '8', 'module' => 'GlpiPlugin\\Grcmanager\\Risk'],
        '9.1'   => ['clause' => '9', 'module' => 'PluginGrcmanagerObjective'],
        '9.2.1' => ['clause' => '9', 'module' => 'GlpiPlugin\\Grcmanager\\Audit'],
        '9.2.2' => ['clause' => '9', 'module' => 'GlpiPlugin\\Grcmanager\\Audit'],
        '9.3.1' => ['clause' => '9', 'module' => 'GlpiPlugin\\Grcmanager\\ManagementReview'],
        '9.3.2' => ['clause' => '9', 'module' => 'GlpiPlugin\\Grcmanager\\ManagementReview'],
        '9.3.3' => ['clause' => '9', 'module' => 'GlpiPlugin\\Grcmanager\\ManagementReview'],
        '10.1'  => ['clause' => '10', 'module' => null],
        '10.2'  => ['clause' => '10', 'module' => 'GlpiPlugin\\Grcmanager\\Nonconformity'],
    ];

    /**
     * Comptage par statut et taux de conformité (en %, arrondi à l'entier) pour la barre de
     * complétude de l'écran des clauses et le rapport PDF. Un statut inconnu compte comme « non
     * commencé » : une donnée corrompue ne doit jamais gonfler le taux affiché.
     *
     * @param list<string> $statuses un statut par sous-article
     * @return array{not_started: int, in_progress: int, compliant: int, total: int, percent: int}
     */
    public static function completion(array $statuses): array
    {
        $counts = [self::STATUS_NOT_STARTED => 0, self::STATUS_IN_PROGRESS => 0, self::STATUS_COMPLIANT => 0];
        foreach ($statuses as $status) {
            $counts[in_array($status, self::STATUSES, true) ? $status : self::STATUS_NOT_STARTED]++;
        }
        $total = count($statuses);

        return $counts + [
            'total'   => $total,
            'percent' => $total === 0 ? 0 : (int) round(100 * $counts[self::STATUS_COMPLIANT] / $total),
        ];
    }

    /** Tri naturel des numéros (« 10.1 » après « 9.3.3 », « 6.1.2 » avant « 6.2 »). */
    public static function compareCodes(string $a, string $b): int
    {
        return version_compare($a, $b);
    }
}
