# shellcheck shell=bash
# Issue #835, lot 3 : chemins d'attaque de l'entite A seulement ; exemples fictifs.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
if [ "$ROLE" = reader ]; then
  contains "chemins d'attaque : compte de A" "Compte expose A" oui "$(body "$P/adsecurityaudit.php?path=$(id attackpath_A)")"
  contains "chemins d'attaque : rien de B" "chemins-b.test" non "$(body "$P/adsecurityaudit.php")"
  contains "chemins d'attaque : import de B demande par son numero" "Compte expose B" non \
    "$(body "$P/adsecurityaudit.php?path=$(id attackpath_B)")"
  contains "chemins d'attaque : exemple BloodHound fictif" "DOMAIN ADMINS@EXEMPLE.TEST" oui \
    "$(body "$P/adsecurityaudit.php?example=bloodhound")"
  contains "chemins d'attaque : exemple Adalanche fictif" "elements" oui \
    "$(body "$P/adsecurityaudit.php?example=adalanche")"
fi
if [ "$ROLE" = none ]; then
  expect "front/adsecurityaudit.php : exemple BloodHound sans droit" 403 \
    "$(code "$P/adsecurityaudit.php?example=bloodhound")"
fi
