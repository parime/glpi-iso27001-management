# shellcheck shell=bash
# Issue #846 : droits sur les partages de fichiers. Partages de l'entite A seulement ; import, fiche,
# revue et decisions selon les droits. Fragment charge par tests/security/access-matrix.sh.

# Registres sensibles : la page est refusee au profil qui n'a pas ce droit.
if [ "$ROLE" = general ]; then
  expect "front/shareaccess.php : sans le droit des registres sensibles" 403 "$(code "$P/shareaccess.php")"
fi

if [ "$ROLE" = reader ]; then
  contains "partages : partage de A" "Partage securite A" oui "$(body "$P/shareaccess.php")"
  contains "partages : rien de B" "Partage securite B" non "$(body "$P/shareaccess.php")"
  contains "fiche du partage de A : droit nominatif" "Compte partage securite A" oui \
    "$(body "$P/shareaccess.php?share=$(id share_A)")"
  expect "front/shareaccess.php : fiche du partage de B" 404 "$(code "$P/shareaccess.php?share=$(id share_B)")"
  contains "fiche du partage de A : aucun formulaire de decision" 'name="decide"' non \
    "$(body "$P/shareaccess.php?share=$(id share_A)")"
  expect "front/shareaccess.php : classer un partage en lecture seule" 403 \
    "$(code -d "save_share=1&share=$(id share_A)&confidentiality=low&_glpi_csrf_token=$(csrf)" "$P/shareaccess.php")"
  expect "front/shareaccess.php : effacer un import en lecture seule" 403 \
    "$(code -d "clear_shares=1&server=fs-securite.exemple.test&_glpi_csrf_token=$(csrf)" "$P/shareaccess.php")"
  expect "front/shareaccess.php : lancer une revue en lecture seule" 403 \
    "$(code -d "start_review=1&share=$(id share_A)&_glpi_csrf_token=$(csrf)" "$P/shareaccess.php")"
  expect "front/shareaccess.php : decider sans etre proprietaire" 403 \
    "$(code -d "decide=1&share=$(id share_A)&ace=$(id share_ace_A)&decision=keep&_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
fi

if [ "$ROLE" = limited ]; then
  expect "front/shareaccess.php : decider pour un droit de A" 302 \
    "$(code -d "decide=1&share=$(id share_A)&ace=$(id share_ace_A)&decision=keep&_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
  expect "front/shareaccess.php : decider pour un droit de B" 403 \
    "$(code -d "decide=1&share=$(id share_B)&ace=$(id share_ace_B)&decision=keep&_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
  expect "front/shareaccess.php : classer le partage de B" 403 \
    "$(code -d "save_share=1&share=$(id share_B)&confidentiality=low&_glpi_csrf_token=$(csrf)" "$P/shareaccess.php")"
  expect "front/shareaccess.php : classer sans jeton CSRF" 403 \
    "$(code -d "save_share=1&share=$(id share_A)&confidentiality=low" "$P/shareaccess.php")"
  share_csv=$(mktemp)
  printf 'Server,Share,Identity,Rights\nfs-import.exemple.test,Import securite,Everyone,Change\n' > "$share_csv"
  expect "front/shareaccess.php : importer un CSV" 302 \
    "$(code -F import_shares=1 -F "share_file=@$share_csv;filename=shares.csv" -F "_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
  # Fichier piege : du PHP annonce comme CSV est lu comme un export illisible, rien n'est importe.
  expect "front/shareaccess.php : importer un fichier piege" 302 \
    "$(code -F import_shares=1 -F "share_file=@$FAKE_PDF;filename=shares.php" -F "_glpi_csrf_token=$(csrf)" \
      "$P/shareaccess.php")"
  expect "front/shareaccess.php : importer sans jeton CSRF" 403 \
    "$(code -F import_shares=1 -F "share_file=@$share_csv;filename=shares.csv" "$P/shareaccess.php")"
  expect "front/shareaccess.php : effacer l'import de test" 302 \
    "$(code -d "clear_shares=1&server=fs-import.exemple.test&_glpi_csrf_token=$(csrf)" "$P/shareaccess.php")"
  rm -f "$share_csv"
fi
