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
# Issue #380 : preuve envoyee depuis le formulaire d'un test (vrai PDF, et faux PDF contenant du PHP).
EVIDENCE_PDF=$(mktemp); FAKE_PDF=$(mktemp)
trap 'rm -f "$EVIDENCE_PDF" "$FAKE_PDF"' EXIT
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$EVIDENCE_PDF"
printf "<?php echo 'securite';\n" > "$FAKE_PDF"
upload_evidence() { # fichier, nom annonce, [jeton CSRF : oui|non]
  local token=(-F "_glpi_csrf_token=$(csrf)")
  [ "${3:-oui}" = non ] && token=()
  code -F add=1 -F "plugin_grcmanager_controls_id=$(id control)" -F result=effective \
    -F "evidence_file=@$1;filename=$2" "${token[@]}" "$BASE/plugins/grcmanager/front/controltest.form.php"
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
  front/parkvulnerability.php front/parkmachines.php front/softwarewatch.php front/riskcatalog.php front/waiver.php
  front/coveragerule.php front/playbook.php front/impactanalysis.php front/causalgraph.php
  front/ebiosstudy.php
  front/auditlog.php front/controltest.php
  front/impactanalysis.php?export=continuity front/maturity.php front/frameworkassessment.php front/crisisexercise.php front/complianceaudit.php
  front/dashboard.php front/networkflow.report.php front/breachexposure.php front/networkflow.matrix.php
  front/processingactivity.php front/datasubjectrequest.php front/processingactivity.export.php?format=pdf
  front/processingactivity.export.php?format=csv
  front/riskappetite.php front/riskindicator.php
  front/businessprocess.php front/businessprocess.php?export=csv front/businessprocess.report.php"
# Creations : refusees sans droit comme en lecture seule (etat en base compare par la CI).
create_attempts() { # code attendu, code attendu pour la classification (404 sans droit sur l'actif)
  local incident; incident=$(id incident_A)
  for form in front/accessreview.form.php front/audit.form.php front/canonicalproduct.form.php \
    front/complianceobligation.form.php front/control.form.php front/managementreview.form.php \
    front/nonconformity.form.php front/objective.form.php front/objectivemeasurement.form.php \
    front/policy.form.php front/risk.form.php front/risktreatmentaction.form.php front/supplierrisk.form.php \
    front/training.form.php front/securityincident.form.php front/securityincidentticket.form.php \
    front/securityincidentcve.form.php front/securityincidentioc.form.php \
    front/securityincidenttechnique.form.php front/securityincidentaction.form.php \
    front/securityincidentplaybooktask.form.php front/playbook.form.php front/playbookstep.form.php \
    front/securityincidentedrimport.php front/securityincidentevidence.form.php \
    front/ebiosstudy.form.php front/ebioselement.form.php \
    front/securityincidentteam.form.php \
    front/cpereference.form.php front/productalias.form.php \
    front/waiver.form.php front/coveragerule.form.php front/controltest.form.php \
    front/processingactivity.form.php front/datasubjectrequest.form.php \
    front/businessprocess.form.php front/riskindicator.form.php; do
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
  # Issue #160 : ticket de correction a la demande (compare en base).
  expect "front/parkvulnerability.php : creer un ticket" "$1" \
    "$(code -d "create_ticket=1&canonical_id=1&_glpi_csrf_token=$(csrf)" "$P/parkvulnerability.php")"
  # Issue #169 : rapport post-incident (cree un Document, compare en base).
  expect "front/securityincident.postmortem.php : generer" "$1" \
    "$(code -d "generate=1&id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/securityincident.postmortem.php")"
  # Issue #168 : lancer un exercice de crise (cree un incident fictif, compare en base).
  expect "front/crisisexercise.php : lancer" "$1" \
    "$(code -d "launch=ransomware&_glpi_csrf_token=$(csrf)" "$P/crisisexercise.php")"
  # Issue #185 : activer la cellule de crise sur un incident reel (compare en base).
  expect "front/crisisexercise.form.php : activer la cellule de crise" "$1" \
    "$(code -d "activate=1&incident=$(id incident_A)&scenario=ransomware&_glpi_csrf_token=$(csrf)" "$P/crisisexercise.form.php")"
  # Issue #162 : ticket de mise en conformite d'un controle technique (compare en base).
  expect "front/complianceaudit.php : ticket de mise en conformite" "$1" \
    "$(code -d "create_ticket=antivirus&_glpi_csrf_token=$(csrf)" "$P/complianceaudit.php")"
  # Issue #171 : relier deux elements de l'effet domino (compare en base).
  expect "front/causallink.form.php : relier" "$1" \
    "$(code -d "add=1&item_key=GlpiPlugin%5CGrcmanager%5CRisk:1&other=GlpiPlugin%5CGrcmanager%5CRisk:2&direction=effect&_glpi_csrf_token=$(csrf)" "$P/causallink.form.php")"
  # Issue #177 : creer le risque d'un point de defaillance unique (compare en base).
  expect "front/impactanalysis.php : creer le risque" "$1" \
    "$(code -d "create_risk=1&itemtype=Computer&items_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/impactanalysis.php")"
  # Issue #178 : importer un risque type cree un risque et ses liens (compare en base).
  expect "front/riskcatalog.php : importer un risque type" "$1" \
    "$(code -d "import=1&keys[]=admin_exposed&_glpi_csrf_token=$(csrf)" "$P/riskcatalog.php")"
  # Issue #160 : surveiller un logiciel cree un produit, un CPE et un alias (compare en base).
  expect "front/softwarewatch.php : surveiller un logiciel" "$1"     "$(code -d "confirm=1&software=securite&cpe=cpe:2.3:a:securite:securite&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
  expect "front/softwarewatch.php : ignorer un logiciel" "$1"     "$(code -d "ignore=1&software=securite&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
  # Issue #184 : ticket de revue des comptes (exige aussi le droit natif de creer un ticket).
  expect "front/accounthygiene.php : ticket de revue" "$1" \
    "$(code -d "create_ticket=1&users_id[]=2&_glpi_csrf_token=$(csrf)" "$P/accounthygiene.php")"
  # Issue #141 : activer une revue cree un gabarit et un ticket recurrent (compare en base).
  expect "front/periodicreview.php : activer une revue" "$1" \
    "$(code -d "activate=1&id=1&frequency_months=6&_glpi_csrf_token=$(csrf)" "$P/periodicreview.php")"
  # Issue #140 : l'equipe d'un audit (audience de ses documents) ne change qu'avec le droit de
  # modifier l'audit.
  expect "front/auditteam.form.php : ajouter un membre" "$1" \
    "$(code -d "add=1&plugin_grcmanager_audits_id=$(id audit)&users_id=2&role=auditee&_glpi_csrf_token=$(csrf)" "$P/auditteam.form.php")"
  expect "front/evidence.form.php : date de validite d'une preuve" "$1" \
    "$(code -d "save=1&itemtype=GlpiPlugin%5CGrcmanager%5CPolicy&items_id=$(id policy_A)&documents_id=$(id document_A)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"
  # Issue #372 : ticket lie depuis l'incident (exige aussi de pouvoir modifier l'incident).
  expect "front/securityincidentticket.form.php : creer un ticket lie" "$1" \
    "$(code -d "create_ticket=1&plugin_grcmanager_securityincidents_id=$incident&_glpi_csrf_token=$(csrf)" "$P/securityincidentticket.form.php")"
  # Issue #378 : appetence (registre sensible), releve, releve automatique et ticket d'un KRI.
  expect "front/riskappetite.php : enregistrer l'appetence" "$1" \
    "$(code -d "save_appetite=1&appetite[technical][appetite_level]=low&_glpi_csrf_token=$(csrf)" "$P/riskappetite.php")"
  expect "front/riskindicatorvalue.form.php : ajouter un releve" "$1" \
    "$(code -d "add=1&plugin_grcmanager_riskindicators_id=$(id kri_A)&value=3&_glpi_csrf_token=$(csrf)" "$P/riskindicatorvalue.form.php")"
  expect "front/riskindicator.form.php : relever maintenant" "$1" \
    "$(code -d "measure=1&id=$(id kri_A)&_glpi_csrf_token=$(csrf)" "$P/riskindicator.form.php")"
  expect "front/riskindicator.form.php : ticket au rouge" "$1" \
    "$(code -d "create_ticket=1&id=$(id kri_A)&_glpi_csrf_token=$(csrf)" "$P/riskindicator.form.php")"
  # Issue #377 : dependance et BIA d'un processus metier (exigent de pouvoir modifier le processus).
  expect "front/businessprocessitem.form.php : lier un element" "$1" \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&itemtype=Computer&items_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/businessprocessitem.form.php")"
  expect "front/businessimpact.form.php : enregistrer la BIA" "$1" \
    "$(code -d "save=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&levels[4][financial]=4&_glpi_csrf_token=$(csrf)" "$P/businessimpact.form.php")"
  # Issue #377, deuxieme etape : dependance entre processus, risque lie, risque propose pour un actif.
  expect "front/businessprocesslink.form.php : lier un processus" "$1" \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&plugin_grcmanager_businessprocesses_id_upstream=$(id process_B)&_glpi_csrf_token=$(csrf)" "$P/businessprocesslink.form.php")"
  expect "front/businessprocessrisk.form.php : lier un risque" "$1" \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&plugin_grcmanager_risks_id=$(id risk)&_glpi_csrf_token=$(csrf)" "$P/businessprocessrisk.form.php")"
  expect "front/businessprocessrisk.form.php : proposer un risque" "$1" \
    "$(code -d "create_risk=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&itemtype=Computer&items_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/businessprocessrisk.form.php")"
  # Issue #377, troisieme etape : temps de reprise mesure d'un processus (droit de le modifier).
  expect "front/businessprocessrecovery.form.php : enregistrer un test de reprise" "$1" \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&measured_minutes=60&_glpi_csrf_token=$(csrf)" "$P/businessprocessrecovery.form.php")"
  expect "front/businessprocess.form.php : modifier la strategie de reprise" "$1" \
    "$(code -d "update=1&id=$(id process_A)&degraded_mode=securite&_glpi_csrf_token=$(csrf)" "$P/businessprocess.form.php")"
  # Issue #372 : declarer un incident depuis un element (droit de creer un incident).
  expect "front/securityincidentlinkeditem.form.php : declarer un incident" "$1" \
    "$(code -d "declare=1&itemtype=Computer&items_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/securityincidentlinkeditem.form.php")"
  # Issue #380 : plan de test d'une mesure (droit de modifier la mesure).
  expect "front/controltest.form.php : plan de test" "$1" \
    "$(code -d "save_plan=1&plugin_grcmanager_controls_id=$(id control)&test_frequency_months=12&_glpi_csrf_token=$(csrf)" "$P/controltest.form.php")"
  # Issue #380, troisieme etape : envoyer une preuve, ou enregistrer le resultat d'un controle technique.
  expect "front/controltest.form.php : envoyer une preuve" "$1" "$(upload_evidence "$EVIDENCE_PDF" preuve-securite.pdf)"
  expect "front/controltest.form.php : resultat d'un controle technique" "$1" \
    "$(code -d "record_check=antivirus&plugin_grcmanager_controls_id=$(id control)&_glpi_csrf_token=$(csrf)" "$P/controltest.form.php")"
  # Issue #139 : joindre ou retirer une copie de quiz exige de pouvoir modifier la session.
  expect "front/trainingquiz.form.php : retirer une copie" "$1" \
    "$(code -d "remove=1&participations_id=$(id quiz)&_glpi_csrf_token=$(csrf)" "$P/trainingquiz.form.php")"
}

# Issue #143 : cartes des incidents de securite, calculees par le plugin (et non plus par le
# fournisseur generique de GLPI, sans droit du plugin ni cloisonnement). Tous les profils voient
# les tableaux de bord (droit natif) : seuls ceux qui lisent les incidents en obtiennent les cartes,
# et seulement pour leurs entites. La vue « incidents » de la page n'est proposee qu'a eux.
card() { # identifiant de la carte, type de widget
  body "$BASE/ajax/dashboard.php?action=get_card&dashboard=grcmanager-security-incidents&card_id=$1&args%5Bwidgettype%5D=$2&args%5Bgridstack_id%5D=matrice&args%5Bforce%5D=1"
}
SEE_INCIDENTS=oui
case $ROLE in none|general) SEE_INCIDENTS=non ;; esac
by_entity=$(card securityincidents_by_entity multipleNumber)
contains "carte incidents par entite : entite A" "Securite ISO A" $SEE_INCIDENTS "$by_entity"
contains "carte incidents par entite : entite B" "Securite ISO B" $SEE_B "$by_entity"
contains "carte incidents ouverts : chiffre" "big-number" $SEE_INCIDENTS "$(card securityincidents_bn_open bigNumber)"
contains "page du tableau de bord : vue incidents proposee" "view=incidents" $SEE_INCIDENTS \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/dashboard.php")"
# Issue #380 : tests d'efficacite en retard, seulement pour qui lit les mesures.
SEE_CONTROLS=oui
[ "$ROLE" = none ] && SEE_CONTROLS=non
contains "carte tests d'efficacite en retard : chiffre" "big-number" $SEE_CONTROLS \
  "$(body "$BASE/ajax/dashboard.php?action=get_card&dashboard=grcmanager-isms-overview&card_id=grcmanager_overdue_control_tests&args%5Bwidgettype%5D=bigNumber&args%5Bgridstack_id%5D=matrice&args%5Bforce%5D=1")"
