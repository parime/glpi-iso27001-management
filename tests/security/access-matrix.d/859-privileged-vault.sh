# shellcheck shell=bash
# Issue #859 : comptes a privileges et coffre-fort, donnees de l'entite A seulement ; lecture seule.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/privilegedvault.php
if [ "$ROLE" = reader ]; then
  contains "coffre : element de A" "Compte coffre securite A" oui "$(body "$P/privilegedvault.php")"
  contains "coffre : rien de B" "Compte coffre securite B" non "$(body "$P/privilegedvault.php")"
  expect "front/privilegedvault.php : effacer un import en lecture seule" 403 \
    "$(code -d "clear_vault=1&source=pam&kind=entries&_glpi_csrf_token=$(csrf)" "$P/privilegedvault.php")"
  expect "front/privilegedvault.php : lire Bitwarden en lecture seule" 403 \
    "$(code -d "fetch_bitwarden=1&_glpi_csrf_token=$(csrf)" "$P/privilegedvault.php")"
fi
