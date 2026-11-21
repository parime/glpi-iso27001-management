# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #852 : compte et methode importes pieges (couverture MFA, preuve A.8.5).
check "front/mfacoverage.php"                 "$P/mfacoverage.php"
check "preuve couverture MFA (A.8.5)"         "$P/control.form.php?id=$(xid control_a85)"
