# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #858 : depot et alertes pieges sur l'application de A (securite du code, onglet, preuve A.8.28).
check "front/codesecurity.php"                "$P/codesecurity.php"
check "onglet Securite du code d'une application" \
  "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CCodeSecurity$1' "$(id appliance_A)")"
check "preuve securite du code (A.8.28)"      "$P/control.form.php?id=$(xid control_a828)"
