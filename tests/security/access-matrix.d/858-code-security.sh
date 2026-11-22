# shellcheck shell=bash
# Issue #858 : securite du code (alertes GitHub et GitLab en lecture seule). Page et onglet : droit du
# plugin ET lecture des applications, alertes des applications visibles (entite) seulement ;
# connexion, lecture et effacement : mise a jour du plugin en plus.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/codesecurity.php
case $ROLE in
  none|general) CS_TAB=non ;;
  *)            CS_TAB=oui ;;
esac
cs_tab() {
  tab Appliance 'GlpiPlugin%5CGrcmanager%5CCodeSecurity$1' "$(id "appliance_$1")"
}
contains "onglet Securite du code d'une application de A" "Alerte code securite A" $CS_TAB "$(cs_tab A)"
contains "onglet Securite du code d'une application de B" "Alerte code securite B" $SEE_B "$(cs_tab B)"
if [ "$ROLE" = reader ]; then
  contains "securite du code : alerte de A" "Alerte code securite A" oui "$(body "$P/codesecurity.php")"
  contains "securite du code : rien de B" "Alerte code securite B" non "$(body "$P/codesecurity.php")"
  expect "front/codesecurity.php : connexion en lecture seule" 403 \
    "$(code -d "save_codesecurity=1&codesecurity_gitlab_url=https://gitlab.example.test&_glpi_csrf_token=$(csrf)" "$P/codesecurity.php")"
  expect "front/codesecurity.php : lecture en lecture seule" 403 \
    "$(code -d "read_codesecurity=1&_glpi_csrf_token=$(csrf)" "$P/codesecurity.php")"
  expect "front/codesecurity.php : effacement en lecture seule" 403 \
    "$(code -d "clear_codesecurity=1&_glpi_csrf_token=$(csrf)" "$P/codesecurity.php")"
fi
