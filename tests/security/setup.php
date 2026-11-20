<?php

/**
 * Données et vérifications du test de contrôle d'accès de bout en bout
 * (tests/security/access-matrix.sh), lancé par la CI dans une instance GLPI jetable.
 *
 * Deux entités A et B ; un « gestionnaire » (tous les droits du plugin) et un « lecteur »
 * (lecture seule), tous deux limités à l'entité A. Dans chaque entité : un incident de sécurité,
 * une campagne de revue d'accès, un contrat du même fournisseur, un ordinateur ; un risque (objet
 * global) lié à l'ordinateur de l'entité B.
 *
 * Usage (en www-data, depuis la racine GLPI) :
 *   php plugins/grcmanager/tests/security/setup.php                     crée les données, JSON des ids
 *   php plugins/grcmanager/tests/security/setup.php risk-links <risk>   liens actifs du risque, JSON
 *   php plugins/grcmanager/tests/security/setup.php soa-approvers       approbateurs de la dernière
 *                                                                       version de SoA, JSON
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
Session::start();
if (!(new Auth())->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Connexion glpi/glpi impossible\n");
    exit(1);
}

global $DB;

const SECURITY_TEST_PASSWORD = 'Secrev#2026';

$mode = $argv[1] ?? 'setup';

if ($mode === 'risk-links') {
    $links = [];
    $rows = $DB->request([
        'FROM'  => 'glpi_plugin_grcmanager_risks_items',
        'WHERE' => ['plugin_grcmanager_risks_id' => (int) $argv[2]],
    ]);
    foreach ($rows as $row) {
        $links[] = $row['itemtype'] . ':' . $row['items_id'];
    }
    sort($links);
    echo json_encode($links), "\n";
    exit(0);
}

if ($mode === 'processing-links') {
    // Issue #375 : éléments cités par une fiche du registre des traitements, JSON trié.
    $links = [];
    $rows = $DB->request([
        'FROM'  => 'glpi_plugin_grcmanager_processingactivities_items',
        'WHERE' => ['plugin_grcmanager_processingactivities_id' => (int) $argv[2]],
    ]);
    foreach ($rows as $row) {
        $links[] = $row['itemtype'] . ':' . $row['items_id'];
    }
    sort($links);
    echo json_encode($links), "\n";
    exit(0);
}

if ($mode === 'xss') {
    // Charge piégée dans chaque champ texte libre : un objet de chaque type est créé, et les objets
    // du jeu de données sont piégés aussi. tests/security/xss-check.sh vérifie qu'aucune page ne
    // la restitue telle quelle. Usage : setup.php xss '<json du mode normal>' ; affiche les ids.
    $ids = json_decode((string) ($argv[2] ?? ''), true);
    if (!is_array($ids)) {
        fwrite(STDERR, "Usage : setup.php xss '<json du mode normal>'\n");
        exit(1);
    }
    $canary = "<svg/onload=alert('xss-canary')>";
    $insert = static function (string $table, array $row) use ($DB): int {
        $DB->insert('glpi_plugin_grcmanager_' . $table, $row);
        return (int) $DB->insertId();
    };
    $update = static function (string $table, array $row, int $id) use ($DB): void {
        $DB->update('glpi_plugin_grcmanager_' . $table, $row, ['id' => $id]);
    };
    $out = [];
    // Issue #772 : audit de certification piégé (auditeur de l'organisme, référentiel) et son constat
    // piégé (référence de l'organisme, processus, clause), sur la vue des constats et l'onglet.
    $out['audit'] = $insert('audits', [
        'title' => $canary, 'scope' => $canary, 'conclusion' => $canary,
        'audit_type' => 'surveillance_1', 'lead_auditor' => $canary, 'certification_standard' => $canary,
    ]);
    $insert('nonconformities', [
        'title' => $canary, 'plugin_grcmanager_audits_id' => $out['audit'], 'body_reference' => $canary,
        'audited_process' => $canary, 'requirement_reference' => $canary, 'corrective_action' => $canary,
        'root_cause' => $canary,
    ]);
    $out['obligation'] = $insert('complianceobligations', [
        'title' => $canary, 'description' => $canary, 'reference_source' => $canary,
    ]);
    $out['review'] = $insert('managementreviews', ['title' => $canary, 'agenda' => $canary, 'decisions' => $canary]);
    $out['nonconformity'] = $insert('nonconformities', [
        'title' => $canary, 'description' => $canary, 'corrective_action' => $canary,
        'root_cause' => $canary, 'preventive_action' => $canary,
    ]);
    $out['objective'] = $insert('objectives', [
        'title' => $canary, 'description' => $canary, 'target_description' => $canary,
    ]);
    $insert('objectivemeasurements', ['plugin_grcmanager_objectives_id' => $out['objective'], 'comment' => $canary]);
    // Issue #380 : constat d'un test d'efficacité (onglet de la mesure et registre des tests).
    $insert('controltests', [
        'plugin_grcmanager_controls_id' => (int) $ids['control'], 'test_date' => date('Y-m-d'),
        'result' => 'partial', 'comment' => $canary,
    ]);
    // Issue #607 : échantillon piégé (question, critères, nom figé et commentaire d'un élément).
    $out['sample'] = $insert('controltestsamples', [
        'plugin_grcmanager_controls_id' => (int) $ids['control'], 'name' => $canary, 'itemtype' => 'Computer',
        'criteria' => '{}', 'population_count' => 1, 'sample_size' => 1, 'seed' => 'xss', 'users_id' => 2,
        'draw_method' => 'sha256-rank-v1', 'date_draw' => date('Y-m-d H:i:s'),
    ]);
    $insert('controltestsampleitems', [
        'plugin_grcmanager_controltestsamples_id' => $out['sample'], 'rank' => 1, 'itemtype' => 'Computer',
        'items_id' => (int) $ids['computer_A'], 'label' => $canary, 'result' => 'noncompliant', 'comment' => $canary,
    ]);
    $out['policy'] = $insert('policies', [
        'title' => $canary, 'description' => $canary, 'version' => '1.0', 'status' => 'approved',
        'approval_date' => date('Y-m-d'),
    ]);
    // Issue #775 : procédure d'exploitation piégée (titre, domaine) : registre, fiche, liste.
    $out['procedure'] = $insert('policies', [
        'title' => $canary, 'category' => 'operating_procedure', 'procedure_domain' => $canary,
        'version' => '1.0', 'status' => 'draft',
    ]);
    // Issue #535 : campagne de prise de connaissance de la politique piégée, à lire par glpi, et
    // groupe piégé dans son public (onglet de la politique, onglet « Politiques à lire »).
    $DB->insert('glpi_groups', ['name' => $canary, 'entities_id' => 0, 'is_recursive' => 1]);
    $xssGroup = (int) $DB->insertId();
    $insert('policyaudiences', [
        'plugin_grcmanager_policies_id' => $out['policy'], 'itemtype' => 'Group', 'items_id' => $xssGroup,
    ]);
    $out['ack_campaign'] = $insert('policycampaigns', [
        'plugin_grcmanager_policies_id' => $out['policy'], 'version' => '1.0', 'status' => 'open',
        'users_id' => 2, 'date_launch' => date('Y-m-d H:i:s'), 'due_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $insert('policycampaigns_users', [
        'plugin_grcmanager_policycampaigns_id' => $out['ack_campaign'], 'users_id' => 2, 'entities_id' => 0,
    ]);
    // Issue #538 : demande de preuve piégée de l'audit piégé (référence, description, commentaires),
    // confiée à glpi et renvoyée à compléter, avec le document piégé soumis ; accès auditeur de glpi
    // (onglet de l'audit, « Mes demandes de preuves », espace auditeur).
    $now = date('Y-m-d H:i:s');
    $out['evreq'] = $insert('auditevidencerequests', [
        'plugin_grcmanager_audits_id' => $out['audit'], 'plugin_grcmanager_controls_id' => (int) $ids['control'],
        'reference' => $canary, 'description' => $canary, 'users_id' => 2, 'status' => 'to_complete',
        'submission_comment' => $canary, 'review_comment' => $canary, 'date_submitted' => $now,
        'date_last_submission' => $now, 'due_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $insert('auditevidencepieces', [
        'plugin_grcmanager_auditevidencerequests_id' => $out['evreq'], 'documents_id' => (int) $ids['document_A'],
        'origin' => 'existing', 'users_id' => 2, 'is_submitted' => 1, 'date_submitted' => $now,
    ]);
    // Issue #606 : ligne de grille piégée de l'audit piégé (glpi dans l'équipe pour la voir).
    $insert('audits_users', ['plugin_grcmanager_audits_id' => $out['audit'], 'users_id' => 2, 'role' => 'auditor']);
    $insert('auditchecklistitems', [
        'plugin_grcmanager_audits_id' => $out['audit'], 'ref_type' => 'free', 'code' => mb_substr($canary, 0, 50),
        'label' => $canary, 'question' => $canary, 'evidence' => $canary, 'comment' => $canary,
        'result' => 'nc_minor', 'rank' => 1,
    ]);
    $insert('auditchecklisttemplates', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $canary]);
    $insert('auditoraccesses', [
        'plugin_grcmanager_audits_id' => $out['audit'], 'users_id' => 2, 'date_begin' => date('Y-m-d'),
        'date_end' => date('Y-m-d', strtotime('+5 days')), 'users_id_grantor' => 2, 'date_creation' => $now,
    ]);
    // Issue #773 : processus piégé de l'audit piégé, où glpi réalise l'activité (conflit
    // d'indépendance), justification et commentaire de remise du rapport piégés.
    $xssProcess = $insert('responsibilityactivities', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $canary]);
    $insert('responsibilityassignments', [
        'plugin_grcmanager_responsibilityactivities_id' => $xssProcess, 'itemtype' => 'User', 'items_id' => 2,
        'role' => 'responsible',
    ]);
    $insert('audits_processes', [
        'plugin_grcmanager_audits_id' => $out['audit'], 'plugin_grcmanager_responsibilityactivities_id' => $xssProcess,
    ]);
    $insert('auditindependencewaivers', [
        'plugin_grcmanager_audits_id' => $out['audit'], 'users_id' => 2,
        'plugin_grcmanager_responsibilityactivities_id' => $xssProcess, 'justification' => $canary,
        'users_id_author' => 2, 'date_creation' => $now,
    ]);
    $insert('auditreportdeliveries', [
        'plugin_grcmanager_audits_id' => $out['audit'], 'delivery_date' => date('Y-m-d'), 'users_id_recipient' => 2,
        'comment' => $canary, 'users_id' => 2,
    ]);
    $out['training'] = $insert('trainings', [
        'title' => $canary, 'description' => $canary, 'target_audience' => $canary,
    ]);
    // Issue #375 : chaque champ texte d'une fiche du registre des traitements.
    $out['processing'] = $insert('processingactivities', [
        'name' => $canary, 'reference' => $canary, 'controller_name' => $canary, 'purpose' => $canary,
        'legal_basis_detail' => $canary, 'data_details' => $canary, 'recipients' => $canary,
        'transfer_countries' => $canary, 'retention_period' => $canary, 'security_measures' => $canary,
        'comment' => $canary, 'data_subjects' => '[]', 'data_categories' => '[]', 'sensitive_data' => '[]',
        'status' => 'active',
    ]);
    // Issue #771 : chaque champ texte d'un contact officiel (registre, fiche, onglet de l'incident de A).
    $out['official_contact'] = $insert('contextitems', [
        'entities_id' => 0, 'is_recursive' => 1, 'kind' => 'party', 'name' => $canary, 'party_type' => 'authority',
        'is_official_contact' => 1, 'contact_category' => 'csirt', 'contact_details' => $canary,
        'when_to_contact' => $canary, 'contact_delay' => $canary, 'on_security_incident' => 1,
        'on_data_breach' => 1, 'expectations' => $canary, 'comment' => $canary,
    ]);
    // Issue #604 : campagne de simulation d'hameçonnage piégée (champs texte, nom du fichier importé),
    // avec une ligne non rapprochée piégée et un résultat de glpi (onglet de ses préférences).
    $out['phishing'] = $insert('phishingcampaigns', [
        'entities_id' => 0, 'is_recursive' => 1, 'name' => $canary, 'campaign_date' => date('Y-m-d'),
        'tool' => $canary, 'scenario' => $canary, 'target_population' => $canary, 'comment' => $canary,
        'nb_sent' => 6, 'nb_opened' => 3, 'nb_clicked' => 2, 'nb_unmatched' => 1, 'import_file' => $canary,
        'date_import' => date('Y-m-d H:i:s'),
    ]);
    $insert('phishingresults', [
        'plugin_grcmanager_phishingcampaigns_id' => $out['phishing'], 'users_id' => 0, 'identifier' => $canary,
        'line' => 2, 'is_sent' => 1,
    ]);
    $insert('phishingresults', [
        'plugin_grcmanager_phishingcampaigns_id' => $out['phishing'], 'users_id' => 2, 'line' => 3,
        'is_sent' => 1, 'is_clicked' => 1,
    ]);
    // Issue #616 : modèle de message de crise piégé (nom, objet, texte, commentaire), contact officiel
    // piégé dans sa liste de diffusion, et communication tracée piégée sur l'incident de A. Les
    // variables de l'incident de A (nom, ordinateur lié) sont piégées aussi.
    $out['crisis_template'] = $insert('crisismessagetemplates', [
        'name' => $canary, 'situation' => 'major_incident', 'stage' => 'first', 'audience' => 'internal',
        'subject' => $canary . ' {title}', 'body' => $canary . ' {title} {affected_systems} {entity}',
        'comment' => $canary, 'is_active' => 1,
    ]);
    $insert('crisismessagerecipients', [
        'plugin_grcmanager_crisismessagetemplates_id' => $out['crisis_template'],
        'itemtype' => 'GlpiPlugin\\Grcmanager\\ContextItem', 'items_id' => $out['official_contact'],
    ]);
    $insert('crisiscommunications', [
        'entities_id' => (int) $ids['a'], 'itemtype' => 'PluginGrcmanagerSecurityIncident',
        'items_id' => (int) $ids['incident_A'],
        'plugin_grcmanager_crisismessagetemplates_id' => $out['crisis_template'],
        'template_name' => $canary, 'subject' => $canary, 'body' => $canary, 'recipients' => $canary,
        'users_id' => 2, 'date_communication' => date('Y-m-d H:i:s'),
    ]);
    // Issue #771, lot 2 : prise de contact piégée (onglet de l'incident de A, fiche du contact).
    $insert('officialcontactnotifications', [
        'entities_id' => (int) $ids['a'], 'itemtype' => 'PluginGrcmanagerSecurityIncident',
        'items_id' => (int) $ids['incident_A'], 'plugin_grcmanager_contextitems_id' => $out['official_contact'],
        'decision' => 'notified', 'date_notified' => date('Y-m-d H:i:s'), 'reference' => $canary,
        'comment' => $canary, 'users_id' => 2,
    ]);
    // Issue #620 : chaque champ texte d'une violation de données.
    $out['breach'] = $insert('databreaches', [
        'name' => $canary, 'description' => $canary, 'data_details' => $canary, 'consequences' => $canary,
        'measures_taken' => $canary, 'measures_planned' => $canary, 'authority_reason' => $canary,
        'authority_name' => $canary, 'authority_reference' => $canary, 'delay_reason' => $canary,
        'subjects_reason' => $canary, 'subjects_means' => $canary, 'dpo_opinion' => $canary, 'comment' => $canary,
        'nature' => '["confidentiality"]', 'data_subjects' => '[]', 'data_categories' => '[]',
        'date_awareness' => date('Y-m-d H:i:s'), 'authority_decision' => 'not_notified',
        'plugin_grcmanager_securityincidents_id' => (int) $ids['incident_A'],
    ]);
    $insert('databreaches_processingactivities', [
        'plugin_grcmanager_databreaches_id' => $out['breach'],
        'plugin_grcmanager_processingactivities_id' => $out['processing'],
    ]);
    $insert('processingactivities_items', [
        'plugin_grcmanager_processingactivities_id' => $out['processing'],
        'itemtype' => 'Computer', 'items_id' => (int) $ids['computer_A'],
    ]);
    $out['product'] = $insert('canonicalproducts', ['manufacturer' => $canary, 'product' => $canary]);
    $insert('cpereferences', ['plugin_grcmanager_canonicalproducts_id' => $out['product'], 'cpe' => $canary]);
    $insert('productaliases', ['plugin_grcmanager_canonicalproducts_id' => $out['product'], 'alias' => $canary]);
    $insert('risktreatmentactions', [
        'plugin_grcmanager_risks_id' => (int) $ids['risk'], 'entities_id' => (int) $ids['a'], 'description' => $canary,
    ]);
    $update('risks', [
        'title' => $canary, 'description' => $canary, 'justification' => $canary,
        'external_ref' => mb_substr($canary, 0, 100),
    ], (int) $ids['risk']);
    $update('controls', ['justification' => $canary], (int) $ids['control']);
    // Issue #378 : KRI, relevé et énoncé d'appétence.
    $out['kri'] = $insert('riskindicators', [
        'name' => $canary, 'description' => $canary, 'unit' => $canary, 'entities_id' => (int) $ids['a'],
        'plugin_grcmanager_risks_id' => (int) $ids['risk'], 'status' => 'red', 'last_value' => 12,
    ]);
    $insert('riskindicatorvalues', [
        'plugin_grcmanager_riskindicators_id' => $out['kri'], 'value_date' => date('Y-m-d'), 'value' => 12,
        'status' => 'red', 'comment' => $canary,
    ]);
    // Deuxième relevé : la courbe de tendance de la fiche s'affiche (libellés et unité piégés).
    $insert('riskindicatorvalues', [
        'plugin_grcmanager_riskindicators_id' => $out['kri'], 'value_date' => date('Y-m-d', strtotime('-1 day')),
        'value' => 4, 'status' => 'green', 'comment' => $canary,
    ]);
    $DB->updateOrInsert('glpi_plugin_grcmanager_riskappetites', [
        'category' => 'process', 'appetite_level' => 'low', 'tolerance_level' => 'low', 'statement' => $canary,
    ], ['category' => 'process']);
    $update('supplierrisks', [
        'title' => $canary, 'description' => $canary, 'justification' => $canary,
    ], (int) $ids['supplierrisk']);
    $update('accessreviews', ['name' => $canary, 'comment' => $canary], (int) $ids['campaign_A']);
    $update('securityincidents', [
        'name' => $canary, 'content' => $canary, 'root_cause' => $canary, 'lessons_learned' => $canary,
        'regulatory_alerts' => $canary, 'impact_content' => $canary, 'control_list_content' => $canary,
        'rollback_plan_content' => $canary,
    ], (int) $ids['incident_A']);
    $insert('securityincidentcosts', [
        'plugin_grcmanager_securityincidents_id' => (int) $ids['incident_A'], 'name' => $canary, 'comment' => $canary,
    ]);
    $insert('securityincidenttasks', [
        'plugin_grcmanager_securityincidents_id' => (int) $ids['incident_A'], 'content' => $canary,
    ]);
    $DB->update('glpi_plugin_grcmanager_ismsrequirements', ['comment' => $canary], ['id' => ['>', 0]]);
    $DB->update('glpi_computers', ['name' => $canary], ['id' => (int) $ids['computer_A']]);
    // Issue #779 : logiciel commercial sans licence piégé (page des licences logicielles, export).
    $DB->update('glpi_softwares', ['name' => $canary], ['name' => 'Logiciel licence securite A']);
    // Issue #377 : processus metier et role d'une dependance (fiche, liste, onglets, export).
    $out['process'] = $insert('businessprocesses', ['name' => $canary, 'description' => $canary, 'rto_hours' => 4]);
    $insert('businessprocesses_items', [
        'plugin_grcmanager_businessprocesses_id' => $out['process'], 'itemtype' => 'Computer',
        'items_id' => (int) $ids['computer_A'], 'comment' => $canary,
    ]);
    // Issue #377, deuxième étape : processus amont, role du lien, risque lie et son commentaire.
    $upstream = $insert('businessprocesses', ['name' => $canary, 'description' => $canary, 'rto_hours' => 24]);
    $insert('businessprocesses_businessprocesses', [
        'plugin_grcmanager_businessprocesses_id' => $out['process'],
        'plugin_grcmanager_businessprocesses_id_upstream' => $upstream, 'comment' => $canary,
    ]);
    $insert('businessprocesses_risks', [
        'plugin_grcmanager_businessprocesses_id' => $out['process'],
        'plugin_grcmanager_risks_id' => (int) $ids['risk'], 'comment' => $canary,
    ]);
    // Issue #377, troisième étape : stratégie de reprise et commentaire d'un test mesuré.
    $update('businessprocesses', [
        'degraded_mode' => $canary, 'backup_solution' => $canary, 'recovery_procedure' => $canary,
    ], $out['process']);
    $insert('businessprocessrecoveries', [
        'plugin_grcmanager_businessprocesses_id' => $out['process'], 'test_date' => date('Y-m-d'),
        'measured_minutes' => 90, 'comment' => $canary,
    ]);
    // Issue #776 : scénario de crise piégé (nom, description, mode dégradé, rôles, commentaire), lié au
    // processus piégé (registre, fiche, onglet du processus, PDF).
    $out['crisis_scenario'] = $insert('crisisscenarios', [
        'name' => $canary, 'description' => $canary, 'degraded_mode' => $canary, 'role_management' => $canary,
        'role_ciso' => $canary, 'role_it' => $canary, 'comment' => $canary, 'is_active' => 1,
    ]);
    $insert('crisisscenarios_businessprocesses', [
        'plugin_grcmanager_crisisscenarios_id' => $out['crisis_scenario'],
        'plugin_grcmanager_businessprocesses_id' => $out['process'],
    ]);
    // Issue #774 : fonction, compétence et déclaration piégées ; glpi tient la fonction (sa fiche).
    $update('ismsfunctions', ['name' => $canary, 'description' => $canary], (int) $ids['ismsfunction_A']);
    $update('competences', ['name' => $canary, 'description' => $canary], (int) $ids['competence_A']);
    $insert('ismsfunctions_holders', [
        'plugin_grcmanager_ismsfunctions_id' => (int) $ids['ismsfunction_A'], 'itemtype' => 'User', 'items_id' => 2,
    ]);
    $out['competencedeclaration'] = $insert('competencedeclarations', [
        'entities_id' => 0, 'users_id' => 2, 'plugin_grcmanager_competences_id' => (int) $ids['competence_A'],
        'level' => 1, 'evidence_type' => 'certificate', 'evidence_reference' => $canary, 'comment' => $canary,
    ]);
    // Issue #778 : évaluation de la sécurité physique de A piégée (fiche, grille, zones, comparaison,
    // onglet du lieu, preuve sur A.7.1) ; constat en état non conforme pour afficher le bouton d'action.
    $update('physicalsecurityassessments', ['name' => $canary, 'comment' => $canary], (int) $ids['physical_A']);
    $update('physicalsecuritychecks', [
        'state' => 'noncompliant', 'finding' => $canary, 'evidence' => $canary,
    ], (int) $ids['physical_line_A']);
    $update('physicalsecurityzones', [
        'name' => $canary, 'authorized_people' => $canary, 'comment' => $canary,
    ], (int) $ids['physical_zone_A']);
    // Issue #145 : nom du document de preuve, affiché par l'onglet et la liste des validités.
    $DB->update('glpi_documents', ['name' => $canary], ['id' => (int) $ids['document_A']]);
    // Issue #380 : nom de la preuve envoyée depuis le formulaire, citée par un test (onglet).
    $DB->update('glpi_documents', ['name' => $canary], ['id' => (int) $ids['evidence_upload']]);
    $insert('controltests', [
        'plugin_grcmanager_controls_id' => (int) $ids['control'], 'test_date' => date('Y-m-d'),
        'result' => 'effective', 'documents_id' => (int) $ids['evidence_upload'],
    ]);
    // Issue #537 : référentiel personnalisé piégé, activé pour que ses cartes, ses badges sur la
    // mesure du jeu de données et son taux soient rendus.
    $controlCode = (string) ($DB->request([
        'SELECT' => 'code', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['id' => (int) $ids['control']],
    ])->current()['code'] ?? 'A.5.1');
    $out['customframework'] = $insert('customframeworks', [
        'name' => $canary, 'version' => $canary, 'source' => $canary, 'comment' => $canary,
    ]);
    $insert('customrequirements', [
        'plugin_grcmanager_customframeworks_id' => $out['customframework'], 'code' => $canary, 'theme' => $canary,
        'title' => $canary, 'description' => $canary, 'annex_a' => $controlCode, 'rank' => 1, 'comment' => $canary,
    ]);
    \GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('custom_' . $out['customframework'], true);
    // Issue #540 : registre DORA piégé : entité, prestataire (fournisseur du jeu de données), accord
    // du contrat de A, fonction (processus piégé) liée à l'accord.
    \Config::setConfigurationValues('plugin:grcmanager', ['dora_register' => json_encode([
        'name' => $canary, 'authority' => $canary, 'lei' => $canary,
    ])]);
    $DB->delete('glpi_plugin_grcmanager_doraproviders', ['suppliers_id' => (int) $ids['supplier']]);
    $insert('doraproviders', [
        'suppliers_id' => (int) $ids['supplier'], 'legal_name' => $canary, 'latin_name' => $canary, 'code' => $canary,
        'parent_code' => $canary,
    ]);
    $DB->update('glpi_plugin_grcmanager_doraarrangements', [
        'reference' => $canary, 'overarching_reference' => $canary, 'alternative_providers' => $canary,
        'storage_countries' => $canary,
    ], ['contracts_id' => (int) $ids['contract_A']]);
    $insert('doraarrangements_functions', [
        'contracts_id' => (int) $ids['contract_A'], 'plugin_grcmanager_businessprocesses_id' => $out['process'],
    ]);
    $insert('dorafunctions', [
        'plugin_grcmanager_businessprocesses_id' => $out['process'], 'licensed_activity' => $canary,
        'criticality' => 1, 'criticality_reasons' => $canary,
    ]);
    // Issue #732 : bilan de l'exercice de B (non validé) piégé : objectif, constat, historique.
    $exercise = (int) $ids['exercise_B'];
    $insert('crisisexerciseobjectives', [
        'plugin_grcmanager_crisisexercises_id' => $exercise, 'name' => $canary, 'comment' => $canary,
    ]);
    $insert('crisisexercisefindings', [
        'plugin_grcmanager_crisisexercises_id' => $exercise, 'name' => $canary, 'description' => $canary,
    ]);
    $insert('crisisexercisereviews', [
        'plugin_grcmanager_crisisexercises_id' => $exercise, 'action' => 'reopened', 'users_id' => 2,
        'date' => date('Y-m-d H:i:s'), 'comment' => $canary,
    ]);
    $out['exercise'] = $exercise;
    // Issue #777 : application et changement pieges (nom), avec leur fiche et leur liste de controle
    // piegees : page du developpement securise, onglets de l'application et du changement.
    $DB->insert('glpi_appliances', ['name' => $canary, 'entities_id' => 0, 'comment' => $canary]);
    $out['appliance'] = (int) $DB->insertId();
    $insert('applicationsecurities', [
        'appliances_id' => $out['appliance'], 'repository_url' => $canary, 'framework' => 'other',
        'framework_detail' => $canary, 'tools' => $canary, 'environments_separated' => 'no',
        'separation_justification' => $canary, 'comment' => $canary,
    ]);
    $DB->insert('glpi_changes', [
        'name' => $canary, 'content' => $canary, 'entities_id' => 0, 'status' => Change::SOLVED,
        'solvedate' => date('Y-m-d H:i:s'), 'date' => date('Y-m-d H:i:s'),
    ]);
    $out['change'] = (int) $DB->insertId();
    $DB->insert('glpi_changes_items', [
        'changes_id' => $out['change'], 'itemtype' => 'Appliance', 'items_id' => $out['appliance'],
    ]);
    $insert('releasevalidations', [
        'changes_id' => $out['change'], 'tests_passed' => 1, 'checklist_comment' => $canary,
        'decision' => 'rejected', 'validator_role' => 'ciso', 'decision_comment' => $canary, 'users_id_decision' => 2,
        'date_decision' => date('Y-m-d H:i:s'),
    ]);
    $out['control_a831'] = (int) ($DB->request([
        'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_controls', 'WHERE' => ['code' => 'A.8.31'], 'LIMIT' => 1,
    ])->current()['id'] ?? 0);
    echo json_encode($out), "\n";
    exit(0);
}

if ($mode === 'counts') {
    // Nombre de lignes de chaque table du plugin : un lecteur ne doit rien créer ni supprimer.
    $counts = [];
    foreach ($DB->listTables('glpi_plugin_grcmanager_%') as $table) {
        $name = $table['TABLE_NAME'];
        // Issue #371 : le journal d'audit enregistre par conception les consultations et les accès
        // refusés de ces profils (il ne contient rien d'autre que ces traces).
        // Issue #538 : de même, l'historique des téléchargements trace par conception la pièce
        // soumise que l'auditeur (iso-sans-droit) télécharge.
        // Issue #614 : la tendance des contrôles de cohérence enregistre, par conception, le résumé du
        // mois de chaque personne qui ouvre la page (score et nombres, aucun élément du SMSI).
        if (
            in_array($name, [
                'glpi_plugin_grcmanager_auditlogs', 'glpi_plugin_grcmanager_documentaccesses',
                'glpi_plugin_grcmanager_consistencysnapshots',
            ], true)
        ) {
            continue;
        }
        $counts[$name] = countElementsInTable($name);
    }
    ksort($counts);
    echo json_encode($counts), "\n";
    exit(0);
}

if ($mode === 'soa-approvers') {
    $last = $DB->request([
        'SELECT' => ['MAX' => 'id AS id'],
        'FROM'   => 'glpi_plugin_grcmanager_soaversions',
    ])->current()['id'] ?? 0;
    $approvers = [];
    $rows = $DB->request([
        'FROM'  => 'glpi_plugin_grcmanager_soaversions_users',
        'WHERE' => ['plugin_grcmanager_soaversions_id' => (int) $last],
    ]);
    foreach ($rows as $row) {
        $approvers[] = (int) $row['users_id'];
    }
    sort($approvers);
    echo json_encode(['version' => (int) $last, 'approvers' => $approvers]), "\n";
    exit(0);
}

$entityId = static function (string $name): int {
    $entity = new Entity();
    if ($entity->getFromDBByCrit(['name' => $name, 'entities_id' => 0])) {
        return (int) $entity->getID();
    }
    return (int) $entity->add(['name' => $name, 'entities_id' => 0]);
};
$profileId = static function (string $name, array $rights): int {
    $profile = new Profile();
    if (!$profile->getFromDBByCrit(['name' => $name])) {
        $profile->add(['name' => $name, 'interface' => 'central']);
    }
    ProfileRight::updateProfileRights((int) $profile->getID(), $rights);
    return (int) $profile->getID();
};
$userId = static function (string $login, int $entity, int $profile) use ($DB): int {
    $user = new User();
    if (!$user->getFromDBbyName($login)) {
        $user->add([
            'name' => $login, 'password' => SECURITY_TEST_PASSWORD, 'password2' => SECURITY_TEST_PASSWORD,
            'entities_id' => $entity, '_profiles_id' => 0,
        ]);
    }
    $id = (int) $user->getID();
    if ($profile > 0) {
        $DB->delete('glpi_profiles_users', ['users_id' => $id]);
        $DB->insert('glpi_profiles_users', [
            'users_id' => $id, 'profiles_id' => $profile, 'entities_id' => $entity,
            'is_recursive' => 0, 'is_dynamic' => 0, 'is_default_profile' => 1,
        ]);
        $DB->update('glpi_users', ['profiles_id' => $profile, 'entities_id' => $entity], ['id' => $id]);
    }
    return $id;
};
$add = static function (CommonDBTM $item, array $input): int {
    $id = (int) $item->add($input);
    if ($id <= 0) {
        fwrite(STDERR, 'Creation impossible : ' . $item::class . ' ' . json_encode($input) . "\n");
        exit(1);
    }
    return $id;
};

$a = $entityId('Securite ISO A');
$b = $entityId('Securite ISO B');
// Issue #536 : entités créées après la connexion, ajoutées aux entités actives de la session (un
// risque ou une SoA ne se crée que dans une entité de la session).
Session::changeActiveEntities(0, true);
\GlpiPlugin\Grcmanager\EntityPerimeter::reset();

// Gestionnaire : tous les droits du plugin ; lecteur : lecture seule. Tous deux limités à A.
$manager = $userId('iso-gestionnaire', $a, $profileId('Securite ISO - gestionnaire A', [
    'plugin_grcmanager'                  => ALLSTANDARDRIGHT,
    'plugin_grcmanager_sensitive'        => ALLSTANDARDRIGHT,
    'plugin_grcmanager_gdpr'             => ALLSTANDARDRIGHT,
    'plugin_grcmanager_continuity'       => ALLSTANDARDRIGHT,
    'plugin_grcmanager_securityincident' => ALLSTANDARDRIGHT | CommonITILObject::READALL | CommonITILObject::READMY,
    'computer' => READ, 'contract' => READ, 'supplier' => READ, 'user' => READ, 'document' => READ,
    'software' => READ, 'dashboard' => READ, 'appliance' => READ,
    // Issue #777 : voit tous les changements (mises en production), sans le droit de décider.
    'change' => CommonITILObject::READALL,
]));
$reader = $userId('iso-lecteur', $a, $profileId('Securite ISO - lecteur A', [
    'plugin_grcmanager'                  => READ,
    'plugin_grcmanager_sensitive'        => READ,
    'plugin_grcmanager_gdpr'             => READ,
    'plugin_grcmanager_continuity'       => READ,
    'plugin_grcmanager_securityincident' => CommonITILObject::READALL,
    'computer' => READ, 'contract' => READ, 'supplier' => READ, 'user' => READ, 'software' => READ,
    'dashboard' => READ, 'appliance' => READ, 'change' => CommonITILObject::READALL,
    // Issue #779 : la conformité des licences exige aussi la lecture native des licences (le
    // gestionnaire ne l'a pas : la page lui est refusée).
    'license' => READ,
    // Issue #782 : la fin de garantie du matériel exige la lecture native des informations
    // financières (le gestionnaire ne l'a pas : l'export lui est refusé).
    'infocom' => READ,
]));

// Sans droit : aucun droit sur le plugin, chaque page doit lui être refusée. Il lit les
// ordinateurs, comme un technicien : les onglets du plugin sur un ordinateur ne lui montrent rien
// (revue de sécurité 2.11.0, contenu d'onglet demandé directement).
$noRight = $userId('iso-sans-droit', $a, $profileId('Securite ISO - sans droit', [
    'plugin_grcmanager'                  => 0,
    'plugin_grcmanager_sensitive'        => 0,
    'plugin_grcmanager_gdpr'             => 0,
    'plugin_grcmanager_continuity'       => 0,
    'plugin_grcmanager_securityincident' => 0,
    'computer'                           => READ,
    'software'                           => READ,
    // Issue #377 : lit aussi les applications et les fournisseurs, dont les onglets du plugin
    // (classification, processus métier) ne lui montrent rien.
    'appliance'                          => READ,
    'supplier'                           => READ,
    // Issue #777 : lit les changements, dont l'onglet de validation de mise en production ne lui
    // montre rien.
    'change'                             => CommonITILObject::READALL,
    // Issue #143 : voit les tableaux de bord, pas les cartes du plugin.
    'dashboard'                          => READ,
    // Issue #380 : lit les documents de son entité, sauf la preuve d'une mesure qu'il ne lit pas.
    'document'                           => READ,
]));

// Registres sensibles : tout le SMSI en gestion, mais pas le droit dédié (risques, vulnérabilités du
// parc, flux réseau, analyse d'impact) : ces écrans, cartes et titres de risques lui sont refusés.
$general = $userId('iso-general', $a, $profileId('Securite ISO - sans registres sensibles', [
    'plugin_grcmanager'                  => ALLSTANDARDRIGHT,
    'plugin_grcmanager_sensitive'        => 0,
    'plugin_grcmanager_gdpr'             => 0,
    'plugin_grcmanager_continuity'       => 0,
    'plugin_grcmanager_securityincident' => 0,
    'computer' => READ, 'software' => READ, 'supplier' => READ, 'contract' => READ, 'user' => READ,
    'dashboard' => READ,
]));

// Issue #536 : chaque entité tient sa propre SoA (copie des 93 mesures) ; la mesure testée est
// celle de A, que le gestionnaire de A peut modifier. Celle de B ne doit jamais lui être ouverte.
$soaControl = static function (int $entity): int {
    global $DB;

    if (!\GlpiPlugin\Grcmanager\EntityPerimeter::hasOwnSoa($entity)) {
        \GlpiPlugin\Grcmanager\EntityPerimeter::createSoa($entity);
    }
    $control = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_grcmanager_controls',
        'WHERE'  => ['entities_id' => $entity],
        'ORDER'  => 'id',
        'LIMIT'  => 1,
    ])->current();
    if ($control === null) {
        fwrite(STDERR, "Aucune mesure (annexe A) dans l'entité $entity : SoA de l'entité non créée\n");
        exit(1);
    }

    return (int) $control['id'];
};
$controlId = $soaControl($a);
$controlB = $soaControl($b);
$DB->update('glpi_plugin_grcmanager_controls', ['justification' => 'Justification securite A'], ['id' => $controlId]);
$DB->update('glpi_plugin_grcmanager_controls', ['justification' => 'Justification securite B'], ['id' => $controlB]);
// Version approuvée de la SoA de B (historique de l'approbation, jamais montré à A).
if (countElementsInTable('glpi_plugin_grcmanager_soaversions', ['entities_id' => $b]) === 0) {
    $DB->insert('glpi_plugin_grcmanager_soaversions', [
        'entities_id' => $b, 'is_recursive' => 1, 'version' => 1, 'status' => 'approved',
        'comment' => 'Version securite B', 'users_id' => 2, 'date_creation' => date('Y-m-d H:i:s'),
        'date_answered' => date('Y-m-d H:i:s'),
    ]);
}

$supplier = $add(new Supplier(), [
    'name' => 'Fournisseur securite ' . uniqid(), 'entities_id' => 0, 'is_recursive' => 1,
]);
$ids = [
    'a' => $a, 'b' => $b, 'manager' => $manager, 'reader' => $reader, 'control' => $controlId,
    'control_B' => $controlB, 'supplier' => $supplier,
];
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $ids['incident_' . $suffix] = $add(new PluginGrcmanagerSecurityIncident(), [
        'name'        => 'Incident securite ' . $suffix,
        'content'     => 'Test de controle d acces',
        'entities_id' => $entity,
    ]);
    $ids['campaign_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\AccessReview(), [
        'name'                          => 'Campagne securite ' . $suffix,
        'entities_id'                   => $entity,
        'plugin_grcmanager_controls_id' => $controlId,
        'date_start'                    => date('Y-m-d'),
        'due_date'                      => date('Y-m-d', strtotime('+30 days')),
    ]);
    $contract = $add(new Contract(), [
        'name'        => 'Contrat securite ' . $suffix,
        'entities_id' => $entity,
        'begin_date'  => date('Y-m-d'),
        'duration'    => 12,
    ]);
    $add(new Contract_Supplier(), ['contracts_id' => $contract, 'suppliers_id' => $supplier]);
    // Issue #540 : contrat de l'entité au registre DORA (référence propre à l'entité).
    $ids['contract_' . $suffix] = $contract;
    if (countElementsInTable('glpi_plugin_grcmanager_doraarrangements', ['contracts_id' => $contract]) === 0) {
        $DB->insert('glpi_plugin_grcmanager_doraarrangements', [
            'contracts_id' => $contract, 'reference' => 'DORA-securite-' . $suffix, 'service_types' => 'S19',
        ]);
    }
    $ids['computer_' . $suffix] = $add(new Computer(), ['name' => 'PC securite ' . $suffix, 'entities_id' => $entity]);
    // Issue #779 : logiciel commercial fictif installé sans licence sur l'ordinateur de l'entité.
    $licenseSoftware = $add(new Software(), [
        'name' => 'Logiciel licence securite ' . $suffix, 'entities_id' => $entity,
        'manufacturers_id' => (int) (new Manufacturer())->import(['name' => 'Microsoft']),
    ]);
    $add(new Item_SoftwareVersion(), [
        'itemtype' => Computer::class, 'items_id' => $ids['computer_' . $suffix], 'entities_id' => $entity,
        'softwareversions_id' => $add(new SoftwareVersion(), [
            'name' => '1.0', 'softwares_id' => $licenseSoftware, 'entities_id' => $entity,
        ]),
    ]);
    // Issue #782 : ordinateur fictif de l'entité dont la garantie est expirée.
    $add(new Infocom(), [
        'itemtype' => Computer::class, 'entities_id' => $entity,
        'warranty_date' => '2020-01-01', 'warranty_duration' => 12,
        'items_id' => $add(new Computer(), ['name' => 'PC garantie securite ' . $suffix, 'entities_id' => $entity]),
    ]);
    // Issue #377, troisième étape : application classable (RTO) de l'entité.
    $ids['appliance_' . $suffix] = $add(new Appliance(), [
        'name' => 'Application securite ' . $suffix, 'entities_id' => $entity,
    ]);
    // Issue #378 : indicateur de risque de l'entité.
    $ids['kri_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\RiskIndicator(), [
        'name' => 'KRI securite ' . $suffix, 'entities_id' => $entity,
        'threshold_warning' => '5', 'threshold_critical' => '10',
    ]);
    // Issue #608 : partie intéressée de l'entité (contexte de l'organisation).
    $ids['context_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\ContextItem(), [
        'kind' => 'party', 'name' => 'Partie securite ' . $suffix, 'entities_id' => $entity,
        // Issue #771 : contact officiel a prevenir pour un incident (onglet de l'incident de A).
        'is_official_contact' => 1, 'contact_category' => 'csirt', 'on_security_incident' => 1,
    ]);
    // Issue #612 : activite de la matrice des responsabilites de l'entite.
    $ids['responsibility_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\ResponsibilityActivity(), [
        'name' => 'Activite securite ' . $suffix, 'entities_id' => $entity,
    ]);
    // Issue #145 : politique (globale) avec un document de preuve daté de l'entité.
    $ids['policy_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\Policy(), [
        'title' => 'Politique securite ' . $suffix, 'entities_id' => $entity,
    ]);
    $ids['document_' . $suffix] = $add(new Document(), [
        'name' => 'Preuve securite ' . $suffix, 'entities_id' => $entity,
    ]);
    $add(new Document_Item(), [
        'documents_id' => $ids['document_' . $suffix],
        'itemtype' => \GlpiPlugin\Grcmanager\Policy::class, 'items_id' => $ids['policy_' . $suffix],
    ]);
    // Issue #380 : le même document est aussi une preuve possible des tests d'efficacité de la mesure.
    $add(new Document_Item(), [
        'documents_id' => $ids['document_' . $suffix],
        'itemtype' => \GlpiPlugin\Grcmanager\Control::class, 'items_id' => $controlId,
    ]);
    $add(new \GlpiPlugin\Grcmanager\Evidence(), [
        'itemtype' => \GlpiPlugin\Grcmanager\Policy::class, 'items_id' => $ids['policy_' . $suffix],
        'documents_id' => $ids['document_' . $suffix], 'valid_until' => date('Y-m-d', strtotime('+10 days')),
    ]);
}
// Issue #372 : les deux incidents touchent l'ordinateur de A ; l'onglet « Incidents de sécurité »
// de cet ordinateur ne doit montrer (ni compter) l'incident de B qu'au super-admin.
foreach (['A', 'B'] as $suffix) {
    $add(new PluginGrcmanagerSecurityIncident_Item(), [
        'plugin_grcmanager_securityincidents_id' => $ids['incident_' . $suffix],
        'itemtype' => Computer::class, 'items_id' => $ids['computer_A'],
    ]);
}
$ids['supplierrisk'] = $add(new \GlpiPlugin\Grcmanager\SupplierRisk(), [
    'title' => 'Risque fournisseur securite', 'suppliers_id' => $supplier,
]);
$ids['training'] = $add(
    new \GlpiPlugin\Grcmanager\Training(),
    ['title' => 'Formation securite', 'participants' => [2]]
);
// Issue #139 : copie de quiz de glpi, lisible par lui seul (ni formateur ni groupe RSSI ici).
$ids['quiz'] = \GlpiPlugin\Grcmanager\TrainingQuiz::getParticipationId($ids['training'], 2);
$quizPrefix = uniqid('', true);
file_put_contents(GLPI_TMP_DIR . '/' . $quizPrefix . 'copie-quiz.txt', "Copie de quiz securite\n");
\GlpiPlugin\Grcmanager\TrainingQuiz::attach($ids['quiz'], $quizPrefix . 'copie-quiz.txt', $quizPrefix);
// Issue #536 : le risque appartient à l'entité A ; celui de B n'est jamais montré aux profils de A.
$ids['risk'] = $add(new \GlpiPlugin\Grcmanager\Risk(), [
    'title' => 'Risque securite', 'entities_id' => $a, 'linked_items_Computer' => [$ids['computer_B']],
]);
$ids['risk_B'] = $add(new \GlpiPlugin\Grcmanager\Risk(), [
    'title' => 'Risque securite B', 'entities_id' => $b, 'external_ref' => 'RISQUE-SECURITE-B',
]);
// Issue #608, lot 2 : les parties intéressées de A et de B citent le risque de A (onglet du risque,
// export du registre : la partie de B n'est montrée qu'aux profils qui voient B).
foreach (['context_A', 'context_B'] as $contextKey) {
    $contextLink = [
        'plugin_grcmanager_contextitems_id' => (int) $ids[$contextKey],
        'itemtype' => \GlpiPlugin\Grcmanager\Risk::class, 'items_id' => (int) $ids['risk'],
    ];
    if (countElementsInTable('glpi_plugin_grcmanager_contextitems_items', $contextLink) === 0) {
        $DB->insert(
            'glpi_plugin_grcmanager_contextitems_items',
            $contextLink + ['date_creation' => date('Y-m-d H:i:s')]
        );
    }
}
// Registres sensibles : la fiche du contrôle, lisible sans le droit dédié, ne doit pas montrer ce
// risque lié.
$linkWhere = ['plugin_grcmanager_controls_id' => $controlId, 'plugin_grcmanager_risks_id' => (int) $ids['risk']];
if (countElementsInTable('glpi_plugin_grcmanager_controls_risks', $linkWhere) === 0) {
    $DB->insert('glpi_plugin_grcmanager_controls_risks', $linkWhere + ['date_creation' => date('Y-m-d H:i:s')]);
}
// Issue #140 : audit avec équipe, document restreint, note, groupe RSSI et accès historisé, pour
// que le contrôle des restes après désinstallation porte aussi sur ces données.
// Issue #536 : l'audit appartient à l'entité A, comme les profils testés.
$ids['audit'] = $add(
    new \GlpiPlugin\Grcmanager\Audit(),
    ['title' => 'Audit securite', 'users_id' => 2, 'entities_id' => $a]
);
// Vrai fichier : document.send.php répond 404 avant tout contrôle d'accès sans lui.
$auditFile = 'plan-audit-securite-' . uniqid() . '.txt';
file_put_contents(GLPI_TMP_DIR . '/' . $auditFile, "Plan d'audit securite\n");
$ids['audit_document'] = $add(new Document(), [
    'name' => 'Plan d\'audit securite', 'entities_id' => 0, 'is_recursive' => 1,
    '_filename' => [$auditFile], '_tag_filename' => [$auditFile],
]);
$add(new Document_Item(), [
    'documents_id' => $ids['audit_document'],
    'itemtype'     => \GlpiPlugin\Grcmanager\Audit::class,
    'items_id'     => $ids['audit'],
]);
\GlpiPlugin\Grcmanager\AuditTeam::addMember($ids['audit'], 2, \GlpiPlugin\Grcmanager\AuditTeam::ROLE_AUDITEE);
$ids['ciso_group'] = $add(new Group(), ['name' => 'RSSI securite', 'entities_id' => 0, 'is_recursive' => 1]);
\GlpiPlugin\Grcmanager\AuditTeam::setCisoGroupId($ids['ciso_group']);
$add(new Notepad(), [
    'itemtype' => \GlpiPlugin\Grcmanager\Audit::class, 'items_id' => $ids['audit'], 'content' => 'Note de travail',
]);
\GlpiPlugin\Grcmanager\DocumentAccess::log(
    $ids['audit_document'],
    \GlpiPlugin\Grcmanager\Audit::class,
    $ids['audit'],
    2
);
// Issue #183 : exposition à une fuite (registre sensible), créée comme le fait la tâche planifiée.
$exposure = ['users_id' => 2, 'email' => '', 'breach_name' => 'SecurityTestBreach'];
if (countElementsInTable(\GlpiPlugin\Grcmanager\BreachExposure::getTable(), $exposure) === 0) {
    $DB->insert(\GlpiPlugin\Grcmanager\BreachExposure::getTable(), $exposure + [
        'breach_title' => 'Fuite securite',
        'data_classes' => json_encode(['Email addresses', 'Passwords']),
        'status'       => \GlpiPlugin\Grcmanager\BreachExposure::STATUS_NEW,
    ]);
}
// Issue #377 : un processus metier par entite ; celui de A depend aussi du PC de B (invisible pour
// les profils limites a A, jamais nomme pour eux).
foreach (['A', 'B'] as $suffix) {
    $ids['process_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\BusinessProcess(), [
        'name' => 'Processus securite ' . $suffix, 'entities_id' => $ids[strtolower($suffix)], 'rto_hours' => 4,
    ]);
    $DB->insert('glpi_plugin_grcmanager_businessprocesses_items', [
        'plugin_grcmanager_businessprocesses_id' => $ids['process_' . $suffix],
        'itemtype' => 'Computer', 'items_id' => $ids['computer_' . $suffix], 'comment' => 'Serveur ' . $suffix,
    ]);
}
$DB->insert('glpi_plugin_grcmanager_businessprocesses_items', [
    'plugin_grcmanager_businessprocesses_id' => $ids['process_A'],
    'itemtype' => 'Computer', 'items_id' => $ids['computer_B'], 'comment' => 'Serveur B',
]);
// Issue #377, deuxième étape : le processus de A dépend de celui de B (jamais nommé pour un profil
// limité à A), et le risque est lié aux deux processus.
$DB->insert('glpi_plugin_grcmanager_businessprocesses_businessprocesses', [
    'plugin_grcmanager_businessprocesses_id' => $ids['process_A'],
    'plugin_grcmanager_businessprocesses_id_upstream' => $ids['process_B'], 'comment' => 'Amont B',
]);
foreach (['A', 'B'] as $suffix) {
    $DB->insert('glpi_plugin_grcmanager_businessprocesses_risks', [
        'plugin_grcmanager_businessprocesses_id' => $ids['process_' . $suffix],
        'plugin_grcmanager_risks_id' => (int) $ids['risk'],
    ]);
    // Issue #377, troisième étape : stratégie de reprise et test mesuré, historisé sur le processus,
    // pour que le contrôle commun des restes après désinstallation voie aussi ce journal.
    (new \GlpiPlugin\Grcmanager\BusinessProcess())->update([
        'id' => $ids['process_' . $suffix], 'degraded_mode' => 'Mode degrade ' . $suffix,
    ]);
    $add(new \GlpiPlugin\Grcmanager\BusinessProcessRecovery(), [
        'plugin_grcmanager_businessprocesses_id' => $ids['process_' . $suffix],
        'measured_minutes' => 300, 'comment' => 'Test de reprise ' . $suffix,
    ]);
    $DB->insert('glpi_plugin_grcmanager_businessprocesses_items', [
        'plugin_grcmanager_businessprocesses_id' => $ids['process_' . $suffix],
        'itemtype' => 'Appliance', 'items_id' => $ids['appliance_' . $suffix], 'comment' => 'Application ' . $suffix,
    ]);
    $add(new \GlpiPlugin\Grcmanager\AssetClassification(), [
        'itemtype' => 'Appliance', 'items_id' => $ids['appliance_' . $suffix], 'availability' => 'high',
        'rto_hours' => 24, 'rpo_hours' => 4,
    ]);
    // Issue #777 : fiche « développement sécurisé » de l'application, et changement de l'entité lié à
    // l'application, mis en production sans validation sécurité.
    if (\GlpiPlugin\Grcmanager\ApplicationSecurity::getForAppliance($ids['appliance_' . $suffix]) === null) {
        \GlpiPlugin\Grcmanager\ApplicationSecurity::saveFor($ids['appliance_' . $suffix], [
            'repository_url' => 'https://git.example.org/securite-' . strtolower($suffix),
            'framework' => 'owasp_asvs', 'environments_separated' => 'no',
            'separation_justification' => 'Justification securite ' . $suffix,
        ]);
    }
    $changeEntity = $suffix === 'A' ? $a : $b;
    $ids['change_' . $suffix] = $add(new Change(), [
        'name' => 'Changement securite ' . $suffix, 'content' => 'Mise en production fictive',
        'entities_id' => $changeEntity,
    ]);
    $add(new Change_Item(), [
        'changes_id' => $ids['change_' . $suffix], 'itemtype' => 'Appliance',
        'items_id'   => $ids['appliance_' . $suffix],
    ]);
    // Mise en production : changement résolu à l'instant, sans passer par le circuit de solution.
    $DB->update('glpi_changes', [
        'status' => Change::SOLVED, 'solvedate' => date('Y-m-d H:i:s'),
    ], ['id' => $ids['change_' . $suffix]]);
}
// Issue #776 : un scénario de crise sécurité par entité, chacun lié au processus de son entité. Celui
// de B n'est jamais nommé pour un profil limité à A (registre, onglet du processus, PDF).
foreach (['A', 'B'] as $suffix) {
    $ids['crisis_scenario_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\CrisisScenario(), [
        'name' => 'Scenario crise securite ' . $suffix, 'entities_id' => $ids[strtolower($suffix)],
        'degraded_mode' => 'Mode degrade securise ' . $suffix, 'role_ciso' => 'RSSI ' . $suffix,
        '_processes_sent' => 1, '_processes' => [$ids['process_' . $suffix]],
    ]);
}
// Issue #377, quatrième étape : BIA échue du processus de A, rappelée par la tâche planifiée (message
// en file d'attente si l'envoi est actif), pour que le contrôle des restes après désinstallation porte
// aussi sur ces rappels.
$DB->update('glpi_plugin_grcmanager_businessprocesses', [
    'bia_date' => date('Y-m-d', strtotime('-400 days')), 'users_id' => $manager,
], ['id' => $ids['process_A']]);
\GlpiPlugin\Grcmanager\BusinessProcessReminder::run(date('Y-m-d'));

$ids['breachexposure'] = (int) current(
    (new \GlpiPlugin\Grcmanager\BreachExposure())->find($exposure, [], 1)
)['id'];
// Issue #375 : registre des traitements (RGPD), une fiche par entité. Celle de A cite les
// ordinateurs de A et de B : le gestionnaire de A ne doit ni voir ni retirer celui de B.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $processing = new \GlpiPlugin\Grcmanager\ProcessingActivity();
    if (!$processing->getFromDBByCrit(['name' => 'Traitement securite ' . $suffix])) {
        $add($processing, [
            'name'                           => 'Traitement securite ' . $suffix,
            'entities_id'                    => $entity,
            'purpose'                        => 'Finalite fictive',
            '_linked_items_Computer_defined' => 1,
            'linked_items_Computer'          => $suffix === 'A' ? [$ids['computer_A'], $ids['computer_B']] : [],
        ]);
    }
    $ids['processing_' . $suffix] = (int) $processing->getID();
    // Issue #614 : AIPD requise non faite, signalee par les controles de coherence.
    $DB->update('glpi_plugin_grcmanager_processingactivities', ['dpia_status' => 'required'], [
        'id' => $ids['processing_' . $suffix],
    ]);
    // Analyse d'impact historisée : le contrôle commun des restes doit voir son journal nettoyé.
    if ($suffix === 'A' && \GlpiPlugin\Grcmanager\Dpia::forActivity($ids['processing_A']) === null) {
        \GlpiPlugin\Grcmanager\Dpia::saveForm($ids['processing_A'], ['necessity' => 'Necessite fictive']);
    }

    $dsr = new \GlpiPlugin\Grcmanager\DataSubjectRequest();
    if (!$dsr->getFromDBByCrit(['name' => 'Demande securite ' . $suffix])) {
        $add($dsr, [
            'name'          => 'Demande securite ' . $suffix,
            'entities_id'   => $entity,
            'date_received' => date('Y-m-d'),
        ]);
    }
    $ids['dsr_' . $suffix] = (int) $dsr->getID();

    // Issue #620 : violation de données inscrite au registre, non notifiée avec sa justification.
    $breach = new \GlpiPlugin\Grcmanager\DataBreach();
    if (!$breach->getFromDBByCrit(['name' => 'Violation securite ' . $suffix])) {
        $add($breach, [
            'name'               => 'Violation securite ' . $suffix,
            'entities_id'        => $entity,
            'date_awareness'     => date('Y-m-d H:i:s'),
            'authority_decision' => 'not_notified',
            'authority_reason'   => 'Risque improbable (fictif)',
        ]);
    }
    $ids['breach_' . $suffix] = (int) $breach->getID();
}

// Issue #380, troisième étape : preuve envoyée depuis le formulaire d'un test, par le même chemin
// (type réel vérifié, document de la mesure au registre des documents restreints), puis rangée dans
// l'entité A : seuls ceux qui lisent la mesure la lisent, pas un simple droit Documents dans A.
$evidenceFile = tempnam(sys_get_temp_dir(), 'grc');
file_put_contents($evidenceFile, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$evidenceControl = new \GlpiPlugin\Grcmanager\Control();
$evidenceControl->getFromDB($controlId);
$ids['evidence_upload'] = \GlpiPlugin\Grcmanager\ControlTest::storeEvidenceFile(
    $evidenceControl,
    $evidenceFile,
    'Preuve envoyee securite.pdf'
);
unlink($evidenceFile);
if ($ids['evidence_upload'] <= 0) {
    fwrite(STDERR, "Envoi de la preuve impossible\n");
    exit(1);
}
$DB->update('glpi_documents', ['entities_id' => $a], ['id' => $ids['evidence_upload']]);

// Issue #537 : référentiel personnalisé (fiche consultée par les profils de la matrice d'accès).
$customFramework = new \GlpiPlugin\Grcmanager\CustomFramework();
if (!$customFramework->getFromDBByCrit(['name' => 'Referentiel securite'])) {
    $add($customFramework, ['name' => 'Referentiel securite', 'version' => '1']);
    (new \GlpiPlugin\Grcmanager\CustomRequirement())->add([
        'plugin_grcmanager_customframeworks_id' => $customFramework->getID(), 'code' => 'SEC-01',
        'title' => 'Exigence securite', 'annex_a' => 'A.5.1', 'rank' => 1,
    ]);
}
$ids['custom_framework'] = (int) $customFramework->getID();

// Issue #535 : politique approuvée diffusée au seul groupe « Lecteurs politique securite » (glpi).
// Les autres profils sont hors du public : ni la politique à lire, ni ses documents.
$ackPolicy = new \GlpiPlugin\Grcmanager\Policy();
if (!$ackPolicy->getFromDBByCrit(['title' => 'Politique lecture securite'])) {
    $add($ackPolicy, [
        'title' => 'Politique lecture securite', 'version' => '1.0', 'status' => 'approved',
        'approval_date' => date('Y-m-d'), 'description' => 'Texte de la politique fictive',
    ]);
    $ackGroup = $add(new Group(), ['name' => 'Lecteurs politique securite', 'entities_id' => 0, 'is_recursive' => 1]);
    $DB->insert('glpi_groups_users', ['groups_id' => $ackGroup, 'users_id' => 2]);
    \GlpiPlugin\Grcmanager\PolicyAcknowledgement::addAudience($ackPolicy->getID(), 'Group', $ackGroup);
    $add(new Document_Item(), [
        'documents_id' => $ids['document_A'],
        'itemtype' => \GlpiPlugin\Grcmanager\Policy::class, 'items_id' => $ackPolicy->getID(),
    ]);
    \GlpiPlugin\Grcmanager\PolicyAcknowledgement::launch($ackPolicy, 2);
}
$ids['policy_ack'] = (int) $ackPolicy->getID();
$ackCampaign = \GlpiPlugin\Grcmanager\PolicyAcknowledgement::getCurrentCampaign($ids['policy_ack']);
$ids['ack_campaign'] = (int) ($ackCampaign['id'] ?? 0);
$ids['ack_line'] = (int) ($DB->request([
    'SELECT' => ['id'],
    'FROM'   => \GlpiPlugin\Grcmanager\PolicyAcknowledgement::LINES_TABLE,
    'WHERE'  => ['plugin_grcmanager_policycampaigns_id' => $ids['ack_campaign'], 'users_id' => 2],
])->current()['id'] ?? 0);
if ($ids['ack_line'] <= 0) {
    fwrite(STDERR, "Campagne de prise de connaissance impossible\n");
    exit(1);
}

// Issue #538 : deux demandes de preuves de « Audit securite » confiées à glpi, l'une soumise avec sa
// pièce, l'autre en préparation (pièce non soumise). iso-sans-droit, sans aucun droit sur le plugin,
// a un accès auditeur en vigueur à cet audit seulement ; un second audit lui reste fermé.
$evidenceDocument = static function (string $name) use ($add, $ids): int {
    $file = 'preuve-securite-' . uniqid() . '.txt';
    file_put_contents(GLPI_TMP_DIR . '/' . $file, $name . "\n");
    $documentsId = $add(new Document(), [
        'name' => $name, 'entities_id' => 0, 'is_recursive' => 1,
        '_filename' => [$file], '_tag_filename' => [$file],
    ]);
    $add(new Document_Item(), [
        'documents_id' => $documentsId, 'itemtype' => \GlpiPlugin\Grcmanager\Audit::class, 'items_id' => $ids['audit'],
    ]);

    return $documentsId;
};
$ids['audit_other'] = $add(
    new \GlpiPlugin\Grcmanager\Audit(),
    ['title' => 'Audit hors perimetre securite', 'users_id' => 2, 'entities_id' => $a]
);
foreach (['submitted' => 'REQ securite soumise', 'draft' => 'REQ securite brouillon'] as $key => $reference) {
    $ids['evreq_' . $key] = $add(new \GlpiPlugin\Grcmanager\AuditEvidenceRequest(), [
        'plugin_grcmanager_audits_id'   => $ids['audit'],
        'plugin_grcmanager_controls_id' => $controlId,
        'reference'                     => $reference,
        'description'                   => 'Preuve fictive demandee pour le test',
        'users_id'                      => 2,
        'due_date'                      => date('Y-m-d', strtotime('+10 days')),
    ]);
    $DB->insert(\GlpiPlugin\Grcmanager\AuditEvidenceRequest::PIECES_TABLE, [
        'plugin_grcmanager_auditevidencerequests_id' => $ids['evreq_' . $key],
        'documents_id'                               => $evidenceDocument('Piece securite ' . $key),
        'origin'                                     => 'upload',
        'users_id'                                   => 2,
        'is_submitted'                               => 0,
        'date_creation'                              => date('Y-m-d H:i:s'),
    ]);
    $ids['evpiece_' . $key] = (int) $DB->insertId();
}
if (\GlpiPlugin\Grcmanager\AuditEvidenceRequest::submit($ids['evreq_submitted'], 2) !== '') {
    fwrite(STDERR, "Soumission de la demande de preuve impossible\n");
    exit(1);
}
$accessEnd = date('Y-m-d', strtotime('+30 days'));
if (\GlpiPlugin\Grcmanager\AuditorAccess::grant($ids['audit'], $noRight, $accessEnd, 2) !== '') {
    fwrite(STDERR, "Accès auditeur impossible\n");
    exit(1);
}
// Issue #606 : ligne de la grille de « Audit securite » (glpi en est l'auditeur, seul de l'équipe
// parmi les profils testés) et modèle de grille de l'entité racine, hors de portée des profils
// limités à A.
$DB->insert(\GlpiPlugin\Grcmanager\AuditChecklist::TABLE, [
    'plugin_grcmanager_audits_id' => $ids['audit'], 'ref_type' => 'free', 'code' => 'S-1',
    'label' => 'Point securite grille', 'question' => 'Question securite grille', 'result' => 'not_audited',
    'rank' => 1, 'date_creation' => date('Y-m-d H:i:s'),
]);
$ids['checklist_line'] = (int) $DB->insertId();
$DB->insert(\GlpiPlugin\Grcmanager\AuditChecklistTemplate::TABLE, [
    'entities_id' => 0, 'is_recursive' => 1, 'name' => 'Modele securite ' . uniqid(),
    'date_creation' => date('Y-m-d H:i:s'),
]);
$ids['checklist_template'] = (int) $DB->insertId();
// Issue #607 : test d'efficacité de la mesure de A, en attente de relecture, enregistré par le
// gestionnaire de A : il ne peut pas le relire lui-même ; le super-admin le peut.
$DB->insert('glpi_plugin_grcmanager_controltests', [
    'plugin_grcmanager_controls_id' => $controlId, 'test_date' => date('Y-m-d'), 'method' => 'inspection',
    'result' => 'effective', 'users_id' => $manager, 'users_id_recorder' => $manager,
    'review_status' => 'pending', 'comment' => 'Test securite relecture', 'date_creation' => date('Y-m-d H:i:s'),
]);
$ids['review_test'] = (int) $DB->insertId();

// Issue #536, lot 2 : audit, constat et action de traitement de l'entité B, jamais montrés aux
// profils limités à A, même confiés au gestionnaire de A. Une demande de preuve de l'audit de B
// confiée à ce gestionnaire et un accès auditeur à cet audit donné avant les périmètres (insérés
// tels quels, l'application les refuserait désormais) ne lui ouvrent rien non plus.
$overdue = date('Y-m-d', strtotime('-60 days'));
$ids['audit_B'] = $add(
    new \GlpiPlugin\Grcmanager\Audit(),
    ['title' => 'Audit securite B', 'users_id' => 2, 'entities_id' => $b]
);
// Issue #772, lot 2 : audits de certification de A et de B (plan d'audit de l'organisme).
foreach (['audit_cert' => $a, 'audit_cert_B' => $b] as $key => $entityId) {
    $ids[$key] = $add(new \GlpiPlugin\Grcmanager\Audit(), [
        'title' => 'Audit certification securite ' . ($entityId === $b ? 'B' : 'A'), 'audit_type' => 'surveillance_1',
        'planned_date' => date('Y-m-d'), 'users_id' => 2, 'entities_id' => $entityId,
    ]);
}
foreach (['A' => $ids['audit'], 'B' => $ids['audit_B']] as $suffix => $auditId) {
    $ids['nc_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\Nonconformity(), [
        'title'                       => 'Constat securite ' . $suffix,
        'plugin_grcmanager_audits_id' => $auditId,
        'finding_type'                => 'nonconformity',
        'severity'                    => 'major',
        'status'                      => 'open',
        'users_id'                    => $manager,
        'due_date'                    => $overdue,
    ]);
}
$ids['action_B'] = $add(new \GlpiPlugin\Grcmanager\RiskTreatmentAction(), [
    'plugin_grcmanager_risks_id' => $ids['risk_B'],
    'description'                => 'Action securite B',
    'users_id'                   => $manager,
    'due_date'                   => $overdue,
]);
$DB->insert(\GlpiPlugin\Grcmanager\AuditEvidenceRequest::getTable(), [
    'plugin_grcmanager_audits_id' => $ids['audit_B'],
    'reference'                   => 'REQ securite B',
    'users_id'                    => $manager,
    'users_id_requester'          => 2,
    'status'                      => 'requested',
    'date_creation'               => date('Y-m-d H:i:s'),
]);
$ids['evreq_B'] = (int) $DB->insertId();
$DB->insert(\GlpiPlugin\Grcmanager\AuditorAccess::TABLE, [
    'plugin_grcmanager_audits_id' => $ids['audit_B'],
    'users_id'                    => $noRight,
    'date_begin'                  => date('Y-m-d'),
    'date_end'                    => $accessEnd,
    'users_id_grantor'            => 2,
    'date_creation'               => date('Y-m-d H:i:s'),
]);

// Issue #540 : référentiel DORA activé, pour que le registre et ses onglets soient testés.
\GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('dora', true);
// Issue #703 : registre NIS2 Italia - ACN, entité A importante et entité B essentielle.
\GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('acn_it', true);
foreach (['A' => [$a, 'important'], 'B' => [$b, 'essential']] as $suffix => [$entity, $category]) {
    \GlpiPlugin\Grcmanager\AcnRequirement::setCategory($entity, $category);
    $acn = new \GlpiPlugin\Grcmanager\AcnRequirement();
    $acn->getFromDBByCrit(['entities_id' => $entity, 'code' => 'DE.CM-01', 'point' => 2]);
    $ids['acn_' . $suffix] = (int) $acn->getID();
    $ids['acn_entity_' . $suffix] = $entity;
}
// Issue #599 : registre ReCyF (NIS2 France), entité A importante et entité B essentielle.
\GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('nis2', true);
foreach (['A' => [$a, 'important'], 'B' => [$b, 'essential']] as $suffix => [$entity, $category]) {
    \GlpiPlugin\Grcmanager\RecyfRequirement::setCategory($entity, $category);
    $recyf = new \GlpiPlugin\Grcmanager\RecyfRequirement();
    $recyf->getFromDBByCrit(['entities_id' => $entity, 'code' => '10.A.1']);
    $ids['recyf_' . $suffix] = (int) $recyf->getID();
    $ids['recyf_entity_' . $suffix] = $entity;
}
// Issue #732 : exercice de crise terminé dans chaque entité, avec un objectif évalué (bilan à valider).
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $drill = $add(new PluginGrcmanagerSecurityIncident(), [
        'name' => '[EXERCICE] Exercice securite ' . $suffix, 'content' => 'Exercice fictif',
        'entities_id' => $entity, 'is_exercise' => 1,
    ]);
    $DB->insert(\GlpiPlugin\Grcmanager\CrisisExercise::getTable(), [
        'entities_id' => $entity, 'plugin_grcmanager_securityincidents_id' => $drill, 'scenario' => 'outage',
        'users_id' => 2, 'started_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        'ended_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
    ]);
    $ids['exercise_' . $suffix] = (int) $DB->insertId();
    $DB->insert(\GlpiPlugin\Grcmanager\CrisisExerciseReview::OBJECTIVES_TABLE, [
        'plugin_grcmanager_crisisexercises_id' => $ids['exercise_' . $suffix],
        'name' => 'Objectif securite ' . $suffix, 'outcome' => 'met',
    ]);
}
// Issue #542 : revue de direction fictive, pour le dossier préparatoire et ses actions.
$ids['mreview'] = $add(new \GlpiPlugin\Grcmanager\ManagementReview(), [
    'title'       => 'Revue de direction securite',
    'status'      => 'planned',
    'review_date' => date('Y-m-d'),
    'decisions'   => 'Decision securite',
]);
// Issue #546 : un collaborateur fictif dans chaque entité (fiche de conformité sécurité) ; celui de
// A a pour responsable le gestionnaire de A, qui peut le prévenir des écarts.
$collabProfile = new Profile();
$collabProfile->getFromDBByCrit(['name' => 'Securite ISO - sans droit']);
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $collab = $userId('iso-collaborateur-' . strtolower($suffix), $entity, (int) $collabProfile->getID());
    $DB->update('glpi_users', [
        'realname'            => 'Collaborateur securite ' . $suffix,
        'firstname'           => 'Fictif',
        'users_id_supervisor' => $suffix === 'A' ? $manager : 0,
    ], ['id' => $collab]);
    $ids['collab_' . $suffix] = $collab;
    // Issue #774 : une fonction du SMSI dans chaque entité, tenue par le collaborateur, avec une
    // compétence exigée ; la compétence est déclarée pour le collaborateur de B.
    $competenceId = $add(new \GlpiPlugin\Grcmanager\Competence(), [
        'name' => 'Competence securite ' . $suffix, 'entities_id' => $entity,
    ]);
    $ids['ismsfunction_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\IsmsFunction(), [
        'name' => 'Fonction securite ' . $suffix, 'entities_id' => $entity,
    ]);
    (new \GlpiPlugin\Grcmanager\IsmsFunction())->update([
        'id' => $ids['ismsfunction_' . $suffix], '_add_users_id' => $collab,
        '_add_competences_id' => $competenceId, '_add_level' => 2,
    ]);
    $ids['competence_' . $suffix] = $competenceId;
    // Issue #778 : un site fictif (lieu de GLPI) et son évaluation de la sécurité physique, avec une
    // zone, dans chaque entité.
    // Le nom d'un lieu est unique dans son entité : un second passage (test XSS) reprend le même lieu.
    $location = new \Location();
    $siteCriteria = ['name' => 'Site securite ' . $suffix, 'entities_id' => $entity];
    $ids['location_' . $suffix] = $location->getFromDBByCrit($siteCriteria)
        ? $location->getID()
        : $add(new \Location(), [
            'name' => 'Site securite ' . $suffix, 'entities_id' => $entity, 'address' => '1 rue Fictive',
        ]);
    $ids['physical_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\PhysicalSecurityAssessment(), [
        'name' => 'Evaluation securite ' . $suffix, 'entities_id' => $entity,
        'locations_id' => $ids['location_' . $suffix], 'assessment_date' => '2026-01-15',
    ]);
    $ids['physical_line_' . $suffix] = (int) $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_grcmanager_physicalsecuritychecks',
        'WHERE'  => [
            'plugin_grcmanager_physicalsecurityassessments_id' => $ids['physical_' . $suffix], 'code' => 'A.7.1',
        ],
    ])->current()['id'];
    $physical = new \GlpiPlugin\Grcmanager\PhysicalSecurityAssessment();
    $physical->getFromDB($ids['physical_' . $suffix]);
    \GlpiPlugin\Grcmanager\PhysicalSecurityZone::addZone($physical, [
        'name' => 'Salle serveurs securite ' . $suffix, 'zone_type' => 'technical', 'access_means' => ['badge'],
    ]);
    $ids['physical_zone_' . $suffix] = (int) $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_grcmanager_physicalsecurityzones',
        'WHERE'  => ['plugin_grcmanager_physicalsecurityassessments_id' => $ids['physical_' . $suffix]],
    ])->current()['id'];
}
$ids['competencedeclaration_B'] = $add(new \GlpiPlugin\Grcmanager\CompetenceDeclaration(), [
    'users_id' => $ids['collab_B'], 'plugin_grcmanager_competences_id' => $ids['competence_B'],
    'level' => 2, 'evidence_type' => 'certificate', 'evidence_reference' => 'Certificat fictif B',
]);
// Issue #808 : fiche de conformité d'un collaborateur de A, saisie en deux temps comme dans l'écran
// (vérification préalable, puis engagement de confidentialité). Ses journaux (glpi_logs) doivent
// disparaître à la désinstallation : le contrôle commun des restes les cherche.
$screening = ['screening_status' => 'done', 'screening_date' => '2026-01-10'];
$complianceInputs = [$screening, $screening + ['nda_date' => '2026-01-12', 'nda_version' => 'v1']];
foreach ($complianceInputs as $complianceInput) {
    if (!\GlpiPlugin\Grcmanager\PersonnelCompliance::saveRecord($ids['collab_A'], $complianceInput)) {
        fwrite(STDERR, "Fiche de conformite impossible a enregistrer\n");
        exit(1);
    }
}
$compliance = new \GlpiPlugin\Grcmanager\PersonnelCompliance();
$compliance->getFromDBByCrit(['users_id' => $ids['collab_A']]);
$ids['personnelcompliance_A'] = (int) $compliance->getID();
$complianceLogs = [
    'itemtype' => \GlpiPlugin\Grcmanager\PersonnelCompliance::class, 'items_id' => $ids['personnelcompliance_A'],
];
if (countElementsInTable('glpi_logs', $complianceLogs) === 0) {
    fwrite(STDERR, "Fiche de conformite sans historique : le controle des restes ne la verrait pas\n");
    exit(1);
}
// Issue #616 : modèle de message de crise (global) dont la liste de diffusion cite les contacts
// officiels de A et de B, et une communication tracée sur l'incident de chaque entité. Depuis
// l'incident de A, ni le contact ni la communication de B ne doivent apparaître.
$crisisTemplate = new \GlpiPlugin\Grcmanager\CrisisMessageTemplate();
if (!$crisisTemplate->getFromDBByCrit(['name' => 'Modele crise securite'])) {
    $add($crisisTemplate, [
        'name' => 'Modele crise securite', 'situation' => 'major_incident', 'stage' => 'first',
        'audience' => 'external', 'subject' => 'Objet crise {reference}', 'body' => 'Texte crise {title}',
        'is_active' => 1,
    ]);
}
$ids['crisis_template'] = (int) $crisisTemplate->getID();
foreach (['A', 'B'] as $suffix) {
    \GlpiPlugin\Grcmanager\CrisisMessageRecipient::addTo(
        $ids['crisis_template'],
        \GlpiPlugin\Grcmanager\ContextItem::class,
        $ids['context_' . $suffix]
    );
    $crisisCriteria = [
        'itemtype' => PluginGrcmanagerSecurityIncident::class, 'items_id' => $ids['incident_' . $suffix],
    ];
    if (countElementsInTable('glpi_plugin_grcmanager_crisiscommunications', $crisisCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_crisiscommunications', $crisisCriteria + [
            'entities_id'                                 => $suffix === 'A' ? $a : $b,
            'plugin_grcmanager_crisismessagetemplates_id' => $ids['crisis_template'],
            'template_name'                               => 'Modele crise securite',
            'subject'                                     => 'Communication securite ' . $suffix,
            'body'                                        => 'Texte securite ' . $suffix,
            'recipients'                                  => 'Destinataire securite ' . $suffix,
            'users_id'                                    => 2,
            'date_communication'                          => date('Y-m-d H:i:s'),
            'date_creation'                               => date('Y-m-d H:i:s'),
        ]);
    }
}
// Issue #771, lot 2 : une prise de contact tracée avec le contact officiel de A, sur l'incident de
// chaque entité. Celle de l'incident de B ne doit apparaître sur la fiche du contact de A qu'au
// super-admin (les autres ne la voient que comptée).
foreach (['A', 'B'] as $suffix) {
    $traceCriteria = [
        'itemtype' => PluginGrcmanagerSecurityIncident::class, 'items_id' => $ids['incident_' . $suffix],
        'plugin_grcmanager_contextitems_id' => $ids['context_A'],
    ];
    if (countElementsInTable('glpi_plugin_grcmanager_officialcontactnotifications', $traceCriteria) === 0) {
        $DB->insert('glpi_plugin_grcmanager_officialcontactnotifications', $traceCriteria + [
            'entities_id'   => $suffix === 'A' ? $a : $b,
            'decision'      => 'notified',
            'date_notified' => date('Y-m-d H:i:s'),
            'reference'     => 'Dossier securite ' . $suffix,
            'users_id'      => 2,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }
}
// Issue #604 : une campagne de simulation d'hameçonnage dans chaque entité, avec des résultats
// importés. Dans A : le collaborateur de A et iso-sans-droit ont cliqué (ce dernier voit les siens dans
// ses préférences), et quatre adresses fictives restent non rapprochées (6 personnes : assez pour des
// taux anonymes). Dans B : le collaborateur de B a cliqué.
$phishingMail = static function (int $usersId, string $email) use ($DB): void {
    if (countElementsInTable('glpi_useremails', ['users_id' => $usersId, 'email' => $email]) === 0) {
        $DB->insert('glpi_useremails', ['users_id' => $usersId, 'email' => $email, 'is_default' => 1]);
    }
};
$phishingMail($ids['collab_A'], 'collaborateur.a@example.org');
$phishingMail($ids['collab_B'], 'collaborateur.b@example.org');
$phishingMail($noRight, 'sans.droit@example.org');
$phishingLines = [
    'A' => "email,status,reported\ncollaborateur.a@example.org,Clicked Link,false\n"
        . "sans.droit@example.org,Clicked Link,false\ninconnu.securite@example.org,Email Opened,true\n"
        . "autre1.securite@example.org,Email Sent,false\nautre2.securite@example.org,Email Sent,true\n"
        . "autre3.securite@example.org,Email Sent,false\n",
    'B' => "email,status,reported\ncollaborateur.b@example.org,Clicked Link,false\n",
];
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $campaign = new \GlpiPlugin\Grcmanager\PhishingCampaign();
    if (!$campaign->getFromDBByCrit(['name' => 'Campagne hameconnage ' . $suffix])) {
        $add($campaign, [
            'name' => 'Campagne hameconnage ' . $suffix, 'entities_id' => $entity, 'campaign_date' => date('Y-m-d'),
            'tool' => 'GoPhish', 'scenario' => 'Faux avis de livraison', 'target_population' => 'Entite ' . $suffix,
        ]);
        $campaign->getFromDB($campaign->getID());
        $parsed = \GlpiPlugin\Grcmanager\Services\Phishing\PhishingRules::parse($phishingLines[$suffix]);
        \GlpiPlugin\Grcmanager\PhishingResult::import(
            $campaign,
            $parsed['rows'],
            \GlpiPlugin\Grcmanager\Services\Phishing\PhishingRules::guessMapping($parsed['headers']),
            'resultats-fictifs.csv'
        );
    }
    $ids['phishing_' . $suffix] = (int) $campaign->getID();
}
// Issue #778 : mesure A.7.1 de la SoA du siège (preuve des évaluations de la sécurité physique).
$ids['control_a71'] = \GlpiPlugin\Grcmanager\EntityPerimeter::controlIdsByCode(0)['A.7.1'] ?? 0;
// Issue #604 : mesure A.6.3 de la SoA du siège (preuve des simulations d'hameçonnage).
$ids['control_a63'] = \GlpiPlugin\Grcmanager\EntityPerimeter::controlIdsByCode(0)['A.6.3'] ?? 0;
echo json_encode($ids), "\n";
