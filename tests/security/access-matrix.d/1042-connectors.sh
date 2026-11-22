# shellcheck shell=bash
# Issue #1042 : connexions Zabbix, Uptime Kuma, Veeam et serveur DNS dans l'onglet Connecteurs ;
# reglages des sauvegardes dans l'onglet Parc. Les pages d'exploitation gardent les resultats et un
# lien vers la configuration ; leurs anciens envois de reglage restent refuses sans le droit.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
case $ROLE in
  reader)
    page=$(body "$P/config.php?tab=connectors")
    for id in grc-zabbix-config grc-kuma-config grc-veeam-config grc-mail-dns-config; do
      contains "configuration, Connecteurs : bloc $id affiche en lecture" "id=\"$id\"" oui "$page"
    done
    contains "configuration, Connecteurs : aucun enregistrement de connexion en lecture" 'name="save_zabbix"' non "$page"
    contains "configuration, Connecteurs : aucun essai de connexion en lecture" 'name="test_veeam"' non "$page"
    expect "front/config.php : connexion Zabbix en lecture seule" 403 \
      "$(code -d "save_zabbix=1&zabbix_url=https://zabbix.example.test&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : essai Uptime Kuma en lecture seule" 403 \
      "$(code -d "test_uptimekuma=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : serveur DNS en lecture seule" 403 \
      "$(code -d "save_dns_server=1&dns_server=192.0.2.53&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : reglages des sauvegardes en lecture seule" 403 \
      "$(code -d "save_backup_settings=1&backup_scope=all&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    contains "configuration, Parc : reglages des sauvegardes" 'id="grc-backup-settings-config"' oui \
      "$(body "$P/config.php?tab=assets")"
    for p in zabbixmonitoring uptimekuma backupcoverage; do
      contains "front/$p.php : lien vers la configuration" 'config.php?tab=connectors' oui "$(body "$P/$p.php")"
      contains "front/$p.php : plus de champ de secret" 'type="password"' non "$(body "$P/$p.php")"
    done ;;
  limited|admin)
    page=$(body "$P/config.php?tab=connectors")
    contains "configuration, Connecteurs : connexion Zabbix modifiable" 'name="save_zabbix"' oui "$page"
    contains "configuration, Connecteurs : connexion Uptime Kuma modifiable" 'name="save_uptimekuma"' oui "$page"
    contains "configuration, Connecteurs : connexion Veeam modifiable" 'name="save_veeam"' oui "$page"
    contains "configuration, Connecteurs : serveur DNS modifiable" 'name="test_dns_server"' oui "$page"
    contains "configuration, Parc : reglages des sauvegardes modifiables" 'name="save_backup_settings"' oui \
      "$(body "$P/config.php?tab=assets")"
    expect "front/config.php : reglages des sauvegardes" 302 \
      "$(code -d "save_backup_settings=1&backup_scope=critical_servers&backup_max_age_hours=48&backup_restore_months=12&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : serveur DNS sans jeton CSRF" 403 \
      "$(code -d "save_dns_server=1&dns_server=" "$P/config.php")"
    contains "front/maildomainsecurity.php : lien vers la configuration" 'config.php?tab=connectors' oui \
      "$(body "$P/maildomainsecurity.php")" ;;
esac
