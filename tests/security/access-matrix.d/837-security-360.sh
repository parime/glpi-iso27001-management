# shellcheck shell=bash
# Issue #837 : onglet « Securite 360° » de l'ordinateur, lecture seule ; reprend le controleur de
# gestion de A (fragment 843) ; rien sans droit, rien de B.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
s360_tab() { tab Computer 'GlpiPlugin%5CGrcmanager%5CAssetSecurity360$1' "$(id "$1")"; }
if [ "$ROLE" = none ]; then
  contains "onglet securite 360 sans droit : rien" "Controleur BMC securite A" non "$(s360_tab computer_A)"
fi
if [ "$ROLE" = reader ]; then
  contains "onglet securite 360 de l'ordinateur de A : controleur" "Controleur BMC securite A" oui "$(s360_tab computer_A)"
  contains "onglet securite 360 de l'ordinateur de A : rien de B" "BMC securite B" non "$(s360_tab computer_A)"
fi
