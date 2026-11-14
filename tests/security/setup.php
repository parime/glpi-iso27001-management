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
    $out['audit'] = $insert('audits', ['title' => $canary, 'scope' => $canary, 'conclusion' => $canary]);
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
    $out['policy'] = $insert('policies', [
        'title' => $canary, 'description' => $canary, 'version' => '1.0', 'status' => 'approved',
        'approval_date' => date('Y-m-d'),
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
    $insert('auditoraccesses', [
        'plugin_grcmanager_audits_id' => $out['audit'], 'users_id' => 2, 'date_begin' => date('Y-m-d'),
        'date_end' => date('Y-m-d', strtotime('+5 days')), 'users_id_grantor' => 2, 'date_creation' => $now,
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
    $insert('risktreatmentactions', ['plugin_grcmanager_risks_id' => (int) $ids['risk'], 'description' => $canary]);
    $update('risks', ['title' => $canary, 'description' => $canary, 'justification' => $canary], (int) $ids['risk']);
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
        if (in_array($name, ['glpi_plugin_grcmanager_auditlogs', 'glpi_plugin_grcmanager_documentaccesses'], true)) {
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

// Gestionnaire : tous les droits du plugin ; lecteur : lecture seule. Tous deux limités à A.
$manager = $userId('iso-gestionnaire', $a, $profileId('Securite ISO - gestionnaire A', [
    'plugin_grcmanager'                  => ALLSTANDARDRIGHT,
    'plugin_grcmanager_sensitive'        => ALLSTANDARDRIGHT,
    'plugin_grcmanager_gdpr'             => ALLSTANDARDRIGHT,
    'plugin_grcmanager_continuity'       => ALLSTANDARDRIGHT,
    'plugin_grcmanager_securityincident' => ALLSTANDARDRIGHT | CommonITILObject::READALL | CommonITILObject::READMY,
    'computer' => READ, 'contract' => READ, 'supplier' => READ, 'user' => READ, 'document' => READ,
    'software' => READ, 'dashboard' => READ, 'appliance' => READ,
]));
$reader = $userId('iso-lecteur', $a, $profileId('Securite ISO - lecteur A', [
    'plugin_grcmanager'                  => READ,
    'plugin_grcmanager_sensitive'        => READ,
    'plugin_grcmanager_gdpr'             => READ,
    'plugin_grcmanager_continuity'       => READ,
    'plugin_grcmanager_securityincident' => CommonITILObject::READALL,
    'computer' => READ, 'contract' => READ, 'supplier' => READ, 'user' => READ, 'software' => READ,
    'dashboard' => READ, 'appliance' => READ,
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

$control = $DB->request([
    'SELECT' => ['id'],
    'FROM'   => 'glpi_plugin_grcmanager_controls',
    'ORDER'  => 'id',
    'LIMIT'  => 1,
])->current();
if ($control === null) {
    fwrite(STDERR, "Aucun contrôle (annexe A) : installation du plugin incomplète\n");
    exit(1);
}
$controlId = (int) $control['id'];

$supplier = $add(new Supplier(), [
    'name' => 'Fournisseur securite ' . uniqid(), 'entities_id' => 0, 'is_recursive' => 1,
]);
$ids = [
    'a' => $a, 'b' => $b, 'manager' => $manager, 'reader' => $reader, 'control' => $controlId, 'supplier' => $supplier,
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
    // Issue #377, troisième étape : application classable (RTO) de l'entité.
    $ids['appliance_' . $suffix] = $add(new Appliance(), [
        'name' => 'Application securite ' . $suffix, 'entities_id' => $entity,
    ]);
    // Issue #378 : indicateur de risque de l'entité.
    $ids['kri_' . $suffix] = $add(new \GlpiPlugin\Grcmanager\RiskIndicator(), [
        'name' => 'KRI securite ' . $suffix, 'entities_id' => $entity,
        'threshold_warning' => '5', 'threshold_critical' => '10',
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
$ids['risk'] = $add(new \GlpiPlugin\Grcmanager\Risk(), [
    'title' => 'Risque securite', 'linked_items_Computer' => [$ids['computer_B']],
]);
// Registres sensibles : la fiche du contrôle, lisible sans le droit dédié, ne doit pas montrer ce
// risque lié.
$linkWhere = ['plugin_grcmanager_controls_id' => $controlId, 'plugin_grcmanager_risks_id' => (int) $ids['risk']];
if (countElementsInTable('glpi_plugin_grcmanager_controls_risks', $linkWhere) === 0) {
    $DB->insert('glpi_plugin_grcmanager_controls_risks', $linkWhere + ['date_creation' => date('Y-m-d H:i:s')]);
}
// Issue #140 : audit avec équipe, document restreint, note, groupe RSSI et accès historisé, pour
// que le contrôle des restes après désinstallation porte aussi sur ces données.
$ids['audit'] = $add(new \GlpiPlugin\Grcmanager\Audit(), ['title' => 'Audit securite', 'users_id' => 2]);
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
    ['title' => 'Audit hors perimetre securite', 'users_id' => 2]
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

// Issue #540 : référentiel DORA activé, pour que le registre et ses onglets soient testés.
\GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('dora', true);
echo json_encode($ids), "\n";
