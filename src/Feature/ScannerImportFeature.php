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

namespace GlpiPlugin\Grcmanager\Feature;

use CommonDBTM;
use Computer;
use GlpiPlugin\Grcmanager\ScannerImport;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use GlpiPlugin\Grcmanager\Services\Scanner\ScannerReports;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules as Rules;

/**
 * Issue #543 : rapports de scanners de vulnérabilités. Aide de la page, KRI des constats critiques
 * ou élevés ouverts, preuve de la mesure A.8.8 et source de l'onglet Sécurité 360° de l'ordinateur.
 */
final class ScannerImportFeature implements
    PageHelpProvider,
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    AssetSecuritySourcesProvider
{
    public static function pageHelp(): array
    {
        return [
            'scannerimport' => [
                'purpose'   => __(
                    'Brings the results of your network vulnerability scanner (Greenbone / OpenVAS) into the ISMS: '
                        . 'each finding is matched to a GLPI computer, kept from one scan to the next and marked '
                        . 'fixed when a later scan of the same host no longer reports it.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Import the XML report after each scan, match the hosts that were not recognised, then handle '
                        . 'the critical and high findings first (ticket proposed on click only). Nothing is changed '
                        . 'automatically, neither in GLPI nor on the scanner.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, control A.8.8', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            'scanner_open_high_findings' => [
                'label'     => __('Open critical or high scanner findings', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [ScannerImport::class, 'openHighFindings'],
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Vulnerability scanner reports', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_scanner_import_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return ScannerImport::evidence($code);
    }

    public static function assetSecuritySources(): array
    {
        return [
            'scanner_findings' => [
                'label'     => __('Network scanner findings', 'grcmanager'),
                'icon'      => 'ti ti-radar-2',
                'itemtypes' => [Computer::class],
                'weight'    => 2,
                'compute'   => [self::class, 'scannerFindings'],
                'after'     => 'vulnerabilities',
            ],
        ];
    }

    /**
     * Constats ouverts de l'ordinateur ; absente sans droit ou si aucun constat ne l'a jamais visé.
     *
     * @return array<string, mixed>|null
     */
    public static function scannerFindings(CommonDBTM $item): ?array
    {
        if (!ScannerImport::canView() || !$item instanceof Computer) {
            return null;
        }
        $ever = ScannerImport::findings(['computers_id' => (int) $item->getID()], null, 1);
        if ($ever === []) {
            return null;
        }
        $counts = ScannerReports::countBySeverity(ScannerImport::getForItem($item));
        $url = ScannerImport::getSearchURL() . '?computers_id=' . (int) $item->getID();
        if ($counts['total'] === 0) {
            return [
                'status'  => Rules::STATUS_OK,
                'summary' => __('No open finding in the last network scans of this computer.', 'grcmanager'),
                'url'     => $url,
            ];
        }
        $serious = $counts[ScannerReports::SEVERITY_CRITICAL] + $counts[ScannerReports::SEVERITY_HIGH];

        return [
            'status'  => $serious > 0 ? Rules::STATUS_CRITICAL : Rules::STATUS_WARNING,
            'summary' => sprintf(
                __('%1$d open findings, %2$d of them critical or high.', 'grcmanager'),
                $counts['total'],
                $serious
            ),
            'url'     => $url,
        ];
    }
}
