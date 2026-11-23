<?php

/**
 * Issue #1055 : fournisseur de la surveillance des fuites piégé en base (valeur hors de la liste
 * autorisée) : l'onglet Connecteurs ne doit ni le rendre ni l'exécuter.
 * Fragment inclus par tests/security/setup.php (jeu XSS).
 */

Config::setConfigurationValues('plugin:grcmanager', ['breach_provider' => $canary]);
