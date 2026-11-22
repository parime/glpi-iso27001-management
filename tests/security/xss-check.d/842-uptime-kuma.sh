# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #842 : sonde Uptime Kuma piegee (services exposes, preuve A.8.24).
check "front/uptimekuma.php"                  "$P/uptimekuma.php"
check "preuve services exposes (A.8.24)"      "$P/control.form.php?id=$(xid control_a824)"
