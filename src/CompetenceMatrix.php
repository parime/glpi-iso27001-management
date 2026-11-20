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
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Competence\CompetenceRules as Rules;
use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceProvider as Provider;
use Html;
use Preference;
use Session;
use User;

/**
 * Issue #774 : compétences requises, acquises et écarts (ISO 27001, article 7.2).
 *
 * Calcule, sans rien écrire, pour chaque personne qui tient une fonction du SMSI (directement, par
 * un groupe ou par son titre GLPI) : les compétences exigées par ses fonctions, celles qu'elle a
 * acquises (formations réussies du plugin, déclarations avec preuve) et les écarts, avec l'action
 * proposée (formation à planifier, preuve à déclarer).
 *
 * Droits :
 * - sa propre fiche, toujours, dans ses préférences (lecture seule) ;
 * - la matrice, la fiche d'une autre personne et le dossier PDF : droit du plugin et lecture des
 *   utilisateurs, pour les comptes habilités dans les entités de la session (comme la fiche de
 *   conformité du personnel) ;
 * - la fiche d'une fonction : droit du plugin et entité de la fonction ; les personnes n'y figurent
 *   qu'avec la lecture des utilisateurs.
 */
class CompetenceMatrix extends CommonGLPI
{
    /** Table des participations aux formations (Training::PARTICIPANTS_TABLE, privée). */
    private const PARTICIPANTS_TABLE = 'glpi_plugin_grcmanager_trainings_users';

    public static function getTypeName($nb = 0)
    {
        return Competence::getTypeName($nb);
    }

    public static function getIcon()
    {
        return 'ti ti-certificate';
    }

    /** Matrice des personnes : droit du plugin et lecture des utilisateurs. */
    public static function canView(): bool
    {
        return (bool) Session::haveRight(IsmsFunction::RIGHTNAME, READ)
            && (bool) Session::haveRight(User::$rightname, READ);
    }

    /**
     * @param bool $allowOwn sa propre fiche sans droit (onglet des préférences)
     */
    public static function canViewFor(int $usersId, bool $allowOwn = true): bool
    {
        if ($usersId <= 0) {
            return false;
        }
        if ($allowOwn && $usersId === (int) Session::getLoginUserID()) {
            return true;
        }

        return self::canView() && CompetenceDeclaration::isVisibleUser($usersId);
    }

