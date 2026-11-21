# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #843 : controleur, firmware et compte local pieges (page, onglet de l'ordinateur, preuve A.8.2).
check "front/servermanagement.php"            "$P/servermanagement.php"
check "onglet controleur de gestion (ordinateur)" \
  "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CServerManagement$1' "$(id computer_A)")"
check "preuve controleurs de gestion (A.8.2)" "$P/control.form.php?id=$(xid control_a82)"