contains "page du tableau de bord, vue incidents : grille" "\"current\":\"grcmanager-security-incidents\"" $SEE_INCIDENTS \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/dashboard.php?view=incidents")"
# Issue #377, quatrieme etape : indicateurs des processus metier (BIA a revoir, reprise au-dela du
# RTO), sous le seul droit de la continuite. Sans lui, la carte est videe : ni chiffre ni lien.
isms_card() { # identifiant de la carte
  body "$BASE/ajax/dashboard.php?action=get_card&dashboard=grcmanager-isms-overview&card_id=$1&args%5Bwidgettype%5D=bigNumber&args%5Bgridstack_id%5D=matrice&args%5Bforce%5D=1"
}
SEE_CONTINUITY=oui
case $ROLE in none|general) SEE_CONTINUITY=non ;; esac
for c in grcmanager_business_processes_bia_due grcmanager_business_processes_recovery_above_rto; do
  contains "carte $c : lien vers les processus" "businessprocess.php" $SEE_CONTINUITY "$(isms_card $c)"
done

if [ "$ROLE" = none ]; then
  for p in $READABLE; do expect "$p : sans droit" 403 "$(code "$BASE/plugins/grcmanager/$p")"; done
  for p in "risk.form.php?id=$(id risk)" "control.form.php?id=$(id control)" "securityincident.form.php?id=$(id incident_A)" \
           "accessreview.form.php?id=$(id campaign_A)" "supplierrisk.form.php?id=$(id supplierrisk)" \
           "businessprocess.form.php?id=$(id process_A)"; do
    expect "front/$p : sans droit" 403 "$(code "$P/$p")"
  done
  expect "front/training.report.php : sans droit" 403 "$(code "$P/training.report.php?id=$(id training)")"
  expect "front/ebiosstudy.report.php : sans droit" 403 "$(code "$P/ebiosstudy.report.php?id=1")"
  expect "front/businessprocess.report.php : PCA d'un processus, sans droit" 403 \
    "$(code "$P/businessprocess.report.php?id=$(id process_A)")"
  expect "front/riskindicator.form.php : sans droit" 403 "$(code "$P/riskindicator.form.php?id=$(id kri_A)")"
  expect "front/trainingquiz.send.php : copie d'un autre, sans droit" 403 "$(code "$P/trainingquiz.send.php?id=$(id quiz)")"
  # Page des responsables de revue : ouverte a toute session, ne montre que leurs propres lignes.
  contains "front/accessreview.mine.php : aucune campagne d'autrui" "Campagne securite" non "$(curl -s -c "$JAR" -b "$JAR" "$P/accessreview.mine.php")"
  # Issue #168 : la salle de crise refuse avant meme de chercher l'exercice.
  expect "front/crisisexercise.form.php : terminer sans droit" 403 \
    "$(code -d "finish=1&id=1&_glpi_csrf_token=$(csrf)" "$P/crisisexercise.form.php")"
  # Revue de securite 2.11.0 : contenu des onglets du plugin demande directement sur un
  # ordinateur lisible, sans le droit du plugin (GLPI ne consulte pas getTabNameForItem()).
  for t in 'GlpiPlugin%5CGrcmanager%5CImpactAnalysis$1' 'GlpiPlugin%5CGrcmanager%5CAssetClassification$1' \
           'GlpiPlugin%5CGrcmanager%5CParkVulnerability$1' 'GlpiPlugin%5CGrcmanager%5CRisk$1' \
           'GlpiPlugin%5CGrcmanager%5CSecurityIncidentLinkedItem$1' \
           'GlpiPlugin%5CGrcmanager%5CProcessingActivity$1' \
           'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1'; do
    contains "onglet $t d'un ordinateur : sans droit" "plugins/grcmanager" non "$(tab Computer "$t" "$(id computer_A)")"
  done
  # Issue #377, troisieme etape : classification et processus d'une application et d'un fournisseur
  # lisibles, demandes directement sans aucun droit du plugin.
  for t in 'GlpiPlugin%5CGrcmanager%5CAssetClassification$1' 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1'; do
    contains "onglet $t d'une application : sans droit" "plugins/grcmanager" non "$(tab Appliance "$t" "$(id appliance_A)")"
    contains "onglet $t d'un fournisseur : sans droit" "plugins/grcmanager" non "$(tab Supplier "$t" "$(id supplier)")"
  done
  contains "onglet Processus metier d'un risque : sans droit" "Processus securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id risk)")"
  # Issue #377, quatrieme etape : carte des dependances demandee directement, sans droit.
  contains "onglet Carte des dependances d'un processus : sans droit" "Processus securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(id process_A)")"
  # Issue #403 : les referentiels actifs se reglent avec le droit de modifier la configuration.
  expect "front/config.php : desactiver les referentiels" 403 \
    "$(code -d "update_frameworks=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #380 : le rappel des tests au groupe RSSI se regle avec le droit de modifier la configuration.
  expect "front/config.php : rappel des tests au groupe RSSI" 403 \
    "$(code -d "update_controltest_reminder=1&controltest_reminder_ciso=always&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #378 : le niveau d'alerte des KRI se regle avec le droit de modifier la configuration.
  expect "front/config.php : niveau d'alerte des KRI" 403 \
    "$(code -d "update_kri_alert=1&kri_alert_level=orange&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #375 : relier un traitement a un incident exige de pouvoir modifier l'incident.
  expect "front/processingactivity.form.php : relier un incident en lecture seule" 403 \
    "$(code -d "link_incident=1&plugin_grcmanager_processingactivities_id=$(id processing_A)&incidents_id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/processingactivity.form.php")"
  # Issue #380 : la preuve envoyee pour une mesure, dans son entite A, lui est refusee malgre son
  # droit Documents : il ne lit pas les mesures.
  expect "preuve envoyee d'une mesure : droit Documents sans lecture des mesures" 403 \
    "$(code "$BASE/front/document.send.php?docid=$(id evidence_upload)")"
  create_attempts 403 403
  rm -f "$JAR"; exit $FAIL
