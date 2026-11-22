<?php

/**
 * Issue #1043 : groupes d'accès distant réglés dans la configuration. Fragment inclus par
 * tests/security/setup.php xss (données piégées).
 */

// Groupe piégé choisi comme groupe d'accès distant : son nom s'affiche dans le bloc de l'onglet
// « Personnes et politiques » et dans le rappel de la page de la couverture MFA.
$DB->insert('glpi_groups', ['name' => $canary, 'entities_id' => 0, 'is_recursive' => 1]);
\GlpiPlugin\Grcmanager\MfaCoverage::setRemoteGroups([(int) $DB->insertId()]);
