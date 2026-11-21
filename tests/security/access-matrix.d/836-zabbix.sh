# shellcheck shell=bash
# Issue #836 : supervision Zabbix, hote et probleme lus de l'entite A seulement ; connexion,
# lecture et effacement en lecture seule.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/zabbixmonitoring.php
if [ "$ROLE" = reader ]; then
  contains "supervision Zabbix : hote de A" "Hote Zabbix securite A" oui "$(body "$P/zabbixmonitoring.php")"
  contains "supervision Zabbix : probleme de A" "Probleme Zabbix securite A" oui "$(body "$P/zabbixmonitoring.php")"
  contains "supervision Zabbix : rien de B" "Zabbix securite B" non "$(body "$P/zabbixmonitoring.php")"
  expect "front/zabbixmonitoring.php : connexion en lecture seule" 403 \
    "$(code -d "save_zabbix=1&zabbix_url=https://zabbix.example.test&_glpi_csrf_token=$(csrf)" "$P/zabbixmonitoring.php")"
  expect "front/zabbixmonitoring.php : lecture en lecture seule" 403 \
    "$(code -d "read_zabbix=1&_glpi_csrf_token=$(csrf)" "$P/zabbixmonitoring.php")"
  expect "front/zabbixmonitoring.php : effacement en lecture seule" 403 \
    "$(code -d "clear_zabbix=1&_glpi_csrf_token=$(csrf)" "$P/zabbixmonitoring.php")"
fi
