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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\PolicyCoverageTables;
use GlpiPlugin\Grcmanager\Services\Consistency\ConsistencyRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyCoverageRules as Rules;
use Html;
use Session;

/**
 * Issue #879 : chaque mesure applicable de la SoA est-elle couverte par au moins un texte approuvé
 * de la bibliothèque des politiques (clause 5.2, mesure A.5.1) ?
 *
 * - onglet « Mesures couvertes » d'une politique : relier la politique entière, ou une de ses
 *   sections, à plusieurs mesures d'un coup (liste ou codes saisis : « A.5.15-A.5.18, A.8 ») ;
 * - onglet « Textes de référence » d'une mesure : les textes qui la couvrent, et leur état ;
 * - rapport des mesures applicables non couvertes, couvertes seulement par un texte non approuvé
 *   ou à réviser (sous-page de la rubrique Conformité), exportable en CSV et en PDF ;
 * - règle des contrôles de cohérence control_without_approved_policy.
 *
 * Droits : lecture de la SoA et des politiques ; relier ou retirer un lien demande le droit de
 * modifier la politique. Seules les mesures des entités de la session sont montrées ou reliées.
 * Aucun statut n'est modifié : le rapport dit seulement quoi faire.
 */
class PolicyCoverage extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Control coverage by policies', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-check';
    }

    public static function canView(): bool
    {
        return Control::canView() && Policy::canView();
    }

    /** Relier ou retirer un lien : droit de modifier les politiques (cast : int sous GLPI 11). */
    public static function canEditLinks(): bool
    {
        return self::canView() && (bool) Policy::canUpdate();
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/policycoverage.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/policycoverage.form.php';
    }

    public static function getExportURL(string $format, string $filter): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/policycoverage.export.php?'
            . http_build_query(['format' => $format, 'filter' => $filter]);
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

    public static function getPolicyTabURL(int $policiesId): string
    {
        return Policy::getFormURLWithID($policiesId) . '&forcetab=' . rawurlencode(self::class . '$1');
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getStatusStyles(): array
    {
        return [
            Rules::STATUS_UNCOVERED    => [
                'label' => __('Not covered by any text', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-file-off',
            ],
            Rules::STATUS_NOT_APPROVED => [
                'label' => __('Covered only by a text not approved', 'grcmanager'),
                'class' => 'bg-orange-subtle text-orange-emphasis',
                'icon'  => 'ti-file-pencil',
            ],
            Rules::STATUS_REVIEW_DUE   => [
                'label' => __('Covered only by a text to review', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-calendar-exclamation',
            ],
            Rules::STATUS_COVERED      => [
                'label' => __('Covered by an approved text', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-file-check',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getTextStyles(): array
    {
        return [
            Rules::TEXT_APPROVED     => [
                'label' => __('Approved', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-circle-check',
            ],
            Rules::TEXT_REVIEW_DUE   => [
                'label' => __('Review overdue', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-calendar-exclamation',
            ],
            Rules::TEXT_NOT_APPROVED => [
                'label' => __('Not approved', 'grcmanager'),
                'class' => 'bg-orange-subtle text-orange-emphasis',
                'icon'  => 'ti-file-pencil',
            ],
        ];
    }

    private static function today(): string
    {
        return substr((string) (Session::getCurrentTime() ?? date('Y-m-d H:i:s')), 0, 10);
    }

    // ---------------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------------

    /**
     * Mesures lisibles (une seule, ou la SoA de l'entité active) et les textes lisibles qui les
     * couvrent, avec l'état de la mesure (null pour une mesure non applicable).
     *
     * @return array<int, array{control: array<string, mixed>, texts: list<array<string, mixed>>,
     *     applicable: bool, status: ?string}>
     */
    public static function collect(?int $controlId = null, ?string $today = null): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $today ??= self::today();
        $where = $controlId !== null ? ['id' => $controlId] : EntityPerimeter::controlCriteria();
        $item = new Control();
        $controls = [];
        foreach ($DB->request(['FROM' => Control::getTable(), 'WHERE' => $where]) as $row) {
            if ($item->can((int) $row['id'], READ)) {
                $controls[(int) $row['id']] = [
                    'control' => $row, 'texts' => [], 'applicable' => false, 'status' => null,
                ];
            }
        }
        if ($controls === []) {
            return [];
        }

        $links = iterator_to_array($DB->request([
            'FROM'  => PolicyCoverageTables::LINKS,
            'WHERE' => ['plugin_grcmanager_controls_id' => array_keys($controls)],
            'ORDER' => ['section', 'id'],
        ]), false);
        $policies = self::readablePolicies(array_map(
            static fn (array $l): int => (int) $l['plugin_grcmanager_policies_id'],
            $links
        ));
        $styles = self::getTextStyles();
        foreach ($links as $link) {
            $policy = $policies[(int) $link['plugin_grcmanager_policies_id']] ?? null;
            if ($policy === null) {
                continue;
            }
            $state = Rules::textState($policy, $today);
            $controls[(int) $link['plugin_grcmanager_controls_id']]['texts'][] = [
                'link_id'     => (int) $link['id'],
                'policy_id'   => (int) $policy['id'],
                'title'       => (string) $policy['title'],
                'version'     => (string) ($policy['version'] ?? ''),
                'url'         => Policy::getFormURLWithID((int) $policy['id']),
                'section'     => (string) $link['section'],
                'next_review' => (string) ($policy['next_review_date'] ?? ''),
                'next_review_label' => (string) ($policy['next_review_date'] ?? '') !== ''
                    ? (string) Html::convDate((string) $policy['next_review_date'])
                    : '-',
                'state'       => $state,
                'style'       => $styles[$state],
            ];
        }
        foreach ($controls as $id => $entry) {
            $applicable = ConsistencyRules::isApplicable($entry['control']);
            $controls[$id]['applicable'] = $applicable;
            $controls[$id]['status'] = $applicable
                ? Rules::controlStatus(array_column($entry['texts'], 'state'))
                : null;
        }

        return $controls;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function readablePolicies(array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $item = new Policy();
        $policies = [];
        foreach ($DB->request(['FROM' => Policy::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
            if ($item->can((int) $row['id'], READ)) {
                $policies[(int) $row['id']] = $row;
            }
        }

        return $policies;
    }

    /**
     * Lignes du rapport : mesures applicables, dans l'ordre de l'Annexe A, filtrées sur les écarts.
     *
     * @param array<int, array<string, mixed>> $collected résultat de collect()
     * @return list<array<string, mixed>>
     */
    public static function rows(array $collected, string $filter): array
    {
        $styles = self::getStatusStyles();
        $rows = [];
        foreach ($collected as $id => $entry) {
            if (!$entry['applicable']) {
                continue;
            }
            $status = (string) $entry['status'];
            if ($filter === Rules::FILTER_GAPS && !Rules::isGap($status)) {
                continue;
            }
            $code = (string) $entry['control']['code'];
            $rows[] = [
                'id'     => $id,
                'code'   => $code,
                'title'  => Control::getControlTitle($code),
                'url'    => self::getControlTabURL($id),
                'status' => $status,
                'style'  => $styles[$status],
                'texts'  => $entry['texts'],
            ];
        }
        usort($rows, static fn (array $x, array $y): int => strnatcmp($x['code'], $y['code']));

        return $rows;
    }

    /**
     * Rapport complet (page, CSV, PDF).
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>, filter: string,
     *     linked: bool}
     */
    public static function report(string $filter, ?string $today = null): array
    {
        $collected = self::collect(null, $today);
        $statuses = [];
        $linked = false;
        foreach ($collected as $entry) {
            $linked = $linked || $entry['texts'] !== [];
            if ($entry['applicable']) {
                $statuses[] = (string) $entry['status'];
            }
        }

        return [
            'rows'    => self::rows($collected, $filter),
            'summary' => Rules::summarize($statuses),
            'filter'  => $filter,
            'linked'  => $linked,
        ];
    }

    /**
     * Écarts du contrôle de cohérence control_without_approved_policy. Non évaluée tant qu'aucun
     * texte n'est relié à une mesure de la SoA (fonction pas encore utilisée).
     *
     * @return array{total: int, gaps: list<array<string, mixed>>}|null
     */
    public static function consistencyGaps(string $today): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $report = self::report(Rules::FILTER_GAPS, $today);
        if (!$report['linked']) {
            return null;
        }
        $gaps = [];
        foreach ($report['rows'] as $row) {
            $gaps[] = [
                'itemtype'   => Control::class,
                'type_label' => Control::getTypeName(1),
                'id'         => $row['id'],
                'name'       => $row['code'] . ' ' . $row['title'],
                'url'        => $row['url'],
                'detail'     => $row['style']['label'],
            ];
        }

        return ['total' => $report['summary']['total'], 'gaps' => $gaps];
    }

    /**
     * Liens lisibles d'une politique, par section puis par code.
     *
     * @return list<array<string, mixed>>
     */
    public static function policyLinks(int $policyId): array
    {
        global $DB;

        $links = iterator_to_array($DB->request([
            'FROM'  => PolicyCoverageTables::LINKS,
            'WHERE' => ['plugin_grcmanager_policies_id' => $policyId],
        ]), false);
        $controlIds = array_values(array_unique(array_map(
            static fn (array $l): int => (int) $l['plugin_grcmanager_controls_id'],
            $links
        )));
        $controls = [];
        if ($controlIds !== []) {
            $item = new Control();
            foreach ($DB->request(['FROM' => Control::getTable(), 'WHERE' => ['id' => $controlIds]]) as $row) {
                if ($item->can((int) $row['id'], READ)) {
                    $controls[(int) $row['id']] = $row;
                }
            }
        }
        $rows = [];
        foreach ($links as $link) {
            $control = $controls[(int) $link['plugin_grcmanager_controls_id']] ?? null;
            if ($control === null) {
                continue;
            }
            $code = (string) $control['code'];
            $rows[] = [
                'id'         => (int) $link['id'],
                'section'    => (string) $link['section'],
                'code'       => $code,
                'title'      => Control::getControlTitle($code),
                'url'        => self::getControlTabURL((int) $control['id']),
                'applicable' => ConsistencyRules::isApplicable($control),
                'entity'     => (string) \Dropdown::getDropdownName('glpi_entities', (int) $control['entities_id']),
            ];
        }
        usort($rows, static fn (array $x, array $y): int => strcmp($x['section'], $y['section'])
            ?: strnatcmp($x['code'], $y['code']));

        return $rows;
    }

    /**
     * Mesures de la SoA de l'entité active, proposées à la liaison : id => « A.5.1 Intitulé ».
     *
     * @return array<int, string>
     */
    public static function controlChoices(): array
    {
        $choices = [];
        foreach (self::activeControls() as $code => $id) {
            $choices[$id] = $code . ' ' . Control::getControlTitle($code);
        }

        return $choices;
    }

    /**
     * Code => id des mesures lisibles de la SoA de l'entité active, dans l'ordre de l'Annexe A.
     *
     * @return array<string, int>
     */
    private static function activeControls(): array
    {
        global $DB;

        $item = new Control();
        $codes = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code'],
                'FROM'   => Control::getTable(),
                'WHERE'  => EntityPerimeter::controlCriteria(),
            ]) as $row
        ) {
            if ($item->can((int) $row['id'], READ)) {
                $codes[(string) $row['code']] = (int) $row['id'];
            }
        }
        uksort($codes, 'strnatcmp');

        return $codes;
    }

    /**
     * Politiques modifiables proposées depuis la fiche d'une mesure : id => titre.
     *
     * @return array<int, string>
     */
    public static function policyChoices(): array
    {
        global $DB;

        $item = new Policy();
        $choices = [];
        $query = ['SELECT' => ['id', 'title'], 'FROM' => Policy::getTable(), 'ORDER' => 'title'];
        foreach ($DB->request($query) as $row) {
            if ($item->can((int) $row['id'], UPDATE)) {
                $choices[(int) $row['id']] = (string) $row['title'];
            }
        }

        return $choices;
    }

    // ---------------------------------------------------------------------------------------------
    // Écriture
    // ---------------------------------------------------------------------------------------------

    /**
     * Mesures choisies dans la liste et codes saisis, réduits aux mesures de la SoA active que la
     * personne peut lire.
     *
     * @param mixed $selected identifiants postés
     * @return array{ids: list<int>, unknown: list<string>}
     */
    public static function resolveControls(mixed $selected, string $codes): array
    {
        $active = self::activeControls();
        $allowed = array_flip($active);
        $ids = [];
        foreach (is_array($selected) ? $selected : [] as $id) {
            if (isset($allowed[(int) $id])) {
                $ids[] = (int) $id;
            }
        }
        $parsed = Rules::parseCodes($codes, array_keys($active));
        foreach ($parsed['codes'] as $code) {
            $ids[] = $active[$code];
        }

        return ['ids' => array_values(array_unique($ids)), 'unknown' => $parsed['unknown']];
    }

    /**
     * Relie des mesures à des politiques (une politique et plusieurs mesures, ou l'inverse). Un lien
     * déjà présent n'est pas doublé. Renvoie le nombre de liens créés.
     *
     * @param list<int> $policyIds
     * @param list<int> $controlIds
     */
    public static function addLinks(array $policyIds, array $controlIds, string $section): int
    {
        global $DB;

        if (!self::canEditLinks()) {
            return 0;
        }
        $section = Rules::normalizeSection($section);
        $policy = new Policy();
        $control = new Control();
        $added = 0;
        foreach (array_unique($policyIds) as $policyId) {
            if (!$policy->can((int) $policyId, UPDATE)) {
                continue;
            }
            foreach (array_unique($controlIds) as $controlId) {
                if (!$control->can((int) $controlId, READ)) {
                    continue;
                }
                $link = [
                    'plugin_grcmanager_policies_id' => (int) $policyId,
                    'plugin_grcmanager_controls_id' => (int) $controlId,
                    'section'                       => $section,
                ];
                if (countElementsInTable(PolicyCoverageTables::LINKS, $link) > 0) {
                    continue;
                }
                $DB->insert(PolicyCoverageTables::LINKS, $link + [
                    'users_id'      => (int) Session::getLoginUserID(),
                    'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
                $added++;
            }
        }

        return $added;
    }

    /** Issue #545 : politique purgée, ses liens aux mesures disparaissent avec elle. */
    public static function forgetPolicy(int $policiesId): void
    {
        global $DB;

        $DB->delete(PolicyCoverageTables::LINKS, ['plugin_grcmanager_policies_id' => $policiesId]);
    }

    /** Retire un lien : droit de modifier la politique, mesure lisible. */
    public static function removeLink(int $linkId): bool
    {
        global $DB;

        if (!self::canEditLinks()) {
            return false;
        }
        $row = $DB->request([
            'FROM'  => PolicyCoverageTables::LINKS,
            'WHERE' => ['id' => $linkId],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row)) {
            return false;
        }
        if (
            !(new Policy())->can((int) $row['plugin_grcmanager_policies_id'], UPDATE)
            || !(new Control())->can((int) $row['plugin_grcmanager_controls_id'], READ)
        ) {
            return false;
        }

        return (bool) $DB->delete(PolicyCoverageTables::LINKS, ['id' => $linkId]);
    }

    // ---------------------------------------------------------------------------------------------
    // Onglets
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->isNewItem() || !self::canView()) {
            return '';
        }
        if ($item instanceof Policy) {
            return self::createTabEntry(
                __('Covered controls', 'grcmanager'),
                $_SESSION['glpishow_count_on_tabs'] ? count(self::policyLinks($item->getID())) : 0,
                null,
                self::getIcon()
            );
        }
        if ($item instanceof Control) {
            $entry = self::collect($item->getID())[$item->getID()] ?? ['texts' => []];

            return self::createTabEntry(
                __('Reference texts', 'grcmanager'),
                $_SESSION['glpishow_count_on_tabs'] ? count($entry['texts']) : 0,
                null,
                self::getIcon()
            );
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (
            !self::canView()
            || !($item instanceof Policy || $item instanceof Control)
            || !$item->can($item->getID(), READ)
        ) {
            return false;
        }
        $common = [
            'form_url'   => self::getFormURL(),
            'report_url' => self::getSearchURL(),
            'statuses'   => self::getStatusStyles(),
        ];
        if ($item instanceof Policy) {
            $canEdit = self::canEditLinks() && $item->can($item->getID(), UPDATE);
            TemplateRenderer::getInstance()->display('@grcmanager/policy_coverage_policy_tab.html.twig', $common + [
                'policy_id' => $item->getID(),
                'links'     => self::policyLinks($item->getID()),
                'can_edit'  => $canEdit,
                'choices'   => $canEdit ? self::controlChoices() : [],
            ]);

            return true;
        }
        $entry = self::collect($item->getID())[$item->getID()] ?? ['texts' => [], 'status' => null];
        $canEdit = self::canEditLinks();
        TemplateRenderer::getInstance()->display('@grcmanager/policy_coverage_control_tab.html.twig', $common + [
            'control_id' => $item->getID(),
            'texts'      => $entry['texts'],
            'status'     => $entry['status'],
            'can_edit'   => $canEdit,
            'choices'    => $canEdit ? self::policyChoices() : [],
        ]);

        return true;
    }

    // ---------------------------------------------------------------------------------------------
    // Exports
    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $report résultat de report()
     */
    public static function renderPdf(array $report): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/policy_coverage_report.html.twig', [
            'report_title'       => self::getTypeName(1),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
            'entity'             => (string) \Dropdown::getDropdownName('glpi_entities', EntityPerimeter::soaEntity()),
            'statuses'           => self::getStatusStyles(),
        ] + $report);
    }

    /**
     * Lignes CSV : une par texte relié (une seule, vide, pour une mesure non couverte).
     *
     * @param array<string, mixed> $report résultat de report()
     * @return list<list<string>>
     */
    public static function csvRows(array $report): array
    {
        $lines = [[
            __('Control', 'grcmanager'),
            __('Title', 'grcmanager'),
            __('Coverage', 'grcmanager'),
            Policy::getTypeName(1),
            __('Section', 'grcmanager'),
            __('Text status', 'grcmanager'),
            __('Next review', 'grcmanager'),
        ]];
        foreach ($report['rows'] as $row) {
            $texts = $row['texts'] !== [] ? $row['texts'] : [null];
            foreach ($texts as $text) {
                $lines[] = array_map([ConsistencyRules::class, 'csvCell'], [
                    $row['code'],
                    $row['title'],
                    $row['style']['label'],
                    $text['title'] ?? '',
                    $text['section'] ?? '',
                    $text['style']['label'] ?? '',
                    $text !== null && $text['next_review'] !== '' ? (string) Html::convDate($text['next_review']) : '',
                ]);
            }
        }

        return $lines;
    }
}