fi

# Registres sensibles : tout le SMSI, mais pas le droit dedie. Risques, vulnerabilites du parc,
# flux reseau et analyse d'impact lui sont refuses, et aucun titre de risque n'apparait ailleurs.
if [ "$ROLE" = general ]; then
  for p in front/risk.php front/supplierrisk.php front/riskheatmap.php front/risk.report.php \
           front/networkflow.php front/networkflow.report.php front/networkflow.matrix.php front/parkvulnerability.php \
           front/impactanalysis.php front/causalgraph.php front/breachexposure.php \
           front/riskappetite.php front/riskindicator.php \
           front/businessprocess.php front/businessprocess.php?export=csv front/businessprocess.report.php; do
    expect "$p : sans le droit des registres sensibles" 403 "$(code "$BASE/plugins/grcmanager/$p")"
  done
  expect "front/businessprocess.report.php : PCA d'un processus, sans le droit de la continuite" 403 \
    "$(code "$P/businessprocess.report.php?id=$(id process_A)")"
  contains "onglet Processus metier d'un risque : rien" "Processus securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id risk)")"
  contains "onglet Risques d'un processus : rien" "Risque securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id process_A)")"
  # Issue #377, troisieme etape : ni strategie de reprise ni test sans le droit de la continuite.
  contains "onglet Strategie de reprise d'un processus : rien" "Test de reprise A" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRecovery$1' "$(id process_A)")"
  # Issue #377, quatrieme etape : ni carte des dependances sans le droit de la continuite.
  contains "onglet Carte des dependances d'un processus : rien" "Processus securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(id process_A)")"
  expect "front/businessprocessrecovery.form.php : sans le droit de la continuite" 403 \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&measured_minutes=60&_glpi_csrf_token=$(csrf)" "$P/businessprocessrecovery.form.php")"
  contains "onglet Processus metier d'une application : rien" "Processus securite" non \
    "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1' "$(id appliance_A)")"
  expect "front/risk.form.php : sans le droit des registres sensibles" 403 "$(code "$P/risk.form.php?id=$(id risk)")"
  expect "front/riskindicator.form.php : sans le droit des registres sensibles" 403 "$(code "$P/riskindicator.form.php?id=$(id kri_A)")"
  # Issue #183 : la surveillance des fuites (cle d'API, domaines) se regle avec le droit des registres
  # sensibles, et l'onglet des expositions de la fiche utilisateur reste vide sans lui.
  expect "front/config.php : regler la surveillance des fuites" 403 \
    "$(code -d "update_breach_config=1&hibp_enabled=1&hibp_domains=example.com&hibp_api_key=x&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  contains "onglet Expositions d'un utilisateur : rien" "breach" non \
    "$(tab User 'GlpiPlugin%5CGrcmanager%5CBreachExposure$1' 2)"
  expect "front/breachexposure.form.php : lire une exposition" 403 \
    "$(code "$P/breachexposure.form.php?id=$(id breachexposure)")"
  expect "front/breachexposure.form.php : changer son statut" 403 \
    "$(code -d "update=1&id=$(id breachexposure)&status=handled&_glpi_csrf_token=$(csrf)" "$P/breachexposure.form.php")"
  # Issue #375 : le registre des traitements (RGPD) a son propre droit, distinct du droit general
  # du SMSI : liste, fiche, exports, modification et onglet des actifs lui sont refuses.
  for p in front/processingactivity.php front/datasubjectrequest.php "front/datasubjectrequest.form.php?id=$(id dsr_A)" \
           "front/processingactivity.export.php?format=pdf" \
           "front/processingactivity.export.php?format=csv" "front/processingactivity.form.php?id=$(id processing_A)"; do
    expect "$p : sans le droit du registre des traitements" 403 "$(code "$BASE/plugins/grcmanager/$p")"
  done
  expect "front/dpia.report.php : sans le droit du registre des traitements" 403 \
    "$(code "$P/dpia.report.php?id=$(id processing_A)")"
  expect "front/dpia.form.php : enregistrer une AIPD" 403 \
    "$(code -d "save=1&plugin_grcmanager_processingactivities_id=$(id processing_A)&_glpi_csrf_token=$(csrf)" "$P/dpia.form.php")"
  expect "front/processingactivity.form.php : modifier une fiche" 403 \
    "$(code -d "update=1&id=$(id processing_A)&name=securite&_glpi_csrf_token=$(csrf)" "$P/processingactivity.form.php")"
  contains "onglet Registre des traitements d'un ordinateur : rien" "Traitement securite" non \
    "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CProcessingActivity$1' "$(id computer_A)")"
  for p in front/control.php front/policy.php front/audit.php front/training.php front/complianceobligation.php; do
    expect "$p : droit general du SMSI" 200 "$(code "$BASE/plugins/grcmanager/$p")"
  done
  contains "fiche d'un controle : aucun titre de risque lie" "Risque securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CControl$main' "$(id control)")"
  # Issue #377 : sans le droit de la continuite, ni fiche de processus ni processus sur l'ordinateur.
  expect "front/businessprocess.form.php : sans le droit de la continuite" 403 "$(code "$P/businessprocess.form.php?id=$(id process_A)")"
  contains "onglet Processus metier d'un ordinateur : rien" "Processus securite" non \
    "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1' "$(id computer_A)")"
  contains "onglet Risques d'un ordinateur : rien" "Risque securite" non \
    "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CRisk$1' "$(id computer_A)")"
  # Issue #372 : sans droit sur les incidents, l'onglet de l'ordinateur ne montre aucun incident.
  contains "onglet Incidents de securite d'un ordinateur : rien" "Incident securite" non \
    "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CSecurityIncidentLinkedItem$1' "$(id computer_A)")"
  rm -f "$JAR"; exit $FAIL
