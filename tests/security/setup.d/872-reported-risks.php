<?php

/**
 * Issue #872 : risques signales par les collaborateurs. Fragment inclus par tests/security/setup.php
 * (jeu de donnees).
 *
 * - signalement nouveau du compte sans droit, dans A (il le voit dans ses signalements, la file de A
 *   le montre) ;
 * - signalement nouveau du super-admin, dans B (jamais montre aux profils de A ni au compte sans droit) ;
 * - signalement du compte sans droit rattache au risque de A (onglet du risque ; son auteur ne voit
 *   jamais le titre du risque).
 */

$reporter = new User();
$reporter->getFromDBbyName('iso-sans-droit');
$reports = [
    'reportedrisk_A'      => ['Signalement securite A', $a, (int) $reporter->getID(), 'new', 0],
    'reportedrisk_B'      => ['Signalement securite B', $b, 2, 'new', 0],
    'reportedrisk_merged' => [
        'Signalement rattache securite', $a, (int) $reporter->getID(), 'merged', (int) $ids['risk'],
    ],
];
foreach ($reports as $key => [$name, $entity, $author, $status, $riskId]) {
    $row = [
        'name' => $name, 'entities_id' => $entity, 'users_id' => $author, 'status' => $status,
        'plugin_grcmanager_risks_id' => $riskId,
    ];
    $existing = $DB->request([
        'FROM' => 'glpi_plugin_grcmanager_reportedrisks', 'WHERE' => $row, 'LIMIT' => 1,
    ])->current();
    if (is_array($existing)) {
        $ids[$key] = (int) $existing['id'];
        continue;
    }
    $DB->insert('glpi_plugin_grcmanager_reportedrisks', $row + [
        'description'       => 'Situation fictive de controle d acces',
        'severity'          => 'medium',
        'decision_comment'  => $status === 'merged' ? 'Deja suivi' : '',
        'users_id_decision' => $status === 'merged' ? 2 : 0,
        'date_decision'     => $status === 'merged' ? date('Y-m-d H:i:s') : null,
        'date_creation'     => date('Y-m-d H:i:s'),
    ]);
    $ids[$key] = (int) $DB->insertId();
}
