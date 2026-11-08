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

use GlpiPlugin\Grcmanager\Compatibility\Base\CustomFrameworkBase;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkSync;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use Session;

/**
 * Issue #537 : référentiel personnalisé importé depuis un fichier CSV (exigence interne de groupe,
 * référentiel sectoriel ou contractuel, plan d'assurance sécurité d'un client). Activable comme les
 * référentiels intégrés (clé « custom_<id> », FrameworkConfig), mesuré par les mesures de l'Annexe A
 * que ses exigences citent, et réimportable sans perdre les statuts saisis (CustomFrameworkSync).
 *
 * Registre global, comme la Déclaration d'applicabilité sur laquelle il s'appuie.
 */
class CustomFramework extends CustomFrameworkBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_customframeworks';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Custom framework', 'Custom frameworks', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-spreadsheet';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/customframework.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/customframework.form.php';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(self::RIGHTNAME, READ)) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public function prepareInputForAdd($input)
    {
        return $this->checkName($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->checkName($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkName(array $input): array|false
    {
        foreach (['name', 'version', 'source'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = mb_substr(trim((string) $input[$field]), 0, 255);
            }
        }
        if (array_key_exists('name', $input) && $input['name'] === '') {
            Session::addMessageAfterRedirect(htmlescape(__('Give the framework a name.', 'grcmanager')), false, ERROR);

            return false;
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        (new CustomRequirement())->deleteByCriteria(['plugin_grcmanager_customframeworks_id' => $this->getID()]);
    }

    public function post_purgeItem()
    {
        FrameworkConfig::reset();
    }

    public function post_addItem()
    {
        FrameworkConfig::reset();
    }

    public function post_updateItem($history = true)
    {
        FrameworkConfig::reset();
    }

    public function getFrameworkKey(): string
    {
        return CustomFrameworkCatalog::key($this->getID());
    }

    /**
     * Référentiels et exigences non retirées, pour CustomFrameworkCatalog (une lecture par requête).
     *
     * @return list<array{id: int, name: string, version: string,
     *     requirements: list<array{code: string, theme: string, title: string, annex_a: list<string>}>}>
     */
    public static function catalogRows(): array
    {
        global $DB;

        if (!$DB->tableExists(self::getTable()) || !$DB->tableExists(CustomRequirement::getTable())) {
            return [];
        }
        $requirements = [];
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_customframeworks_id', 'code', 'theme', 'title', 'annex_a'],
                'FROM'   => CustomRequirement::getTable(),
                'WHERE'  => ['is_removed' => 0],
                'ORDER'  => ['rank', 'id'],
            ]) as $row
        ) {
            $requirements[(int) $row['plugin_grcmanager_customframeworks_id']][] = [
                'code'    => (string) $row['code'],
                'theme'   => (string) $row['theme'],
                'title'   => (string) $row['title'],
                'annex_a' => CustomFrameworkSync::decodeAnnexA((string) $row['annex_a']),
            ];
        }
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => ['id']]) as $row) {
            $rows[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'version'      => (string) $row['version'],
                'requirements' => $requirements[(int) $row['id']] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Exigences du référentiel, actives d'abord dans l'ordre du fichier, puis les retirées.
     *
     * @return list<array<string, mixed>>
     */
    public static function getRequirements(int $id): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => CustomRequirement::getTable(),
                'WHERE' => ['plugin_grcmanager_customframeworks_id' => $id],
                'ORDER' => ['is_removed', 'rank', 'id'],
            ]) as $row
        ) {
            $row['annex_a_list'] = CustomFrameworkSync::decodeAnnexA((string) $row['annex_a']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Exigences enregistrées, au format attendu par CustomFrameworkSync::plan().
     *
     * @return array<int, array{code: string, theme: string, title: string, description: string,
     *     annex_a: string, rank: int, is_removed: int}>
     */
    public static function getExistingForSync(int $id): array
    {
        $existing = [];
        foreach (self::getRequirements($id) as $row) {
            $existing[(int) $row['id']] = [
                'code'        => (string) $row['code'],
                'theme'       => (string) $row['theme'],
                'title'       => (string) $row['title'],
                'description' => (string) ($row['description'] ?? ''),
                'annex_a'     => (string) $row['annex_a'],
                'rank'        => (int) $row['rank'],
                'is_removed'  => (int) $row['is_removed'],
            ];
        }

        return $existing;
    }

    /**
     * Applique un plan de CustomFrameworkSync. Le statut et le commentaire ne sont jamais envoyés :
     * ce qui a été saisi reste.
     *
     * @param list<array{action: string, id: int, fields: array<string, string|int>}> $plan
     */
    public static function applyPlan(int $frameworkId, array $plan): void
    {
        $requirement = new CustomRequirement();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach ($plan as $step) {
            switch ($step['action']) {
                case CustomFrameworkSync::CREATE:
                    $requirement->add($step['fields'] + [
                        'plugin_grcmanager_customframeworks_id' => $frameworkId,
                        'is_removed'                            => 0,
                        'status'                                => CustomRequirement::STATUS_NONE,
                    ]);
                    break;
                case CustomFrameworkSync::UPDATE:
                case CustomFrameworkSync::UNCHANGED:
                case CustomFrameworkSync::RESTORE:
                    $requirement->update(['id' => $step['id'], 'is_removed' => 0, 'date_removed' => null]
                        + $step['fields']);
                    break;
                case CustomFrameworkSync::REMOVE:
                    $requirement->update(['id' => $step['id'], 'is_removed' => 1, 'date_removed' => $now]);
                    break;
            }
        }
        FrameworkConfig::reset();
    }

    /**
     * Référentiels avec leur nombre d'exigences et d'exigences évaluées, pour la liste.
     *
     * @return list<array<string, mixed>>
     */
    public static function getList(): array
    {
        global $DB;

        $counts = [];
        foreach (
            $DB->request([
                'SELECT' => ['plugin_grcmanager_customframeworks_id', 'is_removed', 'status'],
                'FROM'   => CustomRequirement::getTable(),
            ]) as $row
        ) {
            $id = (int) $row['plugin_grcmanager_customframeworks_id'];
            $counts[$id] ??= ['active' => 0, 'removed' => 0, 'assessed' => 0];
            if ((int) $row['is_removed'] === 1) {
                $counts[$id]['removed']++;
                continue;
            }
            $counts[$id]['active']++;
            if ((string) $row['status'] !== CustomRequirement::STATUS_NONE) {
                $counts[$id]['assessed']++;
            }
        }
        $list = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => ['name', 'id']]) as $row) {
            $id = (int) $row['id'];
            $key = CustomFrameworkCatalog::key($id);
            $list[] = $row + ($counts[$id] ?? ['active' => 0, 'removed' => 0, 'assessed' => 0]) + [
                'key'     => $key,
                'enabled' => FrameworkConfig::isEnabled($key),
            ];
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    public static function annexACodes(): array
    {
        return array_keys(ControlCatalogDefaults::CONTROLS);
    }

    /**
     * Raison d'un rejet de ligne, dans la langue de l'utilisateur.
     */
    public static function errorLabel(string $reason, string $detail): string
    {
        return match ($reason) {
            CustomFrameworkCsv::ERROR_EMPTY           => __('The file is empty.', 'grcmanager'),
            CustomFrameworkCsv::ERROR_MISSING_COLUMNS => __(
                'Header line without the "code" and "title" columns: start from the template.',
                'grcmanager'
            ),
            CustomFrameworkCsv::ERROR_MISSING_CODE    => __('Code missing.', 'grcmanager'),
            CustomFrameworkCsv::ERROR_MISSING_TITLE   => __('Title missing.', 'grcmanager'),
            CustomFrameworkCsv::ERROR_CODE_TOO_LONG   => sprintf(
                __('Code longer than %d characters.', 'grcmanager'),
                CustomFrameworkCsv::MAX_CODE_LENGTH
            ),
            CustomFrameworkCsv::ERROR_DUPLICATE_CODE  => sprintf(
                __('Code already used on line %s.', 'grcmanager'),
                $detail
            ),
            CustomFrameworkCsv::ERROR_UNKNOWN_ANNEX_A => sprintf(
                __('Unknown ISO 27001 Annex A control: %s (expected format: A.5.1).', 'grcmanager'),
                $detail
            ),
            CustomFrameworkCsv::ERROR_TOO_MANY_ROWS   => sprintf(
                __('More than %s requirements: the following lines were not read.', 'grcmanager'),
                $detail
            ),
            default                                   => $reason,
        };
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
                'GRP-01',
                __('Governance', 'grcmanager'),
                __('Information security policy approved by management', 'grcmanager'),
                __('The policy is reviewed every year and published to all staff.', 'grcmanager'),
                'A.5.1, A.5.4',
            ],
            [
                'GRP-02',
                __('Access', 'grcmanager'),
                __('Multi-factor authentication for remote access', 'grcmanager'),
                __('Every remote access to the information system requires a second factor.', 'grcmanager'),
                'A.8.5',
            ],
            [
                'GRP-03',
                __('Suppliers', 'grcmanager'),
                __('Security clause in supplier contracts', 'grcmanager'),
                '',
                'A.5.19 A.5.20',
            ],
        ];
    }
}
