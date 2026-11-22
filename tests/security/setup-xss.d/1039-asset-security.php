<?php

/**
 * Issue #1039 : Parc > Sécurité du parc. Fragment inclus par tests/security/setup.php xss : modèle
 * piégé de l'ordinateur de A (créé par le jeu XSS, issue #782), pour l'onglet « Fin de vie
 * constructeur » de sa fiche.
 */

$xssComputer = new Computer();
$out['hw_model_xss'] = $xssComputer->getFromDB((int) $ids['computer_A'])
    ? (int) $xssComputer->fields['computermodels_id']
    : 0;
