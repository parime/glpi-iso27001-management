# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1043 : groupe d'acces distant piege (bloc de la configuration et rappel de la page MFA).
check "front/config.php?tab=people (groupes d'acces distant)" "$P/config.php?tab=people"
check "front/mfacoverage.php (groupes d'acces distant)"       "$P/mfacoverage.php"
