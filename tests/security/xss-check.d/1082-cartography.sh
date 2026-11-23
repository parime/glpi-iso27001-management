# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1082 : processus et PC pieges dans la cartographie du SI : points de depart, schema SVG
# (infobulles, libelles accessibles) et liste par couche, dans les deux sens.
check "front/cartography.php" "$P/cartography.php"
check "cartographie du processus piege (vers le bas)" \
  "$P/cartography.php?itemtype=GlpiPlugin%5CGrcmanager%5CBusinessProcess&items_id=$(xid process)&direction=down"
check "cartographie du PC piege (vers le haut)" "$P/cartography.php?itemtype=Computer&items_id=$(id computer_A)&direction=up"
