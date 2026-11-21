<?php

/**
 * Issue #869 : nomenclature (SBOM) avec un composant, une vulnérabilité citée et une déclaration VEX,
 * pour l'application de A et celle de B. Fragment inclus par tests/security/setup.php (jeu de données).
 */

foreach (['A', 'B'] as $suffix) {
    $appliance = (int) $ids['appliance_' . $suffix];
    $sbom = $DB->request([
        'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_sboms', 'WHERE' => ['appliances_id' => $appliance],
    ])->current();
    if ($sbom === null) {
        $DB->insert('glpi_plugin_grcmanager_sboms', [
            'appliances_id' => $appliance, 'product_version' => '1.0.' . $suffix, 'format' => 'cyclonedx',
            'spec_version' => '1.5', 'document_name' => 'SBOM securite ' . $suffix, 'file_name' => 'sbom.json',
            'components' => 1, 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $ids['sbom_' . $suffix] = (int) $DB->insertId();
        $DB->insert('glpi_plugin_grcmanager_sbomcomponents', [
            'plugin_grcmanager_sboms_id' => $ids['sbom_' . $suffix], 'bom_ref' => 'ref-' . $suffix,
            'name' => 'Composant SBOM securite ' . $suffix, 'version' => '1.0', 'type' => 'library',
        ]);
        $DB->insert('glpi_plugin_grcmanager_sbomvulnerabilities', [
            'plugin_grcmanager_sboms_id' => $ids['sbom_' . $suffix],
            'plugin_grcmanager_sbomcomponents_id' => (int) $DB->insertId(), 'vulnerability_id' => 'CVE-2024-0869',
        ]);
        $DB->insert('glpi_plugin_grcmanager_vexstatements', [
            'appliances_id' => $appliance, 'vulnerability_id' => 'CVE-2024-0869', 'component' => 'ref-' . $suffix,
            'status' => 'not_affected', 'detail' => 'Declaration VEX securite ' . $suffix, 'source' => 'manual',
            'date_mod' => date('Y-m-d H:i:s'),
        ]);
    } else {
        $ids['sbom_' . $suffix] = (int) $sbom['id'];
    }
    $vex = $DB->request([
        'SELECT' => 'id', 'FROM' => 'glpi_plugin_grcmanager_vexstatements', 'WHERE' => ['appliances_id' => $appliance],
        'LIMIT' => 1,
    ])->current();
    $ids['vex_' . $suffix] = $vex !== null ? (int) $vex['id'] : 0;
}