fi

if [ "$ROLE" = reader ]; then
  for p in $READABLE; do expect "$p : en lecture" 200 "$(code "$BASE/plugins/grcmanager/$p")"; done
  for p in "risk.form.php?id=$(id risk)" "control.form.php?id=$(id control)" "securityincident.form.php?id=$(id incident_A)" \
           "accessreview.form.php?id=$(id campaign_A)" "supplierrisk.form.php?id=$(id supplierrisk)" \
           "businessprocess.form.php?id=$(id process_A)"; do
    expect "front/$p : en lecture" 200 "$(code "$P/$p")"
  done
  expect "front/training.report.php : en lecture" 200 "$(code "$P/training.report.php?id=$(id training)")"
  expect "front/riskindicator.form.php : KRI de A en lecture" 200 "$(code "$P/riskindicator.form.php?id=$(id kri_A)")"
  expect "front/riskindicator.form.php : KRI de B en lecture" 403 "$(code "$P/riskindicator.form.php?id=$(id kri_B)")"
  expect "front/trainingquiz.send.php : copie d'un autre, en lecture" 403 "$(code "$P/trainingquiz.send.php?id=$(id quiz)")"
  # Issue #372 : l'onglet de l'ordinateur liste l'incident de A, pas celui de B, sans bouton.
  incidents_tab=$(tab Computer 'GlpiPlugin%5CGrcmanager%5CSecurityIncidentLinkedItem$1' "$(id computer_A)")
  contains "onglet Incidents de securite d'un ordinateur : incident A" "Incident securite A" oui "$incidents_tab"
  contains "onglet Incidents de securite d'un ordinateur : incident B" "Incident securite B" non "$incidents_tab"
  contains "onglet Incidents de securite d'un ordinateur : pas de bouton en lecture" "grcmanager-declare-incident" non "$incidents_tab"
  expect "s'assigner un incident en lecture seule" 403 \
    "$(code -d "addme_assign=1&plugin_grcmanager_securityincidents_id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/securityincident.form.php")"
  # Issue #403 : les referentiels actifs se reglent avec le droit de modifier la configuration.
  expect "front/config.php : desactiver les referentiels" 403 \
    "$(code -d "update_frameworks=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #380 : le rappel des tests au groupe RSSI se regle avec le droit de modifier la configuration.
  expect "front/config.php : rappel des tests au groupe RSSI" 403 \
    "$(code -d "update_controltest_reminder=1&controltest_reminder_ciso=always&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #378 : le niveau d'alerte des KRI se regle avec le droit de modifier la configuration.
  expect "front/config.php : niveau d'alerte des KRI" 403 \
    "$(code -d "update_kri_alert=1&kri_alert_level=orange&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #375 : relier un traitement a un incident exige de pouvoir modifier l'incident.
  expect "front/processingactivity.form.php : relier un incident en lecture seule" 403 \
    "$(code -d "link_incident=1&plugin_grcmanager_processingactivities_id=$(id processing_A)&incidents_id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/processingactivity.form.php")"
  create_attempts 403 403
  rm -f "$JAR"; exit $FAIL
