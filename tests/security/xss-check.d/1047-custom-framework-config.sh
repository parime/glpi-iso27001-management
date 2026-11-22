# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1047 : liste des referentiels personnalises (nom piege) dans la configuration, onglet
# Referentiels, et nouvelle version de ce referentiel.
check "front/config.php?tab=frameworks" "$P/config.php?tab=frameworks"
check "front/config.php?tab=frameworks&cf_target=" "$P/config.php?tab=frameworks&cf_target=$(xid customframework)"
