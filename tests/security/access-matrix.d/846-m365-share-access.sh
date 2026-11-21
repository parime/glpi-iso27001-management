# shellcheck shell=bash
# Issue #846, lot 2 : droits SharePoint, OneDrive et Teams. Sites de l'entite A seulement ; lecture
# Microsoft Graph et import CSV selon les droits. Fragment charge par tests/security/access-matrix.sh.

if [ "$ROLE" = reader ]; then
  contains "sites : site de A" "Site securite A" oui "$(body "$P/shareaccess.php")"
  contains "sites : rien de B" "Site securite B" non "$(body "$P/shareaccess.php")"
  contains "fiche du site de A : invite externe" "invite.siteA@partenaire.test" oui \
    "$(body "$P/shareaccess.php?share=$(id m365_share_A)")"
  expect "front/shareaccess.php : fiche du site de B" 404 "$(code "$P/shareaccess.php?share=$(id m365_share_B)")"
  contains "sites : aucun bouton de lecture Graph en lecture seule" 'name="fetch_m365_graph"' non \
    "$(body "$P/shareaccess.php")"
  expect "front/shareaccess.php : lire Microsoft 365 en lecture seule" 403 \
    "$(code -d "fetch_m365_graph=1&source=teams&_glpi_csrf_token=$(csrf)" "$P/shareaccess.php")"
  expect "front/shareaccess.php : effacer un site en lecture seule" 403 \
    "$(code -d "clear_shares=1&server=securite.onmicrosoft.com&source=sharepoint&_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
fi

if [ "$ROLE" = limited ]; then
  m365_csv=$(mktemp)
  printf 'Source,Tenant,Site,Identity,Type,Roles\nTeams,import.onmicrosoft.com,Equipe import,a@ailleurs.test,Guest,member\n' \
    > "$m365_csv"
  expect "front/shareaccess.php : importer un CSV Microsoft 365" 302 \
    "$(code -F import_m365_shares=1 -F "share_file=@$m365_csv;filename=m365.csv" -F "_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
  expect "front/shareaccess.php : importer un CSV Microsoft 365 sans jeton CSRF" 403 \
    "$(code -F import_m365_shares=1 -F "share_file=@$m365_csv;filename=m365.csv" "$P/shareaccess.php")"
  expect "front/shareaccess.php : effacer l'import Microsoft 365 de test" 302 \
    "$(code -d "clear_shares=1&server=import.onmicrosoft.com&source=teams&_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
  rm -f "$m365_csv"
fi
