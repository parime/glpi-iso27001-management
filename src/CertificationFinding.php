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
use Document;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules as Rules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Html;
use Session;

/**
 * Issue #772 : audits de certification. Un audit (Audit) est interne ou mené par l'organisme de
 * certification (initial, surveillance 1 et 2, renouvellement) ; ses constats sont des
 * non-conformités (Nonconformity) avec le vocabulaire de l'organisme, traité par le même workflow
 * CAPA. Cette classe regroupe :
 *
 * - les libellés et badges du type d'audit, des constats de l'organisme et de leur état de levée ;
 * - l'écran « Constats des audits précédents » (état de levée de chaque constat, preuves jointes)
 *   et son export PDF, remis à l'auditeur à l'ouverture de l'audit suivant ;
 * - le comptage des constats non levés pour « Préparation à la certification ».
 *
 * Lecture seule. Droit du plugin (celui des audits et des non-conformités), entités de la session.
 */
class CertificationFinding extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Findings of previous audits', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-certificate-2';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/certificationfinding.php';
    }

    public static function canView(): bool
    {
        return Audit::canView() && Nonconformity::canView();
    }

    /** Sous-page de la rubrique « Audits et amélioration » (Menu::sections()). */
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
    public static function getAuditTypes(): array
    {
        return [
            'internal'       => __('Internal audit (clause 9.2)', 'grcmanager'),
            'initial'        => __('Initial certification audit', 'grcmanager'),
            'surveillance_1' => __('Surveillance audit 1', 'grcmanager'),
            'surveillance_2' => __('Surveillance audit 2', 'grcmanager'),
            'renewal'        => __('Recertification audit', 'grcmanager'),
        ];
    }

    /**
     * Constats de l'organisme de certification.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            Rules::CATEGORY_MAJOR_NC        => __('Major nonconformity', 'grcmanager'),
            Rules::CATEGORY_MINOR_NC        => __('Minor nonconformity', 'grcmanager'),
            Rules::CATEGORY_SENSITIVE_POINT => __('Area of concern', 'grcmanager'),
            Rules::CATEGORY_IMPROVEMENT     => __('Opportunity for improvement', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getLiftStates(): array
    {
        return [
            Rules::LIFT_NOT_LIFTED            => [
                'label' => __('Not lifted', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-flag',
            ],
            Rules::LIFT_IN_PROGRESS           => [
                'label' => __('Action in progress', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-tool',
            ],
            Rules::LIFT_AWAITING_VERIFICATION => [
                'label' => __('Action done, effectiveness to verify', 'grcmanager'),
                'class' => 'bg-info-subtle text-info-emphasis',
                'icon'  => 'ti-hourglass',
            ],
            Rules::LIFT_LIFTED                => [
                'label' => __('Lifted (verified)', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-shield-check',
            ],
        ];
    }

    public static function auditTypeBadge(?string $type): string
    {
        $type = Rules::normalizeAuditType((string) $type);
        $label = self::getAuditTypes()[$type];

        return Rules::isExternal($type)
            ? Badge::html('bg-purple-lt', 'ti-certificate', $label)
            : Badge::html('bg-secondary-lt', 'ti-clipboard-check', $label);
    }

    /**
     * Classe et icône du badge d'un constat de l'organisme.
     *
     * @return array{class: string, icon: string}
     */
    public static function categoryStyle(?string $category): array
    {
        return match ($category) {
            Rules::CATEGORY_MAJOR_NC        => ['class' => 'bg-red-lt', 'icon' => 'ti-alert-octagon'],
            Rules::CATEGORY_MINOR_NC        => ['class' => 'bg-orange-lt', 'icon' => 'ti-alert-triangle'],
            Rules::CATEGORY_SENSITIVE_POINT => ['class' => 'bg-yellow-lt', 'icon' => 'ti-eye-exclamation'],
            Rules::CATEGORY_IMPROVEMENT     => ['class' => 'bg-azure-lt', 'icon' => 'ti-bulb'],
            default                         => ['class' => 'bg-secondary-lt', 'icon' => 'ti-notes'],
        };
    }

    // ------------------------------------------------------------------ Audits.

    /** L'audit est-il mené par l'organisme de certification ? (false s'il n'existe pas) */
    public static function isExternalAudit(int $auditId): bool
    {
        global $DB;

        if ($auditId <= 0) {
            return false;
        }
        $row = $DB->request([
            'SELECT' => ['audit_type'],
            'FROM'   => Audit::getTable(),
            'WHERE'  => ['id' => $auditId],
        ])->current();

        return $row !== null && Rules::isExternal((string) $row['audit_type']);
    }

    /**
     * Audits de l'organisme visibles (entités de la session), le plus récent d'abord ; les audits
     * annulés sont écartés.
     *
     * @return list<array<string, mixed>>
     */
    public static function externalAudits(): array
    {
        global $DB;

        $audits = [];
        foreach (
            $DB->request([
                'FROM'  => Audit::getTable(),
                'WHERE' => array_merge(
                    ['audit_type' => Rules::externalTypes(), 'NOT' => ['status' => 'cancelled']],
                    EntityPerimeter::criteria(Audit::getTable())
                ),
                'ORDER' => ['actual_date DESC', 'planned_date DESC', 'id DESC'],
            ]) as $row
        ) {
            $audits[] = [
                'id'        => (int) $row['id'],
                'title'     => (string) $row['title'],
                'url'       => Audit::getFormURLWithID((int) $row['id']),
                'type'      => (string) $row['audit_type'],
                'type_label' => self::getAuditTypes()[Rules::normalizeAuditType((string) $row['audit_type'])],
                'body'      => (int) $row['suppliers_id'] > 0
                    ? (string) Dropdown::getDropdownName('glpi_suppliers', (int) $row['suppliers_id'])
                    : '',
                'lead_auditor' => (string) $row['lead_auditor'],
                'standard'  => (string) $row['certification_standard'],
                'date'      => (string) Html::convDate($row['actual_date'] ?: $row['planned_date']),
                'status'    => Audit::getStatuses()[$row['status']] ?? (string) $row['status'],
                // Lot 2 : date retenue (réelle, sinon prévue) et statut bruts, pour le plan d'audit.
                'date_value' => (string) ($row['actual_date'] ?: $row['planned_date']),
                'status_key' => (string) $row['status'],
            ];
        }

        return $audits;
    }

    // ------------------------------------------------------------------ Constats.

    /**
     * Constats des audits de l'organisme (entités de la session), avec leur état de levée.
     *
     * @param list<int> $auditIds
     * @return list<array<string, mixed>>
     */
    public static function findings(array $auditIds): array
    {
        global $DB;

        if ($auditIds === []) {
            return [];
        }
        $statuses = Nonconformity::getStatuses();
        $categories = self::getCategories();
        $liftStates = self::getLiftStates();
        $rows = iterator_to_array($DB->request([
            'FROM'  => Nonconformity::getTable(),
            'WHERE' => array_merge(
                ['plugin_grcmanager_audits_id' => $auditIds],
                EntityPerimeter::criteria(Nonconformity::getTable())
            ),
            'ORDER' => ['body_reference', 'id'],
        ]), false);
        $documents = self::documentsByFinding(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $findings = [];
        foreach ($rows as $row) {
            $category = Rules::categoryOf((string) $row['finding_type'], (string) $row['severity']);
            $lift = Rules::liftState((string) $row['status']);
            $id = (int) $row['id'];
            $findings[] = [
                'id'                => $id,
                'audit_id'          => (int) $row['plugin_grcmanager_audits_id'],
                'url'               => Nonconformity::getFormURLWithID($id),
                'reference'         => (string) $row['body_reference'],
                'title'             => (string) $row['title'],
                'category'          => $category,
                'category_label'    => $category !== null ? $categories[$category] : '',
                'category_style'    => self::categoryStyle($category),
                'process'           => (string) $row['audited_process'],
                'requirement'       => (string) $row['requirement_reference'],
                'site'              => (int) $row['locations_id'] > 0
                    ? (string) Dropdown::getDropdownName('glpi_locations', (int) $row['locations_id'])
                    : '',
                'owner'             => (int) $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '',
                'due_date'          => (string) Html::convDate($row['due_date']),
                'status'            => $statuses[$row['status']] ?? (string) $row['status'],
                'lift_state'        => $lift,
                'lift'              => $liftStates[$lift],
                'root_cause'        => (string) ($row['root_cause'] ?? ''),
                'corrective_action' => (string) ($row['corrective_action'] ?? ''),
                'verification_date' => (string) Html::convDate($row['closure_verification_date']),
                'documents'         => $documents[$id] ?? [],
            ];
        }

        return $findings;
    }

    /**
     * Preuves jointes à chaque constat (onglet Documents). Les noms ne sont donnés qu'à qui peut
     * lire les documents GLPI ; sinon seul leur nombre l'est.
     *
     * @param list<int> $findingIds
     * @return array<int, list<string>>
     */
    private static function documentsByFinding(array $findingIds): array
    {
        global $DB;

        if ($findingIds === []) {
            return [];
        }
        $showNames = Document::canView();
        $documents = [];
        foreach (
            $DB->request([
                'SELECT'     => ['links.items_id', 'docs.name'],
                'FROM'       => Document_Item::getTable() . ' AS links',
                'INNER JOIN' => [
                    Document::getTable() . ' AS docs' => [
                        'FKEY' => ['links' => 'documents_id', 'docs' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    'links.itemtype' => Nonconformity::class,
                    'links.items_id' => $findingIds,
                    'docs.is_deleted' => 0,
                ],
                'ORDER'      => 'docs.name',
            ]) as $row
        ) {
            $documents[(int) $row['items_id']][] = $showNames ? (string) $row['name'] : __('Document', 'grcmanager');
        }

        return $documents;
    }

    /**
     * Données de l'écran et de l'export : audits de l'organisme et leurs constats.
     *
     * Lot 2 : $processId restreint aux constats d'un processus du plan d'audit de l'organisme
     * (CertificationSampling::concerns()), processus des entités de la session.
     *
     * @return array{audits: list<array<string, mixed>>, all_audits: list<array<string, mixed>>,
     *     summary: array<string, mixed>, selected: int, open_only: bool, process: array<string, mixed>|null}
     */
    public static function report(int $auditId = 0, bool $openOnly = false, int $processId = 0): array
    {
        $all = self::externalAudits();
        $audits = $auditId > 0
            ? array_values(array_filter($all, static fn (array $a): bool => $a['id'] === $auditId))
            : $all;
        $findings = self::findings(array_column($audits, 'id'));
        $process = CertificationSampling::process($processId);
        if ($process !== null) {
            $findings = array_values(array_filter(
                $findings,
                static fn (array $f): bool => CertificationSampling::concerns($f, $process)
            ));
        }
        $byAudit = [];
        foreach ($findings as $finding) {
            if (!$openOnly || Rules::isOpenState($finding['lift_state'])) {
                $byAudit[$finding['audit_id']][] = $finding;
            }
        }
        foreach ($audits as &$audit) {
            $audit['findings'] = $byAudit[$audit['id']] ?? [];
        }
        unset($audit);

        return [
            'audits'     => $audits,
            'all_audits' => $all,
            'summary'    => Rules::summary(array_map(
                static fn (array $f): array => ['lift_state' => $f['lift_state'], 'category' => $f['category']],
                $findings
            )),
            'selected'   => $auditId > 0 && $audits !== [] ? $auditId : 0,
            'open_only'  => $openOnly,
            'process'    => $process,
        ];
    }

    /**
     * Comptage des constats de l'organisme non levés, pour « Préparation à la certification ».
     *
     * @return array{audits: int, open: int, open_nc: int, total: int}
     */
    public static function openCounts(): array
    {
        $audits = self::externalAudits();
        $summary = Rules::summary(array_map(
            static fn (array $f): array => ['lift_state' => $f['lift_state'], 'category' => $f['category']],
            self::findings(array_column($audits, 'id'))
        ));

        return [
            'audits'  => count($audits),
            'open'    => $summary['open'],
            'open_nc' => $summary['open_nc'],
            'total'   => $summary['total'],
        ];
    }

    // ------------------------------------------------------------------ Rendu.

    /**
     * @param array<string, mixed> $report résultat de report()
     */
    public static function renderPdf(array $report): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/certification_findings_report.html.twig', [
            'report_title'       => self::getTypeName(),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
            'lift_states'        => self::getLiftStates(),
            'categories'         => self::getCategories(),
        ] + $report);
    }
}
