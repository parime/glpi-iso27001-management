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
use GlpiPlugin\Grcmanager\Services\Monitoring\UptimeKumaRules;
use GlpiPlugin\Grcmanager\Services\Risk\KriRules;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules;
use GlpiPlugin\Grcmanager\UptimeKumaMonitoring;
use NetworkEquipment;

/**
 * Issue #842 : disponibilité des services exposés et certificats TLS lus dans Uptime Kuma (lecture
 * seule). Aide de la page, trois sources de KRI, cadre de preuve des mesures A.8.16, A.5.30 et A.8.24
 * et source de la vue sécurité 360° des actifs (#837), déclarés ici plutôt que dans les listes
 * communes (voir FeatureRegistry).
 */
final class UptimeKumaMonitoringFeature implements
    RiskIndicatorSourcesProvider,
    ControlEvidenceProvider,
    PageHelpProvider,
    AssetSecuritySourcesProvider
{
    public static function pageHelp(): array
    {
        return [
            'uptimekuma' => [
                'purpose'   => __(
                    'Reads your Uptime Kuma server, in read only: whether the websites and services seen by your '
                        . 'users and customers answer, how long their outages last, and when their TLS certificates '
                        . 'expire. Each monitor is matched with a GLPI asset, its outages are compared with the '
                        . 'recovery time objectives of the business processes, and the assets published on the '
                        . 'Internet without any monitor are listed.',
                    'grcmanager'
                ),
                'expected'  => __(
                    'Enter the address of Uptime Kuma with an API key (or the public status pages to read), read '
                        . 'now, then follow up the services down, the certificates that expire soon and the exposed '
                        . 'assets without a monitor. A long outage can be turned into a security incident with one '
                        . 'click, then announced to the users with the crisis communication; nothing is created '
                        . 'automatically and nothing is changed in Uptime Kuma.',
                    'grcmanager'
                ),
                'reference' => __('ISO/IEC 27001, controls A.8.16, A.5.30 and A.8.24', 'grcmanager'),
            ],
        ];
    }

    public static function riskIndicatorSources(): array
    {
        return [
            // Disponibilité de l'ensemble des services surveillés sur la période.
            'uptimekuma_availability' => [
                'label'     => __('Availability of the exposed services (%) (Uptime Kuma)', 'grcmanager'),
                'unit'      => '%',
                'direction' => KriRules::LOWER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [UptimeKumaMonitoring::class, 'exposedAvailability'],
                'after'     => 'zabbix_unmonitored_critical',
            ],
            // Certificats expirés, non valides ou proches de l'échéance : l'alerte du KRI prévient.
            'uptimekuma_certificates' => [
                'label'     => __('TLS certificates to renew (Uptime Kuma)', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [UptimeKumaMonitoring::class, 'certificatesToRenew'],
                'after'     => 'uptimekuma_availability',
            ],
            // Actifs publiés sur Internet que personne ne surveille.
            'uptimekuma_exposed_without_monitor' => [
                'label'     => __('Assets published on the Internet without a monitor (Uptime Kuma)', 'grcmanager'),
                'unit'      => '',
                'direction' => KriRules::HIGHER_IS_WORSE,
                'rights'    => ['computer' => READ],
                'global'    => false,
                'compute'   => [UptimeKumaMonitoring::class, 'exposedWithoutMonitor'],
                'after'     => 'uptimekuma_certificates',
            ],
        ];
    }

    public static function controlEvidenceTitle(): string
    {
        return __('Exposed services (Uptime Kuma)', 'grcmanager');
    }

    public static function controlEvidenceTemplate(): string
    {
        return '@grcmanager/_uptime_kuma_evidence.html.twig';
    }

    public static function controlEvidence(string $code): ?array
    {
        return UptimeKumaMonitoring::evidence($code);
    }

    public static function assetSecuritySources(): array
    {
        return [
            'uptime_kuma' => [
                'label'     => __('Exposed services (Uptime Kuma)', 'grcmanager'),
                'icon'      => 'ti ti-world-check',
                'itemtypes' => [Computer::class, NetworkEquipment::class],
                'weight'    => 1,
                'compute'   => [self::class, 'assetSource'],
                'after'     => 'monitoring',
            ],
        ];
    }

    /**
     * Sondes Uptime Kuma de l'actif : en panne (critique), certificat à renouveler ou panne longue
     * (attention), sinon en ordre. null si aucune sonde n'est rapprochée de l'actif.
     *
     * @return array<string, mixed>|null
     */
    public static function assetSource(CommonDBTM $item): ?array
    {
        if (!UptimeKumaMonitoring::canView()) {
            return null;
        }
        $monitors = UptimeKumaMonitoring::monitorsOf($item);
        if ($monitors === []) {
            return null;
        }
        $down = 0;
        $certs = 0;
        $long = 0;
        $facts = [];
        foreach ($monitors as $monitor) {
            $down += $monitor['status'] === UptimeKumaRules::STATUS_DOWN ? 1 : 0;
            $certs += UptimeKumaRules::certNeedsAction((string) $monitor['cert_status']) ? 1 : 0;
            $long += (bool) $monitor['long_outage'] || (bool) $monitor['rto_gap'] ? 1 : 0;
            if ($monitor['availability'] !== null) {
                $facts[] = sprintf(
                    __('%1$s: %2$s %% available', 'grcmanager'),
                    (string) $monitor['name'],
                    number_format((float) $monitor['availability'], 2)
                );
            }
            if ($monitor['cert_days'] !== null) {
                $facts[] = sprintf(
                    __('%1$s: certificate expires in %2$d days', 'grcmanager'),
                    (string) $monitor['name'],
                    (int) $monitor['cert_days']
                );
            }
        }
        $status = match (true) {
            $down > 0                => Security360Rules::STATUS_CRITICAL,
            $certs > 0 || $long > 0  => Security360Rules::STATUS_WARNING,
            default                  => Security360Rules::STATUS_OK,
        };

        return [
            'status'  => $status,
            'summary' => sprintf(
                __('%1$d monitor(s), %2$d down, %3$d certificate(s) to renew.', 'grcmanager'),
                count($monitors),
                $down,
                $certs
            ),
            'facts'   => array_slice($facts, 0, 6),
            'url'     => UptimeKumaMonitoring::getSearchURL(),
        ];
    }
}
