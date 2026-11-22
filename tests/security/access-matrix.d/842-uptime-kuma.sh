# shellcheck shell=bash
# Issue #842 : services exposes (Uptime Kuma), sonde lue de l'entite A seulement ; connexion,
# lecture et effacement en lecture seule.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/uptimekuma.php
if [ "$ROLE" = reader ]; then
  contains "services exposes : sonde de A" "Sonde Kuma securite A" oui "$(body "$P/uptimekuma.php")"
  contains "services exposes : rien de B" "Sonde Kuma securite B" non "$(body "$P/uptimekuma.php")"
  expect "front/uptimekuma.php : connexion en lecture seule" 403 \
    "$(code -d "save_uptimekuma=1&uptimekuma_url=https://status.example.test&_glpi_csrf_token=$(csrf)" "$P/uptimekuma.php")"
  expect "front/uptimekuma.php : lecture en lecture seule" 403 \
    "$(code -d "read_uptimekuma=1&_glpi_csrf_token=$(csrf)" "$P/uptimekuma.php")"
  expect "front/uptimekuma.php : effacement en lecture seule" 403 \
    "$(code -d "clear_uptimekuma=1&_glpi_csrf_token=$(csrf)" "$P/uptimekuma.php")"
fi
