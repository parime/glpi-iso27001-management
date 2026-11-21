# shellcheck shell=bash
# Issue #852 : couverture MFA, comptes importes de l'entite A seulement ; import en lecture seule.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
if [ "$ROLE" = reader ]; then
  contains "couverture MFA : compte importe de A" "Compte MFA securite A" oui "$(body "$P/mfacoverage.php")"
  contains "couverture MFA : rien de B" "Compte MFA securite B" non "$(body "$P/mfacoverage.php")"
  expect "front/mfacoverage.php : effacer un import en lecture seule" 403 \
    "$(code -d "clear_mfa=1&provider=okta&_glpi_csrf_token=$(csrf)" "$P/mfacoverage.php")"
  expect "front/mfacoverage.php : groupes d'acces distant en lecture seule" 403 \
    "$(code -d "save_mfa_remote=1&_glpi_csrf_token=$(csrf)" "$P/mfacoverage.php")"
fi