fi

if [ "$ROLE" = admin ]; then
  for p in $READABLE; do expect "$p : super-admin" 200 "$(code "$BASE/plugins/grcmanager/$p")"; done
  # Issue #380 : reglage du rappel des tests d'efficacite au groupe RSSI. Sans jeton CSRF, refuse
  # et inchange ; avec, enregistre ; une valeur inconnue revient au reglage par defaut.
  expect "front/config.php : rappel des tests, sans jeton CSRF" 403 \
    "$(code -d "update_controltest_reminder=1&controltest_reminder_ciso=always" "$P/config.php")"
  contains "front/config.php : rappel des tests inchange sans jeton" 'value="always" selected' non \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
  expect "front/config.php : rappel des tests au groupe RSSI" 302 \
    "$(code -d "update_controltest_reminder=1&controltest_reminder_ciso=always&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  contains "front/config.php : rappel des tests enregistre" 'value="always" selected' oui \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
  expect "front/config.php : rappel des tests, valeur inconnue" 302 \
    "$(code -d "update_controltest_reminder=1&controltest_reminder_ciso=x&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  contains "front/config.php : rappel des tests par defaut" 'value="overdue" selected' oui \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
  # Issue #378 : niveau d'alerte des KRI. Sans jeton CSRF, refuse et inchange ; avec, enregistre ;
  # une valeur inconnue revient au rouge.
  expect "front/config.php : alerte des KRI, sans jeton CSRF" 403 \
    "$(code -d "update_kri_alert=1&kri_alert_level=orange" "$P/config.php")"
  contains "front/config.php : alerte des KRI inchangee sans jeton" 'value="orange" selected' non \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
  expect "front/config.php : alerte des KRI des l'orange" 302 \
    "$(code -d "update_kri_alert=1&kri_alert_level=orange&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  contains "front/config.php : alerte des KRI enregistree" 'value="orange" selected' oui \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
  expect "front/config.php : alerte des KRI, valeur inconnue" 302 \
    "$(code -d "update_kri_alert=1&kri_alert_level=x&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  contains "front/config.php : alerte des KRI par defaut" 'value="red" selected' oui \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
fi

expect "incident, entite A"            200    "$(code "$P/securityincident.form.php?id=$(id incident_A)")"
expect "incident, entite B"            $DENY  "$(code "$P/securityincident.form.php?id=$(id incident_B)")"
# Revue de securite 2.11.0 : une reference CVE exige de pouvoir modifier son incident.
expect "reference CVE sur un incident de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$(id incident_B)&cve_id=CVE-2099-0001&_glpi_csrf_token=$(csrf)" "$P/securityincidentcve.form.php")"
# Issue #369 : un IOC exige aussi de pouvoir modifier son incident.
expect "IOC sur un incident de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$(id incident_B)&iocs=198.51.100.1&_glpi_csrf_token=$(csrf)" "$P/securityincidentioc.form.php")"
expect "technique ATT&CK sur un incident de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$(id incident_B)&technique=T1566&_glpi_csrf_token=$(csrf)" "$P/securityincidenttechnique.form.php")"
expect "action d'investigation sur un incident de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$(id incident_B)&target=PC-B&tool=edr&_glpi_csrf_token=$(csrf)" "$P/securityincidentaction.form.php")"
expect "journal des actions (PDF) d'un incident de B" "$([ "$ROLE" = admin ] && echo 200 || echo 404)" \
  "$(code "$P/securityincidentaction.form.php?pdf=$(id incident_B)")"
expect "tâche de remédiation sur un incident de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_securityincidents_id=$(id incident_B)&name=PHPUnit&_glpi_csrf_token=$(csrf)" "$P/securityincidentplaybooktask.form.php")"
expect "plan de remédiation (PDF) d'un incident de B" "$([ "$ROLE" = admin ] && echo 200 || echo 404)" \
  "$(code "$P/securityincidentplaybooktask.form.php?pdf=$(id incident_B)")"
