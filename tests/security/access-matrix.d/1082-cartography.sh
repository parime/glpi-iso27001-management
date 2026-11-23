# shellcheck shell=bash
# Issue #1082 : cartographie du SI par couches (Analyses de risques). Registre sensible, comme
# l'analyse d'impact : refusee sans droit et sans le droit des registres sensibles. Un element hors
# des entites ou sans droit de lecture n'est jamais nomme (switch sans le droit du reseau, PC et
# processus de B pour un profil limite a A).
# Page : front/cartography.php.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/cartography.php
map() { # type, id, sens
  body "$P/cartography.php?itemtype=$1&items_id=$2&direction=$3"
}
PROCESS_TYPE=GlpiPlugin%5CGrcmanager%5CBusinessProcess
case $ROLE in
  none|general)
    expect "front/cartography.php : sans le droit des registres sensibles" 403 "$(code "$P/cartography.php")"
    expect "cartographie d'un processus : sans le droit des registres sensibles" 403 \
      "$(code "$P/cartography.php?itemtype=$PROCESS_TYPE&items_id=$(id process_A)&direction=down")"
    ;;
  reader|limited)
    up=$(map Computer "$(id computer_A)" up)
    contains "cartographie : la panne du PC de A touche le processus de A" "Processus securite A" oui "$up"
    down=$(map "$PROCESS_TYPE" "$(id process_A)" down)
    contains "cartographie du processus de A : PC de A" "PC securite A" oui "$down"
    contains "cartographie du processus de A : PC de B jamais nomme" "PC securite B" non "$down"
    contains "cartographie du processus de A : processus de B jamais nomme" "Processus securite B" non "$down"
    contains "cartographie du processus de A : switch sans le droit du reseau" "Switch securite A" non "$down"
    contains "cartographie depuis le PC de B : rien de B" "Processus securite B" non \
      "$(map Computer "$(id computer_B)" up)"
    contains "cartographie : type hors liste blanche" "Processus securite" non "$(map User 2 up)"
    ;;
  admin)
    up=$(map NetworkEquipment "$(id switch_A)" up)
    contains "cartographie : la panne du switch de A touche le PC de A" "PC securite A" oui "$up"
    contains "cartographie : la panne du switch de A touche le processus de A" "Processus securite A" oui "$up"
    down=$(map "$PROCESS_TYPE" "$(id process_A)" down)
    contains "cartographie du processus de A : processus amont de B" "Processus securite B" oui "$down"
    contains "cartographie du processus de A : jusqu'au switch de A" "Switch securite A" oui "$down"
    contains "cartographie : points de depart, processus" "Processus securite A" oui "$(body "$P/cartography.php")"
    contains "analyses de risques : onglet de la cartographie" "front/cartography.php" oui \
      "$(body "$P/impactanalysis.php")"
    ;;
esac
