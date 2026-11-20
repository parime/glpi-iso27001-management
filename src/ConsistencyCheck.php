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

use CommonDBTM;
use CommonGLPI;
use Config;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use Html;
use Session;

/**
 * Issue #614 : contrôles de cohérence du SMSI avant un audit. Une page rassemble les écarts que
 * chaque écran ne signale qu'en partie (risque sans propriétaire, mesure mise en œuvre sans preuve
 * valide ni test, preuve expirée, politique non revue, non-conformité close sans action...), avec
 * leur gravité et un lien vers la fiche à corriger.
 *
 * - Calcul à la demande (page, carte du tableau de bord, export), sans tâche planifiée.
 * - Lecture seule : rien n'est corrigé ni changé de statut ; seul le résumé du mois (score et
 *   nombre d'écarts par règle, sans aucun élément) est enregistré pour la tendance.
 * - Droits : une règle n'est évaluée que si la personne peut lire les éléments concernés (registres
 *   sensibles, continuité et RGPD compris), dans ses entités ; chaque élément listé est en plus
 *   vérifié un par un (CommonDBTM::can()), si bien qu'un élément illisible n'est jamais cité.
 */
class ConsistencyCheck extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const SNAPSHOTS_TABLE = 'glpi_plugin_grcmanager_consistencysnapshots';

    /** Clé de configuration (contexte plugin:grcmanager) : règles actives et seuils, en JSON. */
    public const CONFIG_KEY = 'consistency_rules';

    /** Écarts listés par règle sur la page (l'export les donne tous). */
    public const PAGE_GAPS_MAX = 50;

    public static function getTypeName($nb = 0)
    {
        return __('ISMS consistency checks', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/consistencycheck.php';
    }

    /**
     * @param array<string, string> $filters
     */
    public static function getExportURL(string $format, array $filters = []): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/consistencycheck.export.php?'
            . http_build_query(['format' => $format] + array_filter($filters, static fn ($v): bool => $v !== ''));
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
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
     * @return array<string, string>
     */
    public static function getAreaLabels(): array
    {
        return [
            'policies'          => __('Policies and roles', 'grcmanager'),
            'risk_assessment'   => __('Risk assessment', 'grcmanager'),
            'risk_treatment'    => __('Risk treatment and Statement of Applicability', 'grcmanager'),
            'evidence'          => __('Evidence and monitoring', 'grcmanager'),
            'audit'             => __('Internal audit', 'grcmanager'),
            'management_review' => __('Management review', 'grcmanager'),
            'improvement'       => __('Nonconformities and corrective actions', 'grcmanager'),
            'operations'        => __('Annex A operations', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getSeverities(): array
    {
        return [
            ConsistencyRules::SEVERITY_HIGH   => [
                'label' => __('High', 'grcmanager'),
                'class' => 'bg-red-lt',
                'icon'  => 'ti-alert-octagon',
            ],
            ConsistencyRules::SEVERITY_MEDIUM => [
                'label' => __('Medium', 'grcmanager'),
                'class' => 'bg-orange-lt',
                'icon'  => 'ti-alert-triangle',
            ],
            ConsistencyRules::SEVERITY_LOW    => [
                'label' => __('Low', 'grcmanager'),
                'class' => 'bg-yellow-lt',
                'icon'  => 'ti-info-circle',
            ],
        ];
    }

    /**
     * Libellé (seuil compris) et « comment corriger » de chaque règle.
     *
     * @param array<string, array{enabled: bool, threshold: int|string|null}>|null $settings
     * @return array<string, array{label: string, fix: string}>
     */
    public static function getRuleTexts(?array $settings = null): array
    {
        $settings ??= self::getSettings();
        $levels = Risk::getRiskLevels();
        $t = static fn (string $key): int|string|null => $settings[$key]['threshold'] ?? null;
        $level = static fn (string $key): string => $levels[(string) $t($key)] ?? (string) $t($key);

        return [
            'policy_review_overdue'                => [
                'label' => (int) $t('policy_review_overdue') > 0
                    ? sprintf(
                        __('Approved policies whose review is overdue by more than %d days', 'grcmanager'),
                        (int) $t('policy_review_overdue')
                    )
                    : __('Approved policies whose review date has passed or is missing', 'grcmanager'),
                'fix'   => __(
                    'Open the policy, review it with its owner, then set the next review date (and a new version if '
                        . 'it changed).',
                    'grcmanager'
                ),
            ],
            'policy_without_owner'                 => [
                'label' => __('Policies without an owner', 'grcmanager'),
                'fix'   => __(
                    'Open the policy and choose the person responsible for keeping it up to date.',
                    'grcmanager'
                ),
            ],
            'risk_without_owner'                   => [
                'label' => __('Open risks without an owner', 'grcmanager'),
                'fix'   => __(
                    'Open the risk and choose its owner: the person accountable for the risk and its treatment.',
                    'grcmanager'
                ),
            ],
            'risk_review_overdue'                  => [
                'label' => (int) $t('risk_review_overdue') > 0
                    ? sprintf(
                        __('Open risks whose review is overdue by more than %d days', 'grcmanager'),
                        (int) $t('risk_review_overdue')
                    )
                    : __('Open risks whose review date has passed or is missing', 'grcmanager'),
                'fix'   => __(
                    'Reassess the risk with its owner (probability, impact, treatment), then set the next review date.',
                    'grcmanager'
                ),
            ],
            'risk_without_decision'                => [
                'label' => sprintf(
                    __('Open risks of level %s or above without a treatment decision', 'grcmanager'),
                    $level('risk_without_decision')
                ),
                'fix'   => __(
                    'Open the risk and choose a treatment (mitigate, transfer, avoid or accept) with its '
                        . 'justification.',
                    'grcmanager'
                ),
            ],
            'risk_accepted_without_approval'       => [
                'label' => sprintf(
                    __('Risks of level %s or above accepted without a formal approval', 'grcmanager'),
                    $level('risk_accepted_without_approval')
                ),
                'fix'   => __(
                    'Have the acceptance signed by the risk owner or management: save the risk again with the '
                        . '"Accept" treatment and its justification, or choose another treatment.',
                    'grcmanager'
                ),
            ],
            'control_exclusion_weak_justification' => [
                'label' => sprintf(
                    __(
                        'Excluded or partially applicable controls with a justification under %d characters',
                        'grcmanager'
                    ),
                    (int) $t('control_exclusion_weak_justification')
                ),
                'fix'   => __(
                    'Open the control and explain why it is excluded (no such activity, risk accepted, covered '
                        . 'elsewhere): the auditor checks every exclusion.',
                    'grcmanager'
                ),
            ],
            'control_applicable_without_risk'      => [
                'label' => __('Applicable controls linked to no risk', 'grcmanager'),
                'fix'   => __(
                    'Link the control to the risks it treats ("Risks" tab of the control, or "Controls" tab of the '
                        . 'risk): it justifies its inclusion in the SoA.',
                    'grcmanager'
                ),
            ],
            'soa_not_approved'                     => [
                'label' => __('Statement of Applicability not approved in its current version', 'grcmanager'),
                'fix'   => __(
                    'Open "SoA approval" and request the approval of the current version by management.',
                    'grcmanager'
                ),
            ],
            'treatment_action_overdue'             => [
                'label' => (int) $t('treatment_action_overdue') > 0
                    ? sprintf(
                        __('Risk treatment actions overdue by more than %d days', 'grcmanager'),
                        (int) $t('treatment_action_overdue')
                    )
                    : __('Overdue risk treatment actions', 'grcmanager'),
                'fix'   => __(
                    'Open the risk, "Risk treatment actions" tab: mark the action as done, or set a realistic new '
                        . 'due date.',
                    'grcmanager'
                ),
            ],
            'waiver_expired'                       => [
                'label' => __('Approved waivers past their end date', 'grcmanager'),
                'fix'   => __('Open the waiver: close it, or reassess it and set a new end date.', 'grcmanager'),
            ],
            'control_without_evidence'             => [
                'label' => sprintf(
                    __('Implemented controls without valid evidence nor a test in the last %d months', 'grcmanager'),
                    (int) $t('control_without_evidence')
                ),
                'fix'   => __(
                    'Attach a document proving the control works ("Documents" tab), or record an effectiveness test.',
                    'grcmanager'
                ),
            ],
            'evidence_expired'                     => [
                'label' => __('Expired evidence still attached', 'grcmanager'),
                'fix'   => __(
                    'Open the item, "Evidence validities" tab: attach an up-to-date document and remove or renew the '
                        . 'expired one.',
                    'grcmanager'
                ),
            ],
            'no_recent_internal_audit'             => [
                'label' => sprintf(
                    __('No internal audit completed in the last %d months', 'grcmanager'),
                    (int) $t('no_recent_internal_audit')
                ),
                'fix'   => __('Plan an internal audit and record it as completed with its actual date.', 'grcmanager'),
            ],
            'no_recent_management_review'          => [
                'label' => sprintf(
                    __('No management review held in the last %d months', 'grcmanager'),
                    (int) $t('no_recent_management_review')
                ),
                'fix'   => __(
                    'Hold a management review and record it as completed with its date and decisions.',
                    'grcmanager'
                ),
            ],
            'nonconformity_closed_without_action'  => [
                'label' => __('Nonconformities closed without a corrective action', 'grcmanager'),
                'fix'   => __(
                    'Open the nonconformity and describe the corrective action taken; reopen it if nothing was done.',
                    'grcmanager'
                ),
            ],
            'nonconformity_overdue'                => [
                'label' => (int) $t('nonconformity_overdue') > 0
                    ? sprintf(
                        __('Open nonconformities overdue by more than %d days', 'grcmanager'),
                        (int) $t('nonconformity_overdue')
                    )
                    : __('Open nonconformities past their due date', 'grcmanager'),
                'fix'   => __(
                    'Complete the corrective action and close the nonconformity, or set a new due date.',
                    'grcmanager'
                ),
            ],
            'supplier_risk_review_overdue'         => [
                'label' => (int) $t('supplier_risk_review_overdue') > 0
                    ? sprintf(
                        __('Supplier risks whose review is overdue by more than %d days', 'grcmanager'),
                        (int) $t('supplier_risk_review_overdue')
                    )
                    : __('Supplier risks whose review date has passed or is missing', 'grcmanager'),
                'fix'   => __(
                    'Reassess the supplier (questionnaire, contracts), then set the next review date.',
                    'grcmanager'
                ),
            ],
            'business_process_without_rto'         => [
                'label' => __('Business processes without a recovery time objective (RTO)', 'grcmanager'),
                'fix'   => __(
                    'Open the process and set its RTO, ideally after its business impact analysis (BIA).',
                    'grcmanager'
                ),
            ],
            'processing_dpia_pending'              => [
                'label' => __('Processing activities whose required DPIA is not done', 'grcmanager'),
                'fix'   => __(
                    'Open the processing activity, "DPIA" tab: carry out the DPIA, then '
                        . 'mark it as done.',
                    'grcmanager'
                ),
            ],
            'obligation_review_overdue'            => [
                'label' => (int) $t('obligation_review_overdue') > 0
                    ? sprintf(
                        __('Compliance obligations whose review is overdue by more than %d days', 'grcmanager'),
                        (int) $t('obligation_review_overdue')
                    )
                    : __('Compliance obligations past their review date', 'grcmanager'),
                'fix'   => __(
                    'Check the compliance status of the obligation, then set the next review date.',
                    'grcmanager'
                ),
            ],
            'access_review_overdue'                => [
                'label' => (int) $t('access_review_overdue') > 0
                    ? sprintf(
                        __('Access review campaigns overdue by more than %d days', 'grcmanager'),
                        (int) $t('access_review_overdue')
                    )
                    : __('Access review campaigns past their due date', 'grcmanager'),
                'fix'   => __(
                    'Remind the reviewers, then close the campaign once every access has been reviewed.',
                    'grcmanager'
                ),
            ],
            'asset_end_of_life'                    => [
                'label' => __(
                    'Assets running software, a system or a firmware that is no longer supported',
                    'grcmanager'
                ),
                'fix'   => __(
                    'Upgrade to a supported version, or record a waiver with compensating measures until the upgrade.',
                    'grcmanager'
                ),
            ],
            'hardware_warranty_expired'            => [
                'label' => __('Hardware whose warranty has expired', 'grcmanager'),
                'fix'   => __(
                    'Check that a maintenance contract covers the hardware, renew the warranty or plan its '
                        . 'replacement.',
                    'grcmanager'
                ),
            ],
            'software_license_gap'                 => [
                'label' => __(
                    'Software without a license, installed beyond the licensed seats, or under an expired license',
                    'grcmanager'
                ),
                'fix'   => __(
                    'Record the missing license in GLPI, buy seats or uninstall the software, and renew or replace '
                        . 'the expired license.',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * Unité d'un seuil, pour le formulaire de configuration.
     *
     * @return array<string, string>
     */
    public static function getUnitLabels(): array
    {
        return [
            ConsistencyRules::UNIT_DAYS   => __('Tolerance (days)', 'grcmanager'),
            ConsistencyRules::UNIT_MONTHS => __('Period (months)', 'grcmanager'),
            ConsistencyRules::UNIT_CHARS  => __('Minimum length (characters)', 'grcmanager'),
            ConsistencyRules::UNIT_LEVEL  => __('From risk level', 'grcmanager'),
        ];
    }

    // ------------------------------------------------------------------ Réglages.

    /**
     * @return array<string, array{enabled: bool, threshold: int|string|null}>
     */
    public static function getSettings(): array
    {
        $values = Config::getConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::CONFIG_KEY]);
        $stored = json_decode((string) ($values[self::CONFIG_KEY] ?? ''), true);

        return ConsistencyRules::settings(is_array($stored) ? $stored : []);
    }

    /**
     * @param array<mixed> $posted règle => [enabled, threshold], tel que soumis par la configuration
     * @return bool vrai si les réglages ont changé
     */
    public static function saveSettings(array $posted): bool
    {
        $before = self::getSettings();
        $after = ConsistencyRules::fromForm($posted);
        Config::setConfigurationValues(AuditTeam::CONFIG_CONTEXT, [self::CONFIG_KEY => json_encode($after)]);

        return $before !== $after;
    }

    /**
     * Lignes du formulaire de configuration, par domaine.
     *
     * @return list<array<string, mixed>>
     */
    public static function getConfigRows(): array
    {
        $settings = self::getSettings();
        $texts = self::getRuleTexts($settings);
        $areas = self::getAreaLabels();
        $severities = self::getSeverities();
        $units = self::getUnitLabels();
        $rows = [];
        foreach (ConsistencyRules::RULES as $key => $rule) {
            $spec = $rule['threshold'];
            $rows[] = [
                'key'        => $key,
                'area'       => $areas[$rule['area']],
                'label'      => $texts[$key]['label'],
                'severity'   => $severities[$rule['severity']],
                'enabled'    => $settings[$key]['enabled'],
                'threshold'  => $settings[$key]['threshold'],
                'unit'       => $spec['unit'] ?? '',
                'unit_label' => $spec !== null ? $units[$spec['unit']] : '',
                'min'        => $spec['min'] ?? null,
                'max'        => $spec['max'] ?? null,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ Calcul.

    /**
     * Toutes les règles, dans l'ordre d'affichage. Une règle désactivée ou que la personne ne peut
     * pas évaluer (droit de lecture manquant) n'examine rien et ne liste rien.
     *
     * @return array<string, array{key: string, area: string, clause: string, severity: string, enabled: bool,
     *     evaluated: bool, total: int, gaps: list<array<string, mixed>>}>
     */
    public static function run(?string $today = null): array
    {
        $today ??= substr((string) (Session::getCurrentTime() ?? date('Y-m-d H:i:s')), 0, 10);
        $settings = self::getSettings();
        $cache = [];
        $results = [];
        foreach (ConsistencyRules::RULES as $key => $rule) {
            $result = ['evaluated' => false, 'total' => 0, 'gaps' => []];
            if ($settings[$key]['enabled']) {
                $evaluated = self::evaluate($key, $settings[$key]['threshold'], $today, $cache);
                if ($evaluated !== null) {
                    $result = ['evaluated' => true] + $evaluated;
                }
            }
            $results[$key] = [
                'key'      => $key,
                'area'     => $rule['area'],
                'clause'   => $rule['clause'],
                'severity' => $rule['severity'],
                'enabled'  => $settings[$key]['enabled'],
            ] + $result;
        }

        return $results;
    }

    /**
     * Synthèse des règles évaluées : score, écarts par gravité, nombre total d'écarts.
     *
     * @param array<string, array<string, mixed>> $results résultat de run()
     * @return array{score: ?int, color: string, gaps: int, by_severity: array<string, int>,
     *     counts: array<string, int>, evaluated: int, not_evaluated: int, disabled: int}
     */
    public static function summarize(array $results): array
    {
        $evaluated = [];
        $counts = [];
        $notEvaluated = 0;
        $disabled = 0;
        foreach ($results as $key => $result) {
            if (!$result['enabled']) {
                $disabled++;
                continue;
            }
            if (!$result['evaluated']) {
                $notEvaluated++;
                continue;
            }
            $evaluated[$key] = [
                'severity' => (string) $result['severity'],
                'total'    => (int) $result['total'],
                'gaps'     => count($result['gaps']),
            ];
            $counts[$key] = count($result['gaps']);
        }
        $score = ConsistencyRules::score($evaluated);

        return [
            'score'         => $score,
            'color'         => ConsistencyRules::scoreColor($score),
            'gaps'          => array_sum($counts),
            'by_severity'   => ConsistencyRules::countBySeverity($evaluated),
            'counts'        => $counts,
            'evaluated'     => count($evaluated),
            'not_evaluated' => $notEvaluated,
            'disabled'      => $disabled,
        ];
    }

    /**
     * @param array<string, mixed> $cache lignes déjà lues (risques, mesures), partagées entre règles
     * @return array{total: int, gaps: list<array<string, mixed>>}|null null : règle non évaluable
     */
    private static function evaluate(string $key, int|string|null $threshold, string $today, array &$cache): ?array
    {
        $number = is_int($threshold) ? $threshold : 0;
        $level = is_string($threshold) ? $threshold : 'high';

        return match ($key) {
            'policy_review_overdue'                => self::policyReview($today, $number),
            'policy_without_owner'                 => self::policyOwner(),
            'risk_without_owner', 'risk_review_overdue', 'risk_without_decision', 'risk_accepted_without_approval'
                                                   => self::riskRule($cache, $key, $today, $number, $level),
            'control_exclusion_weak_justification' => self::weakExclusions($cache, $number),
            'control_applicable_without_risk'      => Risk::canView() ? self::controlsWithoutRisk($cache) : null,
            'soa_not_approved'                     => self::soaApproval(),
            'treatment_action_overdue'             => self::treatmentActions($today, $number),
            'waiver_expired'                       => self::waivers($today),
            'control_without_evidence'             => self::controlsWithoutEvidence($cache, $today, $number),
            'evidence_expired'                     => self::expiredEvidence($today),
            'no_recent_internal_audit'             => self::recentEvent(Audit::class, 'actual_date', $today, $number),
            'no_recent_management_review'          => self::recentEvent(
                ManagementReview::class,
                'review_date',
                $today,
                $number
            ),
            'nonconformity_closed_without_action', 'nonconformity_overdue'
                                                   => self::nonconformities($key, $today, $number),
            'supplier_risk_review_overdue'         => self::supplierRisks($today, $number),
            'business_process_without_rto'         => self::businessProcesses(),
            'processing_dpia_pending'              => self::processingActivities(),
            'obligation_review_overdue'            => self::obligations($today, $number),
            'access_review_overdue'                => self::accessReviews($today, $number),
            'asset_end_of_life'                    => self::endOfLifeAssets($today),
            'hardware_warranty_expired'            => self::expiredWarranties($today),
            'software_license_gap'                 => self::softwareLicenseGaps($today),
            default                               => null,
        };
    }

    /**
     * Élément listé seulement s'il est lisible par la personne (droit, entité, restrictions propres
     * à l'objet).
     */
    private static function canRead(string $itemtype, int $id): bool
    {
        static $items = [];
        $items[$itemtype] ??= getItemForItemtype($itemtype);
        $item = $items[$itemtype];

        return $item instanceof CommonDBTM && $item->can($id, READ);
    }

    /**
     * @return array<string, mixed>
     */
    private static function gap(string $itemtype, int $id, string $name, string $detail, ?string $url = null): array
    {
        return [
            'itemtype'   => $itemtype,
            'type_label' => $itemtype::getTypeName(1),
            'id'         => $id,
            'name'       => $name !== ''
                ? $name
                : sprintf(__('%1$s (ID %2$d)', 'grcmanager'), $itemtype::getTypeName(1), $id),
            'url'        => $url ?? $itemtype::getFormURLWithID($id),
            'detail'     => $detail,
        ];
    }

    /**
     * Lignes candidates d'une règle, un écart pour chaque ligne dont $detail renvoie un texte et
     * que la personne peut lire.
     *
     * @param iterable<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): bool $candidate
     * @param callable(array<string, mixed>): ?string $detail
     * @param callable(array<string, mixed>): string $name
     * @return array{total: int, gaps: list<array<string, mixed>>}
     */
    private static function collect(
        string $itemtype,
        iterable $rows,
        callable $candidate,
        callable $detail,
        callable $name
    ): array {
        $total = 0;
        $gaps = [];
        foreach ($rows as $row) {
            if (!$candidate($row)) {
                continue;
            }
            $total++;
            $text = $detail($row);
            if ($text !== null && self::canRead($itemtype, (int) $row['id'])) {
                $gaps[] = self::gap($itemtype, (int) $row['id'], $name($row), $text);
            }
        }

        return ['total' => $total, 'gaps' => $gaps];
    }

    private static function reviewDetail(mixed $date, ?string $state): ?string
    {
        return match ($state) {
            'missing' => __('No review date', 'grcmanager'),
            'overdue' => sprintf(__('Review was due on %s', 'grcmanager'), Html::convDate((string) $date)),
            default   => null,
        };
    }

    private static function dueDetail(mixed $date): string
    {
        return sprintf(__('Due on %s', 'grcmanager'), Html::convDate((string) $date));
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function policyReview(string $today, int $grace): ?array
    {
        global $DB;

        if (!Policy::canView()) {
            return null;
        }

        return self::collect(
            Policy::class,
            $DB->request(['FROM' => Policy::getTable(), 'WHERE' => ['status' => 'approved'], 'ORDER' => 'title']),
            static fn (array $p): bool => true,
            static fn (array $p): ?string => self::reviewDetail(
                $p['next_review_date'],
                ConsistencyRules::reviewState($p['next_review_date'], $today, $grace, true)
            ),
            static fn (array $p): string => (string) $p['title']
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function policyOwner(): ?array
    {
        global $DB;

        if (!Policy::canView()) {
            return null;
        }

        return self::collect(
            Policy::class,
            $DB->request([
                'FROM'  => Policy::getTable(),
                'WHERE' => ['NOT' => ['status' => 'archived']],
                'ORDER' => 'title',
            ]),
            static fn (array $p): bool => true,
            static fn (array $p): ?string => (int) $p['users_id'] <= 0 ? __('No owner', 'grcmanager') : null,
            static fn (array $p): string => (string) $p['title']
        );
    }

    /**
     * Règles du registre des risques (lu une fois pour les quatre règles).
     *
     * @param array<string, mixed> $cache
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function riskRule(array &$cache, string $key, string $today, int $grace, string $level): ?array
    {
        global $DB;

        if (!Risk::canView()) {
            return null;
        }
        if (!isset($cache['risks'])) {
            $cache['risks'] = [];
            // Issue #536 : risques des entités de la session.
            foreach (
                $DB->request([
                    'FROM'  => Risk::getTable(),
                    'WHERE' => EntityPerimeter::criteria(Risk::getTable()),
                    'ORDER' => 'title',
                ]) as $row
            ) {
                $cache['risks'][] = $row + ['residual_level' => '', 'users_id_acceptance' => 0];
            }
        }
        $levels = Risk::getRiskLevels();
        $candidate = match ($key) {
            'risk_without_decision'          => static fn (array $r): bool => ConsistencyRules::isOpenRisk($r)
                && ConsistencyRules::isLevelAtLeast($r['risk_level'], $level),
            'risk_accepted_without_approval' => static fn (array $r): bool => ConsistencyRules::isOpenRisk($r)
                && $r['treatment'] === 'accept',
            default                          => ConsistencyRules::isOpenRisk(...),
        };
        $detail = match ($key) {
            'risk_without_owner'    => static fn (array $r): ?string => ConsistencyRules::riskWithoutOwner($r)
                ? __('No owner', 'grcmanager')
                : null,
            'risk_review_overdue'   => static fn (array $r): ?string => self::reviewDetail(
                $r['review_date'],
                ConsistencyRules::reviewState($r['review_date'], $today, $grace, true)
            ),
            'risk_without_decision' => static fn (array $r): ?string
                => ConsistencyRules::riskWithoutDecision($r, $level)
                ? sprintf(__('Level: %s', 'grcmanager'), $levels[$r['risk_level']] ?? $r['risk_level'])
                : null,
            default                 => static function (array $r) use ($level, $levels): ?string {
                if (!ConsistencyRules::riskAcceptedWithoutApproval($r, $level)) {
                    return null;
                }
                $retained = ConsistencyRules::retainedLevel($r);

                return sprintf(
                    __('Accepted at level %s, no signed acceptance', 'grcmanager'),
                    $levels[$retained] ?? $retained
                );
            },
        };

        return self::collect(
            Risk::class,
            $cache['risks'],
            $candidate,
            $detail,
            static fn (array $r): string => (string) $r['title']
        );
    }

    /**
     * @param array<string, mixed> $cache
     * @return list<array<string, mixed>>
     */
    private static function controls(array &$cache): array
    {
        global $DB;

        if (!isset($cache['controls'])) {
            $cache['controls'] = [];
            // Issue #536 : mesures de la SoA suivie par l'entité active.
            $rows = $DB->request(['FROM' => Control::getTable(), 'WHERE' => EntityPerimeter::controlCriteria()]);
            foreach ($rows as $row) {
                $cache['controls'][] = $row;
            }
            usort(
                $cache['controls'],
                static fn (array $a, array $b): int => strnatcmp((string) $a['code'], (string) $b['code'])
            );
        }

        return $cache['controls'];
    }

    /**
     * @param array<string, mixed> $control
     */
    private static function controlName(array $control): string
    {
        return $control['code'] . ' ' . Control::getControlTitle((string) $control['code']);
    }

    /**
     * @param array<string, mixed> $cache
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function weakExclusions(array &$cache, int $minChars): ?array
    {
        if (!Control::canView()) {
            return null;
        }

        return self::collect(
            Control::class,
            self::controls($cache),
            static fn (array $c): bool => in_array($c['applicability'], ['no', 'partial'], true),
            static function (array $c) use ($minChars): ?string {
                if (!ConsistencyRules::exclusionWeaklyJustified($c, $minChars)) {
                    return null;
                }
                $text = trim(strip_tags((string) $c['justification']));

                return ConsistencyRules::isWeakText($text, 1)
                    ? __('No justification', 'grcmanager')
                    : sprintf(__('Justification too short: "%s"', 'grcmanager'), $text);
            },
            self::controlName(...)
        );
    }

    /**
     * @param array<string, mixed> $cache
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function controlsWithoutRisk(array &$cache): ?array
    {
        global $DB;

        if (!Control::canView()) {
            return null;
        }
        $links = [];
        foreach (
            $DB->request([
                'SELECT'  => ['plugin_grcmanager_controls_id', 'COUNT' => '* AS c'],
                'FROM'    => 'glpi_plugin_grcmanager_controls_risks',
                'GROUPBY' => 'plugin_grcmanager_controls_id',
            ]) as $row
        ) {
            $links[(int) $row['plugin_grcmanager_controls_id']] = (int) $row['c'];
        }

        return self::collect(
            Control::class,
            self::controls($cache),
            static fn (array $c): bool => ConsistencyRules::isApplicable($c) && (int) $c['is_reviewed'] === 1,
            static fn (array $c): ?string => ConsistencyRules::applicableWithoutRisk($c, $links[(int) $c['id']] ?? 0)
                ? __('No linked risk', 'grcmanager')
                : null,
            self::controlName(...)
        );
    }

    /**
     * @param array<string, mixed> $cache
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function controlsWithoutEvidence(array &$cache, string $today, int $months): ?array
    {
        global $DB;

        if (!Control::canView()) {
            return null;
        }
        $expired = [];
        foreach (
            $DB->request([
                'SELECT' => ['items_id', 'documents_id'],
                'FROM'   => Evidence::getTable(),
                'WHERE'  => [
                    'itemtype'    => Control::class,
                    ['NOT' => ['valid_until' => null]],
                    'valid_until' => ['<', $today],
                ],
            ]) as $row
        ) {
            $expired[(int) $row['items_id'] . '-' . (int) $row['documents_id']] = true;
        }
        $documents = [];
        foreach (
            $DB->request([
                'SELECT' => ['items_id', 'documents_id'],
                'FROM'   => Document_Item::getTable(),
                'WHERE'  => ['itemtype' => Control::class],
            ]) as $row
        ) {
            if (!isset($expired[(int) $row['items_id'] . '-' . (int) $row['documents_id']])) {
                $documents[(int) $row['items_id']] = ($documents[(int) $row['items_id']] ?? 0) + 1;
            }
        }
        $tests = [];
        foreach (
            $DB->request([
                'SELECT'  => ['plugin_grcmanager_controls_id', 'MAX' => 'test_date AS last_test'],
                'FROM'    => ControlTest::getTable(),
                'GROUPBY' => 'plugin_grcmanager_controls_id',
            ]) as $row
        ) {
            $tests[(int) $row['plugin_grcmanager_controls_id']] = $row['last_test'] !== null
                ? (string) $row['last_test']
                : null;
        }

        return self::collect(
            Control::class,
            self::controls($cache),
            ConsistencyRules::isImplemented(...),
            static function (array $c) use ($documents, $tests, $today, $months): ?string {
                $last = $tests[(int) $c['id']] ?? null;
                $valid = $documents[(int) $c['id']] ?? 0;
                if (!ConsistencyRules::implementedWithoutEvidence($c, $valid, $last, $today, $months)) {
                    return null;
                }

                return $last === null
                    ? __('No valid document, never tested', 'grcmanager')
                    : sprintf(__('No valid document, last test on %s', 'grcmanager'), Html::convDate($last));
            },
            self::controlName(...)
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function soaApproval(): ?array
    {
        if (!Control::canView()) {
            return null;
        }
        $approved = SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED);
        $state = SoaApprovalLogic::currentState(
            $approved !== null ? (string) $approved['fingerprint'] : null,
            SoaVersion::currentFingerprint()
        );
        if ($state === 'up_to_date') {
            return ['total' => 1, 'gaps' => []];
        }

        global $CFG_GLPI;

        return ['total' => 1, 'gaps' => [[
            'itemtype'   => '',
            'type_label' => __('Statement of Applicability', 'grcmanager'),
            'id'         => 0,
            'name'       => __('Statement of Applicability', 'grcmanager'),
            'url'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/soaapproval.php',
            'detail'     => $state === 'never_approved'
                ? __('Never approved', 'grcmanager')
                : sprintf(
                    __('Changed since version %s was approved', 'grcmanager'),
                    (string) ($approved['version'] ?? '')
                ),
        ]]];
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function treatmentActions(string $today, int $grace): ?array
    {
        global $DB;

        if (!RiskTreatmentAction::canView() || !Risk::canView()) {
            return null;
        }
        $actions = RiskTreatmentAction::getTable();
        $risks = Risk::getTable();
        $total = 0;
        $gaps = [];
        foreach (
            $DB->request([
                'SELECT'     => ["$actions.*", "$risks.title AS risk_title"],
                'FROM'       => $actions,
                'INNER JOIN' => [$risks => ['ON' => [$actions => 'plugin_grcmanager_risks_id', $risks => 'id']]],
                // Issue #536 : actions des risques des entités de la session.
                'WHERE'      => array_merge(
                    ['NOT' => ["$actions.status" => 'done']],
                    EntityPerimeter::criteria($risks),
                    EntityPerimeter::criteria($actions)
                ),
                'ORDER'      => "$actions.due_date",
            ]) as $row
        ) {
            $total++;
            $riskId = (int) $row['plugin_grcmanager_risks_id'];
            if (ConsistencyRules::treatmentActionOverdue($row, $today, $grace) && self::canRead(Risk::class, $riskId)) {
                $gaps[] = self::gap(Risk::class, $riskId, (string) $row['risk_title'], sprintf(
                    __('Action "%1$s" due on %2$s', 'grcmanager'),
                    mb_strimwidth(trim(strip_tags((string) $row['description'])), 0, 80, '...'),
                    Html::convDate((string) $row['due_date'])
                ));
            }
        }

        return ['total' => $total, 'gaps' => $gaps];
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function waivers(string $today): ?array
    {
        global $DB;

        if (!Waiver::canView()) {
            return null;
        }

        return self::collect(
            Waiver::class,
            $DB->request([
                'FROM'  => Waiver::getTable(),
                'WHERE' => ['is_deleted' => 0, 'status' => 'approved'],
                'ORDER' => 'date_end',
            ]),
            static fn (array $w): bool => true,
            static fn (array $w): ?string => ConsistencyRules::waiverExpired($w, $today)
                ? sprintf(__('Ended on %s', 'grcmanager'), Html::convDate((string) $w['date_end']))
                : null,
            static fn (array $w): string => (string) $w['name']
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function expiredEvidence(string $today): ?array
    {
        global $DB;

        if (!Evidence::canView()) {
            return null;
        }
        $table = Evidence::getTable();
        $where = [['NOT' => ["$table.valid_until" => null]]] + getEntitiesRestrictCriteria($table, '', '', true);
        $total = 0;
        $gaps = [];
        $evidence = new Evidence();
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => 'valid_until']) as $row) {
            $linked = Evidence::isDocumentLinked(
                (string) $row['itemtype'],
                (int) $row['items_id'],
                (int) $row['documents_id']
            );
            if (!$linked) {
                continue;
            }
            $total++;
            if (!ConsistencyRules::isExpired($row['valid_until'], $today) || !$evidence->can((int) $row['id'], READ)) {
                continue;
            }
            $parent = Evidence::loadParent((string) $row['itemtype'], (int) $row['items_id']);
            if ($parent === null) {
                continue;
            }
            $gaps[] = self::gap(
                (string) $row['itemtype'],
                (int) $row['items_id'],
                sprintf(
                    '%1$s: %2$s',
                    $parent instanceof Control ? self::controlName($parent->fields) : $parent->getName(),
                    Dropdown::getDropdownName('glpi_documents', (int) $row['documents_id'])
                ),
                sprintf(__('Valid until %s', 'grcmanager'), Html::convDate((string) $row['valid_until']))
            );
        }

        return ['total' => $total, 'gaps' => $gaps];
    }

    /**
     * Audit interne terminé ou revue de direction tenue récemment : un écart global, vers la liste.
     *
     * @param class-string<Audit|ManagementReview> $itemtype
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function recentEvent(string $itemtype, string $dateField, string $today, int $months): ?array
    {
        global $DB;

        if (!$itemtype::canView()) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['MAX' => "$dateField AS last_date"],
            'FROM'   => $itemtype::getTable(),
            // Issue #536 : audits (ou revues) des entités de la session, quand ils en ont une.
            'WHERE'  => array_merge(
                ['status' => 'completed'],
                (new $itemtype())->isEntityAssign() ? EntityPerimeter::criteria($itemtype::getTable()) : []
            ),
        ])->current();
        $last = $row['last_date'] ?? null;
        if (!ConsistencyRules::noRecentEvent($last !== null ? (string) $last : null, $today, $months)) {
            return ['total' => 1, 'gaps' => []];
        }

        return ['total' => 1, 'gaps' => [[
            'itemtype'   => '',
            'type_label' => $itemtype::getTypeName(Session::getPluralNumber()),
            'id'         => 0,
            'name'       => $itemtype::getTypeName(Session::getPluralNumber()),
            'url'        => $itemtype::getSearchURL(),
            'detail'     => $last === null
                ? __('None recorded as completed', 'grcmanager')
                : sprintf(__('Last one on %s', 'grcmanager'), Html::convDate((string) $last)),
        ]]];
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function nonconformities(string $key, string $today, int $grace): ?array
    {
        global $DB;

        if (!Nonconformity::canView()) {
            return null;
        }
        $closed = static fn (array $n): bool => in_array($n['status'], Nonconformity::CLOSED_STATUSES, true);
        if ($key === 'nonconformity_closed_without_action') {
            $candidate = static fn (array $n): bool => $closed($n) && $n['finding_type'] === 'nonconformity';
            $detail = static fn (array $n): ?string => ConsistencyRules::closedWithoutAction($n)
                ? __('Closed without a corrective action', 'grcmanager')
                : null;
        } else {
            $candidate = static fn (array $n): bool => !$closed($n);
            $detail = static fn (array $n): ?string => ConsistencyRules::nonconformityOverdue($n, $today, $grace)
                ? self::dueDetail($n['due_date'])
                : null;
        }

        return self::collect(
            Nonconformity::class,
            // Issue #536 : non-conformités des entités de la session.
            $DB->request([
                'FROM'  => Nonconformity::getTable(),
                'WHERE' => EntityPerimeter::criteria(Nonconformity::getTable()),
                'ORDER' => 'title',
            ]),
            $candidate,
            $detail,
            static fn (array $n): string => (string) $n['title']
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function supplierRisks(string $today, int $grace): ?array
    {
        global $DB;

        if (!SupplierRisk::canView()) {
            return null;
        }

        return self::collect(
            SupplierRisk::class,
            $DB->request([
                'FROM'  => SupplierRisk::getTable(),
                'WHERE' => ['NOT' => ['status' => 'closed']],
                'ORDER' => 'title',
            ]),
            static fn (array $r): bool => true,
            static fn (array $r): ?string => self::reviewDetail(
                $r['review_date'],
                ConsistencyRules::reviewState($r['review_date'], $today, $grace, true)
            ),
            static fn (array $r): string => (string) $r['title']
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function businessProcesses(): ?array
    {
        global $DB;

        if (!BusinessProcess::canView()) {
            return null;
        }
        $table = BusinessProcess::getTable();

        return self::collect(
            BusinessProcess::class,
            $DB->request([
                'FROM'  => $table,
                'WHERE' => getEntitiesRestrictCriteria($table, '', '', true),
                'ORDER' => 'name',
            ]),
            static fn (array $p): bool => true,
            static fn (array $p): ?string => ConsistencyRules::processWithoutRto($p)
                ? __('RTO not set', 'grcmanager')
                : null,
            static fn (array $p): string => (string) $p['name']
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function processingActivities(): ?array
    {
        global $DB;

        if (!ProcessingActivity::canView()) {
            return null;
        }
        $table = ProcessingActivity::getTable();

        return self::collect(
            ProcessingActivity::class,
            $DB->request([
                'FROM'  => $table,
                'WHERE' => ["$table.is_deleted" => 0, 'NOT' => ["$table.status" => 'archived']]
                    + getEntitiesRestrictCriteria($table, '', '', true),
                'ORDER' => 'name',
            ]),
            static fn (array $a): bool => true,
            static fn (array $a): ?string => ConsistencyRules::dpiaPending($a)
                ? __('DPIA required, not done', 'grcmanager')
                : null,
            static fn (array $a): string => (string) $a['name']
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function obligations(string $today, int $grace): ?array
    {
        global $DB;

        if (!ComplianceObligation::canView()) {
            return null;
        }

        return self::collect(
            ComplianceObligation::class,
            $DB->request(['FROM' => ComplianceObligation::getTable(), 'ORDER' => 'title']),
            static fn (array $o): bool => true,
            static fn (array $o): ?string => ConsistencyRules::isPastDue($o['review_date'], $today, $grace)
                ? self::reviewDetail($o['review_date'], 'overdue')
                : null,
            static fn (array $o): string => (string) $o['title']
        );
    }

    /**
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function accessReviews(string $today, int $grace): ?array
    {
        global $DB;

        if (!AccessReview::canView()) {
            return null;
        }
        $table = AccessReview::getTable();

        return self::collect(
            AccessReview::class,
            $DB->request([
                'FROM'  => $table,
                'WHERE' => ['NOT' => ["$table.status" => 'closed']] + getEntitiesRestrictCriteria($table, '', '', true),
                'ORDER' => 'due_date',
            ]),
            static fn (array $c): bool => true,
            static fn (array $c): ?string => ConsistencyRules::accessReviewOverdue($c, $today, $grace)
                ? self::dueDetail($c['due_date'])
                : null,
            static fn (array $c): string => (string) $c['name']
        );
    }

    /**
     * Issue #704 : actifs visibles portant un élément plus supporté (endoflife.date). Non évaluable
     * tant que la source est désactivée.
     *
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function endOfLifeAssets(string $today): ?array
    {
        $assets = EndOfLife::unsupportedAssets($today);
        if ($assets === null) {
            return null;
        }
        $gaps = [];
        foreach ($assets as $asset) {
            if (!is_a($asset['itemtype'], CommonDBTM::class, true)) {
                continue;
            }
            $gaps[] = self::gap(
                $asset['itemtype'],
                $asset['items_id'],
                $asset['item_name'],
                sprintf(__('No longer supported: %s', 'grcmanager'), implode(', ', $asset['details']))
            );
        }

        return ['total' => count($assets), 'gaps' => $gaps];
    }

    /**
     * Issue #782 : matériels visibles dont la garantie est expirée (A.7.13). Non évaluable sans le
     * droit des registres sensibles et la lecture des informations financières.
     *
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function expiredWarranties(string $today): ?array
    {
        $findings = HardwareWarranty::consistencyGaps($today);
        if ($findings === null) {
            return null;
        }
        $gaps = [];
        foreach ($findings as $finding) {
            if (!is_a($finding['itemtype'], CommonDBTM::class, true)) {
                continue;
            }
            $gaps[] = self::gap($finding['itemtype'], $finding['items_id'], $finding['name'], $finding['detail']);
        }

        return ['total' => count($findings), 'gaps' => $gaps];
    }

    /**
     * Issue #779 : licences logicielles (A.5.32), un écart par constat. Non évaluable sans le droit des
     * registres sensibles et la lecture des logiciels et des licences.
     *
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    private static function softwareLicenseGaps(string $today): ?array
    {
        $findings = SoftwareLicenseCompliance::consistencyGaps($today);
        if ($findings === null) {
            return null;
        }
        $gaps = [];
        foreach ($findings as $finding) {
            $gaps[] = self::gap($finding['itemtype'], $finding['items_id'], $finding['name'], $finding['detail']);
        }

        return ['total' => count($findings), 'gaps' => $gaps];
    }

    // ------------------------------------------------------------------ Présentation et export.

    /**
     * Données de la page et des exports : synthèse, puis règles évaluées regroupées par domaine,
     * filtrées.
     *
     * @param array{area: string, severity: string, rule: string} $filters
     * @param array<string, array<string, mixed>>|null $results résultat de run() (calculé sinon)
     * @return array<string, mixed>
     */
    public static function report(array $filters, ?array $results = null): array
    {
        $results ??= self::run();
        $summary = self::summarize($results);
        $texts = self::getRuleTexts();
        $areaLabels = self::getAreaLabels();
        $severities = self::getSeverities();
        $deltas = ConsistencyRules::deltas($summary['counts'], self::previousCounts());
        $areas = [];
        foreach ($results as $key => $result) {
            if (!$result['enabled'] || !$result['evaluated'] || !ConsistencyRules::matches($key, $filters)) {
                continue;
            }
            $area = (string) $result['area'];
            $areas[$area] ??= [
                'key'    => $area,
                'label'  => $areaLabels[$area],
                'clause' => $area === 'operations' ? __('Annex A', 'grcmanager') : ConsistencyRules::AREAS[$area],
                'gaps'   => 0,
                'rules'  => [],
            ];
            $areas[$area]['gaps'] += count($result['gaps']);
            $areas[$area]['rules'][] = [
                'key'       => $key,
                'label'     => $texts[$key]['label'],
                'fix'       => $texts[$key]['fix'],
                'clause'    => $result['clause'],
                'severity'  => $severities[$result['severity']] + ['key' => $result['severity']],
                'total'     => $result['total'],
                'gap_count' => count($result['gaps']),
                'gaps'      => $result['gaps'],
                'delta'     => $deltas[$key] ?? null,
            ];
        }

        return [
            'summary'    => $summary,
            'areas'      => array_values($areas),
            'filters'    => $filters,
            'severities' => $severities,
        ];
    }

    /**
     * Rapport PDF (HTML rendu par Twig, converti par PdfRenderer).
     *
     * @param array<string, mixed> $report résultat de report()
     */
    public static function renderPdf(array $report): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/consistency_report.html.twig', [
            'report_title'       => __('ISMS consistency checks: audit readiness', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
        ] + $report);
    }

    // ------------------------------------------------------------------ Tendance mensuelle.

    /**
     * Enregistre (ou remplace) le résumé du mois pour la personne et son entité active : score,
     * nombre d'écarts et nombre par règle, jamais les éléments eux-mêmes.
     *
     * @param array<string, mixed> $summary résultat de summarize()
     */
    public static function snapshot(array $summary): void
    {
        global $DB;

        $userId = (int) Session::getLoginUserID();
        if ($userId <= 0) {
            return;
        }
        $key = ['users_id' => $userId, 'entities_id' => Session::getActiveEntity(), 'month' => date('Y-m')];
        $fields = [
            'score'    => $summary['score'],
            'gaps'     => (int) $summary['gaps'],
            'details'  => json_encode($summary['counts']),
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        if ($DB->request(['FROM' => self::SNAPSHOTS_TABLE, 'WHERE' => $key])->count() > 0) {
            $DB->update(self::SNAPSHOTS_TABLE, $fields, $key);
        } else {
            $DB->insert(self::SNAPSHOTS_TABLE, $key + $fields);
        }
    }

    /**
     * Les 12 derniers mois enregistrés pour la personne et son entité active, du plus ancien au
     * plus récent.
     *
     * @return list<array{month: string, score: ?int, gaps: int}>
     */
    public static function history(): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::SNAPSHOTS_TABLE,
                'WHERE' => ['users_id' => (int) Session::getLoginUserID(), 'entities_id' => Session::getActiveEntity()],
                'ORDER' => 'month DESC',
                'LIMIT' => 12,
            ]) as $row
        ) {
            $rows[] = [
                'month' => (string) $row['month'],
                'score' => $row['score'] !== null ? (int) $row['score'] : null,
                'gaps'  => (int) $row['gaps'],
            ];
        }

        return array_reverse($rows);
    }

    /**
     * Écarts par règle du dernier mois enregistré avant le mois en cours.
     *
     * @return array<string, mixed>
     */
    private static function previousCounts(): array
    {
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::SNAPSHOTS_TABLE,
                'WHERE' => [
                    'users_id'    => (int) Session::getLoginUserID(),
                    'entities_id' => Session::getActiveEntity(),
                    'month'       => ['<', date('Y-m')],
                ],
                'ORDER' => 'month DESC',
                'LIMIT' => 1,
            ]) as $row
        ) {
            $counts = json_decode((string) $row['details'], true);

            return is_array($counts) ? $counts : [];
        }

        return [];
    }
}
