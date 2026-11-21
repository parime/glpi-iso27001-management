# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #865, lot 2 : page publique d'un lien (questions piégées), onglet Lien sécurisé et relecture
# d'une réponse reçue piégée.
check "page publique du lien fournisseur" "$P/supplierportal.php?t=$(xid portal_token)"
check "onglet lien securise d'une evaluation" "$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluationLink$1' "$(id evaluation_A)")"
check "relecture d'une reponse recue par lien" "$P/supplierevaluationlink.php?id=$(id evaluation_A)&draft=$(xid portal_draft)"
