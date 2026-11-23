# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1036 : chaque onglet de la page de configuration (libelles d'echelle, contact du DPO,
# domaines et types saisis librement).
for t in general frameworks risks controls people incidents connectors assets; do
  check "front/config.php?tab=$t" "$P/config.php?tab=$t"
done
