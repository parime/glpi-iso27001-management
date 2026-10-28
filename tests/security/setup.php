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
    $out['policy'] = $insert('policies', ['title' => $canary, 'description' => $canary]);
    $out['training'] = $insert('trainings', [
        'title' => $canary, 'description' => $canary, 'target_audience' => $canary,
    ]);
    $out['product'] = $insert('canonicalproducts', ['manufacturer' => $canary, 'product' => $canary]);
    $insert('cpereferences', ['plugin_grcmanager_canonicalproducts_id' => $out['product'], 'cpe' => $canary]);
    $insert('productaliases', ['plugin_grcmanager_canonicalproducts_id' => $out['product'], 'alias' => $canary]);
    $insert('risktreatmentactions', ['plugin_grcmanager_risks_id' => (int) $ids['risk'], 'description' => $canary]);
    $update('risks', ['title' => $canary, 'description' => $canary, 'justification' => $canary], (int) $ids['risk']);
    $update('controls', ['justification' => $canary], (int) $ids['control']);
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
    // Issue #145 : nom du document de preuve, affiché par l'onglet et la liste des validités.
    $DB->update('glpi_documents', ['name' => $canary], ['id' => (int) $ids['document_A']]);
    echo json_encode($out), "\n";
    exit(0);
}

if ($mode === 'counts') {
    // Nombre de lignes de chaque table du plugin : un lecteur ne doit rien créer ni supprimer.
    $counts = [];
    foreach ($DB->listTables('glpi_plugin_grcmanager_%') as $table) {
        $name = $table['TABLE_NAME'];
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
    'plugin_grcmanager_securityincident' => ALLSTANDARDRIGHT | CommonITILObject::READALL | CommonITILObject::READMY,
    'computer' => READ, 'contract' => READ, 'supplier' => READ, 'user' => READ, 'document' => READ,
    'software' => READ,
]));
$reader = $userId('iso-lecteur', $a, $profileId('Securite ISO - lecteur A', [
    'plugin_grcmanager'                  => READ,
    'plugin_grcmanager_sensitive'        => READ,
    'plugin_grcmanager_securityincident' => CommonITILObject::READALL,
    'computer' => READ, 'contract' => READ, 'supplier' => READ, 'user' => READ, 'software' => READ,
]));

// Sans droit : aucun droit sur le plugin, chaque page doit lui être refusée. Il lit les
// ordinateurs, comme un technicien : les onglets du plugin sur un ordinateur ne lui montrent rien
// (revue de sécurité 2.11.0, contenu d'onglet demandé directement).
$noRight = $userId('iso-sans-droit', $a, $profileId('Securite ISO - sans droit', [
    'plugin_grcmanager'                  => 0,
    'plugin_grcmanager_sensitive'        => 0,
    'plugin_grcmanager_securityincident' => 0,
    'computer'                           => READ,
    'software'                           => READ,
]));

// Registres sensibles : tout le SMSI en gestion, mais pas le droit dédié (risques, vulnérabilités du
// parc, flux réseau, analyse d'impact) : ces écrans, cartes et titres de risques lui sont refusés.
$general = $userId('iso-general', $a, $profileId('Securite ISO - sans registres sensibles', [
    'plugin_grcmanager'                  => ALLSTANDARDRIGHT,
    'plugin_grcmanager_sensitive'        => 0,
    'plugin_grcmanager_securityincident' => 0,
    'computer' => READ, 'software' => READ, 'supplier' => READ, 'contract' => READ, 'user' => READ,
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
$ids = ['a' => $a, 'b' => $b, 'manager' => $manager, 'reader' => $reader, 'control' => $controlId];
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $ids['incident_' . $suffix] = $add(new PluginGrcmanagerSecurityIncident(), [
        'name'        => 'Incident securite ' . $suffix,
        'content'     => 'Test de controle d acces',
        'entities_id' => $entity,
    ]);
    $ids['campaign_' . $suffix] = $add(new PluginGrcmanagerAccessReview(), [
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
    $ids['computer_' . $suffix] = $add(new Computer(), ['name' => 'PC securite ' . $suffix, 'entities_id' => $entity]);
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
    $add(new \GlpiPlugin\Grcmanager\Evidence(), [
        'itemtype' => \GlpiPlugin\Grcmanager\Policy::class, 'items_id' => $ids['policy_' . $suffix],
        'documents_id' => $ids['document_' . $suffix], 'valid_until' => date('Y-m-d', strtotime('+10 days')),
    ]);
}
$ids['supplierrisk'] = $add(new \GlpiPlugin\Grcmanager\SupplierRisk(), [
    'title' => 'Risque fournisseur securite', 'suppliers_id' => $supplier,
]);
$ids['training'] = $add(new PluginGrcmanagerTraining(), ['title' => 'Formation securite', 'participants' => [2]]);
// Issue #139 : copie de quiz de glpi, lisible par lui seul (ni formateur ni groupe RSSI ici).
$ids['quiz'] = PluginGrcmanagerTrainingQuiz::getParticipationId($ids['training'], 2);
$quizPrefix = uniqid('', true);
file_put_contents(GLPI_TMP_DIR . '/' . $quizPrefix . 'copie-quiz.txt', "Copie de quiz securite\n");
PluginGrcmanagerTrainingQuiz::attach($ids['quiz'], $quizPrefix . 'copie-quiz.txt', $quizPrefix);
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
$ids['audit'] = $add(new PluginGrcmanagerAudit(), ['title' => 'Audit securite', 'users_id' => 2]);
// Vrai fichier : document.send.php répond 404 avant tout contrôle d'accès sans lui.
$auditFile = 'plan-audit-securite-' . uniqid() . '.txt';
file_put_contents(GLPI_TMP_DIR . '/' . $auditFile, "Plan d'audit securite\n");
$ids['audit_document'] = $add(new Document(), [
    'name' => 'Plan d\'audit securite', 'entities_id' => 0, 'is_recursive' => 1,
    '_filename' => [$auditFile], '_tag_filename' => [$auditFile],
]);
$add(new Document_Item(), [
    'documents_id' => $ids['audit_document'], 'itemtype' => PluginGrcmanagerAudit::class, 'items_id' => $ids['audit'],
]);
PluginGrcmanagerAuditTeam::addMember($ids['audit'], 2, PluginGrcmanagerAuditTeam::ROLE_AUDITEE);
$ids['ciso_group'] = $add(new Group(), ['name' => 'RSSI securite', 'entities_id' => 0, 'is_recursive' => 1]);
PluginGrcmanagerAuditTeam::setCisoGroupId($ids['ciso_group']);
$add(new Notepad(), [
    'itemtype' => PluginGrcmanagerAudit::class, 'items_id' => $ids['audit'], 'content' => 'Note de travail',
]);
PluginGrcmanagerDocumentAccess::log($ids['audit_document'], PluginGrcmanagerAudit::class, $ids['audit'], 2);

echo json_encode($ids), "\n";
