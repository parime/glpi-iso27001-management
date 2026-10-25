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
use GlpiPlugin\Grcmanager\Compatibility\Base\CanonicalProductBase;

/**
 * Anchor of the CVE-to-inventory correlation catalog (issue: corrélation CPE des CVE avec le
 * parc, cf. ROADMAP.md) : a (manufacturer, product) pair that both an admin-declared CPE
 * identifier (PluginGrcmanagerCpeReference) and an admin-declared GLPI software name
 * (PluginGrcmanagerProductAlias) point back to. Neither satellite table has a menu entry of its
 * own — same "no menu for a simple child record, managed inline from its parent's own form"
 * convention already used for PluginGrcmanagerObjectiveMeasurement (see
 * PluginGrcmanagerObjective::showMeasurementHistory()) — see showCpeReferences()/
 * showProductAliases() below.
 *
 * Deliberately NOT an automatic name/version-based guess: exactly like the plugin's own
 * "never invent a value" rule elsewhere (see EnvironmentalData in the sibling plugin
 * assetsign-glpi), an installed software with no matching alias is silently skipped by
 * GlpiPlugin\Grcmanager\Services\Cve\InventoryCveMatcher rather than fuzzy-matched — a wrong
 * correlation would be worse than a missing one for a security decision.
 */
class PluginGrcmanagerCanonicalProduct extends CanonicalProductBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_canonicalproducts';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Produit (corrélation CVE)', 'Produits (corrélation CVE)', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-package';
    }

    /**
     * `UNIQUE(manufacturer, product)` in the database (Installer.php) — without this check, a
     * duplicate submission reaches CommonDBTM::add()'s raw INSERT and surfaces as an uncaught
     * RuntimeException (HTTP 500, confirmed live against a real GLPI instance) instead of the
     * normal Session::addMessageAfterRedirect() error flow every other validation failure in this
     * plugin uses.
     */
    public function prepareInputForAdd($input)
    {
        return $this->rejectIfDuplicate($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->rejectIfDuplicate($input, (int) $this->getID());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function rejectIfDuplicate(array $input, int $excludeId = 0)
    {
        $manufacturer = trim((string) ($input['manufacturer'] ?? ($this->fields['manufacturer'] ?? '')));
        $product = trim((string) ($input['product'] ?? ($this->fields['product'] ?? '')));

        if ($manufacturer === '' || $product === '') {
            Session::addMessageAfterRedirect(
                __('L\'éditeur/fabricant et le produit sont obligatoires.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        global $DB;
        $where = ['manufacturer' => $manufacturer, 'product' => $product];
        if ($excludeId > 0) {
            $where['id'] = ['<>', $excludeId];
        }
        $exists = $DB->request(['FROM' => self::getTable(), 'WHERE' => $where])->count() > 0;

        if ($exists) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Un produit "%s / %s" existe déjà.', 'grcmanager'),
                    $manufacturer,
                    $product
                ),
                false,
                ERROR
            );

            return false;
        }

        $input['manufacturer'] = $manufacturer;
        $input['product'] = $product;

        return $input;
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
            'field'    => 'manufacturer',
            'name'     => __('Éditeur / fabricant', 'grcmanager'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'product',
            'name'     => __('Produit', 'grcmanager'),
            'datatype' => 'string',
        ];

        return $tab;
    }

    /**
     * Les identifiants CPE et alias du produit disparaissent avec lui : restés orphelins, ils
     * bloquaient une nouvelle saisie du même identifiant (clé unique, erreur 500).
     */
    public function cleanDBonPurge()
    {
        foreach ([new PluginGrcmanagerCpeReference(), new PluginGrcmanagerProductAlias()] as $child) {
            $child->deleteByCriteria(['plugin_grcmanager_canonicalproducts_id' => $this->getID()], true);
        }
    }

    public function showForm($ID, array $options = []): bool
    {
        global $CFG_GLPI;

        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@grcmanager/canonical_product_form.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);

        // Comme PluginGrcmanagerObjective::showMeasurementHistory() : les deux listes satellites
        // n'ont de sens que pour un produit déjà enregistré (il leur faut un id réel).
        if ($this->isNewID($ID)) {
            return true;
        }
        $front = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/';
        TemplateRenderer::getInstance()->display('@grcmanager/canonical_product_lists.html.twig', [
            'product_id' => (int) $ID,
            'can_edit'   => Session::haveRight(self::$rightname, UPDATE),
            'lists'      => [
                // Plusieurs CPE peuvent pointer vers le même produit canonique (le NVD référence
                // souvent le même logiciel avec des identifiants légèrement différents).
                [
                    'title'       => __('Identifiants CPE', 'grcmanager'),
                    'help'        => __(
                        'Un identifiant CPE tel que rapporté par le NVD pour ce produit (ex. '
                        . '"cpe:2.3:a:apache:log4j:*:*:*:*:*:*:*:*"). Plusieurs identifiants peuvent pointer '
                        . 'vers le même produit.',
                        'grcmanager'
                    ),
                    'url'         => $front . 'cpereference.form.php',
                    'field'       => 'cpe',
                    'placeholder' => 'cpe:2.3:a:vendor:product:*:*:*:*:*:*:*:*',
                    'code'        => true,
                    'empty'       => __('Aucun identifiant CPE pour l\'instant.', 'grcmanager'),
                    'rows'        => array_map(
                        static fn (array $row): array => ['id' => (int) $row['id'], 'value' => (string) $row['cpe']],
                        PluginGrcmanagerCpeReference::getForCanonicalProduct((int) $ID)
                    ),
                ],
                // Le nom EXACT tel qu'il apparaît dans l'inventaire (glpi_softwares.name), jamais
                // une correspondance approximative : voir Services\Cve\InventoryCveMatcher.
                [
                    'title'       => __('Alias de logiciels (parc GLPI)', 'grcmanager'),
                    'help'        => __(
                        'Le nom exact tel qu\'il apparaît dans votre inventaire GLPI (fiche Logiciel), pour que '
                        . 'la corrélation puisse reconnaître ce produit sur vos actifs. Aucune correspondance '
                        . 'approximative n\'est faite automatiquement.',
                        'grcmanager'
                    ),
                    'url'         => $front . 'productalias.form.php',
                    'field'       => 'alias',
                    'placeholder' => 'ex. Apache Log4j',
                    'code'        => false,
                    'empty'       => __('Aucun alias pour l\'instant.', 'grcmanager'),
                    'rows'        => array_map(
                        static fn (array $row): array => ['id' => (int) $row['id'], 'value' => (string) $row['alias']],
                        PluginGrcmanagerProductAlias::getForCanonicalProduct((int) $ID)
                    ),
                ],
            ],
        ]);

        return true;
    }
}
