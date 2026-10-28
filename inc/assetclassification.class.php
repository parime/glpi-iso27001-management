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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\AssetClassificationBase;
use GlpiPlugin\Grcmanager\Services\Classification\ClassificationLevels;
use GlpiPlugin\Grcmanager\Services\Impact\RecoveryObjectives;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;

/**
 * Confidentialité/Intégrité/Disponibilité (C/I/D) classification register (issue #26, ISO/IEC
 * 27001:2022 controls A.5.9/A.5.12/A.8.2). Deliberately its OWN small register, keyed directly on
 * the real asset (itemtype/items_id, one row per asset via `unicity_link`), not a field bolted
 * onto the risk <-> asset link table from issue #25
 * (glpi_plugin_grcmanager_risks_items/PluginGrcmanagerRisk::getLinkedAssets()): a classification is
 * an inherent property of the asset itself ("the HR database is confidentiality-sensitive"),
 * independent of any one risk that happens to mention it, and must still exist/be readable even
 * for an asset with zero linked risks.
 *
 * Reuses the exact same linkable-itemtype list as issue #25
 * (GlpiPlugin\Grcmanager\Services\Risk\LinkableItemtypes / PluginGrcmanagerRisk::
 * getLinkableItemtypes()) rather than inventing a second, possibly-diverging list of "which GLPI
 * itemtypes does this plugin care about" — see setup.php for the identical
 * Plugin::registerClass()/addtabon mechanism already used for the Sprint-#25 "Risques" reverse tab,
 * applied here for a second, independent tab.
 */