# Issue #372 : un ticket lie exige de pouvoir modifier l'incident (et de creer un ticket).
expect "ticket lie a un incident de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "create_ticket=1&plugin_grcmanager_securityincidents_id=$(id incident_B)&_glpi_csrf_token=$(csrf)" "$P/securityincidentticket.form.php")"
# Issue #372 : onglet « Incidents de securite » d'un ordinateur de A, lie aux incidents de A et de B :
# l'incident de B n'est ni liste ni compte hors super-admin ; « Declarer un incident » exige de lire
# l'element (ordinateur de B refuse) et n'accepte que les types de l'onglet.
incidents_tab=$(tab Computer 'GlpiPlugin%5CGrcmanager%5CSecurityIncidentLinkedItem$1' "$(id computer_A)")
contains "onglet Incidents de securite d'un ordinateur : incident A" "Incident securite A" oui    "$incidents_tab"
contains "onglet Incidents de securite d'un ordinateur : incident B" "Incident securite B" $SEE_B "$incidents_tab"
contains "onglet Incidents de securite d'un ordinateur : bouton" "grcmanager-declare-incident" oui "$incidents_tab"
expect "declarer un incident depuis un ordinateur de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "declare=1&itemtype=Computer&items_id=$(id computer_B)&_glpi_csrf_token=$(csrf)" "$P/securityincidentlinkeditem.form.php")"
expect "declarer un incident depuis un type hors onglet" 403 \
  "$(code -d "declare=1&itemtype=Entity&items_id=$(id a)&_glpi_csrf_token=$(csrf)" "$P/securityincidentlinkeditem.form.php")"
expect "declarer un incident sans jeton CSRF" 403 \
  "$(code -d "declare=1&itemtype=Computer&items_id=$(id computer_A)" "$P/securityincidentlinkeditem.form.php")"
expect "declarer un incident depuis un ordinateur de A" 302 \
  "$(code -d "declare=1&itemtype=Computer&items_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/securityincidentlinkeditem.form.php")"
# Issue #140 : un document d'audit ne se lit que par l'equipe de l'audit (glpi en fait partie),
# jamais avec le seul droit Documents de GLPI ou le droit du plugin.
expect "document d'audit (equipe de l'audit seulement)" "$([ "$ROLE" = admin ] && echo 200 || echo 403)" \
  "$(code "$BASE/front/document.send.php?docid=$(id audit_document)")"
# Issue #139 : la copie de quiz de glpi ne se lit que par lui (ni formateur ni groupe RSSI ici).
expect "front/trainingquiz.send.php : copie d'un autre" "$([ "$ROLE" = admin ] && echo 200 || echo 403)" \
  "$(code "$P/trainingquiz.send.php?id=$(id quiz)")"
# Issue #142 : le flux iCal d'abonnement ignore la session et n'accepte qu'un jeton personnel valide.
expect "front/compliancecalendar.ics.php : sans jeton" 403    "$(curl -s -o /dev/null -w '%{http_code}' "$P/compliancecalendar.ics.php")"
expect "front/compliancecalendar.ics.php : jeton invalide" 403    "$(code "$P/compliancecalendar.ics.php?token=jeton-invalide")"
# Issue #375 : registre des traitements rattache a une entite ; un element lie d'une autre entite
# n'est jamais nomme, ni sur la fiche, ni dans l'export, ni dans l'onglet de l'actif.
expect "traitement, entite A"          200    "$(code "$P/processingactivity.form.php?id=$(id processing_A)")"
expect "traitement, entite B"          $DENY  "$(code "$P/processingactivity.form.php?id=$(id processing_B)")"
expect "AIPD d'un traitement de B"     $DENY  "$(code "$P/dpia.report.php?id=$(id processing_B)")"
expect "demande RGPD, entite A"        200    "$(code "$P/datasubjectrequest.form.php?id=$(id dsr_A)")"
expect "demande RGPD, entite B"        $DENY  "$(code "$P/datasubjectrequest.form.php?id=$(id dsr_B)")"
expect "modifier une demande RGPD de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id dsr_B)&comment=securite&_glpi_csrf_token=$(csrf)" "$P/datasubjectrequest.form.php")"
expect "ticket d'une demande RGPD de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "create_ticket=1&id=$(id dsr_B)&_glpi_csrf_token=$(csrf)" "$P/datasubjectrequest.form.php")"
expect "enregistrer l'AIPD d'un traitement de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "save=1&plugin_grcmanager_processingactivities_id=$(id processing_B)&_glpi_csrf_token=$(csrf)" "$P/dpia.form.php")"
expect "modifier un traitement de B"   "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id processing_B)&comment=securite&_glpi_csrf_token=$(csrf)" "$P/processingactivity.form.php")"
processing_page=$(tab GlpiPlugin%5CGrcmanager%5CProcessingActivity 'GlpiPlugin%5CGrcmanager%5CProcessingActivity$main' "$(id processing_A)")
contains "traitement de A : actif de A nomme" "PC securite A" oui "$processing_page"
contains "traitement de A : actif de B nomme" "PC securite B" $SEE_B "$processing_page"
processing_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/processingactivity.export.php?format=csv")
contains "export CSV du registre : traitement de A" "Traitement securite A" oui "$processing_csv"
contains "export CSV du registre : traitement de B" "Traitement securite B" $SEE_B "$processing_csv"
contains "export CSV du registre : actif de B" "PC securite B" $SEE_B "$processing_csv"
contains "onglet Registre des traitements d'un ordinateur de A" "Traitement securite A" oui \
  "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CProcessingActivity$1' "$(id computer_A)")"

# Issue #378 : un KRI appartient a son entite (lecture, releve, ticket).
expect "KRI, entite A"                  200    "$(code "$P/riskindicator.form.php?id=$(id kri_A)")"
expect "KRI, entite B"                  $DENY  "$(code "$P/riskindicator.form.php?id=$(id kri_B)")"
expect "releve sur un KRI de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_riskindicators_id=$(id kri_B)&value=3&_glpi_csrf_token=$(csrf)" "$P/riskindicatorvalue.form.php")"
expect "ticket depuis un KRI de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "create_ticket=1&id=$(id kri_B)&_glpi_csrf_token=$(csrf)" "$P/riskindicator.form.php")"
expect "creer un KRI dans l'entite B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&name=KRI%20cree&entities_id=$(id b)&_glpi_csrf_token=$(csrf)" "$P/riskindicator.form.php")"
kris=$(curl -s -c "$JAR" -b "$JAR" "$P/riskindicator.php")
contains "liste des KRI : KRI de A" "KRI securite A" oui    "$kris"
contains "liste des KRI : KRI de B" "KRI securite B" $SEE_B "$kris"
expect "campagne de revue, entite A"   200    "$(code "$P/accessreview.form.php?id=$(id campaign_A)")"
expect "campagne de revue, entite B"   $DENY  "$(code "$P/accessreview.form.php?id=$(id campaign_B)")"

control_tab=$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CAccessReview$2' "$(id control)")
contains "onglet revues d'acces d'un controle : campagne A" "Campagne securite A" oui     "$control_tab"
contains "onglet revues d'acces d'un controle : campagne B" "Campagne securite B" $SEE_B  "$control_tab"

contracts_tab=$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$2' "$(id supplierrisk)")
contains "onglet contrats du fournisseur : contrat A" "Contrat securite A" oui     "$contracts_tab"
contains "onglet contrats du fournisseur : contrat B" "Contrat securite B" $SEE_B  "$contracts_tab"

