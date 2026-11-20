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
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Calendar\ComplianceCalendarRules;
use GlpiPlugin\Grcmanager\Services\Physical\PhysicalSecurityRules as Rules;
use Html;

/**
 * Issue #778 : page « Sécurité physique » (sous-page Conformité) : dernière évaluation de chaque site
 * côte à côte (comparaison entre sites, mesure par mesure), échéances de réévaluation, preuve sur les
 * mesures A.7.1 à A.7.14 de la SoA et échéances du calendrier de conformité.
 *
 * Droits : ceux des évaluations (droit général du plugin) et leurs entités, partout.
 */
class PhysicalSecurity extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Physical security', 'grcmanager');
    }

    public static function getIcon()
    {
        return PhysicalSecurityAssessment::getIcon();
    }

    public static function canView(): bool
    {
        return PhysicalSecurityAssessment::canView();
    }

    public static function getPageURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/physicalsecurity.php';
    }

    /**
     * Sous-page de la rubrique « Conformité » (Menu::sections()), avec les droits des évaluations.
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $menu = [
            'title' => self::getTypeName(),
            'page'  => self::getPageURL(false),
            'icon'  => self::getIcon(),
            'links' => ['search' => PhysicalSecurityAssessment::getSearchURL(false)],
        ];
        if (PhysicalSecurityAssessment::canCreate()) {
            $menu['links']['add'] = PhysicalSecurityAssessment::getFormURL(false);
        }

        return $menu;
    }

    // ---------------------------------------------------------------------------------------------
    // Libellés
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> état => libellé, classes, icône
     */
    public static function stateBadges(): array
    {
        return [
            Rules::STATE_NOT_ASSESSED   => [
                __('Not assessed', 'grcmanager'), 'bg-secondary-subtle text-secondary-emphasis', 'ti-circle-dashed',
            ],
            Rules::STATE_COMPLIANT      => [
                __('Compliant', 'grcmanager'), 'bg-green-subtle text-green-emphasis', 'ti-circle-check',
            ],
            Rules::STATE_PARTIAL        => [
                __('Partially compliant', 'grcmanager'), 'bg-orange-subtle text-orange-emphasis', 'ti-alert-triangle',
            ],
            Rules::STATE_NONCOMPLIANT   => [
                __('Non-compliant', 'grcmanager'), 'bg-red-subtle text-red-emphasis', 'ti-alert-octagon',
            ],
            Rules::STATE_NOT_APPLICABLE => [
                __('Not applicable', 'grcmanager'), 'bg-light text-muted', 'ti-minus',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> type => libellé, classes, aide
     */
    public static function zoneTypes(): array
    {
        return [
            Rules::ZONE_PUBLIC     => [
                __('Public area', 'grcmanager'), 'bg-green-subtle text-green-emphasis',
                __('Reception, meeting rooms open to visitors.', 'grcmanager'),
            ],
            Rules::ZONE_CONTROLLED => [
                __('Controlled area', 'grcmanager'), 'bg-azure-subtle text-azure-emphasis',
                __('Offices: staff only, visitors accompanied.', 'grcmanager'),
            ],
            Rules::ZONE_SENSITIVE  => [
                __('Sensitive area', 'grcmanager'), 'bg-orange-subtle text-orange-emphasis',
                __('Archives, management, HR: named people only.', 'grcmanager'),
            ],
            Rules::ZONE_TECHNICAL  => [
                __('Technical room', 'grcmanager'), 'bg-red-subtle text-red-emphasis',
                __('Server room, network cabinets, electrical room: authorized technicians only.', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function accessMeans(): array
    {
        return [
            'badge'       => __('Badge', 'grcmanager'),
            'key'         => __('Key', 'grcmanager'),
            'code'        => __('Door code', 'grcmanager'),
            'biometric'   => __('Biometrics', 'grcmanager'),
            'reception'   => __('Reception desk', 'grcmanager'),
            'visitor_log' => __('Visitor log', 'grcmanager'),
            'escort'      => __('Escorted visitors', 'grcmanager'),
            'cctv'        => __('Video surveillance', 'grcmanager'),
            'alarm'       => __('Intrusion alarm', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function zoneWarningLabels(): array
    {
        return [
            Rules::ZONE_WARNING_NO_ACCESS_CONTROL => __('No access control recorded', 'grcmanager'),
            Rules::ZONE_WARNING_NO_INSTRUCTIONS   => __('Access instructions not posted', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> échéance => libellé, classes
     */
    public static function dueLabels(): array
    {
        return [
            Rules::DUE_NONE    => [
                __('No reassessment date', 'grcmanager'), 'bg-secondary-subtle text-secondary-emphasis',
            ],
            Rules::DUE_OK      => [__('Up to date', 'grcmanager'), 'bg-success-subtle text-success-emphasis'],
            Rules::DUE_SOON    => [
                __('Reassessment due soon', 'grcmanager'), 'bg-warning-subtle text-warning-emphasis',
            ],
            Rules::DUE_OVERDUE => [__('Reassessment overdue', 'grcmanager'), 'bg-danger-subtle text-danger-emphasis'],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Données
    // ---------------------------------------------------------------------------------------------

    /**
     * Dernière évaluation (date d'évaluation puis numéro) de chaque site et de chaque entité.
     *
     * @param array<int|string, mixed> $where restriction (entités de la session, hors tâche automatique)
     * @return list<array<string, mixed>>
     */
    public static function latestAssessments(array $where): array
    {
        global $DB;

        $latest = [];
        foreach (
            $DB->request([
                'FROM'  => PhysicalSecurityAssessment::getTable(),
                'WHERE' => array_merge($where, [['locations_id' => ['>', 0]]]),
                'ORDER' => ['assessment_date DESC', 'id DESC'],
            ]) as $row
        ) {
            $key = (int) $row['entities_id'] . ':' . (int) $row['locations_id'];
            $latest[$key] ??= $row;
        }

        return array_values($latest);
    }

    /**
     * Évaluations visibles par la personne : dernière évaluation de chaque site.
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleLatest(): array
    {
        if (!self::canView()) {
            return [];
        }

        return self::latestAssessments(EntityPerimeter::criteria(PhysicalSecurityAssessment::getTable()));
    }

    /**
     * Ligne affichable d'une évaluation (page, onglet du lieu).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentAssessment(array $row, string $today): array
    {
        $id = (int) $row['id'];
        $lines = PhysicalSecurityCheck::getLines($id);
        $states = [];
        foreach ($lines as $line) {
            $states[(string) $line['code']] = Rules::normalizeState($line['state']);
        }
        $next = isset($row['next_assessment_date']) ? (string) $row['next_assessment_date'] : null;
        $due = Rules::dueStatus($next, $today);
        $statuses = PhysicalSecurityAssessment::getStatuses();
        $status = Rules::normalizeStatus($row['status'] ?? '');

        return [
            'id'          => $id,
            'url'         => PhysicalSecurityAssessment::getFormURLWithID($id),
            'name'        => (string) $row['name'],
            'site'        => PhysicalSecurityAssessment::siteName((int) $row['locations_id']),
            'entity'      => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
            'date'        => empty($row['assessment_date']) ? '' : Html::convDate((string) $row['assessment_date']),
            'next'        => empty($next) ? '' : Html::convDate($next),
            'due'         => $due,
            'status'      => $statuses[$status],
            'completed'   => $status === Rules::STATUS_COMPLETED,
            'assessor'    => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
            'states'      => $states,
            'summary'     => Rules::summary(array_values($states)),
            'zones'       => count(PhysicalSecurityZone::getZones($id)),
        ];
    }

    /**
     * Page de comparaison entre sites.
     */
    public static function showPage(): void
    {
        $today = date('Y-m-d');
        $sites = array_map(
            static fn (array $row): array => self::presentAssessment($row, $today),
            self::visibleLatest()
        );
        usort($sites, static fn (array $a, array $b): int => strnatcasecmp($a['site'], $b['site']));
        $dueCounts = array_count_values(array_column($sites, 'due'));
        $titles = [];
        foreach (Rules::CONTROL_CODES as $code) {
            $titles[$code] = Control::getControlTitle($code);
        }
        TemplateRenderer::getInstance()->display('@grcmanager/physical_security_page.html.twig', [
            'sites'       => $sites,
            'codes'       => Rules::CONTROL_CODES,
            'titles'      => $titles,
            'states'      => self::stateBadges(),
            'due_labels'  => self::dueLabels(),
            'overdue'     => $dueCounts[Rules::DUE_OVERDUE] ?? 0,
            'due_soon'    => $dueCounts[Rules::DUE_SOON] ?? 0,
            'can_create'  => PhysicalSecurityAssessment::canCreate(),
            'add_url'     => PhysicalSecurityAssessment::getFormURL(),
            'search_url'  => PhysicalSecurityAssessment::getSearchURL(),
            'multi'       => EntityPerimeter::isMultiEntity(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Preuve des mesures A.7.1 à A.7.14
    // ---------------------------------------------------------------------------------------------

    /**
     * Preuve affichée sur la fiche d'une mesure A.7.x : état de la mesure dans la dernière évaluation
     * de chaque site, dans les entités de la personne. Null pour les autres mesures ou sans droit.
     *
     * @return array{sites: list<array<string, mixed>>, counts: array<string, int>, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!Rules::isControlCode($code) || !self::canView()) {
            return null;
        }
        global $DB;

        $badges = self::stateBadges();
        $counts = array_fill_keys(Rules::STATES, 0);
        $sites = [];
        foreach (self::visibleLatest() as $row) {
            $line = $DB->request([
                'FROM'  => PhysicalSecurityCheck::TABLE,
                'WHERE' => [PhysicalSecurityAssessment::FK => (int) $row['id'], 'code' => $code],
            ])->current();
            $state = Rules::normalizeState($line['state'] ?? '');
            $counts[$state]++;
            $sites[] = [
                'site'        => PhysicalSecurityAssessment::siteName((int) $row['locations_id']),
                'date'        => empty($row['assessment_date']) ? '' : Html::convDate((string) $row['assessment_date']),
                'state_label' => $badges[$state][0],
                'state_class' => $badges[$state][1],
                'state_icon'  => $badges[$state][2],
                'finding'     => (string) ($line['finding'] ?? ''),
                'url'         => PhysicalSecurityAssessment::getFormURLWithID((int) $row['id'])
                    . '&forcetab=' . rawurlencode(PhysicalSecurityAssessment::class . '$1'),
            ];
        }
        usort($sites, static fn (array $a, array $b): int => strnatcasecmp($a['site'], $b['site']));

        return ['sites' => $sites, 'counts' => $counts, 'url' => self::getPageURL()];
    }

    // ---------------------------------------------------------------------------------------------
    // Calendrier de conformité
    // ---------------------------------------------------------------------------------------------

    /**
     * Réévaluation de chaque site (dernière évaluation), dans les entités de la session, au nom de
     * l'évaluateur (planning GLPI, abonnement iCalendar).
     *
     * @return list<array<string, mixed>>
     */
    public static function calendarDeadlines(): array
    {
        $list = [];
        foreach (self::visibleLatest() as $row) {
            $next = isset($row['next_assessment_date']) ? (string) $row['next_assessment_date'] : null;
            if (empty($next)) {
                continue;
            }
            $id = (int) $row['id'];
            $list[] = [
                'source'   => 'physical_security',
                'id'       => $id,
                'name'     => sprintf(
                    __('%1$s: %2$s', 'grcmanager'),
                    __('Physical security reassessment', 'grcmanager'),
                    PhysicalSecurityAssessment::siteName((int) $row['locations_id'])
                ),
                'range'    => ComplianceCalendarRules::dayRange($next),
                'users_id' => (int) $row['users_id'],
                'url'      => PhysicalSecurityAssessment::getFormURLWithID($id),
                'done'     => false,
            ];
        }

        return $list;
    }
}
