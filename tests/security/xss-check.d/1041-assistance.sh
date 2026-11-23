# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1041 : page d'accueil de Crise et continuite (noms des ecrans, compteurs) et page d'import
# des alertes EDR (revue SentinelOne, derniere lecture, agents sans ordinateur GLPI).
check "front/crisiscontinuity.php" "$P/crisiscontinuity.php"
check "front/securityincidentedrimport.php" "$P/securityincidentedrimport.php"
check "front/recoveryprogramme.php" "$P/recoveryprogramme.php"
