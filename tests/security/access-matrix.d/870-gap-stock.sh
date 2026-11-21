# shellcheck shell=bash
# Issue #870 : rapport du stock d'ecarts (10.1, 10.2). Constats des entites de la session seulement ;
# exports CSV et PDF en lecture ; archivage du PDF reserve au droit de modifier, avec jeton CSRF.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/gapstock.php
case $ROLE in
  reader|limited|admin)
    contains "stock d'ecarts : constat de A" "Constat securite A" oui "$(body "$P/gapstock.php")"
    contains "stock d'ecarts : constat de B" "Constat securite B" $SEE_B "$(body "$P/gapstock.php")"
    contains "stock d'ecarts CSV : constat de A" "Constat securite A" oui "$(body "$P/gapstock.php?export=csv")"
    contains "stock d'ecarts CSV : constat de B" "Constat securite B" $SEE_B "$(body "$P/gapstock.php?export=csv")"
    expect "stock d'ecarts : export PDF" 200 "$(code "$P/gapstock.php?export=pdf")"
    expect "stock d'ecarts : archiver sans jeton CSRF" 403 "$(code -d "archive_gap_stock=1" "$P/gapstock.php")"
    ;;
esac
if [ "$ROLE" = reader ]; then
  expect "stock d'ecarts : archiver en lecture seule" 403 \
    "$(code -d "archive_gap_stock=1&_glpi_csrf_token=$(csrf)" "$P/gapstock.php")"
fi
