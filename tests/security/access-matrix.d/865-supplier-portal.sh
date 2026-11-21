# shellcheck shell=bash
# Issue #865, lot 2 : réponse du fournisseur par lien sécurisé, sans compte GLPI.
# Page publique front/supplierportal.php (sans session) : jeton absent, faux, expiré, révoqué ou déjà
# utilisé refusé ; seules les questions de l'évaluation du lien ; en-têtes de sécurité ; CSRF ;
# réponse hors liste, question d'une autre évaluation et fichier piégé refusés ; limitation de débit.
# Côté GLPI (front/supplierevaluationlink.php) : création réservée à qui modifie l'évaluation,
# réglage réservé à la configuration du plugin. La page publique ne dépend pas du profil : testée
# une fois (super-admin, qui relit ensuite la réponse reçue).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
L=$P/supplierevaluationlink.php
create_a=302
create_b=403
config=403
case $ROLE in
  none|general|reader) create_a=403 ;;
  admin) create_b=302; config=302 ;;
esac
expect "creer un lien sur l'evaluation de A" $create_a \
  "$(code -d "create_link=1&days=3&max_uses=1&id=$(id evaluation_A)&_glpi_csrf_token=$(csrf)" "$L")"
expect "creer un lien sur l'evaluation de B" $create_b \
  "$(code -d "create_link=1&days=3&max_uses=1&id=$(id evaluation_B)&_glpi_csrf_token=$(csrf)" "$L")"
expect "creer un lien sans jeton CSRF" 403 "$(code -d "create_link=1&id=$(id evaluation_A)" "$L")"
expect "regler le lien securise" $config \
  "$(code -d "update_portal_config=1&supplier_portal_enabled=1&_glpi_csrf_token=$(csrf)" "$L")"

