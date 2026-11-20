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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;
use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as Rules;
use Html;
use Session;

/**
 * Issue #619 : préparation à la certification ISO 27001. Pour chaque étape du parcours (planifier,
 * faire, vérifier, agir), un état calculé à partir des données du plugin (fait, partiel, à faire),
 * ce qui est attendu et le lien vers l'écran à compléter, avec un score global et un export PDF.
 *
 * - Les calculs viennent des modules eux-mêmes : contrôles de cohérence (ConsistencyCheck::run()),
 *   synthèse du contexte, matrice des responsabilités, couverture du programme d'audit, dossier de
 *   la revue de direction, approbation de la SoA, taux de réalisation des formations.
 * - Lecture seule : rien n'est modifié ni enregistré.
 * - Droits : une étape n'est évaluée que si la personne peut lire le module concerné, dans les
 *   entités de sa session ; sinon elle apparaît « hors de vos droits » et ne compte pas au score.
 */
class CertificationReadiness extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Certification readiness', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-certificate';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/certificationreadiness.php';
    }

    public static function getPdfURL(): string
    {
        return self::getSearchURL() . '?format=pdf';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    /**
     * Sous-page de la rubrique « Tableau de bord » (Menu::sections()), jamais une entrée de menu à
     * part entière.
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, array{label: string, summary: string, icon: string}>
     */
    public static function getPhases(): array
    {
        return [
            'plan'  => [
                'label'   => __('Plan', 'grcmanager'),
                'summary' => __('Set up the management system: scope, context, policy, roles, risks.', 'grcmanager'),
                'icon'    => 'ti-map-2',
            ],
            'do'    => [
                'label'   => __('Do', 'grcmanager'),
                'summary' => __('Run it day to day: people trained, exercises carried out.', 'grcmanager'),
                'icon'    => 'ti-player-play',
            ],
            'check' => [
                'label'   => __('Check', 'grcmanager'),
                'summary' => __('Evaluate it: internal audit and management review.', 'grcmanager'),
                'icon'    => 'ti-zoom-check',
            ],
            'act'   => [
                'label'   => __('Act', 'grcmanager'),
                'summary' => __('Improve it: treat what the checks have found.', 'grcmanager'),
                'icon'    => 'ti-refresh',
            ],
        ];
    }

    /**
     * Styles des états : badges contrastés, icône Tabler.
     *
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getStatusStyles(): array
    {
        return [
            Rules::STATUS_DONE    => [
                'label' => __('Done', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-circle-check',
            ],
            Rules::STATUS_PARTIAL => [
                'label' => __('Partly done', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-progress',
            ],
            Rules::STATUS_TODO    => [
                'label' => __('To do', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-circle-dashed',
            ],
            Rules::STATUS_LOCKED  => [
                'label' => __('Outside your rights', 'grcmanager'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-lock',
            ],
        ];
    }

    /**
     * Titre et attendu de chaque étape, avec nos mots (pas le texte de la norme).
     *
     * @return array<string, array{title: string, expected: string, icon: string}>
     */
    public static function getStepTexts(): array
    {
        return [
            'scope'             => [
                'title'    => __('Define the scope of the ISMS', 'grcmanager'),
                'expected' => __(
                    'Write down which sites, activities, systems and teams the management system covers, and what '
                        . 'is left out, then attach this document to requirement 4.3.',
                    'grcmanager'
                ),
                'icon'     => 'ti-border-outer',
            ],
            'context'           => [
                'title'    => __('Describe the context and the interested parties', 'grcmanager'),
                'expected' => __(
                    'List the internal and external issues and the interested parties with their expectations, '
                        . 'and decide whether climate change matters for you.',
                    'grcmanager'
                ),
                'icon'     => 'ti-world',
            ],
            'policy'            => [
                'title'    => __('Approve the information security policy', 'grcmanager'),
                'expected' => __(
                    'Have management approve the security policy and set the date of its next review.',
                    'grcmanager'
                ),
                'icon'     => 'ti-file-certificate',
            ],
            'roles'             => [
                'title'    => __('Assign the roles and responsibilities', 'grcmanager'),
                'expected' => __(
                    'For each activity of the management system, name who carries it out, who is accountable, and '
                        . 'who is consulted or informed.',
                    'grcmanager'
                ),
                'icon'     => 'ti-users-group',
            ],
            'risk_assessment'   => [
                'title'    => __('Assess the risks', 'grcmanager'),
                'expected' => __(
                    'Identify the risks, give each one an owner, rate them with your method and keep their review '
                        . 'dates up to date.',
                    'grcmanager'
                ),
                'icon'     => 'ti-alert-triangle',
            ],
            'risk_treatment'    => [
                'title'    => __('Decide how to treat the risks', 'grcmanager'),
                'expected' => __(
                    'Choose a treatment for each significant risk, have accepted risks approved, and follow up the '
                        . 'treatment actions until they are done.',
                    'grcmanager'
                ),
                'icon'     => 'ti-shield-check',
            ],
            'soa'               => [
                'title'    => __('Approve the Statement of Applicability', 'grcmanager'),
                'expected' => __(
                    'Justify whether each control applies, then have the Statement of Applicability approved; '
                        . 'approve it again after any change.',
                    'grcmanager'
                ),
                'icon'     => 'ti-list-check',
            ],
            'objectives'        => [
                'title'    => __('Set measurable security objectives', 'grcmanager'),
                'expected' => __(
                    'Define security objectives with an owner and a target, and record measurements to show their '
                        . 'progress.',
                    'grcmanager'
                ),
                'icon'     => 'ti-target-arrow',
            ],
            'awareness'         => [
                'title'    => __('Train and raise awareness', 'grcmanager'),
                'expected' => __(
                    'Deliver security training and awareness sessions, and make sure the people concerned have '
                        . 'completed them.',
                    'grcmanager'
                ),
                'icon'     => 'ti-school',
            ],
            'exercises'         => [
                'title'    => __('Run a continuity or crisis exercise', 'grcmanager'),
                'expected' => __(
                    'Run at least one exercise, assess whether its objectives were met and have its review '
                        . 'validated.',
                    'grcmanager'
                ),
                'icon'     => 'ti-urgent',
            ],
            'internal_audit'    => [
                'title'    => __('Carry out the internal audit', 'grcmanager'),
                'expected' => __(
                    'Complete an internal audit and make sure the audit programme covers every requirement and '
                        . 'every applicable control.',
                    'grcmanager'
                ),
                'icon'     => 'ti-clipboard-check',
            ],
            'management_review' => [
                'title'    => __('Hold the management review', 'grcmanager'),
                'expected' => __(
                    'Hold a management review prepared with its input file, and record the decisions taken.',
                    'grcmanager'
                ),
                'icon'     => 'ti-presentation',
            ],
            'nonconformities'   => [
                'title'    => __('Treat the nonconformities', 'grcmanager'),
                'expected' => __(
                    'Analyse each nonconformity, carry out its corrective action on time, and close it only once '
                        . 'the action is done.',
                    'grcmanager'
                ),
                'icon'     => 'ti-tool',
            ],
        ];
    }

    // ------------------------------------------------------------------ Calcul.

    /**
     * Données de la page, de l'export PDF et de la carte du tableau de bord.
     *
     * @return array{steps: list<array<string, mixed>>, phases: list<array<string, mixed>>,
     *     summary: array<string, mixed>}
     */
    public static function report(?string $today = null): array
    {
        $today ??= substr((string) (Session::getCurrentTime() ?? date('Y-m-d H:i:s')), 0, 10);
        $evaluations = self::evaluate($today);
        $texts = self::getStepTexts();
        $styles = self::getStatusStyles();
        $declared = self::declaredStatuses();
        $statuses = array_map(static fn (array $e): string => $e['status'], $evaluations);
        $next = Rules::nextStep($statuses);

        $steps = [];
        $number = 0;
        foreach (Rules::STEPS as $key => $definition) {
            $number++;
            $evaluation = $evaluations[$key];
            $requirements = Rules::declared($definition['requirements'], $declared);
            $steps[] = [
                'key'          => $key,
                'number'       => $number,
                'phase'        => $definition['phase'],
                'clauses'      => $definition['clauses'],
                'title'        => $texts[$key]['title'],
                'expected'     => $texts[$key]['expected'],
                'icon'         => $texts[$key]['icon'],
                'status'       => $evaluation['status'],
                'style'        => $styles[$evaluation['status']],
                'facts'        => $evaluation['facts'],
                'links'        => $evaluation['links'],
                'requirements' => $requirements['total'] > 0 && IsmsRequirement::canView()
                    ? sprintf(
                        __('Declared requirements: %1$d of %2$d compliant', 'grcmanager'),
                        $requirements['compliant'],
                        $requirements['total']
                    )
                    : '',
                'next'         => $key === $next,
            ];
        }

        $phases = [];
        foreach (self::getPhases() as $phase => $label) {
            $phaseSteps = array_values(array_filter($steps, static fn (array $s): bool => $s['phase'] === $phase));
            $phaseStatuses = array_column($phaseSteps, 'status');
            $phases[] = $label + [
                'key'    => $phase,
                'steps'  => $phaseSteps,
                'counts' => Rules::counts($phaseStatuses),
                'score'  => Rules::score($phaseStatuses),
            ];
        }

        $score = Rules::score(array_values($statuses));
        $counts = Rules::counts($statuses);
        $nextStep = null;
        foreach ($steps as $step) {
            if ($step['next']) {
                $nextStep = $step;
            }
        }

        return [
            'steps'   => $steps,
            'phases'  => $phases,
            'summary' => [
                'score'     => $score,
                'color'     => Rules::color($score),
                'counts'    => $counts,
                'total'     => count($steps),
                'evaluated' => count($steps) - $counts[Rules::STATUS_LOCKED],
                'next'      => $nextStep,
            ],
        ];
    }

    /**
     * Statut de chaque étape, faits affichés et liens vers les écrans à compléter.
     *
     * @return array<string, array{status: string, facts: list<string>, links: list<array{label: string,
     *     url: string, icon: string}>}>
     */
    private static function evaluate(string $today): array
    {
        $consistency = ConsistencyCheck::canView() ? ConsistencyCheck::run($today) : [];
        $gaps = static fn (string $rule): int => !empty($consistency[$rule]['evaluated'])
            ? count($consistency[$rule]['gaps'])
            : 0;
        $fix = static fn (string $area): array => [
            'label' => __('See the items to fix', 'grcmanager'),
            'url'   => ConsistencyCheck::getSearchURL() . '?area=' . $area,
            'icon'  => 'ti-list-check',
        ];
        $since = date('Y-m-d', (int) strtotime($today . ' -' . Rules::RECENT_MONTHS . ' months'));

        return [
            'scope'             => self::scopeStep(),
            'context'           => self::contextStep(),
            'policy'            => self::policyStep($gaps, $fix),
            'roles'             => self::rolesStep(),
            'risk_assessment'   => self::riskAssessmentStep($gaps, $fix),
            'risk_treatment'    => self::riskTreatmentStep($gaps, $fix),
            'soa'               => self::soaStep(),
            'objectives'        => self::objectivesStep(),
            'awareness'         => self::awarenessStep($since, $today),
            'exercises'         => self::exercisesStep($since),
            'internal_audit'    => self::internalAuditStep($today),
            'management_review' => self::managementReviewStep($today),
            'nonconformities'   => self::nonconformitiesStep($gaps, $fix),
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function locked(): array
    {
        return ['status' => Rules::STATUS_LOCKED, 'facts' => [], 'links' => []];
    }

    /**
     * @return array{label: string, url: string, icon: string}
     */
    private static function link(string $label, string $url, string $icon = 'ti-arrow-right'): array
    {
        return ['label' => $label, 'url' => $url, 'icon' => $icon];
    }

    /**
     * Nombre de lignes d'un module dans les entités de la session (quand il en a une).
     *
     * @param class-string<\CommonDBTM> $itemtype
     * @param array<mixed> $where
     */
    private static function count(string $itemtype, array $where = []): int
    {
        global $DB;

        $table = $itemtype::getTable();
        $criteria = (new $itemtype())->isEntityAssign() ? EntityPerimeter::criteria($table) : [];

        return (int) ($DB->request([
            'COUNT' => 'c',
            'FROM'  => $table,
            'WHERE' => array_merge($where, $criteria),
        ])->current()['c'] ?? 0);
    }

    /**
     * Date (AAAA-MM-JJ) du dernier élément terminé (audit, revue), dans les entités de la session.
     *
     * @param class-string<Audit|ManagementReview> $itemtype
     */
    private static function lastCompleted(string $itemtype, string $dateField): ?string
    {
        global $DB;

        $table = $itemtype::getTable();
        $row = $DB->request([
            'SELECT' => ['MAX' => "$dateField AS last_date"],
            'FROM'   => $table,
            'WHERE'  => array_merge(
                ['status' => 'completed'],
                (new $itemtype())->isEntityAssign() ? EntityPerimeter::criteria($table) : []
            ),
        ])->current();
        $last = $row['last_date'] ?? null;

        return $last !== null && $last !== '' ? substr((string) $last, 0, 10) : null;
    }

    /**
     * Statut déclaré de chaque exigence du SMSI (code => statut).
     *
     * @return array<string, string>
     */
    private static function declaredStatuses(): array
    {
        global $DB;

        $statuses = [];
        foreach ($DB->request(['SELECT' => ['code', 'status'], 'FROM' => IsmsRequirement::getTable()]) as $row) {
            $statuses[(string) $row['code']] = (string) $row['status'];
        }

        return $statuses;
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function scopeStep(): array
    {
        global $DB;

        if (!IsmsRequirement::canView()) {
            return self::locked();
        }
        $row = $DB->request([
            'SELECT' => ['id', 'status'],
            'FROM'   => IsmsRequirement::getTable(),
            'WHERE'  => ['code' => '4.3'],
        ])->current();
        $id = (int) ($row['id'] ?? 0);
        $declared = (string) ($row['status'] ?? '');
        $documents = $id > 0
            ? countElementsInTable(Document_Item::getTable(), ['itemtype' => IsmsRequirement::class, 'items_id' => $id])
            : 0;

        return [
            'status' => Rules::scope($declared, $documents),
            'facts'  => [
                sprintf(
                    __('Declared status of requirement 4.3: %s', 'grcmanager'),
                    IsmsRequirement::getStatuses()[$declared] ?? IsmsRequirement::getStatuses()['not_started']
                ),
                sprintf(__('Documents attached: %d', 'grcmanager'), $documents),
            ],
            'links'  => [self::link(
                __('Open requirement 4.3 (scope)', 'grcmanager'),
                $id > 0 ? IsmsRequirement::getFormURLWithID($id) : IsmsRequirement::getSearchURL()
            )],
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function contextStep(): array
    {
        $summary = ContextItem::clauseReadiness('4.1');
        if ($summary === null) {
            return self::locked();
        }
        $facts = [
            sprintf(
                __('Issues: %1$d, interested parties: %2$d', 'grcmanager'),
                $summary['issues'],
                $summary['parties']
            ),
            $summary['climate']
                ? __('Climate change question answered', 'grcmanager')
                : __('Climate change question not answered yet', 'grcmanager'),
        ];
        if ($summary['overdue'] > 0) {
            $facts[] = sprintf(__('Reviews overdue: %d', 'grcmanager'), $summary['overdue']);
        }

        return [
            'status' => Rules::context(
                $summary['issues'],
                $summary['parties'],
                $summary['climate'],
                $summary['overdue']
            ),
            'facts'  => $facts,
            'links'  => [self::link(ContextItem::getTypeName(Session::getPluralNumber()), $summary['url'])],
        ];
    }

    /**
     * @param callable(string): int $gaps
     * @param callable(string): array<string, string> $fix
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function policyStep(callable $gaps, callable $fix): array
    {
        if (!Policy::canView()) {
            return self::locked();
        }
        $approved = self::count(Policy::class, ['status' => 'approved']);
        $drafts = self::count(Policy::class, ['status' => 'draft']);
        $overdue = $gaps('policy_review_overdue');
        $links = [self::link(Policy::getTypeName(Session::getPluralNumber()), Policy::getSearchURL())];
        if ($overdue > 0) {
            $links[] = $fix('policies');
        }

        return [
            'status' => Rules::policy($approved, $drafts, $overdue),
            'facts'  => [
                sprintf(__('Approved policies: %d', 'grcmanager'), $approved),
                sprintf(__('Drafts: %d', 'grcmanager'), $drafts),
                sprintf(__('Approved policies whose review is overdue: %d', 'grcmanager'), $overdue),
            ],
            'links'  => $links,
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function rolesStep(): array
    {
        $counts = ResponsibilityActivity::clauseReadiness('5.3');
        if ($counts === null) {
            return self::locked();
        }

        return [
            'status' => Rules::roles($counts['activities'], $counts['high']),
            'facts'  => [
                sprintf(__('Activities in the matrix: %d', 'grcmanager'), $counts['activities']),
                sprintf(
                    __(
                        'Activities with a serious alert (no one accountable, several accountable, roles that '
                            . 'should be separated): %d',
                        'grcmanager'
                    ),
                    $counts['high']
                ),
            ],
            'links'  => [self::link(ResponsibilityActivity::getTypeName(Session::getPluralNumber()), $counts['url'])],
        ];
    }

    /**
     * @param callable(string): int $gaps
     * @param callable(string): array<string, string> $fix
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function riskAssessmentStep(callable $gaps, callable $fix): array
    {
        if (!Risk::canView()) {
            return self::locked();
        }
        $open = self::count(Risk::class, ['NOT' => ['status' => 'closed']]);
        $withoutOwner = $gaps('risk_without_owner');
        $overdue = $gaps('risk_review_overdue');
        $links = [self::link(Risk::getTypeName(Session::getPluralNumber()), Risk::getSearchURL())];
        if ($withoutOwner + $overdue > 0) {
            $links[] = $fix('risk_assessment');
        }

        return [
            'status' => Rules::riskAssessment($open, $withoutOwner, $overdue),
            'facts'  => [
                sprintf(__('Open risks: %d', 'grcmanager'), $open),
                sprintf(__('Without an owner: %d', 'grcmanager'), $withoutOwner),
                sprintf(__('Review overdue: %d', 'grcmanager'), $overdue),
            ],
            'links'  => $links,
        ];
    }

    /**
     * @param callable(string): int $gaps
     * @param callable(string): array<string, string> $fix
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function riskTreatmentStep(callable $gaps, callable $fix): array
    {
        if (!Risk::canView()) {
            return self::locked();
        }
        $open = self::count(Risk::class, ['NOT' => ['status' => 'closed']]);
        $withoutDecision = $gaps('risk_without_decision');
        $accepted = $gaps('risk_accepted_without_approval');
        $actions = $gaps('treatment_action_overdue');
        $links = [self::link(Risk::getTypeName(Session::getPluralNumber()), Risk::getSearchURL())];
        if ($withoutDecision + $accepted + $actions > 0) {
            $links[] = $fix('risk_treatment');
        }

        return [
            'status' => Rules::riskTreatment($open, $withoutDecision, $accepted, $actions),
            'facts'  => [
                sprintf(__('High risks without a treatment decision: %d', 'grcmanager'), $withoutDecision),
                sprintf(__('Accepted risks without approval: %d', 'grcmanager'), $accepted),
                sprintf(__('Treatment actions overdue: %d', 'grcmanager'), $actions),
            ],
            'links'  => $links,
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function soaStep(): array
    {
        global $CFG_GLPI;

        if (!Control::canView()) {
            return self::locked();
        }
        $approved = SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED);
        $state = SoaApprovalLogic::currentState(
            $approved !== null ? (string) $approved['fingerprint'] : null,
            SoaVersion::currentFingerprint()
        );
        $pending = SoaVersion::latest(SoaApprovalLogic::VERSION_PENDING) !== null;
        $facts = [match ($state) {
            'up_to_date'     => sprintf(
                __('Version %s approved, still up to date', 'grcmanager'),
                (string) ($approved['version'] ?? '')
            ),
            'changed'        => sprintf(
                __('Changed since version %s was approved', 'grcmanager'),
                (string) ($approved['version'] ?? '')
            ),
            default          => __('Never approved', 'grcmanager'),
        }];
        if ($pending) {
            $facts[] = __('An approval request is pending', 'grcmanager');
        }

        return [
            'status' => Rules::soa($state, $pending),
            'facts'  => $facts,
            'links'  => [
                self::link(
                    __('Statement of Applicability', 'grcmanager'),
                    $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/soaapproval.php'
                ),
                self::link(Control::getTypeName(Session::getPluralNumber()), Control::getSearchURL(), 'ti-list'),
            ],
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function objectivesStep(): array
    {
        global $DB;

        if (!Objective::canView()) {
            return self::locked();
        }
        $total = self::count(Objective::class);
        $measured = (int) ($DB->request([
            'COUNT'    => 'c',
            'SELECT'   => 'plugin_grcmanager_objectives_id',
            'DISTINCT' => true,
            'FROM'     => ObjectiveMeasurement::getTable(),
        ])->current()['c'] ?? 0);
        $measured = min($measured, $total);

        return [
            'status' => Rules::objectives($total, $measured),
            'facts'  => [
                sprintf(__('Objectives: %d', 'grcmanager'), $total),
                sprintf(__('With at least one measurement: %d', 'grcmanager'), $measured),
            ],
            'links'  => [self::link(Objective::getTypeName(Session::getPluralNumber()), Objective::getSearchURL())],
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function awarenessStep(string $since, string $today): array
    {
        if (!Training::canView()) {
            return self::locked();
        }
        $total = self::count(Training::class);
        $delivered = self::count(Training::class, [
            ['date_delivered' => ['>=', $since]],
            ['date_delivered' => ['<=', $today]],
        ]);
        $rate = $total > 0 ? (int) DashboardCardService::trainingCompletionRate()['number'] : null;
        $facts = [
            sprintf(__('Trainings and sessions: %d', 'grcmanager'), $total),
            sprintf(
                __('Delivered in the last %1$d months: %2$d', 'grcmanager'),
                Rules::RECENT_MONTHS,
                $delivered
            ),
        ];
        if ($rate !== null) {
            $facts[] = sprintf(__('Participants who completed their training: %d %%', 'grcmanager'), $rate);
        }

        return [
            'status' => Rules::awareness($total, $delivered, $rate),
            'facts'  => $facts,
            'links'  => [self::link(Training::getTypeName(Session::getPluralNumber()), Training::getSearchURL())],
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function exercisesStep(string $since): array
    {
        if (!CrisisExercise::canView()) {
            return self::locked();
        }
        $total = self::count(CrisisExercise::class);
        $recent = self::count(CrisisExercise::class, ['started_at' => ['>=', $since]]);
        $validated = self::count(CrisisExercise::class, [
            'started_at'   => ['>=', $since],
            'NOT'          => ['validated_at' => null],
        ]);

        return [
            'status' => Rules::exercises($total, $recent, $validated),
            'facts'  => [
                sprintf(
                    __('Exercises in the last %1$d months: %2$d', 'grcmanager'),
                    Rules::RECENT_MONTHS,
                    $recent
                ),
                sprintf(__('With a validated review: %d', 'grcmanager'), $validated),
            ],
            'links'  => [self::link(
                CrisisExercise::getTypeName(Session::getPluralNumber()),
                CrisisExercise::getSearchURL()
            )],
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function internalAuditStep(string $today): array
    {
        if (!Audit::canView()) {
            return self::locked();
        }
        $last = self::lastCompleted(Audit::class, 'actual_date');
        $recent = !ConsistencyRules::noRecentEvent($last, $today, Rules::RECENT_MONTHS);
        $audits = self::count(Audit::class, ['NOT' => ['status' => 'cancelled']]);
        $coverage = AuditChecklist::coverage(ChecklistRules::DEFAULT_COVERAGE_YEARS, $today);

        return [
            'status' => Rules::internalAudit($recent, $audits, (int) $coverage['percent']),
            'facts'  => [
                $last !== null
                    ? sprintf(__('Last internal audit completed on %s', 'grcmanager'), Html::convDate($last))
                    : __('No internal audit completed yet', 'grcmanager'),
                sprintf(
                    __('Audit programme coverage: %1$d %% (%2$d of %3$d requirements and controls)', 'grcmanager'),
                    (int) $coverage['percent'],
                    (int) $coverage['covered'],
                    (int) $coverage['total']
                ),
            ],
            'links'  => [
                self::link(Audit::getTypeName(Session::getPluralNumber()), Audit::getSearchURL()),
                self::link(
                    __('Audit programme coverage', 'grcmanager'),
                    AuditChecklist::getCoverageURL(),
                    'ti-chart-pie'
                ),
            ],
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function managementReviewStep(string $today): array
    {
        global $DB;

        if (!ManagementReview::canView()) {
            return self::locked();
        }
        $last = self::lastCompleted(ManagementReview::class, 'review_date');
        $recent = !ConsistencyRules::noRecentEvent($last, $today, Rules::RECENT_MONTHS);
        $reviews = self::count(ManagementReview::class);
        // Dossier préparé pour la dernière revue (terminée ou à venir).
        $latest = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ManagementReview::getTable(),
            'ORDER'  => ['review_date DESC', 'id DESC'],
            'LIMIT'  => 1,
        ])->current();
        $latestId = (int) ($latest['id'] ?? 0);
        $dossier = $latestId > 0 && ManagementReviewDossier::history($latestId) !== [];
        $links = [self::link(
            ManagementReview::getTypeName(Session::getPluralNumber()),
            ManagementReview::getSearchURL()
        )];
        if ($latestId > 0) {
            $links[] = self::link(
                __('Open the last management review', 'grcmanager'),
                ManagementReview::getFormURLWithID($latestId),
                'ti-file-analytics'
            );
        }

        return [
            'status' => Rules::managementReview($recent, $reviews, $dossier),
            'facts'  => [
                $last !== null
                    ? sprintf(__('Last management review completed on %s', 'grcmanager'), Html::convDate($last))
                    : __('No management review completed yet', 'grcmanager'),
                $dossier
                    ? __('Input file prepared for the last review', 'grcmanager')
                    : __('No input file prepared for the last review', 'grcmanager'),
            ],
            'links'  => $links,
        ];
    }

    /**
     * @param callable(string): int $gaps
     * @param callable(string): array<string, string> $fix
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function nonconformitiesStep(callable $gaps, callable $fix): array
    {
        if (!Nonconformity::canView()) {
            return self::locked();
        }
        $open = self::count(Nonconformity::class, [
            'NOT' => ['status' => Nonconformity::CLOSED_STATUSES],
            'finding_type' => 'nonconformity',
        ]);
        $overdue = $gaps('nonconformity_overdue');
        $closedWithoutAction = $gaps('nonconformity_closed_without_action');
        $links = [self::link(
            Nonconformity::getTypeName(Session::getPluralNumber()),
            Nonconformity::getSearchURL()
        )];
        if ($overdue + $closedWithoutAction > 0) {
            $links[] = $fix('improvement');
        }

        return [
            'status' => Rules::nonconformities($open, $overdue, $closedWithoutAction),
            'facts'  => [
                sprintf(__('Open nonconformities: %d', 'grcmanager'), $open),
                sprintf(__('Overdue: %d', 'grcmanager'), $overdue),
                sprintf(__('Closed without a corrective action: %d', 'grcmanager'), $closedWithoutAction),
            ],
            'links'  => $links,
        ];
    }

    // ------------------------------------------------------------------ Rendu.

    /**
     * Rapport PDF « état de préparation » (HTML rendu par Twig, converti par PdfRenderer).
     *
     * @param array<string, mixed> $report résultat de report()
     */
    public static function renderPdf(array $report): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/certification_readiness_report.html.twig', [
            'report_title'       => __('Certification readiness report', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
            'styles'             => self::getStatusStyles(),
        ] + $report);
    }
}
