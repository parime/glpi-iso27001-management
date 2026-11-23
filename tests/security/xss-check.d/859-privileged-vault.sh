# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #859 : element et membre du coffre pieges (comptes a privileges, preuve A.8.2).
check "front/privilegedvault.php"             "$P/privilegedvault.php"
check "preuve comptes a privileges (A.8.2)"   "$P/control.form.php?id=$(xid control_a82)"
