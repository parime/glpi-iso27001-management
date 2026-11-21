# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #835 : domaine, regle et constat pieges (audit de securite de l'AD, preuve A.8.2).
check "front/adsecurityaudit.php"             "$P/adsecurityaudit.php"
check "preuve audit de l'AD (A.8.2)"          "$P/control.form.php?id=$(xid control_a82)"
