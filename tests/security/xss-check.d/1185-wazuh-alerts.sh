# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1185 : incident cree depuis une alerte Wazuh piegee (regle et agent), titre et description.
check "incident cree depuis des alertes Wazuh" "$P/securityincident.form.php?id=$(xid wazuh_incident)"
check "page de l'import des alertes EDR (revue Wazuh)" "$P/securityincidentedrimport.php"
