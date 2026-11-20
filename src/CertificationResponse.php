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
use GlpiPlugin\Grcmanager\Services\Audit\BodyResponseRules as Rules;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Html;
use Session;

/**
 * Issue #772, lot 3 : réponse aux constats de l'organisme de certification.
 *
 * Après l'audit, l'organisme fixe dans son rapport le délai d'envoi du plan d'actions (correction,
 * cause et action corrective de chaque non-conformité) puis celui des preuves de levée. Cette
 * classe fournit :
 *
 * - l'onglet « Réponse à l'organisme » d'un audit de certification : délais et jours restants,
 *   état de la réponse et des preuves de chaque constat, éléments manquants avant l'envoi ;
 * - l'export PDF du plan d'actions correctives, à envoyer à l'organisme ;
 * - le comptage des réponses et des preuves encore à envoyer, pour « Préparation à la
 *   certification ».
 *
 * Lecture seule : les dates d'envoi et la décision de l'organisme se saisissent sur la fiche du
 * constat, rien n'est modifié automatiquement. Droits de l'audit et des non-conformités, entités
 * de la session.
 */
class CertificationResponse extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Response to the certification body', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-send';
    }

    public static function canView(): bool
    {
        return CertificationFinding::canView();
    }

    /** Export PDF du plan d'actions d'un audit de certification. */
    public static function pdfUrl(int $auditId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/certificationresponse.php?audit=' . $auditId;
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * Décision de l'organisme sur le plan d'actions.
     *
     * @return array<string, string>
     */
    public static function getDecisions(): array
    {
        return [
            Rules::DECISION_PENDING     => __('Awaiting the decision of the certification body', 'grcmanager'),
            Rules::DECISION_ACCEPTED    => __('Accepted by the certification body', 'grcmanager'),
            Rules::DECISION_TO_COMPLETE => __('To be completed at the request of the certification body', 'grcmanager'),
        ];
    }

    public static function decisionBadge(string $decision): string
    {
        $decision = Rules::normalizeDecision($decision);
        $label = self::getDecisions()[$decision];

        [$class, $icon] = match ($decision) {
            Rules::DECISION_ACCEPTED    => ['bg-success-subtle text-success-emphasis', 'ti-circle-check'],
            Rules::DECISION_TO_COMPLETE => ['bg-warning-subtle text-warning-emphasis', 'ti-pencil-exclamation'],
            default                     => ['bg-secondary-subtle text-secondary-emphasis', 'ti-hourglass'],
        };

        return Badge::html($class, $icon, $label);
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getResponseStates(): array
    {
        return [
            Rules::RESPONSE_OVERDUE      => [
                'label' => __('Action plan overdue', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-alarm',
            ],
            Rules::RESPONSE_TO_COMPLETE  => [
                'label' => __('Action plan to complete', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-pencil-exclamation',
            ],
            Rules::RESPONSE_DUE_SOON     => [
                'label' => __('Action plan due soon', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-clock-exclamation',
            ],
            Rules::RESPONSE_TO_SEND      => [
                'label' => __('Action plan to send', 'grcmanager'),
                'class' => 'bg-info-subtle text-info-emphasis',
                'icon'  => 'ti-send',
            ],
            Rules::RESPONSE_SENT         => [
                'label' => __('Action plan sent, awaiting decision', 'grcmanager'),
                'class' => 'bg-primary-subtle text-primary-emphasis',
                'icon'  => 'ti-mail-forward',
            ],
            Rules::RESPONSE_ACCEPTED     => [
                'label' => __('Action plan accepted', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-circle-check',
            ],
            Rules::RESPONSE_NOT_REQUIRED => [
                'label' => __('No response required', 'grcmanager'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-minus',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getEvidenceStates(): array
    {
        return [
            Rules::EVIDENCE_OVERDUE      => [
                'label' => __('Evidence overdue', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-alarm',
            ],
            Rules::EVIDENCE_DUE_SOON     => [
                'label' => __('Evidence due soon', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-clock-exclamation',
            ],
            Rules::EVIDENCE_TO_SEND      => [
                'label' => __('Evidence to send', 'grcmanager'),
                'class' => 'bg-info-subtle text-info-emphasis',
                'icon'  => 'ti-paperclip',
            ],
            Rules::EVIDENCE_SENT         => [
                'label' => __('Evidence sent', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-mail-check',
            ],
            Rules::EVIDENCE_NOT_REQUIRED => [
                'label' => __('No evidence required', 'grcmanager'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-minus',
            ],
        ];
    }

    /**
     * Libellé d'un délai de l'audit, selon son état et les jours restants.
     *
     * @return array{label: string, class: string, icon: string}
     */
    private static function deadlineBadge(string $state, ?int $days): array
    {
        return match ($state) {
            'done'     => [
                'label' => __('Everything has been sent', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-circle-check',
            ],
            'overdue'  => [
                'label' => sprintf(
                    _n('Overdue by %d day', 'Overdue by %d days', abs((int) $days), 'grcmanager'),
                    abs((int) $days)
                ),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-alarm',
            ],
            'soon', 'upcoming' => [
                'label' => sprintf(_n('%d day left', '%d days left', (int) $days, 'grcmanager'), (int) $days),
                'class' => $state === 'soon'
                    ? 'bg-warning-subtle text-warning-emphasis'
                    : 'bg-info-subtle text-info-emphasis',
                'icon'  => 'ti-clock',
            ],
            default    => [
                'label' => __('No deadline entered', 'grcmanager'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-calendar-question',
            ],
        };
    }

    // ------------------------------------------------------------------ Données.

    /**
     * Ajoute à chaque constat l'état de sa réponse, de ses preuves et les éléments manquants
     * avant l'envoi du plan d'actions.
     *
     * @param list<array<string, mixed>> $findings constats de CertificationFinding::findings()
     * @param array<string, mixed>       $audit    audit de CertificationFinding::externalAudits()
     * @return list<array<string, mixed>>
     */
    public static function annotate(array $findings, array $audit, string $today): array
    {
        $responseStates = self::getResponseStates();
        $evidenceStates = self::getEvidenceStates();
        $decisions = self::getDecisions();
        foreach ($findings as &$finding) {
            $category = $finding['category'];
            $response = Rules::responseState(
                $category,
                $finding['response_date'],
                $audit['response_due'],
                $finding['decision'],
                $today
            );
            $evidence = Rules::evidenceState($category, $finding['evidence_date'], $audit['evidence_due'], $today);
            $missing = [];
            if (Rules::isResponseRequired($category)) {
                foreach (
                    [
                        'correction'        => __('Correction', 'grcmanager'),
                        'root_cause'        => __('Root cause', 'grcmanager'),
                        'corrective_action' => __('Corrective action', 'grcmanager'),
                        'owner'             => __('Owner', 'grcmanager'),
                        'due_date'          => __('Due date', 'grcmanager'),
                    ] as $field => $label
                ) {
                    if (trim((string) $finding[$field]) === '') {
                        $missing[] = $label;
                    }
                }
            }
            $finding['response_state'] = $response;
            $finding['response'] = $responseStates[$response];
            $finding['response_date_label'] = (string) Html::convDate($finding['response_date']);
            $finding['decision_label'] = $decisions[$finding['decision']];
            $finding['evidence_state'] = $evidence;
            $finding['evidence'] = $evidenceStates[$evidence];
            $finding['evidence_date_label'] = (string) Html::convDate($finding['evidence_date']);
            $finding['missing'] = $missing;
        }
        unset($finding);

        return $findings;
    }

    /**
     * Données de l'onglet et du PDF d'un audit de certification visible (entités de la session) ;
     * null si l'audit n'est pas un audit de certification visible.
     *
     * @return array<string, mixed>|null
     */
    public static function report(int $auditId, ?string $today = null): ?array
    {
        $today ??= date('Y-m-d');
        $audit = null;
        foreach (CertificationFinding::externalAudits() as $candidate) {
            if ($candidate['id'] === $auditId) {
                $audit = $candidate;
            }
        }
        if ($audit === null) {
            return null;
        }
        $findings = self::annotate(CertificationFinding::findings([$auditId]), $audit, $today);
        $summary = Rules::summary(array_map(
            static fn (array $f): array => [
                'response_state' => $f['response_state'],
                'evidence_state' => $f['evidence_state'],
            ],
            $findings
        ));
        $deadlines = [];
        foreach (
            [
                'response' => [$audit['response_due'], $summary['responses_to_send']],
                'evidence' => [$audit['evidence_due'], $summary['evidence_to_send']],
            ] as $key => [$due, $toSend]
        ) {
            $state = Rules::deadlineState($due, $toSend, $today);
            $days = Rules::daysLeft($due, $today);
            $deadlines[$key] = [
                'date'  => (string) Html::convDate($due),
                'state' => $state,
                'days'  => $days,
                'badge' => self::deadlineBadge($state, $days),
            ];
        }

        return [
            'audit'       => $audit + ['report_date_label' => (string) Html::convDate($audit['report_date'])],
            'findings'    => $findings,
            'summary'     => $summary,
            'deadlines'   => $deadlines,
            'to_complete' => count(array_filter(
                $findings,
                static fn (array $f): bool => $f['missing'] !== [] && Rules::responseNeedsAction($f['response_state'])
            )),
        ];
    }

    /**
     * Réponses et preuves encore à envoyer à l'organisme, tous audits de certification visibles
     * confondus, pour « Préparation à la certification ».
     *
     * @return array{responses: int, evidence: int, late: int}
     */
    public static function pendingCounts(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $audits = CertificationFinding::externalAudits();
        $byId = array_column($audits, null, 'id');
        $rows = [];
        foreach (CertificationFinding::findings(array_column($audits, 'id')) as $finding) {
            $audit = $byId[$finding['audit_id']] ?? null;
            if ($audit === null) {
                continue;
            }
            $rows[] = [
                'response_state' => Rules::responseState(
                    $finding['category'],
                    $finding['response_date'],
                    $audit['response_due'],
                    $finding['decision'],
                    $today
                ),
                'evidence_state' => Rules::evidenceState(
                    $finding['category'],
                    $finding['evidence_date'],
                    $audit['evidence_due'],
                    $today
                ),
            ];
        }
        $summary = Rules::summary($rows);

        return [
            'responses' => $summary['responses_to_send'],
            'evidence'  => $summary['evidence_to_send'],
            'late'      => $summary['late'],
        ];
    }

    // ------------------------------------------------------------------ Onglet de l'audit.

    private static function isExternal(CommonGLPI $item): bool
    {
        return $item instanceof Audit
            && CertificationAuditRules::isExternal((string) ($item->fields['audit_type'] ?? ''));
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Audit || $item->isNewItem() || !self::canView() || !self::isExternal($item)) {
            return '';
        }
        $report = self::report($item->getID());
        $pending = $report === null
            ? 0
            : $report['summary']['responses_to_send'] + $report['summary']['evidence_to_send'];

        return self::createTabEntry(self::getTypeName(), $pending, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (
            !$item instanceof Audit
            || !self::canView()
            || !$item->can($item->getID(), READ)
            || !self::isExternal($item)
        ) {
            return false;
        }
        $report = self::report($item->getID());
        if ($report === null) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/certification_response_tab.html.twig', $report + [
            'pdf_url'      => self::pdfUrl($item->getID()),
            'findings_url' => CertificationFinding::getSearchURL() . '?audit=' . $item->getID(),
            'lift_states'  => CertificationFinding::getLiftStates(),
            'soon_days'    => Rules::SOON_DAYS,
        ]);

        return true;
    }

    // ------------------------------------------------------------------ PDF.

    /**
     * Plan d'actions correctives à envoyer à l'organisme : seuls les constats qui appellent une
     * réponse (non-conformités) ou pour lesquels une réponse a été préparée sont repris.
     *
     * @param array<string, mixed> $report résultat de report()
     */
    public static function renderPdf(array $report): string
    {
        $findings = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['response_state'] !== Rules::RESPONSE_NOT_REQUIRED
                || trim($f['corrective_action'] . $f['correction']) !== ''
        ));

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/certification_response_report.html.twig', [
            'report_title'       => __('Corrective action plan for the certification body', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
            'plan_findings'      => $findings,
        ] + $report);
    }
}