# Issue #171 : la fiche du risque s'affiche avec onglets, le formulaire est le premier onglet.
risk_page=$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CRisk$main' "$(id risk)")
contains "actif lie d'une autre entite affiche sur le risque" "PC securite B" $SEE_B "$risk_page"

expect "classification d'un actif de A" 302 \
  "$(code -d "save=1&itemtype=Computer&items_id=$(id computer_A)&confidentiality=high&integrity=high&availability=high&_glpi_csrf_token=$(csrf)" "$P/assetclassification.form.php")"
expect "classification d'un actif de B" "$([ "$ROLE" = admin ] && echo 302 || echo 404)" \
  "$(code -d "save=1&itemtype=Computer&items_id=$(id computer_B)&confidentiality=low&integrity=low&availability=low&_glpi_csrf_token=$(csrf)" "$P/assetclassification.form.php")"

# Issue #377 : processus metier de son entite seulement ; un element d'une autre entite n'est jamais
# nomme dans les dependances, ne peut pas etre lie, et la BIA d'un processus de B ne se modifie pas.
expect "processus metier, entite A" 200 "$(code "$P/businessprocess.form.php?id=$(id process_A)")"
expect "processus metier, entite B" $DENY "$(code "$P/businessprocess.form.php?id=$(id process_B)")"
deps=$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1' "$(id process_A)")
contains "dependances du processus A : PC de A" "PC securite A" oui "$deps"
contains "dependances du processus A : PC de B" "PC securite B" $SEE_B "$deps"
contains "onglet Processus metier du PC de A" "Processus securite A" oui \
  "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1' "$(id computer_A)")"
procs_b=$(tab Computer 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1' "$(id computer_B)")
contains "onglet Processus metier du PC de B : processus de B" "Processus securite B" $SEE_B "$procs_b"
if [ "$ROLE" = limited ]; then
  expect "lier le PC de B au processus A" 404 \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&itemtype=Computer&items_id=$(id computer_B)&_glpi_csrf_token=$(csrf)" "$P/businessprocessitem.form.php")"
fi
expect "BIA d'un processus de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "save=1&plugin_grcmanager_businessprocesses_id=$(id process_B)&levels[4][financial]=1&_glpi_csrf_token=$(csrf)" "$P/businessimpact.form.php")"
expect "dependance d'un processus de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_B)&itemtype=Computer&items_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/businessprocessitem.form.php")"
pca_export=$(curl -s -c "$JAR" -b "$JAR" "$P/businessprocess.php?export=csv")
contains "export PCA : processus de A" "Processus securite A" oui "$pca_export"
contains "export PCA : processus de B" "Processus securite B" $SEE_B "$pca_export"
contains "export PCA : PC de B" "PC securite B" $SEE_B "$pca_export"
# Issue #377, deuxieme etape : processus lies, risques lies et PCA en PDF. Le processus de B, dont
# depend celui de A, n'est nomme ni dans l'onglet ni dans le PDF ; il ne peut pas etre lie.
expect "PCA (PDF) d'un processus de A" 200 "$(code "$P/businessprocess.report.php?id=$(id process_A)")"
expect "PCA (PDF) d'un processus de B" $DENY "$(code "$P/businessprocess.report.php?id=$(id process_B)")"
links=$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$1' "$(id process_A)")
contains "processus lies de A : processus de B" "Processus securite B" $SEE_B "$links"
contains "processus lies de A : role du lien" "Amont B" $SEE_B "$links"
contains "risques du processus A : risque" "Risque securite" oui \
  "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id process_A)")"
risk_procs=$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id risk)")
contains "processus metier du risque : processus de A" "Processus securite A" oui "$risk_procs"
contains "processus metier du risque : processus de B" "Processus securite B" $SEE_B "$risk_procs"
expect "lier un processus a un processus de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_B)&plugin_grcmanager_businessprocesses_id_upstream=$(id process_A)&_glpi_csrf_token=$(csrf)" "$P/businessprocesslink.form.php")"
expect "lier un risque a un processus de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_B)&plugin_grcmanager_risks_id=$(id risk)&_glpi_csrf_token=$(csrf)" "$P/businessprocessrisk.form.php")"
expect "lier un processus sans jeton CSRF" 403 \
  "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&plugin_grcmanager_businessprocesses_id_upstream=$(id process_B)" "$P/businessprocesslink.form.php")"
if [ "$ROLE" = limited ]; then
  expect "lier le processus de B (illisible) au processus A" 404 \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&plugin_grcmanager_businessprocesses_id_upstream=$(id process_B)&_glpi_csrf_token=$(csrf)" "$P/businessprocesslink.form.php")"
  expect "proposer un risque pour le PC de B (illisible)" 404 \
    "$(code -d "create_risk=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&itemtype=Computer&items_id=$(id computer_B)&_glpi_csrf_token=$(csrf)" "$P/businessprocessrisk.form.php")"
  expect "test de reprise rattache a un exercice inexistant" 404 \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&measured_minutes=60&plugin_grcmanager_crisisexercises_id=999999&_glpi_csrf_token=$(csrf)" "$P/businessprocessrecovery.form.php")"
fi
# Issue #377, troisieme etape : RTO des applications (classification) et strategie de reprise.
expect "classification d'une application de A" 302 \
  "$(code -d "save=1&itemtype=Appliance&items_id=$(id appliance_A)&availability=high&rto_hours=4&_glpi_csrf_token=$(csrf)" "$P/assetclassification.form.php")"
expect "classification d'une application de B" "$([ "$ROLE" = admin ] && echo 302 || echo 404)" \
  "$(code -d "save=1&itemtype=Appliance&items_id=$(id appliance_B)&availability=low&_glpi_csrf_token=$(csrf)" "$P/assetclassification.form.php")"
contains "onglet classification d'une application de A" "rto_hours" oui \
  "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CAssetClassification$1' "$(id appliance_A)")"
contains "dependances du processus A : application de A" "Application securite A" oui "$deps"
strategy=$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRecovery$1' "$(id process_A)")
contains "strategie de reprise du processus A : test" "Test de reprise A" oui "$strategy"
contains "strategie de reprise du processus A : mode degrade" "Mode degrade A" oui "$strategy"
contains "strategie de reprise du processus A : test de B" "Test de reprise B" non "$strategy"
expect "test de reprise d'un processus de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_B)&measured_minutes=60&_glpi_csrf_token=$(csrf)" "$P/businessprocessrecovery.form.php")"
expect "strategie de reprise d'un processus de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id process_B)&degraded_mode=securite&_glpi_csrf_token=$(csrf)" "$P/businessprocess.form.php")"
expect "test de reprise sans jeton CSRF" 403 \
  "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&measured_minutes=60" "$P/businessprocessrecovery.form.php")"
