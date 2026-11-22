# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #855 : domaine, commentaire, selecteur DKIM et enregistrements pieges (securite de la messagerie).
check "front/maildomainsecurity.php"          "$P/maildomainsecurity.php"
check "preuve messagerie (A.5.14)"            "$P/control.form.php?id=$(xid control_a514)"
