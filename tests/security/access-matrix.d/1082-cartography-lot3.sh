# shellcheck shell=bash
# Issue #1082, lot 3 : couche du reseau logique (VLAN) et environnements des applications dans la
# cartographie du SI (Analyses de risques). Un VLAN ou une application hors des entites n'est jamais
# nomme ; la carte d'une application liste ses serveurs par environnement.
# Page : front/cartography.php (deja declaree par 1082-cartography.sh).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
case $ROLE in
  none|general)
    expect "cartographie d'un VLAN : sans le droit des registres sensibles" 403 \
      "$(code "$P/cartography.php?itemtype=Vlan&items_id=$(id vlan_A)&direction=up")"
    contains "onglet Cartographie de l'application de A : rien sans le droit" "PC securite A" non \
      "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CCartography$1' "$(id appliance_A)")"
    ;;
  reader|limited)
    contains "cartographie du VLAN de B : rien de B" "PC securite B" non \
      "$(body "$P/cartography.php?itemtype=Vlan&items_id=$(id vlan_B)&direction=up")"
    contains "cartographie de l'application de B : rien de B" "PC securite B" non \
      "$(body "$P/cartography.php?itemtype=Appliance&items_id=$(id appliance_B)&direction=down")"
    contains "cartographie du PC de A : VLAN de B jamais nomme" "Vlan securite B" non \
      "$(body "$P/cartography.php?itemtype=Computer&items_id=$(id computer_A)&direction=down")"
    ;;
  admin)
    vlan=$(body "$P/cartography.php?itemtype=Vlan&items_id=$(id vlan_A)&direction=up")
    contains "cartographie : la panne du VLAN de A touche le PC de A" "PC securite A" oui "$vlan"
    contains "cartographie : la panne du VLAN de A touche l'application de A" "Application securite A" oui "$vlan"
    contains "cartographie : la panne du VLAN de A touche le processus de A" "Processus securite A" oui "$vlan"
    contains "cartographie du VLAN de A : rien de B" "PC securite B" non "$vlan"
    app=$(body "$P/cartography.php?itemtype=Appliance&items_id=$(id appliance_A)")
    contains "cartographie de l'application de A : son environnement" "Production securite" oui "$app"
    contains "cartographie de l'application de A : jusqu'au VLAN de A" "Vlan securite A" oui "$app"
    contains "filtre d'environnement : application de A gardee" "Application securite A" oui \
      "$(body "$P/cartography.php?itemtype=Vlan&items_id=$(id vlan_A)&direction=up&environment=$(id environment_production)")"
    contains "onglet Cartographie de l'application de A" "PC securite A" oui \
      "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CCartography$1' "$(id appliance_A)")"
    contains "export CSV de l'application de A : environnement" "Production securite" oui \
      "$(body "$P/cartography.php?itemtype=Appliance&items_id=$(id appliance_A)&direction=down&export=csv")"
    contains "cartographie : points de depart, applications" "Application securite A" oui "$(body "$P/cartography.php")"
    ;;
esac
