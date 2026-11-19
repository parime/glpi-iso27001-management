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

use Change;
use CommonGLPI;
use CommonITILObject;
use Computer;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Control\SampleRules;
use Html;
use ITILCategory;
use Session;
use Ticket;
use User;

/**
 * Issue #607 : échantillon d'un test d'efficacité, tiré au hasard dans GLPI.
 *
 * - Population : utilisateurs, ordinateurs, tickets ou changements des entités actives de la
 *   personne qui tire (ou d'une seule d'entre elles, avec ses sous-entités), sur une période et
 *   quelques critères simples. Il faut pouvoir lire ces éléments : droit de lecture du type, et
 *   pour les tickets et les changements le droit de les voir tous (sinon la population ne
 *   serait qu'une partie choisie par les droits, pas celle du contrôle).
 * - Tirage reproductible (SampleRules::draw()) : population, critères, période, taille, méthode,
 *   graine, auteur et date conservés ; liste des éléments tirés figée avec leur nom au tirage.
 * - Chaque élément reçoit un résultat (conforme, non conforme, non applicable) et un commentaire ;
 *   le résultat du test est proposé à partir des écarts, modifiable avec une justification. Le
 *   test enregistré (ControlTest, méthode « échantillonnage ») fige l'échantillon ; supprimer le
 *   test le rouvre.
 * - Droits : ceux de la mesure (lire pour consulter, modifier pour tirer, évaluer, enregistrer).
 *
 * Rien n'est modifié dans les éléments tirés : ni statut, ni priorité, ni attribution, ni droit.
 */
