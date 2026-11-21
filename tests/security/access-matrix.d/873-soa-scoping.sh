# shellcheck shell=bash
# Issue #873 : cadrage guide de la SoA. Consultation et export CSV avec le droit de lire la SoA ;
# reponses et decisions reservees au droit de la modifier, avec jeton CSRF.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/soascoping.php
case $ROLE in
  reader|admin)
    contains "cadrage SoA : export CSV" "A.8.34" oui "$(body "$P/soascoping.php?export=csv")"
    expect "cadrage SoA : reponses sans jeton CSRF" 403 "$(code -d "save_answers=1" "$P/soascoping.php")"
    expect "cadrage SoA : decision sans jeton CSRF" 403 "$(code -d "accept_one=1" "$P/soascoping.php")"
    ;;
esac
if [ "$ROLE" = reader ]; then
  expect "cadrage SoA : reponses en lecture seule" 403 \
    "$(code -d "save_answers=1&answers[remote_work]=no&_glpi_csrf_token=$(csrf)" "$P/soascoping.php")"
  expect "cadrage SoA : decision en lecture seule" 403 \
    "$(code -d "decide=accepted&controls[]=1&_glpi_csrf_token=$(csrf)" "$P/soascoping.php")"
fi
