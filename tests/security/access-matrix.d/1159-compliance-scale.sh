# shellcheck shell=bash
# Issue #1159 : type de réponse des questions (Oui / Non ou échelle de conformité). Le choix du type
# n'est proposé qu'avec le droit de modifier le modèle ; changer le type d'une question de B est
# refusé hors super-admin. Le type des questions de A n'est pas changé (les liens fournisseurs du jeu
# de données portent l'empreinte de ses questions).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
grid=$(tab GlpiPlugin%5CGrcmanager%5CSupplierQuestionnaireTemplate 'GlpiPlugin%5CGrcmanager%5CSupplierQuestionnaireTemplate$1' "$(id questionnaire_A)")
case $ROLE in
  none|general)
    contains "type de reponse : onglet questions sans droit" "Question securite A" non "$grid"
    expect "type de reponse : modifier une question de B sans droit" 403 \
      "$(code -d "save_questions=1&id=$(id questionnaire_B)&_glpi_csrf_token=$(csrf)" "$P/supplierquestionnairetemplate.form.php")" ;;
  reader)
    contains "type de reponse : pas de choix du type en lecture" "[answer_type]" non "$grid"
    expect "type de reponse : modifier le modele de A en lecture" 403 \
      "$(code -d "save_questions=1&id=$(id questionnaire_A)&new_questions%5B1%5D%5Bquestion%5D=x&new_questions%5B1%5D%5Banswer_type%5D=compliance&_glpi_csrf_token=$(csrf)" "$P/supplierquestionnairetemplate.form.php")" ;;
  limited)
    contains "type de reponse : choix du type propose" "[answer_type]" oui "$grid"
    expect "type de reponse : modifier une question de B" 403 \
      "$(code -d "save_questions=1&id=$(id questionnaire_B)&new_questions%5B1%5D%5Bquestion%5D=x&new_questions%5B1%5D%5Banswer_type%5D=compliance&_glpi_csrf_token=$(csrf)" "$P/supplierquestionnairetemplate.form.php")" ;;
  admin)
    contains "type de reponse : choix du type propose" "[answer_type]" oui "$grid"
    contains "type de reponse : echelle de conformite proposee" 'value="compliance"' oui "$grid" ;;
esac
