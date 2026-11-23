# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1095 : fournisseur piege lie au risque de A (titre piege) : onglet Securite du fournisseur
# (section des risques generaux) ; la fiche du risque (selection des fournisseurs) est deja controlee.
check "onglet Securite d'un fournisseur, risques generaux" \
  "$(tab Supplier 'GlpiPlugin%5CGrcmanager%5CSupplierSecurityTab$1' "$(xid supplier)")"
