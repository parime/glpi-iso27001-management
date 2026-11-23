# shellcheck shell=bash
# Issue #543 : rapports de scanners de vulnerabilites, constats de l'entite A seulement ; import,
# rattachement, suppression et ticket refuses en lecture seule.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/scannerimport.php
if [ "$ROLE" = reader ]; then
  contains "scanners : constat de A" "Constat scanner securite A" oui "$(body "$P/scannerimport.php")"
  contains "scanners : rien de B" "Constat scanner securite B" non "$(body "$P/scannerimport.php")"
  contains "scanners : tache de B absente" "Tache scanner securite B" non "$(body "$P/scannerimport.php")"
  expect "front/scannerimport.php : importer en lecture seule" 403 \
    "$(code -F import_scanner=1 -F "scanner_file=@$EVIDENCE_PDF;filename=rapport.xml" -F "_glpi_csrf_token=$(csrf)" "$P/scannerimport.php")"
  expect "front/scannerimport.php : rattacher en lecture seule" 403 \
    "$(code -d "link_scanner_host=1&host=192.0.2.41&computers_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/scannerimport.php")"
  expect "front/scannerimport.php : supprimer en lecture seule" 403 \
    "$(code -d "delete_scanner_import=1&import=$(id scannerimport_A)&_glpi_csrf_token=$(csrf)" "$P/scannerimport.php")"
  expect "front/scannerimport.php : ticket en lecture seule" 403 \
    "$(code -d "scanner_ticket=1&finding=$(id scannerfinding_A)&_glpi_csrf_token=$(csrf)" "$P/scannerimport.php")"
fi
