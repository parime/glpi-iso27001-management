# shellcheck shell=bash
# Issue #869 : onglet « SBOM et VEX » des applications. Voir : lecture du plugin ET application
# visible (entite) ; importer, declarer, supprimer : mise a jour du plugin en plus. Le profil general
# ne lit pas les applications : rien ne lui est montre ni enregistre.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
# Pages : front/productsbom.form.php (actions) et front/productsbom.php (liste en lecture).
readable front/productsbom.php
sbom_tab() {
  tab Appliance 'GlpiPlugin%5CGrcmanager%5CProductSbom$1' "$(id "appliance_$1")"
}
sbom_post() { # donnees
  code -d "$1&_glpi_csrf_token=$(csrf)" "$P/productsbom.form.php"
}
case $ROLE in
  none|general) SBOM_TAB=non; SBOM_A=403; SBOM_B=403 ;;
  reader)       SBOM_TAB=oui; SBOM_A=403; SBOM_B=403 ;;
  limited)      SBOM_TAB=oui; SBOM_A=302; SBOM_B=404 ;;
  *)            SBOM_TAB=oui; SBOM_A=302; SBOM_B=302 ;;
esac
contains "onglet SBOM et VEX d'une application de A" "Composant SBOM securite A" $SBOM_TAB "$(sbom_tab A)"
contains "onglet SBOM et VEX d'une application de B" "Composant SBOM securite B" $SEE_B "$(sbom_tab B)"
expect "declaration VEX sur une application de A" $SBOM_A \
  "$(sbom_post "declare=1&appliances_id=$(id appliance_A)&vulnerability_id=CVE-2024-0870&status=affected")"
expect "declaration VEX sur une application de B" $SBOM_B \
  "$(sbom_post "declare=1&appliances_id=$(id appliance_B)&vulnerability_id=CVE-2024-0870&status=affected")"
# Le super-admin ne supprime rien : le jeu de donnees reste le meme d'un passage a l'autre.
if [ "$ROLE" != admin ]; then
  expect "suppression d'une declaration VEX de B" $SBOM_B \
    "$(sbom_post "delete_statement=1&statements_id=$(id vex_B)")"
fi
if [ "$ROLE" = limited ]; then
  expect "suppression d'une nomenclature de B" 404 "$(sbom_post "delete_sbom=1&sboms_id=$(id sbom_B)")"
  expect "declaration VEX sans jeton CSRF" 403 \
    "$(code -d "declare=1&appliances_id=$(id appliance_A)&vulnerability_id=CVE-2024-0870&status=affected" "$P/productsbom.form.php")"
fi
if [ "$ROLE" = reader ]; then
  contains "liste des SBOM : version de A" "1.0.A" oui "$(body "$P/productsbom.php")"
  contains "liste des SBOM : version de B" "1.0.B" $SEE_B "$(body "$P/productsbom.php")"
fi
