#!/usr/bin/env bash
# Matrice de controle d'acces de bout en bout (vraies requetes HTTP, vraie session GLPI).
#
# Usage : access-matrix.sh <url GLPI> <none|reader|limited|admin> <login> <mot de passe> <json de setup.php>
#   limited : gestionnaire limite a l'entite A -> rien de l'entite B (incident, campagne, contrat,
#             ordinateur), ni lu ni modifie ; ses enregistrements ne touchent pas aux liens vers B
#   none    : aucun droit sur le plugin -> chaque page refusee, aucune creation possible
#   reader  : lecteur (lecture seule) limite a A -> listes, rapports et fiches lisibles, aucune
#             creation possible, ne peut pas s'assigner un incident
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

# Pages consultables (listes, rapports, fiches existantes) : refusees sans droit, ouvertes en lecture.
READABLE="front/accessreview.php front/audit.php front/canonicalproduct.php front/complianceobligation.php
  front/control.php front/ismsrequirement.php front/managementreview.php front/nonconformity.php
  front/objective.php front/policy.php front/risk.php front/securityincident.php front/supplierrisk.php
  front/training.php front/referentiels.php front/riskheatmap.php front/soaapproval.php front/config.php
  front/audit.report.php front/control.report.php front/risk.report.php front/control.csv.php?action=export
  front/evidence.php front/periodicreview.php front/networkflow.php front/accounthygiene.php
  front/compliancecalendar.php front/compliancecalendar.php?ics=1 front/complianceevent.php
  front/parkvulnerability.php front/softwarewatch.php"
# Creations : refusees sans droit comme en lecture seule (etat en base compare par la CI).
create_attempts() { # code attendu, code attendu pour la classification (404 sans droit sur l'actif)
  local incident; incident=$(id incident_A)
  for form in front/accessreview.form.php front/audit.form.php front/canonicalproduct.form.php \
    front/complianceobligation.form.php front/control.form.php front/managementreview.form.php \
    front/nonconformity.form.php front/objective.form.php front/objectivemeasurement.form.php \
    front/policy.form.php front/risk.form.php front/risktreatmentaction.form.php front/supplierrisk.form.php \
    front/training.form.php front/securityincident.form.php front/securityincidentticket.form.php \
    front/securityincidentcve.form.php front/cpereference.form.php front/productalias.form.php; do
    expect "$form : creation" "$1" "$(code -d "add=1&name=securite&title=securite&_glpi_csrf_token=$(csrf)" "$BASE/plugins/grcmanager/$form")"
  done
  expect "front/ismsrequirement.form.php : modification" "$1" \
    "$(code -d "update=1&id=1&status=done&_glpi_csrf_token=$(csrf)" "$P/ismsrequirement.form.php")"
  expect "front/supplierassessment.form.php : questionnaire" "$1" \
    "$(code -d "save=1&id=$(id supplierrisk)&_glpi_csrf_token=$(csrf)" "$P/supplierassessment.form.php")"
  expect "front/assetclassification.form.php : classification" "$2" \
    "$(code -d "save=1&itemtype=Computer&items_id=$(id computer_A)&confidentiality=high&integrity=high&availability=high&_glpi_csrf_token=$(csrf)" "$P/assetclassification.form.php")"
  expect "front/securityincident_item.form.php : lier un actif" "$1" \
    "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$incident&itemtype=Computer&items_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/securityincident_item.form.php")"
  expect "front/securityincidentcost.form.php : ajouter un cout" "$1" \
    "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$incident&name=cout&cost_fixed=10&_glpi_csrf_token=$(csrf)" "$P/securityincidentcost.form.php")"
  expect "front/securityincidenttask.form.php : ajouter une tache" "$1" \
    "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$incident&content=tache&_glpi_csrf_token=$(csrf)" "$P/securityincidenttask.form.php")"
  # Issue #144 : creer un flux (source et destination fournies) ou ouvrir la page d'import.
  expect "front/networkflow.form.php : creation d'un flux" "$1" \
    "$(code -d "add=1&source_label=securite&destination_label=securite&ports=22&_glpi_csrf_token=$(csrf)" "$P/networkflow.form.php")"
  expect "front/networkflow.import.php : import CSV" "$1" "$(code "$P/networkflow.import.php")"
  # Issue #142 : evenement du calendrier de conformite.
  expect "front/complianceevent.form.php : creation d'un evenement" "$1" \
    "$(code -d "add=1&name=securite&date_begin=2030-01-01+09:00:00&_glpi_csrf_token=$(csrf)" "$P/complianceevent.form.php")"
  # Issue #160 : surveiller un logiciel cree un produit, un CPE et un alias (compare en base).
  expect "front/softwarewatch.php : surveiller un logiciel" "$1"     "$(code -d "confirm=1&software=securite&cpe=cpe:2.3:a:securite:securite&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
  expect "front/softwarewatch.php : ignorer un logiciel" "$1"     "$(code -d "ignore=1&software=securite&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
  # Issue #184 : ticket de revue des comptes (exige aussi le droit natif de creer un ticket).
  expect "front/accounthygiene.php : ticket de revue" "$1" \
    "$(code -d "create_ticket=1&users_id[]=2&_glpi_csrf_token=$(csrf)" "$P/accounthygiene.php")"
  # Issue #141 : activer une revue cree un gabarit et un ticket recurrent (compare en base).
  expect "front/periodicreview.php : activer une revue" "$1" \
    "$(code -d "activate=1&id=1&frequency_months=6&_glpi_csrf_token=$(csrf)" "$P/periodicreview.php")"
  expect "front/evidence.form.php : date de validite d'une preuve" "$1" \
    "$(code -d "save=1&itemtype=PluginGrcmanagerPolicy&items_id=$(id policy_A)&documents_id=$(id document_A)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"
}

