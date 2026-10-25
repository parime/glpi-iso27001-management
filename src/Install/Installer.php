<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Install;

use CronTask;
use DBConnection;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Dashboard\DefaultDashboardService;
use GlpiPlugin\Grcmanager\Services\DefaultSearchColumns;
use GlpiPlugin\Grcmanager\Services\Incident\LegacySecurityIncidentMigrator;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use GlpiPlugin\Grcmanager\Services\Cve\NvdConfigDefaults;
use GlpiPlugin\Grcmanager\Services\Review\PeriodicReviewCatalog;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixDefaults;
use Migration;
use Notification;
use NotificationTemplate;
use PluginGrcmanagerSecurityIncident;
use ProfileRight;

/**
 * Handles plugin install/uninstall. Kept out of hook.php so it can evolve (and be
 * integration-tested) without touching the GLPI entry point.
 *
 * NOTE: relies on GLPI core classes (Migration, ProfileRight, DBConnection, global $DB) that are
 * only available inside a running GLPI instance, same exclusion rationale as the sibling plugin
 * glpi-vulnerability-manager, see phpstan.neon.dist.
 */
final class Installer
{
    public const RIGHT_NAME = 'plugin_grcmanager';

    // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : droits dédiés de l'objet
    // ITIL fusionné, distincts de RIGHT_NAME ci-dessus - voir le commentaire dans install().
    public const SECURITY_INCIDENT_RIGHT = 'plugin_grcmanager_securityincident';

    public const SECURITY_INCIDENT_RULE_RIGHT = 'rule_grcmanager_securityincident';

    // Table-name derivation confirmed against a real GLPI 11 instance by the sibling plugins of
    // this same author (glpi-vulnerability-manager, assetsign-glpi): the class-name suffix is
    // lowercased and concatenated as one block (no underscore inserted at camelCase boundaries).
    private const RISKS_TABLE = 'glpi_plugin_grcmanager_risks';

    // Sprint 2 (matrice de risque administrable), same derivation rule.
    private const RISK_MATRIX_CONFIG_TABLE = 'glpi_plugin_grcmanager_riskmatrixconfig';

    // Enrichissement CVE via NVD : réglages administrables (interrupteur, seuil d'alerte CVSS),
    // même schéma mono-ligne (id=1) que RISK_MATRIX_CONFIG_TABLE ci-dessus.
    private const NVD_CONFIG_TABLE = 'glpi_plugin_grcmanager_nvdconfig';

    // Cache d'enrichissement NVD, une ligne par CVE (pas par référence d'incident : plusieurs
    // incidents peuvent citer la même CVE, les données NVD ne dépendent que de l'identifiant),
    // voir GlpiPlugin\Grcmanager\Services\Cve\NvdCveEnrichmentService.
    private const CVE_ENRICHMENTS_TABLE = 'glpi_plugin_grcmanager_cveenrichments';

    // Corrélation CPE des CVE avec le parc GLPI : catalogue de correspondance produit (cf.
    // ROADMAP.md, PluginGrcmanagerCanonicalProduct et ses deux tables satellites ci-dessous).
    private const CANONICAL_PRODUCTS_TABLE = 'glpi_plugin_grcmanager_canonicalproducts';

    private const CPE_REFERENCES_TABLE = 'glpi_plugin_grcmanager_cpereferences';

    private const PRODUCT_ALIASES_TABLE = 'glpi_plugin_grcmanager_productaliases';

    // Sprint 3 (Déclaration d'Applicabilité / SoA, clause 6.1.3), same derivation rule.
    private const CONTROLS_TABLE = 'glpi_plugin_grcmanager_controls';

    // Issue #113 (exigences du SMSI, articles 4 à 10), same table-name derivation rule.
    private const ISMS_REQUIREMENTS_TABLE = 'glpi_plugin_grcmanager_ismsrequirements';

    // Issue #112 (approbation de la SoA par la direction) : versions figées + approbateurs.
    private const SOA_VERSIONS_TABLE = 'glpi_plugin_grcmanager_soaversions';
    private const SOA_VERSIONS_USERS_TABLE = 'glpi_plugin_grcmanager_soaversions_users';

    // Many-to-many link table, `<left>_<right>` naming after the `glpi_plugin_grcmanager_` prefix
    // (both sides already lowercased+concatenated class-name suffixes), matching GLPI core's own
    // `Left_Right` relation-table convention (e.g. glpi_documents_items).
    private const CONTROLS_RISKS_TABLE = 'glpi_plugin_grcmanager_controls_risks';

    // Sprint 4 (audits internes et CAPA, clause 9.2/10.2), same table-name derivation rule.
    private const AUDITS_TABLE = 'glpi_plugin_grcmanager_audits';
    private const NONCONFORMITIES_TABLE = 'glpi_plugin_grcmanager_nonconformities';
    private const AUDITS_CONTROLS_TABLE = 'glpi_plugin_grcmanager_audits_controls';

    // Sprint 5 (risques fournisseurs/tiers), same table-name derivation rule.
    private const SUPPLIER_RISKS_TABLE = 'glpi_plugin_grcmanager_supplierrisks';

    // Sprint 6 (formations et revues de direction, clauses 7.2/7.3/9.3), same table-name
    // derivation rule.
    private const TRAININGS_TABLE = 'glpi_plugin_grcmanager_trainings';
    private const TRAININGS_USERS_TABLE = 'glpi_plugin_grcmanager_trainings_users';

    // Issue #126 (revue périodique des accès), même dérivation de nom de table.
    private const ACCESS_REVIEWS_TABLE = 'glpi_plugin_grcmanager_accessreviews';
    private const ACCESS_REVIEWS_USERS_TABLE = 'glpi_plugin_grcmanager_accessreviews_users';
    private const MANAGEMENT_REVIEWS_TABLE = 'glpi_plugin_grcmanager_managementreviews';
    private const MANAGEMENT_REVIEWS_USERS_TABLE = 'glpi_plugin_grcmanager_managementreviews_users';

    // Issue #25 (lien registre de risques <-> actifs GLPI/CMDB), même dérivation de nom de table.
    private const RISKS_ITEMS_TABLE = 'glpi_plugin_grcmanager_risks_items';

    // Issue #26 (classification Confidentialité/Intégrité/Disponibilité des actifs), même
    // dérivation de nom de table (suffixe de classe en minuscules, sans underscore ajouté à la
    // frontière camelCase) que toutes les autres tables ci-dessus.
    private const ASSET_CLASSIFICATIONS_TABLE = 'glpi_plugin_grcmanager_assetclassifications';

    // Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1), même dérivation de
    // nom de table que toutes les autres tables ci-dessus.
    private const POLICIES_TABLE = 'glpi_plugin_grcmanager_policies';

    // Issue #30 (registre des obligations légales/réglementaires/contractuelles), même dérivation
    // de nom de table que toutes les autres tables ci-dessus.
    private const COMPLIANCE_OBLIGATIONS_TABLE = 'glpi_plugin_grcmanager_complianceobligations';

    // Issue #32 (objectifs ISMS et suivi de KPI dans le temps, clause 6.2), même dérivation de nom
    // de table que toutes les autres ci-dessus.
    private const OBJECTIVES_TABLE = 'glpi_plugin_grcmanager_objectives';
    private const OBJECTIVE_MEASUREMENTS_TABLE = 'glpi_plugin_grcmanager_objectivemeasurements';
    private const MANAGEMENT_REVIEWS_OBJECTIVES_TABLE
        = 'glpi_plugin_grcmanager_managementreviews_objectives';

    // Issue #29 (registre des incidents de sécurité de l'information, A.5.24-27). Absorption de
    // glpi-security-incidents (ROADMAP.md "Version 2.0") : ce nom de table reste inchangé, mais son
    // schéma change du tout au tout (objet ITIL complet au lieu d'un simple registre CommonDBTM) —
    // voir la migration légère->fusionnée dans install() ci-dessous, qui renomme l'ancien contenu
    // vers SECURITY_INCIDENTS_LEGACY_TABLE avant de recréer celui-ci avec le nouveau schéma.
    private const SECURITY_INCIDENTS_TABLE = 'glpi_plugin_grcmanager_securityincidents';

    private const SECURITY_INCIDENTS_LEGACY_TABLE = 'glpi_plugin_grcmanager_securityincidents_legacy';

    private const SECURITY_INCIDENTS_USERS_TABLE = 'glpi_plugin_grcmanager_securityincidents_users';

    private const SECURITY_INCIDENTS_GROUPS_TABLE = 'glpi_plugin_grcmanager_securityincidents_groups';

    private const SECURITY_INCIDENTS_SUPPLIERS_TABLE
        = 'glpi_plugin_grcmanager_securityincidents_suppliers';

    private const SECURITY_INCIDENTS_ITEMS_TABLE = 'glpi_plugin_grcmanager_securityincidents_items';

    // Issue #128 : tickets GLPI qualifiés en incident de sécurité.
    private const SECURITY_INCIDENTS_TICKETS_TABLE = 'glpi_plugin_grcmanager_securityincidents_tickets';

    private const SECURITY_INCIDENT_TASKS_TABLE = 'glpi_plugin_grcmanager_securityincidenttasks';

    private const SECURITY_INCIDENT_CVES_TABLE = 'glpi_plugin_grcmanager_securityincidentcves';

    private const SECURITY_INCIDENT_COSTS_TABLE = 'glpi_plugin_grcmanager_securityincidentcosts';

    // Raccourci (ne reflète pas le nom de classe complet) : le nom par défaut que GLPI dériverait
    // (glpi_plugin_grcmanager_securityincidenttemplates_predefinedfields, etc. pour les 4 tables
    // satellites) dépasse la limite de 64 caractères de MySQL pour un identifiant — voir le
    // docblock de PluginGrcmanagerSecurityIncidentTemplate::getTable().
    private const SECURITY_INCIDENT_TEMPLATES_TABLE = 'glpi_plugin_grcmanager_secincidenttemplates';

    private const SECURITY_INCIDENT_TEMPLATES_PREDEFINED_FIELDS_TABLE
        = 'glpi_plugin_grcmanager_secincidenttemplates_predefinedfields';

    private const SECURITY_INCIDENT_TEMPLATES_HIDDEN_FIELDS_TABLE
        = 'glpi_plugin_grcmanager_secincidenttemplates_hiddenfields';

    private const SECURITY_INCIDENT_TEMPLATES_MANDATORY_FIELDS_TABLE
        = 'glpi_plugin_grcmanager_secincidenttemplates_mandatoryfields';

    private const SECURITY_INCIDENT_TEMPLATES_READONLY_FIELDS_TABLE
        = 'glpi_plugin_grcmanager_secincidenttemplates_readonlyfields';

    // Issue #31 (plan d'action de traitement des risques, clause 8.3/6.1.3), même dérivation de nom
    // de table que toutes les autres ci-dessus.
    private const RISK_TREATMENT_ACTIONS_TABLE = 'glpi_plugin_grcmanager_risktreatmentactions';

    // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : interrupteurs
    // module/CVE/modèles/tableau de bord, une seule ligne singleton (id=1), même convention que
    // RISK_MATRIX_CONFIG_TABLE ci-dessus (voir SecurityIncidentModuleConfig). Ne pilote jamais de
    // DDL - seulement l'enregistrement des hooks/menus/onglets à l'exécution.
    private const SECURITY_INCIDENT_CONFIG_TABLE = 'glpi_plugin_grcmanager_securityincidentconfig';
    // Issue #145 : validité des documents de preuve, voir PluginGrcmanagerEvidence.
    private const EVIDENCES_TABLE = 'glpi_plugin_grcmanager_evidences';
    // Issue #141 : revues périodiques du SMSI, voir PluginGrcmanagerPeriodicReview.
    private const PERIODIC_REVIEWS_TABLE = 'glpi_plugin_grcmanager_periodicreviews';
    // Issue #144 : matrice des flux réseau, voir PluginGrcmanagerNetworkFlow.
    private const NETWORK_FLOWS_TABLE = 'glpi_plugin_grcmanager_networkflows';
    // Issue #142 : evenements du calendrier de conformite, voir PluginGrcmanagerComplianceEvent.
    private const COMPLIANCE_EVENTS_TABLE = 'glpi_plugin_grcmanager_complianceevents';
    // Issue #160 : vulnérabilités du parc, voir GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync.
    private const IGNORED_SOFTWARES_TABLE = 'glpi_plugin_grcmanager_ignoredsoftwares';
    private const PARK_VERSION_CHECKS_TABLE = 'glpi_plugin_grcmanager_parkversionchecks';
    private const PARK_CVES_TABLE = 'glpi_plugin_grcmanager_parkcves';
    private const CVE_SIGNALS_TABLE = 'glpi_plugin_grcmanager_cvesignals';
    // Issue #160, phase 2 : ticket ouvert par produit du catalogue (logiciel à mettre à jour).
    private const CERTFR_NOTICES_TABLE = 'glpi_plugin_grcmanager_certfrnotices';
    // Issue #166 : score de maturité enregistré chaque mois.
    private const MATURITY_SNAPSHOTS_TABLE = 'glpi_plugin_grcmanager_maturitysnapshots';
    // Issue #171 : liens cause → conséquence entre risques et incidents (effet domino).
    private const CAUSAL_LINKS_TABLE = 'glpi_plugin_grcmanager_causallinks';
    private const CERTFR_NOTIFIED_TABLE = 'glpi_plugin_grcmanager_certfrnotices_supplierrisks';
    private const PARK_CVE_TICKETS_TABLE = 'glpi_plugin_grcmanager_parkcvetickets';
    // Issue #178 : risques types importés depuis le catalogue, voir PluginGrcmanagerRiskCatalog.
    private const RISK_CATALOG_IMPORTS_TABLE = 'glpi_plugin_grcmanager_riskcatalogimports';
    // Issue #170 : dérogations de sécurité, voir PluginGrcmanagerWaiver.
    private const WAIVERS_TABLE = 'glpi_plugin_grcmanager_waivers';
    private const WAIVERS_ITEMS_TABLE = 'glpi_plugin_grcmanager_waivers_items';
    // Issue #164 : règles de couverture des preuves, voir PluginGrcmanagerCoverageRule.
    private const COVERAGE_RULES_TABLE = 'glpi_plugin_grcmanager_coveragerules';
    // Issue #168 : exercices de crise et leurs étapes chronométrées.
    private const CRISIS_EXERCISES_TABLE = 'glpi_plugin_grcmanager_crisisexercises';
    private const CRISIS_EXERCISE_STEPS_TABLE = 'glpi_plugin_grcmanager_crisisexercisesteps';

    public function install(Migration $migration): bool
    {
        global $DB;

        $migration->setVersion(PLUGIN_GRCMANAGER_VERSION);

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $keySign   = DBConnection::getDefaultPrimaryKeySignOption();

        if (!$DB->tableExists(self::RISKS_TABLE)) {
            $query = "CREATE TABLE `" . self::RISKS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` text,
                `category` varchar(32) NOT NULL DEFAULT 'process'
                    COMMENT 'people, process, physical, third_party, technical',
                `probability` varchar(16) NOT NULL DEFAULT 'possible'
                    COMMENT 'rare, possible, probable, certain',
                `impact` varchar(16) NOT NULL DEFAULT 'medium'
                    COMMENT 'low, medium, high, critical',
                `risk_level` varchar(16) NOT NULL DEFAULT 'medium'
                    COMMENT 'Derived from probability x impact, see RiskScoringService, never entered manually',
                `computed_score` decimal(5,2) NOT NULL DEFAULT 0,
                `treatment` varchar(16) NOT NULL DEFAULT ''
                    COMMENT 'accept, mitigate, transfer, avoid, empty = no decision yet',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Risk owner',
                `justification` text,
                `review_date` date DEFAULT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'identified'
                    COMMENT 'identified, in_treatment, accepted, closed',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `category` (`category`),
                KEY `risk_level` (`risk_level`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Sprint 2 (TECH_DEBT.md "Matrice de risque fixe, non administrable") : matrice
        // probabilité x impact administrable depuis front/config.php (RiskMatrixConfig), une
        // seule ligne singleton (id=1), seedée avec exactement la grille fixe du Sprint 1
        // (RiskMatrixDefaults::MATRIX) pour qu'une installation existante ne voie aucun changement
        // tant qu'un administrateur ne modifie pas la matrice.
        if (!$DB->tableExists(self::RISK_MATRIX_CONFIG_TABLE)) {
            $query = "CREATE TABLE `" . self::RISK_MATRIX_CONFIG_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `matrix` text COMMENT 'JSON - grille probabilite x impact -> niveau de risque',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());

            $DB->insert(self::RISK_MATRIX_CONFIG_TABLE, [
                'matrix'   => json_encode(RiskMatrixDefaults::MATRIX),
                'date_mod' => date('Y-m-d H:i:s'),
            ]);
        }

        // Enrichissement CVE via NVD (cf. ROADMAP.md) : mêmes deux réglages que
        // NvdConfigDefaults, seedés ici pour qu'une instance existante ne voie aucun changement de
        // comportement tant qu'un administrateur n'active pas l'enrichissement (voir
        // GlpiPlugin\Grcmanager\Services\Cve\NvdConfig, front/config.php).
        if (!$DB->tableExists(self::NVD_CONFIG_TABLE)) {
            $query = "CREATE TABLE `" . self::NVD_CONFIG_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `enable_nvd_enrichment` tinyint NOT NULL DEFAULT 0,
                `cvss_alert_threshold` decimal(3,1) NOT NULL DEFAULT 7.0,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());

            $DB->insert(self::NVD_CONFIG_TABLE, [
                'enable_nvd_enrichment' => (int) NvdConfigDefaults::ENABLE_NVD_ENRICHMENT,
                'cvss_alert_threshold'  => NvdConfigDefaults::CVSS_ALERT_THRESHOLD,
                'date_mod'              => date('Y-m-d H:i:s'),
            ]);
        }

