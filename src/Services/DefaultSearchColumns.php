<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services;

/**
 * Default search-result columns per admin itemtype, keyed by class name (search option IDs from
 * each class's own rawSearchOptions()). Passed explicitly as `Search::showList()`'s
 * `$forcedisplay` by front/risk.php, and also seeded once into `glpi_displaypreferences` at
 * install (see Installer::seedDisplayPreferences()) so the "colonnes a afficher" GLPI dialog
 * starts from a sensible, self-explanatory default rather than an empty list, matching the
 * lesson learned on the sibling plugin glpi-vulnerability-manager (see its own
 * DefaultSearchColumns.php docblock and TECH_DEBT.md).
 */
final class DefaultSearchColumns
{
    public const COLUMNS = [
        'PluginGrcmanagerRisk'    => [10, 1, 2, 5, 3, 4, 6, 7, 8, 9],
        // Sprint 3 (SoA) : id-then-theme-then-code first, same "clickable row first" convention
        // as the risk register above.
        'PluginGrcmanagerControl' => [8, 2, 1, 3, 4, 6, 7],
        // Sprint 4 (audits internes et CAPA), same "id first" convention.
        'PluginGrcmanagerAudit'         => [9, 1, 2, 3, 4, 5, 6],
        // Issue #27 : id 13 (finding_type) juste apres le titre, avant severite (2), pour que le
        // type de constat (non-conformite/observation) soit visible d'emblee dans la liste.
        'PluginGrcmanagerNonconformity' => [12, 1, 13, 2, 3, 4, 5, 7],
        // Sprint 5 (risques fournisseurs/tiers), same "id first" convention, supplier column
        // second (the whole point of this register) then the same scoring/treatment order as
        // PluginGrcmanagerRisk above.
        'PluginGrcmanagerSupplierRisk'  => [11, 1, 2, 3, 6, 4, 5, 7, 8, 9],
        // Sprint 6 (formations et revues de direction), same "id first" convention.
        'PluginGrcmanagerTraining'         => [8, 1, 2, 3, 4, 5, 6],
        'PluginGrcmanagerManagementReview' => [6, 1, 2, 3, 4, 5],
        // Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1), same "id first"
        // convention.
        'PluginGrcmanagerPolicy'           => [8, 1, 2, 3, 4, 5, 6],
        // Issue #30 (registre des obligations légales/réglementaires/contractuelles), same
        // "id first" convention.
        'PluginGrcmanagerComplianceObligation' => [9, 1, 2, 3, 4, 5, 6, 7],
        // Issue #32 (objectifs ISMS et suivi de KPI dans le temps), same "id first" convention:
        // titre, statut, échéance, valeur cible, propriétaire.
        'PluginGrcmanagerObjective' => [8, 1, 2, 3, 4, 6],
        // Issue #29 (registre des incidents de sécurité de l'information, A.5.24-27), same
        // "id first" convention: titre, catégorie, sévérité, statut, date, responsable.
        'PluginGrcmanagerSecurityIncident' => [13, 1, 2, 3, 4, 5, 6],
        // Corrélation CPE des CVE avec le parc GLPI (cf. ROADMAP.md) : seulement 2 colonnes
        // (éditeur, produit), pas de convention "id first" à respecter faute de colonne ID dédiée
        // — même choix que le CanonicalProduct du plugin jumeau glpi-vulnerability-manager, qui n'a
        // lui non plus pas de colonne de titre naturelle.
        'PluginGrcmanagerCanonicalProduct' => [1, 2],
    ];
}
