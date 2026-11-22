# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1046 : liste de demarrage de l'onglet General (nom du cadrage de la SoA, etapes) et ecran
# de changement de taille de la matrice, range dans Configuration > Plugins (libelles d'echelle).
check "front/config.php?tab=general (liste de demarrage)" "$P/config.php?tab=general#grc-start-checklist"
check "front/riskscale.php?size=5" "$P/riskscale.php?size=5"
