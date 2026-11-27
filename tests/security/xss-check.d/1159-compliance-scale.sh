# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1159 : questions piégées en échelle de conformité (données du fragment setup-xss.d/1159) ;
# la page publique du lien (fragment #865) les affiche aussi, sans la recharger ici (limite de débit).
check "onglet questions d'un modele (echelle de conformite)" "$(tab GlpiPlugin%5CGrcmanager%5CSupplierQuestionnaireTemplate 'GlpiPlugin%5CGrcmanager%5CSupplierQuestionnaireTemplate$1' "$(id questionnaire_A)")"
check "onglet questionnaire d'une evaluation (echelle de conformite)" "$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluation$1' "$(id evaluation_A)")"
