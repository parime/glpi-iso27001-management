<?php

/**
 * Issue #1082, lot 3 : couche du réseau logique et environnements des applications. Fragment inclus par
 * tests/security/setup.php (jeu de données) : un VLAN par entité (A et B) où se trouve le PC de son
 * entité, et une application de production par entité, portée par le PC. Le PC porte déjà le processus
 * métier de son entité (issue #377) : la panne du VLAN de A touche le processus de A. Le fragment se
 * relance sans doublon ($add, $insertOnce).
 */

$ids['environment_production'] = $add(new ApplianceEnvironment(), ['name' => 'Production securite']);
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $ids['vlan_' . $suffix] = $add(new Vlan(), [
        'name' => 'Vlan securite ' . $suffix, 'entities_id' => $entity, 'tag' => $suffix === 'A' ? 1082 : 1083,
    ]);
    $vlanPort = $insertOnce('glpi_networkports', [
        'itemtype' => 'Computer', 'items_id' => $ids['computer_' . $suffix], 'entities_id' => $entity,
        'name' => 'eth1 vlan securite', 'logical_number' => 2, 'instantiation_type' => 'NetworkPortEthernet',
    ]);
    $insertOnce('glpi_networkports_vlans', ['networkports_id' => $vlanPort, 'vlans_id' => $ids['vlan_' . $suffix]]);
    $ids['appliance_' . $suffix] = $add(new Appliance(), [
        'name' => 'Application securite ' . $suffix, 'entities_id' => $entity,
        'applianceenvironments_id' => $ids['environment_production'],
    ]);
    $insertOnce('glpi_appliances_items', [
        'appliances_id' => $ids['appliance_' . $suffix], 'itemtype' => 'Computer', 'items_id' => $ids['computer_' . $suffix],
    ]);
}