# Issue #377, quatrieme etape : carte des dependances du processus de A. Le processus de B dont il
# depend et le PC de B qu'il utilise ne sont ni nommes ni designes pour un profil limite a A.
map=$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(id process_A)")
contains "carte des dependances de A : graphe" "data-elements=" oui "$map"
contains "carte des dependances de A : processus A" "Processus securite A" oui "$map"
contains "carte des dependances de A : PC de A" "PC securite A" oui "$map"
contains "carte des dependances de A : processus de B" "Processus securite B" $SEE_B "$map"
contains "carte des dependances de A : PC de B" "PC securite B" $SEE_B "$map"
contains "carte des dependances de A : identifiant du PC de B" "Computer:$(id computer_B)&quot;" $SEE_B "$map"
contains "carte des dependances d'un processus de B" "Processus securite B" $SEE_B \
  "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(id process_B)")"

# Issue #145 : preuves datées, uniquement celles de ses entités.
evidences=$(curl -s -c "$JAR" -b "$JAR" "$P/evidence.php")
contains "liste des preuves : preuve de A" "Preuve securite A" oui    "$evidences"
contains "liste des preuves : preuve de B" "Preuve securite B" $SEE_B "$evidences"
expect "date de validite d'une preuve de A" 302 \
  "$(code -d "save=1&itemtype=GlpiPlugin%5CGrcmanager%5CPolicy&items_id=$(id policy_A)&documents_id=$(id document_A)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"
expect "date de validite d'une preuve de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "save=1&itemtype=GlpiPlugin%5CGrcmanager%5CPolicy&items_id=$(id policy_B)&documents_id=$(id document_B)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"
expect "date de validite : document d'une autre politique" 404 \
  "$(code -d "save=1&itemtype=GlpiPlugin%5CGrcmanager%5CPolicy&items_id=$(id policy_A)&documents_id=$(id document_B)&valid_until=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/evidence.form.php")"

# Issue #380 : un test d'efficacite d'une mesure ne cite comme preuve qu'un document joint a la
# mesure ET lisible (entite du document), jamais un document quelconque designe par son id.
expect "test d'efficacite, preuve de A" 302 \
  "$(code -d "add=1&plugin_grcmanager_controls_id=$(id control)&result=effective&documents_id=$(id document_A)&_glpi_csrf_token=$(csrf)" "$P/controltest.form.php")"
expect "test d'efficacite, preuve de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&plugin_grcmanager_controls_id=$(id control)&result=effective&documents_id=$(id document_B)&_glpi_csrf_token=$(csrf)" "$P/controltest.form.php")"
expect "test d'efficacite, document d'un autre objet" 404 \
  "$(code -d "add=1&plugin_grcmanager_controls_id=$(id control)&result=effective&documents_id=$(id audit_document)&_glpi_csrf_token=$(csrf)" "$P/controltest.form.php")"

# Issue #380, troisieme etape : preuve envoyee depuis le formulaire du test. Memes droits que l'onglet
# Documents : le gestionnaire limite (droit Documents en lecture seule) ne peut pas en envoyer. Le
# type reel est verifie : un faux PDF n'est pas enregistre. La preuve envoyee se lit par qui lit la
# mesure et le document (entite A).
expect "preuve envoyee d'une mesure, lecture" 200 "$(code "$BASE/front/document.send.php?docid=$(id evidence_upload)")"
expect "envoyer une preuve sans jeton CSRF" 403 "$(upload_evidence "$EVIDENCE_PDF" preuve-securite.pdf non)"
if [ "$ROLE" = admin ]; then
  expect "envoyer une preuve (PDF)" 302 "$(upload_evidence "$EVIDENCE_PDF" preuve-securite-admin.pdf)"
  expect "envoyer un faux PDF" 302 "$(upload_evidence "$FAKE_PDF" faux-securite.pdf)"
  test_tab=$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CControlTest$1' "$(id control)")
  contains "onglet des tests : preuve envoyee" "preuve-securite-admin.pdf" oui "$test_tab"
  contains "onglet des tests : faux PDF refuse" "faux-securite.pdf" non "$test_tab"
  contains "onglet des tests : envoi propose" 'name="evidence_file"' oui "$test_tab"
else
  expect "envoyer une preuve sans le droit de creer un document" 403 \
    "$(upload_evidence "$EVIDENCE_PDF" preuve-securite-limite.pdf)"
  contains "onglet des tests : pas d'envoi sans droit de creer un document" 'name="evidence_file"' non \
    "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CControlTest$1' "$(id control)")"
fi

# Issue #539 : « Mon espace GRC », ouvert a toute session, ne montre que ce qui est confie a
# l'utilisateur connecte (le risque du jeu de donnees n'a pas de proprietaire ; glpi est inscrit a
# la formation, sans l'avoir suivie).
expect "front/myspace.php : ouverte a toute session" 200 "$(code "$P/myspace.php")"
myspace=$(curl -s -c "$JAR" -b "$JAR" "$P/myspace.php")
contains "front/myspace.php : aucun element confie a un autre" "Risque securite" non "$myspace"
contains "front/myspace.php : formation a suivre" "Formation securite" "$([ "$ROLE" = admin ] && echo oui || echo non)" \
  "$myspace"

if [ "$ROLE" = limited ]; then
  # Enregistre le risque (objet global) avec l'actif de A : le lien vers B, invisible pour lui,
  # doit rester (verifie en base par la CI). Puis demande d'approbation de la SoA en se designant
  # lui-meme : il doit etre retire des approbateurs (verifie en base par la CI).
  code -d "update=1&id=$(id risk)&title=Risque%20securite&linked_items_Computer[]=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/risk.form.php" > /dev/null
  code -d "request_approval=1&approvers[]=$(id manager)&approvers[]=2&comment=test&_glpi_csrf_token=$(csrf)" "$P/soaapproval.php" > /dev/null
  # Issue #375 : enregistre le traitement de A avec le seul ordinateur de A : le lien vers celui de B,
  # invisible pour lui, doit rester (verifie en base par la CI).
  code -d "update=1&id=$(id processing_A)&_linked_items_Computer_defined=1&linked_items_Computer[]=$(id computer_A)&_glpi_csrf_token=$(csrf)" \
    "$P/processingactivity.form.php" > /dev/null
  echo "    (traitement de A enregistre : verification en base par la CI)"
  echo "    (risque enregistre et approbation SoA demandee : verification en base par la CI)"
fi

rm -f "$JAR"
exit $FAIL
