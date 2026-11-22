# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #843, lot 3 : composant et evenement du journal pieges (onglet de l'ordinateur).
check "onglet controleur de gestion, sante (ordinateur)" \
  "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CServerManagement$1' "$(id computer_A)")"
