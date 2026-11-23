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

use CommonDBTM;
use CommonGLPI;
use GlpiPlugin\Grcmanager\Install\Installer;
use Session;

/**
 * Issue #1041 : page d'accueil de la rubrique « Assistance > Crise et continuité » (sans table). Elle
 * porte l'entrée de menu de la rubrique et montre, en cartes groupées, les écrans de la rubrique que
 * le profil peut ouvrir, avec leur nombre d'éléments et les tests de reprise à faire.
 *
 * Issue #1083 : les plans de continuité et de reprise (PCA, PRA, PCI, PRI) ont leur carte dans
 * « Se préparer », avec leurs indicateurs (non approuvés, revue dépassée, non testés dans l'année,
 * processus critiques sans plan).
 */
class CrisisContinuity extends CommonGLPI
{
    /** Droit principal de la rubrique (continuité) ; la page s'ouvre aussi avec le droit d'un de ses écrans. */
    public const RIGHTNAME = Installer::CONTINUITY_RIGHT;

    /** Pseudo-élément de la rubrique : programme des tests de reprise (page sans classe propre). */
    public const RECOVERY_PROGRAMME = 'recoveryprogramme';

    public static function getTypeName($nb = 0)
    {
        return __('Crisis and continuity', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-lifebuoy';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/crisiscontinuity.php';
    }

    /** Ouverte à qui peut voir au moins un écran de la rubrique (aucun droit n'est élargi). */
    public static function canView(): bool
    {
        return BusinessProcess::canView()
            || CrisisScenario::canView()
            || ContinuityPlan::canView()
            || CrisisExercise::canView()
            || CrisisMessageTemplate::canView();
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return Menu::withSubPages(self::class, false);
        }

        return Menu::withSubPages(self::class, [
            'title'         => self::getTypeName(),
            'section_label' => __('Overview', 'grcmanager'),
            'page'          => self::getSearchURL(false),
            'icon'          => self::getIcon(),
        ]);
    }

    /**
     * Cartes de la page d'accueil, groupées par thème. Seuls les écrans que le profil peut ouvrir
     * sont listés ; un groupe vide n'est pas affiché.
     *
     * @return list<array{label: string, cards: list<array<string, mixed>>}>
     */
    public static function hubData(string $today): array
    {
        $groups = [
            [
                'label' => __('Prepare', 'grcmanager'),
                'cards' => array_values(array_filter([
                    self::classCard(BusinessProcess::class, __(
                        'Critical activities, their business impact analysis (BIA) and recovery objectives.',
                        'grcmanager'
                    )),
                    self::classCard(CrisisScenario::class, __(
                        'Crisis scenarios of the continuity plan and how ready you are for each one.',
                        'grcmanager'
                    )),
                    self::planCard($today),
                ])),
            ],
            [
                'label' => __('Train', 'grcmanager'),
                'cards' => array_values(array_filter([
                    self::classCard(CrisisExercise::class, __(
                        'Crisis exercises and real crisis teams: timeline, decisions and lessons learned.',
                        'grcmanager'
                    )),
                    self::programmeCard($today),
                ])),
            ],
            [
                'label' => __('Communicate', 'grcmanager'),
                'cards' => array_values(array_filter([
                    self::classCard(CrisisMessageTemplate::class, __(
                        'Ready-to-use crisis messages and their distribution lists.',
                        'grcmanager'
                    )),
                ])),
            ],
        ];

        return array_values(array_filter($groups, static fn (array $group): bool => $group['cards'] !== []));
    }

    /**
     * @param class-string<CommonDBTM> $class
     * @return array<string, mixed>|null
     */
    private static function classCard(string $class, string $description): ?array
    {
        if (!(bool) $class::canView()) {
            return null;
        }

        return [
            'title'       => $class::getTypeName(Session::getPluralNumber()),
            'description' => $description,
            'icon'        => $class::getIcon(),
            'url'         => $class::getSearchURL(),
            'count'       => self::countVisible($class),
            'todo'        => [],
        ];
    }

