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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\Control\ControlBlockerRules as Rules;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Control\SentinelOneEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidence;
use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use Html;
use Session;

/**
 * Issue #867 : ce qui empêche chaque mesure de la SoA d'être conforme, en une seule liste.
 *
 * - onglet « Ce qui bloque cette mesure » de la fiche d'une mesure ;
 * - vue globale « Mesures bloquées » (sous-page de la rubrique Conformité), triable.
 *
 * Rien n'est recalculé : les écarts viennent des contrôles de cohérence (ConsistencyCheck : preuves
 * manquantes ou expirées, dérogations expirées, politiques non revues, justifications), des tests
 * d'efficacité (dernier résultat, échéance du plan de test), des non-conformités ouvertes, du statut
 * des politiques et des contrôles techniques automatiques (ComplianceAudit, couverture Wazuh et
 * SentinelOne). Chaque ligne renvoie à l'écran à corriger. Lecture seule : rien n'est modifié.
 *
 * Droits : lecture de la SoA ; chaque source garde ses propres droits et un élément que la personne
 * ne peut pas lire (droit, entité) n'est jamais cité.
 */
class ControlBlocker extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return _n('Blocked control', 'Blocked controls', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-barrier-block';
    }

    public static function canView(): bool
    {
        return Control::canView();
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/controlblocker.php';
    }

    /** Sous-page de la rubrique « Conformité » (Menu::sections()). */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function getControlTabURL(int $controlsId): string
    {
        return Control::getFormURLWithID($controlsId) . '&forcetab=' . rawurlencode(self::class . '$1');
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function getCategories(): array
    {
        $categories = [
            Rules::CATEGORY_TECHNICAL     => [__('Technical check', 'grcmanager'), 'ti-cpu'],
            Rules::CATEGORY_EVIDENCE      => [__('Evidence', 'grcmanager'), 'ti-file-certificate'],
            Rules::CATEGORY_POLICY        => [__('Policy', 'grcmanager'), 'ti-file-text'],
            Rules::CATEGORY_TEST          => [__('Effectiveness test', 'grcmanager'), 'ti-checklist'],
            Rules::CATEGORY_NONCONFORMITY => [__('Nonconformity', 'grcmanager'), 'ti-alert-circle'],
            Rules::CATEGORY_WAIVER        => [__('Waiver', 'grcmanager'), 'ti-hourglass-off'],
            Rules::CATEGORY_SOA           => [__('Statement of Applicability', 'grcmanager'), 'ti-list-check'],
        ];

        return array_map(static fn (array $c): array => ['label' => $c[0], 'icon' => $c[1]], $categories);
    }

    /**
     * Gravités, de la plus haute à la plus basse (libellés et icônes des contrôles de cohérence).
     *
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getSeverityStyles(): array
    {
        $classes = [
            ConsistencyRules::SEVERITY_HIGH   => 'bg-danger-subtle text-danger-emphasis',
            ConsistencyRules::SEVERITY_MEDIUM => 'bg-orange-subtle text-orange-emphasis',
            ConsistencyRules::SEVERITY_LOW    => 'bg-warning-subtle text-warning-emphasis',
        ];
        $styles = [];
        foreach (ConsistencyCheck::getSeverities() as $key => $severity) {
            $styles[$key] = ['class' => $classes[$key] ?? 'bg-secondary-subtle text-secondary-emphasis'] + $severity;
        }

        return $styles;
    }

    // ---------------------------------------------------------------------------------------------
    // Collecte
    // ---------------------------------------------------------------------------------------------

    /**
     * Mesures et leurs blocages : une seule mesure ($controlId), sinon la SoA de l'entité active.
     *
     * @return array<int, array{control: array<string, mixed>, blockers: list<array<string, mixed>>}>
     */
    public static function collect(?int $controlId = null, ?string $today = null): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $today ??= substr((string) (Session::getCurrentTime() ?? date('Y-m-d H:i:s')), 0, 10);
        $where = $controlId !== null ? ['id' => $controlId] : EntityPerimeter::controlCriteria();
        $item = new Control();
        $controls = [];
        $byCode = [];
        foreach ($DB->request(['FROM' => Control::getTable(), 'WHERE' => $where]) as $row) {
            if (!$item->can((int) $row['id'], READ)) {
                continue;
            }
            $controls[(int) $row['id']] = ['control' => $row, 'blockers' => []];
            $byCode[(string) $row['code']][] = (int) $row['id'];
        }
        if ($controls === []) {
            return [];
        }

        $add = static function (array $ids, array $blocker) use (&$controls): void {
            foreach ($ids as $id) {
                if (isset($controls[$id])) {
                    $controls[$id]['blockers'][] = $blocker;
                }
            }
        };
        $applicable = static fn (int $id): bool => ConsistencyRules::isApplicable($controls[$id]['control']);

        $ncControls = self::nonconformityControls($byCode);
        self::addConsistencyGaps($today, $byCode, $ncControls, $add);
        self::addTests($controls, $today, $add);
        self::addNonconformities($ncControls, $today, $add);
        self::addPolicies($byCode, $add);
        self::addTechnicalChecks($byCode, $applicable, $add);
        self::addCoverage($byCode, $applicable, $add);

        foreach ($controls as $id => $entry) {
            $controls[$id]['blockers'] = Rules::sortBlockers($entry['blockers']);
        }

        return $controls;
    }

    /**
     * @return array<string, mixed>
     */
    private static function blocker(
        string $category,
        string $severity,
        string $name,
        string $detail,
        string $url,
        string $fix = ''
    ): array {
        return [
            'category' => $category,
            'severity' => $severity,
            'name'     => $name,
            'detail'   => $detail,
            'url'      => $url,
            'fix'      => $fix,
        ];
    }

    /**
     * Écarts des contrôles de cohérence rattachés à une mesure : la mesure elle-même, ses preuves,
     * ses dérogations ; une politique (ou sa preuve, sa dérogation) bloque la mesure A.5.1.
     *
     * @param array<string, list<int>> $byCode
     * @param array<int, list<int>> $ncControls
     */
    private static function addConsistencyGaps(string $today, array $byCode, array $ncControls, callable $add): void
    {
        global $DB;

        $results = ConsistencyCheck::run($today, array_keys(Rules::CONSISTENCY_RULES));
        $texts = ConsistencyCheck::getRuleTexts();
        $policyControls = $byCode[Rules::POLICY_CONTROL] ?? [];

        $waiverIds = [];
        foreach ($results['waiver_expired']['gaps'] ?? [] as $gap) {
            $waiverIds[] = (int) $gap['id'];
        }
        $waivers = [];
        if ($waiverIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'plugin_grcmanager_controls_id', 'plugin_grcmanager_policies_id'],
                    'FROM'   => Waiver::getTable(),
                    'WHERE'  => ['id' => $waiverIds],
                ]) as $row
            ) {
                $waivers[(int) $row['id']] = $row;
            }
        }

        foreach (Rules::CONSISTENCY_RULES as $rule => $category) {
            $result = $results[$rule] ?? null;
            if ($result === null || !$result['evaluated']) {
                continue;
            }
            foreach ($result['gaps'] as $gap) {
                $id = (int) $gap['id'];
                $targets = match ((string) $gap['itemtype']) {
                    Control::class       => [$id],
                    Policy::class        => $policyControls,
                    Nonconformity::class => $ncControls[$id] ?? [],
                    Waiver::class        => isset($waivers[$id])
                        ? ((int) $waivers[$id]['plugin_grcmanager_controls_id'] > 0
                            ? [(int) $waivers[$id]['plugin_grcmanager_controls_id']]
                            : ((int) $waivers[$id]['plugin_grcmanager_policies_id'] > 0 ? $policyControls : []))
                        : [],
                    default              => [],
                };
                if ($targets === []) {
                    continue;
                }
                $isControl = $gap['itemtype'] === Control::class;
                $add($targets, self::blocker(
                    $category,
                    (string) $result['severity'],
                    $isControl ? (string) ($texts[$rule]['label'] ?? $rule) : (string) $gap['name'],
                    $isControl ? (string) $gap['detail'] : sprintf(
                        __('%1$s: %2$s', 'grcmanager'),
                        (string) ($texts[$rule]['label'] ?? $rule),
                        (string) $gap['detail']
                    ),
                    // Pas de preuve valide : l'onglet Documents de la mesure.
                    $rule === 'control_without_evidence'
                        ? Control::getFormURLWithID($id) . '&forcetab=Document_Item$1'
                        : (string) $gap['url'],
                    (string) ($texts[$rule]['fix'] ?? '')
                ));
            }
        }
    }

    /**
     * Dernier test d'efficacité inefficace ou partiel, et test en retard sur le plan de test.
     *
     * @param array<int, array{control: array<string, mixed>, blockers: list<array<string, mixed>>}> $controls
     */
    private static function addTests(array $controls, string $today, callable $add): void
    {
        global $DB;

        if (!ControlTest::canView()) {
            return;
        }
        $latest = [];
        foreach (
            $DB->request([
                'FROM'  => ControlTest::getTable(),
                'WHERE' => ['plugin_grcmanager_controls_id' => array_keys($controls)],
                'ORDER' => ['test_date DESC', 'id DESC'],
            ]) as $row
        ) {
            $latest[(int) $row['plugin_grcmanager_controls_id']] ??= $row;
        }
        $results = ControlTest::getResults();
        foreach ($controls as $id => $entry) {
            $test = $latest[$id] ?? null;
            if ($test === null || !ConsistencyRules::isApplicable($entry['control'])) {
                continue;
            }
            $next = ControlTest::nextDueDate($entry['control'], $test);
            $blockers = Rules::testBlockers((string) $test['result'], ControlTestRules::dueStatus($next, $today));
            foreach ($blockers as $blocker) {
                $add([$id], self::blocker(
                    Rules::CATEGORY_TEST,
                    $blocker['severity'],
                    $blocker['kind'] === 'overdue'
                        ? __('Effectiveness test overdue', 'grcmanager')
                        : sprintf(
                            __('Last test: %s', 'grcmanager'),
                            $results[(string) $test['result']]['label'] ?? ''
                        ),
                    $blocker['kind'] === 'overdue'
                        ? sprintf(__('Next test was due on %s', 'grcmanager'), Html::convDate((string) $next))
                        : sprintf(__('Tested on %s', 'grcmanager'), Html::convDate((string) $test['test_date'])),
                    ControlTest::getControlTabURL($id),
                    $blocker['kind'] === 'overdue'
                        ? __('Test the control again and record the result.', 'grcmanager')
                        : __('Fix the weakness found by the test, then test the control again.', 'grcmanager')
                ));
            }
        }
    }

    /**
     * Mesures citées par chaque non-conformité des entités de la session : par sa référence
     * (« Clause ou mesure ») ou par le test inefficace qui l'a ouverte.
     *
     * @param array<string, list<int>> $byCode
     * @return array<int, list<int>> identifiant de la non-conformité => mesures
     */
    private static function nonconformityControls(array $byCode): array
    {
        global $DB;

        if (!Nonconformity::canView()) {
            return [];
        }
        $links = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'requirement_reference'],
                'FROM'   => Nonconformity::getTable(),
                'WHERE'  => array_merge(EntityPerimeter::criteria(Nonconformity::getTable()), [
                    ['NOT' => ['requirement_reference' => null]],
                    ['NOT' => ['requirement_reference' => '']],
                ]),
            ]) as $row
        ) {
            foreach ($byCode as $code => $ids) {
                if (Rules::referencesControl((string) $row['requirement_reference'], (string) $code)) {
                    $links[(int) $row['id']] = array_merge($links[(int) $row['id']] ?? [], $ids);
                }
            }
        }
        $controlIds = array_merge(...array_values($byCode));
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_controls_id', 'plugin_grcmanager_nonconformities_id'],
                'FROM'   => ControlTest::getTable(),
                'WHERE'  => [
                    'plugin_grcmanager_controls_id'        => $controlIds,
                    'plugin_grcmanager_nonconformities_id' => ['>', 0],
                ],
            ]) as $row
        ) {
            $links[(int) $row['plugin_grcmanager_nonconformities_id']][] = (int) $row['plugin_grcmanager_controls_id'];
        }

        return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $links);
    }

    /**
     * Non-conformités ouvertes rattachées à une mesure (lisibles par la personne).
     *
     * @param array<int, list<int>> $ncControls
     */
    private static function addNonconformities(array $ncControls, string $today, callable $add): void
    {
        global $DB;

        if ($ncControls === []) {
            return;
        }
        $grace = ConsistencyCheck::getSettings()['nonconformity_overdue']['threshold'] ?? 0;
        $grace = is_int($grace) ? $grace : 0;
        $item = new Nonconformity();
        foreach (
            $DB->request([
                'FROM'  => Nonconformity::getTable(),
                'WHERE' => ['id' => array_keys($ncControls), ['NOT' => ['status' => Nonconformity::CLOSED_STATUSES]]],
            ]) as $row
        ) {
            if (!$item->can((int) $row['id'], READ)) {
                continue;
            }
            $overdue = ConsistencyRules::nonconformityOverdue($row, $today, $grace);
            $add($ncControls[(int) $row['id']], self::blocker(
                Rules::CATEGORY_NONCONFORMITY,
                Rules::nonconformitySeverity((string) $row['severity'], $overdue),
                (string) $row['title'] !== ''
                    ? (string) $row['title']
                    : sprintf(__('%1$s (ID %2$d)', 'grcmanager'), Nonconformity::getTypeName(1), (int) $row['id']),
                $overdue
                    ? sprintf(__('Open, due on %s', 'grcmanager'), Html::convDate((string) $row['due_date']))
                    : __('Open', 'grcmanager'),
                Nonconformity::getFormURLWithID((int) $row['id']),
                __('Carry out the corrective action, then close the nonconformity.', 'grcmanager')
            ));
        }
    }

    /**
     * Politiques encore en brouillon : non approuvées, elles bloquent la mesure A.5.1.
     *
     * @param array<string, list<int>> $byCode
     */
    private static function addPolicies(array $byCode, callable $add): void
    {
        global $DB;

        $targets = $byCode[Rules::POLICY_CONTROL] ?? [];
        if ($targets === [] || !Policy::canView()) {
            return;
        }
        $item = new Policy();
        $drafts = [
            'FROM'  => Policy::getTable(),
            'WHERE' => ['status' => PolicyLifecycle::STATUS_DRAFT],
            'ORDER' => 'title',
        ];
        foreach ($DB->request($drafts) as $row) {
            if (!$item->can((int) $row['id'], READ)) {
                continue;
            }
            $add($targets, self::blocker(
                Rules::CATEGORY_POLICY,
                ConsistencyRules::SEVERITY_MEDIUM,
                (string) $row['title'],
                __('Not approved yet', 'grcmanager'),
                Policy::getFormURLWithID((int) $row['id']),
                __('Have the policy approved by management.', 'grcmanager')
            ));
        }
    }

    /**
     * Contrôles techniques automatiques rattachés aux mesures applicables, résultat partiel ou
     * inefficace (même calcul que l'onglet des tests).
     *
     * @param array<string, list<int>> $byCode
     */
    private static function addTechnicalChecks(array $byCode, callable $applicable, callable $add): void
    {
        if (!ComplianceAudit::canView()) {
            return;
        }
        $labels = null;
        foreach ($byCode as $code => $ids) {
            $ids = array_values(array_filter($ids, $applicable));
            if ($ids === []) {
                continue;
            }
            foreach (ControlTestRules::technicalChecksFor((string) $code, ComplianceAuditScore::CHECKS) as $check) {
                $outcome = ComplianceAudit::runCheck($check);
                if (!$outcome['evaluated']) {
                    continue;
                }
                $gaps = count($outcome['gaps']);
                $severity = Rules::technicalSeverity(
                    ControlTestRules::resultFromTechnicalCheck($gaps, (int) $outcome['total'])
                );
                if ($severity === null) {
                    continue;
                }
                $labels ??= ComplianceAudit::getCheckLabels();
                $add($ids, self::blocker(
                    Rules::CATEGORY_TECHNICAL,
                    $severity,
                    (string) ($labels[$check]['label'] ?? $check),
                    sprintf(
                        __('%1$d gaps out of %2$d, %3$d%% compliant', 'grcmanager'),
                        $gaps,
                        (int) $outcome['total'],
                        (int) ComplianceAuditScore::rate($gaps, (int) $outcome['total'])
                    ),
                    ComplianceAudit::getSearchURL(),
                    __('Fix the items listed by the automated check.', 'grcmanager')
                ));
            }
        }
    }

    /**
     * Couverture Wazuh et SentinelOne du parc (A.8.16, A.8.7) : ordinateurs sans agent actif.
     *
     * @param array<string, list<int>> $byCode
     */
    private static function addCoverage(array $byCode, callable $applicable, callable $add): void
    {
        $ids = [];
        foreach (WazuhEvidence::CONTROLS as $code) {
            $ids = array_merge($ids, array_values(array_filter($byCode[$code] ?? [], $applicable)));
        }
        if ($ids === []) {
            return;
        }
        $sources = [
            'Wazuh'       => [WazuhEvidenceProvider::fetchCoverage(), WazuhEvidenceProvider::agentsUrl()],
            'SentinelOne' => [SentinelOneEvidenceProvider::fetchCoverage(), null],
        ];
        foreach ($sources as $tool => [$coverage, $url]) {
            if ($coverage === null || (int) $coverage['computers'] <= 0) {
                continue;
            }
            $missing = (int) $coverage['computers'] - (int) $coverage['covered'];
            $severity = Rules::technicalSeverity(
                ControlTestRules::resultFromTechnicalCheck($missing, (int) $coverage['computers'])
            );
            if ($severity === null) {
                continue;
            }
            foreach ($ids as $id) {
                $add([$id], self::blocker(
                    Rules::CATEGORY_TECHNICAL,
                    $severity,
                    sprintf(__('%s coverage', 'grcmanager'), $tool),
                    sprintf(
                        __('%1$d computers out of %2$d without an active agent', 'grcmanager'),
                        $missing,
                        (int) $coverage['computers']
                    ),
                    $url ?? Control::getFormURLWithID($id),
                    __('Install or reactivate the agent on the computers not covered.', 'grcmanager')
                ));
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Affichage
    // ---------------------------------------------------------------------------------------------

    /**
     * Lignes de la vue globale : mesures qui ont au moins un blocage, triées.
     *
     * @param array<int, array{control: array<string, mixed>, blockers: list<array<string, mixed>>}> $collected
     * @return list<array<string, mixed>>
     */
    public static function rows(array $collected, string $sort, string $order): array
    {
        $rows = [];
        foreach ($collected as $id => $entry) {
            if ($entry['blockers'] === []) {
                continue;
            }
            $code = (string) $entry['control']['code'];
            $rows[] = [
                'id'       => $id,
                'code'     => $code,
                'title'    => Control::getControlTitle($code),
                'url'      => self::getControlTabURL($id),
                'summary'  => Rules::summarize($entry['blockers']),
                'blockers' => $entry['blockers'],
            ];
        }

        return Rules::sortRows($rows, $sort, $order);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Control || $item->isNewItem() || !self::canView()) {
            return '';
        }

        // Pas de compteur : il faudrait évaluer toutes les sources à chaque ouverture de la fiche.
        return self::createTabEntry(__('What blocks this control', 'grcmanager'), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Control || !self::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        $entry = self::collect($item->getID())[$item->getID()] ?? ['blockers' => []];
        TemplateRenderer::getInstance()->display('@grcmanager/control_blockers_tab.html.twig', [
            'blockers'   => $entry['blockers'],
            'summary'    => Rules::summarize($entry['blockers']),
            'categories' => self::getCategories(),
            'severities' => self::getSeverityStyles(),
            'list_url'   => self::getSearchURL(),
        ]);

        return true;
    }
}
