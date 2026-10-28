<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Dashboard;

use Glpi\Dashboard\Dashboard;

/**
 * Sprint 7 (tableaux de bord, consolidation) : tableau de bord natif GLPI "prêt à l'emploi",
 * seedé à l'installation, pour qu'une installation fraîche affiche d'emblée une vue d'ensemble
 * ISMS au lieu d'un sélecteur « Ajouter une carte » vide (voir DEVELOPMENT_PLAN.md, Sprint 7).
 * Reprend telles quelles les 15 cartes déjà posées aux Sprints 1 à 6 (voir
 * hook.php::plugin_grcmanager_dashboard_cards() / DashboardCardService) : aucune carte
 * « résumé » supplémentaire n'a été ajoutée pour ce sprint, ce tableau de bord EST la vue de
 * synthèse qui les relie entre elles. Une 16e carte (« Objectifs ISMS par statut ») a depuis été
 * ajoutée par l'issue #32, suivant exactement ce même principe de report direct dans la grille.
 *
 * Issue #30 (registre des obligations légales/réglementaires/contractuelles) ajoute 4 nouvelles
 * cartes à ce tableau de bord seedé (2 chiffres clés en rangée 2, 2 répartitions en rangées 4/5),
 * la première évolution de ce tableau de bord depuis le Sprint 7 lui-même.
 *
 * Issue #29 (registre des incidents de sécurité de l'information, A.5.24-27) et issue #31 (plan
 * d'action de traitement des risques, clause 8.3/6.1.3), développées en parallèle, ont chacune
 * ajouté 2 cartes à une même rangée 6 neuve (y=16) plutôt que de réutiliser un `y` déjà occupé : la
 * grille native GLPI ne détecte aucune collision automatiquement (deux items avec les mêmes
 * coordonnées se superposent silencieusement). Les deux issues ayant chacune commencé leurs cartes
 * à x=0 sur cette même rangée, la fusion des deux branches les a repositionnées côte à côte plutôt
 * que superposées (issue #29 en x=0..12, issue #31 en x=12..20, voir getItems() ci-dessous) - tout
 * nouvel ajout futur à ce tableau de bord doit choisir un `y` strictement après le maximum déjà
 * utilisé, ou vérifier l'occupation en x d'une rangée existante avant d'y ajouter une carte.
 *
 * Construit avec l'API native `Glpi\Dashboard\Dashboard` (mêmes méthodes que celles appelées par
 * l'écran natif Configuration > Tableaux de bord : save()/saveItems(), voir
 * src/Glpi/Dashboard/Dashboard.php du cœur GLPI 11) plutôt qu'une insertion SQL directe dans
 * glpi_dashboards_dashboards/glpi_dashboards_items, pour ne jamais diverger du schéma interne
 * (card_options JSON-encodé, etc.) que l'écran natif produit lui-même.
 *
 * `users_id` forcé à 0, jamais `Dashboard::saveNew()` (qui l'aurait mis à l'utilisateur de la
 * session en cours, aucun utilisateur particulier lors d'une installation en CLI de toute façon) :
 * c'est ce qui rend ce tableau de bord visible par tout le monde disposant du droit natif
 * "dashboard", exactement comme les tableaux de bord "Central"/"Parc"/"Assistance" fournis par le
 * cœur GLPI (voir `Dashboard::getDefaults()`, même valeur users_id=0, aucune ligne
 * glpi_dashboards_rights). Confirmé en lisant `Dashboard::canViewCurrent()` : un tableau de bord
 * avec users_id=0 passe par `self::canView() && !$this->isPrivate()`, jamais par les droits de
 * partage détaillés (glpi_dashboards_rights) réservés aux tableaux de bord privés partagés à la
 * main, donc aucune ligne de droits à seeder ici.
 *
 * Idempotent comme le reste de `Installer.php` : `key` fixe (pas de slug dérivé du titre traduit,
 * qui changerait avec la langue), `Dashboard::save()` fait un vrai upsert sur cette colonne
 * (`$DB->updateOrInsert(..., ['key' => $this->key])`), et `saveItems()` supprime puis réinsère
 * les items à chaque appel : ré-exécuter `seed()` (upgrade, `plugin:install --force`) ne duplique
 * jamais rien. Contrepartie assumée (voir TECH_DEBT.md) : un administrateur qui personnalise
 * ensuite ce tableau de bord depuis l'écran natif Tableaux de bord verra ses changements écrasés
 * au prochain upgrade du plugin, comme n'importe quelle configuration par défaut réinitialisée à
 * chaque mise à jour.
 */
final class DefaultDashboardService
{
    public const KEY = 'grcmanager-isms-overview';

    /**
     * Issue #143 : tableau de bord « Direction », simplifié, en plus de la vue RSSI ci-dessus.
     */
    public const DIRECTION_KEY = 'grcmanager-direction';

