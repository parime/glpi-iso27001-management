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
class PluginGrcmanagerCanonicalProduct extends CommonDBTM
{
    public static $rightname = 'plugin_grcmanager';

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

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo '<tr class="tab_bg_1"><td>' . __('Éditeur / fabricant', 'grcmanager') . '</td><td>';
        echo Html::input('manufacturer', ['value' => $this->fields['manufacturer'] ?? '', 'size' => 40]);
        echo '<small class="form-hint">' . __(
            'Nom court, ex. "Apache", "Microsoft" — sert uniquement d\'étiquette, pas de comparaison.',
            'grcmanager'
        ) . '</small>';
        echo '</td>';

        echo '<td>' . __('Produit', 'grcmanager') . '</td><td>';
        echo Html::input('product', ['value' => $this->fields['product'] ?? '', 'size' => 40]);
        echo '</td></tr>';

        $this->showFormButtons($options);

        // Comme PluginGrcmanagerObjective::showMeasurementHistory() : les deux listes satellites
        // n'ont de sens que pour un produit déjà enregistré (il leur faut un id réel).
        if (!$this->isNewID($ID)) {
            $this->showCpeReferences((int) $ID);
            $this->showProductAliases((int) $ID);
        }

        return true;
    }

    /**
     * Mini formulaire d'ajout suivi de la liste des identifiants CPE déjà rattachés à ce produit —
     * plusieurs CPE peuvent pointer vers le même produit canonique (le NVD référence souvent le
     * même logiciel avec des identifiants CPE légèrement différents selon les versions).
     */
    private function showCpeReferences(int $canonicalProductId): void
    {
        global $CFG_GLPI;

        $canEdit = Session::haveRight(self::$rightname, UPDATE);
        $formUrl = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/cpereference.form.php';

        echo '<div class="card mt-3"><div class="card-body">';
        echo '<h3>' . __('Identifiants CPE', 'grcmanager') . '</h3>';
        echo '<p class="text-muted">' . __(
            'Un identifiant CPE tel que rapporté par le NVD pour ce produit (ex. '
            . '"cpe:2.3:a:apache:log4j:*:*:*:*:*:*:*:*"). Plusieurs identifiants peuvent pointer '
            . 'vers le même produit.',
            'grcmanager'
        ) . '</p>';

        if ($canEdit) {
            echo '<form method="post" action="' . htmlescape($formUrl) . '" class="mb-3 d-flex gap-2">';
            echo Html::hidden('plugin_grcmanager_canonicalproducts_id', ['value' => $canonicalProductId]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo '<input type="text" name="cpe" class="form-control" style="max-width: 480px;" '
                . 'placeholder="cpe:2.3:a:vendor:product:*:*:*:*:*:*:*:*">';
            echo Html::submit(__('Ajouter'), ['name' => 'add']);
            echo '</form>';
        }

        echo '<table class="table"><tbody>';
        $references = PluginGrcmanagerCpeReference::getForCanonicalProduct($canonicalProductId);

        if (count($references) === 0) {
            echo '<tr><td class="text-muted">'
                . __('Aucun identifiant CPE pour l\'instant.', 'grcmanager') . '</td></tr>';
        }

        foreach ($references as $reference) {
            echo '<tr><td><code>' . htmlescape($reference['cpe']) . '</code></td>';
            if ($canEdit) {
                echo '<td class="text-end">';
                echo '<form method="post" action="' . htmlescape($formUrl) . '" '
                    . 'onsubmit="return confirm(\'' . __('Confirmer la suppression ?', 'grcmanager') . '\');">';
                echo Html::hidden('id', ['value' => $reference['id']]);
                echo Html::hidden('plugin_grcmanager_canonicalproducts_id', ['value' => $canonicalProductId]);
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo '<button type="submit" name="purge" class="btn btn-sm btn-outline-danger">'
                    . '<i class="ti ti-trash"></i></button>';
                echo '</form></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div></div>';
    }

    /**
     * Même schéma que showCpeReferences() ci-dessus, pour les alias de logiciels GLPI : l'admin
     * saisit ici le nom EXACT tel qu'il apparaît dans l'inventaire (glpi_softwares.name) — jamais
     * une correspondance approximative, voir GlpiPlugin\Grcmanager\Services\Cve\InventoryCveMatcher.
     */
    private function showProductAliases(int $canonicalProductId): void
    {
        global $CFG_GLPI;

        $canEdit = Session::haveRight(self::$rightname, UPDATE);
        $formUrl = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/productalias.form.php';

        echo '<div class="card mt-3"><div class="card-body">';
        echo '<h3>' . __('Alias de logiciels (parc GLPI)', 'grcmanager') . '</h3>';
        echo '<p class="text-muted">' . __(
            'Le nom exact tel qu\'il apparaît dans votre inventaire GLPI (fiche Logiciel), pour que '
            . 'la corrélation puisse reconnaître ce produit sur vos actifs. Aucune correspondance '
            . 'approximative n\'est faite automatiquement.',
            'grcmanager'
        ) . '</p>';

        if ($canEdit) {
            echo '<form method="post" action="' . htmlescape($formUrl) . '" class="mb-3 d-flex gap-2">';
            echo Html::hidden('plugin_grcmanager_canonicalproducts_id', ['value' => $canonicalProductId]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo '<input type="text" name="alias" class="form-control" style="max-width: 480px;" '
                . 'placeholder="ex. Apache Log4j">';
            echo Html::submit(__('Ajouter'), ['name' => 'add']);
            echo '</form>';
        }

        echo '<table class="table"><tbody>';
        $aliases = PluginGrcmanagerProductAlias::getForCanonicalProduct($canonicalProductId);

        if (count($aliases) === 0) {
            echo '<tr><td class="text-muted">' . __('Aucun alias pour l\'instant.', 'grcmanager') . '</td></tr>';
        }

        foreach ($aliases as $alias) {
            echo '<tr><td>' . htmlescape($alias['alias']) . '</td>';
            if ($canEdit) {
                echo '<td class="text-end">';
                echo '<form method="post" action="' . htmlescape($formUrl) . '" '
                    . 'onsubmit="return confirm(\'' . __('Confirmer la suppression ?', 'grcmanager') . '\');">';
                echo Html::hidden('id', ['value' => $alias['id']]);
                echo Html::hidden('plugin_grcmanager_canonicalproducts_id', ['value' => $canonicalProductId]);
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo '<button type="submit" name="purge" class="btn btn-sm btn-outline-danger">'
                    . '<i class="ti ti-trash"></i></button>';
                echo '</form></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div></div>';
    }
}