        // Cache d'enrichissement NVD lui-même (une ligne par CVE, jamais par référence
        // d'incident) : `fetch_status` distingue explicitement "pas encore tenté"
        // (pending), "récupéré avec succès" (ok), "NVD ne connaît pas cet identifiant"
        // (not_found) et "l'appel a échoué" (error) - jamais un blanc silencieux qui
        // masquerait laquelle de ces situations s'est produite (voir
        // NvdCveEnrichmentService, même principe que EnvironmentalData chez le plugin
        // jumeau assetsign-glpi : ne jamais inventer une donnée absente).
        if (!$DB->tableExists(self::CVE_ENRICHMENTS_TABLE)) {
            $query = "CREATE TABLE `" . self::CVE_ENRICHMENTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `cve_id` varchar(20) NOT NULL,
                `cvss_score` decimal(3,1) DEFAULT NULL,
                `cvss_vector` varchar(255) DEFAULT NULL,
                `severity` varchar(20) DEFAULT NULL,
                `description` text,
                `patch_links` text COMMENT 'JSON - liste de {url, tag}',
                `affected_cpes` mediumtext COMMENT 'JSON - liste de CPE affectes (cf. NvdCveParser)',
                `published_at` timestamp NULL DEFAULT NULL,
                `fetched_at` timestamp NULL DEFAULT NULL,
                `fetch_status` varchar(20) NOT NULL DEFAULT 'pending',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_cve` (`cve_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        } elseif (!$DB->fieldExists(self::CVE_ENRICHMENTS_TABLE, 'affected_cpes')) {
            // Corrélation CPE avec le parc (cf. ROADMAP.md) : ajoutée après la première version de
            // cette table (enrichissement NVD seul) - une instance déjà installée doit recevoir la
            // colonne sans perdre les données déjà enrichies.
            $migration->addField(
                self::CVE_ENRICHMENTS_TABLE,
                'affected_cpes',
                'mediumtext',
                ['after' => 'patch_links', 'comment' => 'JSON - liste de CPE affectes (cf. NvdCveParser)']
            );
            $migration->migrationOneTable(self::CVE_ENRICHMENTS_TABLE);
        } else {
            // Élargit une colonne déjà créée en `text` par une version antérieure de cette
            // migration (limite 64 Ko) vers `mediumtext` (16 Mo) : une CVE très largement diffusée
            // (ex. Log4Shell, CVE-2021-44228) référence des centaines de CPE affectés chez de
            // nombreux éditeurs downstream dans sa réponse NVD réelle, dépassant `text` de manière
            // confirmée en conditions réelles (erreur SQL 1406 "Data too long"). `changeField` est
            // sans risque à rappeler même si la colonne est déjà au bon type (ALTER TABLE... vers
            // le même type, opération idempotente côté MySQL).
            $migration->changeField(
                self::CVE_ENRICHMENTS_TABLE,
                'affected_cpes',
                'affected_cpes',
                'mediumtext',
                ['comment' => 'JSON - liste de CPE affectes (cf. NvdCveParser)']
            );
            $migration->migrationOneTable(self::CVE_ENRICHMENTS_TABLE);
        }

