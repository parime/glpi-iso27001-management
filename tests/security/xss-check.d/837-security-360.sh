# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #837 : resume des sources piegees (controleur de gestion de A) dans l'onglet securite 360°.
check "onglet securite 360 (ordinateur)" \
  "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CAssetSecurity360$1' "$(id computer_A)")"
