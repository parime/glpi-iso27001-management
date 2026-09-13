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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Grcmanager\Compatibility\RequirementChecker;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use GlpiPlugin\Grcmanager\Services\Risk\LinkableItemtypes;

// GLPI does NOT autoload plugin src/ classes on its own (confirmed against a real GLPI 11
// instance by the sibling plugins of this same author, see docs/design/DEVELOPMENT_PLAN.md
// "Sprint 1"). `composer install --no-dev` must be run after cloning, and any release package
// must bundle vendor/, see .github/workflows/release.yml.
require_once __DIR__ . '/vendor/autoload.php';

define('PLUGIN_GRCMANAGER_VERSION', '2.1.0');
define('PLUGIN_GRCMANAGER_MIN_GLPI', '11.0.0');
define('PLUGIN_GRCMANAGER_MAX_GLPI', '11.99.99');
// Relevé de 8.1.0 à 8.2.0 en v2.0.0 : exigence héritée du module Incidents de sécurité absorbé
// depuis glpi-security-incidents (ROADMAP.md "Version 2.0"), qui exigeait déjà PHP 8.2 minimum.
define('PLUGIN_GRCMANAGER_MIN_PHP', '8.2.0');

/**
 * Called by GLPI on every page load once the plugin is active. Registers hooks.
 */
