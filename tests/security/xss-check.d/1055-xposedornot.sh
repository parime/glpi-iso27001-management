# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1055 : fournisseur de la surveillance des fuites pieges en base (valeur hors liste).
check "front/config.php?tab=connectors (fournisseur des fuites)" "$P/config.php?tab=connectors#grc-breach-config"
