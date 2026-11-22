# shellcheck shell=bash
# Issue #843, lot 3 : sante detaillee et journal du controleur de A seulement, dans l'onglet de
# l'ordinateur de A ; rien sans droit.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
bmc_health_tab() { tab Computer 'GlpiPlugin%5CGrcmanager%5CServerManagement$1' "$(id computer_A)"; }
if [ "$ROLE" = none ]; then
  contains "sante du serveur sans droit : rien" "Alimentation BMC securite A" non "$(bmc_health_tab)"
fi
if [ "$ROLE" = reader ]; then
  contains "sante du serveur de A : composant" "Alimentation BMC securite A" oui "$(bmc_health_tab)"
  contains "sante du serveur de A : journal" "Evenement BMC securite A" oui "$(bmc_health_tab)"
  contains "sante du serveur de A : rien de B" "BMC securite B" non "$(bmc_health_tab)"
fi
