# shellcheck shell=bash
# Issue #843 : controleurs de gestion des serveurs, controleur et compte de l'entite A seulement ;
# declaration, lecture, suppression et import en lecture seule ; controleur de B jamais touche.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/servermanagement.php
bmc_tab() { tab Computer 'GlpiPlugin%5CGrcmanager%5CServerManagement$1' "$(id computer_A)"; }
if [ "$ROLE" = none ]; then
  contains "onglet controleur de gestion sans droit : rien" "Controleur BMC securite A" non "$(bmc_tab)"
fi
if [ "$ROLE" = reader ]; then
  contains "controleurs de gestion : controleur de A" "Controleur BMC securite A" oui "$(body "$P/servermanagement.php")"
  contains "controleurs de gestion : compte de A" "Compte BMC securite A" oui "$(body "$P/servermanagement.php")"
  contains "controleurs de gestion : rien de B" "BMC securite B" non "$(body "$P/servermanagement.php")"
  contains "onglet controleur de gestion de l'ordinateur de A" "Firmware BMC securite A" oui "$(bmc_tab)"
  expect "front/servermanagement.php : declarer en lecture seule" 403 \
    "$(code -d "save_bmc=1&bmc_name=x&bmc_url=https://bmc.example.test&bmc_username=a&bmc_password=b&_glpi_csrf_token=$(csrf)" "$P/servermanagement.php")"
  expect "front/servermanagement.php : lire en lecture seule" 403 \
    "$(code -d "read_bmc=$(id bmc_A)&_glpi_csrf_token=$(csrf)" "$P/servermanagement.php")"
  expect "front/servermanagement.php : supprimer en lecture seule" 403 \
    "$(code -d "delete_bmc=$(id bmc_A)&_glpi_csrf_token=$(csrf)" "$P/servermanagement.php")"
  expect "front/servermanagement.php : importer en lecture seule" 403 \
    "$(code -d "import_bmc=1&_glpi_csrf_token=$(csrf)" "$P/servermanagement.php")"
fi
if [ "$ROLE" = limited ]; then
  contains "controleurs de gestion (gestionnaire) : rien de B" "BMC securite B" non "$(body "$P/servermanagement.php")"
  contains "controleurs de gestion : modification du controleur de B" "bmc-securite-b" non \
    "$(body "$P/servermanagement.php?edit=$(id bmc_B)")"
  expect "front/servermanagement.php : lire le controleur de B" 403 \
    "$(code -d "read_bmc=$(id bmc_B)&_glpi_csrf_token=$(csrf)" "$P/servermanagement.php")"
  expect "front/servermanagement.php : supprimer le controleur de B" 403 \
    "$(code -d "delete_bmc=$(id bmc_B)&_glpi_csrf_token=$(csrf)" "$P/servermanagement.php")"
  expect "front/servermanagement.php : modifier le controleur de B" 403 \
    "$(code -d "save_bmc=1&bmc_id=$(id bmc_B)&bmc_name=x&bmc_url=https://bmc.example.test&bmc_username=a&bmc_password=b&_glpi_csrf_token=$(csrf)" "$P/servermanagement.php")"
  expect "front/servermanagement.php : supprimer sans jeton CSRF" 403 \
    "$(code -d "delete_bmc=$(id bmc_A)" "$P/servermanagement.php")"
fi
