# shellcheck shell=bash
# Issue #835, lot 2 : configuration Entra ID (Maester) de l'entite A seulement ; exemples fictifs.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
if [ "$ROLE" = reader ]; then
  contains "audit Entra ID : locataire de A" "Locataire securite A" oui "$(body "$P/adsecurityaudit.php")"
  contains "audit Entra ID : rien de B" "Locataire securite B" non "$(body "$P/adsecurityaudit.php")"
  contains "audit Entra ID : import de B demande par son numero" "Controle Entra securite B" non \
    "$(body "$P/adsecurityaudit.php?id=$(id cloudaudit_B)")"
  contains "audit Entra ID : import de A, renvoi a la couverture MFA" "grc-ad-mfa" oui \
    "$(body "$P/adsecurityaudit.php?id=$(id cloudaudit_A)")"
  contains "audit de l'AD : exemple Maester fictif" "Exemple fictif" oui \
    "$(body "$P/adsecurityaudit.php?example=maester")"
  contains "audit de l'AD : exemple inconnu, PingCastle par defaut" "HealthcheckData" oui \
    "$(body "$P/adsecurityaudit.php?example=..%2F..%2Fconfig")"
fi
if [ "$ROLE" = none ]; then
  expect "front/adsecurityaudit.php : exemple sans droit" 403 \
    "$(code "$P/adsecurityaudit.php?example=scubagear")"
fi