function plugin_init_grcmanager(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['grcmanager'] = true;

    if (!Plugin::isPluginActive('grcmanager')) {
        return;
    }

    // Single entry point (matches the "used daily by the RSSI" intent of the plugin: the generic
    // risk register is the first screen a compliance officer needs).
    // Format confirmed against the sibling plugins of this same author (glpi-vulnerability-manager,
    // assetsign-glpi): a flat array of classes per menu category, keyed by GLPI's internal
    // category key, not by its translated display label. Originally keyed by the native 'tools'
    // category ("Outils"), which is where every entry below lived up to and including v1.1.1.
    // Sprint 3 (SoA, clause 6.1.3) adds PluginGrcmanagerControl alongside the risk register, same
    // category. Sprint 4 (audits internes et CAPA, clause 9.2/10.2) adds
    // PluginGrcmanagerAudit and PluginGrcmanagerNonconformity the same way. Sprint 5 (risques
    // fournisseurs/tiers) adds PluginGrcmanagerSupplierRisk right after the generic risk register
    // it mirrors. Sprint 6 (formations et revues de direction, clauses 7.2/7.3/9.3) adds
    // PluginGrcmanagerTraining and PluginGrcmanagerManagementReview. Issue #28 (bibliothèque de
    // politiques de sécurité versionnées, clause A.5.1) adds PluginGrcmanagerPolicy. Issue #30
    // (registre des obligations légales/réglementaires/contractuelles, clause 4.2/A.5.31-36) adds
    // PluginGrcmanagerComplianceObligation right after the SoA it complements. Issue #32
    // (objectifs ISMS et suivi de KPI dans le temps, clause 6.2) adds PluginGrcmanagerObjective
    // last: the dashboard (Sprint 7) shows the ISMS's current state, this screen is where an admin
    // sets and tracks measurable objectives over time, a natural final entry in this same menu.
    // PluginGrcmanagerObjectiveMeasurement (the per-objective measurement history) deliberately
    // has NO menu entry of its own: it is only ever added/removed inline from its parent
    // objective's own form (see PluginGrcmanagerObjective::showMeasurementHistory()), same
    // "no menu entry for a pure link/child table" convention as every many-to-many link in this
    // plugin family.
    // Dedicated first-level menu "GRC & Conformité" (as opposed to a native GLPI category such as
    // 'tools'): using ANY category key not recognised as a native GLPI sector makes
    // Html::generateMenuSession() (GLPI core) create a brand-new top-level sidebar entry for it,
    // sitting alongside Parc/Assistance/Gestion/Outils/Administration/Configuration - the growing
    // list of 11 screens above had made "Outils" cluttered, and none of them are really generic
    // GLPI tools. PluginGrcmanagerMenu (inc/menu.class.php) is a lightweight CommonGLPI anchor with
    // no table of its own, placed FIRST in the array below purely so the sector's title/icon come
    // from IT rather than from the first functional screen (PluginGrcmanagerRisk) - GLPI core fills
    // an unknown category's title/icon from the first entry in its MENU_TOADD array whose
    // getMenuContent()/getIcon() supply one, see PluginGrcmanagerMenu's own docblock.
    $menuToAdd = [
        'grcmanager' => [
            PluginGrcmanagerMenu::class,
            PluginGrcmanagerRisk::class,
            PluginGrcmanagerSupplierRisk::class,
            PluginGrcmanagerControl::class,
            PluginGrcmanagerComplianceObligation::class,
            PluginGrcmanagerAudit::class,
            PluginGrcmanagerNonconformity::class,
            PluginGrcmanagerTraining::class,
            PluginGrcmanagerManagementReview::class,
            PluginGrcmanagerPolicy::class,
            PluginGrcmanagerObjective::class,
            // Corrélation CPE des CVE avec le parc GLPI (cf. ROADMAP.md) : un seul menu entry pour
            // le catalogue de correspondance (le produit canonique) — ses deux tables satellites
            // (CpeReference, ProductAlias) n'ont pas d'entrée propre, gérées en ligne sur le
            // formulaire du produit, même convention que PluginGrcmanagerObjectiveMeasurement.
            PluginGrcmanagerCanonicalProduct::class,
            // Bibliothèque de contrôles étendue (ROADMAP.md "Version 2.2") : dernier ajouté, ancre
            // sans CommonDBTM vers l'écran de consultation NIST CSF/CIS Controls, même convention
            // que PluginGrcmanagerMenu ci-dessus.
            PluginGrcmanagerReferentials::class,
        ],
    ];

    // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : contrairement au reste de
    // ce plugin (registres CommonDBTM dans le secteur "GRC & Conformité" ci-dessus), l'objet ITIL
    // fusionné vit dans le secteur natif "helpdesk" ("Assistance"), aux côtés de Ticket/Problem/
    // Change - un incident géré au quotidien (acteurs, workflow, tâches) appartient à ce secteur,
    // pas au menu de conformité. Un même plugin peut parfaitement enregistrer des classes dans
    // plusieurs secteurs à la fois (confirmé par lecture de Html::generateMenuSession()) : rien
    // n'empêche cet objet de vivre physiquement dans ce plugin tout en apparaissant ailleurs dans
    // le menu. Conditionné par SecurityIncidentModuleConfig : un administrateur qui désactive le
    // module ne voit plus l'entrée de menu, mais les données/droits/tables restent intacts.
    if (SecurityIncidentModuleConfig::load()['securityincident_enabled']) {
        $menuToAdd['helpdesk'] = [PluginGrcmanagerSecurityIncident::class];
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['grcmanager'] = $menuToAdd;

    // Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1) : le fichier joint
    // (PDF, Word...) d'une politique est stocké via le mécanisme natif GLPI Document/Document_Item,
    // jamais un système de stockage propre à ce plugin. Un CommonDBTM de plugin ne reçoit l'onglet
    // "Documents" que si son propre itemtype figure dans $CFG_GLPI['document_types'] (voir
    // src/Glpi/Asset/Capacity/HasDocumentsCapacity::enable(), qui fait exactement ce push pour les
    // actifs personnalisés dotés de la capacité "Documents", et src/autoload/CFG_GLPI.php pour la
    // liste figée des itemtypes cœur), en plus de l'appel explicite à
    // addStandardTab(Document_Item::class, ...) fait dans PluginGrcmanagerPolicy::defineTabs().
    // Sans cette ligne, l'onglet s'afficherait quand même (Document_Item::getTabNameForItem() ne
    // vérifie que Document::canView(), pas getItemtypesThatCanHave()), mais
    // Document::getItemtypesThatCanHave() - utilisée ailleurs dans GLPI core (API, nettoyage
    // polymorphe CommonDBTM::cleanDBonPurge()) - ignorerait cette politique.
    global $CFG_GLPI;
    $CFG_GLPI['document_types'][] = PluginGrcmanagerPolicy::class;

    // Sprint 2 (matrice de risque administrable, front/config.php) : reachable via
    // Configuration > Plugins > wrench icon on this plugin's row, same minimal-footprint pattern
    // as the sibling plugin glpi-vulnerability-manager's own remise-glpi-inspired Config screen
    // (its own setup.php notes it "likewise has no MENU_TOADD entry of its own"). Revisit with a
    // dedicated menu entry only if a later sprint makes this screen something used daily rather
    // than an occasional admin setting.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['grcmanager'] = 'front/config.php';

    // Issue #25 (lien registre de risques <-> actifs GLPI/CMDB) : onglet "Risques" en lecture
    // seule sur la fiche de chaque actif potentiellement lié (voir
    // PluginGrcmanagerRisk::getTabNameForItem()/displayTabContentForItem()), même mécanisme
    // Plugin::registerClass()/addtabon que le plugin jumeau assetsign-glpi pour ses propres
    // onglets (voir son setup.php). Liste FIXE (LinkableItemtypes::DEFAULT_ITEMTYPES), pas le
    // résultat dynamique de PluginGrcmanagerRisk::getLinkableItemtypes() (qui ajoute aussi les
    // actifs personnalisés actifs) : à l'exécution de ce hook (listener InitializePlugins), GLPI
    // n'a pas encore chargé les définitions d'actifs personnalisés en mémoire, même limitation de
    // séquencement déjà documentée par assetsign-glpi pour sa propre
    // Config::getAllManageableItemtypes() (voir son docblock) — un actif personnalisé reste tout
    // de même liable depuis le formulaire du risque, seul l'onglet retour sur sa propre fiche n'est
    // pas posé (voir TECH_DEBT.md).
    Plugin::registerClass(PluginGrcmanagerRisk::class, [
        'addtabon' => LinkableItemtypes::DEFAULT_ITEMTYPES,
    ]);

    // Issue #26 (classification Confidentialité/Intégrité/Disponibilité des actifs) : même
    // mécanisme et même liste FIXE d'itemtypes qu'immédiatement ci-dessus pour l'onglet "Risques"
    // de l'issue #25 (même limitation de séquencement InitializePlugins/CustomObjectsBoot, voir son
    // commentaire ci-dessus et TECH_DEBT.md), un second onglet indépendant sur la fiche de chaque
    // actif liable pour consulter/éditer sa classification C/I/D (voir
    // PluginGrcmanagerAssetClassification::getTabNameForItem()/displayTabContentForItem()).
    Plugin::registerClass(PluginGrcmanagerAssetClassification::class, [
        'addtabon' => LinkableItemtypes::DEFAULT_ITEMTYPES,
    ]);

    // Dashboard KPI cards, kept accumulator-safe from the start (?array $cards = null, merged
    // onto rather than replacing): a bare no-argument signature returning only this plugin's own
    // cards would silently discard every other plugin's contribution when
    // Plugin::doHookFunction() chains multiple plugins hooking the same point, and would itself
    // be discarded when this plugin runs earlier in that chain. See hook.php.
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['grcmanager'] = 'plugin_grcmanager_dashboard_cards';

    // Strips PluginGrcmanagerMenu's own row from the "GRC & Conformité" submenu (v1.1.4): it must
    // stay in the MENU_TOADD array above, FIRST, so Html::generateMenuSession() (GLPI core) still
    // picks up its title/icon for the whole sector - but once that title/icon are set, its own
    // clickable row is a pure visual duplicate of the sector header just above it ("GRC &
    // Conformité" appearing twice, once as the collapsible section and once as its own first
    // link). Html::header() calls Plugin::doHookFunction(Hooks::REDEFINE_MENUS, $menu) right after
    // Html::generateMenuSession() (see GLPI core), specifically to let a plugin post-process the
    // fully-built $menu array - the row is safe to drop by then, title/icon already copied onto
    // $menu['grcmanager'] itself.
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['grcmanager'] = 'plugin_grcmanager_redefine_menus';

    // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : rend l'accordéon "Analyse"
    // (impact/contrôles/plan de retour arrière) et le nouvel accordéon "Classification ISO 27001"
    // directement dans le panneau principal de l'objet ITIL fusionné, exactement là où vivent les
    // accordéons natifs "Analyse"/"Plans" de Change/Problem (câblés en dur pour ces deux seuls
    // types dans components/itilobject/fields_panel.html.twig, non extensibles par un tiers) - ce
    // hook, appelé à la toute fin de ce même panneau, est le point d'extension réel confirmé cette
    // session pour obtenir le même rendu visuel sans patcher le cœur.
    $PLUGIN_HOOKS[Hooks::POST_ITIL_INFO_SECTION]['grcmanager'] = 'plugin_grcmanager_post_itil_info_section';
}

/**
 * @param array<string, mixed> $menu
 * @return array<string, mixed>
 */
function plugin_grcmanager_redefine_menus(array $menu): array
{
    unset($menu['grcmanager']['content'][strtolower(PluginGrcmanagerMenu::class)]);

    return $menu;
}

/**
 * Plugin metadata displayed in GLPI's plugin list.
 */
function plugin_version_grcmanager(): array
{
    return [
        'name'         => 'GLPI GRC Manager',
        'version'      => PLUGIN_GRCMANAGER_VERSION,
        'author'       => 'Vincent GUILLOTTE',
        'license'      => 'GPLv3',
        'homepage'     => 'https://github.com/parime/glpi-grc-manager',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GRCMANAGER_MIN_GLPI,
                'max' => PLUGIN_GRCMANAGER_MAX_GLPI,
            ],
            'php' => [
                'min' => PLUGIN_GRCMANAGER_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Checked by GLPI before allowing activation. Must not assume GLPI's own autoloading has run for
 * plugin classes yet, hence the explicit require above.
 */
function plugin_grcmanager_check_prerequisites(): bool
{
    $checker = new RequirementChecker();

    if (!$checker->isPhpVersionSupported(PHP_VERSION, PLUGIN_GRCMANAGER_MIN_PHP)) {
        echo sprintf(
            'Cette version du plugin nécessite PHP %s minimum.',
            PLUGIN_GRCMANAGER_MIN_PHP
        );
        return false;
    }

    if (
        defined('GLPI_VERSION')
        && !$checker->isGlpiVersionSupported(
            GLPI_VERSION,
            PLUGIN_GRCMANAGER_MIN_GLPI,
            PLUGIN_GRCMANAGER_MAX_GLPI
        )
    ) {
        echo sprintf(
            'Cette version du plugin nécessite GLPI %s minimum (jusqu\'à %s).',
            PLUGIN_GRCMANAGER_MIN_GLPI,
            PLUGIN_GRCMANAGER_MAX_GLPI
        );
        return false;
    }

    return true;
}

/**
 * Checked by GLPI to verify the plugin's own configuration is valid (none required yet).
 */
function plugin_grcmanager_check_config(bool $verbose = false): bool
{
    return true;
}
