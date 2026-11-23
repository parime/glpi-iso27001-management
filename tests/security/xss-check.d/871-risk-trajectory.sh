# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #871 : titre du risque et action du plan pieges (onglet et carte du registre).
check "front/riskheatmap.php (trajectoire du registre)" "$P/riskheatmap.php"
check "onglet Trajectoire projetee du risque" \
  "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CRiskTrajectory$1' "$(xid trajectory_risk)")"
