# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1040 : onglet Securite de la fiche Fournisseur (titre du risque fournisseur piege) et page
# d'accueil de Gestion > Securite des fournisseurs.
check "onglet Securite de la fiche Fournisseur" "$(tab Supplier 'GlpiPlugin%5CGrcmanager%5CSupplierSecurityTab$1' "$(id supplier)")"
check "front/section.php?section=suppliersecurity" "$P/section.php?section=suppliersecurity"
