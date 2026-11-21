# shellcheck shell=bash
# Issue #838 : couverture des sauvegardes, machines et travaux de l'entite A seulement ; reglages,
# connecteur Veeam, lecture et import en lecture seule ; reglages du gestionnaire avec jeton CSRF.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/backupcoverage.php
if [ "$ROLE" = reader ]; then
  contains "sauvegardes : machine de A" "Hote sauvegarde securite A" oui "$(body "$P/backupcoverage.php")"
  contains "sauvegardes : machine de B absente" "Hote sauvegarde securite B" non "$(body "$P/backupcoverage.php")"
  contains "sauvegardes : travail de B absent" "Travail sauvegarde securite B" non "$(body "$P/backupcoverage.php")"
  expect "front/backupcoverage.php : modifier les reglages" 403 \
    "$(code -d "save_backup_settings=1&backup_scope=all&_glpi_csrf_token=$(csrf)" "$P/backupcoverage.php")"
  expect "front/backupcoverage.php : regler Veeam en lecture seule" 403 \
    "$(code -d "save_veeam=1&veeam_url=https%3A%2F%2Fveeam.example.test&_glpi_csrf_token=$(csrf)" "$P/backupcoverage.php")"
  expect "front/backupcoverage.php : lire Veeam en lecture seule" 403 \
    "$(code -d "fetch_veeam=1&_glpi_csrf_token=$(csrf)" "$P/backupcoverage.php")"
  expect "front/backupcoverage.php : effacer un import en lecture seule" 403 \
    "$(code -d "clear_backup_hosts=1&source=acronis&_glpi_csrf_token=$(csrf)" "$P/backupcoverage.php")"
fi
if [ "$ROLE" = limited ]; then
  expect "front/backupcoverage.php : reglages" 302 \
    "$(code -d "save_backup_settings=1&backup_scope=critical_servers&backup_max_age_hours=48&backup_restore_months=12&_glpi_csrf_token=$(csrf)" "$P/backupcoverage.php")"
  expect "front/backupcoverage.php : reglages sans jeton CSRF" 403 \
    "$(code -d "save_backup_settings=1&backup_scope=all" "$P/backupcoverage.php")"
fi
