# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #836 : hote et probleme Zabbix pieges (supervision Zabbix, preuve A.8.16).
check "front/zabbixmonitoring.php"            "$P/zabbixmonitoring.php"
check "preuve supervision Zabbix (A.8.16)"    "$P/control.form.php?id=$(xid control_a816)"
