# shellcheck shell=bash
# Pages qui donnaient HTTP 500 au parcours avant publication (version 2.80.0) : refus propre en 403
# sans droit (Html::displayRightError() n'existe plus sous GLPI 12), journal d'audit lisible.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
if [ "$ROLE" = none ]; then
  expect "front/accessreviewbmcaccount.php sans droit, en GET" 403 "$(code "$P/accessreviewbmcaccount.php")"
fi
if [ "$ROLE" = admin ]; then
  expect "front/auditlog.php : super-admin" 200 "$(code "$P/auditlog.php")"
fi
