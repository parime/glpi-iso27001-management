<?php

/**
 * Issue #1039 : Parc > Sécurité du parc. Fragment inclus par tests/security/setup.php (jeu de
 * données) : un modèle d'ordinateur pour l'onglet « Fin de vie constructeur » des fiches de modèles.
 * Les modèles de GLPI ne sont pas rattachés à une entité ; le fragment se relance sans doublon.
 */

$hwModel = new ComputerModel();
if (!$hwModel->getFromDBByCrit(['name' => 'Modele securite parc A'])) {
    $hwModel->add(['name' => 'Modele securite parc A']);
}
$ids['hw_model_A'] = (int) $hwModel->getID();
