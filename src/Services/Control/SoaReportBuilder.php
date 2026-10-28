<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Dashboard\DashboardCardService;
use Html;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\IsmsRequirement;

/**
 * HTML du rapport PDF de la Déclaration d'Applicabilité, partagé par l'export à la demande
 * (front/control.report.php) et par les versions figées soumises à l'approbation de la direction
 * (issue #112, \GlpiPlugin\Grcmanager\SoaVersion::requestApproval()), pour que le document approuvé soit
 * exactement le même rapport que celui que l'on exporte, plus son bloc d'approbation.
 *
 * Dépend du $DB global et des classes GLPI : exclu de PHPStan comme AssetsignEvidenceProvider.
 */
final class SoaReportBuilder
{
    /**
     * Bloc « version soumise à approbation » + bloc de signature manuscrite, null pour un export simple.
     *
     * @param array{
     *     version: int, requested_at: string, requested_by: string, fingerprint: string, approvers: list<string>
     * }|null $approval
     */
    public static function renderHtml(?array $approval = null): string
    {
        global $DB;

        $titles = \GlpiPlugin\Grcmanager\Control::getControlTitles();
        $themes = \GlpiPlugin\Grcmanager\Control::getThemes();
        $applicabilities = \GlpiPlugin\Grcmanager\Control::getApplicabilities();
        $implementationStatuses = \GlpiPlugin\Grcmanager\Control::getImplementationStatuses();

        $controls = [];
        foreach ($DB->request(['FROM' => \GlpiPlugin\Grcmanager\Control::getTable(), 'ORDER' => 'code ASC']) as $row) {
            $controls[] = [
                'code'                  => $row['code'],
                'theme'                 => $themes[$row['theme']] ?? $row['theme'],
                'title'                 => $titles[$row['code']] ?? $row['code'],
                'applicability'         => $applicabilities[$row['applicability']] ?? $row['applicability'],
                'implementation_status' => $implementationStatuses[$row['implementation_status']]
                    ?? $row['implementation_status'],
                'justification'         => $row['justification'],
            ];
        }

        // Issue #113 : exigences du SMSI (articles 4 à 10) incluses dans le même rapport d'audit.
        $clauseStatuses = \GlpiPlugin\Grcmanager\IsmsRequirement::getStatuses();
        $clauses = [];
        foreach ($DB->request(['FROM' => \GlpiPlugin\Grcmanager\IsmsRequirement::getTable()]) as $row) {
            $clauses[] = [
                'code'   => $row['code'],
                'title'  => \GlpiPlugin\Grcmanager\IsmsRequirement::getClauseTitle((string) $row['code']),
                'status' => $clauseStatuses[$row['status']] ?? $row['status'],
                'owner'  => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
            ];
        }
        usort($clauses, static fn (array $a, array $b): int => IsmsClauseCatalog::compareCodes($a['code'], $b['code']));

        return \Glpi\Application\View\TemplateRenderer::getInstance()->render('@grcmanager/pdf/soa_report.html.twig', [
            'report_title'       => __('Statement of Applicability — ISO/IEC 27001:2022 Annex A', 'grcmanager'),
            'generated_at_label' => sprintf(__(
                'Generated on %s',
                'grcmanager'
            ), Html::convDateTime(date('Y-m-d H:i:s'))),
            'controls'           => $controls,
            'by_applicability'   => DashboardCardService::soaByApplicability(),
            'by_status'          => DashboardCardService::soaByImplementationStatus(),
            'reviewed_count'     => DashboardCardService::soaReviewedCount(),
            'total_count'        => count($controls),
            // Issue #109 : null si assetsign absent, la section est alors omise du PDF.
            'assetsign_evidence' => AssetsignEvidenceProvider::fetchSummary(),
            'clauses'            => $clauses,
            'clause_completion'  => \GlpiPlugin\Grcmanager\IsmsRequirement::getCompletion(),
            'approval'           => $approval,
        ]);
    }
}