if [ "$ROLE" = admin ]; then
  S=$P/supplierportal.php
  PJAR=$(mktemp)
  # En-tete Sec-Fetch-Site : voir site() dans access-matrix.sh (GLPI 12, #980).
  pcode() { curl -s -o /dev/null -w '%{http_code}' -c "$PJAR" -b "$PJAR" -H "Sec-Fetch-Site: $(site "$@")" "$@"; }
  pbody() { curl -s -c "$PJAR" -b "$PJAR" "$@"; }
  ptoken() { pbody "$S?t=$1" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
  zeros=$(printf '0%.0s' $(seq 64))
  expect "portail : sans jeton" 404 "$(pcode "$S")"
  expect "portail : jeton mal forme" 404 "$(pcode "$S?t=abc")"
  expect "portail : jeton inconnu au bon format" 404 "$(pcode "$S?t=$zeros")"
  expect "portail : jeton expire" 410 "$(pcode "$S?t=$(id portal_token_expired)")"
  expect "portail : jeton revoque" 410 "$(pcode "$S?t=$(id portal_token_revoked)")"
  headers=$(curl -s -D - -o /dev/null -c "$PJAR" -b "$PJAR" "$S?t=$(id portal_token_A)")
  contains "portail : politique de securite du contenu" "default-src 'none'" oui "$headers"
  contains "portail : pas d'affichage dans un cadre" "X-Frame-Options: DENY" oui "$headers"
  contains "portail : pas de referent" "Referrer-Policy: no-referrer" oui "$headers"
  page=$(pbody "$S?t=$(id portal_token_A)")
  contains "portail A : question de l'evaluation" "Question securite A" oui "$page"
  contains "portail A : aucune question d'une autre evaluation" "Question securite B" non "$page"
  contains "portail A : ni nom de l'evaluation" "Evaluation securite A" non "$page"
  contains "portail A : ni risque fournisseur" "Risque fournisseur securite" non "$page"
  contains "portail A : ni entite" "Securite ISO A" non "$page"
  answer_a=$(id evaluation_answer_A)
  answer_b=$(id evaluation_answer_B)
  token_a=$(id portal_token_A)
  expect "portail : envoi sans jeton CSRF" 403 "$(pcode -F "t=$token_a" -F "answer[$answer_a]=yes" "$S")"
  expect "portail : reponse hors liste" 422 \
    "$(pcode -F "_glpi_csrf_token=$(ptoken "$token_a")" -F "t=$token_a" -F "answer[$answer_a]=maybe" "$S")"
  expect "portail : question d'une autre evaluation ignoree" 422 \
    "$(pcode -F "_glpi_csrf_token=$(ptoken "$token_a")" -F "t=$token_a" -F "answer[$answer_b]=yes" "$S")"
  trap_file=$(mktemp)
  printf '<?php system($_GET["c"]); ?>\n' > "$trap_file"
  expect "portail : script deguise en PDF refuse" 422 \
    "$(pcode -F "_glpi_csrf_token=$(ptoken "$token_a")" -F "t=$token_a" -F "answer[$answer_a]=yes" \
      -F "evidence[$answer_a]=@$trap_file;filename=certificat.pdf" "$S")"
  expect "portail : page HTML refusee" 422 \
    "$(pcode -F "_glpi_csrf_token=$(ptoken "$token_a")" -F "t=$token_a" -F "answer[$answer_a]=yes" \
      -F "evidence[$answer_a]=@$trap_file;filename=../../index.html" "$S")"
  printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$trap_file"
  # --form-string : avec -F, une valeur qui commence par « < » est lue comme un nom de fichier
  # (curl s'arrête alors en erreur 26 sans rien envoyer).
  sent=$(pbody -w '\n%{http_code}' -F "_glpi_csrf_token=$(ptoken "$token_a")" -F "t=$token_a" \
    -F "answer[$answer_a]=yes" --form-string "comment[$answer_a]=<script>alert(865)</script>" \
    -F "evidence[$answer_a]=@$trap_file;filename=certificat.pdf" "$S")
  expect "portail : envoi des reponses" 200 "$(tail -n 1 <<< "$sent")"
  contains "portail : accuse de reception" "grc-portal-sent" oui "$sent"
  expect "portail : lien deja utilise" 410 "$(pcode "$S?t=$token_a")"
  page_b=$(pbody "$S?t=$(id portal_token_B)")
  contains "portail B : question de son evaluation" "Question securite B" oui "$page_b"
  contains "portail B : aucune question de A" "Question securite A" non "$page_b"
  # Relecture côté GLPI : brouillon listé, commentaire échappé, puis enregistrement.
  link_tab=$(tab GlpiPlugin%5CGrcmanager%5CSupplierEvaluation 'GlpiPlugin%5CGrcmanager%5CSupplierEvaluationLink$1' "$(id evaluation_A)")
  contains "onglet lien securise : reponses a relire" "grc-link-drafts" oui "$link_tab"
  draft=$(grep -o 'draft=[0-9]*' <<< "$link_tab" | head -1 | cut -d= -f2)
  review=$(curl -s -c "$JAR" -b "$JAR" "$L?id=$(id evaluation_A)&draft=$draft")
  contains "relecture : commentaire echappe" "&lt;script&gt;alert(865)" oui "$review"
  contains "relecture : aucune balise executee" "<script>alert(865)" non "$review"
  contains "relecture : piece recue" "certificat.pdf" oui "$review"
  expect "relecture : brouillon d'une autre evaluation" 302 "$(code "$L?id=$(id evaluation_B)&draft=$draft")"
  expect "relecture : enregistrer" 302 \
    "$(code -d "confirm=1&id=$(id evaluation_A)&draft_id=$draft&_glpi_csrf_token=$(csrf)" "$L")"
  expect "relecture : brouillon deja enregistre" 400 \
    "$(code -d "confirm=1&id=$(id evaluation_A)&draft_id=$draft&_glpi_csrf_token=$(csrf)" "$L")"
  # Limitation de débit par jeton (dernier test : ce lien reste ensuite bloqué pour la fenêtre).
  last=""
  for _ in $(seq 1 45); do last=$(pcode "$S?t=$(id portal_token_rate)"); done
  expect "portail : limitation de debit par jeton" 429 "$last"
  rm -f "$trap_file" "$PJAR"
fi
