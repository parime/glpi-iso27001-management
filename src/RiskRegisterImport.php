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

use CommonDBTM;
use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterCsv;
use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterSync;
use Session;

/**
 * Issue #602 : import et export CSV du registre des risques (front/riskimport.php). La lecture du
 * fichier (RiskRegisterCsv) et le plan (RiskRegisterSync) sont sans GLPI et testés unitairement ;
 * cette classe leur fournit les données de l'instance et applique le plan confirmé.
 *
 * Le registre est global, ses propriétaires et actifs non : seuls les utilisateurs et actifs des
 * entités de la session sont rapprochés, et seuls les liens vers des actifs visibles sont comparés
 * et remplacés (Risk::syncImportedAssets()). Rien n'est jamais créé hors des risques : un
 * propriétaire, un actif ou une mesure inconnus rejettent la ligne.
 */
final class RiskRegisterImport
{
    private const CONTROLS_LINK_TABLE = 'glpi_plugin_grcmanager_controls_risks';

    /** @var array<string, mixed>|null */
    private static ?array $lookups = null;

    /**
     * Tables de correspondance de RiskRegisterCsv::parse(), plus les libellés des actifs et des
     * propriétaires pour l'aperçu et l'export.
     *
     * @return array{labels: array<string, array<string, string>>, users: array<string, list<int>>,
     *     controls: list<string>, assets: array<string, list<string>>, asset_names: array<string, string>,
     *     asset_serials: array<string, string>, logins: array<int, string>}
     */
    public static function lookups(bool $refresh = false): array
    {
        if (self::$lookups !== null && !$refresh) {
            return self::$lookups;
        }
        $labels = [];
        foreach (
            [
                'category'             => Risk::getCategories(),
                'probability'          => Risk::getProbabilities(),
                'impact'               => Risk::getImpacts(),
                'residual_probability' => Risk::getProbabilities(),
                'residual_impact'      => Risk::getImpacts(),
                'treatment'            => Risk::getTreatments(),
                'status'               => Risk::getStatuses(),
            ] as $column => $values
        ) {
            $labels[$column] = array_flip(array_map('strval', $values));
        }
        [$users, $logins] = self::userLookup();
        [$assets, $names, $serials] = self::assetLookup();

        return self::$lookups = [
            'labels'        => $labels,
            'users'         => $users,
            'controls'      => array_keys(self::controlIds()),
            'assets'        => $assets,
            'asset_names'   => $names,
            'asset_serials' => $serials,
            'logins'        => $logins,
        ];
    }