    public static function pdfUrl(int $functionId = 0): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/ismsfunction.pdf.php'
            . ($functionId > 0 ? '?id=' . $functionId : '');
    }

    // ------------------------------------------------------------------ Onglets.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            return self::createTabEntry(__('My competences', 'grcmanager'), 0, null, self::getIcon());
        }
        if (!$item instanceof User || $item->isNewItem() || !self::canViewFor($item->getID(), false)) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if ($item instanceof Preference) {
            self::showSheet((int) Session::getLoginUserID(), true);

            return true;
        }
        if (!$item instanceof User || $item->isNewItem() || !self::canViewFor($item->getID(), false)) {
            return false;
        }
        self::showSheet($item->getID(), $item->getID() === (int) Session::getLoginUserID());

        return true;
    }

    // ------------------------------------------------------------------ Calculs.

    /**
     * Personnes actives qui tiennent chaque fonction : utilisateurs cités, membres des groupes cités,
     * utilisateurs portant les titres cités.
     *
     * @param array<int, list<array{itemtype: string, items_id: int}>> $holders
     * @return array<int, list<int>>
     */
    public static function members(array $holders): array
    {
        global $DB;

        $ids = ['User' => [], 'Group' => [], 'UserTitle' => []];
        foreach ($holders as $rows) {
            foreach ($rows as $row) {
                if (isset($ids[$row['itemtype']])) {
                    $ids[$row['itemtype']][$row['items_id']] = $row['items_id'];
                }
            }
        }
        $active = ['glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0];
        $users = $groups = $titles = [];
        if ($ids['User'] !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id'], 'FROM' => 'glpi_users',
                    'WHERE'  => ['glpi_users.id' => array_values($ids['User'])] + $active,
                ]) as $row
            ) {
                $users[(int) $row['id']] = true;
            }
        }
        if ($ids['Group'] !== []) {
            foreach (
                $DB->request([
                    'SELECT'     => ['glpi_groups_users.groups_id', 'glpi_groups_users.users_id'],
                    'FROM'       => 'glpi_groups_users',
                    'INNER JOIN' => [
                        'glpi_users' => ['ON' => ['glpi_groups_users' => 'users_id', 'glpi_users' => 'id']],
                    ],
                    'WHERE'      => ['glpi_groups_users.groups_id' => array_values($ids['Group'])] + $active,
                ]) as $row
            ) {
                $groups[(int) $row['groups_id']][] = (int) $row['users_id'];
            }
        }
        if ($ids['UserTitle'] !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'usertitles_id'], 'FROM' => 'glpi_users',
                    'WHERE'  => ['glpi_users.usertitles_id' => array_values($ids['UserTitle'])] + $active,
                ]) as $row
            ) {
                $titles[(int) $row['usertitles_id']][] = (int) $row['id'];
            }
        }

        $members = [];
        foreach ($holders as $functionId => $rows) {
            $list = [];
            foreach ($rows as $row) {
                $found = match ($row['itemtype']) {
                    'User'      => isset($users[$row['items_id']]) ? [$row['items_id']] : [],
                    'Group'     => $groups[$row['items_id']] ?? [],
                    'UserTitle' => $titles[$row['items_id']] ?? [],
                    default     => [],
                };
                foreach ($found as $userId) {
                    $list[$userId] = $userId;
                }
            }
            $members[$functionId] = array_values($list);
        }

        return $members;
    }

    /**
     * Compétences acquises par les personnes : formations réussies liées à la compétence (formation
     * principale ou l'une de ses sessions) et déclarations avec preuve.
     *
     * @param list<int> $userIds
     * @param list<int> $competenceIds
     * @return array<int, array<int, list<array{level: int, expires: ?string, origin: string, label: string,
     *     date: string, url: string}>>>
     */
    public static function sources(array $userIds, array $competenceIds): array
    {
        global $DB, $CFG_GLPI;

        $sources = [];
        if ($userIds === [] || $competenceIds === []) {
            return $sources;
        }
        $grants = Competence::trainingsFor($competenceIds);
        $mainIds = [];
        foreach ($grants as $list) {
            foreach (array_keys($list) as $trainingId) {
                $mainIds[$trainingId] = $trainingId;
            }
        }
        if ($mainIds !== []) {
            $fk = Training::getForeignKeyField();
            $trainings = [];
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'title', $fk, 'renewal_period_months'],
                    'FROM'   => Training::getTable(),
                    'WHERE'  => ['OR' => ['id' => array_values($mainIds), $fk => array_values($mainIds)]],
                ]) as $row
            ) {
                $trainings[(int) $row['id']] = $row;
            }
            foreach (
                $DB->request([
                    'FROM'  => self::PARTICIPANTS_TABLE,
                    'WHERE' => [$fk => array_keys($trainings) ?: [0], 'users_id' => $userIds],
                ]) as $row
            ) {
                $status = (string) $row['completion_status'];
                $result = (string) ($row['assessment_result'] ?? 'not_applicable');
                if (!Rules::trainingPassed($status, $result)) {
                    continue;
                }
                $training = $trainings[(int) $row[$fk]];
                $mainId = (int) $training[$fk] > 0 ? (int) $training[$fk] : (int) $training['id'];
                $renewal = (int) $training['renewal_period_months'];
                if ($renewal <= 0 && isset($trainings[$mainId])) {
                    $renewal = (int) $trainings[$mainId]['renewal_period_months'];
                }
                $date = (string) ($row['completion_date'] ?? '');
                foreach ($grants as $competenceId => $list) {
                    if (!isset($list[$mainId])) {
                        continue;
                    }
                    $sources[(int) $row['users_id']][$competenceId][] = [
                        'level'   => $list[$mainId],
                        'expires' => Rules::trainingExpiry($date, $renewal),
                        'origin'  => 'training',
                        'label'   => (string) $training['title'],
                        'date'    => $date,
                        'url'     => Training::getFormURLWithID((int) $training['id']),
                    ];
                }
            }
        }

        $evidences = CompetenceDeclaration::evidenceLabels();
        foreach (CompetenceDeclaration::forUsers($userIds, $competenceIds) as $row) {
            $sources[(int) $row['users_id']][(int) $row[Competence::FK]][] = [
                'level'   => Rules::normalizeLevel($row['level']),
                'expires' => Rules::normalizeDate($row['date_expiry']),
                'origin'  => 'declaration',
                'label'   => sprintf(
                    '%s - %s',
                    $evidences[(string) $row['evidence_type']] ?? '',
                    (string) $row['evidence_reference']
                ),
                'date'    => (string) ($row['date_acquired'] ?? ''),
                'url'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/competencedeclaration.form.php?id='
                    . (int) $row['id'],
            ];
        }

        return $sources;
    }

    /**
     * Façon d'acquérir une compétence exigée d'une personne : celle de la fonction qui exige le
     * niveau le plus haut.
     *
     * @param list<int> $functionIds
     * @param array<int, array<int, array{id: int, level: int, mode: string}>> $requirements
     */
    private static function modeFor(array $functionIds, array $requirements, int $competenceId, int $level): string
    {
        foreach ($functionIds as $functionId) {
            $requirement = $requirements[$functionId][$competenceId] ?? null;
            if ($requirement !== null && $requirement['level'] === $level) {
                return $requirement['mode'];
            }
        }

        return 'training';
    }

    /**
     * @param array{level: int, expires: ?string, origin: string, label: string, date: string, url: string} $source
     * @return array<string, mixed>
     */
    private static function decorateSource(array $source, string $today): array
    {
        return $source + [
            'level_label'  => IsmsFunction::levelLabels()[$source['level']] ?? '',
            'date_text'    => $source['date'] !== '' ? (string) Html::convDate($source['date']) : '',
            'expires_text' => $source['expires'] !== null ? (string) Html::convDate($source['expires']) : '',
            'valid'        => Rules::isValid($source['expires'], $today),
        ];
    }

    /** @return array<string, array{label: string, class: string, icon: string}> */
    public static function statusStyles(): array
    {
        return [
            Rules::STATUS_MET     => [
                'label' => __('Acquired', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis', 'icon' => 'ti-circle-check',
            ],
            Rules::STATUS_PARTIAL => [
                'label' => __('Level too low', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis', 'icon' => 'ti-arrow-big-up-lines',
            ],
            Rules::STATUS_EXPIRED => [
                'label' => __('Expired', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis', 'icon' => 'ti-clock-exclamation',
            ],
            Rules::STATUS_MISSING => [
                'label' => __('Not acquired', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis', 'icon' => 'ti-circle-x',
            ],
        ];
    }

    /** Action proposée quand aucune formation liée ne donne la compétence au niveau exigé. */
    public static function actionText(string $mode): string
    {
        return match ($mode) {
            'diploma', 'certification' => __('Declare the diploma or certificate with its evidence.', 'grcmanager'),
            'experience' => __('Declare the experience with its evidence (annual review, references).', 'grcmanager'),
            'training'   => __('Plan a training: no training of the plugin gives this competence yet.', 'grcmanager'),
            default      => __('Declare the competence with its evidence.', 'grcmanager'),
        };
    }

    /**
     * Matrice personnes x compétences : toutes les personnes visibles qui tiennent une fonction des
     * entités de la session, ou la seule personne $userId.
     *
     * @return array<string, mixed>
     */
    public static function data(?int $userId = null, bool $restrictFunctions = true): array
    {
        global $CFG_GLPI;

        $today = date('Y-m-d');
        $functions = IsmsFunction::visibleFunctions($restrictFunctions);
        $holders = IsmsFunction::holdersFor(array_keys($functions));
        $members = self::members($holders);
        $functionsByPerson = [];
        foreach ($members as $functionId => $users) {
            foreach ($users as $user) {
                $functionsByPerson[$user][] = $functionId;
            }
        }
        if ($userId !== null) {
            $functionsByPerson = [$userId => $functionsByPerson[$userId] ?? []];
        } elseif ($functionsByPerson !== []) {
            $functionsByPerson = array_intersect_key(
                $functionsByPerson,
                Provider::users(['users_id' => array_keys($functionsByPerson)])
            );
        }
        $requirements = IsmsFunction::requirementsFor(array_keys($functions));
        $levels = [];
        foreach ($requirements as $functionId => $list) {
            foreach ($list as $competenceId => $requirement) {
                $levels[$functionId][$competenceId] = $requirement['level'];
            }
        }
        $required = Rules::requiredByPerson($functionsByPerson, $levels);
        $competenceIds = [];
        foreach ($required as $list) {
            foreach (array_keys($list) as $competenceId) {
                $competenceIds[$competenceId] = $competenceId;
            }
        }
        $competenceIds = array_values($competenceIds);
        $labels = Competence::labelsFor($competenceIds);
        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        $people = array_keys($functionsByPerson);
        $sources = self::sources($people, $competenceIds);
        $grants = Competence::trainingsFor($competenceIds);
        $trainingIds = [];
        foreach ($grants as $list) {
            $trainingIds = array_merge($trainingIds, array_keys($list));
        }
        $trainingTitles = Competence::trainingTitles(array_values(array_unique($trainingIds)));
        $levelLabels = IsmsFunction::levelLabels();
        $modeLabels = IsmsFunction::modeLabels();
        $canDeclare = CompetenceDeclaration::canCreate();

        $rows = $gaps = $statuses = [];
        foreach ($functionsByPerson as $person => $personFunctions) {
            $name = (string) getUserName($person);
            $cells = [];
            $statuses[$person] = [];
            foreach ($required[$person] ?? [] as $competenceId => $info) {
                $own = $sources[$person][$competenceId] ?? [];
                $status = Rules::status($info['level'], $own, $today);
                $acquired = Rules::acquired($own, $today)['level'];
                $mode = self::modeFor($info['functions'], $requirements, $competenceId, $info['level']);
                $cells[$competenceId] = [
                    'required'       => $info['level'],
                    'required_label' => $levelLabels[$info['level']] ?? '',
                    'acquired'       => $acquired,
                    'acquired_label' => $levelLabels[$acquired] ?? '',
                    'status'         => $status,
                    'mode'           => $mode,
                    'mode_label'     => $modeLabels[$mode] ?? '',
                    'sources'        => array_map(
                        static fn (array $s): array => self::decorateSource($s, $today),
                        $own
                    ),
                ];
                $statuses[$person][$competenceId] = $status;
                if (!Rules::isGap($status)) {
                    continue;
                }
                $proposed = [];
                foreach (Rules::proposedTrainings($info['level'], $grants[$competenceId] ?? []) as $trainingId) {
                    $proposed[] = [
                        'title' => $trainingTitles[$trainingId] ?? '',
                        'url'   => Training::getFormURLWithID($trainingId),
                    ];
                }
                $gaps[] = [
                    'person'      => $person,
                    'name'        => $name,
                    'competence'  => $labels[$competenceId] ?? '',
                    'competence_url' => Competence::getFormURLWithID($competenceId),
                    'status'      => $status,
                    'required'    => $levelLabels[$info['level']] ?? '',
                    'acquired'    => $levelLabels[$acquired] ?? '',
                    'trainings'   => $proposed,
                    'action'      => $proposed === [] ? self::actionText($mode) : '',
                    'declare_url' => $canDeclare
                        ? $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/competencedeclaration.form.php?users_id='
                            . $person . '&competences_id=' . $competenceId . '&level=' . $info['level']
                        : '',
                ];
            }
            $functionLabels = [];
            foreach ($personFunctions as $functionId) {
                $functionLabels[] = IsmsFunction::label($functions[$functionId]);
            }
            sort($functionLabels, SORT_NATURAL | SORT_FLAG_CASE);
            $rows[] = [
                'id'        => $person,
                'name'      => $name,
                'url'       => User::getFormURLWithID($person),
                'functions' => $functionLabels,
                'cells'     => $cells,
                'gaps'      => count(array_filter($statuses[$person], [Rules::class, 'isGap'])),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        usort(
            $gaps,
            static fn (array $a, array $b): int => [$a['name'], $a['competence']] <=> [$b['name'], $b['competence']]
        );

        $functionRows = [];
        foreach ($functions as $functionId => $row) {
            $holdersCount = count(array_filter(
                $functionsByPerson,
                static fn (array $list): bool => in_array($functionId, $list, true)
            ));
            $withGaps = 0;
            foreach ($functionsByPerson as $person => $list) {
                if (!in_array($functionId, $list, true)) {
                    continue;
                }
                foreach (array_keys($requirements[$functionId] ?? []) as $competenceId) {
                    if (Rules::isGap($statuses[$person][$competenceId] ?? Rules::STATUS_MISSING)) {
                        $withGaps++;
                        break;
                    }
                }
            }
            $functionRows[] = [
                'id'           => $functionId,
                'label'        => IsmsFunction::label($row),
                'url'          => IsmsFunction::getFormURLWithID($functionId),
                'pdf_url'      => self::pdfUrl($functionId),
                'requirements' => count($requirements[$functionId] ?? []),
                'people'       => $holdersCount,
                'with_gaps'    => $withGaps,
            ];
        }

        return [
            'columns'   => $labels,
            'rows'      => $rows,
            'gaps'      => $gaps,
            'functions' => $functionRows,
            'summary'   => Rules::summary($statuses),
        ];
    }

    /**
     * Fiche d'une fonction : mission, titulaires, compétences exigées, couverture des personnes (avec
     * la lecture des utilisateurs) et activités de la matrice des responsabilités.
     *
     * @return array<string, mixed>
     */
    public static function functionSheet(IsmsFunction $function): array
    {
        $today = date('Y-m-d');
        $id = (int) $function->getID();
        $holders = IsmsFunction::holdersFor([$id])[$id] ?? [];
        $holderLabels = IsmsFunction::holderLabels($holders);
        $requirements = IsmsFunction::requirementsFor([$id])[$id] ?? [];
        $competenceIds = array_keys($requirements);
        $labels = Competence::labelsFor($competenceIds);
        $grants = Competence::trainingsFor($competenceIds);
        $trainingIds = [];
        foreach ($grants as $list) {
            $trainingIds = array_merge($trainingIds, array_keys($list));
        }
        $titles = Competence::trainingTitles(array_values(array_unique($trainingIds)));
        $levelLabels = IsmsFunction::levelLabels();
        $modeLabels = IsmsFunction::modeLabels();

        $requirementRows = [];
        foreach ($requirements as $competenceId => $requirement) {
            $trainings = [];
            foreach ($grants[$competenceId] ?? [] as $trainingId => $level) {
                $trainings[] = sprintf('%s (%s)', $titles[$trainingId] ?? '', $levelLabels[$level] ?? '');
            }
            $requirementRows[] = [
                'competence_id' => $competenceId,
                'competence'    => $labels[$competenceId] ?? '',
                'level'         => $requirement['level'],
                'level_label'   => $levelLabels[$requirement['level']] ?? '',
                'mode_label'    => $modeLabels[$requirement['mode']] ?? '',
                'trainings'     => $trainings,
            ];
        }
        usort($requirementRows, static fn (array $a, array $b): int => strcasecmp($a['competence'], $b['competence']));

        $people = null;
        $memberIds = self::members([$id => $holders])[$id] ?? [];
        if (self::canView()) {
            $visible = $memberIds === [] ? [] : array_keys(Provider::users(['users_id' => $memberIds]));
            $sources = self::sources($visible, $competenceIds);
            $people = [];
            foreach ($visible as $userId) {
                $cells = [];
                $gaps = 0;
                foreach ($requirementRows as $requirement) {
                    $status = Rules::status(
                        $requirement['level'],
                        $sources[$userId][$requirement['competence_id']] ?? [],
                        $today
                    );
                    $cells[$requirement['competence_id']] = $status;
                    $gaps += Rules::isGap($status) ? 1 : 0;
                }
                $people[] = ['name' => (string) getUserName($userId), 'cells' => $cells, 'gaps' => $gaps];
            }
            usort($people, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        }

        // Activités de la matrice des responsabilités tenues par les titulaires (article 5.3).
        $groupIds = array_column(
            array_filter($holders, static fn (array $h): bool => $h['itemtype'] === 'Group'),
            'items_id'
        );
        $activities = [];
        $visibleActivities = ResponsibilityActivity::visibleActivities();
        $assignments = ResponsibilityActivity::assignmentsFor(
            array_map(static fn (array $a): int => (int) $a['id'], $visibleActivities)
        );
        $roles = ResponsibilityActivity::getRoles();
        foreach ($visibleActivities as $activity) {
            $own = [];
            foreach ($assignments[(int) $activity['id']] ?? [] as $row) {
                $held = ($row['itemtype'] === 'User' && in_array($row['items_id'], $memberIds, true))
                    || ($row['itemtype'] === 'Group' && in_array($row['items_id'], $groupIds, true));
                if ($held) {
                    $own[$row['role']] = $roles[$row['role']] ?? $row['role'];
                }
            }
            if ($own !== []) {
                $activities[] = ['label' => ResponsibilityActivity::label($activity), 'roles' => array_values($own)];
            }
        }

        return [
            'id'           => $id,
            'label'        => $function->getName(),
            'entity'       => (string) Dropdown::getDropdownName(
                Entity::getTable(),
                (int) $function->fields['entities_id']
            ),
            'description'  => (string) ($function->fields['description'] ?? ''),
            'holders'      => array_values($holderLabels),
            'requirements' => $requirementRows,
            'people'       => $people,
            'activities'   => $activities,
        ];
    }

    // ------------------------------------------------------------------ Affichage.

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/competence_page.html.twig', [
            'data'          => self::data(),
            'can_people'    => self::canView(),
            'statuses'      => self::statusStyles(),
            'can_create'    => IsmsFunction::canCreate(),
            'can_update'    => IsmsFunction::canUpdate(),
            'can_declare'   => CompetenceDeclaration::canCreate(),
            'function_form' => IsmsFunction::getFormURL(),
            'competence_list' => Competence::getSearchURL(),
            'declaration_list' => CompetenceDeclaration::canView() ? CompetenceDeclaration::getSearchURL() : '',
            'declaration_form' => CompetenceDeclaration::getFormURL(),
            'page_url'      => IsmsFunction::getSearchURL(),
            'dossier_url'   => self::pdfUrl(),
            'exports'       => CompetenceExport::recent(),
        ]);
    }

    /**
     * Fiche d'une personne : ses fonctions, les compétences exigées et acquises, ses déclarations.
     */
    public static function showSheet(int $usersId, bool $own): void
    {
        $data = self::data($usersId, !$own);
        $row = $data['rows'][0] ?? null;
        $declarations = [];
        $labels = [];
        $rows = CompetenceDeclaration::forUsers([$usersId]);
        $labels = Competence::labelsFor(array_values(array_unique(array_map(
            static fn (array $r): int => (int) $r[Competence::FK],
            $rows
        ))));
        $today = date('Y-m-d');
        foreach ($rows as $declaration) {
            $expiry = Rules::normalizeDate($declaration['date_expiry']);
            $declarations[] = [
                'competence' => $labels[(int) $declaration[Competence::FK]] ?? '',
                'level'      => IsmsFunction::levelLabels()[Rules::normalizeLevel($declaration['level'])] ?? '',
                'evidence'   => CompetenceDeclaration::evidenceLabels()[(string) $declaration['evidence_type']] ?? '',
                'reference'  => (string) $declaration['evidence_reference'],
                'acquired'   => $declaration['date_acquired']
                    ? (string) Html::convDate($declaration['date_acquired']) : '',
                'expiry'     => $expiry !== null ? (string) Html::convDate($expiry) : '',
                'valid'      => Rules::isValid($expiry, $today),
                'url'        => !$own && CompetenceDeclaration::canView()
                    ? CompetenceDeclaration::getFormURLWithID((int) $declaration['id']) : '',
            ];
        }
        $canDeclare = !$own && CompetenceDeclaration::canCreate() && CompetenceDeclaration::isVisibleUser($usersId);

        TemplateRenderer::getInstance()->display('@grcmanager/competence_sheet.html.twig', [
            'own'          => $own,
            'row'          => $row,
            'columns'      => $data['columns'],
            'gaps'         => $data['gaps'],
            'summary'      => $data['summary'],
            'statuses'     => self::statusStyles(),
            'declarations' => $declarations,
            'declare_url'  => $canDeclare
                ? CompetenceDeclaration::getFormURL() . '?users_id=' . $usersId : '',
            'page_url'     => !$own && IsmsFunction::canView() ? IsmsFunction::getSearchURL() : '',
        ]);
    }

    // ------------------------------------------------------------------ PDF et clause 7.2.

    /**
     * Fiches de fonctions en PDF (fiches de poste « sécurité » pour l'audit), avec la liste des écarts
     * pour le dossier complet.
     *
     * @param list<IsmsFunction> $functions
     */
    public static function renderPdf(array $functions, bool $withGaps): string
    {
        $data = $withGaps && self::canView() ? self::data() : null;

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/competence_report.html.twig', [
            'report_title'       => count($functions) === 1 && !$withGaps
                ? sprintf(__('Security job description: %s', 'grcmanager'), $functions[0]->getName())
                : __('Competences of the ISMS functions (ISO 27001, clause 7.2)', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
            'sheets'             => array_map([self::class, 'functionSheet'], $functions),
            'statuses'           => self::statusStyles(),
            'data'               => $data,
        ]);
    }

    /**
     * Fonctions des entités de la session, pour le dossier complet.
     *
     * @return list<IsmsFunction>
     */
    public static function visibleFunctionItems(): array
    {
        $items = [];
        foreach (array_keys(IsmsFunction::visibleFunctions()) as $id) {
            $item = new IsmsFunction();
            if ($item->getFromDB($id)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Synthèse affichée sur l'exigence 7.2, null pour les autres ou sans droit.
     *
     * @return array{functions: int, competences: int, people: ?int, people_with_gaps: ?int, rate: ?int,
     *     archives: int, url: string}|null
     */
    public static function clauseReadiness(string $code): ?array
    {
        if ($code !== '7.2' || !IsmsFunction::canView()) {
            return null;
        }
        $data = self::data();
        $people = self::canView();

        return [
            'functions'        => count($data['functions']),
            'competences'      => countElementsInTable(
                Competence::getTable(),
                EntityPerimeter::criteria(Competence::getTable())
            ),
            'people'           => $people ? $data['summary']['people'] : null,
            'people_with_gaps' => $people ? $data['summary']['people_with_gaps'] : null,
            'rate'             => $people ? $data['summary']['rate'] : null,
            'archives'         => count(CompetenceExport::recent()),
            'url'              => IsmsFunction::getSearchURL(),
        ];
    }
}
