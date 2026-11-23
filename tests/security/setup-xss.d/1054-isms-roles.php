<?php

/**
 * Issue #1054 : note « à reprendre » du DPO piégée (reste de l'ancien champ texte), affichée dans
 * la configuration (onglet people) et sur la matrice des responsabilités. Fragment inclus par
 * tests/security/setup.php xss.
 */

\Config::setConfigurationValues('plugin:grcmanager', ['dpo_contact_note' => $canary]);
