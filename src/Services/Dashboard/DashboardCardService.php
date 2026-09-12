<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Dashboard;

use Glpi\DBAL\QueryExpression;

/**
 * Data providers for the plugin's `Hooks::DASHBOARD_CARDS` entries (registered in
 * setup.php/hook.php). Each public static method matches the calling convention GLPI's
 * `Glpi\Dashboard\Grid` uses for a card's `provider` callable, one `array $params` argument,
 * returning the shape the chosen native widget (`bigNumber`/`multipleNumber`/`pie`) expects.
 *
 * NOTE: depends on GLPI's runtime global $DB, not unit-tested in isolation, same exclusion
 * rationale as the sibling plugin glpi-vulnerability-manager, see phpstan.neon.dist.
 */
final class DashboardCardService
{
    public static function openRisksCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_plugin_grcmanager_risks',
            'WHERE' => ['status' => ['identified', 'in_treatment']],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Risques ouverts', 'grcmanager'),
            'icon' => 'ti ti-shield-exclamation',
        ];
    }

    public static function risksByLevel(array $params = []): array
    {
        global $DB;

        $countsByLevel = array_fill_keys(['low', 'medium', 'high', 'critical'], 0);

        $rows = $DB->request([
            'SELECT' => ['risk_level', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_risks',
            'GROUPBY' => 'risk_level',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByLevel[$row['risk_level']])) {
                $countsByLevel[$row['risk_level']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByLevel as $level => $count) {
            $data[] = ['label' => $level, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Risques par niveau', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    public static function risksByCategory(array $params = []): array
    {
        global $DB;

        $countsByCategory = array_fill_keys(
            ['people', 'process', 'physical', 'third_party', 'technical'],
            0
        );

        $rows = $DB->request([
            'SELECT' => ['category', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_risks',
            'GROUPBY' => 'category',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByCategory[$row['category']])) {
                $countsByCategory[$row['category']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByCategory as $category => $count) {
            $data[] = ['label' => $category, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Risques par catégorie', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    public static function risksPendingReviewCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_plugin_grcmanager_risks',
            'WHERE' => [
                'status' => ['<>', 'closed'],
                new QueryExpression('(review_date IS NULL OR review_date <= CURDATE())'),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Risques en attente de revue', 'grcmanager'),
            'icon' => 'ti ti-calendar-due',
        ];
    }

    /**
     * SoA completion progress (Sprint 3, clause 6.1.3): "X/93 contrôles revus", a control counts
     * as reviewed as soon as it has been explicitly saved once through the form (see
     * PluginGrcmanagerControl::validateAndMarkReviewed()), regardless of the applicability chosen.
     */
    public static function soaReviewedCount(array $params = []): array
    {
        global $DB;

        $total = (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_plugin_grcmanager_controls',
        ])->current()['c'];

        $reviewed = (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_plugin_grcmanager_controls',
            'WHERE' => ['is_reviewed' => 1],
        ])->current()['c'];

        return [
            'number' => $reviewed,
            'label' => $params['label'] ?? sprintf(
                __('Contrôles SoA revus (%d au total)', 'grcmanager'),
                $total
            ),
            'icon' => 'ti ti-checklist',
        ];
    }

    public static function soaByApplicability(array $params = []): array
    {
        global $DB;

        $countsByApplicability = array_fill_keys(['yes', 'no', 'partial'], 0);

        $rows = $DB->request([
            'SELECT' => ['applicability', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_controls',
            'GROUPBY' => 'applicability',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByApplicability[$row['applicability']])) {
                $countsByApplicability[$row['applicability']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByApplicability as $applicability => $count) {
            $data[] = ['label' => $applicability, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Contrôles SoA par applicabilité', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    public static function soaByImplementationStatus(array $params = []): array
    {
        global $DB;

        $countsByStatus = array_fill_keys(
            ['not_started', 'in_progress', 'implemented', 'verified'],
            0
        );

        $rows = $DB->request([
            'SELECT' => ['implementation_status', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_controls',
            'GROUPBY' => 'implementation_status',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByStatus[$row['implementation_status']])) {
                $countsByStatus[$row['implementation_status']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByStatus as $status => $count) {
            $data[] = ['label' => $status, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Contrôles SoA par état de mise en œuvre', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Sprint 4 (audits internes et CAPA, clause 10.2) : non-conformités encore ouvertes ou en
     * traitement, quel que soit l'audit d'origine.
     *
     * Filtree sur `finding_type = 'nonconformity'` depuis l'issue #27 : le libelle de cette carte
     * annonce explicitement des "non-conformités", elle ne doit donc pas mélanger de simples
     * observations/remarques dans son compte (le point même de l'issue #27 est que le RSSI et
     * l'auditeur ne confondent plus les deux natures de constat dans un même chiffre).
     */
    public static function openNonconformitiesCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_plugin_grcmanager_nonconformities',
            'WHERE' => [
                'status'       => ['open', 'in_progress'],
                'finding_type' => 'nonconformity',
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Non-conformités ouvertes', 'grcmanager'),
            'icon' => 'ti ti-alert-hexagon',
        ];
    }

    /**
     * Actions correctives/préventives en retard : même définition que
     * GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService (échéance dépassée, statut ni
     * clôturée ni vérifiée), pour que la carte de tableau de bord et la tâche Cron ne divergent
     * jamais sur ce qui compte comme "en retard".
     */
    public static function overdueCapaCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_plugin_grcmanager_nonconformities',
            'WHERE' => [
                'status' => ['NOT IN', ['closed', 'verified']],
                new QueryExpression('due_date IS NOT NULL'),
                new QueryExpression('due_date < CURDATE()'),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Actions correctives/préventives en retard', 'grcmanager'),
            'icon' => 'ti ti-calendar-due',
        ];
    }

    public static function auditsByStatus(array $params = []): array
    {
        global $DB;

        $countsByStatus = array_fill_keys(
            ['planned', 'in_progress', 'completed', 'cancelled'],
            0
        );

        $rows = $DB->request([
            'SELECT' => ['status', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_audits',
            'GROUPBY' => 'status',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByStatus[$row['status']])) {
                $countsByStatus[$row['status']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByStatus as $status => $count) {
            $data[] = ['label' => $status, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Audits internes par statut', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Sprint 5 (risques fournisseurs/tiers), same low/medium/high/critical breakdown as
     * risksByLevel() above, for the dedicated supplier risk register table.
     */
    public static function supplierRisksByLevel(array $params = []): array
    {
        global $DB;

        $countsByLevel = array_fill_keys(['low', 'medium', 'high', 'critical'], 0);

        $rows = $DB->request([
            'SELECT' => ['risk_level', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_supplierrisks',
            'GROUPBY' => 'risk_level',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByLevel[$row['risk_level']])) {
                $countsByLevel[$row['risk_level']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByLevel as $level => $count) {
            $data[] = ['label' => $level, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Risques fournisseurs par niveau', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Number of distinct suppliers carrying at least one still-open (not accepted/closed)
     * high/critical supplier risk, the same "open" definition risksByLevel()/openRisksCount()
     * apply to the generic register: `identified`/`in_treatment` count, `accepted`/`closed` don't
     * (an accepted or closed high risk is a risk the organization has already made a documented
     * decision about, not one still needing attention on a dashboard).
     */
    public static function suppliersWithHighRiskCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'SELECT' => [new QueryExpression('COUNT(DISTINCT suppliers_id) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_supplierrisks',
            'WHERE' => [
                'risk_level' => ['high', 'critical'],
                'status'     => ['identified', 'in_treatment'],
                new QueryExpression('suppliers_id > 0'),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __(
                'Fournisseurs avec au moins un risque élevé/critique ouvert',
                'grcmanager'
            ),
            'icon' => 'ti ti-building-warehouse',
        ];
    }

    /**
     * Sprint 6 (formations, clauses 7.2/7.3) : pourcentage de lignes de participation marquées
     * "Terminée" parmi l'ensemble des participations enregistrées, toutes formations confondues.
     * Un participant "Dispensé" (exempted) n'est ni un succès ni un échec de réalisation : exclu du
     * dénominateur, même logique qu'un statut "non applicable" ailleurs dans ce plugin.
     */
    public static function trainingCompletionRate(array $params = []): array
    {
        global $DB;

        $eligible = (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_plugin_grcmanager_trainings_users',
            'WHERE' => ['completion_status' => ['<>', 'exempted']],
        ])->current()['c'];

        $completed = (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_plugin_grcmanager_trainings_users',
            'WHERE' => ['completion_status' => 'completed'],
        ])->current()['c'];

        $rate = $eligible > 0 ? (int) round(($completed / $eligible) * 100) : 0;

        return [
            'number' => $rate,
            'label' => $params['label'] ?? __('Taux de réalisation des formations', 'grcmanager'),
            'icon' => 'ti ti-school',
        ];
    }

    /**
     * Indicateur manquant identifié lors de l'audit complet du plugin (recherche des bonnes
     * pratiques KPI ISO 27001:2022) : distinct de trainingCompletionRate() ci-dessus, qui compte
     * la présence/participation, pas la réussite d'une évaluation. Calculé uniquement sur les
     * participants avec une évaluation renseignée (`assessment_result` != 'not_applicable') : une
     * formation sans évaluation ne doit ni compter comme réussie ni comme échouée, elle est hors
     * périmètre de cet indicateur (voir `PluginGrcmanagerTraining::getAssessmentResults()`).
     */
    public static function trainingPassRate(array $params = []): array
    {
        global $DB;

        $assessed = (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_plugin_grcmanager_trainings_users',
            'WHERE' => ['assessment_result' => ['<>', 'not_applicable']],
        ])->current()['c'];

        $passed = (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_plugin_grcmanager_trainings_users',
            'WHERE' => ['assessment_result' => 'passed'],
        ])->current()['c'];

        $rate = $assessed > 0 ? (int) round(($passed / $assessed) * 100) : 0;

        return [
            'number' => $rate,
            'label' => $params['label'] ?? __('Taux de réussite des formations évaluées', 'grcmanager'),
            'icon' => 'ti ti-certificate',
        ];
    }

    /**
     * Nombre de participants distincts en retard de renouvellement, même définition partagée par
     * PluginGrcmanagerTraining::getOverdueParticipants() et la tâche Cron
     * PluginGrcmanagerTraining::cronRenewaldue(), pour que la carte de tableau de bord et le rappel
     * automatique ne divergent jamais sur ce qui compte comme "en retard".
     */
    public static function trainingOverdueRenewalCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'SELECT'     => [new QueryExpression('COUNT(DISTINCT links.users_id) AS c')],
            'FROM'       => 'glpi_plugin_grcmanager_trainings_users AS links',
            'INNER JOIN' => [
                'glpi_plugin_grcmanager_trainings AS t' => [
                    'FKEY' => ['links' => 'plugin_grcmanager_trainings_id', 't' => 'id'],
                ],
            ],
            'WHERE'      => [
                'links.completion_status'  => 'completed',
                't.renewal_period_months'  => ['>', 0],
                new QueryExpression(
                    'DATE_ADD(links.completion_date, INTERVAL t.renewal_period_months MONTH) < CURDATE()'
                ),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __(
                'Participants en retard de renouvellement de formation',
                'grcmanager'
            ),
            'icon' => 'ti ti-calendar-due',
        ];
    }

    /**
     * Sprint 6 (revues de direction, clause 9.3) : répartition par statut (planifiée/terminée),
     * même schéma que auditsByStatus() ci-dessus.
     */
    public static function managementReviewsByStatus(array $params = []): array
    {
        global $DB;

        $countsByStatus = array_fill_keys(['planned', 'completed'], 0);

        $rows = $DB->request([
            'SELECT' => ['status', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_managementreviews',
            'GROUPBY' => 'status',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByStatus[$row['status']])) {
                $countsByStatus[$row['status']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByStatus as $status => $count) {
            $data[] = ['label' => $status, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Revues de direction par statut', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1) : politiques dont la
     * prochaine date de revue est dépassée ou approche, même définition "dû" que
     * GlpiPlugin\Grcmanager\Services\Policy\PolicyReviewReminderWindow (fenêtre de 30 jours,
     * `archived` exclu), pour que cette carte et la tâche Cron
     * PluginGrcmanagerPolicy::cronReviewreminder() ne divergent jamais sur ce qui compte comme "en
     * attente de revue".
     */
    public static function policiesPendingReviewCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_plugin_grcmanager_policies',
            'WHERE' => [
                'status' => ['<>', 'archived'],
                new QueryExpression('next_review_date IS NOT NULL'),
                new QueryExpression('next_review_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)'),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Politiques en attente de revue', 'grcmanager'),
            'icon' => 'ti ti-calendar-due',
        ];
    }

    public static function policiesByStatus(array $params = []): array
    {
        global $DB;

        $countsByStatus = array_fill_keys(['draft', 'approved', 'archived'], 0);

        $rows = $DB->request([
            'SELECT' => ['status', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_policies',
            'GROUPBY' => 'status',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByStatus[$row['status']])) {
                $countsByStatus[$row['status']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByStatus as $status => $count) {
            $data[] = ['label' => $status, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Politiques de sécurité par statut', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Issue #30 (registre des obligations légales/réglementaires/contractuelles, clause 4.2) :
     * nombre d'obligations actuellement évaluées `non_compliant`, le chiffre qui appelle le plus
     * directement à l'action pour un RSSI/DPO.
     */
    public static function obligationsNonCompliantCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_plugin_grcmanager_complianceobligations',
            'WHERE' => ['compliance_status' => 'non_compliant'],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Obligations non conformes', 'grcmanager'),
            'icon' => 'ti ti-gavel',
        ];
    }

    /**
     * Obligations dont la date de revue est dépassée ou approche, même définition (fenêtre de 30
     * jours) que GlpiPlugin\Grcmanager\Services\Compliance\ComplianceObligationRules::isReviewDue()
     * et la tâche Cron PluginGrcmanagerComplianceObligation::cronReviewreminder(), pour que la
     * carte de tableau de bord et le rappel automatique ne divergent jamais sur ce qui compte comme
     * "en attente de revue". Contrairement à risksPendingReviewCount() ci-dessus, aucune exclusion
     * de statut : `compliance_status` ne dit rien sur si l'obligation est encore suivie, voir
     * ReviewReminderService.
     */
    public static function obligationsPendingReviewCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_plugin_grcmanager_complianceobligations',
            'WHERE' => [
                new QueryExpression('review_date IS NOT NULL'),
                new QueryExpression('review_date <= CURDATE()'),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Obligations en attente de revue', 'grcmanager'),
            'icon' => 'ti ti-calendar-due',
        ];
    }

    public static function obligationsByType(array $params = []): array
    {
        global $DB;

        $countsByType = array_fill_keys(['legal', 'regulatory', 'contractual'], 0);

        $rows = $DB->request([
            'SELECT' => ['type', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_complianceobligations',
            'GROUPBY' => 'type',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByType[$row['type']])) {
                $countsByType[$row['type']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByType as $type => $count) {
            $data[] = ['label' => $type, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Obligations par type', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    public static function obligationsByComplianceStatus(array $params = []): array
    {
        global $DB;

        $countsByStatus = array_fill_keys(
            ['compliant', 'partially_compliant', 'non_compliant', 'not_assessed'],
            0
        );

        $rows = $DB->request([
            'SELECT' => ['compliance_status', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_complianceobligations',
            'GROUPBY' => 'compliance_status',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByStatus[$row['compliance_status']])) {
                $countsByStatus[$row['compliance_status']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByStatus as $status => $count) {
            $data[] = ['label' => $status, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Obligations par statut de conformité', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Issue #29 (registre des incidents de sécurité de l'information, A.5.24-27) : répartition par
     * statut. Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : `status` est
     * désormais le statut ITIL entier hérité de CommonITILObject (INCOMING/ASSIGNED/PLANNED/
     * WAITING/SOLVED/CLOSED), plus l'ancien statut texte (open/investigating/contained/closed) du
     * registre CommonDBTM d'origine - les libellés viennent directement de
     * PluginGrcmanagerSecurityIncident::getAllStatusArray() pour ne jamais dupliquer un second
     * vocabulaire de statuts.
     */
    public static function securityIncidentsByStatus(array $params = []): array
    {
        global $DB;

        $labels = \PluginGrcmanagerSecurityIncident::getAllStatusArray();
        $countsByStatus = array_fill_keys(array_keys($labels), 0);

        $rows = $DB->request([
            'SELECT' => ['status', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_securityincidents',
            'GROUPBY' => 'status',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByStatus[$row['status']])) {
                $countsByStatus[$row['status']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByStatus as $status => $count) {
            $data[] = ['label' => $labels[$status] ?? (string) $status, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Incidents de sécurité par statut', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Issue #29 : répartition par sévérité (mineure/majeure/critique), même échelle que
     * PluginGrcmanagerNonconformity::getSeverities() et même schéma de carte que
     * risksByLevel()/supplierRisksByLevel() ci-dessus.
     */
    public static function securityIncidentsBySeverity(array $params = []): array
    {
        global $DB;

        $countsBySeverity = array_fill_keys(['minor', 'major', 'critical'], 0);

        $rows = $DB->request([
            'SELECT' => ['severity', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_securityincidents',
            'GROUPBY' => 'severity',
        ]);

        foreach ($rows as $row) {
            if (isset($countsBySeverity[$row['severity']])) {
                $countsBySeverity[$row['severity']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsBySeverity as $severity => $count) {
            $data[] = ['label' => $severity, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Incidents de sécurité par sévérité', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }

    /**
     * Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : délai moyen de réponse
     * (heures entre la création `date` et la résolution `solvedate`), calculé uniquement sur les
     * incidents effectivement résolus (`solvedate` renseigné) — un incident encore ouvert n'a pas
     * de délai à mesurer, l'inclure avec une valeur nulle/zéro fausserait la moyenne à la baisse.
     * Absent des indicateurs déjà existants (par statut/sévérité) : ceux-ci comptent, celui-ci
     * mesure un temps, un indicateur ISO 27001 A.5.24-27 classique ("temps de réponse") qui
     * n'a pas d'équivalent dans les cartes déjà portées depuis le plugin absorbé.
     */
    public static function securityIncidentResponseTimeHours(array $params = []): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => [
                new QueryExpression(
                    'AVG(TIMESTAMPDIFF(HOUR, `date`, `solvedate`)) AS avg_hours'
                ),
            ],
            'FROM'  => 'glpi_plugin_grcmanager_securityincidents',
            'WHERE' => [
                new QueryExpression('solvedate IS NOT NULL'),
            ],
        ])->current();

        $avgHours = $row['avg_hours'] !== null ? (int) round((float) $row['avg_hours']) : 0;

        return [
            'number' => $avgHours,
            'label'  => $params['label'] ?? __('Délai moyen de réponse aux incidents (heures)', 'grcmanager'),
            'icon'   => 'ti ti-clock-hour-4',
        ];
    }

    /**
     * Issue #31 (plan d'action de traitement des risques, clause 8.3/6.1.3) : même définition "en
     * retard" que GlpiPlugin\Grcmanager\Services\Risk\TreatmentPlanRules::isOverdue() et la tâche
     * Cron PluginGrcmanagerRiskTreatmentAction::cronOverduetreatmentaction() (échéance dépassée,
     * statut différent de "done"), pour que cette carte et le rappel automatique ne divergent
     * jamais sur ce qui compte comme "en retard", même schéma que overdueCapaCount() ci-dessus.
     */
    public static function overdueTreatmentActionsCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'COUNT' => 'c',
            'FROM' => 'glpi_plugin_grcmanager_risktreatmentactions',
            'WHERE' => [
                'status' => ['<>', 'done'],
                new QueryExpression('due_date IS NOT NULL'),
                new QueryExpression('due_date < CURDATE()'),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __('Actions de traitement de risque en retard', 'grcmanager'),
            'icon' => 'ti ti-calendar-due',
        ];
    }

    /**
     * Issue #31 : le "gap" que ce sprint cherche précisément à révéler - un risque encore ouvert
     * dont la décision de traitement est "mitiger"/"transférer" (donc censée être mise en œuvre,
     * voir TreatmentPlanRules::isTreatmentPlanRelevant()) mais qui n'a encore AUCUNE action de
     * traitement enregistrée : une décision prise, sans plan derrière. Un risque déjà `closed` est
     * exclu (voir PluginGrcmanagerRisk::validateTreatmentPlanAndComputeRiskLevel(), qui empêche
     * désormais de clôturer un tel risque sans au moins une action, donc plus rien à signaler ici
     * une fois clôturé), même définition "ouvert" que openRisksCount() ci-dessus.
     */
    public static function risksMissingTreatmentPlanCount(array $params = []): array
    {
        global $DB;

        $count = (int) $DB->request([
            'SELECT' => [new QueryExpression('COUNT(DISTINCT r.id) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_risks AS r',
            'LEFT JOIN' => [
                'glpi_plugin_grcmanager_risktreatmentactions AS a' => [
                    'FKEY' => ['r' => 'id', 'a' => 'plugin_grcmanager_risks_id'],
                ],
            ],
            'WHERE' => [
                'r.treatment' => ['mitigate', 'transfer'],
                'r.status'    => ['identified', 'in_treatment'],
                new QueryExpression('a.id IS NULL'),
            ],
        ])->current()['c'];

        return [
            'number' => $count,
            'label' => $params['label'] ?? __(
                'Risques à mitiger/transférer sans plan de traitement',
                'grcmanager'
            ),
            'icon' => 'ti ti-list-details',
        ];
    }

    /**
     * Issue #32 (objectifs ISMS et suivi de KPI dans le temps, clause 6.2) : répartition par
     * statut (non démarré/sur la bonne voie/à risque/atteint/manqué), même schéma que
     * managementReviewsByStatus()/auditsByStatus() ci-dessus. Le status enum
     * (GlpiPlugin\Grcmanager\Services\Objective\ObjectiveStatuses) n'est pas réutilisé ici pour
     * garder ce fichier hors du périmètre GLPI-indépendant de phpstan.neon.dist (voir sa propre
     * note), les valeurs sont dupliquées littéralement comme le fait déjà auditsByStatus() pour
     * son propre enum de statuts.
     */
    public static function objectivesByStatus(array $params = []): array
    {
        global $DB;

        $countsByStatus = array_fill_keys(
            ['not_started', 'on_track', 'at_risk', 'achieved', 'missed'],
            0
        );

        $rows = $DB->request([
            'SELECT' => ['status', new QueryExpression('COUNT(*) AS c')],
            'FROM' => 'glpi_plugin_grcmanager_objectives',
            'GROUPBY' => 'status',
        ]);

        foreach ($rows as $row) {
            if (isset($countsByStatus[$row['status']])) {
                $countsByStatus[$row['status']] = (int) $row['c'];
            }
        }

        $data = [];
        foreach ($countsByStatus as $status => $count) {
            $data[] = ['label' => $status, 'number' => $count];
        }

        return [
            'data' => $data,
            'label' => $params['label'] ?? __('Objectifs ISMS par statut', 'grcmanager'),
            'icon' => 'ti ti-chart-pie',
        ];
    }
}
