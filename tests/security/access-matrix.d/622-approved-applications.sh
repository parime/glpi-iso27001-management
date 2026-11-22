# shellcheck shell=bash
# Issue #622 : applications et services en ligne approuves (A.5.23). Onglet de l'application : droit
# du plugin ET lecture des applications ; page : droit du plugin ; onglet « Applications autorisees »
# des preferences : toute session, entites de la session seulement ; enregistrement : creation ou
# modification du plugin, application visible.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/approvedapplication.php
case $ROLE in
  none|general) AA_TAB=non ;;
  *)            AA_TAB=oui ;;
esac
aa_tab() {
  tab Appliance 'GlpiPlugin%5CGrcmanager%5CApprovedApplication$1' "$(id "appliance_$1")"
}
contains "onglet Usage autorise d'une application de A" "Usage approuve securite A" $AA_TAB "$(aa_tab A)"
contains "onglet Usage autorise d'une application de B" "Usage approuve securite B" $SEE_B "$(aa_tab B)"
aa_mine=$(tab Preference 'GlpiPlugin%5CGrcmanager%5CApprovedApplication$1' 2)
contains "preferences : applications autorisees de A (toute session)" "Usage approuve securite A" oui "$aa_mine"
contains "preferences : rien de B" "Usage approuve securite B" $SEE_B "$aa_mine"
aa_save() {
  code -d "save=1&appliances_id=$(id "appliance_$1")&status=refused&_glpi_csrf_token=$(csrf)" \
    "$P/approvedapplication.form.php"
}
case $ROLE in
  none)
    expect "front/approvedapplication.form.php : sans droit" 403 "$(aa_save A)" ;;
  reader)
    contains "applications approuvees : fiche de A" "Usage approuve securite A" oui "$(body "$P/approvedapplication.php")"
    contains "applications approuvees : rien de B" "Usage approuve securite B" non "$(body "$P/approvedapplication.php")"
    expect "front/approvedapplication.form.php : en lecture seule" 403 "$(aa_save A)" ;;
  limited)
    expect "front/approvedapplication.form.php : application de B" 404 "$(aa_save B)" ;;
esac