    /**
     * Issue #1083 : carte des plans de continuité et de reprise, avec leurs indicateurs.
     *
     * @return array<string, mixed>|null
     */
    private static function planCard(string $today): ?array
    {
        $card = self::classCard(ContinuityPlan::class, __(
            'Business continuity and recovery plans (BCP, BRP) and IT continuity and recovery plans (ITCP, DRP): '
                . 'approval, review and tests.',
            'grcmanager'
        ));
        if ($card === null) {
            return null;
        }
        $summary = ContinuityPlan::coverage(ContinuityPlan::register($today))['summary'];
        $badges = [
            'not_approved'       => ['bg-orange-subtle text-orange-emphasis', 'ti ti-signature', _n(
                '%d plan not approved',
                '%d plans not approved',
                $summary['not_approved'],
                'grcmanager'
            )],
            'review_overdue'     => ['bg-red-subtle text-red-emphasis', 'ti ti-calendar-exclamation', _n(
                '%d review overdue',
                '%d reviews overdue',
                $summary['review_overdue'],
                'grcmanager'
            )],
            'untested'           => ['bg-yellow-subtle text-yellow-emphasis', 'ti ti-test-pipe', _n(
                '%d plan not tested within a year',
                '%d plans not tested within a year',
                $summary['untested'],
                'grcmanager'
            )],
            'critical_uncovered' => ['bg-red-subtle text-red-emphasis', 'ti ti-building-factory-2', _n(
                '%d critical process without plan',
                '%d critical processes without plan',
                $summary['critical_uncovered'],
                'grcmanager'
            )],
        ];
        foreach ($badges as $key => [$class, $icon, $label]) {
            if ($summary[$key] > 0) {
                $card['todo'][] = ['class' => $class, 'icon' => $icon, 'label' => sprintf($label, $summary[$key])];
            }
        }

        return $card;
    }

    /** @return array<string, mixed>|null */
    private static function programmeCard(string $today): ?array
    {
        global $CFG_GLPI;

        if (!BusinessProcess::canView()) {
            return null;
        }
        $counts = BusinessProcessRecovery::programme($today)['counts'];
        $badges = [
            'overdue'       => ['bg-red-subtle text-red-emphasis', 'ti ti-clock-exclamation', _n(
                '%d test overdue',
                '%d tests overdue',
                (int) $counts['overdue'],
                'grcmanager'
            )],
            'failed'        => ['bg-orange-subtle text-orange-emphasis', 'ti ti-alert-triangle', _n(
                '%d last test failed',
                '%d last tests failed',
                (int) $counts['failed'],
                'grcmanager'
            )],
            'untested_year' => ['bg-yellow-subtle text-yellow-emphasis', 'ti ti-calendar-question', _n(
                '%d process not tested this year',
                '%d processes not tested this year',
                (int) $counts['untested_year'],
                'grcmanager'
            )],
        ];
        $todo = [];
        foreach ($badges as $key => [$class, $icon, $label]) {
            if ((int) $counts[$key] > 0) {
                $todo[] = ['class' => $class, 'icon' => $icon, 'label' => sprintf($label, (int) $counts[$key])];
            }
        }

        return [
            'title'       => __('Recovery test programme', 'grcmanager'),
            'description' => __('When each critical process was last tested and which tests are due.', 'grcmanager'),
            'icon'        => 'ti ti-calendar-repeat',
            'url'         => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/recoveryprogramme.php',
            'count'       => null,
            'todo'        => $todo,
        ];
    }

    /**
     * Éléments visibles dans les entités de la session (hors corbeille).
     *
     * @param class-string<CommonDBTM> $class
     */
    private static function countVisible(string $class): int
    {
        global $DB;

        $table = $class::getTable();
        if (!$DB->tableExists($table)) {
            return 0;
        }
        $item = new $class();
        $criteria = [];
        if ($item->isEntityAssign()) {
            $criteria = getEntitiesRestrictCriteria($table, '', '', $item->maybeRecursive());
        }
        if ($item->maybeDeleted()) {
            $criteria['is_deleted'] = 0;
        }

        return countElementsInTable($table, $criteria);
    }
}
