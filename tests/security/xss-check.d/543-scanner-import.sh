# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #543 : constats et import de scanner pieges (page des rapports de scanners, preuve A.8.8).
check "front/scannerimport.php"                     "$P/scannerimport.php"
check "front/scannerimport.php (tous les statuts)"  "$P/scannerimport.php?status=all"
check "preuve rapports de scanners (A.8.8)"         "$P/control.form.php?id=$(xid control_a88)"
