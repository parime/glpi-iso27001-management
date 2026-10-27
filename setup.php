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

// GLPI does NOT autoload plugin src/ classes on its own (confirmed against a real GLPI 11
// instance by the sibling plugins of this same author, see docs/design/DEVELOPMENT_PLAN.md
// "Sprint 1"). `composer install --no-dev` must be run after cloning, and any release package
// must bundle vendor/, see .github/workflows/release.yml.
require_once __DIR__ . '/vendor/autoload.php';

define('PLUGIN_GRCMANAGER_VERSION', '2.14.0');
define('PLUGIN_GRCMANAGER_MIN_GLPI', '11.0.0');
// GLPI 11 and 12 from a single code base (see src/Compatibility/).
define('PLUGIN_GRCMANAGER_MAX_GLPI', '12.99.99');
// Relevé de 8.1.0 à 8.2.0 en v2.0.0 : exigence héritée du module Incidents de sécurité absorbé
// depuis glpi-security-incidents (ROADMAP.md "Version 2.0"), qui exigeait déjà PHP 8.2 minimum.
define('PLUGIN_GRCMANAGER_MIN_PHP', '8.2.0');

/**
 * Called by GLPI on every page load once the plugin is active. Registers hooks.
 */
function plugin_init_grcmanager(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $PLUGIN_HOOKS['csrf_compliant']['grcmanager'] = true;

    if (!Plugin::isPluginActive('grcmanager')) {
        return;
    }

    // Issue #142, phase 2 : le flux iCal d'abonnement est lu par un agenda externe, sans session.
    // Seule page du plugin ainsi ouverte : elle s'authentifie elle-même par le jeton personnel de
    // l'utilisateur (voir front/compliancecalendar.ics.php).
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
        'grcmanager',
        '#^/front/compliancecalendar\.ics\.php$#',
        \Glpi\Http\Firewall::STRATEGY_NO_CHECK
    );

    // GLPI's own legacy `inc/{name}.class.php` autoloader (src/autoload/legacy-autoloader.php,
    // glpi_autoload()) only fires for a classname that itself STARTS WITH "Plugin" (or the PSR-4
    // namespace prefix) -- confirmed by reading that function directly. GLPI core's own rule-engine
    // convention (CommonITILObject::getRuleCollectionClassInstance(), `'Rule' . static::getType() .
    // 'Collection'`) always produces a name where "Plugin..." sits in the MIDDLE
    // ("RulePluginGrcmanagerSecurityIncidentCollection"), never at the start -- so for ANY plugin
    // exposing a custom CommonITILObject, its Rule/RuleCollection pair can never be reached by
    // either of glpi_autoload()'s two lookup paths, no matter where the files live. Every other
    // class in inc/ is named `PluginGrcmanagerXxx` and loads fine through that same autoloader;
    // only these two are structurally unreachable by it. Confirmed live: linking an asset to a
    // security incident fatals with "Collection class RulePluginGrcmanagerSecurityIncidentCollection
    // does not exists" without this explicit require, even though both classes already exist with
    // correct content. Deliberately here, not at this file's top level: `RuleCommonITILObject`
    // (the parent class) only exists inside a real GLPI Kernel boot, and setup.php is also
    // require_once'd standalone by SetupMenuRedefinitionTest (tests/Unit/) with no GLPI loaded at
    // all — a top-level require here would fatal that test with "Class RuleCommonITILObject not
    // found" (confirmed the hard way, first attempt at this fix).
    require_once __DIR__ . '/inc/rulesecurityincident.class.php';
    require_once __DIR__ . '/inc/rulesecurityincidentcollection.class.php';

    // Menu « GRC & Conformité » : un secteur de premier niveau dédié. Une clé de catégorie inconnue
    // des secteurs natifs fait créer par Html::generateMenuSession() (cœur de GLPI) une nouvelle
    // entrée de la barre latérale. PluginGrcmanagerMenu (inc/menu.class.php), ancre sans table, est
    // placée EN TÊTE du tableau pour que le titre et l'icône du secteur soient les siens et non ceux
    // du premier écran fonctionnel ; plugin_grcmanager_redefine_menus() retire ensuite sa ligne.
    //
    // Six rubriques, dans l'ordre du cycle du SMSI : pilotage, risques, conformité, audits et
    // amélioration, opérations, sécurité technique. Chaque rubrique est une seule entrée de menu ;
    // les autres écrans de la rubrique sont ses sous-pages, avec une barre d'onglets commune
    // (PluginGrcmanagerMenu::sections()) et le fil d'Ariane « Rubrique > Page ». Les objets enfants
    // (mesures d'un objectif, actions de traitement d'un risque…) n'ont pas d'entrée : ils se gèrent
    // depuis la fiche de leur parent.
    $menuToAdd = [
        'grcmanager' => [
            PluginGrcmanagerMenu::class,
            // Issue #247 : tableau de bord en tête, comme dans Parc et Assistance.
            PluginGrcmanagerDashboard::class,
            PluginGrcmanagerRisk::class,
            PluginGrcmanagerControl::class,
            PluginGrcmanagerAudit::class,
            PluginGrcmanagerAccessReview::class,
            PluginGrcmanagerComplianceAudit::class,
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
    // Issue #247 : Html::header() ne charge la grille et les graphiques des tableaux de bord que
    // pour les pages déclarées ici (même mécanisme que Parc > Tableau de bord ; clé en minuscules).
    $CFG_GLPI['javascript']['grcmanager'][strtolower(PluginGrcmanagerDashboard::class)] = ['dashboard'];

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
    // Issue #170 : validation signée d'une dérogation, analyse de risque jointe.
    $CFG_GLPI['document_types'][] = PluginGrcmanagerWaiver::class;
    // Issue #139 : feuille d'émargement, support et quiz d'une session de formation.
    $CFG_GLPI['document_types'][] = PluginGrcmanagerTraining::class;
    // Issue #140 : pièces d'un audit interne et de ses constats.
    $CFG_GLPI['document_types'][] = PluginGrcmanagerAudit::class;
    $CFG_GLPI['document_types'][] = PluginGrcmanagerNonconformity::class;
    // Issue #113 : preuves des exigences du SMSI, même mécanisme.
    $CFG_GLPI['document_types'][] = PluginGrcmanagerIsmsRequirement::class;
    // Issue #127 : preuves de l'évaluation d'un fournisseur (questionnaire signé, certificats).
    $CFG_GLPI['document_types'][] = PluginGrcmanagerSupplierRisk::class;
    // Issue #126 : rapport PDF de clôture d'une revue des accès, sur la campagne et sur la mesure.
    $CFG_GLPI['document_types'][] = PluginGrcmanagerAccessReview::class;
    $CFG_GLPI['document_types'][] = PluginGrcmanagerControl::class;
    // Issue #142 : documents d'un evenement de conformite (rapport d'audit, de pentest...).
    $CFG_GLPI['document_types'][] = PluginGrcmanagerComplianceEvent::class;
    // Echeances de conformite dans le planning GLPI de chaque responsable (lecture seule).
    $CFG_GLPI['planning_types'][] = PluginGrcmanagerComplianceEvent::class;

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
    // onglets (voir son setup.php).
    //
    // Issue #88 : cette liste utilisait auparavant LinkableItemtypes::DEFAULT_ITEMTYPES (fixe),
    // ce qui privait tout actif personnalisé créé par un autre plugin (Configuration-glpi-auto :
    // Vehicule, Serveur, Local...) de cet onglet sur sa propre fiche, même si le lien
    // registre-de-risques/actif restait fonctionnel depuis le formulaire du risque lui-même.
    // PluginGrcmanagerRisk::getLinkableItemtypes() interroge déjà directement la table SQL
    // glpi_assets_assetdefinitions (voir son docblock) plutôt que
    // Glpi\Asset\AssetDefinitionManager::getInstance()->getDefinitions() (qui renverrait un
    // tableau vide ici : ce hook s'exécute pendant le listener InitializePlugins, avant
    // CustomObjectsBoot) — exactement le même contournement, déjà éprouvé en production, que
    // Config::getAllManageableItemtypes() du plugin jumeau assetsign-glpi, qui l'appelle lui
    // aussi directement depuis son propre plugin_init() pour construire ses listes d'addtabon.
    Plugin::registerClass(PluginGrcmanagerRisk::class, [
        'addtabon' => PluginGrcmanagerRisk::getLinkableItemtypes(),
    ]);

    // Issue #26 (classification Confidentialité/Intégrité/Disponibilité des actifs) : même
    // mécanisme qu'immédiatement ci-dessus pour l'onglet "Risques" de l'issue #25 (voir son
    // commentaire, y compris pour le correctif de l'issue #88), un second onglet indépendant sur
    // la fiche de chaque actif liable pour consulter/éditer sa classification C/I/D (voir
    // PluginGrcmanagerAssetClassification::getTabNameForItem()/displayTabContentForItem()).
    Plugin::registerClass(PluginGrcmanagerAssetClassification::class, [
        'addtabon' => PluginGrcmanagerRisk::getLinkableItemtypes(),
    ]);

    // Issue #145 : onglet « Validités des preuves » sur les éléments dont les documents prouvent
    // quelque chose pour une durée limitée (mesures, risques fournisseurs, audits, politiques).
    // Issue #170 : onglet « Dérogations » sur les actifs, les mesures et les politiques.
    Plugin::registerClass(PluginGrcmanagerWaiver::class, [
        'addtabon' => array_merge(
            PluginGrcmanagerRisk::getLinkableItemtypes(),
            [PluginGrcmanagerControl::class, PluginGrcmanagerPolicy::class]
        ),
    ]);

    // Issue #160, phase 2 : onglet « Vulnérabilités » sur les éléments qui portent des logiciels.
    Plugin::registerClass(PluginGrcmanagerParkVulnerability::class, [
        'addtabon' => $CFG_GLPI['software_types'] ?? ['Computer'],
    ]);

    // Issue #169 : onglet « Rapport post-incident » sur les incidents de sécurité.
    Plugin::registerClass(PluginGrcmanagerSecurityIncidentPostMortem::class, [
        'addtabon' => [PluginGrcmanagerSecurityIncident::class],
    ]);
    // Issue #185 : onglet « Cellule de crise » de l'incident.
    Plugin::registerClass(PluginGrcmanagerCrisisExercise::class, [
        'addtabon' => [PluginGrcmanagerSecurityIncident::class],
    ]);

    // Issue #171 : onglet « Effet domino » sur les risques, risques fournisseurs et incidents.
    Plugin::registerClass(PluginGrcmanagerCausalLink::class, [
        'addtabon' => PluginGrcmanagerCausalLink::getLinkableItemtypes(),
    ]);
    // Issue #177 : onglet « Impact d'une panne » sur les actifs.
    Plugin::registerClass(PluginGrcmanagerImpactAnalysis::class, [
        'addtabon' => PluginGrcmanagerRisk::getLinkableItemtypes(),
    ]);

    // Issue #164 : onglet « Couverture des preuves » sur les mesures et les exigences du SMSI.
    Plugin::registerClass(PluginGrcmanagerCoverageRule::class, [
        'addtabon' => [PluginGrcmanagerControl::class, PluginGrcmanagerIsmsRequirement::class],
    ]);

    Plugin::registerClass(PluginGrcmanagerEvidence::class, [
        'addtabon' => \GlpiPlugin\Grcmanager\Services\Evidence\EvidenceValidity::ITEMTYPES,
    ]);

    // Issue #128 : qualifier un ticket en incident de sécurité (onglet sur les deux objets) ; les
    // liens disparaissent avec le ticket ou l'incident supprimé définitivement.
    Plugin::registerClass(PluginGrcmanagerSecurityIncidentTicket::class, [
        'addtabon' => ['Ticket', PluginGrcmanagerSecurityIncident::class],
    ]);
    // Issue #139 : « Mes formations » dans les préférences (résultats et copies de quiz de
    // l'utilisateur, sans droit sur le plugin).
    Plugin::registerClass(PluginGrcmanagerTrainingQuiz::class, ['addtabon' => [Preference::class]]);
    // Onglet « GRC Manager » des profils : attribuer les droits du plugin sans passer par la base.
    Plugin::registerClass(PluginGrcmanagerProfile::class, ['addtabon' => [Profile::class]]);
    // Issue #141 : le ticket créé par le ticket récurrent d'une revue devient son ticket en cours ;
    // sa résolution date la revue (PluginGrcmanagerPeriodicReview::onTicketAdd()/onTicketUpdate()).
    // Issue #140 : un document joint à un audit, ou à un constat d'audit, est réservé à l'équipe
    // de l'audit (PluginGrcmanagerRestrictedDocument::onDocumentItemAdd()).
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['grcmanager'] = [
        'Ticket'        => 'plugin_grcmanager_periodic_review_ticket_add',
        'Document_Item' => 'plugin_grcmanager_restricted_document_item_add',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['grcmanager'] = ['Ticket' => 'plugin_grcmanager_periodic_review_ticket_update'];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['grcmanager'] = [
        'Ticket'                                => 'plugin_grcmanager_purge_security_incident_links',
        PluginGrcmanagerSecurityIncident::class => 'plugin_grcmanager_purge_security_incident_links',
        'Document'                              => 'plugin_grcmanager_forget_restricted_document',
    ];
    // Rapports générés (audit, revue des accès, post-incident, cellule de crise) : lisibles par
    // les seules personnes qui lisent leur objet source, voir PluginGrcmanagerRestrictedDocument.
    $PLUGIN_HOOKS[Hooks::ITEM_CAN]['grcmanager'] = ['Document' => 'plugin_grcmanager_restricted_document_can'];
    $PLUGIN_HOOKS[Hooks::ADD_DEFAULT_WHERE]['grcmanager'] = 'plugin_grcmanager_restricted_documents_where';

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

    // Issue #165 : « Qualifier en incident de sécurité » aussi en action groupée sur les tickets
    // (plugin_grcmanager_MassiveActions(), hook.php).
    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['grcmanager'] = 1;
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
