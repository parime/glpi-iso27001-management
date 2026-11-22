# shellcheck shell=bash
# Issue #1095 : risques generaux lies a un fournisseur. Le fournisseur de A (1040-supplier-security)
# est lie au risque de A et a celui de B (setup.d/1095-supplier-on-risk.php). Onglet Securite de la
# fiche Fournisseur : le risque de A est montre a qui lit les risques dans A, celui de B au seul
# super-admin ; la fiche du risque propose les fournisseurs a qui lit les fournisseurs.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
general_tab=$(tab Supplier 'GlpiPlugin%5CGrcmanager%5CSupplierSecurityTab$1' "$(id supplier_tab)")
SEE_GENERAL_RISKS=oui
case $ROLE in
  none|general) SEE_GENERAL_RISKS=non ;;
esac
contains "fiche Fournisseur, onglet Securite : section des risques generaux" \
  'id="grc-supplier-general-risks"' $SEE_GENERAL_RISKS "$general_tab"
contains "fiche Fournisseur, onglet Securite : risque general de A" \
  "risk.form.php?id=$(id risk)\"" $SEE_GENERAL_RISKS "$general_tab"
contains "fiche Fournisseur, onglet Securite : risque general de B" \
  "risk.form.php?id=$(id risk_B)\"" $SEE_B "$general_tab"
case $ROLE in
  limited|admin)
    contains "fiche du risque de A : rubrique Fournisseurs lies" 'linked_items_Supplier' oui \
      "$(body "$P/risk.form.php?id=$(id risk)")" ;;
esac
