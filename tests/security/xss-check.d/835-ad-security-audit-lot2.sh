# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #835, lot 2 : locataire, controle et constat pieges (detail d'un import Maester, preuve A.8.5).
check "front/adsecurityaudit.php (import Maester)" "$P/adsecurityaudit.php?id=$(xid cloudaudit_A)"
