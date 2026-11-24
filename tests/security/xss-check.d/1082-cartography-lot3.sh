# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1082, lot 3 : PC piege vu depuis son VLAN (couche du reseau logique) et depuis son application
# (tableau des applications par environnement, onglet de la fiche de l'application).
check "cartographie du VLAN du PC piege" "$P/cartography.php?itemtype=Vlan&items_id=$(id vlan_A)&direction=up"
check "cartographie de l'application du PC piege" \
  "$P/cartography.php?itemtype=Appliance&items_id=$(id appliance_A)&direction=down"
check "onglet Cartographie de l'application du PC piege" \
  "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CCartography$1' "$(id appliance_A)")"
