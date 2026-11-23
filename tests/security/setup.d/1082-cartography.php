<?php

/**
 * Issue #1082 : cartographie du SI. Fragment inclus par tests/security/setup.php (jeu de données) : un
 * switch par entité (A et B), relié par un câble au PC de son entité. Le PC porte déjà le processus
 * métier de son entité (issue #377) : la panne du switch de A touche le processus de A. Le fragment
 * se relance sans doublon ($add, $insertOnce).
 */

foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $ids['switch_' . $suffix] = $add(new NetworkEquipment(), [
        'name' => 'Switch securite ' . $suffix, 'entities_id' => $entity,
    ]);
    $switchPort = $insertOnce('glpi_networkports', [
        'itemtype' => 'NetworkEquipment', 'items_id' => $ids['switch_' . $suffix], 'entities_id' => $entity,
        'name' => 'Gi1/0/1 securite', 'logical_number' => 1, 'instantiation_type' => 'NetworkPortEthernet',
    ]);
    $computerPort = $insertOnce('glpi_networkports', [
        'itemtype' => 'Computer', 'items_id' => $ids['computer_' . $suffix], 'entities_id' => $entity,
        'name' => 'eth0 securite', 'logical_number' => 1, 'instantiation_type' => 'NetworkPortEthernet',
    ]);
    $insertOnce('glpi_networkports_networkports', [
        'networkports_id_1' => $switchPort, 'networkports_id_2' => $computerPort,
    ]);
}