class PluginGrcmanagerAssetClassification extends AssetClassificationBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_assetclassifications';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('C/I/A classification', 'C/I/A classifications', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-lock';
    }

    /**
     * @return array<string, string>
     */
    public static function getLevels(): array
    {
        return [
            ClassificationLevels::LOW    => __('Low', 'grcmanager'),
            ClassificationLevels::MEDIUM => __('Medium', 'grcmanager'),
            ClassificationLevels::HIGH   => __('High', 'grcmanager'),
        ];
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'       => 1,
            'table'    => $this->getTable(),
            'field'    => 'itemtype',
            'name'     => __('Asset type', 'grcmanager'),
            'datatype' => 'itemtypename',
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'confidentiality',
            'name'     => __('Confidentiality', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'integrity',
            'name'     => __('Integrity', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'availability',
            'name'     => __('Availability', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'rto_hours',
            'name'     => __('RTO (maximum downtime)', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'rpo_hours',
            'name'     => __('RPO (maximum data loss)', 'grcmanager'),
            'datatype' => 'specific',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if (in_array($field, ClassificationLevels::AXES, true)) {
            return self::levelBadge($values[$field] ?? null);
        }
        if (in_array($field, ['rto_hours', 'rpo_hours'], true)) {
            return htmlescape(self::getRecoveryOptions()[(int) ($values[$field] ?? 0)] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Same low/medium/high scale for all three axes, color-coded like every other enum badge in
     * this plugin (RiskAssessmentTrait::riskLevelBadge()), but only 3 levels (no "critical") — see
     * ClassificationLevels' own docblock for why this scale is deliberately narrower than the risk
     * impact scale it otherwise resembles.
     */
    public static function levelBadge(?string $value): string
    {
        $levels = [
            ClassificationLevels::LOW    => ['bg-green-lt', __('Low', 'grcmanager')],
            ClassificationLevels::MEDIUM => ['bg-yellow-lt', __('Medium', 'grcmanager')],
            ClassificationLevels::HIGH   => ['bg-orange-lt', __('High', 'grcmanager')],
        ];

        if (!ClassificationLevels::isValid($value)) {
            return Fragment::text(__('Not classified', 'grcmanager'), null, 'text-muted');
        }

        [$class, $label] = $levels[$value];

        return Badge::html($class, null, $label);
    }

    /**
     * Never trusts confidentiality/integrity/availability from the request as-is: an
     * invalid/tampered value always falls back to '' ("not set on this axis", see
     * src/Install/Installer.php's column comment), exactly the empty-string "no decision yet"
     * convention this plugin already uses for `PluginGrcmanagerRisk::treatment`, never stored as
     * garbage nor silently guessed.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function sanitizeLevels(array $input): array
    {
        foreach (ClassificationLevels::AXES as $axis) {
            if (array_key_exists($axis, $input)) {
                $input[$axis] = ClassificationLevels::sanitize($input[$axis]) ?? '';
            }
        }
        // Issue #177, phase 2 : objectifs de reprise, uniquement parmi les durées proposées.
        foreach (['rto_hours', 'rpo_hours'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = RecoveryObjectives::sanitize($input[$field]);
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->sanitizeLevels($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->sanitizeLevels($input);
    }

    /**
     * @return array<string, mixed>|null raw DB row for this asset's classification, or null if it
     *         has never been classified at all (distinct from "classified with all axes empty",
     *         which cannot happen through the form below but is handled the same way by
     *         ClassificationLevels::isClassified() regardless).
     */
    public static function getByItem(string $itemtype, int $itemsId): ?array
    {
        global $DB;

        $criteria = [
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId],
        ];

        foreach ($DB->request($criteria) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>> items_id => raw DB row, every classified asset of
     *         one itemtype in a single query. Used by PluginGrcmanagerRisk::showForm() to annotate
     *         each option of its per-itemtype multi-select without one query per row (issue #26,
     *         "read-only hint next to a linked asset").
     */
    public static function getByItemtype(string $itemtype): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $rows[(int) $row['items_id']] = $row;
        }

        return $rows;
    }

    /**
     * Issue #26, reverse view: read-only summary + edit form on every linkable itemtype's own page
     * (see setup.php, Plugin::registerClass()/addtabon), same mechanism as issue #25's own
     * "Risques" tab (PluginGrcmanagerRisk::getTabNameForItem()). Guarded to only fire for a
     * genuinely linkable $item, same reasoning as PluginGrcmanagerRisk's own guard.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!in_array($item->getType(), PluginGrcmanagerRisk::getLinkableItemtypes(), true)) {
            return '';
        }

        if (!Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1));
    }

    /**
     * Read-only summary of the asset's current C/I/D classification (or an explicit empty state if
     * it has never been classified), followed by a plain 3-dropdown edit form — same "HTML/PHP
     * manual, no JS-heavy widget" convention as every other form in this plugin (see TECH_DEBT.md).
     * Posts to front/assetclassification.form.php (itemtype/items_id carried as hidden fields,
     * this class is polymorphic-keyed, not a single-ID form like every other itemtype in this
     * plugin), which resolves add-vs-update itself via getByItem() rather than requiring the
     * caller to know the row's own numeric id.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof CommonDBTM || !Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $classification = self::getByItem($item->getType(), (int) $item->getID());
        TemplateRenderer::getInstance()->display('@grcmanager/asset_classification_tab.html.twig', [
            'itemtype'       => $item->getType(),
            'items_id'       => (int) $item->getID(),
            'classification' => $classification ?? [],
            'classified'     => ClassificationLevels::isClassified($classification),
            'levels'         => self::getLevels(),
            'recovery'       => self::getRecoveryOptions(),
            'can_edit'       => Session::haveRight(self::$rightname, UPDATE),
            'action'         => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/assetclassification.form.php',
        ]);

        return true;
    }

    /**
     * Issue #177, phase 2 : durées proposées pour les objectifs de reprise (RTO, RPO).
     *
     * @return array<int, string> heures => libellé
     */
    public static function getRecoveryOptions(): array
    {
        return [
            1   => __('1 hour', 'grcmanager'),
            4   => __('4 hours', 'grcmanager'),
            8   => __('8 hours', 'grcmanager'),
            24  => __('24 hours (1 day)', 'grcmanager'),
            48  => __('48 hours (2 days)', 'grcmanager'),
            72  => __('72 hours (3 days)', 'grcmanager'),
            168 => __('1 week', 'grcmanager'),
        ];
    }
}
