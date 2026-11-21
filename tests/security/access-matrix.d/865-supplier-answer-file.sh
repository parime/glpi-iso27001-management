# shellcheck shell=bash
# Issue #865 : réponse du fournisseur hors ligne. Exporter le questionnaire d'une évaluation se fait
# avec le droit de lire le risque fournisseur ; importer le fichier retourné (aperçu) exige de le
# modifier ; rien de B hors super-admin ; un fichier piégé reste du texte à l'aperçu ; sans jeton
# CSRF, refus. Pages : front/supplierevaluationfile.php.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
read=200 write=200 see_b=403
case $ROLE in
  none|general) read=403; write=403 ;;
  reader)       write=403 ;;
  admin)        see_b=200 ;;
esac
F=$P/supplierevaluationfile.php
expect "export du questionnaire de A" $read "$(code "$F?id=$(id evaluation_A)&export=1")"
expect "export du questionnaire de B" $see_b "$(code "$F?id=$(id evaluation_B)&export=1")"
if [ $read = 200 ]; then
  contains "onglet fichier fournisseur de A : export propose" "grc-evaluation-export" oui \
    "$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluationFile$1' "$(id evaluation_A)")"
fi
export_a=$(curl -s -c "$JAR" -b "$JAR" "$F?id=$(id evaluation_A)&export=1")
contains "export de A : repere de l'evaluation" "grcmanager-supplier-evaluation" \
  "$([ $read = 200 ] && echo oui || echo non)" "$export_a"
trap_csv=$(mktemp)
if [ $read = 200 ]; then
  code_a=$(printf '%s\n' "$export_a" | sed -n 3p | cut -d';' -f1)
  { printf '%s\n' "$export_a" | sed -n 1,2p
    printf '%s;;;;;;;yes;"<script>alert(865)</script>";..\..\secret.pdf\n' "$code_a"
    printf 'Q-INCONNUE;;;;;;;yes;;\n'; } > "$trap_csv"
else
  printf 'grcmanager-supplier-evaluation;1;%s;0\n' "$(id evaluation_A)" > "$trap_csv"
fi
upload_file() { # evaluation, fichier
  code -F upload=1 -F "id=$1" -F "_glpi_csrf_token=$(csrf)" -F "answer_file=@$2;filename=reponses.csv" "$F"
}
expect "import d'un fichier dans l'evaluation de A" $write "$(upload_file "$(id evaluation_A)" "$trap_csv")"
if [ $write = 200 ]; then
  preview=$(curl -s -c "$JAR" -b "$JAR" "$F?id=$(id evaluation_A)")
  contains "apercu : texte piege echappe" "&lt;script&gt;alert(865)" oui "$preview"
  contains "apercu : aucune balise executee" "<script>alert(865)" non "$preview"
  contains "apercu : code inconnu signale" "Q-INCONNUE" oui "$preview"
  contains "apercu : nom de piece sans chemin" "secret.pdf" oui "$preview"
  expect "annuler l'import" 302 "$(code -d "cancel=1&id=$(id evaluation_A)&_glpi_csrf_token=$(csrf)" "$F")"
  expect "confirmer sans fichier en attente" 400 "$(code -d "confirm=1&id=$(id evaluation_A)&_glpi_csrf_token=$(csrf)" "$F")"
fi
expect "import d'un fichier dans l'evaluation de B" $see_b "$(upload_file "$(id evaluation_B)" "$trap_csv")"
[ "$ROLE" = admin ] && code -d "cancel=1&id=$(id evaluation_B)&_glpi_csrf_token=$(csrf)" "$F" > /dev/null
expect "import sans jeton CSRF" 403 \
  "$(code -F upload=1 -F "id=$(id evaluation_A)" -F "answer_file=@$trap_csv;filename=reponses.csv" "$F")"
rm -f "$trap_csv"
