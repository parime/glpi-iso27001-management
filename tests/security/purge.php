<?php

/**
 * Issue #1002 : purge du jeu de données des tests de sécurité de bout en bout (setup.php et
 * setup.d/), pour remettre une instance de test partagée au propre.
 *
 * Supprime définitivement :
 *   - les entités « Securite ISO A » et « Securite ISO B », leurs sous-entités et tout ce qu'elles
 *     contiennent (toutes les tables qui ont une colonne entities_id) ;
 *   - les comptes « iso-... » et les profils « Securite ISO - ... » ;
 *   - à la racine et dans les tables du plugin, les objets dont le nom ou le titre contient le mot
 *     « securite » (sans accent, en minuscules, comme le jeu de données), la charge XSS de
 *     « setup.php xss » et le gabarit « Gabarit test 980 ».
 * Les données réelles (« sécurité » avec accents, connexions « Labo réel - ... ») ne sont pas touchées.
 *
 * Usage (en www-data, depuis la racine GLPI) :
 *   php plugins/grcmanager/tests/security/purge.php [--dry-run]
 * Affiche en JSON le nombre de lignes supprimées (ou à supprimer avec --dry-run) par table.
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
Session::start();
if (!(new Auth())->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Connexion glpi/glpi impossible\n");
    exit(1);
}
Session::changeActiveEntities(0, true);

global $DB;

$dryRun = in_array('--dry-run', $argv, true);
$done = [];
$purge = static function (string $table, int $id) use ($DB, $dryRun, &$done): void {
    $done[$table] = ($done[$table] ?? 0) + 1;
    if ($dryRun) {
        return;
    }
    // Par l'objet GLPI quand il existe : ses liens (documents, journaux, éléments liés) partent avec.
    $type = getItemTypeForTable($table);
    if (is_string($type) && is_a($type, CommonDBTM::class, true) && !is_a($type, Entity::class, true)) {
        $item = new $type();
        if ($item->getFromDB($id) && $item->delete(['id' => $id], true)) {
            return;
        }
    }
    $DB->delete($table, ['id' => $id]);
};
$isTestValue = static function (string $value): bool {
    return preg_match('/\bsecurite\b/', $value) === 1
        || str_starts_with($value, 'Securite ISO')
        || str_contains($value, 'xss-canary')
        || $value === 'Gabarit test 980';
};

// Entités du jeu de données et leurs sous-entités.
$entities = [];
foreach (['Securite ISO A', 'Securite ISO B'] as $name) {
    $entity = new Entity();
    if ($entity->getFromDBByCrit(['name' => $name, 'entities_id' => 0])) {
        $entities = array_merge($entities, array_values(getSonsOf('glpi_entities', (int) $entity->getID())));
    }
}
$entities = array_values(array_unique(array_map('intval', $entities)));

// Comptes et profils de test (leurs habilitations partent avec eux).
$testUsers = ['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', 'iso-%']]];
foreach ($DB->request($testUsers) as $row) {
    $purge('glpi_users', (int) $row['id']);
}
$testProfiles = [
    'SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'WHERE' => ['name' => ['LIKE', 'Securite ISO - %']],
];
foreach ($DB->request($testProfiles) as $row) {
    if (str_starts_with((string) $row['name'], 'Securite ISO - ')) {
        $purge('glpi_profiles', (int) $row['id']);
    }
}

foreach ($DB->listTables('glpi_%') as $tableRow) {
    $table = $tableRow['TABLE_NAME'];
    if ($table === 'glpi_entities' || !$DB->fieldExists($table, 'id')) {
        continue;
    }
    $hasEntity = $DB->fieldExists($table, 'entities_id');
    // Contenu des entités du jeu de données.
    if ($hasEntity && $entities !== []) {
        $inEntities = ['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => ['entities_id' => $entities]];
        foreach ($DB->request($inEntities) as $row) {
            $purge($table, (int) $row['id']);
        }
    }
    // Objets nommés du jeu de données, à la racine ou sans entité (tables du plugin).
    $isPlugin = str_starts_with($table, 'glpi_plugin_grcmanager_');
    if (!$isPlugin && !$hasEntity) {
        continue;
    }
    foreach (['name', 'title'] as $field) {
        if (!$DB->fieldExists($table, $field)) {
            continue;
        }
        $where = ['OR' => [
            [$field => ['LIKE', '%securite%']],
            [$field => ['LIKE', 'Securite ISO%']],
            [$field => ['LIKE', '%xss-canary%']],
            [$field => 'Gabarit test 980'],
        ]];
        if ($hasEntity) {
            $where['entities_id'] = 0;
        }
        foreach ($DB->request(['SELECT' => ['id', $field], 'FROM' => $table, 'WHERE' => $where]) as $row) {
            // La comparaison SQL ignore les accents et la casse : filtre exact ici.
            if ($isTestValue((string) $row[$field])) {
                $purge($table, (int) $row['id']);
            }
        }
    }
}

// Entités en dernier, des plus profondes aux plus hautes.
$entityItem = new Entity();
foreach (array_reverse($entities) as $entityId) {
    $done['glpi_entities'] = ($done['glpi_entities'] ?? 0) + 1;
    if (!$dryRun && !$entityItem->delete(['id' => $entityId], true)) {
        $DB->delete('glpi_entities', ['id' => $entityId]);
    }
}

ksort($done);
echo json_encode($done), "\n";
