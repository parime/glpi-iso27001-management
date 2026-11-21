# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #869 : nomenclature, composant et declaration VEX pieges sur l'application de A.
check "onglet SBOM et VEX d'une application" \
  "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CProductSbom$1' "$(id appliance_A)")"