    public static function seed(): void
    {
        self::upsert(self::KEY, __('GRC Manager - ISMS overview', 'grcmanager'), self::getItems());
        self::upsert(self::DIRECTION_KEY, __('GRC Manager - Management', 'grcmanager'), self::getDirectionItems());
    }

    public static function remove(): void
    {
        foreach ([self::KEY, self::DIRECTION_KEY] as $key) {
            (new Dashboard())->delete(['key' => $key], true);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function upsert(string $key, string $name, array $items): void
    {
        global $DB;

        // Dashboard::$key is protected, and Dashboard::saveNew() forces users_id to the current
        // session's user (0 for a CLI install, but the installing admin's own ID if triggered from
        // the web UI, which would make the dashboard private to them, see class docblock above).
        // Upserting the row directly with users_id=0 (same query shape as Dashboard::save(),
        // Dashboard::getTable() so this can never diverge from the real table name) sidesteps
        // both restrictions, then Dashboard::getFromDB()/saveItems() (both public) take over for
        // the items, exactly the calls the native dashboard editor itself would make.
        $DB->updateOrInsert(
            Dashboard::getTable(),
            [
                'key'      => $key,
                'name'     => $name,
                'context'  => 'core',
                'users_id' => 0,
            ],
            ['key' => $key]
        );

        $dashboard = new Dashboard();
        if ($dashboard->getFromDB($key)) {
            $dashboard->saveItems($items);
        }
    }

    /**
     * Une carte graphique (barres, courbes) : mêmes options que les répartitions ci-dessous.
     *
     * @return array<string, mixed>
     */
    private static function chart(string $cardId, string $widget, int $x, int $y, int $width, int $height): array
    {
        return [
            'gridstack_id' => $cardId . '_default',
            'card_id'      => $cardId,
            'x'            => $x,
            'y'            => $y,
            'width'        => $width,
            'height'       => $height,
            'card_options' => [
                'color'        => '#f9fafb',
                'widgettype'   => $widget,
                'use_gradient' => '1',
            ],
        ];
    }

    /**
     * Vue « Direction » (issue #143) : peu de cartes, les plus parlantes pour un comité de direction
     * (score de maturité, conformité par référentiel, tendance des risques et non-conformités,
     * échéances et retards), sur trois rangées.
     *
     * @return list<array<string, mixed>>
     */
    private static function getDirectionItems(): array
    {
        $isms = [];
        foreach (self::getItems() as $item) {
            $isms[$item['card_id']] = $item;
        }
        $bigNumber = static function (string $cardId, int $x, int $y, int $width = 4) use ($isms): array {
            $color = $isms[$cardId]['card_options']['color'] ?? '#0e87a0';

            return [
                'gridstack_id' => $cardId . '_direction',
                'card_id'      => $cardId,
                'x'            => $x,
                'y'            => $y,
                'width'        => $width,
                'height'       => 2,
                'card_options' => ['color' => $color, 'widgettype' => 'bigNumber', 'use_gradient' => '0'],
            ];
        };

        return [
            $bigNumber('grcmanager_maturity_score', 0, 0),
            $bigNumber('grcmanager_open_risks', 4, 0),
            $bigNumber('grcmanager_open_nonconformities', 8, 0, 5),
            $bigNumber('grcmanager_overdue_capa', 13, 0, 5),
            $bigNumber('grcmanager_security_incident_regulatory_deadlines', 18, 0, 6),
            self::chart('grcmanager_compliance_by_framework', 'hbar', 0, 2, 12, 6),
            self::chart('grcmanager_monthly_trend', 'lines', 12, 2, 12, 6),
            $bigNumber('grcmanager_upcoming_compliance_deadlines', 0, 8, 6),
            $bigNumber('grcmanager_evidences_to_renew', 6, 8, 6),
            $bigNumber('grcmanager_critical_suppliers_without_assessment', 12, 8, 6),
            $bigNumber('grcmanager_periodic_reviews_overdue', 18, 8, 6),
        ];
    }

    /**
     * Disposition sur la grille native (26 colonnes de large par défaut) : une première rangée de
     * chiffres clés (bigNumber, ceux qui appellent le plus directement à l'action : risques
     * ouverts/en attente de revue, non-conformités ouvertes, CAPA en retard, fournisseurs à
     * risque, renouvellements de formation en retard), une deuxième rangée de progression
     * (contrôles SoA revus, taux de réalisation des formations), puis les répartitions
     * (pie/donut) qui détaillent chaque registre.
     */
    private static function getItems(): array
    {
        $bigNumber = static function (
            string $cardId,
            int $x,
            int $y,
            string $color,
            int $width = 4
        ): array {
            return [
                'gridstack_id' => $cardId . '_default',
                'card_id'      => $cardId,
                'x'            => $x,
                'y'            => $y,
                'width'        => $width,
                'height'       => 2,
                'card_options' => [
                    'color'        => $color,
                    'widgettype'   => 'bigNumber',
                    'use_gradient' => '0',
                ],
            ];
        };

        $pie = static function (string $cardId, int $x, int $y): array {
            return [
                'gridstack_id' => $cardId . '_default',
                'card_id'      => $cardId,
                'x'            => $x,
                'y'            => $y,
                'width'        => 6,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#f9fafb',
                    'widgettype'   => 'pie',
                    'use_gradient' => '1',
                ],
            ];
        };

        return [
            // Rangée 1 (y=0) : chiffres clés appelant à l'action.
            $bigNumber('grcmanager_open_risks', 0, 0, '#e69393'),
            $bigNumber('grcmanager_risks_pending_review', 4, 0, '#f8911f'),
            $bigNumber('grcmanager_open_nonconformities', 8, 0, '#b52d30'),
            $bigNumber('grcmanager_overdue_capa', 12, 0, '#f08d7b'),
            $bigNumber('grcmanager_suppliers_with_high_risk', 16, 0, '#8e5ea2'),
            $bigNumber('grcmanager_training_overdue_renewal', 20, 0, '#ffdc64'),
            // Rangée 2 (y=2) : progression, complétée par les 2 chiffres clés de l'issue #30
            // (obligations légales/réglementaires/contractuelles).
            $bigNumber('grcmanager_soa_reviewed', 0, 2, '#0e87a0', 6),
            $bigNumber('grcmanager_training_completion_rate', 6, 2, '#27ab3c', 6),
            $bigNumber('grcmanager_obligations_non_compliant', 12, 2, '#cc2936', 6),
            $bigNumber('grcmanager_obligations_pending_review', 18, 2, '#f8911f', 6),
            // Rangée 3 (y=4) : répartitions, registre de risques et SoA.
            $pie('grcmanager_risks_by_level', 0, 4),
            $pie('grcmanager_risks_by_category', 6, 4),
            $pie('grcmanager_soa_by_applicability', 12, 4),
            $pie('grcmanager_soa_by_implementation_status', 18, 4),
            // Rangée 4 (y=8) : répartitions, audits/CAPA, fournisseurs, revues de direction,
            // politiques (issue #28).
            $pie('grcmanager_audits_by_status', 0, 8),
            $pie('grcmanager_supplierrisks_by_level', 6, 8),
            $pie('grcmanager_management_reviews_by_status', 12, 8),
            $pie('grcmanager_policies_by_status', 18, 8),
            // Rangée 5 (y=12) : obligations (issue #30), politiques en attente de revue (issue
            // #28), objectifs ISMS (issue #32).
            $pie('grcmanager_obligations_by_type', 0, 12),
            $pie('grcmanager_obligations_by_compliance_status', 6, 12),
            $pie('grcmanager_objectives_by_status', 12, 12),
            $bigNumber('grcmanager_policies_pending_review', 18, 12, '#f8911f'),
            // Rangée 6 (y=16) : incidents de sécurité de l'information (issue #29, x=0..12) suivis
            // du plan d'action de traitement des risques (issue #31, x=12..20) - deux issues
            // développées en parallèle avaient chacune choisi cette même rangée neuve en partant de
            // x=0 ; fusionnées côte à côte plutôt que superposées (voir le docblock de cette classe).
            $pie('grcmanager_security_incidents_by_status', 0, 16),
            $pie('grcmanager_security_incidents_by_severity', 6, 16),
            $bigNumber('grcmanager_overdue_treatment_actions', 12, 16, '#cc2936'),
            $bigNumber('grcmanager_risks_missing_treatment_plan', 16, 16, '#f8911f'),
            // Rangée 7 (y=20) : cartes de pilotage (issue #143), conformité par référentiel puis
            // échéances, revue des accès, délais réglementaires et revues périodiques.
            self::chart('grcmanager_compliance_by_framework', 'hbar', 0, 20, 8, 4),
            $bigNumber('grcmanager_upcoming_compliance_deadlines', 8, 20, '#0e87a0'),
            $bigNumber('grcmanager_access_review_progress', 12, 20, '#27ab3c'),
            $bigNumber('grcmanager_security_incident_regulatory_deadlines', 16, 20, '#cc2936'),
            $bigNumber('grcmanager_periodic_reviews_overdue', 20, 20, '#f8911f'),
            $bigNumber('grcmanager_supplier_questionnaire_average', 8, 22, '#8e5ea2'),
            $bigNumber('grcmanager_critical_suppliers_without_assessment', 12, 22, '#cc2936'),
            $bigNumber('grcmanager_access_review_late_reviewers', 16, 22, '#f8911f'),
            $bigNumber('grcmanager_training_retake_participants', 20, 22, '#ffdc64'),
            // Rangée 8 (y=24) : tendance mensuelle pour la revue de direction et réussite par
            // session de formation (issue #143).
            self::chart('grcmanager_monthly_trend', 'lines', 0, 24, 12, 6),
            self::chart('grcmanager_training_pass_rate_by_session', 'hbar', 12, 24, 12, 6),
        ];
    }
}
