<?php

/**
 * Issue #869 : nomenclature, composant et déclaration VEX piégés sur l'application de A (onglet
 * « SBOM et VEX »). Fragment inclus par tests/security/setup.php (jeu XSS).
 */

$sbomA = (int) ($ids['sbom_A'] ?? 0);
$update('sboms', [
    'product_version' => mb_substr($canary, 0, 100), 'document_name' => mb_substr($canary, 0, 255),
    'file_name' => mb_substr($canary, 0, 255), 'spec_version' => mb_substr($canary, 0, 32),
], $sbomA);
$DB->update('glpi_plugin_grcmanager_sbomcomponents', [
    'name' => mb_substr($canary, 0, 255), 'version' => mb_substr($canary, 0, 100), 'purl' => mb_substr($canary, 0, 512),
    'supplier' => mb_substr($canary, 0, 255), 'licenses' => mb_substr($canary, 0, 255),
], ['plugin_grcmanager_sboms_id' => $sbomA]);
$DB->update('glpi_plugin_grcmanager_vexstatements', [
    'detail' => $canary, 'justification' => mb_substr($canary, 0, 64), 'source' => mb_substr($canary, 0, 16),
], ['appliances_id' => (int) $ids['appliance_A']]);