class ControlTestSample extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_controltestsamples';
    public const ITEMS_TABLE = 'glpi_plugin_grcmanager_controltestsampleitems';

    public static function getTypeName($nb = 0)
    {
        return _n('Sample', 'Samples', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-arrows-shuffle';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/controltestsample.form.php';
    }

    public static function getPageURL(int $sampleId): string
    {
        return self::getFormURL() . '?id=' . $sampleId;
    }

    /**
     * @return array<string, string> population => libellé (noms des types du cœur de GLPI)
     */
    public static function getPopulations(): array
    {
        $labels = [];
        foreach (SampleRules::POPULATIONS as $itemtype) {
            $labels[$itemtype] = $itemtype::getTypeName(Session::getPluralNumber());
        }

        return $labels;
    }

    /**
     * @return array<string, string> fréquence => libellé avec la taille proposée
     */
    public static function getFrequencies(): array
    {
        $labels = [
            'annual'    => __('Annual control', 'grcmanager'),
            'quarterly' => __('Quarterly control', 'grcmanager'),
            'monthly'   => __('Monthly control', 'grcmanager'),
            'weekly'    => __('Weekly control', 'grcmanager'),
            'daily'     => __('Daily control', 'grcmanager'),
            'recurring' => __('Control run several times a day', 'grcmanager'),
        ];
        foreach ($labels as $key => $label) {
            $size = SampleRules::FREQUENCY_SIZES[$key];
            $labels[$key] = sprintf(_n('%1$s (%2$d item)', '%1$s (%2$d items)', $size, 'grcmanager'), $label, $size);
        }

        return $labels;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> résultat => libellé, classes, icône
     */
    public static function getItemResults(): array
    {
        return [
            SampleRules::ITEM_PENDING      => [
                __('To be checked', 'grcmanager'), 'bg-secondary-subtle text-secondary-emphasis', 'ti-circle-dashed',
            ],
            SampleRules::ITEM_COMPLIANT    => [
                __('Compliant', 'grcmanager'), 'bg-green-subtle text-green-emphasis', 'ti-circle-check',
            ],
            SampleRules::ITEM_NONCOMPLIANT => [
                __('Non-compliant', 'grcmanager'), 'bg-red-subtle text-red-emphasis', 'ti-circle-x',
            ],
            SampleRules::ITEM_NA           => [
                __('Not applicable', 'grcmanager'), 'bg-azure-subtle text-azure-emphasis', 'ti-circle-minus',
            ],
        ];
    }

    /**
     * La personne connectée peut-elle tirer dans cette population ? Lire le type, et pour les
     * tickets et les changements les voir tous.
     */
    public static function canDrawFrom(string $itemtype): bool
    {
        return match ($itemtype) {
            'User'     => User::canView(),
            'Computer' => Computer::canView(),
            'Ticket'   => Session::haveRight(Ticket::$rightname, CommonITILObject::READALL),
            'Change'   => Session::haveRight(Change::$rightname, CommonITILObject::READALL),
            default    => false,
        };
    }

    /**
     * Entités actives de la session (celles où la personne voit les éléments).
     *
     * @return list<int>
     */
    public static function activeEntities(): array
    {
        $entities = $_SESSION['glpiactiveentities'] ?? [];

        return array_values(array_map('intval', is_array($entities) ? $entities : [(int) $entities]));
    }

    /**
     * Entités de la population : une entité active choisie et ses sous-entités actives, sinon toutes
     * les entités actives. Null si l'entité choisie n'est pas active (hors de portée).
     *
     * @return list<int>|null
     */
    public static function populationEntities(int $entitiesId): ?array
    {
        $active = self::activeEntities();
        if ($entitiesId <= 0) {
            return $active;
        }
        if (!in_array($entitiesId, $active, true)) {
            return null;
        }
        $sons = array_map('intval', getSonsOf('glpi_entities', $entitiesId));

        return array_values(array_intersect($active, array_unique([$entitiesId, ...$sons])));
    }

    /**
     * Critères de lecture de la population (DBmysqlIterator), jamais de SQL écrit à la main.
     *
     * @param array{entities: list<int>, period_start: ?string, period_end: ?string,
     *              itilcategories_id: int, closed_only: bool, active_only: bool} $criteria
     * @return array<string, mixed>
     */
    public static function populationQuery(string $itemtype, array $criteria): array
    {
        $entities = $criteria['entities'];
        // Conditions de période (clé « AND » seulement s'il y en a : jamais un « AND » vide).
        $period = static function (string $field) use ($criteria): array {
            $where = [];
            if ($criteria['period_start'] !== null) {
                $where[] = [$field => ['>=', $criteria['period_start'] . ' 00:00:00']];
            }
            if ($criteria['period_end'] !== null) {
                $where[] = [$field => ['<=', $criteria['period_end'] . ' 23:59:59']];
            }

            return $where === [] ? [] : ['AND' => $where];
        };

        if ($itemtype === 'User') {
            $where = [
                'glpi_users.is_deleted' => 0,
            ] + getEntitiesRestrictCriteria('glpi_profiles_users', 'entities_id', $entities, true);
            if ($criteria['active_only']) {
                $where['glpi_users.is_active'] = 1;
            }

            return [
                'SELECT'     => ['glpi_users.id', 'glpi_users.entities_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_users',
                'INNER JOIN' => [
                    'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
                ],
                'WHERE'      => $where + $period('glpi_users.date_creation'),
                'ORDER'      => 'glpi_users.id',
            ];
        }
        if ($itemtype === 'Computer') {
            return [
                'SELECT' => ['id', 'entities_id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => [
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ] + $period('date_creation') + getEntitiesRestrictCriteria('glpi_computers', '', $entities, true),
                'ORDER'  => 'id',
            ];
        }

        // Tickets et changements : ouverts sur la période, ou clos sur la période.
        $table = $itemtype === 'Ticket' ? 'glpi_tickets' : 'glpi_changes';
        $where = ['is_deleted' => 0] + getEntitiesRestrictCriteria($table, '', $entities, false);
        if ($criteria['itilcategories_id'] > 0) {
            $where['itilcategories_id'] = $criteria['itilcategories_id'];
        }
        if ($criteria['closed_only']) {
            $where['status'] = $itemtype::getClosedStatusArray();
            $where += $period('closedate');
        } else {
            $where += $period('date');
        }

        return ['SELECT' => ['id', 'entities_id'], 'FROM' => $table, 'WHERE' => $where, 'ORDER' => 'id'];
    }

    private static function itemLabel(string $itemtype, int $id): string
    {
        if ($itemtype === 'User') {
            $name = (string) getUserName($id);
        } else {
            $table = match ($itemtype) {
                'Computer' => 'glpi_computers',
                'Ticket'   => 'glpi_tickets',
                default    => 'glpi_changes',
            };
            $name = (string) Dropdown::getDropdownName($table, $id);
        }

        return mb_substr(trim($name) === '' ? '#' . $id : $name, 0, 255);
    }

    private static function refuse(string $message): int
    {
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);

        return 0;
    }

    /**
     * Tire un échantillon pour une mesure et fige la liste des éléments tirés.
     *
     * @param array<string, mixed> $input saisie du formulaire de tirage
     * @return int échantillon créé, 0 en cas de refus (message en session)
     */
    public static function draw(Control $control, array $input): int
    {
        global $DB;

        $controlsId = (int) $control->getID();
        if (!ControlTest::canEditControl($controlsId)) {
            return self::refuse(__('You are not allowed to test this control.', 'grcmanager'));
        }
        $itemtype = SampleRules::normalizePopulation($input['itemtype'] ?? null);
        if ($itemtype === null) {
            return self::refuse(__('Choose the population to draw from.', 'grcmanager'));
        }
        if (!self::canDrawFrom($itemtype)) {
            return self::refuse(sprintf(
                __('You are not allowed to see all the items of this population (%s).', 'grcmanager'),
                self::getPopulations()[$itemtype]
            ));
        }
        $question = trim((string) ($input['name'] ?? ''));
        if ($question === '') {
            return self::refuse(__('Enter the question checked on each item.', 'grcmanager'));
        }
        $period = SampleRules::period($input['period_start'] ?? '', $input['period_end'] ?? '');
        if ($period === null) {
            return self::refuse(__('The start of the period must be before its end.', 'grcmanager'));
        }
        $entitiesId = max(0, (int) ($input['entities_id'] ?? 0));
        $entities = self::populationEntities($entitiesId);
        if ($entities === null || $entities === []) {
            return self::refuse(__('This entity is not among your active entities.', 'grcmanager'));
        }
        $isItil = in_array($itemtype, ['Ticket', 'Change'], true);
        $criteria = [
            'entities'          => $entities,
            'period_start'      => $period[0],
            'period_end'        => $period[1],
            'itilcategories_id' => $isItil ? max(0, (int) ($input['itilcategories_id'] ?? 0)) : 0,
            'closed_only'       => $isItil && !empty($input['closed_only']),
            'active_only'       => $itemtype === 'User' && !empty($input['active_only']),
        ];
        $seed = SampleRules::normalizeSeed($input['seed'] ?? '');
        if (trim((string) ($input['seed'] ?? '')) !== '' && $seed === null) {
            return self::refuse(__('The seed may only contain letters, digits, dashes and underscores.', 'grcmanager'));
        }
        $seed ??= SampleRules::newSeed();

        $query = self::populationQuery($itemtype, $criteria);
        $query['LIMIT'] = SampleRules::MAX_POPULATION + 1;
        $population = [];
        foreach ($DB->request($query) as $row) {
            $population[(int) $row['id']] = (int) $row['entities_id'];
        }
        if (count($population) > SampleRules::MAX_POPULATION) {
            return self::refuse(sprintf(
                __('The population exceeds %d items: narrow the period or the criteria.', 'grcmanager'),
                SampleRules::MAX_POPULATION
            ));
        }
        if ($population === []) {
            return self::refuse(__('No item matches these criteria: nothing was drawn.', 'grcmanager'));
        }
        $frequency = SampleRules::normalizeFrequency($input['operating_frequency'] ?? '');
        $size = SampleRules::sampleSize($input['sample_size'] ?? '', $frequency, count($population));
        $drawn = SampleRules::draw(array_keys($population), $size, $seed);

        $now = date('Y-m-d H:i:s');
        $DB->insert(self::TABLE, [
            'plugin_grcmanager_controls_id' => $controlsId,
            'name'                          => mb_substr($question, 0, 255),
            'itemtype'                      => $itemtype,
            'criteria'                      => (string) json_encode([
                'entities_id'       => $entitiesId,
                'entities'          => $entities,
                'itilcategories_id' => $criteria['itilcategories_id'],
                'closed_only'       => $criteria['closed_only'],
                'active_only'       => $criteria['active_only'],
            ]),
            'period_start'                  => $period[0],
            'period_end'                    => $period[1],
            'population_count'              => count($population),
            'sample_size'                   => count($drawn),
            'operating_frequency'           => $frequency,
            'draw_method'                   => SampleRules::METHOD,
            'seed'                          => $seed,
            'users_id'                      => (int) Session::getLoginUserID(),
            'date_draw'                     => $now,
            'date_creation'                 => $now,
            'date_mod'                      => $now,
        ]);
        $sampleId = (int) $DB->insertId();
        if ($sampleId <= 0) {
            return self::refuse(__('The sample could not be saved.', 'grcmanager'));
        }
        foreach ($drawn as $rank => $id) {
            $DB->insert(self::ITEMS_TABLE, [
                'plugin_grcmanager_controltestsamples_id' => $sampleId,
                'rank'                                    => $rank + 1,
                'itemtype'                                => $itemtype,
                'items_id'                                => $id,
                'label'                                   => self::itemLabel($itemtype, $id),
                'entities_id'                             => $population[$id],
                'result'                                  => SampleRules::ITEM_PENDING,
                'comment'                                 => '',
            ]);
        }
        AuditLog::record('create', self::class, $sampleId, 0, 'success', [
            'control'    => (string) $control->fields['code'],
            'population' => $itemtype,
            'count'      => count($population),
            'size'       => count($drawn),
            'seed'       => $seed,
        ]);
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            _n(
                '%1$d item drawn at random out of %2$d.',
                '%1$d items drawn at random out of %2$d.',
                count($drawn),
                'grcmanager'
            ),
            count($drawn),
            count($population)
        )));

        return $sampleId;
    }

    /**
     * @return array<string, mixed>|null ligne de l'échantillon
     */
    public static function getSample(int $sampleId): ?array
    {
        global $DB;

        if ($sampleId <= 0) {
            return null;
        }
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $sampleId], 'LIMIT' => 1])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getItems(int $sampleId): array
    {
        global $DB;

        $items = [];
        foreach (
            $DB->request([
                'FROM'  => self::ITEMS_TABLE,
                'WHERE' => ['plugin_grcmanager_controltestsamples_id' => $sampleId],
                'ORDER' => ['rank', 'id'],
            ]) as $row
        ) {
            $items[] = $row;
        }

        return $items;
    }

    /**
     * Échantillons d'une mesure, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForControl(int $controlsId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_grcmanager_controls_id' => $controlsId],
                'ORDER' => ['date_draw DESC', 'id DESC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Échantillon d'un test enregistré, null sinon.
     *
     * @return array<string, mixed>|null
     */
    public static function getForTest(int $testsId): ?array
    {
        global $DB;

        if ($testsId <= 0) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['plugin_grcmanager_controltests_id' => $testsId],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    /**
     * Mesure de l'échantillon, si la personne connectée peut la lire.
     *
     * @param array<string, mixed> $sample
     */
    public static function loadControl(array $sample): ?Control
    {
        $control = new Control();
        $controlsId = (int) $sample['plugin_grcmanager_controls_id'];

        return $controlsId > 0 && $control->can($controlsId, READ) ? $control : null;
    }

    /**
     * @param array<string, mixed> $sample
     */
    public static function isOpen(array $sample): bool
    {
        return (int) $sample['plugin_grcmanager_controltests_id'] === 0;
    }

    /**
     * Résultats des éléments tirés (échantillon ouvert, droit de modifier la mesure). Un élément
     * d'un autre échantillon est ignoré.
     *
     * @param array<string, mixed> $sample
     * @param mixed $results identifiant de l'élément => [result, comment]
     * @return int éléments enregistrés
     */
    public static function saveResults(array $sample, mixed $results): int
    {
        global $DB;

        if (!is_array($results) || !self::isOpen($sample)) {
            return 0;
        }
        $known = array_column(self::getItems((int) $sample['id']), null, 'id');
        $saved = 0;
        foreach ($results as $itemId => $values) {
            $itemId = (int) $itemId;
            if (!isset($known[$itemId]) || !is_array($values)) {
                continue;
            }
            $result = SampleRules::normalizeItemResult($values['result'] ?? '');
            $comment = mb_substr(trim((string) ($values['comment'] ?? '')), 0, 2000);
            if ($result === (string) $known[$itemId]['result'] && $comment === (string) $known[$itemId]['comment']) {
                continue;
            }
            $DB->update(self::ITEMS_TABLE, [
                'result'   => $result,
                'comment'  => $comment,
                'users_id' => (int) Session::getLoginUserID(),
                'date_mod' => date('Y-m-d H:i:s'),
            ], ['id' => $itemId]);
            $saved++;
        }
        if ($saved > 0) {
            $DB->update(self::TABLE, ['date_mod' => date('Y-m-d H:i:s')], ['id' => (int) $sample['id']]);
        }

        return $saved;
    }

    /**
     * Enregistre le test d'efficacité de l'échantillon (méthode « échantillonnage ») et le fige.
     *
     * @param array<string, mixed> $sample
     * @param array<string, mixed> $input  résultat retenu, justification, constat, date, preuve
     * @return int test créé, 0 en cas de refus (message en session)
     */
    public static function record(array $sample, array $input): int
    {
        global $DB;

        if (!self::isOpen($sample)) {
            return self::refuse(__('This sample is already recorded as a test.', 'grcmanager'));
        }
        $tally = SampleRules::tally(self::getItems((int) $sample['id']));
        $proposed = SampleRules::proposedResult($tally);
        $chosen = (string) ($input['result'] ?? '');
        if ($chosen === '' && $proposed !== null) {
            $chosen = $proposed;
        }
        $justification = trim((string) ($input['result_justification'] ?? ''));
        $error = SampleRules::recordError($tally, $proposed, $chosen, $justification);
        if ($error !== null) {
            return self::refuse(match ($error) {
                'pending'       => __('Give a result to every item drawn before recording the test.', 'grcmanager'),
                'comment'       => __(
                    'Explain each non-compliant or not applicable item in its comment.',
                    'grcmanager'
                ),
                'all_na'        => __('Every item is not applicable: draw another sample.', 'grcmanager'),
                'justification' => __('Justify a result different from the proposed one.', 'grcmanager'),
                'result'        => __('Choose the result of the test.', 'grcmanager'),
                default         => __('This sample has no item.', 'grcmanager'),
            });
        }

        $summary = sprintf(
            __(
                'Random sample, %1$d of %2$d (%3$s): %4$d compliant, %5$d non-compliant, %6$d not applicable.',
                'grcmanager'
            ),
            $tally['total'],
            (int) $sample['population_count'],
            self::getPopulations()[(string) $sample['itemtype']] ?? (string) $sample['itemtype'],
            $tally['compliant'],
            $tally['noncompliant'],
            $tally['na']
        );
        $lines = [
            sprintf(__('Question: %s', 'grcmanager'), (string) $sample['name']),
            $summary,
        ];
        if ($chosen !== $proposed) {
            $lines[] = sprintf(
                __('Result changed from the proposed one (%1$s): %2$s', 'grcmanager'),
                ControlTest::getResults()[(string) $proposed]['label'] ?? (string) $proposed,
                $justification
            );
        }
        $finding = trim((string) ($input['comment'] ?? ''));
        if ($finding !== '') {
            $lines[] = $finding;
        }

        $testsId = (int) (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => (int) $sample['plugin_grcmanager_controls_id'],
            'test_date'                     => $input['test_date'] ?? date('Y-m-d'),
            'method'                        => 'sampling',
            'result'                        => $chosen,
            'users_id'                      => (int) Session::getLoginUserID(),
            'comment'                       => implode("\n", $lines),
            'documents_id'                  => (int) ($input['documents_id'] ?? 0),
            'next_test_date'                => $input['next_test_date'] ?? '',
        ]);
        if ($testsId <= 0) {
            return 0;
        }
        $DB->update(self::TABLE, [
            'plugin_grcmanager_controltests_id' => $testsId,
            'proposed_result'                   => (string) $proposed,
            'result_justification'              => $chosen !== $proposed ? $justification : '',
            'date_mod'                          => date('Y-m-d H:i:s'),
        ], ['id' => (int) $sample['id']]);

        return $testsId;
    }

    /**
     * Suppression d'un échantillon encore ouvert (droit de modifier la mesure, vérifié par
     * l'appelant). Un échantillon d'un test enregistré reste avec le test.
     *
     * @param array<string, mixed> $sample
     */
    public static function purge(array $sample): bool
    {
        global $DB;

        if (!self::isOpen($sample)) {
            return false;
        }
        $DB->delete(self::ITEMS_TABLE, ['plugin_grcmanager_controltestsamples_id' => (int) $sample['id']]);
        $DB->delete(self::TABLE, ['id' => (int) $sample['id']]);
        AuditLog::record('purge', self::class, (int) $sample['id'], 0, 'success', [
            'control' => (int) $sample['plugin_grcmanager_controls_id'],
            'seed'    => (string) $sample['seed'],
        ]);

        return true;
    }

    /**
     * Le test de l'échantillon a été supprimé (par exemple renvoyé à la relecture) : l'échantillon
     * redevient ouvert, ses résultats sont conservés pour être corrigés.
     */
    public static function reopenForTest(int $testsId): void
    {
        global $DB;

        if ($testsId > 0) {
            $DB->update(self::TABLE, [
                'plugin_grcmanager_controltests_id' => 0,
                'proposed_result'                   => '',
                'result_justification'              => '',
            ], ['plugin_grcmanager_controltests_id' => $testsId]);
        }
    }

    /**
     * Critères de la population, en libellés lisibles (page de l'échantillon, rapport PDF).
     *
     * @param array<string, mixed> $sample
     * @return list<array{0: string, 1: string}>
     */
    public static function describeCriteria(array $sample): array
    {
        $criteria = json_decode((string) ($sample['criteria'] ?? ''), true);
        $criteria = is_array($criteria) ? $criteria : [];
        $itemtype = (string) $sample['itemtype'];
        $lines = [[__('Population', 'grcmanager'), self::getPopulations()[$itemtype] ?? $itemtype]];

        $entitiesId = (int) ($criteria['entities_id'] ?? 0);
        $entityNames = [];
        foreach (array_slice(array_map('intval', (array) ($criteria['entities'] ?? [])), 0, 20) as $id) {
            $entityNames[] = (string) Dropdown::getDropdownName('glpi_entities', $id);
        }
        $lines[] = [
            _n('Entity', 'Entities', count($entityNames)),
            $entitiesId > 0
                ? sprintf(
                    __('%s and its sub-entities', 'grcmanager'),
                    Dropdown::getDropdownName('glpi_entities', $entitiesId)
                )
                : implode(', ', $entityNames),
        ];
        $start = (string) ($sample['period_start'] ?? '');
        $end = (string) ($sample['period_end'] ?? '');
        $lines[] = [
            __('Period covered', 'grcmanager'),
            $start === '' && $end === ''
                ? __('No period limit', 'grcmanager')
                : sprintf(
                    __('From %1$s to %2$s', 'grcmanager'),
                    $start === '' ? '-' : Html::convDate($start),
                    $end === '' ? '-' : Html::convDate($end)
                ),
        ];
        $dateField = match ($itemtype) {
            'User', 'Computer' => __('Creation date'),
            default            => !empty($criteria['closed_only']) ? __('Closing date') : __('Opening date'),
        };
        $lines[] = [__('Date used for the period', 'grcmanager'), $dateField];
        if ((int) ($criteria['itilcategories_id'] ?? 0) > 0) {
            $lines[] = [
                _n('Category', 'Categories', 1),
                (string) Dropdown::getDropdownName(ITILCategory::getTable(), (int) $criteria['itilcategories_id']),
            ];
        }
        if (!empty($criteria['closed_only'])) {
            $lines[] = [__('Status'), __('Closed only', 'grcmanager')];
        }
        if (!empty($criteria['active_only'])) {
            $lines[] = [__('Status'), __('Active accounts only', 'grcmanager')];
        }

        return $lines;
    }

    /**
     * Données d'affichage d'un échantillon (page, rapport PDF).
     *
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    public static function viewData(array $sample): array
    {
        $items = self::getItems((int) $sample['id']);
        foreach ($items as &$item) {
            $itemtype = (string) $item['itemtype'];
            $object = getItemForItemtype($itemtype);
            $item['url'] = $object instanceof \CommonDBTM
                && in_array($itemtype, SampleRules::POPULATIONS, true)
                && $object->can((int) $item['items_id'], READ)
                ? $itemtype::getFormURLWithID((int) $item['items_id'])
                : '';
            $item['entity_name'] = (string) Dropdown::getDropdownName('glpi_entities', (int) $item['entities_id']);
        }
        unset($item);
        $tally = SampleRules::tally($items);
        $proposed = SampleRules::proposedResult($tally);
        $frequencies = self::getFrequencies();

        return [
            'sample'       => $sample,
            'items'        => $items,
            'tally'        => $tally,
            'proposed'     => $proposed,
            'criteria'     => self::describeCriteria($sample),
            'frequency'    => $frequencies[(string) $sample['operating_frequency']] ?? '',
            'author'       => (int) $sample['users_id'] > 0 ? (string) getUserName((int) $sample['users_id']) : '',
            'item_results' => self::getItemResults(),
            'results'      => ControlTest::getResults(),
            'is_open'      => self::isOpen($sample),
        ];
    }

    /**
     * Page de l'échantillon : critères et liste figée, résultats par élément, enregistrement du test.
     *
     * @param array<string, mixed> $sample
     */
    public static function showPage(array $sample, Control $control): void
    {
        $controlsId = (int) $control->getID();
        $canEdit = ControlTest::canEditControl($controlsId);
        $testsId = (int) $sample['plugin_grcmanager_controltests_id'];

        $data = self::viewData($sample);
        TemplateRenderer::getInstance()->display('@grcmanager/control_test_sample.html.twig', $data + [
            'control'        => $control,
            'control_title'  => Control::getControlTitle((string) $control->fields['code']),
            'control_url'    => ControlTest::getControlTabURL($controlsId),
            'can_edit'       => $canEdit && self::isOpen($sample),
            'can_purge'      => $canEdit && self::isOpen($sample),
            'documents'      => $canEdit ? Evidence::getDocumentsFor($control) : [],
            'today'          => date('Y-m-d'),
            'form_url'       => self::getFormURL(),
            'test_pdf_url'   => $testsId > 0 ? ControlTest::getPdfURL($testsId) : '',
            'review_enabled' => ControlTest::isReviewEnabled(),
            'needs_comment'  => [SampleRules::ITEM_NONCOMPLIANT, SampleRules::ITEM_NA],
            'threshold'      => ControlTestRules::TECHNICAL_PARTIAL_THRESHOLD,
        ]);
    }

    /**
     * Données du formulaire de tirage (onglet des tests de la mesure).
     *
     * @return array<string, mixed>
     */
    public static function drawFormData(): array
    {
        $populations = [];
        foreach (self::getPopulations() as $itemtype => $label) {
            $populations[$itemtype] = ['label' => $label, 'allowed' => self::canDrawFrom($itemtype)];
        }
        $entities = [];
        foreach (array_slice(self::activeEntities(), 0, 200) as $id) {
            $entities[$id] = (string) Dropdown::getDropdownName('glpi_entities', $id);
        }
        asort($entities);

        return [
            'populations'        => $populations,
            'entities'           => $entities,
            'frequencies'        => self::getFrequencies(),
            'default_frequency'  => SampleRules::DEFAULT_FREQUENCY,
            'max_sample'         => SampleRules::MAX_SAMPLE,
            'category_dropdown'  => ITILCategory::dropdown([
                'name'    => 'itilcategories_id',
                'display' => false,
                'width'   => '100%',
                'entity'  => self::activeEntities(),
            ]),
        ];
    }
}
