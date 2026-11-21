<?php

/**
 * Issue #603 : maturité par mesure. Fragment inclus par tests/security/setup.php xss (données piégées).
 */

// Issue #603 : campagne de maturité de A piégée (fiche, grille, écarts, comparaison, PDF, brouillon
// d'action), avec un libellé d'échelle piégé dans la configuration du plugin.
$update('maturitycampaigns', ['name' => $canary, 'comment' => $canary], (int) $ids['maturity_A']);
$update('maturityassessments', ['justification' => $canary], (int) $ids['maturity_line_A']);
\Config::setConfigurationValues('plugin:grcmanager', ['maturity_scale_labels' => json_encode([1 => $canary])]);
