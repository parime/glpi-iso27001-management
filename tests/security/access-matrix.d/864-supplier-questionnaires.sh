# shellcheck shell=bash
# Issue #864 : questionnaires fournisseurs. Le modèle et l'évaluation de A se lisent avec le droit des
# registres sensibles ; répondre, envoyer une pièce, terminer ou modifier les questions exige de
# modifier ; rien de B hors super-admin ; une pièce justificative est contrôlée (type réel du contenu).
# Pages : front/supplierquestionnairetemplate.php, front/supplierquestionnairetemplate.form.php,
# front/supplierevaluation.form.php.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables) ; les profils
# none, reader et general sortent avant les fragments (creations refusees : create_attempts).
read=200 write=302 write_b=403 see_b=403
case $ROLE in
  none|general) read=403; write=403 ;;
  reader)       write=403 ;;
  admin)        write_b=302; see_b=200 ;;
esac
answers="$(id evaluation_answer_A)"
upload() { # fichier, nom annonce
  code -F save_answers=1 -F "plugin_grcmanager_supplierevaluations_id=$(id evaluation_A)" -F "_glpi_csrf_token=$(csrf)" \
    -F "evidence[$answers]=@$1;filename=$2" "$P/supplierevaluation.form.php"
}
expect "liste des modeles de questionnaire" $read "$(code "$P/supplierquestionnairetemplate.php")"
expect "modele de questionnaire de A" $read "$(code "$P/supplierquestionnairetemplate.form.php?id=$(id questionnaire_A)")"
expect "evaluation fournisseur de A" $read "$(code "$P/supplierevaluation.form.php?id=$(id evaluation_A)")"
expect "modele de questionnaire de B" $see_b "$(code "$P/supplierquestionnairetemplate.form.php?id=$(id questionnaire_B)")"
expect "evaluation fournisseur de B" $see_b "$(code "$P/supplierevaluation.form.php?id=$(id evaluation_B)")"
if [ $read = 200 ]; then
  list=$(curl -s -c "$JAR" -b "$JAR" "$P/supplierquestionnairetemplate.php")
  contains "liste des modeles : modele de A" "Modele questionnaire securite A" oui "$list"
  contains "liste des modeles : modele de B" "Modele questionnaire securite B" $SEE_B "$list"
  tab_a=$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$1' "$(id supplierrisk)")
  contains "onglet questionnaires du risque de A : evaluation de A" "Evaluation securite A" oui "$tab_a"
  contains "onglet questionnaires du risque de A : modele de B jamais propose" "Modele questionnaire securite B" non "$tab_a"
else
  contains "onglet questionnaires d'un risque : sans droit" "Evaluation securite A" non       "$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$1' "$(id supplierrisk)")"
  contains "onglet questionnaire d'une evaluation : sans droit" "Question securite A" non       "$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluation$1' "$(id evaluation_A)")"
fi
expect "enregistrer les reponses de l'evaluation de A" $write     "$(code -d "save_answers=1&plugin_grcmanager_supplierevaluations_id=$(id evaluation_A)&answers%5B$answers%5D=yes&_glpi_csrf_token=$(csrf)" "$P/supplierevaluation.form.php")"
expect "enregistrer les reponses de l'evaluation de B" $write_b     "$(code -d "save_answers=1&plugin_grcmanager_supplierevaluations_id=$(id evaluation_B)&answers%5B$(id evaluation_answer_B)%5D=yes&_glpi_csrf_token=$(csrf)" "$P/supplierevaluation.form.php")"
expect "modifier les questions du modele de A" $write     "$(code -d "save_questions=1&id=$(id questionnaire_A)&_glpi_csrf_token=$(csrf)" "$P/supplierquestionnairetemplate.form.php")"
expect "modifier les questions du modele de B" $write_b     "$(code -d "save_questions=1&id=$(id questionnaire_B)&_glpi_csrf_token=$(csrf)" "$P/supplierquestionnairetemplate.form.php")"
expect "dupliquer le modele de A" $write     "$(code -d "duplicate=1&id=$(id questionnaire_A)&_glpi_csrf_token=$(csrf)" "$P/supplierquestionnairetemplate.form.php")"
expect "creer une evaluation sur le risque de B" $write_b     "$(code -d "add=1&plugin_grcmanager_supplierrisks_id=$(id supplierrisk_B)&plugin_grcmanager_supplierquestionnairetemplates_id=$(id questionnaire_B)&_glpi_csrf_token=$(csrf)" "$P/supplierevaluation.form.php")"
expect "rouvrir l'evaluation de B" $write_b     "$(code -d "reopen=1&id=$(id evaluation_B)&_glpi_csrf_token=$(csrf)" "$P/supplierevaluation.form.php")"
expect "envoyer une piece justificative sans jeton CSRF" 403     "$(code -F save_answers=1 -F "plugin_grcmanager_supplierevaluations_id=$(id evaluation_A)"       -F "evidence[$answers]=@$EVIDENCE_PDF;filename=certificat.pdf" "$P/supplierevaluation.form.php")"
# Pièce justificative : le droit de créer un document est exigé (le gestionnaire limité ne l'a
# pas) ; le type réel est vérifié (un faux PDF n'est pas enregistré).
if [ "$ROLE" = admin ]; then
  upload "$FAKE_PDF" faux-questionnaire.pdf > /dev/null
  upload "$EVIDENCE_PDF" certificat-questionnaire.pdf > /dev/null
  tab_a=$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluation$1' "$(id evaluation_A)")
  contains "piece justificative : faux PDF refuse" "faux-questionnaire.pdf" non "$tab_a"
  contains "piece justificative : PDF accepte" "certificat-questionnaire.pdf" oui "$tab_a"
  contains "piece justificative : envoi propose" "name=\"evidence[$answers]\"" oui "$tab_a"
elif [ "$ROLE" = limited ]; then
  upload "$EVIDENCE_PDF" certificat-limite.pdf > /dev/null
  tab_a=$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluation$1' "$(id evaluation_A)")
  contains "piece justificative sans le droit de creer un document" "certificat-limite.pdf" non "$tab_a"
  contains "piece justificative : pas d'envoi sans droit de creer un document" "name=\"evidence[" non "$tab_a"
fi
