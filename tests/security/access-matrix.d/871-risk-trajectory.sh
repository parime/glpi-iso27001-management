# shellcheck shell=bash
# Issue #871 : trajectoire projetee du risque. Lecture seule : l'onglet du risque et la carte du
# registre (cartographie) exigent la lecture du registre des risques, dans les entites de la session.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
see_risks=non; case $ROLE in reader|limited|admin) see_risks=oui ;; esac
heatmap=$(curl -s -c "$JAR" -b "$JAR" "$P/riskheatmap.php")
contains "trajectoire du registre : risque de A" "Trajectoire securite A" $see_risks "$heatmap"
contains "trajectoire du registre : risque de B" "Trajectoire securite B" $SEE_B "$heatmap"
contains "onglet Trajectoire du risque de A : action en retard" "Action trajectoire securite en retard" $see_risks \
  "$(tab 'GlpiPlugin%5CGrcmanager%5CRisk' 'GlpiPlugin%5CGrcmanager%5CRiskTrajectory$1' "$(id trajectory_risk_A)")"
contains "onglet Trajectoire du risque de B" "Action trajectoire securite B" $SEE_B \
  "$(tab 'GlpiPlugin%5CGrcmanager%5CRisk' 'GlpiPlugin%5CGrcmanager%5CRiskTrajectory$1' "$(id trajectory_risk_B)")"