        // Corrélation CPE des CVE avec le parc GLPI (cf. ROADMAP.md) : catalogue de correspondance
        // produit, voir PluginGrcmanagerCanonicalProduct/CpeReference/ProductAlias et
        // GlpiPlugin\Grcmanager\Services\Cve\InventoryCveMatcher. Le produit canonique est le seul
        // des trois avec une entree de menu ; les deux tables satellites sont gerees en ligne sur
        // son propre formulaire, meme convention que OBJECTIVE_MEASUREMENTS_TABLE ci-dessus (pas
        // d'entree de menu pour une simple table enfant).
        if (!$DB->tableExists(self::CANONICAL_PRODUCTS_TABLE)) {
            $query = "CREATE TABLE `" . self::CANONICAL_PRODUCTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `manufacturer` varchar(255) NOT NULL,
                `product` varchar(255) NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_product` (`manufacturer`, `product`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::CPE_REFERENCES_TABLE)) {
            $query = "CREATE TABLE `" . self::CPE_REFERENCES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `cpe` varchar(255) NOT NULL,
                `plugin_grcmanager_canonicalproducts_id` int {$keySign} NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_cpe` (`cpe`),
                KEY `canonicalproducts_id` (`plugin_grcmanager_canonicalproducts_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::PRODUCT_ALIASES_TABLE)) {
            $query = "CREATE TABLE `" . self::PRODUCT_ALIASES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `alias` varchar(255) NOT NULL,
                `plugin_grcmanager_canonicalproducts_id` int {$keySign} NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_alias` (`alias`),
                KEY `canonicalproducts_id` (`plugin_grcmanager_canonicalproducts_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Sprint 3 (SoA, clause 6.1.3) : les 93 mesures Annexe A ISO/IEC 27001:2022, une ligne par
        // mesure. Seule la donnée stable (code, thème) est seedée, jamais l'intitulé traduit (voir
        // PluginGrcmanagerControl::getControlTitles()), sur le même principe que les enums
        // catégorie/probabilité/impact du registre de risques.
        if (!$DB->tableExists(self::CONTROLS_TABLE)) {
            $query = "CREATE TABLE `" . self::CONTROLS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `code` varchar(8) NOT NULL COMMENT 'Identifiant ISO/IEC 27001:2022 Annexe A, ex. A.5.1',
                `theme` varchar(32) NOT NULL
                    COMMENT 'organizational, people, physical, technological',
                `applicability` varchar(16) NOT NULL DEFAULT 'yes' COMMENT 'yes, no, partial',
                `justification` text
                    COMMENT 'Obligatoire si applicability != yes, voir PluginGrcmanagerControl',
                `implementation_status` varchar(16) NOT NULL DEFAULT 'not_started'
                    COMMENT 'not_started, in_progress, implemented, verified',
                `is_reviewed` tinyint NOT NULL DEFAULT 0
                    COMMENT 'Mis a 1 des la premiere sauvegarde explicite via le formulaire',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_code` (`code`),
                KEY `theme` (`theme`),
                KEY `applicability` (`applicability`),
                KEY `implementation_status` (`implementation_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #113 : une ligne par sous-article des articles 4 à 10 (numéro seul, titres courts
        // résolus par PluginGrcmanagerIsmsRequirement::getClauseTitles(), jamais stockés), seedée par
        // seedClauses() ci-dessous ; preuves = Documents GLPI liés (Document_Item).
        // Issue #112 : une ligne par version figée de la SoA soumise à approbation (PDF = Document
        // GLPI lié, `pdf_sha256` = empreinte du fichier, `fingerprint` = empreinte du contenu, voir
        // SoaApprovalLogic), une ligne par approbateur désigné et sa réponse.
        if (!$DB->tableExists(self::SOA_VERSIONS_TABLE)) {
            $query = "CREATE TABLE `" . self::SOA_VERSIONS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `version` int NOT NULL DEFAULT 0,
                `status` varchar(16) NOT NULL DEFAULT 'pending'
                    COMMENT 'pending, approved, rejected, obsolete',
                `fingerprint` char(64) NOT NULL DEFAULT '' COMMENT 'SHA-256 du contenu de la SoA',
                `pdf_sha256` char(64) NOT NULL DEFAULT '' COMMENT 'SHA-256 du PDF fige',
                `documents_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Demandeur',
                `comment` text,
                `date_answered` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_version` (`version`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `documents_id` (`documents_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::SOA_VERSIONS_USERS_TABLE)) {
            $query = "CREATE TABLE `" . self::SOA_VERSIONS_USERS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_soaversions_id` int {$keySign} NOT NULL,
                `users_id` int {$keySign} NOT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected',
                `comment` text,
                `date_answered` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link` (`plugin_grcmanager_soaversions_id`, `users_id`),
                KEY `soaversions_id` (`plugin_grcmanager_soaversions_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::ISMS_REQUIREMENTS_TABLE)) {
            $query = "CREATE TABLE `" . self::ISMS_REQUIREMENTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `code` varchar(8) NOT NULL COMMENT 'Sous-article ISO/IEC 27001:2022, ex. 6.1.2',
                `clause` varchar(2) NOT NULL COMMENT 'Article de premier niveau, 4 a 10',
                `status` varchar(16) NOT NULL DEFAULT 'not_started'
                    COMMENT 'not_started, in_progress, compliant',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_code` (`code`),
                KEY `clause` (`clause`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Many-to-many : risque(s) du registre justifiant ou pilotant la mise en oeuvre d'une
        // mesure Annexe A (voir PluginGrcmanagerControl::getLinkedRisks()/syncLinkedRisks()).
        if (!$DB->tableExists(self::CONTROLS_RISKS_TABLE)) {
            $query = "CREATE TABLE `" . self::CONTROLS_RISKS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_controls_id` int {$keySign} NOT NULL,
                `plugin_grcmanager_risks_id` int {$keySign} NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link` (`plugin_grcmanager_controls_id`, `plugin_grcmanager_risks_id`),
                KEY `controls_id` (`plugin_grcmanager_controls_id`),
                KEY `risks_id` (`plugin_grcmanager_risks_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Sprint 4 (audits internes, clause 9.2) : un audit peut couvrir un ou plusieurs contrôles
        // Annexe A (lien many-to-many, voir PluginGrcmanagerAudit::getLinkedControls()/
        // syncLinkedControls()) et/ou une ou plusieurs catégories de risque (liste séparée par des
        // virgules, résolue via PluginGrcmanagerRisk::getCategories(), jamais une seconde
        // définition de cette énumération).
        if (!$DB->tableExists(self::AUDITS_TABLE)) {
            $query = "CREATE TABLE `" . self::AUDITS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `scope` text,
                `planned_date` date DEFAULT NULL,
                `actual_date` date DEFAULT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Auditeur',
                `status` varchar(16) NOT NULL DEFAULT 'planned'
                    COMMENT 'planned, in_progress, completed, cancelled',
                `conclusion` text,
                `risk_categories` varchar(255) NOT NULL DEFAULT ''
                    COMMENT 'Liste de categories PluginGrcmanagerRisk separees par des virgules',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Many-to-many : contrôle(s) Annexe A dans le périmètre d'un audit (voir
        // PluginGrcmanagerAudit::getLinkedControls()/syncLinkedControls()), même convention que
        // CONTROLS_RISKS_TABLE ci-dessus.
        if (!$DB->tableExists(self::AUDITS_CONTROLS_TABLE)) {
            $query = "CREATE TABLE `" . self::AUDITS_CONTROLS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_audits_id` int {$keySign} NOT NULL,
                `plugin_grcmanager_controls_id` int {$keySign} NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link` (`plugin_grcmanager_audits_id`, `plugin_grcmanager_controls_id`),
                KEY `audits_id` (`plugin_grcmanager_audits_id`),
                KEY `controls_id` (`plugin_grcmanager_controls_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Sprint 4 (non-conformités et CAPA, clause 10.2) : un constat (finding) rattaché à un
        // audit, avec le cycle complet cause racine -> action corrective -> action préventive ->
        // clôture vérifiée que la clause 10.2 impose. `plugin_grcmanager_audits_id` n'est pas une
        // vraie clé étrangère GLPI (pas de ON DELETE CASCADE natif ici), résolue par
        // PluginGrcmanagerNonconformity elle-même, même simplification assumée que le lien
        // contrôle <-> risque du Sprint 3 (voir TECH_DEBT.md).
        if (!$DB->tableExists(self::NONCONFORMITIES_TABLE)) {
            $query = "CREATE TABLE `" . self::NONCONFORMITIES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` text,
                `plugin_grcmanager_audits_id` int {$keySign} NOT NULL DEFAULT 0,
                `finding_type` varchar(16) NOT NULL DEFAULT 'nonconformity'
                    COMMENT 'nonconformity, observation (issue #27, deux axes independants de severity)',
                `severity` varchar(16) NOT NULL DEFAULT 'minor' COMMENT 'minor, major, critical',
                `root_cause` text,
                `corrective_action` text,
                `preventive_action` text,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable',
                `due_date` date DEFAULT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'open'
                    COMMENT 'open, in_progress, closed, verified',
                `closure_verification_date` date DEFAULT NULL,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Issue #163 : incident de sécurité à l origine du plan d action',
                `cve_id` varchar(255) NOT NULL DEFAULT '',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_audits_id` (`plugin_grcmanager_audits_id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `finding_type` (`finding_type`),
                KEY `severity` (`severity`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `due_date` (`due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #27 (distinguer non-conformité et observation/remarque, vocabulaire ISO 19011) :
        // deuxième axe independant de `severity` (voir TECH_DEBT.md Sprint 4, résolu, et le
        // docblock de PluginGrcmanagerNonconformity). `finding_type` est deja dans le CREATE TABLE
        // ci-dessus pour une installation neuve ; ce bloc gere le meme ajout pour une installation
        // existante d'avant l'issue #27, premiere migration de champ de ce fichier (v1.0 n'avait
        // encore jamais eu besoin d'en ajouter une sur une table deja creee), suivant exactement la
        // meme convention idempotente fieldExists/addField/migrationOneTable que le plugin jumeau
        // assetsign-glpi (src/GlpiPlugin/Assetsign/Config.php). Type 'string' (pas un fragment SQL
        // brut comme 'varchar(16)') delibere : Migration::fieldFormat() ne sait appliquer un
        // DEFAULT que pour ses types nommes reconnus (string/bool/integer/...), un fragment SQL
        // brut passe tel quel sans jamais honorer l'option 'value' ci-dessous (verifie en conditions
        // reelles contre GLPI 11). VARCHAR(255) plutot que VARCHAR(16) comme dans le CREATE TABLE
        // ci-dessus : largeur suffisante, l'essentiel est que la valeur par defaut 'nonconformity'
        // soit reellement appliquee (MySQL retro-remplit alors chaque ligne existante avec ce
        // defaut lors de l'ADD COLUMN NOT NULL DEFAULT, jamais laissee a NULL) pour ne jamais
        // reclasser silencieusement en simple observation un constat d'audit deja existant.
        if (!$DB->fieldExists(self::NONCONFORMITIES_TABLE, 'finding_type')) {
            $migration->addField(
                self::NONCONFORMITIES_TABLE,
                'finding_type',
                'string',
                [
                    'value'   => 'nonconformity',
                    'comment' => 'nonconformity, observation (issue #27, deux axes independants de severity)',
                    'after'   => 'plugin_grcmanager_audits_id',
                ]
            );
            $migration->addKey(self::NONCONFORMITIES_TABLE, 'finding_type', 'finding_type');
            $migration->migrationOneTable(self::NONCONFORMITIES_TABLE);
        }

        // Issue #163 : plan d'action proposé à partir d'une CVE d'incident.
        if (!$DB->fieldExists(self::NONCONFORMITIES_TABLE, 'plugin_grcmanager_securityincidents_id')) {
            $migration->addField(self::NONCONFORMITIES_TABLE, 'plugin_grcmanager_securityincidents_id', 'fkey', [
                'after' => 'closure_verification_date',
            ]);
            $migration->addField(self::NONCONFORMITIES_TABLE, 'cve_id', 'string', [
                'value' => '',
                'after' => 'plugin_grcmanager_securityincidents_id',
            ]);
            $migration->addKey(
                self::NONCONFORMITIES_TABLE,
                'plugin_grcmanager_securityincidents_id',
                'plugin_grcmanager_securityincidents_id'
            );
            $migration->migrationOneTable(self::NONCONFORMITIES_TABLE);
        }

        // Sprint 5 (risques fournisseurs/tiers) : même structure que RISKS_TABLE ci-dessus (mêmes
        // colonnes de notation/traitement, voir GlpiPlugin\Grcmanager\Traits\RiskAssessmentTrait),
        // avec en plus `suppliers_id`, une vraie clé étrangère vers le `Supplier` natif de GLPI
        // (`glpi_suppliers`), jamais un concept fournisseur parallèle propre à ce plugin.
        if (!$DB->tableExists(self::SUPPLIER_RISKS_TABLE)) {
            $query = "CREATE TABLE `" . self::SUPPLIER_RISKS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `suppliers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'glpi_suppliers.id',
                `title` varchar(255) NOT NULL,
                `description` text,
                `category` varchar(32) NOT NULL DEFAULT 'third_party'
                    COMMENT 'people, process, physical, third_party, technical',
                `probability` varchar(16) NOT NULL DEFAULT 'possible'
                    COMMENT 'rare, possible, probable, certain',
                `impact` varchar(16) NOT NULL DEFAULT 'medium'
                    COMMENT 'low, medium, high, critical',
                `risk_level` varchar(16) NOT NULL DEFAULT 'medium'
                    COMMENT 'Derived from probability x impact, see RiskAssessmentTrait, never entered manually',
                `computed_score` decimal(5,2) NOT NULL DEFAULT 0,
                `treatment` varchar(16) NOT NULL DEFAULT ''
                    COMMENT 'accept, mitigate, transfer, avoid, empty = no decision yet',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Risk owner',
                `justification` text,
                `review_date` date DEFAULT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'identified'
                    COMMENT 'identified, in_treatment, accepted, closed',
                `questionnaire_answers` text COMMENT 'Issue #127 : question => yes/partial/no/na',
                `questionnaire_score` int DEFAULT NULL COMMENT 'Issue #127 : %, NULL = pas encore rempli',
                `questionnaire_date` timestamp NULL DEFAULT NULL,
                `questionnaire_users_id` int {$keySign} NOT NULL DEFAULT 0,
                `review_tickets_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Issue #127 : ticket de réévaluation',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `suppliers_id` (`suppliers_id`),
                KEY `category` (`category`),
                KEY `risk_level` (`risk_level`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Sprint 6 (suivi des formations de sensibilisation, clauses 7.2/7.3) : une ligne par
        // session/campagne de formation. `renewal_period_months` = 0 signifie qu'aucun
        // renouvellement periodique n'est requis (voir PluginGrcmanagerTraining::getOverdueParticipants()).
        if (!$DB->tableExists(self::TRAININGS_TABLE)) {
            $query = "CREATE TABLE `" . self::TRAININGS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` text,
                `format` varchar(16) NOT NULL DEFAULT 'in_person'
                    COMMENT 'in_person, e_learning, other',
                `target_audience` varchar(255) NOT NULL DEFAULT '' COMMENT 'Texte libre',
                `date_delivered` date DEFAULT NULL,
                `is_mandatory` tinyint NOT NULL DEFAULT 1,
                `renewal_period_months` int NOT NULL DEFAULT 0
                    COMMENT '0 = pas de renouvellement requis',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `format` (`format`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Many-to-many + suivi individuel : un participant (vrai `User` natif de GLPI) par ligne,
        // avec son propre statut/date de realisation (pas seulement un lien, contrairement aux
        // liens controle<->risque/audit<->controle des Sprints 3-4 : un auditeur ISO 27001 doit
        // pouvoir voir qui a precisement termine quelle formation et quand, pas seulement un
        // decompte agrege), voir PluginGrcmanagerTraining::syncParticipants()/getParticipants().
        if (!$DB->tableExists(self::TRAININGS_USERS_TABLE)) {
            $query = "CREATE TABLE `" . self::TRAININGS_USERS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_trainings_id` int {$keySign} NOT NULL,
                `users_id` int {$keySign} NOT NULL,
                `completion_status` varchar(16) NOT NULL DEFAULT 'pending'
                    COMMENT 'pending, completed, exempted',
                `completion_date` date DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link` (`plugin_grcmanager_trainings_id`, `users_id`),
                KEY `trainings_id` (`plugin_grcmanager_trainings_id`),
                KEY `users_id` (`users_id`),
                KEY `completion_status` (`completion_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #126 : campagnes de revue des accès et leurs lignes (un compte par ligne, profils et
        // groupes figés au lancement, décision du responsable et ticket éventuel).
        if (!$DB->tableExists(self::ACCESS_REVIEWS_TABLE)) {
            $query = "CREATE TABLE `" . self::ACCESS_REVIEWS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `frequency` varchar(16) NOT NULL DEFAULT 'annual' COMMENT 'quarterly, semiannual, annual',
                `status` varchar(16) NOT NULL DEFAULT 'draft' COMMENT 'draft, open, closed',
                `date_start` date DEFAULT NULL,
                `due_date` date DEFAULT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Pilote de la campagne',
                `plugin_grcmanager_controls_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Mesure de la SoA',
                `comment` text,
                `documents_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Rapport PDF de clôture',
                `date_launch` timestamp NULL DEFAULT NULL,
                `date_close` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `status` (`status`),
                KEY `plugin_grcmanager_controls_id` (`plugin_grcmanager_controls_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }
        if (!$DB->tableExists(self::ACCESS_REVIEWS_USERS_TABLE)) {
            $query = "CREATE TABLE `" . self::ACCESS_REVIEWS_USERS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_accessreviews_id` int {$keySign} NOT NULL,
                `users_id` int {$keySign} NOT NULL COMMENT 'Compte revu',
                `reviewers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable qui revoit',
                `groups_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Groupe du responsable, 0 sinon',
                `reviewer_source` varchar(16) NOT NULL DEFAULT 'owner' COMMENT 'supervisor, group, owner',
                `profiles` text COMMENT 'JSON : profils et entités au lancement',
                `user_groups` text COMMENT 'JSON : groupes au lancement',
                `decision` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending, keep, modify, remove',
                `comment` text,
                `decided_by` int {$keySign} NOT NULL DEFAULT 0,
                `date_decision` timestamp NULL DEFAULT NULL,
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_reminder` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_line` (`plugin_grcmanager_accessreviews_id`, `users_id`),
                KEY `reviewers_id` (`reviewers_id`),
                KEY `decision` (`decision`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Indicateur manquant identifié lors de l'audit complet du plugin (recherche des bonnes
        // pratiques KPI ISO 27001:2022) : `completion_status` (pending/completed/exempted) ne
        // distingue pas une formation suivie d'une formation réellement réussie - une formation
        // avec évaluation (quiz, test pratique...) peut être "completed" sans que le participant
        // ait démontré la compétence visée. Colonne ajoutée séparément de `completion_status`
        // (pas un remplacement) : les deux axes sont indépendants, une formation peut très bien
        // n'avoir aucune évaluation associée (`not_applicable`, valeur par défaut - n'affecte
        // aucune ligne existante) auquel cas seul `completion_status` compte. Ajoutée après coup
        // sur une table déjà existante, gardée par `fieldExists()` - premier usage réel de ce
        // patron dans ce fichier (jusqu'ici seulement anticipé en commentaire).
        if (!$DB->fieldExists(self::TRAININGS_USERS_TABLE, 'assessment_result')) {
            $migration->addField(
                self::TRAININGS_USERS_TABLE,
                'assessment_result',
                'string',
                [
                    'value'   => 'not_applicable',
                    'comment' => 'not_applicable, passed, failed',
                    'after'   => 'completion_status',
                ]
            );
            $migration->addKey(self::TRAININGS_USERS_TABLE, 'assessment_result');
        }

        // Sprint 6 (revues de direction, clause 9.3) : une ligne par revue de direction realisee ou
        // planifiee, avec l'ordre du jour et les decisions/actions en texte libre (pas un lien
        // fort vers le mecanisme CAPA existant, voir PluginGrcmanagerManagementReview pour le
        // raisonnement, et TECH_DEBT.md).
        if (!$DB->tableExists(self::MANAGEMENT_REVIEWS_TABLE)) {
            $query = "CREATE TABLE `" . self::MANAGEMENT_REVIEWS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'planned' COMMENT 'planned, completed',
                `review_date` date DEFAULT NULL,
                `agenda` text,
                `decisions` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Many-to-many : participants (vrais `User` natifs de GLPI) d'une revue de direction, meme
        // convention "lien simple en acces direct $DB" que AUDITS_CONTROLS_TABLE ci-dessus (voir
        // PluginGrcmanagerManagementReview::getAttendees()/syncAttendees()).
        if (!$DB->tableExists(self::MANAGEMENT_REVIEWS_USERS_TABLE)) {
            $query = "CREATE TABLE `" . self::MANAGEMENT_REVIEWS_USERS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_managementreviews_id` int {$keySign} NOT NULL,
                `users_id` int {$keySign} NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link` (`plugin_grcmanager_managementreviews_id`, `users_id`),
                KEY `reviews_id` (`plugin_grcmanager_managementreviews_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #25 (lien registre de risques <-> actifs GLPI/CMDB) : table de liaison
        // POLYMORPHE (itemtype/items_id), pas une deuxième colonne d'ID fixe comme
        // CONTROLS_RISKS_TABLE/AUDITS_CONTROLS_TABLE ci-dessus (deux itemtypes fixes, propres à ce
        // plugin) : la cible ici est n'importe quel itemtype GLPI géré (Computer, actif
        // personnalisé...), exactement le même modèle que les tables de liaison polymorphes du
        // cœur GLPI lui-même (ex. glpi_documents_items). Un risque peut avoir zéro ligne ici (reste
        // un risque purement organisationnel, ex. "processus de recrutement", voir l'issue) — ce
        // n'est pas une relation obligatoire, contrairement à `users_id` (propriétaire) sur
        // RISKS_TABLE ci-dessus qui, elle, est bien une colonne directe (relation 1-vers-1
        // implicite avec un `User`, toujours renseignée). Voir
        // PluginGrcmanagerRisk::getLinkedAssets()/syncLinkedAssets()/getRisksLinkedToItem().
        if (!$DB->tableExists(self::RISKS_ITEMS_TABLE)) {
            $query = "CREATE TABLE `" . self::RISKS_ITEMS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_risks_id` int {$keySign} NOT NULL,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link` (`plugin_grcmanager_risks_id`, `itemtype`, `items_id`),
                KEY `risks_id` (`plugin_grcmanager_risks_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #26 (classification C/I/D des actifs, ISO/IEC 27001:2022 A.5.9/A.5.12/A.8.2) :
        // registre INDÉPENDANT du lien risque <-> actif de l'issue #25 ci-dessus (RISKS_ITEMS_TABLE)
        // — une classification est une propriété de l'actif lui-même, pas d'un risque particulier
        // qui le mentionne, voir PluginGrcmanagerAssetClassification. Clé composite unique
        // itemtype/items_id (une seule ligne par actif réel, jamais deux classifications
        // concurrentes pour le même actif), même modèle polymorphe que RISKS_ITEMS_TABLE mais SANS
        // second membre de relation (`plugin_grcmanager_risks_id`) : ici l'actif EST l'entité
        // classifiée, pas un lien entre deux entités. Les trois axes sont chacun un varchar
        // optionnel avec défaut '' ("non classifié sur cet axe"), même convention que
        // `PluginGrcmanagerRisk.treatment` ci-dessus ("empty = no decision yet") : une
        // classification partielle (un seul axe renseigné) est un état valide, pas une exigence
        // tout-ou-rien (voir ClassificationLevels::isClassified()).
        if (!$DB->tableExists(self::ASSET_CLASSIFICATIONS_TABLE)) {
            $query = "CREATE TABLE `" . self::ASSET_CLASSIFICATIONS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `confidentiality` varchar(16) NOT NULL DEFAULT ''
                    COMMENT 'low, medium, high, empty = not classified on this axis',
                `integrity` varchar(16) NOT NULL DEFAULT ''
                    COMMENT 'low, medium, high, empty = not classified on this axis',
                `availability` varchar(16) NOT NULL DEFAULT ''
                    COMMENT 'low, medium, high, empty = not classified on this axis',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_item` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1/A.5.1.1/A.5.1.2) :
        // une ligne par politique (charte informatique, politique de mots de passe, PCA...), avec
        // le cycle de vie brouillon -> approuvée -> archivée (voir
        // GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle) et le rappel de revue périodique
        // sur `next_review_date` (voir PolicyReviewReminderService, calqué sur
        // ReviewReminderService du registre de risques). Le document lui-même (PDF, Word...) n'est
        // PAS stocké ici : attaché via le mécanisme natif GLPI Document/Document_Item (voir
        // setup.php, $CFG_GLPI['document_types'], et PluginGrcmanagerPolicy::defineTabs()).
        if (!$DB->tableExists(self::POLICIES_TABLE)) {
            $query = "CREATE TABLE `" . self::POLICIES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `version` varchar(16) NOT NULL DEFAULT '1.0',
                `status` varchar(16) NOT NULL DEFAULT 'draft' COMMENT 'draft, approved, archived',
                `approval_date` date DEFAULT NULL,
                `next_review_date` date DEFAULT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Policy owner',
                `description` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #30 (registre des obligations légales/réglementaires/contractuelles, clause 4.2
        // ISO 27001 "parties intéressées et leurs exigences", Annexe A A.5.31-36) : lien optionnel
        // zéro-ou-un vers un risque du registre en colonne DIRECTE (`plugin_grcmanager_risks_id`),
        // pas une table de liaison many-to-many comme CONTROLS_RISKS_TABLE ci-dessus - voir le
        // docblock de PluginGrcmanagerComplianceObligation pour le raisonnement complet (cardinalité
        // plus simple que le lien risque <-> actifs CMDB de l'issue #25, même choix déjà fait pour
        // `users_id`/propriétaire sur chaque autre registre de ce plugin).
        if (!$DB->tableExists(self::COMPLIANCE_OBLIGATIONS_TABLE)) {
            $query = "CREATE TABLE `" . self::COMPLIANCE_OBLIGATIONS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `type` varchar(16) NOT NULL DEFAULT 'legal'
                    COMMENT 'legal, regulatory, contractual',
                `reference_source` varchar(255) NOT NULL DEFAULT ''
                    COMMENT 'Ex. RGPD, Contrat client Acme SA, Loi n°...',
                `compliance_status` varchar(24) NOT NULL DEFAULT 'not_assessed'
                    COMMENT 'compliant, partially_compliant, non_compliant, not_assessed',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Owner',
                `review_date` date DEFAULT NULL,
                `plugin_grcmanager_risks_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Lien optionnel zero-ou-un vers un risque, 0 = aucun',
                `description` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `type` (`type`),
                KEY `compliance_status` (`compliance_status`),
                KEY `users_id` (`users_id`),
                KEY `review_date` (`review_date`),
                KEY `plugin_grcmanager_risks_id` (`plugin_grcmanager_risks_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #32 (objectifs ISMS et suivi de KPI dans le temps, clause 6.2) : `target_value` et
        // `target_description` sont deux colonnes INDÉPENDANTES et toutes deux nullables plutôt
        // qu'une seule colonne numérique obligatoire — certains objectifs ont une vraie cible
        // chiffrée ("réduire de 20%"), d'autres sont purement qualitatifs ("obtenir la
        // certification ISO 27001") et n'ont rien de sensé à mettre dans une colonne numérique
        // obligatoire (voir PluginGrcmanagerObjective::hasNumericTarget() et
        // GlpiPlugin\Grcmanager\Services\Objective\ObjectiveMeasurementValidator, qui utilisent
        // cette même distinction pour savoir si une mesure doit obligatoirement porter une valeur
        // chiffrée).
        if (!$DB->tableExists(self::OBJECTIVES_TABLE)) {
            $query = "CREATE TABLE `" . self::OBJECTIVES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` text,
                `target_value` decimal(10,2) DEFAULT NULL
                    COMMENT 'Cible numerique, NULL si objectif purement qualitatif',
                `target_description` text
                    COMMENT 'Cible qualitative en texte libre, en complement ou a la place de target_value',
                `target_date` date DEFAULT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Proprietaire de l''objectif',
                `status` varchar(16) NOT NULL DEFAULT 'not_started'
                    COMMENT 'not_started, on_track, at_risk, achieved, missed',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Historique de mesures manuel (issue #32) : une ligne par point de mesure dans le temps
        // ("à cette date, nous en sommes à X"), volontairement PAS auto-calculé depuis d'autres
        // données du plugin (ex. décompte de non-conformités) pour cette première version, même
        // philosophie "version minimale et testée" que le reste de ce plugin (voir TECH_DEBT.md
        // Sprint 2). `plugin_grcmanager_objectives_id` n'est pas une vraie clé étrangère GLPI
        // (pas de ON DELETE CASCADE natif ici), même simplification assumée que
        // `PluginGrcmanagerNonconformity.plugin_grcmanager_audits_id` (TECH_DEBT.md Sprint 4).
        // `value` nullable : une mesure sur un objectif purement qualitatif peut n'avoir aucune
        // valeur chiffrée, voir ObjectiveMeasurementValidator ci-dessus.
        // Issue #145 : une ligne par (élément du plugin, document rattaché) qui porte une date de
        // validité. Entité reprise de l'élément porteur (restriction d'entité de la liste).
        if (!$DB->tableExists(self::EVIDENCES_TABLE)) {
            $query = "CREATE TABLE `" . self::EVIDENCES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                `documents_id` int {$keySign} NOT NULL DEFAULT 0,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `valid_until` date DEFAULT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable du renouvellement',
                `date_notified` timestamp NULL DEFAULT NULL,
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Ticket de renouvellement ouvert a expiration',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`itemtype`, `items_id`, `documents_id`),
                KEY `documents_id` (`documents_id`),
                KEY `entities_id` (`entities_id`),
                KEY `valid_until` (`valid_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #141 : une ligne par revue du catalogue (PeriodicReviewCatalog), créée inactive ici
        // et à chaque mise à jour du plugin (nouvelles revues du catalogue) : l'écran ne crée rien.
        // Issue #144 : un flux relie une source et une destination (actif GLPI et/ou libellé libre :
        // réseau, zone, adresse). Registre global, comme les autres registres du plugin.
        // Issue #142 : evenements dates du calendrier de conformite (audit de certification, pentest,
        // exercice de crise...). Registre global, comme les autres registres du plugin.
        if (!$DB->tableExists(self::COMPLIANCE_EVENTS_TABLE)) {
            $query = "CREATE TABLE `" . self::COMPLIANCE_EVENTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `event_type` varchar(32) NOT NULL DEFAULT 'other' COMMENT 'Voir ComplianceCalendarRules',
                `date_begin` timestamp NULL DEFAULT NULL,
                `date_end` timestamp NULL DEFAULT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'planned' COMMENT 'planned, done, cancelled',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable',
                `suppliers_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Prestataire',
                `scope` text,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `date_begin` (`date_begin`),
                KEY `users_id` (`users_id`),
                KEY `suppliers_id` (`suppliers_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::NETWORK_FLOWS_TABLE)) {
            $query = "CREATE TABLE `" . self::NETWORK_FLOWS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `flow_type` varchar(32) NOT NULL DEFAULT 'internal' COMMENT 'Voir NetworkFlowRules::TYPES',
                `source_itemtype` varchar(100) NOT NULL DEFAULT '',
                `source_items_id` int {$keySign} NOT NULL DEFAULT 0,
                `source_label` varchar(255) NOT NULL DEFAULT '',
                `destination_itemtype` varchar(100) NOT NULL DEFAULT '',
                `destination_items_id` int {$keySign} NOT NULL DEFAULT 0,
                `destination_label` varchar(255) NOT NULL DEFAULT '',
                `protocol` varchar(16) NOT NULL DEFAULT 'tcp' COMMENT 'tcp, udp, tcp_udp, icmp, ipsec, any',
                `ports` varchar(255) NOT NULL DEFAULT 'any' COMMENT 'Forme canonique : 80,443,8000-8100 ou any',
                `justification` text,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Propriétaire du flux',
                `date_end` date DEFAULT NULL,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `flow_type` (`flow_type`),
                KEY `users_id` (`users_id`),
                KEY `date_end` (`date_end`),
                KEY `is_active` (`is_active`),
                KEY `source_item` (`source_itemtype`, `source_items_id`),
                KEY `destination_item` (`destination_itemtype`, `destination_items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #164 : couverture des preuves (type d'actif × catégorie de document requise).
        if (!$DB->tableExists(self::COVERAGE_RULES_TABLE)) {
            $query = "CREATE TABLE `" . self::COVERAGE_RULES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `itemtype` varchar(255) NOT NULL DEFAULT '',
                `types_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Sous-type facultatif',
                `documentcategories_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_controls_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_ismsrequirements_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `controls_id` (`plugin_grcmanager_controls_id`),
                KEY `ismsrequirements_id` (`plugin_grcmanager_ismsrequirements_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #170 : dérogations de sécurité (exception temporaire à une politique ou à une mesure).
        if (!$DB->tableExists(self::WAIVERS_TABLE)) {
            $query = "CREATE TABLE `" . self::WAIVERS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `status` varchar(16) NOT NULL DEFAULT 'requested'
                    COMMENT 'requested, approved, rejected, closed',
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable qui accepte le risque',
                `users_id_approver` int {$keySign} NOT NULL DEFAULT 0,
                `date_approval` timestamp NULL DEFAULT NULL,
                `date_begin` date DEFAULT NULL,
                `date_end` date DEFAULT NULL,
                `plugin_grcmanager_controls_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_policies_id` int {$keySign} NOT NULL DEFAULT 0,
                `description` text COMMENT 'Justification',
                `compensating_measures` text,
                `date_notified` timestamp NULL DEFAULT NULL,
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Ticket de réévaluation',
                `is_deleted` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `date_end` (`date_end`),
                KEY `controls_id` (`plugin_grcmanager_controls_id`),
                KEY `policies_id` (`plugin_grcmanager_policies_id`),
                KEY `is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::WAIVERS_ITEMS_TABLE)) {
            $query = "CREATE TABLE `" . self::WAIVERS_ITEMS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_waivers_id` int {$keySign} NOT NULL,
                `itemtype` varchar(255) NOT NULL,
                `items_id` int {$keySign} NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link` (`plugin_grcmanager_waivers_id`, `itemtype`, `items_id`),
                KEY `item` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #178 : risque créé pour chaque risque type du catalogue (un import par clé).
        if (!$DB->tableExists(self::RISK_CATALOG_IMPORTS_TABLE)) {
            $query = "CREATE TABLE `" . self::RISK_CATALOG_IMPORTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `catalog_key` varchar(64) NOT NULL,
                `plugin_grcmanager_risks_id` int {$keySign} NOT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_key` (`catalog_key`),
                KEY `risks_id` (`plugin_grcmanager_risks_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #160 : logiciels du parc écartés de la veille (sans enjeu de sécurité).
        if (!$DB->tableExists(self::IGNORED_SOFTWARES_TABLE)) {
            $query = "CREATE TABLE `" . self::IGNORED_SOFTWARES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #160 : dernière interrogation de NVD pour une version installée d'un produit.
        if (!$DB->tableExists(self::PARK_VERSION_CHECKS_TABLE)) {
            $query = "CREATE TABLE `" . self::PARK_VERSION_CHECKS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_canonicalproducts_id` int {$keySign} NOT NULL,
                `version` varchar(100) NOT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'ok' COMMENT 'ok ou error',
                `cve_count` int NOT NULL DEFAULT 0,
                `checked_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_version` (`plugin_grcmanager_canonicalproducts_id`, `version`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #160 : CVE touchant une version installée (réponse de NVD à la dernière vérification).
        if (!$DB->tableExists(self::PARK_CVES_TABLE)) {
            $query = "CREATE TABLE `" . self::PARK_CVES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_canonicalproducts_id` int {$keySign} NOT NULL,
                `version` varchar(100) NOT NULL,
                `cve_id` varchar(20) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_cve` (`plugin_grcmanager_canonicalproducts_id`, `version`, `cve_id`),
                KEY `cve_id` (`cve_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #160, phase 2 : un ticket par produit, et l'état connu au dernier suivi.
        if (!$DB->tableExists(self::PARK_CVE_TICKETS_TABLE)) {
            $query = "CREATE TABLE `" . self::PARK_CVE_TICKETS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_canonicalproducts_id` int {$keySign} NOT NULL,
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0,
                `machines` int NOT NULL DEFAULT 0,
                `is_kev` tinyint NOT NULL DEFAULT 0,
                `resolved_notified` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_canonicalproducts_id`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #177, phase 2 : objectifs de reprise (heures, 0 : non défini) sur la classification.
        if (!$DB->fieldExists(self::ASSET_CLASSIFICATIONS_TABLE, 'rto_hours')) {
            $migration->addField(self::ASSET_CLASSIFICATIONS_TABLE, 'rto_hours', 'integer', ['value' => 0]);
            $migration->addField(self::ASSET_CLASSIFICATIONS_TABLE, 'rpo_hours', 'integer', ['value' => 0]);
            $migration->migrationOneTable(self::ASSET_CLASSIFICATIONS_TABLE);
        }

        // Issue #166 : score de maturité, un enregistrement par mois.
        if (!$DB->tableExists(self::MATURITY_SNAPSHOTS_TABLE)) {
            $query = "CREATE TABLE `" . self::MATURITY_SNAPSHOTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `month` char(7) NOT NULL,
                `score` int NOT NULL DEFAULT 0,
                `level` int NOT NULL DEFAULT 1,
                `details` text,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `month` (`month`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #171 : effet domino, un lien par couple cause → conséquence.
        if (!$DB->tableExists(self::CAUSAL_LINKS_TABLE)) {
            $query = "CREATE TABLE `" . self::CAUSAL_LINKS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `itemtype_cause` varchar(100) NOT NULL,
                `items_id_cause` int {$keySign} NOT NULL DEFAULT 0,
                `itemtype_effect` varchar(100) NOT NULL,
                `items_id_effect` int {$keySign} NOT NULL DEFAULT 0,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`itemtype_cause`, `items_id_cause`, `itemtype_effect`, `items_id_effect`),
                KEY `effect` (`itemtype_effect`, `items_id_effect`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #167 : avis et alertes du CERT-FR, et avis déjà signalés par risque fournisseur.
        if (!$DB->tableExists(self::CERTFR_NOTICES_TABLE)) {
            $query = "CREATE TABLE `" . self::CERTFR_NOTICES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `ref` varchar(32) NOT NULL,
                `kind` varchar(10) NOT NULL DEFAULT 'avis',
                `title` varchar(255) NOT NULL DEFAULT '',
                `link` varchar(255) NOT NULL DEFAULT '',
                `summary` text,
                `published` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ref` (`ref`),
                KEY `published` (`published`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }
        if (!$DB->tableExists(self::CERTFR_NOTIFIED_TABLE)) {
            $query = "CREATE TABLE `" . self::CERTFR_NOTIFIED_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_certfrnotices_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_supplierrisks_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_certfrnotices_id`, `plugin_grcmanager_supplierrisks_id`),
                KEY `plugin_grcmanager_supplierrisks_id` (`plugin_grcmanager_supplierrisks_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #160 : signaux d'exploitation par CVE (CISA KEV, FIRST EPSS).
        if (!$DB->tableExists(self::CVE_SIGNALS_TABLE)) {
            $query = "CREATE TABLE `" . self::CVE_SIGNALS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `cve_id` varchar(20) NOT NULL,
                `is_kev` tinyint NOT NULL DEFAULT 0,
                `kev_date_added` date DEFAULT NULL,
                `kev_due_date` date DEFAULT NULL,
                `kev_ransomware` tinyint NOT NULL DEFAULT 0,
                `epss` decimal(6,5) DEFAULT NULL,
                `epss_percentile` decimal(6,5) DEFAULT NULL,
                `epss_date` date DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_cve` (`cve_id`),
                KEY `is_kev` (`is_kev`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::PERIODIC_REVIEWS_TABLE)) {
            $query = "CREATE TABLE `" . self::PERIODIC_REVIEWS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `review_key` varchar(64) NOT NULL,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_active` tinyint NOT NULL DEFAULT 0,
                `frequency_months` int NOT NULL DEFAULT 12,
                `users_id_assign` int {$keySign} NOT NULL DEFAULT 0,
                `groups_id_assign` int {$keySign} NOT NULL DEFAULT 0,
                `tickettemplates_id` int {$keySign} NOT NULL DEFAULT 0,
                `ticketrecurrents_id` int {$keySign} NOT NULL DEFAULT 0,
                `last_tickets_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Dernier ticket créé par la revue',
                `last_review_date` date DEFAULT NULL COMMENT 'Résolution du dernier ticket',
                `date_activation` date DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `review_key` (`review_key`),
                KEY `tickettemplates_id` (`tickettemplates_id`),
                KEY `last_tickets_id` (`last_tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }
        $existingReviews = [];
        foreach ($DB->request(['SELECT' => ['review_key'], 'FROM' => self::PERIODIC_REVIEWS_TABLE]) as $row) {
            $existingReviews[] = $row['review_key'];
        }
        foreach (PeriodicReviewCatalog::keys() as $reviewKey) {
            if (!in_array($reviewKey, $existingReviews, true)) {
                $DB->insert(self::PERIODIC_REVIEWS_TABLE, [
                    'review_key'       => $reviewKey,
                    'frequency_months' => PeriodicReviewCatalog::defaultMonths($reviewKey),
                    'date_creation'    => date('Y-m-d H:i:s'),
                ]);
            }
        }

        if (!$DB->tableExists(self::OBJECTIVE_MEASUREMENTS_TABLE)) {
            $query = "CREATE TABLE `" . self::OBJECTIVE_MEASUREMENTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_objectives_id` int {$keySign} NOT NULL,
                `measurement_date` date DEFAULT NULL,
                `value` decimal(10,2) DEFAULT NULL
                    COMMENT 'Valeur mesuree, NULL si objectif qualitatif sans indicateur chiffre',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `objectives_id` (`plugin_grcmanager_objectives_id`),
                KEY `measurement_date` (`measurement_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Many-to-many : objectif(s) ISMS abordés lors d'une revue de direction (issue #32, lien
        // léger demandé par l'issue elle-même : "le comité de direction voit une trajectoire...
        // lors des revues de direction, qui référencent déjà ces objectifs"), même convention
        // "lien simple en accès direct $DB" que CONTROLS_RISKS_TABLE/MANAGEMENT_REVIEWS_USERS_TABLE
        // ci-dessus (voir PluginGrcmanagerManagementReview::getLinkedObjectives()/
        // syncLinkedObjectives()).
        if (!$DB->tableExists(self::MANAGEMENT_REVIEWS_OBJECTIVES_TABLE)) {
            $query = "CREATE TABLE `" . self::MANAGEMENT_REVIEWS_OBJECTIVES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_managementreviews_id` int {$keySign} NOT NULL,
                `plugin_grcmanager_objectives_id` int {$keySign} NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity_link`
                    (`plugin_grcmanager_managementreviews_id`, `plugin_grcmanager_objectives_id`),
                KEY `reviews_id` (`plugin_grcmanager_managementreviews_id`),
                KEY `objectives_id` (`plugin_grcmanager_objectives_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Issue #29 (registre des incidents de sécurité de l'information, ISO/IEC 27001:2022
        // Annexe A A.5.24-27). Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") :
        // ce qui était un simple registre CommonDBTM devient l'objet ITIL complet porté depuis ce
        // plugin jumeau (acteurs, workflow, tâches, notifications, CVE), avec les champs de
        // classification ISO ci-dessus FUSIONNÉS dessus (`category`/`severity`/`cia_impact`/
        // `root_cause`/`lessons_learned`/`plugin_grcmanager_risks_id`) plutôt que gardés sur un
        // second enregistrement séparé — un seul incident, jamais deux saisies à faire concorder.
        // `linked_itemtype`/`linked_items_id` (référence légère vers un Ticket/Problem) disparaît :
        // le lien polymorphe hérité de CommonITILObject (SECURITY_INCIDENTS_ITEMS_TABLE ci-dessous,
        // qui accepte n'importe quel itemtype) couvre déjà ce besoin, pas la peine de deux
        // mécanismes de liaison qui se chevauchent.
        //
        // Le nom de table ne change pas, mais son schéma si : le contenu existant (sous l'ancien
        // schéma CommonDBTM) est d'abord renommé vers SECURITY_INCIDENTS_LEGACY_TABLE avant de
        // recréer la table sous son nom canonique avec le nouveau schéma — voir la migration de
        // données après la création des tables satellites ci-dessous. `incident_date` n'existe que
        // dans l'ancien schéma, donc sa présence sert de marqueur fiable pour détecter qu'une
        // migration est nécessaire (idempotent : une fois migré et la table legacy supprimée, ce
        // marqueur n'existe plus jamais).
        $hasLegacySecurityIncidents = $DB->tableExists(self::SECURITY_INCIDENTS_TABLE)
            && $DB->fieldExists(self::SECURITY_INCIDENTS_TABLE, 'incident_date');

        if ($hasLegacySecurityIncidents) {
            $migration->renameTable(self::SECURITY_INCIDENTS_TABLE, self::SECURITY_INCIDENTS_LEGACY_TABLE);
        }

        // Colonnes calquées sur `glpi_changes` (le plus proche analogue natif), lues directement
        // depuis un vrai schéma GLPI 11 par le plugin absorbé plutôt que devinées — voir son propre
        // Installer.php d'origine.
        if (!$DB->tableExists(self::SECURITY_INCIDENTS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `is_deleted` tinyint NOT NULL DEFAULT 0,
                `status` int NOT NULL DEFAULT 1,
                `content` longtext,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date` timestamp NULL DEFAULT NULL,
                `solvedate` timestamp NULL DEFAULT NULL,
                `closedate` timestamp NULL DEFAULT NULL,
                `time_to_resolve` timestamp NULL DEFAULT NULL,
                `users_id_recipient` int {$keySign} NOT NULL DEFAULT 0,
                `users_id_lastupdater` int {$keySign} NOT NULL DEFAULT 0,
                `urgency` int NOT NULL DEFAULT 1,
                `impact` int NOT NULL DEFAULT 1,
                `priority` int NOT NULL DEFAULT 1,
                `itilcategories_id` int {$keySign} NOT NULL DEFAULT 0,
                `impact_content` longtext,
                `control_list_content` longtext,
                `rollback_plan_content` longtext,
                `actiontime` int NOT NULL DEFAULT 0,
                `begin_waiting_date` timestamp NULL DEFAULT NULL,
                `waiting_duration` int NOT NULL DEFAULT 0,
                `close_delay_stat` int NOT NULL DEFAULT 0,
                `solve_delay_stat` int NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `locations_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_secincidenttemplates_id` int {$keySign} NOT NULL DEFAULT 0,
                `category` varchar(32) NOT NULL DEFAULT 'other'
                    COMMENT 'data_breach, malware, unauthorized_access, availability, other',
                `severity` varchar(16) NOT NULL DEFAULT 'minor' COMMENT 'minor, major, critical',
                `cia_impact` varchar(64) NOT NULL DEFAULT ''
                    COMMENT 'Axes confidentiality/integrity/availability separes par des virgules, vide = non evalue',
                `root_cause` text,
                `lessons_learned` text,
                `plugin_grcmanager_risks_id` int {$keySign} NOT NULL DEFAULT 0
                    COMMENT 'Lien optionnel zero-ou-un vers un risque, 0 = aucun',
                `nis2_applicable` tinyint NOT NULL DEFAULT 0,
                `personal_data_breach` tinyint NOT NULL DEFAULT 0,
                `nis2_early_warning_at` timestamp NULL DEFAULT NULL,
                `nis2_notification_at` timestamp NULL DEFAULT NULL,
                `nis2_final_report_at` timestamp NULL DEFAULT NULL,
                `gdpr_notification_at` timestamp NULL DEFAULT NULL,
                `regulatory_alerts` text COMMENT 'Alertes deja envoyees, echeance:statut (issue #128)',
                `is_exercise` tinyint NOT NULL DEFAULT 0 COMMENT 'Incident fictif d exercice de crise (issue #168)',
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `is_deleted` (`is_deleted`),
                KEY `status` (`status`),
                KEY `itilcategories_id` (`itilcategories_id`),
                KEY `date` (`date`),
                KEY `plugin_grcmanager_secincidenttemplates_id` (`plugin_grcmanager_secincidenttemplates_id`),
                KEY `category` (`category`),
                KEY `severity` (`severity`),
                KEY `plugin_grcmanager_risks_id` (`plugin_grcmanager_risks_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENTS_USERS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENTS_USERS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `type` int NOT NULL DEFAULT 1,
                `use_notification` tinyint NOT NULL DEFAULT 0,
                `alternative_email` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `users_id` (`users_id`),
                KEY `type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENTS_GROUPS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENTS_GROUPS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `groups_id` int {$keySign} NOT NULL DEFAULT 0,
                `type` int NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `groups_id` (`groups_id`),
                KEY `type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENTS_SUPPLIERS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENTS_SUPPLIERS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `suppliers_id` int {$keySign} NOT NULL DEFAULT 0,
                `type` int NOT NULL DEFAULT 1,
                `use_notification` tinyint NOT NULL DEFAULT 0,
                `alternative_email` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `suppliers_id` (`suppliers_id`),
                KEY `type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENTS_ITEMS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENTS_ITEMS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `itemtype` varchar(100) DEFAULT NULL,
                `items_id` int {$keySign} NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `item` (`itemtype`,`items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENT_TASKS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENT_TASKS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `uuid` varchar(255) DEFAULT NULL,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `taskcategories_id` int {$keySign} NOT NULL DEFAULT 0,
                `state` int NOT NULL DEFAULT 0,
                `date` timestamp NULL DEFAULT NULL,
                `begin` timestamp NULL DEFAULT NULL,
                `end` timestamp NULL DEFAULT NULL,
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `users_id_editor` int {$keySign} NOT NULL DEFAULT 0,
                `users_id_tech` int {$keySign} NOT NULL DEFAULT 0,
                `groups_id_tech` int {$keySign} NOT NULL DEFAULT 0,
                `content` longtext,
                `actiontime` int NOT NULL DEFAULT 0,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `tasktemplates_id` int {$keySign} NOT NULL DEFAULT 0,
                `timeline_position` tinyint NOT NULL DEFAULT 0,
                `is_private` tinyint NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `users_id` (`users_id`),
                KEY `users_id_tech` (`users_id_tech`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENT_CVES_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENT_CVES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `cve_id` varchar(20) NOT NULL DEFAULT '',
                `epss_alerted` int NOT NULL DEFAULT 0,
                `kev_alerted` tinyint NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_securityincidents_id`,`cve_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }
        // Issue #172 : EPSS (en %) et passage KEV déjà signalés sur l'incident, pour ne pas répéter.
        if (!$DB->fieldExists(self::SECURITY_INCIDENT_CVES_TABLE, 'epss_alerted')) {
            $migration->addField(self::SECURITY_INCIDENT_CVES_TABLE, 'epss_alerted', 'integer', [
                'after' => 'cve_id',
            ]);
            $migration->addField(self::SECURITY_INCIDENT_CVES_TABLE, 'kev_alerted', 'bool', [
                'after' => 'epss_alerted',
            ]);
            $migration->migrationOneTable(self::SECURITY_INCIDENT_CVES_TABLE);
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENT_COSTS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENT_COSTS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `name` varchar(255) DEFAULT NULL,
                `comment` text,
                `begin_date` date DEFAULT NULL,
                `end_date` date DEFAULT NULL,
                `actiontime` int NOT NULL DEFAULT 0,
                `cost_time` decimal(20,4) NOT NULL DEFAULT 0.0000,
                `cost_fixed` decimal(20,4) NOT NULL DEFAULT 0.0000,
                `cost_material` decimal(20,4) NOT NULL DEFAULT 0.0000,
                `budgets_id` int {$keySign} NOT NULL DEFAULT 0,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }
        // GLPI 12 : CommonITILCost lit aussi date_creation, date_mod, users_id et
        // users_id_lastupdater (comme glpi_ticketcosts) ; sans elles, l'onglet « Coûts » d'un
        // incident de sécurité échouait (HTTP 500). Ajoutées aussi sur GLPI 11, sans effet.
        $costFields = [
            'date_creation'        => ['timestamp', ['null' => true]],
            'date_mod'             => ['timestamp', ['null' => true]],
            'users_id'             => ['fkey', []],
            'users_id_lastupdater' => ['fkey', []],
        ];
        foreach ($costFields as $field => [$type, $options]) {
            if (!$DB->fieldExists(self::SECURITY_INCIDENT_COSTS_TABLE, $field)) {
                $migration->addField(self::SECURITY_INCIDENT_COSTS_TABLE, $field, $type, $options);
            }
        }

        if (!$DB->tableExists(self::SECURITY_INCIDENT_TEMPLATES_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENT_TEMPLATES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `comment` text,
                `allowed_statuses` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        foreach (
            [
            self::SECURITY_INCIDENT_TEMPLATES_PREDEFINED_FIELDS_TABLE => true,
            self::SECURITY_INCIDENT_TEMPLATES_HIDDEN_FIELDS_TABLE => false,
            self::SECURITY_INCIDENT_TEMPLATES_MANDATORY_FIELDS_TABLE => false,
            self::SECURITY_INCIDENT_TEMPLATES_READONLY_FIELDS_TABLE => false,
            ] as $table => $hasValueColumn
        ) {
            if ($DB->tableExists($table)) {
                continue;
            }

            $valueColumn = $hasValueColumn ? "`value` longtext,\n                " : '';
            $query = "CREATE TABLE `{$table}` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `securityincidenttemplates_id` int {$keySign} NOT NULL DEFAULT 0,
                `num` int NOT NULL DEFAULT 0,
                {$valueColumn}PRIMARY KEY (`id`),
                KEY `securityincidenttemplates_id` (`securityincidenttemplates_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Colonnes requises par convention GLPI core (dérivées de strtolower(static::class) pour
        // un objet ITIL avec gestion de modèles) : CommonITILObject::getITILTemplateToUse() /
        // Entity::getUsedConfig() vont chercher ces colonnes par ce nom exact — confirmé sur le
        // plugin absorbé avant fusion, pas une supposition. Même forme que les colonnes natives
        // changetemplates_strategy/_id.
        if (!$DB->fieldExists('glpi_entities', 'plugingrcmanagersecurityincidenttemplates_strategy')) {
            $migration->addField(
                'glpi_entities',
                'plugingrcmanagersecurityincidenttemplates_strategy',
                'integer',
                ['value' => -2, 'after' => 'entities_id']
            );
        }
        if (!$DB->fieldExists('glpi_entities', 'plugingrcmanagersecurityincidenttemplates_id')) {
            $migration->addField(
                'glpi_entities',
                'plugingrcmanagersecurityincidenttemplates_id',
                'integer',
                ['value' => 0, 'after' => 'plugingrcmanagersecurityincidenttemplates_strategy']
            );
        }
        if (!$DB->fieldExists('glpi_itilcategories', 'plugingrcmanagersecurityincidenttemplates_id')) {
            $migration->addField(
                'glpi_itilcategories',
                'plugingrcmanagersecurityincidenttemplates_id',
                'integer',
                ['value' => 0, 'after' => 'problemtemplates_id']
            );
            $migration->addKey('glpi_itilcategories', 'plugingrcmanagersecurityincidenttemplates_id');
        }
        if (!$DB->fieldExists('glpi_profiles', 'plugingrcmanagersecurityincidenttemplates_id')) {
            $migration->addField(
                'glpi_profiles',
                'plugingrcmanagersecurityincidenttemplates_id',
                'integer',
                ['value' => 0, 'after' => 'problemtemplates_id']
            );
            $migration->addKey('glpi_profiles', 'plugingrcmanagersecurityincidenttemplates_id');
        }

        // Issue #128 : délais de notification réglementaires (NIS2, RGPD) et qualification d'un
        // ticket en incident de sécurité. Sans 'after' : la mise à jour depuis une version
        // antérieure doit fonctionner quel que soit l'ordre des colonnes existantes.
        foreach (['nis2_applicable', 'personal_data_breach'] as $flag) {
            if (!$DB->fieldExists(self::SECURITY_INCIDENTS_TABLE, $flag)) {
                $migration->addField(self::SECURITY_INCIDENTS_TABLE, $flag, 'bool', ['value' => 0]);
            }
        }
        // Issue #127 : questionnaire de sécurité fournisseur et ticket de réévaluation.
        $questionnaireFields = [
            'questionnaire_answers'  => ['text', []],
            'questionnaire_score'    => ['integer', ['null' => true, 'value' => null]],
            'questionnaire_date'     => ['timestamp', ['null' => true]],
            'questionnaire_users_id' => ['fkey', []],
            'review_tickets_id'      => ['fkey', []],
        ];
        foreach ($questionnaireFields as $field => [$type, $options]) {
            if (!$DB->fieldExists(self::SUPPLIER_RISKS_TABLE, $field)) {
                $migration->addField(self::SUPPLIER_RISKS_TABLE, $field, $type, $options);
            }
        }

        $doneFields = ['nis2_early_warning_at', 'nis2_notification_at', 'nis2_final_report_at', 'gdpr_notification_at'];
        foreach ($doneFields as $doneField) {
            if (!$DB->fieldExists(self::SECURITY_INCIDENTS_TABLE, $doneField)) {
                $migration->addField(self::SECURITY_INCIDENTS_TABLE, $doneField, 'timestamp', ['null' => true]);
            }
        }
        if (!$DB->fieldExists(self::SECURITY_INCIDENTS_TABLE, 'regulatory_alerts')) {
            $migration->addField(self::SECURITY_INCIDENTS_TABLE, 'regulatory_alerts', 'text');
        }
        if (!$DB->fieldExists(self::SECURITY_INCIDENTS_TABLE, 'is_exercise')) {
            $migration->addField(self::SECURITY_INCIDENTS_TABLE, 'is_exercise', 'bool', ['value' => 0]);
        }
        // Issue #168 : un exercice = un incident fictif, ses étapes = des tâches de l'incident.
        if (!$DB->tableExists(self::CRISIS_EXERCISES_TABLE)) {
            $query = "CREATE TABLE `" . self::CRISIS_EXERCISES_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$keySign} NOT NULL DEFAULT 0,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `scenario` varchar(50) NOT NULL DEFAULT '',
                `users_id` int {$keySign} NOT NULL DEFAULT 0,
                `started_at` timestamp NULL DEFAULT NULL,
                `ended_at` timestamp NULL DEFAULT NULL,
                `documents_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_grcmanager_securityincidents_id` (`plugin_grcmanager_securityincidents_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }
        if (!$DB->tableExists(self::CRISIS_EXERCISE_STEPS_TABLE)) {
            $query = "CREATE TABLE `" . self::CRISIS_EXERCISE_STEPS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_crisisexercises_id` int {$keySign} NOT NULL DEFAULT 0,
                `step` varchar(50) NOT NULL DEFAULT '',
                `rank` int NOT NULL DEFAULT 0,
                `target_minutes` int NOT NULL DEFAULT 0,
                `plugin_grcmanager_securityincidenttasks_id` int {$keySign} NOT NULL DEFAULT 0,
                `done_at` timestamp NULL DEFAULT NULL,
                `users_id_done` int {$keySign} NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_grcmanager_crisisexercises_id` (`plugin_grcmanager_crisisexercises_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }
        if (!$DB->tableExists(self::SECURITY_INCIDENTS_TICKETS_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENTS_TICKETS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_securityincidents_id` int {$keySign} NOT NULL DEFAULT 0,
                `tickets_id` int {$keySign} NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_grcmanager_securityincidents_id`, `tickets_id`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        $migration->executeMigration();

        // Migration légère->fusionnée (voir le commentaire au-dessus de la création de
        // SECURITY_INCIDENTS_TABLE) : ne s'exécute qu'une seule fois, tant que la table renommée
        // existe encore - une fois migrée et supprimée ci-dessous, ce bloc devient un no-op
        // permanent (idempotent par construction, pas besoin d'un indicateur de version séparé).
        if ($hasLegacySecurityIncidents && $DB->tableExists(self::SECURITY_INCIDENTS_LEGACY_TABLE)) {
            $this->migrateLegacySecurityIncidents();
        }

        // Issue #31 (plan d'action de traitement des risques, clause 8.3/6.1.3) : PLUSIEURS
        // actions concrètes par risque (contrairement au CAPA d'une non-conformité, qui n'a
        // qu'une action corrective ET une action préventive fixes, voir NONCONFORMITIES_TABLE
        // ci-dessus) - véritable table enfant one-to-many, comme OBJECTIVE_MEASUREMENTS_TABLE
        // ci-dessus, pas un simple lien polymorphe comme RISKS_ITEMS_TABLE (chaque action porte
        // ses propres données : description, responsable, échéance, statut, date de réalisation).
        // `plugin_grcmanager_risks_id` n'est pas une vraie clé étrangère GLPI (pas de ON DELETE
        // CASCADE natif ici), même simplification assumée que
        // `PluginGrcmanagerObjectiveMeasurement.plugin_grcmanager_objectives_id` ci-dessus, mais
        // voir PluginGrcmanagerRisk::post_purgeItem() pour le nettoyage explicite ajouté malgré
        // tout (coût marginal nul, ce hook existe déjà pour RISKS_ITEMS_TABLE).
        if (!$DB->tableExists(self::RISK_TREATMENT_ACTIONS_TABLE)) {
            $query = "CREATE TABLE `" . self::RISK_TREATMENT_ACTIONS_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `plugin_grcmanager_risks_id` int {$keySign} NOT NULL,
                `description` text,
                `users_id` int {$keySign} NOT NULL DEFAULT 0 COMMENT 'Responsable de l''action',
                `due_date` date DEFAULT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'planned'
                    COMMENT 'planned, in_progress, done',
                `completion_date` date DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `risks_id` (`plugin_grcmanager_risks_id`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `due_date` (`due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());
        }

        // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : interrupteurs
        // module/CVE/modèles/tableau de bord, une seule ligne singleton (id=1) seedée avec tous
        // les indicateurs activés — même convention que RISK_MATRIX_CONFIG_TABLE ci-dessus (voir
        // SecurityIncidentModuleConfig). Tous activés par défaut : sur une install existante, ce
        // toggle ne fait que permettre de DÉSACTIVER une fonctionnalité, jamais l'inverse.
        if (!$DB->tableExists(self::SECURITY_INCIDENT_CONFIG_TABLE)) {
            $query = "CREATE TABLE `" . self::SECURITY_INCIDENT_CONFIG_TABLE . "` (
                `id` int {$keySign} NOT NULL AUTO_INCREMENT,
                `securityincident_enabled` tinyint NOT NULL DEFAULT 1,
                `securityincident_cve_enabled` tinyint NOT NULL DEFAULT 1,
                `securityincident_templates_enabled` tinyint NOT NULL DEFAULT 1,
                `securityincident_dashboard_enabled` tinyint NOT NULL DEFAULT 1,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

            $DB->doQuery($query) or die($DB->error());

            $DB->insert(self::SECURITY_INCIDENT_CONFIG_TABLE, array_merge(
                array_map(static fn (bool $v): int => $v ? 1 : 0, SecurityIncidentModuleConfig::DEFAULTS),
                ['date_mod' => date('Y-m-d H:i:s')]
            ));
        }

        $this->seedControls();
        $this->seedClauses();
        $this->seedSoaApprovalNotification();

        $this->seedSecurityIncidentNotifications();

        $this->seedReviewReminderNotification(
            'PluginGrcmanagerRisk',
            'risk',
            'Revue de risque à échéance',
            'risque'
        );
        $this->seedReviewReminderNotification(
            'PluginGrcmanagerSupplierRisk',
            'supplierrisk',
            'Revue de risque fournisseur à échéance',
            'risque fournisseur'
        );
        $this->seedReviewReminderNotification(
            'PluginGrcmanagerComplianceObligation',
            'complianceobligation',
            'Revue d\'obligation à échéance',
            'obligation',
            ['type' => 'Type', 'status' => 'Statut de conformité']
        );
        $this->seedCapaOverdueNotification();
        $this->seedSecurityWatchNotification();
        $this->seedTrainingRenewalNotification();
        $this->seedPolicyReviewReminderNotification();
        $this->seedTreatmentActionOverdueNotification();
        $this->seedAccessReviewNotifications();
        $this->seedEvidenceExpiringNotification();
        $this->seedWaiverExpiringNotification();

        // Issue #126 : relance hebdomadaire des responsables en retard sur une revue des accès.
        CronTask::Register(
            'PluginGrcmanagerAccessReview',
            'accessreviewreminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Relance les responsables qui n\'ont pas terminé une revue des accès échue',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Sprint 2 (rappels de date de revue) : évalue chaque jour les risques dont la date de
        // revue est dépassée ou approche, et déclenche la notification GLPI seedée ci-dessus (voir
        // PluginGrcmanagerRisk::cronReviewreminder(), src/Services/Risk/ReviewReminderService.php).
        CronTask::Register(
            'PluginGrcmanagerRisk',
            'reviewreminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Notifie le propriétaire de chaque risque dont la date de revue est '
                    . 'dépassée ou approche',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Enrichissement CVE via NVD : rattrape les CVE jamais enrichies avec succès (pending/
        // error/not_found - y compris une CVE ajoutée avant que l'admin n'active la
        // fonctionnalité) et rafraîchit celles dont la dernière récupération date de plus de 7
        // jours (une CVE encore "en cours d'analyse" chez NVD peut recevoir son score plus tard,
        // voir NvdCveEnrichmentService::refreshDue()). No-op silencieux si l'enrichissement est
        // désactivé (voir PluginGrcmanagerSecurityIncidentCve::cronRefreshNvdData()).
        CronTask::Register(
            'PluginGrcmanagerSecurityIncidentCve',
            'refreshnvddata',
            DAY_TIMESTAMP,
            [
                'comment' => 'Récupère/rafraîchit le score CVSS, la sévérité et les liens de '
                    . 'correctif de chaque CVE suivie, depuis le NVD',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Sprint 5 (risques fournisseurs/tiers) : même mécanisme de rappel de revue que le
        // registre générique ci-dessus (voir GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService,
        // partagée par les deux tâches Cron), mais une tâche dédiée : le modèle de tâche Cron de
        // GLPI enregistre/déclenche un point d'entrée statique par itemtype, une seule tâche ne
        // peut donc pas couvrir les deux tables elle-même (voir
        // PluginGrcmanagerSupplierRisk::cronReviewreminder()).
        CronTask::Register(
            'PluginGrcmanagerSupplierRisk',
            'reviewreminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Notifie le propriétaire de chaque risque fournisseur dont la date de '
                    . 'revue est dépassée ou approche',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Issue #30 (obligations légales/réglementaires/contractuelles) : même mécanisme de
        // rappel de revue que les deux registres de risques ci-dessus (ReviewReminderService
        // généralisé par cette issue avec un $excludeCriteria vide, voir
        // PluginGrcmanagerComplianceObligation::cronReviewreminder()).
        CronTask::Register(
            'PluginGrcmanagerComplianceObligation',
            'reviewreminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Notifie le propriétaire de chaque obligation dont la date de revue '
                    . 'est dépassée ou approche',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Sprint 4 (CAPA en retard) : évalue chaque jour les non-conformités dont l'échéance est
        // dépassée et qui ne sont ni clôturées ni vérifiées, et déclenche la notification GLPI
        // seedée ci-dessus (voir PluginGrcmanagerNonconformity::cronOverduecapa(),
        // src/Services/Capa/OverdueCapaService.php).
        CronTask::Register(
            'PluginGrcmanagerNonconformity',
            'overduecapa',
            DAY_TIMESTAMP,
            [
                'comment' => 'Notifie le responsable de chaque action corrective/préventive dont '
                    . 'l\'échéance est dépassée',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Sprint 6 (formations, clauses 7.2/7.3) : évalue chaque jour les formations ayant au
        // moins un participant en retard de renouvellement, et déclenche la notification GLPI
        // seedée ci-dessus (voir PluginGrcmanagerTraining::cronRenewaldue(),
        // src/Services/Training/TrainingRenewalService.php).
        CronTask::Register(
            'PluginGrcmanagerTraining',
            'renewaldue',
            DAY_TIMESTAMP,
            [
                'comment' => 'Notifie chaque participant en retard de renouvellement pour une '
                    . 'formation',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Issue #28 (bibliothèque de politiques de sécurité versionnées, A.5.1) : évalue chaque
        // jour les politiques dont la prochaine date de revue est dépassée ou approche, et
        // déclenche la notification GLPI seedée ci-dessus (voir
        // PluginGrcmanagerPolicy::cronReviewreminder(),
        // src/Services/Policy/PolicyReviewReminderService.php).
        CronTask::Register(
            'PluginGrcmanagerPolicy',
            'reviewreminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Notifie le propriétaire de chaque politique de sécurité dont la '
                    . 'prochaine date de revue est dépassée ou approche',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Issue #128 : délais de notification réglementaires (NIS2, RGPD) des incidents de
        // sécurité — suivi sur l'incident quand une échéance approche ou est dépassée (voir
        // PluginGrcmanagerSecurityIncident::cronRegulatorydeadlines()).
        CronTask::Register(
            'PluginGrcmanagerSecurityIncident',
            'regulatorydeadlines',
            HOUR_TIMESTAMP,
            [
                'comment' => 'Signale par un suivi les délais de notification NIS2 / RGPD proches ou dépassés',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Issue #31 (plan d'action de traitement des risques, clause 8.3/6.1.3) : évalue chaque
        // jour les actions de traitement dont l'échéance est dépassée et qui ne sont pas encore
        // réalisées, et déclenche la notification GLPI seedée ci-dessus (voir
        // PluginGrcmanagerRiskTreatmentAction::cronOverduetreatmentaction(),
        // src/Services/Risk/OverdueTreatmentActionService.php).
        CronTask::Register(
            'PluginGrcmanagerRiskTreatmentAction',
            'overduetreatmentaction',
            DAY_TIMESTAMP,
            [
                'comment' => 'Notifie le responsable de chaque action de traitement de risque dont '
                    . 'l\'échéance est dépassée',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Issue #166 : score de maturité du mois, enregistré chaque jour pour l'historique.
        CronTask::Register(
            'PluginGrcmanagerMaturity',
            'maturitysnapshot',
            DAY_TIMESTAMP,
            [
                'comment' => 'Enregistre le score de maturité du mois pour l\'historique de la revue de direction',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Issue #170 : rappel avant la fin d'une dérogation, puis ticket de réévaluation.
        CronTask::Register(
            'PluginGrcmanagerWaiver',
            'waiverreminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Prévient le responsable avant la fin d\'une dérogation, puis ouvre un '
                    . 'ticket de réévaluation',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // Issue #160 : vulnérabilités du parc. Toutes les heures, quelques versions installées
        // vérifiées auprès de NVD (limite publique de 5 requêtes par 30 s), puis CISA KEV et
        // FIRST EPSS. Sans effet tant que l'enrichissement NVD n'est pas activé.
        CronTask::Register(
            'PluginGrcmanagerParkVulnerability',
            'parkvulnerabilitysync',
            HOUR_TIMESTAMP,
            [
                'comment' => 'Vérifie les versions installées des logiciels surveillés auprès de NVD, '
                    . 'CISA KEV et FIRST EPSS',
                'mode'    => CronTask::MODE_EXTERNAL,
                'param'   => 20,
            ]
        );

        // Issue #145 : rappel avant l'expiration d'une preuve, puis ticket de renouvellement
        // (PluginGrcmanagerEvidence::cronEvidencereminder()).
        CronTask::Register(
            'PluginGrcmanagerEvidence',
            'evidencereminder',
            DAY_TIMESTAMP,
            [
                'comment' => 'Prévient le responsable avant l\'expiration d\'une preuve, puis ouvre '
                    . 'un ticket de renouvellement',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );

        // ProfileRight::addProfileRights() is NOT idempotent, it does a raw INSERT with no
        // existence check, so it throws a duplicate-key error on any second call, guarded here
        // the same way the sibling plugin glpi-vulnerability-manager guards its own equivalent
        // call (see its Installer.php).
        if ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => self::RIGHT_NAME]])->count() === 0) {
            ProfileRight::addProfileRights([self::RIGHT_NAME]);
        }

        // addProfileRights() above inserts every profile at rights=0 (GLPI core's own default),
        // an admin is expected to grant it explicitly per profile via Administration > Profils,
        // except Super-Admin which always gets full rights so the plugin is usable right after
        // install without a second manual step, same lesson learned live on the sibling plugins
        // of this same author (glpi-vulnerability-manager, assetsign-glpi, Configuration-glpi-auto).
        foreach ($DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Super-Admin']]) as $profileRow) {
            ProfileRight::updateProfileRights((int) $profileRow['id'], [self::RIGHT_NAME => ALLSTANDARDRIGHT]);
        }

        // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : contrairement au reste
        // de ce plugin (un seul droit plat ci-dessus), l'objet ITIL fusionné garde ses propres
        // droits dédiés - un vrai objet ITIL a besoin d'une vraie granularité de visibilité (un
        // technicien voit ses incidents assignés sans forcément tout voir) que RIGHT_NAME seul ne
        // permet pas. `rule_grcmanager_securityincident` est requis par convention core (voir
        // RulePluginGrcmanagerSecurityIncidentCollection) même si aucune règle n'est encore
        // administrée par défaut.
        foreach ([self::SECURITY_INCIDENT_RIGHT, self::SECURITY_INCIDENT_RULE_RIGHT] as $right) {
            if ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $right]])->count() === 0) {
                ProfileRight::addProfileRights([$right]);
            }
        }

        // ALLSTANDARDRIGHT seul (READ/UPDATE/CREATE/DELETE/PURGE = 31) ne suffit pas pour un objet
        // ITIL : PluginGrcmanagerSecurityIncident::getRights() ajoute READALL (bit distinct de
        // READ/READMY qu'il remplace) en plus du jeu standard - même écart déjà rencontré sur le
        // plugin absorbé avant fusion (Super-Admin recevait un vrai 403 sur un incident dont il
        // n'était pas acteur).
        foreach ($DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Super-Admin']]) as $profileRow) {
            ProfileRight::updateProfileRights((int) $profileRow['id'], [
                self::SECURITY_INCIDENT_RIGHT => ALLSTANDARDRIGHT | PluginGrcmanagerSecurityIncident::READALL,
                self::SECURITY_INCIDENT_RULE_RIGHT => ALLSTANDARDRIGHT,
            ]);
        }

        $this->seedDisplayPreferences();

        // Sprint 7 (tableaux de bord, consolidation) : tableau de bord natif GLPI prêt à l'emploi,
        // voir DefaultDashboardService pour le détail (idempotent comme le reste de cette
        // méthode : upsert sur une clé fixe, jamais dupliqué au réinstall).
        DefaultDashboardService::seed();

        $migration->executeMigration();

        return true;
    }

    /**
     * Seeds the Notification/NotificationTemplate/translation/target rows a review-date reminder
     * Cron task (PluginGrcmanagerRisk::cronReviewreminder(), and since Sprint 5
     * PluginGrcmanagerSupplierRisk::cronReviewreminder(), and since issue #30
     * PluginGrcmanagerComplianceObligation::cronReviewreminder()) needs to actually send something
     * via NotificationEvent::raiseEvent('review_due', ...), see inc/notificationtargetrisk.class.php /
     * inc/notificationtargetsupplierrisk.class.php / inc/notificationtargetcomplianceobligation.class.php
     * for the NotificationTarget classes and their tag lists. Idempotent per itemtype: skipped
     * entirely if a Notification for that itemtype/event already exists (an admin may have edited
     * the template's wording since, never overwritten here). Generalized at Sprint 5 (was
     * PluginGrcmanagerRisk-only before) so every review-reminder itemtype is seeded from the exact
     * same implementation, only their tag prefix/wording differ.
     *
     * @param string $itemtype    'PluginGrcmanagerRisk', 'PluginGrcmanagerSupplierRisk' or
     *                            'PluginGrcmanagerComplianceObligation'.
     * @param string $tagPrefix   Matches the NotificationTarget's own tag prefix
     *                            ('risk'/'supplierrisk'/'complianceobligation').
     * @param string $name        Human-readable Notification/NotificationTemplate name suffix.
     * @param string $noun        French noun used in the seeded comment
     *                            ('risque'/'risque fournisseur'/'obligation').
     * @param array<string, string> $detailLines Tag suffix => French label for the two body lines
     *                            between the title and the review date. Defaults to
     *                            category/risklevel (PluginGrcmanagerRisk/PluginGrcmanagerSupplierRisk's
     *                            own tags, unchanged since Sprint 2/5) so existing callers see no
     *                            behaviour change; issue #30 passes type/status instead, matching
     *                            PluginGrcmanagerComplianceObligation's own fields (no
     *                            category/risklevel on that itemtype).
     */
    private function seedReviewReminderNotification(
        string $itemtype,
        string $tagPrefix,
        string $name,
        string $noun,
        array $detailLines = ['category' => 'Catégorie', 'risklevel' => 'Niveau de risque']
    ): void {
        global $DB;

        $event = 'review_due';

        $alreadySeeded = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        ])->count() > 0;

        if ($alreadySeeded) {
            return;
        }

        $template = new NotificationTemplate();
        $templateId = $template->add([
            'name'     => 'GRC Manager - ' . $name,
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche automatique GRC Manager lorsqu\'un '
                . $noun . ' atteint ou dépasse sa date de revue.',
        ]);

        $detailText = '';
        $detailHtml = '';
        foreach ($detailLines as $tagSuffix => $label) {
            $detailText .= "{$label} : ##{$tagPrefix}.{$tagSuffix}##\n";
            $detailHtml .= "{$label} : ##{$tagPrefix}.{$tagSuffix}##<br>";
        }

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => "##{$tagPrefix}.action## : ##{$tagPrefix}.title##",
            'content_text'             => "##{$tagPrefix}.action## : ##{$tagPrefix}.title##\n\n"
                . $detailText
                . "Date de revue : ##{$tagPrefix}.reviewdate##\n\n"
                . "Voir le " . $noun . " : ##{$tagPrefix}.url##",
            'content_html'             => "<p><strong>##{$tagPrefix}.action## : ##{$tagPrefix}.title##</strong></p>"
                . '<p>' . $detailHtml
                . 'Date de revue : ' . "##{$tagPrefix}.reviewdate##</p>"
                . "<p><a href=\"##{$tagPrefix}.url##\">Voir le " . $noun . '</a></p>',
        ]);

        $notification = new Notification();
        $notificationId = $notification->add([
            'name'         => 'GRC Manager - ' . $name,
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);

        // Default recipient: the risk's own owner (Notification::ITEM_USER, resolved generically
        // by GLPI core from the `users_id` field, see NotificationTarget::addItemOwner() and
        // inc/notificationtargetrisk.class.php / inc/notificationtargetsupplierrisk.class.php's
        // own addAdditionalTargets()). Without this row, NotificationEvent::raiseEvent() finds the
        // Notification above but no configured recipient and silently sends nothing.
        $DB->insert('glpi_notificationtargets', [
            'items_id'         => Notification::ITEM_USER,
            'type'             => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);
    }

    /**
     * Same structure as seedReviewReminderNotification() above, for the Sprint 4 overdue-CAPA Cron
     * task (PluginGrcmanagerNonconformity::cronOverduecapa(), event 'capa_overdue', see
     * inc/notificationtargetnonconformity.class.php). Idempotent for the same reason.
     */
    private function seedCapaOverdueNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerNonconformity';
        $event    = 'capa_overdue';

        $alreadySeeded = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        ])->count() > 0;

        if ($alreadySeeded) {
            return;
        }

        $template = new NotificationTemplate();
        $templateId = $template->add([
            'name'     => 'GRC Manager - Action corrective/préventive en retard',
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche automatique GRC Manager lorsqu\'une '
                . 'non-conformité dépasse son échéance sans être clôturée ni vérifiée.',
        ]);

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => '##nc.action## : ##nc.title##',
            'content_text'             => "##nc.action## : ##nc.title##\n\n"
                . "Audit : ##nc.audit##\n"
                . "Sévérité : ##nc.severity##\n"
                . "Échéance : ##nc.duedate##\n\n"
                . "Voir la non-conformité : ##nc.url##",
            'content_html'             => '<p><strong>##nc.action## : ##nc.title##</strong></p>'
                . '<p>Audit : ##nc.audit##<br>'
                . 'Sévérité : ##nc.severity##<br>'
                . 'Échéance : ##nc.duedate##</p>'
                . '<p><a href="##nc.url##">Voir la non-conformité</a></p>',
        ]);

        $notification = new Notification();
        $notificationId = $notification->add([
            'name'         => 'GRC Manager - Action corrective/préventive en retard',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);

        // Default recipient: the non-conformity's own responsible owner (`users_id`), same
        // generic resolution as seedReviewReminderNotification() above.
        $DB->insert('glpi_notificationtargets', [
            'items_id'         => Notification::ITEM_USER,
            'type'             => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);
    }

    /**
     * Issue #167 : nouvel avis du CERT-FR qui cite le fournisseur d'un risque (événement
     * 'security_watch', voir PluginGrcmanagerSecurityWatch::notifyOwners()), adressé au
     * propriétaire du risque. Idempotent, même structure que seedCapaOverdueNotification().
     */
    private function seedSecurityWatchNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerSupplierRisk';
        $event    = 'security_watch';

        if (
            $DB->request([
                'FROM'  => 'glpi_notifications',
                'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
            ])->count() > 0
        ) {
            return;
        }

        $templateId = (new NotificationTemplate())->add([
            'name'     => 'GRC Manager - Avis de sécurité concernant un fournisseur',
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche des vulnérabilités du parc lorsqu\'un nouvel '
                . 'avis du CERT-FR cite le fournisseur d\'un risque du registre.',
        ]);

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => '##supplierrisk.action## : ##supplierrisk.supplier##',
            // Texte seul : la liste des avis est déjà une liste de lignes, et un modèle HTML écrit
            // ici ajouterait du HTML en PHP (exigence Marketplace, voir check-marketplace-readiness).
            'content_text'             => "##supplierrisk.action## : ##supplierrisk.supplier##\n\n"
                . "##supplierrisk.notices##\n\n"
                . "Risque fournisseur : ##supplierrisk.title##\n"
                . "Voir le risque fournisseur (onglet Veille sécurité) : ##supplierrisk.url##",
        ]);

        $notificationId = (new Notification())->add([
            'name'         => 'GRC Manager - Avis de sécurité concernant un fournisseur',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);

        $DB->insert('glpi_notificationtargets', [
            'items_id'         => Notification::ITEM_USER,
            'type'             => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);
    }

    /**
     * Same structure as seedCapaOverdueNotification() above, for the Sprint 6 training-renewal Cron
     * task (PluginGrcmanagerTraining::cronRenewaldue(), event 'training_renewal_due', see
     * inc/notificationtargettraining.class.php). Idempotent for the same reason.
     *
     * Deliberately does NOT insert a `glpi_notificationtargets` row (unlike every notification
     * seeded above): a training has no single "item owner" field to resolve a default recipient
     * from, its recipients are the (possibly several) overdue participants,
     * PluginGrcmanagerNotificationTargetTraining::addAdditionalTargets() resolves and adds every
     * one of them itself, unconditionally, regardless of any configured target row (see its own
     * docblock).
     */
    /**
     * Issue #145 : rappel au responsable du renouvellement d'une preuve qui arrive à échéance
     * (PluginGrcmanagerEvidence::runReminders(), inc/notificationtargetevidence.class.php), avec sa
     * cible « Responsable du renouvellement ». Idempotent, comme les autres notifications seedées.
     */
    private function seedEvidenceExpiringNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerEvidence';
        $event    = 'evidence_expiring';

        $existing = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        ]);
        if ($existing->count() > 0) {
            return;
        }

        $templateId = (new NotificationTemplate())->add([
            'name'     => 'GRC Manager - Preuve à renouveler',
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche automatique GRC Manager lorsqu\'un document '
                . 'de preuve arrive à la fin de sa validité.',
        ]);

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => '##evidence.action## : ##evidence.document##',
            'content_text'             => "##evidence.action## : ##evidence.document##\n\n"
                . "Élément : ##evidence.item##\n"
                . "Valide jusqu'au : ##evidence.validuntil##\n\n"
                . "Renouvelez le document (nouveau certificat, rapport ou attestation) puis mettez à jour sa "
                . "date de validité. Un ticket de renouvellement sera ouvert si la preuve expire.\n\n"
                . "Voir l'élément : ##evidence.url##",
            'content_html'             => '<p><strong>##evidence.action## : ##evidence.document##</strong></p>'
                . '<p>Élément : ##evidence.item##<br>Valide jusqu\'au : ##evidence.validuntil##</p>'
                . '<p>Renouvelez le document (nouveau certificat, rapport ou attestation) puis mettez à jour '
                . 'sa date de validité. Un ticket de renouvellement sera ouvert si la preuve expire.</p>'
                . '<p><a href="##evidence.url##">Voir l\'élément</a></p>',
        ]);

        $notificationId = (new Notification())->add([
            'name'         => 'GRC Manager - Preuve à renouveler',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);
        $DB->insert('glpi_notificationtargets', [
            'notifications_id' => $notificationId,
            'type'             => Notification::USER_TYPE,
            'items_id'         => \PluginGrcmanagerNotificationTargetEvidence::TARGET_RESPONSIBLE,
        ]);
    }

    /**
     * Issue #170 : avis au responsable d'une dérogation 15 jours avant sa fin. Le contenu HTML est
     * dérivé du texte (aucun gabarit HTML écrit en PHP, cf. exigences du Marketplace GLPI).
     */
    private function seedWaiverExpiringNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerWaiver';
        $event    = 'waiver_expiring';
        $where = ['itemtype' => $itemtype, 'event' => $event];
        if ($DB->request(['FROM' => 'glpi_notifications', 'WHERE' => $where])->count() > 0) {
            return;
        }

        $templateId = (new NotificationTemplate())->add([
            'name'     => 'GRC Manager - Dérogation bientôt échue',
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche automatique GRC Manager 15 jours avant la fin '
                . 'd\'une dérogation de sécurité.',
        ]);
        $text = "##waiver.action## : ##waiver.name##\n\n"
            . "Date de fin : ##waiver.dateend##\n\n"
            . "Réévaluez la dérogation : prolongez-la avec une nouvelle date de fin si l'exception est toujours "
            . "nécessaire, ou close-la une fois la situation régularisée. Un ticket de réévaluation sera ouvert "
            . "à l'échéance.\n\n"
            . "Voir la dérogation : ##waiver.url##";
        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => '##waiver.action## : ##waiver.name##',
            'content_text'             => $text,
            'content_html'             => nl2br(htmlescape($text)),
        ]);

        $notificationId = (new Notification())->add([
            'name'         => 'GRC Manager - Dérogation bientôt échue',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);
        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);
        $DB->insert('glpi_notificationtargets', [
            'notifications_id' => $notificationId,
            'type'             => Notification::USER_TYPE,
            'items_id'         => \PluginGrcmanagerNotificationTargetWaiver::TARGET_RESPONSIBLE,
        ]);
    }

    private function seedTrainingRenewalNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerTraining';
        $event    = 'training_renewal_due';

        $alreadySeeded = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        ])->count() > 0;

        if ($alreadySeeded) {
            return;
        }

        $template = new NotificationTemplate();
        $templateId = $template->add([
            'name'     => 'GRC Manager - Renouvellement de formation en retard',
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche automatique GRC Manager lorsqu\'un '
                . 'participant est en retard de renouvellement pour une formation.',
        ]);

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => '##training.action## : ##training.title##',
            'content_text'             => "##training.action## : ##training.title##\n\n"
                . "Format : ##training.format##\n"
                . "Renouvellement (mois) : ##training.renewalmonths##\n\n"
                . "Voir la formation : ##training.url##",
            'content_html'             => '<p><strong>##training.action## : ##training.title##</strong></p>'
                . '<p>Format : ' . "##training.format##<br>"
                . 'Renouvellement (mois) : ' . "##training.renewalmonths##</p>"
                . '<p><a href="##training.url##">Voir la formation</a></p>',
        ]);

        $notification = new Notification();
        $notificationId = $notification->add([
            'name'         => 'GRC Manager - Renouvellement de formation en retard',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);
    }

    /**
     * Same structure as seedReviewReminderNotification() above, for the issue #28 policy
     * review-reminder Cron task (PluginGrcmanagerPolicy::cronReviewreminder(), event
     * 'policy_review_due', see inc/notificationtargetpolicy.class.php). Idempotent for the same
     * reason. Kept as its own dedicated method rather than a third call to
     * seedReviewReminderNotification(): that method's event is hardcoded to 'review_due' and its
     * tags to `##<prefix>.risklevel##`/`##<prefix>.reviewdate##`-shaped risk wording, which doesn't
     * fit this itemtype's own event name and tag set (title/version/status/reviewdate), same
     * reasoning as PolicyReviewReminderService not simply reusing ReviewReminderService (see its
     * own docblock).
     */
    private function seedPolicyReviewReminderNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerPolicy';
        $event    = 'policy_review_due';

        $alreadySeeded = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        ])->count() > 0;

        if ($alreadySeeded) {
            return;
        }

        $template = new NotificationTemplate();
        $templateId = $template->add([
            'name'     => 'GRC Manager - Revue de politique de sécurité à échéance',
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche automatique GRC Manager lorsqu\'une '
                . 'politique de sécurité atteint ou dépasse sa prochaine date de revue.',
        ]);

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => '##policy.action## : ##policy.title##',
            'content_text'             => "##policy.action## : ##policy.title##\n\n"
                . "Version : ##policy.version##\n"
                . "Statut : ##policy.status##\n"
                . "Prochaine revue : ##policy.reviewdate##\n\n"
                . "Voir la politique : ##policy.url##",
            'content_html'             => '<p><strong>##policy.action## : ##policy.title##</strong></p>'
                . '<p>Version : ' . "##policy.version##<br>"
                . 'Statut : ' . "##policy.status##<br>"
                . 'Prochaine revue : ' . "##policy.reviewdate##</p>"
                . '<p><a href="##policy.url##">Voir la politique</a></p>',
        ]);

        $notification = new Notification();
        $notificationId = $notification->add([
            'name'         => 'GRC Manager - Revue de politique de sécurité à échéance',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);

        // Default recipient: the policy's own owner (`users_id`), same generic resolution as
        // seedReviewReminderNotification() above.
        $DB->insert('glpi_notificationtargets', [
            'items_id'         => Notification::ITEM_USER,
            'type'             => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);
    }

    /**
     * Same structure as seedCapaOverdueNotification() above, for the issue #31 overdue treatment
     * action Cron task (PluginGrcmanagerRiskTreatmentAction::cronOverduetreatmentaction(), event
     * 'treatment_action_overdue', see inc/notificationtargetrisktreatmentaction.class.php).
     * Idempotent for the same reason.
     */
    private function seedTreatmentActionOverdueNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerRiskTreatmentAction';
        $event    = 'treatment_action_overdue';

        $alreadySeeded = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        ])->count() > 0;

        if ($alreadySeeded) {
            return;
        }

        $template = new NotificationTemplate();
        $templateId = $template->add([
            'name'     => 'GRC Manager - Action de traitement de risque en retard',
            'itemtype' => $itemtype,
            'comment'  => 'Notification envoyée par la tâche automatique GRC Manager lorsqu\'une '
                . 'action du plan de traitement d\'un risque dépasse son échéance sans être réalisée.',
        ]);

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
            'subject'                  => '##treatmentaction.action## : ##treatmentaction.risktitle##',
            'content_text'             => "##treatmentaction.action## : ##treatmentaction.risktitle##\n\n"
                . "Action : ##treatmentaction.description##\n"
                . "Échéance : ##treatmentaction.duedate##\n\n"
                . "Voir le risque : ##treatmentaction.url##",
            'content_html'             => '<p><strong>##treatmentaction.action## : '
                . '##treatmentaction.risktitle##</strong></p>'
                . '<p>Action : ##treatmentaction.description##<br>'
                . 'Échéance : ##treatmentaction.duedate##</p>'
                . '<p><a href="##treatmentaction.url##">Voir le risque</a></p>',
        ]);

        $notification = new Notification();
        $notificationId = $notification->add([
            'name'         => 'GRC Manager - Action de traitement de risque en retard',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);

        // Default recipient: the treatment action's own responsible owner (`users_id`), same
        // generic resolution as seedCapaOverdueNotification() above.
        $DB->insert('glpi_notificationtargets', [
            'items_id'         => Notification::ITEM_USER,
            'type'             => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);
    }

    /**
     * Without this, `NotificationEvent::raiseEvent('new'|'update'|'solved'|'closed', $this)`
     * (called from `PluginGrcmanagerSecurityIncident::post_addItem()`/`post_updateItem()`) finds no
     * active `Notification` row to fire and silently does nothing. One shared `NotificationTemplate`
     * for all four events (same pattern as GLPI core's own native `Change` notifications) and four
     * `Notification` rows. Target `items_id`/`type` values (1, 3, 21, and 1/2/3/4/21 for "update")
     * are copied verbatim from a real GLPI 11 install's own `glpi_notificationtargets` rows for
     * `Change` — ported unchanged from the absorbed plugin's own Installer.php.
     */
    private function seedSecurityIncidentNotifications(): void
    {
        $itemtype = PluginGrcmanagerSecurityIncident::class;
        $tag      = strtolower($itemtype);

        $template = new NotificationTemplate();
        if (!$template->getFromDBByCrit(['itemtype' => $itemtype, 'name' => 'Security incident'])) {
            $templateId = $template->add([
                'name'    => 'Security incident',
                'itemtype' => $itemtype,
                'comment' => 'Seeded at install by the absorbed glpi-security-incidents module.',
            ]);

            (new \NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $templateId,
                'language'                 => '',
                'subject'                  => "##{$tag}.action## ##{$tag}.title##",
                'content_text'             => "##{$tag}.action## ##{$tag}.title##\n\n"
                    . "##{$tag}.url##\n\n"
                    . "##{$tag}.content##",
                'content_html'             => "<p>##{$tag}.action## ##{$tag}.title##</p>"
                    . "<p><a href=\"##{$tag}.url##\">##{$tag}.url##</a></p>"
                    . "<p>##{$tag}.content##</p>",
            ]);
        } else {
            $templateId = (int) $template->getID();
        }

        $eventsAndTargets = [
            'new'    => [1, 3, 21],
            'update' => [1, 2, 3, 4, 21],
            'solved' => [1, 3, 21],
            'closed' => [1, 3, 21],
        ];

        foreach ($eventsAndTargets as $event => $targetItemsIds) {
            $notification = new Notification();
            if ($notification->getFromDBByCrit(['itemtype' => $itemtype, 'event' => $event])) {
                continue;
            }

            $notificationId = $notification->add([
                'name'         => 'Security incident — ' . $event,
                'entities_id'  => 0,
                'is_recursive' => 1,
                'is_active'    => 1,
                'itemtype'     => $itemtype,
                'event'        => $event,
            ]);

            (new \Notification_NotificationTemplate())->add([
                'notifications_id'         => $notificationId,
                'notificationtemplates_id' => $templateId,
                'mode'                     => 'mailing',
            ]);

            foreach ($targetItemsIds as $targetItemsId) {
                (new \NotificationTarget())->add([
                    'notifications_id' => $notificationId,
                    'items_id'         => $targetItemsId,
                    'type'             => Notification::USER_TYPE,
                ]);
            }
        }
    }

    /**
     * Migrates every row of the former lightweight CommonDBTM register (renamed to
     * SECURITY_INCIDENTS_LEGACY_TABLE just before the new ITIL-object table was created — see the
     * caller) into the new merged schema, then drops the now-empty legacy table. Runs at most once
     * per instance: the legacy table only exists on an upgrade from a pre-absorption version, and
     * is gone for good after this method returns.
     *
     * The actual field mapping (including the status-vocabulary conversion) lives in
     * `LegacySecurityIncidentMigrator::mapRow()` — a pure, GLPI-independent, unit-tested class —
     * so the trickiest part of this migration is verified without needing a live DB. `users_id`
     * (the old register's single "responsable" field) becomes an ASSIGN actor on the new object;
     * `linked_itemtype`/`linked_items_id` (if set) becomes a row in SECURITY_INCIDENTS_ITEMS_TABLE,
     * the polymorphic link mechanism that supersedes it.
     */
    private function migrateLegacySecurityIncidents(): void
    {
        global $DB;

        foreach ($DB->request(['FROM' => self::SECURITY_INCIDENTS_LEGACY_TABLE]) as $row) {
            $fields = LegacySecurityIncidentMigrator::mapRow($row);
            $fields['date_creation'] ??= date('Y-m-d H:i:s');

            $newId = $DB->insert(self::SECURITY_INCIDENTS_TABLE, $fields) ? $DB->insertId() : 0;

            if ($newId === 0) {
                continue;
            }

            if (LegacySecurityIncidentMigrator::hasResponsibleUser($row)) {
                $DB->insert(self::SECURITY_INCIDENTS_USERS_TABLE, [
                    'plugin_grcmanager_securityincidents_id' => $newId,
                    'users_id'                                => (int) $row['users_id'],
                    'type'                                     => \CommonITILActor::ASSIGN,
                ]);
            }

            if (LegacySecurityIncidentMigrator::hasLinkedItem($row)) {
                $DB->insert(self::SECURITY_INCIDENTS_ITEMS_TABLE, [
                    'plugin_grcmanager_securityincidents_id' => $newId,
                    'itemtype'                                 => $row['linked_itemtype'],
                    'items_id'                                 => (int) $row['linked_items_id'],
                ]);
            }
        }

        $DB->doQuery('DROP TABLE IF EXISTS `' . self::SECURITY_INCIDENTS_LEGACY_TABLE . '`');
    }

    /**
     * Idempotent like seedSource() on the sibling plugin glpi-vulnerability-manager (same author,
     * same guard shape): each of the 93 controls is looked up by its unique `code` before
     * inserting, so re-running install() (upgrade path, `plugin:install --force`) never duplicates
     * a row nor overwrites an admin's own applicability/justification/status edits on an existing
     * one.
     */
    private function seedControls(): void
    {
        global $DB;

        foreach (ControlCatalogDefaults::CONTROLS as $code => $theme) {
            $exists = $DB->request([
                'FROM'  => self::CONTROLS_TABLE,
                'WHERE' => ['code' => $code],
            ])->count() > 0;

            if ($exists) {
                continue;
            }

            $DB->insert(self::CONTROLS_TABLE, [
                'code'                  => $code,
                'theme'                 => $theme,
                'applicability'         => 'yes',
                'implementation_status' => 'not_started',
                'is_reviewed'           => 0,
                'date_creation'         => date('Y-m-d H:i:s'),
                'date_mod'              => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Issue #113 : même garde idempotente que seedControls() (recherche par `code` avant
     * insertion), une mise à jour du plugin n'écrase jamais le statut/responsable/commentaire saisis.
     */
    private function seedClauses(): void
    {
        global $DB;

        foreach (IsmsClauseCatalog::SUBCLAUSES as $code => $subclause) {
            $exists = $DB->request([
                'FROM'  => self::ISMS_REQUIREMENTS_TABLE,
                'WHERE' => ['code' => (string) $code],
            ])->count() > 0;

            if ($exists) {
                continue;
            }

            $DB->insert(self::ISMS_REQUIREMENTS_TABLE, [
                'code'          => (string) $code,
                'clause'        => $subclause['clause'],
                'status'        => IsmsClauseCatalog::STATUS_NOT_STARTED,
                'date_creation' => date('Y-m-d H:i:s'),
                'date_mod'      => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Issue #112 : prévient les approbateurs désignés qu'une version de la SoA attend leur
     * approbation (cible « Approbateurs désignés », voir PluginGrcmanagerNotificationTargetSoaVersion).
     */
    private function seedSoaApprovalNotification(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerSoaVersion';
        $event    = 'soa_approval_request';

        $alreadySeeded = $DB->request([
            'FROM'  => 'glpi_notifications',
            'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        ])->count() > 0;

        if ($alreadySeeded) {
            return;
        }

        $template = new NotificationTemplate();
        $templateId = $template->add([
            'name'     => 'GRC Manager - Approbation de la SoA demandée',
            'itemtype' => $itemtype,
            'comment'  => 'Envoyée aux approbateurs désignés quand une version de la Déclaration '
                . 'd\'Applicabilité est figée et soumise à approbation.',
        ]);

        // Modèle FR par défaut + EN pour les destinataires en anglais (sinon libellés traduits et
        // texte français se mélangent dans le même e-mail).
        $translations = [
            ''      => [
                'subject' => '##soa.action## : version ##soa.version##',
                'ask'     => '##soa.requester## vous demande d\'approuver la version ##soa.version## de la '
                    . 'Déclaration d\'Applicabilité (##soa.date##).',
                'link'    => 'Lire la version figée et répondre',
                'sep'     => ' : ',
            ],
            'en_GB' => [
                'subject' => '##soa.action##: version ##soa.version##',
                'ask'     => '##soa.requester## asks you to approve version ##soa.version## of the Statement '
                    . 'of Applicability (##soa.date##).',
                'link'    => 'Read the frozen version and answer',
                'sep'     => ': ',
            ],
        ];
        foreach ($translations as $language => $t) {
            $DB->insert('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
                'language'                 => $language,
                'subject'                  => $t['subject'],
                'content_text'             => $t['ask'] . "\n\n##soa.comment##\n\n"
                    . $t['link'] . $t['sep'] . '##soa.url##',
                'content_html'             => '<p>' . $t['ask'] . '</p><p>##soa.comment##</p>'
                    . '<p><a href="##soa.url##">' . $t['link'] . '</a></p>',
            ]);
        }

        $notification = new Notification();
        $notificationId = $notification->add([
            'name'         => 'GRC Manager - Approbation de la SoA demandée',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => $notificationId,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templateId,
        ]);

        $DB->insert('glpi_notificationtargets', [
            'items_id'         => \PluginGrcmanagerNotificationTargetSoaVersion::APPROVERS_TARGET,
            'type'             => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);
    }

    /**
     * Issue #126 : notifications de la revue des accès (lancement, puis relances des responsables
     * en retard), cible « Responsables ayant des comptes à revoir ».
     */
    private function seedAccessReviewNotifications(): void
    {
        global $DB;

        $itemtype = 'PluginGrcmanagerAccessReview';
        $events = [
            'access_review_request'  => [
                'name' => 'GRC Manager - Revue des accès à réaliser',
                ''     => [
                    'Revue des accès à réaliser : ##accessreview.name##',
                    '##accessreview.owner## vous demande de revoir les accès des comptes de votre équipe '
                        . 'avant le ##accessreview.duedate## (campagne « ##accessreview.name## »).',
                    'Faire ma revue des accès',
                ],
                'en_GB' => [
                    'Access review to complete: ##accessreview.name##',
                    '##accessreview.owner## asks you to review the access rights of your team\'s accounts '
                        . 'before ##accessreview.duedate## (campaign "##accessreview.name##").',
                    'Complete my access review',
                ],
            ],
            'access_review_reminder' => [
                'name' => 'GRC Manager - Relance de la revue des accès',
                ''     => [
                    'Relance : revue des accès ##accessreview.name##',
                    'L\'échéance du ##accessreview.duedate## est dépassée et des comptes de votre équipe '
                        . 'attendent encore votre décision (campagne « ##accessreview.name## »).',
                    'Terminer ma revue des accès',
                ],
                'en_GB' => [
                    'Reminder: access review ##accessreview.name##',
                    'The ##accessreview.duedate## deadline has passed and accounts of your team are still '
                        . 'waiting for your decision (campaign "##accessreview.name##").',
                    'Finish my access review',
                ],
            ],
        ];

        foreach ($events as $event => $definition) {
            $alreadySeeded = $DB->request([
                'FROM'  => 'glpi_notifications',
                'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
            ])->count() > 0;
            if ($alreadySeeded) {
                continue;
            }

            $templateId = (new NotificationTemplate())->add([
                'name'     => $definition['name'],
                'itemtype' => $itemtype,
            ]);
            foreach (['', 'en_GB'] as $language) {
                [$subject, $text, $link] = $definition[$language];
                $DB->insert('glpi_notificationtemplatetranslations', [
                    'notificationtemplates_id' => $templateId,
                    'language'                 => $language,
                    'subject'                  => $subject,
                    'content_text'             => $text . "\n\n" . $link . ' : ##accessreview.url##',
                    'content_html'             => '<p>' . $text . '</p><p><a href="##accessreview.url##">'
                        . $link . '</a></p>',
                ]);
            }

            $notificationId = (new Notification())->add([
                'name'         => $definition['name'],
                'entities_id'  => 0,
                'is_recursive' => 1,
                'itemtype'     => $itemtype,
                'event'        => $event,
                'is_active'    => 1,
            ]);
            $DB->insert('glpi_notifications_notificationtemplates', [
                'notifications_id'         => $notificationId,
                'mode'                     => 'mailing',
                'notificationtemplates_id' => $templateId,
            ]);
            $DB->insert('glpi_notificationtargets', [
                'items_id'         => \PluginGrcmanagerNotificationTargetAccessReview::REVIEWERS_TARGET,
                'type'             => Notification::USER_TYPE,
                'notifications_id' => $notificationId,
            ]);
        }
    }

    private function seedDisplayPreferences(): void
    {
        global $DB;

        foreach (DefaultSearchColumns::COLUMNS as $itemtype => $columns) {
            $alreadySeeded = $DB->request([
                'FROM' => 'glpi_displaypreferences',
                'WHERE' => ['itemtype' => $itemtype, 'users_id' => 0],
            ])->count() > 0;

            if ($alreadySeeded) {
                continue;
            }

            foreach (array_values($columns) as $index => $searchOptionId) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num' => $searchOptionId,
                    'rank' => $index + 1,
                    'users_id' => 0,
                    'interface' => 'central',
                ]);
            }
        }
    }

    public function uninstall(Migration $migration): bool
    {
        global $DB;

        ProfileRight::deleteProfileRights([self::RIGHT_NAME]);
        ProfileRight::deleteProfileRights([self::SECURITY_INCIDENT_RIGHT, self::SECURITY_INCIDENT_RULE_RIGHT]);

        $DB->delete('glpi_displaypreferences', ['itemtype' => array_keys(DefaultSearchColumns::COLUMNS)]);

        // Sprint 2 (rappels de date de revue) : même règle que le plugin jumeau
        // glpi-vulnerability-manager (voir son propre Installer::uninstall()), supprime toutes
        // les tâches Cron enregistrées par ce plugin.
        CronTask::Unregister('grcmanager');

        $this->unseedNotification('PluginGrcmanagerRisk');
        $this->unseedNotification('PluginGrcmanagerSoaVersion');
        $this->unseedNotification('PluginGrcmanagerSupplierRisk');
        $this->unseedNotification('PluginGrcmanagerComplianceObligation');
        $this->unseedNotification('PluginGrcmanagerNonconformity');
        $this->unseedNotification('PluginGrcmanagerTraining');
        $this->unseedNotification('PluginGrcmanagerPolicy');
        $this->unseedNotification('PluginGrcmanagerRiskTreatmentAction');
        $this->unseedNotification('PluginGrcmanagerEvidence');
        $this->unseedNotification('PluginGrcmanagerWaiver');
        $this->unseedNotification(PluginGrcmanagerSecurityIncident::class);
        $this->unseedNotification('PluginGrcmanagerAccessReview');

        // Absorption de glpi-security-incidents (ROADMAP.md "Version 2.0") : colonnes ajoutées sur
        // les tables cœur pour la gestion de modèles - même dérivation que le plugin absorbé
        // (strtolower(nom de classe)), migrées vers ces nouveaux noms lors de la fusion (voir
        // install()).
        $migration->dropField('glpi_entities', 'plugingrcmanagersecurityincidenttemplates_id');
        $migration->dropField('glpi_entities', 'plugingrcmanagersecurityincidenttemplates_strategy');
        $migration->dropField('glpi_itilcategories', 'plugingrcmanagersecurityincidenttemplates_id');
        $migration->dropField('glpi_profiles', 'plugingrcmanagersecurityincidenttemplates_id');

        // Sprint 7 (tableaux de bord) : retire le tableau de bord natif seedé par
        // DefaultDashboardService::seed() ci-dessus, avant que ses tables ne disparaissent.
        DefaultDashboardService::remove();

        // GLPI 11 forbids $DB->query() for direct queries ("Executing direct queries is not
        // allowed!"), same lesson learned live on the sibling plugin glpi-vulnerability-manager,
        // see its TECH_DEBT.md. Migration's own dropTable() is the sanctioned way to drop a table
        // outside doQuery()/QueryBuilder.
        $migration->dropTable(self::RISKS_TABLE);
        $migration->dropTable(self::SUPPLIER_RISKS_TABLE);
        $migration->dropTable(self::RISK_MATRIX_CONFIG_TABLE);
        $migration->dropTable(self::NVD_CONFIG_TABLE);
        $migration->dropTable(self::CVE_ENRICHMENTS_TABLE);
        $migration->dropTable(self::CPE_REFERENCES_TABLE);
        $migration->dropTable(self::PRODUCT_ALIASES_TABLE);
        $migration->dropTable(self::CANONICAL_PRODUCTS_TABLE);
        $migration->dropTable(self::CONTROLS_RISKS_TABLE);
        $migration->dropTable(self::CONTROLS_TABLE);
        $migration->dropTable(self::ISMS_REQUIREMENTS_TABLE);
        $migration->dropTable(self::SOA_VERSIONS_USERS_TABLE);
        $migration->dropTable(self::SOA_VERSIONS_TABLE);
        $migration->dropTable(self::AUDITS_CONTROLS_TABLE);
        $migration->dropTable(self::NONCONFORMITIES_TABLE);
        $migration->dropTable(self::AUDITS_TABLE);
        $migration->dropTable(self::TRAININGS_USERS_TABLE);
        $migration->dropTable(self::TRAININGS_TABLE);
        $migration->dropTable(self::MANAGEMENT_REVIEWS_USERS_TABLE);
        $migration->dropTable(self::MANAGEMENT_REVIEWS_TABLE);
        $migration->dropTable(self::RISKS_ITEMS_TABLE);
        $migration->dropTable(self::ASSET_CLASSIFICATIONS_TABLE);
        $migration->dropTable(self::POLICIES_TABLE);
        $migration->dropTable(self::COMPLIANCE_OBLIGATIONS_TABLE);
        $migration->dropTable(self::MANAGEMENT_REVIEWS_OBJECTIVES_TABLE);
        $migration->dropTable(self::OBJECTIVE_MEASUREMENTS_TABLE);
        $migration->dropTable(self::EVIDENCES_TABLE);
        $migration->dropTable(self::PERIODIC_REVIEWS_TABLE);
        $migration->dropTable(self::NETWORK_FLOWS_TABLE);
        $migration->dropTable(self::COMPLIANCE_EVENTS_TABLE);
        $migration->dropTable(self::IGNORED_SOFTWARES_TABLE);
        $migration->dropTable(self::PARK_VERSION_CHECKS_TABLE);
        $migration->dropTable(self::PARK_CVES_TABLE);
        $migration->dropTable(self::CVE_SIGNALS_TABLE);
        $migration->dropTable(self::PARK_CVE_TICKETS_TABLE);
        $migration->dropTable(self::CERTFR_NOTICES_TABLE);
        $migration->dropTable(self::CAUSAL_LINKS_TABLE);
        $migration->dropTable(self::MATURITY_SNAPSHOTS_TABLE);
        $migration->dropTable(self::CERTFR_NOTIFIED_TABLE);
        $migration->dropTable(self::RISK_CATALOG_IMPORTS_TABLE);
        $migration->dropTable(self::WAIVERS_TABLE);
        $migration->dropTable(self::WAIVERS_ITEMS_TABLE);
        $migration->dropTable(self::COVERAGE_RULES_TABLE);
        $migration->dropTable(self::CRISIS_EXERCISE_STEPS_TABLE);
        $migration->dropTable(self::CRISIS_EXERCISES_TABLE);
        $migration->dropTable(self::ACCESS_REVIEWS_USERS_TABLE);
        $migration->dropTable(self::ACCESS_REVIEWS_TABLE);
        $migration->dropTable(self::OBJECTIVES_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_TEMPLATES_PREDEFINED_FIELDS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_TEMPLATES_HIDDEN_FIELDS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_TEMPLATES_MANDATORY_FIELDS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_TEMPLATES_READONLY_FIELDS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_TEMPLATES_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_COSTS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_CVES_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_TASKS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENTS_TICKETS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENTS_ITEMS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENTS_SUPPLIERS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENTS_GROUPS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENTS_USERS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENTS_LEGACY_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENTS_TABLE);
        $migration->dropTable(self::RISK_TREATMENT_ACTIONS_TABLE);
        $migration->dropTable(self::SECURITY_INCIDENT_CONFIG_TABLE);

        $migration->executeMigration();

        return true;
    }

    /**
     * Purges (not soft-deletes) the Notification and NotificationTemplate seeded for a given
     * itemtype by seedReviewReminderNotification()/seedCapaOverdueNotification(): both classes'
     * own cleanDBonPurge() cascades to glpi_notifications_notificationtemplates,
     * glpi_notificationtargets and glpi_notificationtemplatetranslations (confirmed by reading
     * GLPI 11 core, src/Notification.php and src/NotificationTemplate.php), so nothing is
     * orphaned. Generalized from Sprint 2's single-itemtype version to also cover Sprint 4's
     * PluginGrcmanagerNonconformity notification, called once per itemtype above.
     */
    private function unseedNotification(string $itemtype): void
    {
        global $DB;

        $notification = new Notification();
        foreach ($DB->request(['FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $notification->delete(['id' => $row['id']], true);
        }

        $template = new NotificationTemplate();
        foreach ($DB->request(['FROM' => 'glpi_notificationtemplates', 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $template->delete(['id' => $row['id']], true);
        }
    }
}
