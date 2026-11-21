# shellcheck shell=bash
# Issue #879 : couverture des mesures par les politiques ; rapport, exports et onglets en lecture,
# liaison reservee au droit de modifier les politiques, mesures de B invisibles depuis A.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/policycoverage.php front/policycoverage.export.php
if [ "$ROLE" = reader ]; then
  contains "couverture : section de A sur la politique de A" "Section couverture 879 A" oui \
    "$(tab GlpiPlugin%5CGrcmanager%5CPolicy 'GlpiPlugin%5CGrcmanager%5CPolicyCoverage$1' "$(id policy_A)")"
  contains "couverture : lien vers la mesure de B masque" "Section couverture 879 B" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CPolicy 'GlpiPlugin%5CGrcmanager%5CPolicyCoverage$1' "$(id policy_B)")"
  contains "couverture : textes de la mesure de A" "Section couverture 879 A" oui \
    "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CPolicyCoverage$1' "$(id control)")"
  expect "front/policycoverage.form.php : relier en lecture seule" 403 \
    "$(code -d "add_links=1&policies_id[]=$(id policy_A)&codes=A.5&_glpi_csrf_token=$(csrf)" "$P/policycoverage.form.php")"
  expect "front/policycoverage.form.php : retirer en lecture seule" 403 \
    "$(code -d "remove_link=1&links_id=1&_glpi_csrf_token=$(csrf)" "$P/policycoverage.form.php")"
fi
if [ "$ROLE" = reader ]; then
  expect "front/policycoverage.export.php : PDF en lecture" 200 "$(code "$P/policycoverage.export.php?format=pdf")"
fi
