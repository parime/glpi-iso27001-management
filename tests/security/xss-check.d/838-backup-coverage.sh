# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #838 : machine sauvegardee, travail et type pieges (couverture des sauvegardes, preuve A.8.13).
check "front/backupcoverage.php"              "$P/backupcoverage.php"
check "preuve couverture des sauvegardes (A.8.13)" "$P/control.form.php?id=$(xid control_a813)"
