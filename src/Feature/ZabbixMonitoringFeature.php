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

use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use GlpiPlugin\Grcmanager\ZabbixMonitoring;

/**
 * Issue #836 : supervision lue dans Zabbix (lecture seule). Aide de la page, trois sources de KRI et cadre de
 * preuve des mesures A.8.16, A.8.6 et A.5.30, déclarés ici plutôt que dans les listes communes (voir
 * FeatureRegistry).
 */
final class ZabbixMonitoringFeature implements
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    PageHelpProvider
{
    public static function pageHelp(): array
    {
        return [
            'zabbixmonitoring' => [
                'purpose'   => __(
                    'Reads your Zabbix monitoring server, in read only: hosts, problems and availability of the '
                        . 'services. Hosts are matched with the computers and network devices of GLPI to show the '
                        . 'assets that nobody monitors, the measured availability compared with the recovery time '
                        . 'objectives of the business processes, and the saturations of disk, memory or processor.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Enter the address of the Zabbix server and the API token of a read-only Zabbix user, read now, '
                        . 'then follow up the critical assets not monitored, the open high-severity problems and the '
                        . 'services below their objective. A high-severity problem can be turned into a security '
                        . 'incident or a ticket with one click; nothing is created automatically and nothing is '
                        . 'changed in Zabbix.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.8.16, A.8.6 and A.5.30', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Disponibilité des services du mois (SLA Zabbix), la plus basse.
            'zabbix_month_availability' => [
                'label'     => __('Service availability this month, lowest (%) (Zabbix)', 'grcmanager'),
                'unit'      => '%',
                'direction' => KriRules::LOWER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [ZabbixMonitoring::class, 'lowestServiceAvailability'],
                'after'     => 'critical_assets_without_logging',
            ],
            // Problèmes ouverts de gravité haute.
            'zabbix_open_high_problems' => [
                'label'     => __('Open high-severity problems (Zabbix)', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [ZabbixMonitoring::class, 'openHighProblems'],
                'after'     => 'zabbix_month_availability',
            ],
            // Actifs critiques non supervisés.
            'zabbix_unmonitored_critical' => [
                'label'     => __('Critical assets not monitored (Zabbix)', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [ZabbixMonitoring::class, 'unmonitoredCriticalAssets'],
                'after'     => 'zabbix_open_high_problems',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Zabbix monitoring', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_zabbix_monitoring_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return ZabbixMonitoring::evidence($code);
    }
}
