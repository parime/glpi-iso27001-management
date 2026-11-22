# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1039 : Parc > Securite du parc. Page d'accueil, saisie groupee de la fin de vie des modeles
# (modele piege de l'ordinateur de A), onglet « Fin de vie constructeur » de sa fiche, logiciels a
# surveiller (correspondances endoflife.date sorties de la configuration).
check "front/assetsecurity.php"     "$P/assetsecurity.php"
check "front/hardwaremodeleol.php"  "$P/hardwaremodeleol.php"
check "front/softwarewatch.php (fin de support)" "$P/softwarewatch.php"
check "onglet fin de vie constructeur (modele)" \
  "$(tab ComputerModel 'GlpiPlugin%5CGrcmanager%5CHardwareModelEol$1' "$(xid hw_model_xss)")"
