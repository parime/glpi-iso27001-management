# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #622 : fiche « usage autorise » piegee sur l'application de A.
check "front/approvedapplication.php"          "$P/approvedapplication.php"
check "onglet Usage autorise d'une application" \
  "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CApprovedApplication$1' "$(id appliance_A)")"
check "onglet Applications autorisees (preferences)" \
  "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CApprovedApplication$1' 2)"
