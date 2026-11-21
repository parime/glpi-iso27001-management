# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #835, lot 3 : compte expose piege (detail d'un import de chemins d'attaque).
check "front/adsecurityaudit.php (chemins d'attaque)" "$P/adsecurityaudit.php?path=$(xid attackpath_A)"
