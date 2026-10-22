#!/usr/bin/env bash
# Matrice de controle d'acces de bout en bout (vraies requetes HTTP, vraie session GLPI).
#
# Usage : access-matrix.sh <url GLPI> <limited|reader|admin> <login> <mot de passe> <json de setup.php>
#   limited : gestionnaire limite a l'entite A -> rien de l'entite B (incident, campagne, contrat,
#             ordinateur), ni lu ni modifie ; ses enregistrements ne touchent pas aux liens vers B
#   reader  : lecteur (lecture seule) limite a A -> ne peut pas s'assigner un incident
#   admin   : super-admin -> tout reste accessible (detecte un controle trop strict)
#
# Les verifications en base (liens d'un risque, approbateurs de la SoA) sont faites par la CI avec
# `setup.php risk-links|soa-approvers` autour de ce script.
set -u
BASE=$1 ROLE=$2 LOGIN=$3 PASSWORD=$4 IDS=$5
JAR=$(mktemp)
FAIL=0
id() { jq -r ".$1" <<< "$IDS"; }
code() { curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$@"; }
body() { curl -s -c "$JAR" -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' "$@"; }
csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/front/central.php" | grep -o 'glpi:csrf_token" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
expect() { # libelle, attendu, obtenu
  if [ "$2" = "$3" ]; then echo "OK  $1 ($3)"; else echo "::error::$ROLE - $1 : attendu $2, obtenu $3"; FAIL=1; fi
}
contains() { # libelle, texte, attendu (oui|non), contenu
  local got=non; grep -qF "$2" <<< "$4" && got=oui
  expect "$1" "$3" "$got"
}
tab() { # itemtype, onglet, id
  body "$BASE/ajax/common.tabs.php?_target=/plugins/grcmanager/front/x.php&_itemtype=$1&_glpi_tab=$2&id=$3"
}

for try in 1 2 3 4 5; do
  rm -f "$JAR"
  token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "login_name=$LOGIN" --data-urlencode "login_password=$PASSWORD" \
    --data-urlencode "_glpi_csrf_token=$token" -d noAUTO=1 "$BASE/front/login.php"
  [ "$(code "$BASE/front/central.php")" = 200 ] && break
  [ "$try" = 5 ] && { echo "::error::connexion impossible pour $LOGIN"; exit 1; }
  sleep 3
done

P=$BASE/plugins/grcmanager/front
if [ "$ROLE" = admin ]; then SEE_B=oui; DENY=200; else SEE_B=non; DENY=403; fi

if [ "$ROLE" = reader ]; then
  expect "s'assigner un incident en lecture seule" 403 \
    "$(code -d "addme_assign=1&plugin_grcmanager_securityincidents_id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/securityincident.form.php")"
  rm -f "$JAR"; exit $FAIL
fi

expect "incident, entite A"            200    "$(code "$P/securityincident.form.php?id=$(id incident_A)")"
expect "incident, entite B"            $DENY  "$(code "$P/securityincident.form.php?id=$(id incident_B)")"
expect "campagne de revue, entite A"   200    "$(code "$P/accessreview.form.php?id=$(id campaign_A)")"
expect "campagne de revue, entite B"   $DENY  "$(code "$P/accessreview.form.php?id=$(id campaign_B)")"

control_tab=$(tab PluginGrcmanagerControl 'PluginGrcmanagerAccessReview$2' "$(id control)")
contains "onglet revues d'acces d'un controle : campagne A" "Campagne securite A" oui     "$control_tab"
contains "onglet revues d'acces d'un controle : campagne B" "Campagne securite B" $SEE_B  "$control_tab"

contracts_tab=$(tab PluginGrcmanagerSupplierRisk 'PluginGrcmanagerSupplierAssessment$2' "$(id supplierrisk)")
contains "onglet contrats du fournisseur : contrat A" "Contrat securite A" oui     "$contracts_tab"
contains "onglet contrats du fournisseur : contrat B" "Contrat securite B" $SEE_B  "$contracts_tab"

risk_page=$(curl -s -c "$JAR" -b "$JAR" "$P/risk.form.php?id=$(id risk)")
contains "actif lie d'une autre entite affiche sur le risque" "PC securite B" $SEE_B "$risk_page"

expect "classification d'un actif de A" 302 \
  "$(code -d "save=1&itemtype=Computer&items_id=$(id computer_A)&confidentiality=high&integrity=high&availability=high&_glpi_csrf_token=$(csrf)" "$P/assetclassification.form.php")"
expect "classification d'un actif de B" "$([ "$ROLE" = admin ] && echo 302 || echo 404)" \
  "$(code -d "save=1&itemtype=Computer&items_id=$(id computer_B)&confidentiality=low&integrity=low&availability=low&_glpi_csrf_token=$(csrf)" "$P/assetclassification.form.php")"

if [ "$ROLE" = limited ]; then
  # Enregistre le risque (objet global) avec l'actif de A : le lien vers B, invisible pour lui,
  # doit rester (verifie en base par la CI). Puis demande d'approbation de la SoA en se designant
  # lui-meme : il doit etre retire des approbateurs (verifie en base par la CI).
  code -d "update=1&id=$(id risk)&title=Risque%20securite&linked_items_Computer[]=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/risk.form.php" > /dev/null
  code -d "request_approval=1&approvers[]=$(id manager)&approvers[]=2&comment=test&_glpi_csrf_token=$(csrf)" "$P/soaapproval.php" > /dev/null
  echo "    (risque enregistre et approbation SoA demandee : verification en base par la CI)"
fi

rm -f "$JAR"
exit $FAIL
