# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #865 : onglet « Fichier fournisseur » d'une évaluation piégée (nom, réponses).
check "onglet fichier fournisseur d'une evaluation" "$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluationFile$1' "$(id evaluation_A)")"
# Aperçu d'import : nom de l'évaluation et questions piégés (fichier exporté puis réimporté).
xss_csv=$(mktemp)
curl -s -c "$JAR" -b "$JAR" "$P/supplierevaluationfile.php?id=$(id evaluation_A)&export=1" > "$xss_csv"
xss_token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/front/central.php" | grep -o 'glpi:csrf_token" content="[^"]*"' | sed 's/.*content="//;s/"//')
curl -s -o /dev/null -c "$JAR" -b "$JAR" -F upload=1 -F "id=$(id evaluation_A)" -F "_glpi_csrf_token=$xss_token" \
  -F "answer_file=@$xss_csv;filename=reponses.csv" "$P/supplierevaluationfile.php"
check "apercu d'un fichier fournisseur" "$P/supplierevaluationfile.php?id=$(id evaluation_A)"
rm -f "$xss_csv"