if [ "$ROLE" = none ]; then
  for p in $READABLE; do expect "$p : sans droit" 403 "$(code "$BASE/plugins/grcmanager/$p")"; done
  for p in "risk.form.php?id=$(id risk)" "control.form.php?id=$(id control)" "securityincident.form.php?id=$(id incident_A)" \
           "accessreview.form.php?id=$(id campaign_A)" "supplierrisk.form.php?id=$(id supplierrisk)"; do
    expect "front/$p : sans droit" 403 "$(code "$P/$p")"
  done
  # Page des responsables de revue : ouverte a toute session, ne montre que leurs propres lignes.
  contains "front/accessreview.mine.php : aucune campagne d'autrui" "Campagne securite" non "$(curl -s -c "$JAR" -b "$JAR" "$P/accessreview.mine.php")"
  create_attempts 403 404
  rm -f "$JAR"; exit $FAIL
fi

if [ "$ROLE" = reader ]; then
  for p in $READABLE; do expect "$p : en lecture" 200 "$(code "$BASE/plugins/grcmanager/$p")"; done
  for p in "risk.form.php?id=$(id risk)" "control.form.php?id=$(id control)" "securityincident.form.php?id=$(id incident_A)" \
           "accessreview.form.php?id=$(id campaign_A)" "supplierrisk.form.php?id=$(id supplierrisk)"; do
    expect "front/$p : en lecture" 200 "$(code "$P/$p")"
  done
  expect "s'assigner un incident en lecture seule" 403 \
    "$(code -d "addme_assign=1&plugin_grcmanager_securityincidents_id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/securityincident.form.php")"
  create_attempts 403 403
  rm -f "$JAR"; exit $FAIL
fi

if [ "$ROLE" = admin ]; then
  for p in $READABLE; do expect "$p : super-admin" 200 "$(code "$BASE/plugins/grcmanager/$p")"; done
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

# Issue #145 : preuves datées, uniquement celles de ses entités.
evidences=$(curl -s -c "$JAR" -b "$JAR" "$P/evidence.php")
contains "liste des preuves : preuve de A" "Preuve securite A" oui    "$evidences"
contains "liste des preuves : preuve de B" "Preuve securite B" $SEE_B "$evidences"
expect "date de validite d'une preuve de A" 302 \
  "$(code -d "save=1&itemtype=PluginGrcmanagerPolicy&items_id=$(id policy_A)&documents_id=$(id document_A)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"
expect "date de validite d'une preuve de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "save=1&itemtype=PluginGrcmanagerPolicy&items_id=$(id policy_B)&documents_id=$(id document_B)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"
expect "date de validite : document d'une autre politique" 404 \
  "$(code -d "save=1&itemtype=PluginGrcmanagerPolicy&items_id=$(id policy_A)&documents_id=$(id document_B)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"

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
