# shellcheck shell=bash
# Issue #1040 : Gestion > Securite des fournisseurs. Page d'accueil de la rubrique (les pages de la
# rubrique, leur fil d'Ariane et leur refus sans droit sont verifies par 1037-menu-sections.sh),
# onglet Securite de la fiche Fournisseur (droits et entites des risques fournisseurs) et reglage du
# lien securise enregistre par la configuration (l'ancien envoi reste accepte, 865-supplier-portal.sh).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable "front/section.php?section=suppliersecurity"
SUPPLIER_TAB='GlpiPlugin%5CGrcmanager%5CSupplierSecurityTab$1'
# Fournisseur de A (setup.d/1040-supplier-security.php) : le fournisseur commun n'est pas ouvert a A.
supplier_tab=$(tab Supplier "$SUPPLIER_TAB" "$(id supplier_tab)")
SEE_SUPPLIER_RISKS=oui
CREATE_SUPPLIER_RISK=non
PORTAL_CONFIG=403
case $ROLE in
  none|general) SEE_SUPPLIER_RISKS=non ;;
  limited) CREATE_SUPPLIER_RISK=oui ;;
  admin) CREATE_SUPPLIER_RISK=oui; PORTAL_CONFIG=302 ;;
esac
contains "fiche Fournisseur, onglet Securite : affiche avec le droit des risques fournisseurs" \
  'id="grc-supplier-security-tab"' $SEE_SUPPLIER_RISKS "$supplier_tab"
contains "fiche Fournisseur, onglet Securite : risque fournisseur de A" \
  "Risque onglet fournisseur A" $SEE_SUPPLIER_RISKS "$supplier_tab"
contains "fiche Fournisseur, onglet Securite : risque fournisseur de B" \
  "Risque onglet fournisseur B" $SEE_B "$supplier_tab"
contains "fiche Fournisseur, onglet Securite : bouton Evaluer ce fournisseur" \
  'id="grc-supplier-security-create"' $CREATE_SUPPLIER_RISK "$supplier_tab"
# Valeur deja posee par 865-supplier-portal.sh (super-admin) : l'etat du jeu de donnees ne change pas.
expect "configuration, General : regler le lien securise" $PORTAL_CONFIG \
  "$(code -d "update_portal_config=1&supplier_portal_enabled=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
if [ "$ROLE" = admin ]; then
  hub=$(body "$P/section.php?section=suppliersecurity")
  contains "Securite des fournisseurs, accueil : risques fournisseurs" "front/supplierrisk.php" oui "$hub"
  contains "Securite des fournisseurs, accueil : questionnaires (autrefois sans menu)" \
    "front/supplierquestionnairetemplate.php" oui "$hub"
  contains "Risques, accueil : plus de risques fournisseurs (partis dans Gestion)" "front/supplierrisk.php" non \
    "$(body "$P/section.php?section=risk")"
  block=$(body "$P/config.php?tab=general" | tr '\n' ' ' | grep -o 'id="grc-supplier-portal-config".*' | sed 's#</form>.*##')
  contains "configuration, General : reglage du lien securise enregistre par la configuration" \
    'plugins/grcmanager/front/config.php"' oui "$block"
  contains "configuration, General : reglage du lien securise plus envoye a la page d'exploitation" \
    'supplierevaluationlink.php' non "$block"
  expect "front/supplierrisk.form.php : nouveau risque prerempli depuis la fiche Fournisseur" 200 \
    "$(code "$P/supplierrisk.form.php?suppliers_id=$(id supplier_tab)")"
fi