    /**
     * Utilisateurs actifs des entités de la session : identifiant, adresse e-mail, « prénom nom » et
     * « nom prénom », en minuscules.
     *
     * @return array{0: array<string, list<int>>, 1: array<int, string>}
     */
    private static function userLookup(): array
    {
        global $DB;

        $users = [];
        $logins = [];
        $iterator = $DB->request([
            'SELECT'     => ['glpi_users.id', 'glpi_users.name', 'glpi_users.firstname', 'glpi_users.realname'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => [
                    'ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id'],
                ],
            ],
            'WHERE'      => [
                'glpi_users.is_active'  => 1,
                'glpi_users.is_deleted' => 0,
            ] + getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true),
        ]);
        foreach ($iterator as $row) {
            $id = (int) $row['id'];
            $logins[$id] = (string) $row['name'];
            $first = trim((string) $row['firstname']);
            $last = trim((string) $row['realname']);
            $keys = [(string) $row['name']];
            if ($first !== '' && $last !== '') {
                $keys[] = $first . ' ' . $last;
                $keys[] = $last . ' ' . $first;
            }
            foreach ($keys as $key) {
                self::addKey($users, $key, $id);
            }
        }
        if ($logins !== []) {
            foreach ($DB->request(['FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => array_keys($logins)]]) as $row) {
                self::addKey($users, (string) $row['email'], (int) $row['users_id']);
            }
        }

        return [$users, $logins];
    }

    /**
     * Actifs liables visibles (entités de la session, hors corbeille et gabarits) : nom et numéro
     * d'inventaire, en minuscules => « Itemtype:id ».
     *
     * @return array{0: array<string, list<string>>, 1: array<string, string>, 2: array<string, string>}
     */
    private static function assetLookup(): array
    {
        global $DB;

        $assets = [];
        $names = [];
        $serials = [];
        foreach (Risk::getLinkableItemtypes() as $itemtype) {
            if (!is_a($itemtype, CommonDBTM::class, true) || !$itemtype::canView()) {
                continue;
            }
            $prototype = new $itemtype();
            $table = $itemtype::getTable();
            $where = getEntitiesRestrictCriteria($table, '', '', $prototype->maybeRecursive())
                + $itemtype::getSystemSQLCriteria($table);
            if ($prototype->isField('is_deleted')) {
                $where["$table.is_deleted"] = 0;
            }
            if ($prototype->isField('is_template')) {
                $where["$table.is_template"] = 0;
            }
            $hasSerial = $prototype->isField('otherserial');
            $select = ["$table.id", "$table.name"];
            if ($hasSerial) {
                $select[] = "$table.otherserial";
            }
            foreach ($DB->request(['SELECT' => $select, 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $key = $itemtype . ':' . (int) $row['id'];
                $names[$key] = (string) $row['name'];
                self::addKey($assets, (string) $row['name'], $key);
                if ($hasSerial && trim((string) $row['otherserial']) !== '') {
                    $serials[$key] = (string) $row['otherserial'];
                    self::addKey($assets, (string) $row['otherserial'], $key);
                }
            }
        }

        return [$assets, $names, $serials];
    }

    /**
     * @template T of int|string
     * @param array<string, list<T>> $map
     * @param T $value
     */
    private static function addKey(array &$map, string $key, $value): void
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $key)));
        if ($key !== '' && !in_array($value, $map[$key] ?? [], true)) {
            $map[$key][] = $value;
        }
    }

    /**
     * @return array<string, int> code de l'Annexe A => mesure
     */
    private static function controlIds(): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => Control::getTable()]) as $row) {
            $ids[(string) $row['code']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Risques enregistrés, au format attendu par RiskRegisterSync::plan().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function existing(): array
    {
        global $DB;

        $risks = [];
        foreach ($DB->request(['FROM' => Risk::getTable(), 'ORDER' => 'id']) as $row) {
            $risk = ['external_ref' => (string) ($row['external_ref'] ?? '')];
            foreach (RiskRegisterSync::FIELDS as $field) {
                $risk[$field] = $row[$field] ?? null;
            }
            $risk['users_id'] = (int) $row['users_id'];
            $risk['risk_level'] = (string) $row['risk_level'];
            $risk['residual_level'] = (string) ($row['residual_level'] ?? '');
            $risk['annex_a'] = [];
            $risk['assets'] = [];
            $risk['actions'] = 0;
            $risks[(int) $row['id']] = $risk;
        }
        if ($risks === []) {
            return [];
        }
        foreach (
            $DB->request([
                'SELECT'     => ['links.plugin_grcmanager_risks_id', 'controls.code'],
                'FROM'       => self::CONTROLS_LINK_TABLE . ' AS links',
                'INNER JOIN' => [
                    Control::getTable() . ' AS controls' => [
                        'FKEY' => ['links' => 'plugin_grcmanager_controls_id', 'controls' => 'id'],
                    ],
                ],
            ]) as $row
        ) {
            $id = (int) $row['plugin_grcmanager_risks_id'];
            if (isset($risks[$id])) {
                $risks[$id]['annex_a'][] = (string) $row['code'];
            }
        }
        $visible = self::lookups()['asset_names'];
        foreach ($DB->request(['FROM' => 'glpi_plugin_grcmanager_risks_items']) as $row) {
            $id = (int) $row['plugin_grcmanager_risks_id'];
            $key = $row['itemtype'] . ':' . (int) $row['items_id'];
            if (isset($risks[$id], $visible[$key])) {
                $risks[$id]['assets'][] = $key;
            }
        }
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_risks_id'],
                'COUNT'  => 'c',
                'FROM'   => RiskTreatmentAction::getTable(),
                'GROUPBY' => 'plugin_grcmanager_risks_id',
            ]) as $row
        ) {
            $id = (int) $row['plugin_grcmanager_risks_id'];
            if (isset($risks[$id])) {
                $risks[$id]['actions'] = (int) $row['c'];
            }
        }
        foreach ($risks as $id => $risk) {
            sort($risks[$id]['annex_a']);
            sort($risks[$id]['assets']);
        }

        return $risks;
    }

    /**
     * Applique les étapes confirmées. Chaque risque passe par Risk::add()/update() : niveau calculé par
     * la matrice, appétence et plan de traitement vérifiés, historique tenu. Une étape refusée par ces
     * règles est comptée en échec, son message s'affiche au retour sur la page.
     *
     * @param list<array{action: string, id: int, line: int, reference: string, title: string,
     *     fields: array<string, mixed>, lists: array<string, list<string>>}> $steps
     * @return array{create: int, update: int, unchanged: int, failed: list<string>}
     */
    public static function apply(array $steps): array
    {
        $result = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'failed' => []];
        $controls = self::controlIds();
        foreach ($steps as $step) {
            $risk = new Risk();
            $riskId = $step['id'];
            if ($step['action'] === RiskRegisterSync::UNCHANGED) {
                $result['unchanged']++;
                continue;
            }
            if ($step['action'] === RiskRegisterSync::CREATE) {
                $riskId = (int) $risk->add($step['fields']);
                if ($riskId <= 0) {
                    $result['failed'][] = $step['reference'];
                    continue;
                }
                $result['create']++;
            } else {
                if (!$risk->getFromDB($riskId)) {
                    $result['failed'][] = $step['reference'];
                    continue;
                }
                if ($step['fields'] !== [] && !$risk->update(['id' => $riskId] + $step['fields'])) {
                    $result['failed'][] = $step['reference'];
                    continue;
                }
                $result['update']++;
            }
            if (isset($step['lists']['annex_a'])) {
                self::syncControls($riskId, array_values(array_filter(array_map(
                    static fn (string $code): int => $controls[$code] ?? 0,
                    $step['lists']['annex_a']
                ))));
            }
            if (isset($step['lists']['assets'])) {
                $pairs = [];
                foreach ($step['lists']['assets'] as $key) {
                    [$itemtype, $itemsId] = explode(':', $key, 2) + ['', '0'];
                    $pairs[] = ['itemtype' => $itemtype, 'items_id' => (int) $itemsId];
                }
                Risk::syncImportedAssets($riskId, $pairs);
            }
        }

        return $result;
    }

    /**
     * Étapes que la personne n'a pas le droit d'appliquer (créer, modifier) : sorties du plan et
     * signalées comme lignes rejetées, dès l'aperçu.
     *
     * @param array{steps: list<array<string, mixed>>, errors: list<array<string, mixed>>} $plan
     * @return array{steps: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public static function filterRights(array $plan, bool $canCreate, bool $canUpdate): array
    {
        $steps = [];
        foreach ($plan['steps'] as $step) {
            $denied = match ($step['action']) {
                RiskRegisterSync::CREATE => $canCreate ? '' : 'no_create_right',
                RiskRegisterSync::UPDATE => $canUpdate ? '' : 'no_update_right',
                default                  => '',
            };
            if ($denied !== '') {
                $plan['errors'][] = [
                    'line' => $step['line'], 'reference' => $step['reference'], 'reason' => $denied, 'detail' => '',
                ];
                continue;
            }
            $steps[] = $step;
        }
        $plan['steps'] = $steps;

        return $plan;
    }

    /**
     * Remplace les mesures de l'Annexe A liées au risque.
     *
     * @param list<int> $controlIds
     */
    private static function syncControls(int $riskId, array $controlIds): void
    {
        global $DB;

        $DB->delete(self::CONTROLS_LINK_TABLE, ['plugin_grcmanager_risks_id' => $riskId]);
        foreach (array_unique($controlIds) as $controlId) {
            $DB->insert(self::CONTROLS_LINK_TABLE, [
                'plugin_grcmanager_controls_id' => $controlId,
                'plugin_grcmanager_risks_id'    => $riskId,
                'date_creation'                 => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Libellé d'un actif dans le fichier : son nom s'il le désigne seul, sinon son numéro
     * d'inventaire s'il le désigne seul, sinon son nom (signalé comme ambigu au prochain import).
     */
    public static function assetLabel(string $key): string
    {
        $lookups = self::lookups();
        $name = $lookups['asset_names'][$key] ?? $key;
        if (count($lookups['assets'][mb_strtolower(trim($name))] ?? []) <= 1) {
            return $name;
        }
        $serial = $lookups['asset_serials'][$key] ?? '';
        if ($serial !== '' && count($lookups['assets'][mb_strtolower(trim($serial))] ?? []) === 1) {
            return $serial;
        }

        return $name;
    }

    /**
     * Lignes de l'export, au format du modèle (libellés de la langue de l'utilisateur, relus à l'import).
     *
     * @return list<list<string>>
     */
    public static function exportRows(): array
    {
        $categories = Risk::getCategories();
        $probabilities = Risk::getProbabilities();
        $impacts = Risk::getImpacts();
        $treatments = Risk::getTreatments();
        $statuses = Risk::getStatuses();
        $logins = self::lookups(true)['logins'];
        $rows = [];
        $label = static fn (array $labels, $value): string => (string) ($labels[(string) $value] ?? $value);
        foreach (self::existing() as $id => $risk) {
            $owner = (int) $risk['users_id'];
            $rows[] = [
                $risk['external_ref'] !== '' ? (string) $risk['external_ref'] : RiskRegisterCsv::INTERNAL_PREFIX . $id,
                (string) $risk['title'],
                (string) $risk['description'],
                $label($categories, $risk['category']),
                $label($probabilities, $risk['probability']),
                $label($impacts, $risk['impact']),
                (string) $risk['residual_probability'] !== '' ? $label($probabilities, $risk['residual_probability']) : '',
                (string) $risk['residual_impact'] !== '' ? $label($impacts, $risk['residual_impact']) : '',
                (string) $risk['treatment'] !== '' ? $label($treatments, $risk['treatment']) : '',
                (string) $risk['justification'],
                $owner > 0 ? ($logins[$owner] ?? (string) getUserName($owner)) : '',
                (string) $risk['review_date'],
                $label($statuses, $risk['status']),
                implode(', ', $risk['annex_a']),
                implode(', ', array_map([self::class, 'assetLabel'], $risk['assets'])),
                $label($impacts, $risk['risk_level']),
                (string) $risk['residual_level'] !== '' ? $label($impacts, $risk['residual_level']) : '',
            ];
        }

        return $rows;
    }

    /**
     * Lignes d'exemple du modèle, fictives.
     *
     * @return list<list<string>>
     */
    public static function templateExamples(): array
    {
        return [
            [
                'R-001', __('Theft of a laptop containing customer data', 'grcmanager'),
                __('A laptop is stolen while travelling; its disk holds customer files.', 'grcmanager'),
                Risk::getCategories()['technical'], '3', '3', '1', '3', Risk::getTreatments()['mitigate'],
                __('Disk encryption deployed on every laptop.', 'grcmanager'), '', '2026-12-31',
                Risk::getStatuses()['in_treatment'], 'A.8.1, A.8.24', '',
            ],
            [
                'R-002', __('Prolonged outage of the hosting provider', 'grcmanager'), '',
                Risk::getCategories()['third_party'], '2', '4', '', '', Risk::getTreatments()['transfer'],
                __('Penalties and recovery time written into the contract.', 'grcmanager'), '', '2027-03-31',
                '', 'A.5.19, A.5.20, A.5.30', '',
            ],
            [
                'R-003', __('Targeted phishing of the accounting team', 'grcmanager'), '',
                Risk::getCategories()['people'], Risk::getProbabilities()['probable'], Risk::getImpacts()['high'],
                '', '', '', '', '', '', '', 'A.6.3', '',
            ],
        ];
    }

    /**
     * Raison d'un rejet de ligne, dans la langue de l'utilisateur.
     */
    public static function errorLabel(string $reason, string $detail): string
    {
        $columns = self::columnLabels();

        return match ($reason) {
            RiskRegisterCsv::ERROR_EMPTY              => __('The file is empty.', 'grcmanager'),
            RiskRegisterCsv::ERROR_MISSING_COLUMNS    => __(
                'Header line without the "reference" and "title" columns: start from the template.',
                'grcmanager'
            ),
            RiskRegisterCsv::ERROR_MISSING_REFERENCE  => __(
                'Reference missing: each risk needs its own reference, so that a later import updates it '
                    . 'instead of creating a duplicate.',
                'grcmanager'
            ),
            RiskRegisterCsv::ERROR_REFERENCE_TOO_LONG => sprintf(
                __('Reference longer than %d characters.', 'grcmanager'),
                RiskRegisterCsv::MAX_REFERENCE_LENGTH
            ),
            RiskRegisterCsv::ERROR_MISSING_TITLE      => __('Title missing.', 'grcmanager'),
            RiskRegisterCsv::ERROR_DUPLICATE_REFERENCE => sprintf(
                __('Reference already used on line %s.', 'grcmanager'),
                $detail
            ),
            RiskRegisterCsv::ERROR_UNKNOWN_VALUE      => self::unknownValueLabel($detail, $columns),
            RiskRegisterCsv::ERROR_UNKNOWN_OWNER      => sprintf(
                __('Owner not found: %s (expected: login, e-mail address or first and last name of a GLPI user).', 'grcmanager'),
                $detail
            ),
            RiskRegisterCsv::ERROR_AMBIGUOUS_OWNER    => sprintf(
                __('Several users match the owner %s: use their login or e-mail address.', 'grcmanager'),
                $detail
            ),
            RiskRegisterCsv::ERROR_UNKNOWN_ANNEX_A    => sprintf(
                __('Unknown ISO 27001 Annex A control: %s (expected format: A.5.1).', 'grcmanager'),
                $detail
            ),
            RiskRegisterCsv::ERROR_UNKNOWN_ASSET      => sprintf(
                __('Asset not found in GLPI: %s (expected: name or inventory number of an asset you can see).', 'grcmanager'),
                $detail
            ),
            RiskRegisterCsv::ERROR_AMBIGUOUS_ASSET    => sprintf(
                __('Several assets are named %s: use their inventory number.', 'grcmanager'),
                $detail
            ),
            RiskRegisterCsv::ERROR_INVALID_DATE       => sprintf(
                __('Invalid review date: %s (expected: 31/12/2026 or 2026-12-31).', 'grcmanager'),
                $detail
            ),
            RiskRegisterCsv::ERROR_TOO_MANY_ROWS      => sprintf(
                __('More than %s risks: the following lines were not read.', 'grcmanager'),
                $detail
            ),
            RiskRegisterSync::ERROR_AMBIGUOUS_REFERENCE => sprintf(
                __('%s risks of the register already carry this reference: correct them in GLPI first.', 'grcmanager'),
                $detail
            ),
            RiskRegisterSync::ERROR_CLOSE_WITHOUT_PLAN => __(
                'A risk being mitigated or transferred cannot be closed without a treatment plan: add its actions in GLPI first.',
                'grcmanager'
            ),
            'no_create_right'                         => __('You are not allowed to create risks.', 'grcmanager'),
            'no_update_right'                         => __('You are not allowed to modify risks.', 'grcmanager'),
            default                                   => $reason,
        };
    }

    /**
     * @param array<string, string> $columns
     */
    private static function unknownValueLabel(string $detail, array $columns): string
    {
        [$column, $value] = explode("\x1F", $detail, 2) + ['', ''];
        $allowed = match ($column) {
            'category'                            => Risk::getCategories(),
            'probability', 'residual_probability' => Risk::getProbabilities(),
            'impact', 'residual_impact'           => Risk::getImpacts(),
            'treatment'                           => array_filter(Risk::getTreatments()),
            'status'                              => Risk::getStatuses(),
            default                               => [],
        };
        $numeric = in_array($column, ['probability', 'residual_probability', 'impact', 'residual_impact'], true);

        return sprintf(
            $numeric
                ? __('Unknown value for "%1$s": %2$s. Expected: %3$s, or a score within the scale chosen below.', 'grcmanager')
                : __('Unknown value for "%1$s": %2$s. Expected: %3$s.', 'grcmanager'),
            $columns[$column] ?? $column,
            $value,
            implode(', ', $allowed)
        );
    }

    /**
     * @return array<string, string> colonne ou champ => libellé
     */
    public static function columnLabels(): array
    {
        return [
            'reference'            => __('Reference', 'grcmanager'),
            'title'                => __('Title', 'grcmanager'),
            'description'          => __('Description', 'grcmanager'),
            'category'             => __('Category', 'grcmanager'),
            'probability'          => __('Probability', 'grcmanager'),
            'impact'               => __('Impact', 'grcmanager'),
            'residual_probability' => __('Residual probability', 'grcmanager'),
            'residual_impact'      => __('Residual impact', 'grcmanager'),
            'treatment'            => __('Treatment', 'grcmanager'),
            'justification'        => __('Justification', 'grcmanager'),
            'owner'                => _x('owner', 'Owner', 'grcmanager'),
            'users_id'             => _x('owner', 'Owner', 'grcmanager'),
            'review_date'          => __('Review date', 'grcmanager'),
            'status'               => __('Status', 'grcmanager'),
            'annex_a'              => __('Annex A controls', 'grcmanager'),
            'assets'               => __('Linked assets (CMDB)', 'grcmanager'),
        ];
    }

    /**
     * Valeur lisible d'un champ dans l'aperçu.
     *
     * @param mixed $value
     */
    public static function displayValue(string $field, $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        $text = match ($field) {
            'category'                            => Risk::getCategories()[$value] ?? (string) $value,
            'probability', 'residual_probability' => Risk::getProbabilities()[$value] ?? (string) $value,
            'impact', 'residual_impact'           => Risk::getImpacts()[$value] ?? (string) $value,
            'treatment'                           => Risk::getTreatments()[$value] ?? (string) $value,
            'status'                              => Risk::getStatuses()[$value] ?? (string) $value,
            'users_id'                            => (int) $value > 0 ? (string) getUserName((int) $value) : '',
            'annex_a'                             => implode(', ', (array) $value),
            'assets'                              => implode(', ', array_map(
                static fn (string $key): string => self::lookups()['asset_names'][$key] ?? $key,
                (array) $value
            )),
            default                               => (string) $value,
        };

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 77) . '...' : $text;
    }
}
