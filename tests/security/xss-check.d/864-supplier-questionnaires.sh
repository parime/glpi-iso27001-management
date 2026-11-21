# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #864 : modèles de questionnaire, évaluation et réponses piégés.
for url in supplierquestionnairetemplate.php "supplierquestionnairetemplate.form.php?id=$(id questionnaire_A)" \
  "supplierevaluation.form.php?id=$(id evaluation_A)"; do
  check "front/$url" "$P/$url"
done
check "onglet questions d'un modele de questionnaire" "$(tab GlpiPlugin%5CGrcmanager%5CSupplierQuestionnaireTemplate 'GlpiPlugin%5CGrcmanager%5CSupplierQuestionnaireTemplate$1' "$(id questionnaire_A)")"
check "onglet questionnaire d'une evaluation"     "$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluation$1' "$(id evaluation_A)")"
