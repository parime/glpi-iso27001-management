# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #846 : partage, chemin, compte, droit et commentaire de revue pieges (droits sur les partages).
check "front/shareaccess.php"                 "$P/shareaccess.php"
check "fiche d'un partage de fichiers"        "$P/shareaccess.php?share=$(id share_A)"
