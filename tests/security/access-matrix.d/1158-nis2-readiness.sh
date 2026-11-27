# shellcheck shell=bash
# Issue #1158 : parcours de mise en conformite NIS2 (Pilotage), lecture seule, NIS2 active par setup.php.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/nis2readiness.php
if [ "$ROLE" = reader ]; then
  contains "parcours NIS2 : criteres affiches" "grc-nis2-criterion" oui "$(body "$P/nis2readiness.php")"
fi
