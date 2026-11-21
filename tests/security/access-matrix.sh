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
readable() { # pages consultables (comme $READABLE) : refusees sans droit, ouvertes au lecteur et au super-admin
  local p
  for p in "$@"; do
    case $ROLE in
      none)   expect "$p : sans droit" 403 "$(code "$BASE/plugins/grcmanager/$p")" ;;
      reader) expect "$p : en lecture" 200 "$(code "$BASE/plugins/grcmanager/$p")" ;;
      admin)  expect "$p : super-admin" 200 "$(code "$BASE/plugins/grcmanager/$p")" ;;
    esac
  done
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

# Issue #706 : le changement de taille de la matrice des risques exige de modifier la configuration
# du plugin ET les registres sensibles. Ecran, apercu et application refuses sans ces droits.
scale_denied() {
  expect "front/riskscale.php : ecran de changement de taille" 403 "$(code "$P/riskscale.php?size=5")"
  expect "front/riskscale.php : apercu de la correspondance" 403 \
    "$(code -d "preview=1&size=5&_glpi_csrf_token=$(csrf)" "$P/riskscale.php")"
  expect "front/riskscale.php : appliquer une taille" 403 \
    "$(code -d "apply=1&size=5&confirm=1&_glpi_csrf_token=$(csrf)" "$P/riskscale.php")"
}

# Issue #604 : simulations d'hameconnage. Les taux de la campagne de A se lisent avec le droit du SMSI ;
# les noms et les lignes non rapprochees exigent aussi le droit des registres sensibles ; rien de B hors
# super-admin ; importer exige de modifier la campagne et les registres sensibles ; chacun ne voit que
# ses propres resultats (iso-sans-droit, sans aucun droit, a clique dans la campagne de A).
phishing_matrix() {
  local see=oui detail=oui import_a=200 import_b=403 mine=non remedial=non csv tab_a
  case $ROLE in
    none)    see=non; detail=non; import_a=403; mine=oui ;;
    general) detail=non; import_a=403 ;;
    reader)  import_a=403 ;;
    limited) remedial=oui ;;
    admin)   import_b=200; remedial=oui ;;
  esac
  tab_a=$(tab 'GlpiPlugin%5CGrcmanager%5CPhishingCampaign' 'GlpiPlugin%5CGrcmanager%5CPhishingResult$1' "$(id phishing_A)")
  contains "onglet Resultats de la campagne de A : taux de clic" "33.3 %" $see "$tab_a"
  contains "onglet Resultats de la campagne de A : nom d'une personne" "Collaborateur securite A" $detail "$tab_a"
  contains "onglet Resultats de la campagne de A : ligne non rapprochee" "inconnu.securite@example.org" $detail "$tab_a"
  contains "onglet Resultats de la campagne de A : rattrapage propose" "phishing_of=" $remedial "$tab_a"
  contains "onglet Resultats de la campagne de B : nom d'une personne" "Collaborateur securite B" $SEE_B \
    "$(tab 'GlpiPlugin%5CGrcmanager%5CPhishingCampaign' 'GlpiPlugin%5CGrcmanager%5CPhishingResult$1' "$(id phishing_B)")"
  contains "liste des simulations : campagne de B" "Campagne hameconnage B" $SEE_B \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/phishingcampaign.php")"
  expect "fiche de la campagne de B" "$DENY" "$(code "$P/phishingcampaign.form.php?id=$(id phishing_B)")"
  # Formulaire de formation (onglet principal, servi par ajax/common.tabs.php) pre-rempli depuis B.
  contains "formation pre-remplie depuis la campagne de B" "Campagne hameconnage B" $SEE_B \
    "$(tab 'GlpiPlugin%5CGrcmanager%5CTraining' 'GlpiPlugin%5CGrcmanager%5CTraining$main' "0&phishing_of=$(id phishing_B)")"
  mine_phishing=$(tab Preference 'GlpiPlugin%5CGrcmanager%5CPhishingResult$1' 2)
  contains "onglet Mes simulations d'hameconnage : ses resultats" "Campagne hameconnage A" $mine "$mine_phishing"
  contains "onglet Mes simulations d'hameconnage : resultat d'un autre" "Collaborateur securite" non "$mine_phishing"
  csv=$(mktemp); printf 'email,clicked\nnouveau.securite@example.org,yes\n' > "$csv"
  phishing_upload() { # campagne, fichier, nom, [jeton CSRF : oui|non]
    local token=(-F "_glpi_csrf_token=$(csrf)")
    [ "${4:-oui}" = non ] && token=()
    code -F upload=1 -F "id=$1" -F "phishing_file=@$2;filename=$3" "${token[@]}" "$P/phishingimport.php"
  }
  expect "import des resultats de la campagne de A" $import_a "$(phishing_upload "$(id phishing_A)" "$csv" r.csv)"
  expect "import des resultats de la campagne de B" $import_b "$(phishing_upload "$(id phishing_B)" "$csv" r.csv)"
  expect "import des resultats sans jeton CSRF" 403 "$(phishing_upload "$(id phishing_A)" "$csv" r.csv non)"
  [ "$ROLE" = limited ] && expect "import d'un fichier qui n'est pas un CSV" 302 \
    "$(phishing_upload "$(id phishing_A)" "$EVIDENCE_PDF" r.pdf)"
  rm -f "$csv"
  [ "$ROLE" != admin ] && expect "supprimer les resultats par personne de B" 403 \
    "$(code -d "anonymize=1&id=$(id phishing_B)&_glpi_csrf_token=$(csrf)" "$P/phishingcampaign.form.php")"
  case $ROLE in none|reader|general)
    expect "supprimer les resultats par personne de A" 403 \
      "$(code -d "anonymize=1&id=$(id phishing_A)&_glpi_csrf_token=$(csrf)" "$P/phishingcampaign.form.php")"
    # Issue #604, lot 2 : la duree de conservation exige la configuration ET les registres sensibles.
    expect "front/config.php : duree de conservation des resultats d'hameconnage" 403       "$(code -d "update_phishing_retention=1&phishing_retention_months=1&_glpi_csrf_token=$(csrf)" "$P/config.php")" ;;
  esac
  contains "onglet Resultats de la campagne de A : taux par groupe" "grc-phishing-groups" $see "$tab_a"
  return 0
}

# Pages consultables (listes, rapports, fiches existantes) : refusees sans droit, ouvertes en lecture.
READABLE="front/accessreview.php front/audit.php front/canonicalproduct.php front/complianceobligation.php
  front/control.php front/ismsrequirement.php front/managementreview.php front/nonconformity.php
  front/objective.php front/policy.php front/risk.php front/securityincident.php front/supplierrisk.php
  front/training.php front/referentiels.php front/riskheatmap.php front/soaapproval.php front/policyapproval.php front/config.php
  front/audit.report.php front/control.report.php front/risk.report.php front/control.csv.php?action=export
  front/evidence.php front/periodicreview.php front/networkflow.php front/accounthygiene.php
  front/compliancecalendar.php front/compliancecalendar.php?ics=1 front/complianceevent.php
  front/parkvulnerability.php front/parkmachines.php front/softwarewatch.php front/riskcatalog.php front/waiver.php
  front/endoflife.export.php front/hardwarewarranty.export.php
  front/softwarelicensecompliance.php front/softwarelicensecompliance.export.php
  front/diskencryption.php front/mfacoverage.php front/patchcompliance.php front/logcoverage.php
  front/configcompliance.php front/adsecurityaudit.php
  front/coveragerule.php front/playbook.php front/impactanalysis.php front/causalgraph.php
  front/ebiosstudy.php
  front/auditlog.php front/controltest.php
  front/impactanalysis.php?export=continuity front/maturity.php front/frameworkassessment.php front/crisisexercise.php front/complianceaudit.php
  front/dashboard.php front/networkflow.report.php front/breachexposure.php front/networkflow.matrix.php
  front/processingactivity.php front/datasubjectrequest.php front/processingactivity.export.php?format=pdf
  front/processingactivity.export.php?format=csv
  front/databreach.php front/databreach.form.php front/databreach.export.php?format=pdf front/databreach.export.php?format=csv
  front/riskappetite.php front/riskindicator.php front/auditcoverage.php front/auditprogramme.php
  front/businessprocess.php front/businessprocess.php?export=csv front/businessprocess.report.php
  front/recoveryprogramme.php
  front/crisisscenario.php front/crisisscenario.export.php front/crisisscenario.form.php
  front/customframework.php front/customframework.php?template=1
  front/riskimport.php front/riskimport.php?template=1 front/riskimport.php?export=1
  front/doraregister.php front/doraregister.php?export=B_02.01 front/doraregister.php?export=zip
  front/consistencycheck.php front/consistencycheck.export.php?format=csv front/consistencycheck.export.php?format=pdf
  front/controlblocker.php front/controlblocker.php?sort=code&order=DESC
  front/certificationreadiness.php front/certificationreadiness.php?format=pdf
  front/certificationfinding.php front/certificationfinding.php?format=pdf front/certificationsampling.php
  front/certificationfinding.php?process=1
  front/contextitem.php front/contextitem.form.php
  front/responsibilityactivity.php front/responsibilityactivity.form.php front/responsibilityactivity.export.php
  front/contextitem.export.php?format=csv front/contextitem.export.php?format=pdf
  front/officialcontact.php front/officialcontact.export.php
  front/operatingprocedure.php front/operatingprocedure.export.php
  front/physicalsecurity.php front/physicalsecurityassessment.php
  front/maturitycampaign.php
  front/crisismessagetemplate.php front/crisismessagetemplate.form.php
  front/securedevelopment.php
  front/acnrequirement.php front/acnrequirement.export.php?format=csv front/acnrequirement.export.php?format=pdf
  front/recyfrequirement.php front/recyfrequirement.export.php?format=csv front/recyfrequirement.export.php?format=pdf
  front/personnelcompliance.php front/personnelcompliance.export.php?format=csv
  front/personnelcompliance.export.php?format=pdf
  front/ismsfunction.php front/ismsfunction.form.php front/ismsfunction.pdf.php front/competence.php
  front/competence.form.php front/competencedeclaration.php front/competencedeclaration.form.php
  front/phishingcampaign.php front/phishingcampaign.form.php front/phishingimport.php?example=1
  front/evidencepack.php"
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
    front/processingactivity.form.php front/datasubjectrequest.form.php front/databreach.form.php \
    front/businessprocess.form.php front/riskindicator.form.php front/contextitem.form.php \
    front/managementreviewaction.form.php front/responsibilityactivity.form.php \
    front/ismsfunction.form.php front/competence.form.php front/competencedeclaration.form.php \
    front/crisismessagetemplate.form.php front/crisismessagerecipient.form.php \
    front/crisisscenario.form.php front/physicalsecurityassessment.form.php front/phishingcampaign.form.php \
    front/maturitycampaign.form.php; do
    expect "$form : creation" "$1" "$(code -d "add=1&name=securite&title=securite&_glpi_csrf_token=$(csrf)" "$BASE/plugins/grcmanager/$form")"
  done
  # Issue #772, lot 2 : cocher le plan d'audit de l'organisme exige de modifier l'audit.
  expect "front/certificationsampling.php : enregistrer le plan" "$1" \
    "$(code -d "save_sampling=1&audit_ids[]=$(id audit_cert)&sampled[$(id audit_cert)][]=1&_glpi_csrf_token=$(csrf)" "$P/certificationsampling.php")"
  # Issue #776 : ajouter les scenarios de crise par defaut exige de pouvoir creer un scenario.
  expect "front/crisisscenario.php : ajouter les scenarios par defaut" "$1" \
    "$(code -d "add_defaults=1&_glpi_csrf_token=$(csrf)" "$P/crisisscenario.php")"
  # Issue #616, lot 2 : la revue annuelle d'une liste de diffusion exige de pouvoir modifier les modeles.
  expect "front/crisismessagerecipient.form.php : revue de la liste de diffusion" "$1" \
    "$(code -d "review=1&plugin_grcmanager_crisismessagetemplates_id=$(id crisis_template)&_glpi_csrf_token=$(csrf)" "$P/crisismessagerecipient.form.php")"
  # Issue #775 : archiver le registre des procedures d'exploitation sur A.5.37 cree un document : il
  # faut pouvoir modifier les politiques.
  expect "front/operatingprocedure.php : archiver sur A.5.37" "$1" \
    "$(code -d "archive=1&_glpi_csrf_token=$(csrf)" "$P/operatingprocedure.php")"
  # Issue #771, lot 3 : archiver le registre des contacts officiels sur A.5.5 et A.5.6 cree un
  # document : il faut pouvoir modifier le contexte de l'organisation.
  expect "front/officialcontact.php : archiver sur A.5.5 et A.5.6" "$1" \
    "$(code -d "archive=1&_glpi_csrf_token=$(csrf)" "$P/officialcontact.php")"
  # Issue #775, lot 2 : tracer la revue d'une procedure exige de pouvoir modifier les politiques.
  expect "front/policy.form.php : enregistrer la revue" "$1" \
    "$(code -d "_record_procedure_review=1&id=$(id policy_A)&_glpi_csrf_token=$(csrf)" "$P/policy.form.php")"
  # Issue #542 : generer le dossier preparatoire d'une revue de direction (document joint) et creer
  # les actions a partir de ses decisions exigent de pouvoir modifier la revue.
  expect "front/managementreview.dossier.php : generer le dossier" "$1" \
    "$(code -d "generate=1&id=$(id mreview)&period_start=2026-01-01&period_end=2026-06-30&_glpi_csrf_token=$(csrf)" "$P/managementreview.dossier.php")"
  expect "front/managementreviewaction.form.php : actions depuis les decisions" "$1" \
    "$(code -d "from_decisions=1&plugin_grcmanager_managementreviews_id=$(id mreview)&_glpi_csrf_token=$(csrf)" "$P/managementreviewaction.form.php")"
  # Issue #537 : analyser un fichier, confirmer un import, et modifier un referentiel personnalise
  # (statuts, description, activation, suppression) sont refuses sans droit comme en lecture seule.
  expect "front/customframework.php : analyser un fichier" "$1" \
    "$(code -F analyse=1 -F name=securite -F "csv_file=@$EVIDENCE_PDF;filename=ref.csv" -F "_glpi_csrf_token=$(csrf)" "$P/customframework.php")"
  expect "front/customframework.php : confirmer un import" "$1" \
    "$(code -d "confirm_import=1&import_token=x&_glpi_csrf_token=$(csrf)" "$P/customframework.php")"
  # Issue #602 : analyser un registre des risques, confirmer son import.
  expect "front/riskimport.php : analyser un fichier" "$1"     "$(code -F analyse=1 -F "csv_file=@$EVIDENCE_PDF;filename=registre.csv" -F "_glpi_csrf_token=$(csrf)" "$P/riskimport.php")"
  expect "front/riskimport.php : confirmer un import" "$1"     "$(code -d "confirm_import=1&import_token=x&_glpi_csrf_token=$(csrf)" "$P/riskimport.php")"
  for action in "save_statuses=1&status%5B1%5D=compliant" "update=1&name=securite" "toggle_framework=1&enable=0" "purge=1"; do
    expect "front/customframework.form.php : ${action%%=*}" "$1" \
      "$(code -d "$action&id=$(id custom_framework)&_glpi_csrf_token=$(csrf)" "$P/customframework.form.php")"
  done
  expect "front/ismsrequirement.form.php : modification" "$1" \
    "$(code -d "update=1&id=1&status=done&_glpi_csrf_token=$(csrf)" "$P/ismsrequirement.form.php")"
  # Issue #864 : questionnaires fournisseurs (front/supplierquestionnairetemplate.php ; le reste dans
  # le fragment access-matrix.d/864-supplier-questionnaires.sh).
  expect "front/supplierquestionnairetemplate.form.php : creation d'un modele" "$1" \
    "$(code -d "add=1&name=securite&_glpi_csrf_token=$(csrf)" "$P/supplierquestionnairetemplate.form.php")"
  expect "front/supplierevaluation.form.php : creation d'une evaluation" "$1" \
    "$(code -d "add=1&plugin_grcmanager_supplierrisks_id=$(id supplierrisk)&plugin_grcmanager_supplierquestionnairetemplates_id=$(id questionnaire_A)&_glpi_csrf_token=$(csrf)" "$P/supplierevaluation.form.php")"
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
  # Issue #773 : processus audites, justification d'independance et remise du rapport exigent de
  # pouvoir modifier l'audit ; les reglages du programme, de modifier les audits.
  expect "front/auditprogramme.form.php : processus audites" "$1" \
    "$(code -d "save_processes=1&plugin_grcmanager_audits_id=$(id audit)&processes[]=1&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.form.php")"
  expect "front/auditprogramme.form.php : remise du rapport" "$1" \
    "$(code -d "save_delivery=1&plugin_grcmanager_audits_id=$(id audit)&delivery_date=2026-10-20&users_id_recipient=2&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.form.php")"
  expect "front/auditprogramme.php : reglages du programme" "$1" \
    "$(code -d "save_settings=1&cycle_start=2025&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.php")"
  # Issue #773, lot 3 : planifier un audit depuis le programme exige le droit de creer un audit.
  expect "front/auditprogramme.php : planifier un audit" "$1" \
    "$(code -d "plan_audit=1&process_id=$(id responsibility_A)&year=$(( $(date +%Y) + 1 ))&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.php")"
  # Issue #761 : date cible, responsable et seuils (et delais, lot 2) de la preparation a la certification exigent le
  # droit de modifier le plugin.
  expect "front/certificationreadiness.php : reglages de la preparation" "$1" \
    "$(code -d "save_readiness_settings=1&target_date=2026-12-03&coverage_target=90&alert_days=30&lead_days%5Binternal_audit%5D=60&_glpi_csrf_token=$(csrf)" "$P/certificationreadiness.php")"
  # Issue #868 : generer le dossier de preuves (archive enregistree) et regler sa limite de taille
  # exigent de pouvoir modifier le plugin.
  expect "front/evidencepack.php : generer le dossier de preuves" "$1"     "$(code -d "generate=1&entities_id=$(id a)&_glpi_csrf_token=$(csrf)" "$P/evidencepack.php")"
  expect "front/evidencepack.php : limite de taille" "$1"     "$(code -d "save_settings=1&max_mb=50&_glpi_csrf_token=$(csrf)" "$P/evidencepack.php")"
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
  # Issue #607 : tirer un echantillon ou relire un test exige de pouvoir modifier la mesure.
  expect "front/controltestsample.form.php : tirer un echantillon" "$1" \
    "$(code -d "draw=1&plugin_grcmanager_controls_id=$(id control)&itemtype=Computer&name=securite&_glpi_csrf_token=$(csrf)" "$P/controltestsample.form.php")"
  expect "front/controltest.form.php : relire un test" "$1" \
    "$(code -d "review=approve&id=$(id review_test)&_glpi_csrf_token=$(csrf)" "$P/controltest.form.php")"
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
# Issue #771 : onglet « Contacts officiels » de l'incident de A. Il faut lire l'incident et le
# contexte ; seuls les contacts de l'entite de l'incident (ou d'une entite parente) sont montres,
# jamais ceux de l'entite soeur B, meme au super-admin.
incident_contacts=$(tab PluginGrcmanagerSecurityIncident 'GlpiPlugin%5CGrcmanager%5COfficialContact$1' "$(id incident_A)")
contains "onglet Contacts officiels de l'incident : contact de A" "Partie securite A" $SEE_INCIDENTS "$incident_contacts"
contains "onglet Contacts officiels de l'incident : contact de B" "Partie securite B" non "$incident_contacts"
# Issue #616 : onglet « Communication de crise » de l'incident de A. Droits de l'incident ; la liste
# de diffusion du modele ne montre que les contacts de l'entite de l'incident (jamais celui de B,
# meme au super-admin) ; les communications de l'incident de B ne sont lues qu'au super-admin.
crisis_A=$(tab PluginGrcmanagerSecurityIncident 'GlpiPlugin%5CGrcmanager%5CCrisisCommunication$1' "$(id incident_A)")
contains "onglet Communication de crise de A : communication de A" "Communication securite A" $SEE_INCIDENTS "$crisis_A"
contains "onglet Communication de crise de A : contact de A" "Partie securite A" $SEE_INCIDENTS "$crisis_A"
contains "onglet Communication de crise de A : contact de B" "Partie securite B" non "$crisis_A"
contains "onglet Communication de crise de B : communication de B" "Communication securite B" $SEE_B \
  "$(tab PluginGrcmanagerSecurityIncident 'GlpiPlugin%5CGrcmanager%5CCrisisCommunication$1' "$(id incident_B)")"
contains "liste de diffusion du modele : contact de B" "Partie securite B" $SEE_B \
  "$(tab 'GlpiPlugin%5CGrcmanager%5CCrisisMessageTemplate' 'GlpiPlugin%5CGrcmanager%5CCrisisMessageRecipient$1' "$(id crisis_template)")"
# Tracer une communication exige de pouvoir modifier l'incident (rien n'est envoye).
RECORD_A=403; case $ROLE in limited|admin) RECORD_A=302 ;; esac
crisis_record() { # incident, [jeton CSRF : oui|non]
  local token="&_glpi_csrf_token=$(csrf)"
  [ "${2:-oui}" = non ] && token=
  code -d "record=1&itemtype=PluginGrcmanagerSecurityIncident&items_id=$1&plugin_grcmanager_crisismessagetemplates_id=$(id crisis_template)&subject=x&body=y&other_recipients=Direction$token" \
    "$BASE/plugins/grcmanager/front/crisiscommunication.form.php"
}
expect "front/crisiscommunication.form.php : trace sur l'incident de A" $RECORD_A "$(crisis_record "$(id incident_A)")"
expect "front/crisiscommunication.form.php : trace sur l'incident de B (autre entite)" \
  "$([ "$ROLE" = admin ] && echo 302 || echo 403)" "$(crisis_record "$(id incident_B)")"
expect "front/crisiscommunication.form.php : trace sans jeton CSRF" 403 "$(crisis_record "$(id incident_A)" non)"
expect "front/crisiscommunication.form.php : sans action (GET)" 400 \
  "$(code "$BASE/plugins/grcmanager/front/crisiscommunication.form.php")"
official=$(curl -s -c "$JAR" -b "$JAR" "$P/officialcontact.php")
contains "registre des contacts officiels : contact de B" "Partie securite B" $SEE_B "$official"
# Issue #771, lot 2 : prises de contact tracees. Onglet de l'incident de A : sa trace, jamais
# celle de l'incident de B. Fiche du contact de A : la trace de l'incident de B n'est lue qu'au
# super-admin. Tracer exige de pouvoir modifier l'incident (rien n'est envoye), avec un jeton.
contains "onglet Contacts officiels de l'incident : trace de A" "Dossier securite A" $SEE_INCIDENTS "$incident_contacts"
contains "onglet Contacts officiels de l'incident : trace de B" "Dossier securite B" non "$incident_contacts"
SEE_CONTACT_A=oui; [ "$ROLE" = none ] && SEE_CONTACT_A=non
contact_history=$(tab 'GlpiPlugin%5CGrcmanager%5CContextItem' 'GlpiPlugin%5CGrcmanager%5COfficialContactNotification$1' "$(id context_A)")
contains "fiche du contact de A : trace de l'incident de A" "Dossier securite A"   "$([ "$SEE_CONTACT_A" = oui ] && echo $SEE_INCIDENTS || echo non)" "$contact_history"
contains "fiche du contact de A : trace de l'incident de B" "Dossier securite B" $SEE_B "$contact_history"
contact_record() { # incident, [jeton CSRF : oui|non]
  local token="&_glpi_csrf_token=$(csrf)"
  [ "${2:-oui}" = non ] && token=
  code -d "add=1&itemtype=PluginGrcmanagerSecurityIncident&items_id=$1&plugin_grcmanager_contextitems_id=$(id context_A)&decision=notified&date_notified=2030-01-01T10:00&reference=securite$token"     "$BASE/plugins/grcmanager/front/officialcontactnotification.form.php"
}
expect "front/officialcontactnotification.form.php : trace sur l'incident de A" $RECORD_A "$(contact_record "$(id incident_A)")"
expect "front/officialcontactnotification.form.php : trace sur l'incident de B (autre entite)"   "$([ "$ROLE" = admin ] && echo 302 || echo 403)" "$(contact_record "$(id incident_B)")"
expect "front/officialcontactnotification.form.php : trace sans jeton CSRF" 403 "$(contact_record "$(id incident_A)" non)"
expect "front/officialcontactnotification.form.php : sans action (GET)" 400   "$(code "$BASE/plugins/grcmanager/front/officialcontactnotification.form.php")"
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
           "businessprocess.form.php?id=$(id process_A)" "customframework.form.php?id=$(id custom_framework)"; do
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
  map=$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(id process_A)")
  contains "onglet Carte des dependances d'un processus : sans droit" "Processus securite" non "$map"
  # Issue #701 : ni export en image.
  contains "onglet Carte des dependances d'un processus : export, sans droit" "grc-map-export" non "$map"
  # Issue #403 : les referentiels actifs se reglent avec le droit de modifier la configuration.
  expect "front/config.php : desactiver les referentiels" 403 \
    "$(code -d "update_frameworks=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #380 : le rappel des tests au groupe RSSI se regle avec le droit de modifier la configuration.
  expect "front/config.php : rappel des tests au groupe RSSI" 403 \
    "$(code -d "update_controltest_reminder=1&controltest_reminder_ciso=always&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #378 : le niveau d'alerte des KRI se regle avec le droit de modifier la configuration.
  expect "front/config.php : niveau d'alerte des KRI" 403 \
    "$(code -d "update_kri_alert=1&kri_alert_level=orange&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #782, lot 2 : la fin de vie constructeur des modeles se saisit avec le droit de modifier la configuration.
  expect "front/config.php : fin de vie constructeur d'un modele" 403 \
    "$(code -d "update_hardware_eol=1&hardware_eol%5BComputerModel%3A1%5D=2020-01-01&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #375 : relier un traitement a un incident exige de pouvoir modifier l'incident.
  expect "front/processingactivity.form.php : relier un incident en lecture seule" 403 \
    "$(code -d "link_incident=1&plugin_grcmanager_processingactivities_id=$(id processing_A)&incidents_id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/processingactivity.form.php")"
  # Issue #380 : la preuve envoyee pour une mesure, dans son entite A, lui est refusee malgre son
  # droit Documents : il ne lit pas les mesures.
  expect "preuve envoyee d'une mesure : droit Documents sans lecture des mesures" 403 \
    "$(code "$BASE/front/document.send.php?docid=$(id evidence_upload)")"
  phishing_matrix
  scale_denied
  create_attempts 403 403
  rm -f "$JAR"; exit $FAIL
fi

# Registres sensibles : tout le SMSI, mais pas le droit dedie. Risques, vulnerabilites du parc,
# flux reseau et analyse d'impact lui sont refuses, et aucun titre de risque n'apparait ailleurs.
if [ "$ROLE" = general ]; then
  for p in front/risk.php front/supplierrisk.php front/riskheatmap.php front/risk.report.php \
           front/networkflow.php front/networkflow.report.php front/networkflow.matrix.php front/parkvulnerability.php \
           front/endoflife.export.php front/hardwarewarranty.export.php \
           front/softwarelicensecompliance.php front/softwarelicensecompliance.export.php \
           front/impactanalysis.php front/causalgraph.php front/breachexposure.php front/adsecurityaudit.php \
           front/riskappetite.php front/riskindicator.php \
           front/businessprocess.php front/businessprocess.php?export=csv front/businessprocess.report.php \
           front/recoveryprogramme.php \
           front/crisisscenario.php front/crisisscenario.export.php "front/crisisscenario.form.php?id=$(id crisis_scenario_A)"; do
    expect "$p : sans le droit des registres sensibles" 403 "$(code "$BASE/plugins/grcmanager/$p")"
  done
  # Issue #608, lot 2 : l'export du contexte ne nomme pas le risque lie sans le droit dedie.
  contains "export du contexte : aucun titre de risque lie" "Risque securite" non     "$(body "$P/contextitem.export.php?format=csv")"
  expect "front/businessprocess.report.php : PCA d'un processus, sans le droit de la continuite" 403 \
    "$(code "$P/businessprocess.report.php?id=$(id process_A)")"
  contains "onglet Processus metier d'un risque : rien" "Processus securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id risk)")"
  contains "onglet Risques d'un processus : rien" "Risque securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id process_A)")"
  # Issue #377, troisieme etape : ni strategie de reprise ni test sans le droit de la continuite.
  contains "onglet Strategie de reprise d'un processus : rien" "Test de reprise A" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRecovery$1' "$(id process_A)")"
  # Issue #776 : ni scenario de crise sur le processus, ni catalogue sans le droit de la continuite.
  contains "onglet Scenarios de crise d'un processus : rien" "Scenario crise securite" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CCrisisScenario$1' "$(id process_A)")"
  contains "onglet Preparation d'un scenario : rien" "Processus securite A" non \
    "$(tab GlpiPlugin%5CGrcmanager%5CCrisisScenario 'GlpiPlugin%5CGrcmanager%5CCrisisScenarioReadiness$1' "$(id crisis_scenario_A)")"
  expect "front/crisisscenario.php : scenarios par defaut sans le droit de la continuite" 403 \
    "$(code -d "add_defaults=1&_glpi_csrf_token=$(csrf)" "$P/crisisscenario.php")"
  # Issue #377, quatrieme etape : ni carte des dependances sans le droit de la continuite.
  map=$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(id process_A)")
  contains "onglet Carte des dependances d'un processus : rien" "Processus securite" non "$map"
  # Issue #701 : ni export en image.
  contains "onglet Carte des dependances d'un processus : export, rien" "grc-map-export" non "$map"
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
           "front/processingactivity.export.php?format=csv" "front/processingactivity.form.php?id=$(id processing_A)" \
           front/databreach.php "front/databreach.form.php?id=$(id breach_A)" "front/databreach.export.php?format=csv" \
           "front/databreach.export.php?format=pdf"; do
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
  # Issue #706 : configuration du plugin en modification, mais pas les registres sensibles.
  phishing_matrix
  scale_denied
  rm -f "$JAR"; exit $FAIL
fi

if [ "$ROLE" = reader ]; then
  for p in $READABLE; do expect "$p : en lecture" 200 "$(code "$BASE/plugins/grcmanager/$p")"; done
  # Issue #779 : constats des licences de l'entite A seulement ; listes des editeurs en lecture seule.
  contains "licences logicielles : logiciel de A" "Logiciel licence securite A" oui \
    "$(body "$P/softwarelicensecompliance.php")"
  contains "licences logicielles : rien de B" "Logiciel licence securite B" non \
    "$(body "$P/softwarelicensecompliance.php")"
  contains "export des licences logicielles : rien de B" "Logiciel licence securite B" non \
    "$(body "$P/softwarelicensecompliance.export.php")"
  # Issue #779, lot 2 : licence de A qui arrive a echeance, rien de B (page et export).
  contains "licences logicielles : echeance de A" "Licence echeance securite A" oui \
    "$(body "$P/softwarelicensecompliance.php")"
  contains "licences logicielles : echeance de B absente" "Licence echeance securite B" non \
    "$(body "$P/softwarelicensecompliance.php")"
  contains "export des licences logicielles : echeance de B absente" "Licence echeance securite B" non \
    "$(body "$P/softwarelicensecompliance.export.php")"
  # Issue #847 : chiffrement des disques de l'entite A seulement ; regle et cles en lecture seule.
  contains "chiffrement des disques : ordinateur de A" "PC securite A" oui "$(body "$P/diskencryption.php")"
  contains "chiffrement des disques : rien de B" "PC securite B" non "$(body "$P/diskencryption.php")"
  expect "front/diskencryption.php : modifier la regle de chiffrement" 403     "$(code -d "save_encryption_rule=1&encryption_scope=laptops&_glpi_csrf_token=$(csrf)" "$P/diskencryption.php")"
  # Issue #853 : journalisation de l'entite A seulement ; reglages, import et declarations en lecture seule.
  contains "journalisation : hote de A" "Hote journaux securite A" oui "$(body "$P/logcoverage.php")"
  contains "journalisation : hote de B absent" "Hote journaux securite B" non "$(body "$P/logcoverage.php")"
  contains "journalisation : conservation de B absente" "Conservation securite B" non "$(body "$P/logcoverage.php")"
  expect "front/logcoverage.php : modifier les reglages" 403     "$(code -d "save_log_settings=1&log_scope=all&_glpi_csrf_token=$(csrf)" "$P/logcoverage.php")"
  expect "front/logcoverage.php : declarer une plateforme en lecture seule" 403     "$(code -d "declare_log_platform=1&platform=splunk&retention_days=1&_glpi_csrf_token=$(csrf)" "$P/logcoverage.php")"
  expect "front/logcoverage.php : effacer un import en lecture seule" 403     "$(code -d "clear_log_hosts=1&platform=graylog&_glpi_csrf_token=$(csrf)" "$P/logcoverage.php")"
  # Issue #854 : correctifs de securite de l'entite A seulement ; delais cibles en lecture seule.
  contains "correctifs de securite : ordinateur de A" "PC securite A" oui "$(body "$P/patchcompliance.php")"
  contains "correctifs de securite : rien de B" "PC securite B" non "$(body "$P/patchcompliance.php")"
  expect "front/patchcompliance.php : modifier les delais cibles" 403 \
    "$(code -d "save_patch_targets=1&patch_targets%5Bcritical%5D=3&_glpi_csrf_token=$(csrf)" "$P/patchcompliance.php")"
  # Issue #781 : conformite de configuration de l'entite A seulement ; import, rattachement,
  # suppression et ticket refuses en lecture seule ; analyse de B introuvable.
  contains "conformite de configuration : ordinateur de A" "PC securite A" oui "$(body "$P/configcompliance.php")"
  contains "conformite de configuration : rien de B" "PC securite B" non "$(body "$P/configcompliance.php")"
  expect "front/configcompliance.php : analyse de A" 200 "$(code "$P/configcompliance.php?report=$(id configscan_A)")"
  expect "front/configcompliance.php : analyse de B" 404 "$(code "$P/configcompliance.php?report=$(id configscan_B)")"
  expect "front/configcompliance.php : importer en lecture seule" 403     "$(code -F upload=1 -F "scan_file=@$EVIDENCE_PDF;filename=resultats.xml" -F "_glpi_csrf_token=$(csrf)" "$P/configcompliance.php")"
  expect "front/configcompliance.php : rattacher en lecture seule" 403     "$(code -d "link_computer=1&report=$(id configscan_A)&computers_id=$(id computer_A)&_glpi_csrf_token=$(csrf)" "$P/configcompliance.php")"
  expect "front/configcompliance.php : supprimer en lecture seule" 403     "$(code -d "delete_report=1&report=$(id configscan_A)&_glpi_csrf_token=$(csrf)" "$P/configcompliance.php")"
  expect "front/configcompliance.php : ticket en lecture seule" 403     "$(code -d "create_ticket=1&report=$(id configscan_A)&_glpi_csrf_token=$(csrf)" "$P/configcompliance.php")"
  expect "front/diskencryption.php : declarer une cle en lecture seule" 403     "$(code -d "declare_key=1&computers_id=$(id computer_A)&is_backed_up=1&_glpi_csrf_token=$(csrf)" "$P/diskencryption.php")"
  expect "front/softwarelicensecompliance.php : modifier l'horizon d'echeance" 403 \
    "$(code -d "save_license_watch=1&license_expiring_days=1&_glpi_csrf_token=$(csrf)" "$P/softwarelicensecompliance.php")"
  # Issue #782 : fin de garantie du materiel de l'entite A seulement (carte et export).
  contains "fin de garantie : materiel de A" "PC garantie securite A" oui "$(body "$P/parkvulnerability.php?warranty=all")"
  contains "fin de garantie : rien de B" "PC garantie securite B" non "$(body "$P/parkvulnerability.php?warranty=all")"
  contains "export de la fin de garantie : materiel de A" "PC garantie securite A" oui \
    "$(body "$P/hardwarewarranty.export.php")"
  contains "export de la fin de garantie : rien de B" "PC garantie securite B" non \
    "$(body "$P/hardwarewarranty.export.php")"
  expect "front/softwarelicensecompliance.php : modifier les editeurs surveilles" 403 \
    "$(code -d "save_license_watch=1&license_publishers=securite&_glpi_csrf_token=$(csrf)" "$P/softwarelicensecompliance.php")"
  for p in "risk.form.php?id=$(id risk)" "control.form.php?id=$(id control)" "securityincident.form.php?id=$(id incident_A)" \
           "accessreview.form.php?id=$(id campaign_A)" "supplierrisk.form.php?id=$(id supplierrisk)" \
           "businessprocess.form.php?id=$(id process_A)" "customframework.form.php?id=$(id custom_framework)" \
           "crisisscenario.form.php?id=$(id crisis_scenario_A)"; do
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
  # Issue #782, lot 2 : la fin de vie constructeur des modeles se saisit avec le droit de modifier la configuration.
  expect "front/config.php : fin de vie constructeur d'un modele" 403 \
    "$(code -d "update_hardware_eol=1&hardware_eol%5BComputerModel%3A1%5D=2020-01-01&_glpi_csrf_token=$(csrf)" "$P/config.php")"
  # Issue #375 : relier un traitement a un incident exige de pouvoir modifier l'incident.
  expect "front/processingactivity.form.php : relier un incident en lecture seule" 403 \
    "$(code -d "link_incident=1&plugin_grcmanager_processingactivities_id=$(id processing_A)&incidents_id=$(id incident_A)&_glpi_csrf_token=$(csrf)" "$P/processingactivity.form.php")"
  phishing_matrix
  scale_denied
  create_attempts 403 403
  rm -f "$JAR"; exit $FAIL
fi

if [ "$ROLE" = admin ]; then
  # Issue #706 : ecran de changement de taille ouvert au super-admin ; l'apercu n'ecrit rien et
  # l'application est refusee sans jeton CSRF (la matrice reste en 4 x 4).
  expect "front/riskscale.php : ecran de correspondance" 200 "$(code "$P/riskscale.php?size=5")"
  expect "front/riskscale.php : apercu de l'effet" 200 \
    "$(code -d "preview=1&size=5&_glpi_csrf_token=$(csrf)" "$P/riskscale.php")"
  expect "front/riskscale.php : appliquer sans jeton CSRF" 403 \
    "$(code -d "apply=1&size=5&confirm=1" "$P/riskscale.php")"
  contains "front/config.php : matrice toujours en 4 x 4" 'name="matrix_very_rare_negligible"' non \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
  for p in $READABLE; do expect "$p : super-admin" 200 "$(code "$BASE/plugins/grcmanager/$p")"; done
  # Issue #779 : les editeurs surveilles ne se modifient pas sans jeton CSRF.
  expect "front/softwarelicensecompliance.php : editeurs sans jeton CSRF" 403 \
    "$(code -d "save_license_watch=1&license_publishers=securite" "$P/softwarelicensecompliance.php")"
  # Issue #542 : dossier preparatoire de la revue de direction : refuse sans jeton CSRF, genere avec,
  # puis liste sur l'onglet de la revue avec son empreinte.
  expect "front/managementreview.dossier.php : sans jeton CSRF" 403 \
    "$(code -d "generate=1&id=$(id mreview)&period_start=2026-01-01&period_end=2026-06-30" "$P/managementreview.dossier.php")"
  expect "front/managementreview.dossier.php : generer le dossier" 302 \
    "$(code -d "generate=1&id=$(id mreview)&period_start=2026-01-01&period_end=2026-06-30&_glpi_csrf_token=$(csrf)" "$P/managementreview.dossier.php")"
  contains "onglet Dossier preparatoire : empreinte du dossier archive" 'dossier-revue-direction' oui \
    "$(tab GlpiPlugin%5CGrcmanager%5CManagementReview 'GlpiPlugin%5CGrcmanager%5CManagementReviewDossier$1' "$(id mreview)")"
  # Issue #868 : dossier de preuves refuse sans jeton CSRF, genere avec.
  expect "front/evidencepack.php : sans jeton CSRF" 403     "$(code -d "generate=1&entities_id=0" "$P/evidencepack.php")"
  expect "front/evidencepack.php : generer le dossier de preuves" 302     "$(code -d "generate=1&entities_id=0&_glpi_csrf_token=$(csrf)" "$P/evidencepack.php")"
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
# Issue #142, #664 : le flux iCal d'abonnement ignore la session et n'accepte qu'un jeton dedie au
# calendrier valide (jamais le jeton personnel GLPI).
expect "front/compliancecalendar.ics.php : sans jeton" 403    "$(curl -s -o /dev/null -w '%{http_code}' "$P/compliancecalendar.ics.php")"
expect "front/compliancecalendar.ics.php : jeton invalide" 403    "$(code "$P/compliancecalendar.ics.php?token=jeton-invalide")"
expect "front/compliancecalendar.ics.php : jeton au bon format inconnu" 403   "$(code "$P/compliancecalendar.ics.php?token=1.$(printf '0%.0s' $(seq 64))")"
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
# Issue #620 : registre des violations, rattache a l'entite de la fiche (lecture, modification, export).
expect "violation de donnees, entite A" 200   "$(code "$P/databreach.form.php?id=$(id breach_A)")"
expect "violation de donnees, entite B" $DENY "$(code "$P/databreach.form.php?id=$(id breach_B)")"
expect "modifier une violation de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id breach_B)&comment=securite&_glpi_csrf_token=$(csrf)" "$P/databreach.form.php")"
contains "export du registre des violations : violation de B" "Violation securite B" $SEE_B \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/databreach.export.php?format=csv")"
expect "modifier un traitement de B"   "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id processing_B)&comment=securite&_glpi_csrf_token=$(csrf)" "$P/processingactivity.form.php")"
processing_page=$(tab GlpiPlugin%5CGrcmanager%5CProcessingActivity 'GlpiPlugin%5CGrcmanager%5CProcessingActivity$main' "$(id processing_A)")
contains "traitement de A : actif de A nomme" "PC securite A" oui "$processing_page"
contains "traitement de A : actif de B nomme" "PC securite B" $SEE_B "$processing_page"
processing_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/processingactivity.export.php?format=csv")
contains "export CSV du registre : traitement de A" "Traitement securite A" oui "$processing_csv"
contains "export CSV du registre : traitement de B" "Traitement securite B" $SEE_B "$processing_csv"
contains "export CSV du registre : actif de B" "PC securite B" $SEE_B "$processing_csv"
# Issue #614 : controles de coherence, AIPD requise non faite sur les traitements de A et de B :
# le traitement de B n'est jamais cite pour un profil limite a A (page et export).
consistency_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/consistencycheck.export.php?format=csv&rule=processing_dpia_pending")
contains "coherence, export CSV : traitement de A" "Traitement securite A" oui "$consistency_csv"
contains "coherence, export CSV : traitement de B" "Traitement securite B" $SEE_B "$consistency_csv"
contains "coherence, page : traitement de B" "Traitement securite B" $SEE_B   "$(curl -s -c "$JAR" -b "$JAR" "$P/consistencycheck.php?rule=processing_dpia_pending")"
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
# Issue #703 : une exigence NIS2 Italia - ACN appartient au registre de son entite
# (front/acnrequirement.form.php : lecture et modification, par entite).
expect "exigence ACN, entite A"         200    "$(code "$P/acnrequirement.form.php?id=$(id acn_A)")"
expect "exigence ACN, entite B"         $DENY  "$(code "$P/acnrequirement.form.php?id=$(id acn_B)")"
expect "modifier une exigence ACN de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)"   "$(code -d "update=1&id=$(id acn_B)&comment=securite&_glpi_csrf_token=$(csrf)" "$P/acnrequirement.form.php")"
acn_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/acnrequirement.export.php?format=csv&entities_id=$(id acn_entity_B)")
contains "export ACN : registre de B" "DE.CM-01, punto 7" $SEE_B "$acn_csv"
# Issue #599 : une mesure ReCyF (NIS2 France) appartient au registre de son entite
# (front/recyfrequirement.form.php : lecture et modification, par entite). Le registre de B est
# celui d'une entite essentielle : seul lui contient les mesures des objectifs 16 a 20.
expect "mesure ReCyF, entite A"         200    "$(code "$P/recyfrequirement.form.php?id=$(id recyf_A)")"
expect "mesure ReCyF, entite B"         $DENY  "$(code "$P/recyfrequirement.form.php?id=$(id recyf_B)")"
expect "modifier une mesure ReCyF de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)"   "$(code -d "update=1&id=$(id recyf_B)&comment=securite&_glpi_csrf_token=$(csrf)" "$P/recyfrequirement.form.php")"
recyf_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/recyfrequirement.export.php?format=csv&entities_id=$(id recyf_entity_B)")
contains "export ReCyF : registre de B" "20.6-EE" $SEE_B "$recyf_csv"

# Issue #608 : une fiche du contexte de l'organisation appartient a son entite.
expect "contexte, entite A"             200    "$(code "$P/contextitem.form.php?id=$(id context_A)")"
expect "contexte, entite B"             $DENY  "$(code "$P/contextitem.form.php?id=$(id context_B)")"
contexts=$(curl -s -c "$JAR" -b "$JAR" "$P/contextitem.php")
contains "liste du contexte : partie de A" "Partie securite A" oui    "$contexts"
contains "liste du contexte : partie de B" "Partie securite B" $SEE_B "$contexts"
# Le nom est conserve : la fiche reste reconnaissable pour les profils suivants.
expect "modifier le contexte de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id context_B)&name=Partie%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/contextitem.form.php")"
# Issue #612 : une activite de la matrice des responsabilites appartient a son entite. Ajouter les
# activites par defaut ou archiver la matrice est refuse sans droit comme en lecture seule.
expect "matrice, activite de A"          200    "$(code "$P/responsibilityactivity.form.php?id=$(id responsibility_A)")"
expect "matrice, activite de B"          $DENY  "$(code "$P/responsibilityactivity.form.php?id=$(id responsibility_B)")"
matrix=$(curl -s -c "$JAR" -b "$JAR" "$P/responsibilityactivity.php")
contains "matrice : activite de A" "Activite securite A" oui    "$matrix"
contains "matrice : activite de B" "Activite securite B" $SEE_B "$matrix"
expect "modifier l'activite de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)"   "$(code -d "update=1&id=$(id responsibility_B)&name=Activite%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/responsibilityactivity.form.php")"
if [ "$ROLE" = none ] || [ "$ROLE" = reader ]; then
  for action in add_defaults=1 archive=1; do
    expect "front/responsibilityactivity.php : ${action%%=*}" 403       "$(code -d "$action&_glpi_csrf_token=$(csrf)" "$P/responsibilityactivity.php")"
  done
fi
# Issue #608, lot 2 : export du registre et onglet du risque de A, limites aux entites de la session.
context_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/contextitem.export.php?format=csv")
contains "export du contexte : partie de A" "Partie securite A" oui    "$context_csv"
contains "export du contexte : partie de B" "Partie securite B" $SEE_B "$context_csv"
risk_context=$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CContextItem$1' "$(id risk)")
contains "onglet Contexte du risque : partie de A" "Partie securite A" oui    "$risk_context"
contains "onglet Contexte du risque : partie de B" "Partie securite B" $SEE_B "$risk_context"
kris=$(curl -s -c "$JAR" -b "$JAR" "$P/riskindicator.php")
contains "liste des KRI : KRI de A" "KRI securite A" oui    "$kris"
contains "liste des KRI : KRI de B" "KRI securite B" $SEE_B "$kris"
expect "campagne de revue, entite A"   200    "$(code "$P/accessreview.form.php?id=$(id campaign_A)")"
expect "campagne de revue, entite B"   $DENY  "$(code "$P/accessreview.form.php?id=$(id campaign_B)")"
# Issue #866 : recalcul des suggestions, droit de modification sur la campagne exigé.
expect "recalculer les suggestions de la campagne B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)"   "$(code -d "refresh_suggestions=1&id=$(id campaign_B)&_glpi_csrf_token=$(csrf)" "$P/accessreview.form.php")"
if [ "$ROLE" = none ] || [ "$ROLE" = reader ]; then
  expect "recalculer les suggestions de la campagne A en lecture seule" 403     "$(code -d "refresh_suggestions=1&id=$(id campaign_A)&_glpi_csrf_token=$(csrf)" "$P/accessreview.form.php")"
fi

control_tab=$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CAccessReview$2' "$(id control)")
contains "onglet revues d'acces d'un controle : campagne A" "Campagne securite A" oui     "$control_tab"
contains "onglet revues d'acces d'un controle : campagne B" "Campagne securite B" $SEE_B  "$control_tab"

contracts_tab=$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$2' "$(id supplierrisk)")
contains "onglet contrats du fournisseur : contrat A" "Contrat securite A" oui     "$contracts_tab"
contains "onglet contrats du fournisseur : contrat B" "Contrat securite B" $SEE_B  "$contracts_tab"

# Issue #776 : scenarios de crise de son entite seulement ; un scenario ou un processus de B n'est
# jamais nomme, et un scenario de B ne se modifie pas.
expect "scenario de crise, entite A" 200 "$(code "$P/crisisscenario.form.php?id=$(id crisis_scenario_A)")"
expect "scenario de crise, entite B" $DENY "$(code "$P/crisisscenario.form.php?id=$(id crisis_scenario_B)")"
scenarios=$(body "$P/crisisscenario.php")
contains "registre des scenarios : scenario de A" "Scenario crise securite A" oui "$scenarios"
contains "registre des scenarios : scenario de B" "Scenario crise securite B" $SEE_B "$scenarios"
contains "registre des scenarios : processus de B" "Processus securite B" $SEE_B "$scenarios"
contains "onglet Scenarios de crise du processus A" "Scenario crise securite A" oui \
  "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CCrisisScenario$1' "$(id process_A)")"
# Issue #776, lot 2 : onglet Preparation du scenario de A : ni contact officiel ni processus de B,
# quel que soit le profil (contacts visibles depuis l'entite du scenario) ; scenario de B refuse.
readiness_A=$(tab GlpiPlugin%5CGrcmanager%5CCrisisScenario 'GlpiPlugin%5CGrcmanager%5CCrisisScenarioReadiness$1' "$(id crisis_scenario_A)")
contains "onglet Preparation du scenario A : processus de A" "Processus securite A" oui "$readiness_A"
contains "onglet Preparation du scenario A : contact officiel de B" "Partie securite B" non "$readiness_A"
contains "onglet Preparation du scenario A : processus de B" "Processus securite B" non "$readiness_A"
contains "onglet Preparation du scenario B" "Processus securite B" $SEE_B \
  "$(tab GlpiPlugin%5CGrcmanager%5CCrisisScenario 'GlpiPlugin%5CGrcmanager%5CCrisisScenarioReadiness$1' "$(id crisis_scenario_B)")"
expect "modifier un scenario de crise de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id crisis_scenario_B)&degraded_mode=securite&_glpi_csrf_token=$(csrf)" "$P/crisisscenario.form.php")"
expect "modifier un scenario de crise sans jeton CSRF" 403 \
  "$(code -d "update=1&id=$(id crisis_scenario_A)&degraded_mode=securite" "$P/crisisscenario.form.php")"

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
  # Issue #868 : dossier de preuves de son entite genere et liste ; celui de B refuse ; la preuve de B
  # (rattachee a la mesure de A) n'est jamais nommee sur la page.
  expect "front/evidencepack.php : dossier de preuves de B" 403     "$(code -d "generate=1&entities_id=$(id b)&_glpi_csrf_token=$(csrf)" "$P/evidencepack.php")"
  expect "front/evidencepack.php : dossier de preuves de A" 302     "$(code -d "generate=1&entities_id=$(id a)&_glpi_csrf_token=$(csrf)" "$P/evidencepack.php")"
  pack_page=$(curl -s -c "$JAR" -b "$JAR" "$P/evidencepack.php?entities_id=$(id a)")
  contains "dossier de preuves : archive listee" 'ti-fingerprint' oui "$pack_page"
  contains "dossier de preuves : preuve de B absente" 'Preuve securite B' non "$pack_page"
  # Issue #847 : sauvegarde de cle declaree pour un ordinateur de A, refusee pour celui de B.
  expect "front/diskencryption.php : declarer la cle d'un ordinateur de A" 302     "$(code -d "declare_key=1&computers_id=$(id computer_A)&is_backed_up=1&backup_date=2026-01-15&_glpi_csrf_token=$(csrf)" "$P/diskencryption.php")"
  expect "front/diskencryption.php : declarer la cle d'un ordinateur de B" 403     "$(code -d "declare_key=1&computers_id=$(id computer_B)&is_backed_up=1&_glpi_csrf_token=$(csrf)" "$P/diskencryption.php")"
  # Issue #853 : conservation declaree par le gestionnaire, jamais sans jeton CSRF.
  expect "front/logcoverage.php : declarer une plateforme" 302     "$(code -d "declare_log_platform=1&platform=splunk&retention_days=365&_glpi_csrf_token=$(csrf)" "$P/logcoverage.php")"
  expect "front/logcoverage.php : declarer une plateforme sans jeton CSRF" 403     "$(code -d "declare_log_platform=1&platform=splunk&retention_days=1" "$P/logcoverage.php")"
  # Issue #854 : delais cibles modifiables par le gestionnaire, jamais sans jeton CSRF.
  expect "front/patchcompliance.php : delais cibles" 302 \
    "$(code -d "save_patch_targets=1&patch_targets%5Bcritical%5D=15&_glpi_csrf_token=$(csrf)" "$P/patchcompliance.php")"
  expect "front/patchcompliance.php : delais cibles sans jeton CSRF" 403 \
    "$(code -d "save_patch_targets=1&patch_targets%5Bcritical%5D=3" "$P/patchcompliance.php")"
  # Issue #781 : analyse de B ni lue ni supprimee ; suppression jamais sans jeton CSRF.
  expect "front/configcompliance.php : analyse de B" 404 "$(code "$P/configcompliance.php?report=$(id configscan_B)")"
  expect "front/configcompliance.php : supprimer l'analyse de B" 403     "$(code -d "delete_report=1&report=$(id configscan_B)&_glpi_csrf_token=$(csrf)" "$P/configcompliance.php")"
  expect "front/configcompliance.php : supprimer sans jeton CSRF" 403     "$(code -d "delete_report=1&report=$(id configscan_A)" "$P/configcompliance.php")"
  expect "front/diskencryption.php : declarer une cle sans jeton CSRF" 403     "$(code -d "declare_key=1&computers_id=$(id computer_A)&is_backed_up=1" "$P/diskencryption.php")"
  # Issue #706 : gestionnaire avec la configuration du plugin et les registres sensibles en
  # modification : l'ecran de changement de taille lui est ouvert, comme la page de configuration.
  expect "front/riskscale.php : ecran de correspondance" 200 "$(code "$P/riskscale.php?size=5")"
  # Issue #779 : droit du plugin sans la lecture native des licences : page et export refuses.
  expect "front/softwarelicensecompliance.php : sans le droit natif des licences" 403 \
    "$(code "$P/softwarelicensecompliance.php")"
  expect "front/softwarelicensecompliance.export.php : sans le droit natif des licences" 403 \
    "$(code "$P/softwarelicensecompliance.export.php")"
  # Issue #782 : droit du plugin sans la lecture native des informations financieres : export
  # refuse, carte absente de la page des vulnerabilites du parc.
  expect "front/hardwarewarranty.export.php : sans le droit natif des informations financieres" 403 \
    "$(code "$P/hardwarewarranty.export.php")"
  contains "fin de garantie : carte absente sans les informations financieres" 'id="grc-hardware-warranty"' non \
    "$(body "$P/parkvulnerability.php")"
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
  # Issue #733 : l'element teste doit etre une dependance lisible du processus.
  expect "test de reprise designant le PC de B (illisible)" 404 \
    "$(code -d "add=1&plugin_grcmanager_businessprocesses_id=$(id process_A)&measured_minutes=60&asset=Computer:$(id computer_B)&_glpi_csrf_token=$(csrf)" "$P/businessprocessrecovery.form.php")"
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
# Issue #733 : programme des tests de reprise, limite aux processus lisibles.
programme=$(curl -s -c "$JAR" -b "$JAR" "$P/recoveryprogramme.php")
contains "programme des tests de reprise : processus de A" "Processus securite A" oui "$programme"
if [ "$ROLE" = limited ]; then
  contains "programme des tests de reprise : processus de B" "Processus securite B" non "$programme"
fi
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
# Issue #701 : l'export en image part du graphe affiche ; ses titre, nom de fichier et mention des
# elements caches ne nomment rien de B pour un profil limite a A.
contains "carte des dependances de A : bouton d'export" "grc-map-export" oui "$map"
contains "carte des dependances de A : export, nom de fichier" "processus-securite-a-20" oui "$map"
map_b=$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(id process_B)")
contains "carte des dependances d'un processus de B" "Processus securite B" $SEE_B "$map_b"
contains "carte des dependances d'un processus de B : bouton d'export" "grc-map-export" $SEE_B "$map_b"

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

# Issue #607 : echantillon tire dans les ordinateurs visibles seulement (entites actives) ; le
# gestionnaire de A ne tire jamais l'ordinateur de B, ni dans les tickets sans le droit de tous les
# voir. Il ne relit pas un test qu'il a enregistre ; le super-admin le peut. Fiche PDF du test.
sample_loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" \
  -d "draw=1&plugin_grcmanager_controls_id=$(id control)&itemtype=Computer&name=Chiffrement%20securite&sample_size=100&_glpi_csrf_token=$(csrf)" \
  "$P/controltestsample.form.php")
contains "tirage d'un echantillon : page de l'echantillon" "controltestsample.form.php?id=" oui "$sample_loc"
sample_page=$(curl -s -c "$JAR" -b "$JAR" "$sample_loc")
contains "echantillon : ordinateur de A tire" "PC securite A" oui "$sample_page"
contains "echantillon : ordinateur de B" "PC securite B" $SEE_B "$sample_page"
contains "echantillon : graine conservee" "grc-sample-seed" oui "$sample_page"
if [ "$ROLE" != admin ]; then
  ticket_loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" \
    -d "draw=1&plugin_grcmanager_controls_id=$(id control)&itemtype=Ticket&name=securite&_glpi_csrf_token=$(csrf)" \
    "$P/controltestsample.form.php")
  contains "tirage dans les tickets sans le droit de tous les voir" "controltestsample.form.php?id=" non "$ticket_loc"
fi
expect "front/controltest.pdf.php : fiche du test" 200 "$(code "$P/controltest.pdf.php?id=$(id review_test)")"
expect "relire un test qu'on a enregistre" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "review=approve&id=$(id review_test)&_glpi_csrf_token=$(csrf)" "$P/controltest.form.php")"

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

# Issue #546 : fiche de conformité sécurité. Chacun voit la sienne (préférences) ; la liste et la
# fiche d'un autre exigent la lecture du plugin et des utilisateurs, dans les entités de la session.
# Saisir la vérification ou prévenir le responsable exige de modifier le plugin.
SEE_STAFF=oui; [ "$ROLE" = none ] && SEE_STAFF=non
contains "onglet Ma conformite securite : sa propre fiche" "grc-pc-own" oui \
  "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CPersonnelCompliance$1' 2)"
staff=$(curl -s -c "$JAR" -b "$JAR" "$P/personnelcompliance.php")
contains "front/personnelcompliance.php : collaborateur de A" "Collaborateur securite A" $SEE_STAFF "$staff"
contains "front/personnelcompliance.php : collaborateur de B" "Collaborateur securite B" $SEE_B "$staff"
staff_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/personnelcompliance.export.php?format=csv")
contains "export CSV : collaborateur de B" "Collaborateur securite B" $SEE_B "$staff_csv"
expect "export de la fiche d'un collaborateur de B" "$([ "$ROLE" = admin ] && echo 200 || echo 403)" \
  "$(code "$P/personnelcompliance.export.php?format=csv&users_id=$(id collab_B)")"
expect "export de la fiche d'un collaborateur de A" "$([ "$SEE_STAFF" = oui ] && echo 200 || echo 403)" \
  "$(code "$P/personnelcompliance.export.php?format=csv&users_id=$(id collab_A)")"
# Le profil sans registres sensibles (droit de modifier le plugin) n'enregistre rien ici : la CI
# compare le nombre de lignes avant et apres son passage.
EDIT_STAFF=403; case $ROLE in limited|admin) EDIT_STAFF=302 ;; general) EDIT_STAFF= ;; esac
[ -n "$EDIT_STAFF" ] && expect "front/personnelcompliance.form.php : saisie pour A" $EDIT_STAFF \
  "$(code -d "save=1&users_id=$(id collab_A)&screening_status=done&screening_date=2026-01-05&_glpi_csrf_token=$(csrf)" "$P/personnelcompliance.form.php")"
expect "fiche de conformite de B : saisie" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "save=1&users_id=$(id collab_B)&screening_status=done&_glpi_csrf_token=$(csrf)" "$P/personnelcompliance.form.php")"
expect "fiche de conformite de B : prevenir le responsable" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "notify=1&users_id=$(id collab_B)&_glpi_csrf_token=$(csrf)" "$P/personnelcompliance.form.php")"
expect "fiche de conformite : saisie sans jeton CSRF" 403 \
  "$(code -d "save=1&users_id=$(id collab_A)&screening_status=done" "$P/personnelcompliance.form.php")"

# Issue #774 : compétences. Chacun voit sa propre fiche (préférences) ; la page, les fonctions et les
# déclarations suivent le droit du plugin et les entités de la session ; les personnes et le dossier
# PDF exigent aussi la lecture des utilisateurs. Archiver exige de modifier le plugin.
contains "onglet Mes competences : sa propre fiche" "grc-competence-sheet" oui \
  "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CCompetenceMatrix$1' 2)"
contains "onglet Competences d'un collaborateur de B" "grc-competence-sheet" $SEE_B \
  "$(tab User 'GlpiPlugin%5CGrcmanager%5CCompetenceMatrix$1' "$(id collab_B)")"
skills=$(curl -s -c "$JAR" -b "$JAR" "$P/ismsfunction.php")
contains "front/ismsfunction.php : fonction de A" "Fonction securite A" $SEE_STAFF "$skills"
contains "front/ismsfunction.php : fonction de B" "Fonction securite B" $SEE_B "$skills"
contains "front/ismsfunction.php : collaborateur de A" "Collaborateur securite A" $SEE_STAFF "$skills"
contains "front/ismsfunction.php : collaborateur de B" "Collaborateur securite B" $SEE_B "$skills"
expect "fiche de la fonction de B" "$DENY" "$(code "$P/ismsfunction.form.php?id=$(id ismsfunction_B)")"
expect "fiche de poste PDF de la fonction de B" "$DENY" "$(code "$P/ismsfunction.pdf.php?id=$(id ismsfunction_B)")"
expect "competence declaree du collaborateur de B" "$DENY" \
  "$(code "$P/competencedeclaration.form.php?id=$(id competencedeclaration_B)")"
expect "fonction de B : ajout d'un titulaire" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id ismsfunction_B)&_add_users_id=$(id collab_A)&_glpi_csrf_token=$(csrf)" "$P/ismsfunction.form.php")"
ARCHIVE=403; case $ROLE in limited|admin) ARCHIVE=302 ;; general) ARCHIVE= ;; esac
[ -n "$ARCHIVE" ] && expect "front/ismsfunction.php : archiver le dossier" $ARCHIVE \
  "$(code -d "archive=1&_glpi_csrf_token=$(csrf)" "$P/ismsfunction.php")"
[ "$ROLE" = reader ] || [ "$ROLE" = none ] && expect "front/ismsfunction.php : fonctions par defaut" 403 \
  "$(code -d "add_defaults=1&_glpi_csrf_token=$(csrf)" "$P/ismsfunction.php")"
expect "front/ismsfunction.php : archiver sans jeton CSRF" 403 "$(code -d "archive=1" "$P/ismsfunction.php")"
# Lot 3 : titulaires repris d'une activité de la matrice des responsabilités. L'activité de B n'est
# jamais acceptée pour la fonction de A (entité), et elle n'est proposée qu'à qui voit B.
code -d "update=1&id=$(id ismsfunction_A)&_add_responsibilityactivities_id=$(id responsibility_B)&_add_raci_role=responsible&_glpi_csrf_token=$(csrf)" "$P/ismsfunction.form.php" >/dev/null
contains "fonction de A : activite de B refusee comme titulaire" "Activite securite B" non \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/ismsfunction.form.php?id=$(id ismsfunction_A)")"
# Lot 2 : session proposée pour les écarts (formulaire de formation servi par ajax/common.tabs.php) ;
# seules les personnes visibles en écart sont présélectionnées, rien n'est enregistré.
contains "session proposee pour les ecarts de A : collaborateur de A" "Collaborateur securite A" $SEE_STAFF   "$(tab 'GlpiPlugin%5CGrcmanager%5CTraining' 'GlpiPlugin%5CGrcmanager%5CTraining$main' "0&competence_gaps_of=$(id competence_training_A)")"
contains "session proposee pour les ecarts de B : collaborateur de B" "Collaborateur securite B" $SEE_B   "$(tab 'GlpiPlugin%5CGrcmanager%5CTraining' 'GlpiPlugin%5CGrcmanager%5CTraining$main' "0&competence_gaps_of=$(id competence_training_B)")"

# Issue #778 : securite physique des sites. Page de comparaison, liste, fiche et onglets suivent le
# droit du plugin et l'entite de l'evaluation ; lignes et zones exigent de modifier l'evaluation.
physical=$(curl -s -c "$JAR" -b "$JAR" "$P/physicalsecurity.php")
contains "front/physicalsecurity.php : site de A" "Site securite A" oui "$physical"
contains "front/physicalsecurity.php : site de B" "Site securite B" $SEE_B "$physical"
expect "fiche de l'evaluation de B" "$DENY" "$(code "$P/physicalsecurityassessment.form.php?id=$(id physical_B)")"
expect "fiche de l'evaluation de A" 200 "$(code "$P/physicalsecurityassessment.form.php?id=$(id physical_A)")"
# Lot 2 : rapport PDF de l'evaluation, meme droit et meme entite que la fiche.
expect "front/physicalsecurityassessment.pdf.php : rapport de B" "$DENY" "$(code "$P/physicalsecurityassessment.pdf.php?id=$(id physical_B)")"
expect "front/physicalsecurityassessment.pdf.php : rapport de A" 200 "$(code "$P/physicalsecurityassessment.pdf.php?id=$(id physical_A)")"
# Issue #603 : rapport PDF d'une campagne de maturite (autres cas dans access-matrix.d/603-*).
expect "front/maturitycampaign.pdf.php : rapport de B" "$DENY" "$(code "$P/maturitycampaign.pdf.php?id=$(id maturity_B)")"
contains "onglet grille de l'evaluation de B" "grc-physical-checklist" $SEE_B \
  "$(tab GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment 'GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment$1' "$(id physical_B)")"
contains "onglet zones de l'evaluation de B" "Salle serveurs securite B" $SEE_B \
  "$(tab GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment 'GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment$2' "$(id physical_B)")"
contains "onglet securite physique du lieu de B" "Evaluation securite B" $SEE_B \
  "$(tab Location 'GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment$1' "$(id location_B)")"
if [ "$ROLE" != admin ]; then
  expect "evaluation de B : modifier une ligne" 403 \
    "$(code -d "save_check=1&plugin_grcmanager_physicalsecurityassessments_id=$(id physical_B)&id=$(id physical_line_B)&state=compliant&_glpi_csrf_token=$(csrf)" "$P/physicalsecurityassessment.form.php")"
  expect "evaluation de B : ajouter une zone" 403 \
    "$(code -d "add_zone=1&plugin_grcmanager_physicalsecurityassessments_id=$(id physical_B)&name=securite&_glpi_csrf_token=$(csrf)" "$P/physicalsecurityassessment.form.php")"
  expect "evaluation de B : supprimer une zone" 403 \
    "$(code -d "delete_zone=1&plugin_grcmanager_physicalsecurityassessments_id=$(id physical_B)&id=$(id physical_zone_B)&_glpi_csrf_token=$(csrf)" "$P/physicalsecurityassessment.form.php")"
fi
[ "$ROLE" = limited ] && expect "evaluation de A : ligne de B glissee dans le formulaire" 400 \
  "$(code -d "save_check=1&plugin_grcmanager_physicalsecurityassessments_id=$(id physical_A)&id=$(id physical_line_B)&state=compliant&_glpi_csrf_token=$(csrf)" "$P/physicalsecurityassessment.form.php")"
EDIT_PHYSICAL=403; case $ROLE in limited|admin) EDIT_PHYSICAL=302 ;; general) EDIT_PHYSICAL= ;; esac
[ -n "$EDIT_PHYSICAL" ] && expect "evaluation de A : modifier une ligne" $EDIT_PHYSICAL \
  "$(code -d "save_check=1&plugin_grcmanager_physicalsecurityassessments_id=$(id physical_A)&id=$(id physical_line_A)&state=partial&_glpi_csrf_token=$(csrf)" "$P/physicalsecurityassessment.form.php")"
expect "evaluation de A : modifier une ligne sans jeton CSRF" 403 \
  "$(code -d "save_check=1&plugin_grcmanager_physicalsecurityassessments_id=$(id physical_A)&id=$(id physical_line_A)&state=compliant" "$P/physicalsecurityassessment.form.php")"

# Issue #535 : prise de connaissance des politiques. Seul glpi (admin) est dans le public de la
# politique : les autres ne la voient ni dans leurs preferences ni dans leur espace, ne lisent pas
# ses documents et ne peuvent pas confirmer a sa place (sa ligne reste a confirmer, verifie par
# l'admin, dernier passage de la CI). Les exports exigent la lecture des politiques.
IS_ADMIN=non; [ "$ROLE" = admin ] && IS_ADMIN=oui
READ_POLICY=200; [ "$ROLE" = none ] && READ_POLICY=403
mine_tab=$(tab Preference 'GlpiPlugin%5CGrcmanager%5CPolicyAcknowledgement$1' 2)
contains "onglet Politiques a lire : politique du public" "Politique lecture securite" $IS_ADMIN "$mine_tab"
contains "front/myspace.php : politique a lire" "Politique lecture securite" $IS_ADMIN "$(curl -s -c "$JAR" -b "$JAR" "$P/myspace.php")"
expect "front/policyacknowledgement.send.php : document de la politique" "$([ "$ROLE" = admin ] && echo 404 || echo 403)" \
  "$(code "$P/policyacknowledgement.send.php?policies_id=$(id policy_ack)&documents_id=$(id document_A)")"
expect "front/policyacknowledgement.send.php : document d'une autre politique" 403 \
  "$(code "$P/policyacknowledgement.send.php?policies_id=$(id policy_ack)&documents_id=$(id audit_document)")"
for format in csv pdf; do
  expect "front/policyacknowledgement.export.php : $format" $READ_POLICY \
    "$(code "$P/policyacknowledgement.export.php?id=$(id ack_campaign)&format=$format")"
done
contains "onglet Prise de connaissance d'une politique" "grc-ack-members" "$([ "$ROLE" = none ] && echo non || echo oui)" \
  "$(tab GlpiPlugin%5CGrcmanager%5CPolicy 'GlpiPlugin%5CGrcmanager%5CPolicyAcknowledgement$1' "$(id policy_ack)")"
if [ "$ROLE" = admin ]; then
  contains "ligne de glpi toujours a confirmer" "name=\"lines_id\" value=\"$(id ack_line)\"" oui "$mine_tab"
else
  code -d "acknowledge=1&confirm_read=1&lines_id=$(id ack_line)&_glpi_csrf_token=$(csrf)" "$P/policyacknowledgement.form.php" > /dev/null
fi
if [ "$ROLE" = none ] || [ "$ROLE" = reader ]; then
  for action in launch=1 remind=1 close=1 add_audience=1 save_days=1; do
    expect "front/policyacknowledgement.form.php : ${action%%=*}" 403 \
      "$(code -d "$action&policies_id=$(id policy_ack)&campaigns_id=$(id ack_campaign)&itemtype=Group&items_id=1&acknowledgement_days=3&_glpi_csrf_token=$(csrf)" "$P/policyacknowledgement.form.php")"
  done
fi

# Issue #611 : approbation des politiques. Exporter l'historique exige la lecture des politiques ;
# demander, retirer, preparer une version ou designer les approbateurs exige leur modification ; un
# PDF fige n'est servi qu'aux lecteurs ou a l'approbateur designe de la version.
expect "front/policyapproval.php : export de l'historique" $READ_POLICY   "$(code "$P/policyapproval.php?export=$(id policy_ack)")"
expect "front/policyapproval.php : PDF fige inexistant" 404 "$(code "$P/policyapproval.php?pdf=999999999")"
if [ "$ROLE" = none ] || [ "$ROLE" = reader ]; then
  for action in submit_approval=1 save_approvers=1 withdraw=1 new_version_submit=1; do
    expect "front/policyapproval.php : ${action%%=*}" 403       "$(code -d "$action&policies_id=$(id policy_ack)&versions_id=1&new_version=9.9&approver_users[]=2&_glpi_csrf_token=$(csrf)" "$P/policyapproval.php")"
  done
fi

# Issue #538 : demandes de preuves d'audit et espace auditeur. iso-sans-droit (aucun droit sur le
# plugin) a un acces auditeur en vigueur a « Audit securite » seulement : il y voit la demande
# soumise et telecharge sa piece, jamais la demande en preparation ni sa piece, ni un autre audit,
# ni les documents de travail de l'audit (document.send.php ci-dessus). glpi est la personne
# designee des deux demandes ; personne d'autre ne peut fournir ou soumettre a sa place (la
# demande en preparation reste a fournir, verifie par l'admin, dernier passage de la CI).
IS_AUDITOR=non; [ "$ROLE" = none ] && IS_AUDITOR=oui
OPEN_AUDIT=403; [ "$ROLE" = none ] && OPEN_AUDIT=200
expect "front/auditorspace.php : liste ouverte a toute session" 200 "$(code "$P/auditorspace.php")"
expect "front/auditorspace.php : audit de l'acces auditeur" $OPEN_AUDIT "$(code "$P/auditorspace.php?id=$(id audit)")"
expect "front/auditorspace.php : audit hors de l'acces" 403 "$(code "$P/auditorspace.php?id=$(id audit_other)")"
space=$(curl -s -c "$JAR" -b "$JAR" "$P/auditorspace.php?id=$(id audit)")
contains "espace auditeur : demande soumise" "REQ securite soumise" $IS_AUDITOR "$space"
contains "espace auditeur : demande en preparation absente" "REQ securite brouillon" non "$space"
contains "espace auditeur : document de travail absent" "Plan d&#039;audit securite" non "$space"
contains "front/auditorspace.php : liste de l'auditeur" "Audit securite" $IS_AUDITOR "$(curl -s -c "$JAR" -b "$JAR" "$P/auditorspace.php")"
SEND_SUBMITTED=403; { [ "$ROLE" = none ] || [ "$ROLE" = admin ]; } && SEND_SUBMITTED=200
expect "front/auditevidencerequest.send.php : piece soumise" $SEND_SUBMITTED \
  "$(code "$P/auditevidencerequest.send.php?id=$(id evpiece_submitted)")"
expect "front/auditevidencerequest.send.php : piece en preparation" "$([ "$ROLE" = admin ] && echo 200 || echo 403)" \
  "$(code "$P/auditevidencerequest.send.php?id=$(id evpiece_draft)")"
expect "front/auditevidencerequest.mine.php : ouverte a toute session" 200 "$(code "$P/auditevidencerequest.mine.php")"
contains "front/auditevidencerequest.mine.php : demande confiee a glpi" "REQ securite brouillon" $IS_ADMIN \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/auditevidencerequest.mine.php")"
evtab=$(tab GlpiPlugin%5CGrcmanager%5CAudit 'GlpiPlugin%5CGrcmanager%5CAuditEvidenceRequest$1' "$(id audit)")
contains "onglet Demandes de preuves d'un audit" "grc-evreq-list" "$([ "$ROLE" = none ] && echo non || echo oui)" "$evtab"
if [ "$ROLE" = admin ]; then
  contains "demande en preparation toujours a fournir" "id=\"grc-evreq-$(id evreq_draft)\" class=\"grc-evreq-row grc-evreq-status-requested" oui "$evtab"
  contains "demande soumise toujours soumise" "id=\"grc-evreq-$(id evreq_submitted)\" class=\"grc-evreq-row grc-evreq-status-submitted" oui "$evtab"
else
  for action in submit_request=1 remove_piece=1 add_existing=1; do
    code -d "$action&requests_id=$(id evreq_draft)&pieces_id=$(id evpiece_draft)&documents_id=$(id document_A)&_glpi_csrf_token=$(csrf)" \
      "$P/auditevidencerequest.mine.php" > /dev/null
  done
fi
if [ "$ROLE" = none ] || [ "$ROLE" = reader ]; then
  for action in add=1 accept=1 ask_completion=1 update=1 purge=1 remind=1 grant_access=1 revoke_access=1; do
    expect "front/auditevidencerequest.form.php : ${action%%=*}" 403 \
      "$(code -d "$action&plugin_grcmanager_audits_id=$(id audit)&id=$(id evreq_submitted)&reference=securite&review_comment=x&users_id=2&date_end=$(date +%Y-%m-%d)&accesses_id=1&_glpi_csrf_token=$(csrf)" "$P/auditevidencerequest.form.php")"
  done
  expect "front/auditevidencerequest.form.php : import CSV" 403 \
    "$(code -F import_csv=1 -F "plugin_grcmanager_audits_id=$(id audit)" -F "csv_file=@$EVIDENCE_PDF;filename=liste.csv" -F "_glpi_csrf_token=$(csrf)" "$P/auditevidencerequest.form.php")"
fi

# Issue #606 : grille d'audit, memes regles d'acces que les documents de l'audit. glpi, auditeur de
# « Audit securite », la lit et la modifie ; les autres profils, hors de l'equipe, ne voient que le
# message de refus, et chaque action leur est refusee. Le modele de grille de l'entite racine reste
# hors de portee des profils limites a A.
cktab=$(tab GlpiPlugin%5CGrcmanager%5CAudit 'GlpiPlugin%5CGrcmanager%5CAuditChecklist$1' "$(id audit)")
contains "onglet Grille d'audit : ligne de la grille" "Question securite grille" $IS_ADMIN "$cktab"
contains "onglet Grille d'audit : refus hors equipe" "grc-checklist-denied" \
  "$({ [ "$ROLE" = admin ] || [ "$ROLE" = none ]; } && echo non || echo oui)" "$cktab"
expect "front/auditchecklist.pdf.php : rapport de l'audit" "$([ "$ROLE" = admin ] && echo 200 || echo 403)" \
  "$(code "$P/auditchecklist.pdf.php?id=$(id audit)")"
expect "front/auditchecklist.pdf.php : audit de B" $DENY "$(code "$P/auditchecklist.pdf.php?id=$(id audit_B)")"
if [ "$ROLE" != admin ]; then
  for action in add_refs=1 add_free=1 update=1 purge=1 save_template=1 apply_template=1 delete_template=1; do
    expect "front/auditchecklist.form.php : ${action%%=*}" 403 \
      "$(code -d "$action&plugin_grcmanager_audits_id=$(id audit)&id=$(id checklist_line)&refs[]=control:A.5.1&label=securite&question=x&result=compliant&template_name=securite&templates_id=$(id checklist_template)&_glpi_csrf_token=$(csrf)" "$P/auditchecklist.form.php")"
  done
else
  expect "front/auditchecklist.form.php : renseigner la ligne" 302 \
    "$(code -d "update=1&plugin_grcmanager_audits_id=$(id audit)&id=$(id checklist_line)&question=Question%20securite%20grille&result=compliant&_glpi_csrf_token=$(csrf)" "$P/auditchecklist.form.php")"
  contains "ligne renseignee" "grc-checklist-result-compliant" oui \
    "$(tab GlpiPlugin%5CGrcmanager%5CAudit 'GlpiPlugin%5CGrcmanager%5CAuditChecklist$1' "$(id audit)")"
fi
expect "front/auditchecklist.form.php : grille d'un audit de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add_free=1&plugin_grcmanager_audits_id=$(id audit_B)&label=securite&_glpi_csrf_token=$(csrf)" "$P/auditchecklist.form.php")"

# Issue #540 : registre d'informations DORA (referentiel DORA active par setup.php). Le contrat de
# chaque entite est au registre ; le gestionnaire et le lecteur limites a A ne voient pas celui de B,
# ni dans la page ni dans l'export. Toute saisie est refusee sans droit comme en lecture seule (etat
# en base compare par la CI) ; l'onglet du contrat est vide sans droit sur le plugin.
register=$(curl -s -c "$JAR" -b "$JAR" "$P/doraregister.php?export=B_02.01")
SEE_A=oui; [ "$ROLE" = none ] && SEE_A=non
contains "export DORA : contrat de A" "DORA-securite-A" $SEE_A "$register"
contains "export DORA : contrat de B" "DORA-securite-B" $SEE_B "$register"
contains "onglet registre DORA d'un contrat" "grc-dora-arrangement-form" $SEE_A   "$(tab Contract 'GlpiPlugin%5CGrcmanager%5CDoraRegister$2' "$(id contract_A)")"
if [ "$ROLE" = none ] || [ "$ROLE" = reader ]; then
  for action in save_settings=1 save_provider=1 save_function=1 include=1 exclude=1 save_arrangement=1 add_function=1     remove_function=1 add_subcontractor=1 remove_subcontractor=1; do
    expect "front/doraregister.form.php : ${action%%=*}" 403       "$(code -d "$action&contracts_id=$(id contract_A)&suppliers_id=$(id supplier)&name=securite&reference=securite&_glpi_csrf_token=$(csrf)" "$P/doraregister.form.php")"
  done
fi
if [ "$ROLE" = limited ]; then
  expect "front/doraregister.form.php : contrat de B" 403     "$(code -d "save_arrangement=1&contracts_id=$(id contract_B)&reference=securite&_glpi_csrf_token=$(csrf)" "$P/doraregister.form.php")"
fi

# Issue #536 : perimetres. Risques, mesures de la SoA et versions approuvees appartiennent a une
# entite : un profil limite a A ne voit ni ne modifie jamais ceux de B (fiche, liste, export CSV,
# historique des approbations), ne cree rien dans B. Le consolide n'est propose qu'a une entite
# parente (ici le super-admin, a la racine), sans jamais etre ouvert aux profils limites a A.
SEE_RISKS_A=oui; case $ROLE in none|general) SEE_RISKS_A=non ;; esac
SEE_SOA_A=oui; case $ROLE in none|admin) SEE_SOA_A=non ;; esac
expect "risque de l'entite B" $DENY "$(code "$P/risk.form.php?id=$(id risk_B)")"
risks=$(curl -s -c "$JAR" -b "$JAR" "$P/risk.php")
contains "liste des risques : risque de A" "Risque securite" $SEE_RISKS_A "$risks"
contains "liste des risques : risque de B" "Risque securite B" $SEE_B "$risks"
contains "export du registre (CSV) : risque de B" "RISQUE-SECURITE-B" $SEE_B \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/riskimport.php?export=1")"
expect "creer un risque dans l'entite B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "add=1&title=Risque%20cree%20securite&entities_id=$(id b)&_glpi_csrf_token=$(csrf)" "$P/risk.form.php")"
expect "modifier un risque de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id risk_B)&title=Risque%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/risk.form.php")"
if [ "$ROLE" != admin ]; then
  expect "supprimer un risque de B" 403 "$(code -d "purge=1&id=$(id risk_B)&_glpi_csrf_token=$(csrf)" "$P/risk.form.php")"
fi
expect "mesure de la SoA de B" $DENY "$(code "$P/control.form.php?id=$(id control_B)")"
expect "modifier une mesure de la SoA de B" "$([ "$ROLE" = admin ] && echo 302 || echo 403)" \
  "$(code -d "update=1&id=$(id control_B)&applicability=yes&justification=Justification%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/control.form.php")"
expect "creer une SoA pour une entite qui en a deja une" 403 \
  "$(code -d "create_entity_soa=1&_glpi_csrf_token=$(csrf)" "$P/control.form.php")"
soa_csv=$(curl -s -c "$JAR" -b "$JAR" "$P/control.csv.php?action=export")
contains "export de la SoA (CSV) : SoA de A" "Justification securite A" $SEE_SOA_A "$soa_csv"
contains "export de la SoA (CSV) : SoA de B" "Justification securite B" non "$soa_csv"
contains "approbations de la SoA : version de B" "Version securite B" non \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/soaapproval.php")"
contains "tableau de bord consolide par perimetre" "grcmanager-perimeters" $IS_ADMIN \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/dashboard.php?view=perimeters")"

# Issue #536, lot 2 : actions de traitement, audits, non-conformites et demandes de preuves
# appartiennent a une entite. Un profil limite a A ne voit, ne modifie ni ne cree jamais ceux de B
# (fiche, liste, export, Mon espace GRC, controles de coherence), meme confies a lui ; un acces
# auditeur a un audit de B ne lui ouvre rien. Le super-admin, a la racine, voit A et B.
expect "audit de l'entite B" $DENY "$(code "$P/audit.form.php?id=$(id audit_B)")"
expect "non-conformite de l'entite B" $DENY "$(code "$P/nonconformity.form.php?id=$(id nc_B)")"
contains "liste des audits : audit de B" "Audit securite B" $SEE_B "$(curl -s -c "$JAR" -b "$JAR" "$P/audit.php")"
ncs=$(curl -s -c "$JAR" -b "$JAR" "$P/nonconformity.php")
contains "liste des non-conformites : constat de A" "Constat securite A" \
  "$(case $ROLE in none) echo non ;; *) echo oui ;; esac)" "$ncs"
contains "liste des non-conformites : constat de B" "Constat securite B" $SEE_B "$ncs"
contains "export CSV de la recherche des non-conformites : constat de B" "Constat securite B" $SEE_B   "$(curl -s -c "$JAR" -b "$JAR" "$BASE/front/report.dynamic.php?item_type=GlpiPlugin%5CGrcmanager%5CNonconformity&display_type=3&export_all=1&criteria%5B0%5D%5Bfield%5D=view&criteria%5B0%5D%5Bsearchtype%5D=contains&criteria%5B0%5D%5Bvalue%5D=securite")"
contains "controles de coherence (CSV) : constat de B en retard" "Constat securite B" $SEE_B \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/consistencycheck.export.php?format=csv")"
# Issue #867 : mesures bloquees. Les constats de A et de B citent la mesure testee : celui de B n'est
# jamais cite pour un profil limite a A (vue globale et onglet de la mesure de A).
blocked=$(curl -s -c "$JAR" -b "$JAR" "$P/controlblocker.php")
contains "mesures bloquees : constat de A" "Constat securite A" \
  "$(case $ROLE in none) echo non ;; *) echo oui ;; esac)" "$blocked"
contains "mesures bloquees : constat de B" "Constat securite B" $SEE_B "$blocked"
blocked_tab=$(tab 'GlpiPlugin%5CGrcmanager%5CControl' 'GlpiPlugin%5CGrcmanager%5CControlBlocker$1' "$(id control)")
contains "onglet ce qui bloque la mesure de A : constat de A" "Constat securite A" \
  "$(case $ROLE in none) echo non ;; *) echo oui ;; esac)" "$blocked_tab"
contains "onglet ce qui bloque la mesure de A : constat de B" "Constat securite B" $SEE_B "$blocked_tab"
myspace_b=$(curl -s -c "$JAR" -b "$JAR" "$P/myspace.php")
contains "Mon espace GRC : constat de A confie au gestionnaire" "Constat securite A" \
  "$([ "$ROLE" = limited ] && echo oui || echo non)" "$myspace_b"
contains "Mon espace GRC : constat de B, meme confie" "Constat securite B" non "$myspace_b"
contains "Mon espace GRC : action de B, meme confiee" "Action securite B" non "$myspace_b"
contains "front/auditevidencerequest.mine.php : demande de B, meme confiee" "REQ securite B" non \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/auditevidencerequest.mine.php")"
expect "front/auditorspace.php : audit de B, meme avec un acces" 403 "$(code "$P/auditorspace.php?id=$(id audit_B)")"
contains "front/auditorspace.php : liste sans l'audit de B" "Audit securite B" non \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/auditorspace.php")"
NOT_ADMIN_403="$([ "$ROLE" = admin ] && echo 302 || echo 403)"
expect "creer un audit dans l'entite B" "$NOT_ADMIN_403" \
  "$(code -d "add=1&title=Audit%20cree%20securite&entities_id=$(id b)&_glpi_csrf_token=$(csrf)" "$P/audit.form.php")"
expect "modifier un audit de B" "$NOT_ADMIN_403" \
  "$(code -d "update=1&id=$(id audit_B)&title=Audit%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/audit.form.php")"
expect "modifier une non-conformite de B" "$NOT_ADMIN_403" \
  "$(code -d "update=1&id=$(id nc_B)&title=Constat%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/nonconformity.form.php")"
expect "ajouter une action de traitement a un risque de B" "$NOT_ADMIN_403" \
  "$(code -d "add=1&plugin_grcmanager_risks_id=$(id risk_B)&description=Action%20creee%20securite&_glpi_csrf_token=$(csrf)" "$P/risktreatmentaction.form.php")"
expect "modifier une action de traitement de B" "$NOT_ADMIN_403" \
  "$(code -d "update=1&id=$(id action_B)&plugin_grcmanager_risks_id=$(id risk_B)&description=Action%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/risktreatmentaction.form.php")"
if [ "$ROLE" != admin ]; then
  expect "supprimer un audit de B" 403 "$(code -d "purge=1&id=$(id audit_B)&_glpi_csrf_token=$(csrf)" "$P/audit.form.php")"
  expect "supprimer une action de traitement de B" 403 \
    "$(code -d "purge=1&id=$(id action_B)&plugin_grcmanager_risks_id=$(id risk_B)&_glpi_csrf_token=$(csrf)" "$P/risktreatmentaction.form.php")"
  expect "donner un acces auditeur a un audit de B" 403 \
    "$(code -d "grant_access=1&plugin_grcmanager_audits_id=$(id audit_B)&users_id=2&date_end=$(date +%Y-%m-%d)&_glpi_csrf_token=$(csrf)" "$P/auditevidencerequest.form.php")"
  expect "ajouter un membre a l'equipe d'un audit de B" 403 \
    "$(code -d "add=1&plugin_grcmanager_audits_id=$(id audit_B)&users_id=2&role=auditee&_glpi_csrf_token=$(csrf)" "$P/auditteam.form.php")"
  # Issue #773 : rien sur le programme d'un audit de B (processus, justification, remise du rapport).
  expect "processus audites d'un audit de B" 403 \
    "$(code -d "save_processes=1&plugin_grcmanager_audits_id=$(id audit_B)&processes[]=1&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.form.php")"
  expect "justifier un conflit d'un audit de B" 403 \
    "$(code -d "justify=1&plugin_grcmanager_audits_id=$(id audit_B)&users_id=2&process_id=1&justification=Justification%20securite&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.form.php")"
  expect "remise du rapport d'un audit de B" 403 \
    "$(code -d "save_delivery=1&plugin_grcmanager_audits_id=$(id audit_B)&delivery_date=2026-10-20&users_id_recipient=2&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.form.php")"
  expect "planifier un audit sur un processus de B" 403 \
    "$(code -d "plan_audit=1&process_id=$(id responsibility_B)&year=$(( $(date +%Y) + 1 ))&_glpi_csrf_token=$(csrf)" "$P/auditprogramme.php")"
fi
# Issue #772, lot 2 : plan d'audit de l'organisme. Cocher demande de modifier l'audit de
# certification concerne (droit et entite) ; un audit interne est refuse ; l'audit de B n'apparait
# qu'au super-admin ; jeton CSRF exige.
case $ROLE in none|reader) SAMPLE_A=403 ;; *) SAMPLE_A=302 ;; esac
expect "front/certificationsampling.php : cocher un audit de certification de A" $SAMPLE_A \
  "$(code -d "save_sampling=1&audit_ids[]=$(id audit_cert)&sampled[$(id audit_cert)][]=1&_glpi_csrf_token=$(csrf)" "$P/certificationsampling.php")"
expect "front/certificationsampling.php : onglet d'un audit de certification de A" $SAMPLE_A \
  "$(code -d "save_audit_sampling=1&plugin_grcmanager_audits_id=$(id audit_cert)&processes[]=1&_glpi_csrf_token=$(csrf)" "$P/certificationsampling.php")"
expect "front/certificationsampling.php : audit de certification de B" "$NOT_ADMIN_403" \
  "$(code -d "save_sampling=1&audit_ids[]=$(id audit_cert_B)&sampled[$(id audit_cert_B)][]=1&_glpi_csrf_token=$(csrf)" "$P/certificationsampling.php")"
expect "front/certificationsampling.php : onglet d'un audit de certification de B" "$NOT_ADMIN_403" \
  "$(code -d "save_audit_sampling=1&plugin_grcmanager_audits_id=$(id audit_cert_B)&processes[]=1&_glpi_csrf_token=$(csrf)" "$P/certificationsampling.php")"
expect "front/certificationsampling.php : audit interne refuse" 403 \
  "$(code -d "save_audit_sampling=1&plugin_grcmanager_audits_id=$(id audit)&processes[]=1&_glpi_csrf_token=$(csrf)" "$P/certificationsampling.php")"
expect "front/certificationsampling.php : sans jeton CSRF" 403 \
  "$(code -d "save_sampling=1&audit_ids[]=$(id audit_cert)" "$P/certificationsampling.php")"
if [ "$ROLE" != none ]; then
  contains "front/certificationsampling.php : audit de certification de B" "Audit certification securite B" $IS_ADMIN \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/certificationsampling.php?start=$(( $(date +%Y) - 1 ))")"
fi
# Issue #772, lot 3 : plan d'actions pour l'organisme (PDF). Lecture de l'audit de certification
# (droit et entite) ; audit de B refuse hors super-admin ; audit interne : aucun plan (404).
case $ROLE in none) RESPONSE_A=403; RESPONSE_INTERNAL=403 ;; *) RESPONSE_A=200; RESPONSE_INTERNAL=404 ;; esac
expect "front/certificationresponse.php : plan d'actions d'un audit de certification de A" $RESPONSE_A \
  "$(code "$P/certificationresponse.php?audit=$(id audit_cert)")"
expect "front/certificationresponse.php : plan d'actions d'un audit de certification de B" "$DENY" \
  "$(code "$P/certificationresponse.php?audit=$(id audit_cert_B)")"
expect "front/certificationresponse.php : audit interne" $RESPONSE_INTERNAL \
  "$(code "$P/certificationresponse.php?audit=$(id audit)")"
if [ "$ROLE" != none ]; then
  contains "onglet reponse a l'organisme d'un audit de certification de B" "grc-certification-response-tab" $IS_ADMIN \
    "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CCertificationResponse$1' "$(id audit_cert_B)")"
fi
contains "tableau de bord consolide : non-conformites ouvertes par entite" "grcmanager-perimeters-findings" \
  $IS_ADMIN "$(curl -s -c "$JAR" -b "$JAR" "$P/dashboard.php?view=perimeters")"

# Issue #732 : bilan apres exercice. Lire et modifier le bilan suivent l'incident d'exercice (lecture,
# modification) ; le valider exige le droit dedie (continuite d'activite), que seul le super-admin a.
case $ROLE in
  none|general) ROOM_A=403; ROOM_B=403; EDIT_A=403; EDIT_B=403; VALIDATE_A=403 ;;
  reader)       ROOM_A=200; ROOM_B=404; EDIT_A=403; EDIT_B=404; VALIDATE_A=403 ;;
  limited)      ROOM_A=200; ROOM_B=404; EDIT_A=302; EDIT_B=404; VALIDATE_A=403 ;;
  *)            ROOM_A=200; ROOM_B=200; EDIT_A=302; EDIT_B=302; VALIDATE_A=302 ;;
esac
expect "salle de crise d'un exercice de A" $ROOM_A "$(code "$P/crisisexercise.form.php?id=$(id exercise_A)")"
expect "salle de crise d'un exercice de B" $ROOM_B "$(code "$P/crisisexercise.form.php?id=$(id exercise_B)")"
contains "bilan d'un exercice de A : objectif" "Objectif securite A" "$([ "$ROOM_A" = 200 ] && echo oui || echo non)"   "$(curl -s -c "$JAR" -b "$JAR" "$P/crisisexercise.form.php?id=$(id exercise_A)")"
expect "constat sur un exercice de A" $EDIT_A   "$(code -d "add_finding=1&id=$(id exercise_A)&name=Constat%20securite&severity=minor&_glpi_csrf_token=$(csrf)" "$P/crisisexercise.form.php")"
expect "constat sur un exercice de B" $EDIT_B   "$(code -d "add_finding=1&id=$(id exercise_B)&name=Constat%20securite&severity=minor&_glpi_csrf_token=$(csrf)" "$P/crisisexercise.form.php")"
expect "constat sans jeton CSRF" 403   "$(code -d "add_finding=1&id=$(id exercise_A)&name=Constat%20securite&severity=minor" "$P/crisisexercise.form.php")"
expect "valider le bilan d'un exercice de A (droit dedie)" $VALIDATE_A   "$(code -d "validate_review=1&id=$(id exercise_A)&_glpi_csrf_token=$(csrf)" "$P/crisisexercise.form.php")"
if [ "$ROLE" != admin ]; then
  expect "repasser en brouillon le bilan d'un exercice de A" 403     "$(code -d "reopen_review=1&id=$(id exercise_A)&reason=securite&_glpi_csrf_token=$(csrf)" "$P/crisisexercise.form.php")"
fi

phishing_matrix
# Issue #777 : developpement securise. Page sous le droit du plugin ; fiche d'une application et
# liste de controle d'un changement sous le droit du plugin ET l'element visible (entite) ; decision
# de mise en production sous le droit dedie, que seul le super-admin a. Le profil general ne lit ni
# les applications ni les changements : rien ne lui est montre ni enregistre. Les onglets verifient
# eux-memes leurs droits (contenu demande directement a ajax/common.tabs.php).
# Pages : front/securedevelopment.php, front/applicationsecurity.form.php, front/releasevalidation.form.php.
case $ROLE in
  none)    SD_PAGE=403; SD_APP_A=403; SD_APP_B=403; SD_CHK_A=403; SD_DECIDE=403; SD_TAB=non ;;
  reader)  SD_PAGE=200; SD_APP_A=403; SD_APP_B=404; SD_CHK_A=403; SD_DECIDE=403; SD_TAB=oui ;;
  general) SD_PAGE=200; SD_APP_A=404; SD_APP_B=404; SD_CHK_A=404; SD_DECIDE=404; SD_TAB=non ;;
  limited) SD_PAGE=200; SD_APP_A=302; SD_APP_B=404; SD_CHK_A=302; SD_DECIDE=403; SD_TAB=oui ;;
  *)       SD_PAGE=200; SD_APP_A=302; SD_APP_B=302; SD_CHK_A=302; SD_DECIDE=302; SD_TAB=oui ;;
esac
expect "front/securedevelopment.php" $SD_PAGE "$(code "$P/securedevelopment.php")"
if [ "$SD_PAGE" = 200 ]; then
  sd_page=$(curl -s -c "$JAR" -b "$JAR" "$P/securedevelopment.php")
  SEE_SD_A=oui
  [ "$ROLE" = general ] && SEE_SD_A=non
  contains "developpement securise : application de A" "Application securite A" $SEE_SD_A "$sd_page"
  contains "developpement securise : application de B" "Application securite B" $SEE_B "$sd_page"
  contains "developpement securise : mise en production de A" "Changement securite A" $SEE_SD_A "$sd_page"
  contains "developpement securise : mise en production de B" "Changement securite B" $SEE_B "$sd_page"
fi
contains "onglet Developpement securise d'une application de A" "grc-application-security" $SD_TAB \
  "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CApplicationSecurity$1' "$(id appliance_A)")"
contains "onglet Developpement securise d'une application de B" "grc-application-security" $SEE_B \
  "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CApplicationSecurity$1' "$(id appliance_B)")"
contains "onglet Validation de mise en production d'un changement de A" "grc-release-validation" $SD_TAB \
  "$(tab Change 'GlpiPlugin%5CGrcmanager%5CReleaseValidation$1' "$(id change_A)")"
contains "onglet Validation de mise en production d'un changement de B" "grc-release-validation" $SEE_B \
  "$(tab Change 'GlpiPlugin%5CGrcmanager%5CReleaseValidation$1' "$(id change_B)")"
expect "fiche developpement securise d'une application de A" $SD_APP_A \
  "$(code -d "save=1&appliances_id=$(id appliance_A)&framework=owasp_top10&environments_separated=yes&_glpi_csrf_token=$(csrf)" "$P/applicationsecurity.form.php")"
expect "fiche developpement securise d'une application de B" $SD_APP_B \
  "$(code -d "save=1&appliances_id=$(id appliance_B)&framework=owasp_top10&environments_separated=yes&_glpi_csrf_token=$(csrf)" "$P/applicationsecurity.form.php")"
expect "fiche developpement securise sans jeton CSRF" 403 \
  "$(code -d "save=1&appliances_id=$(id appliance_A)&framework=owasp_top10" "$P/applicationsecurity.form.php")"
expect "liste de controle d'un changement de A" $SD_CHK_A \
  "$(code -d "save_checklist=1&changes_id=$(id change_A)&tests_passed=1&review_done=1&vulnerabilities_treated=1&_glpi_csrf_token=$(csrf)" "$P/releasevalidation.form.php")"
expect "liste de controle d'un changement de B" "$([ "$ROLE" = admin ] && echo 302 || { [ "$ROLE" = none ] && echo 403 || echo 404; })" \
  "$(code -d "save_checklist=1&changes_id=$(id change_B)&tests_passed=1&_glpi_csrf_token=$(csrf)" "$P/releasevalidation.form.php")"
expect "decision de mise en production d'un changement de A (droit dedie)" $SD_DECIDE \
  "$(code -d "decision=approved&validator_role=ciso&changes_id=$(id change_A)&_glpi_csrf_token=$(csrf)" "$P/releasevalidation.form.php")"
if [ "$ROLE" != admin ]; then
  expect "rouvrir la decision d'un changement de A" "$([ "$SD_DECIDE" = 404 ] && echo 404 || echo 403)" \
    "$(code -d "reopen=1&changes_id=$(id change_A)&_glpi_csrf_token=$(csrf)" "$P/releasevalidation.form.php")"
fi

# Issue #536, lot 3 : risques fournisseurs, formations, revues de direction, objectifs,
# obligations, derogations, flux reseau et etudes EBIOS appartiennent a une entite. Un profil
# limite a A n'ouvre ni ne liste jamais ceux de B ; le super-admin, a la racine, les voit.
for spec in "supplierrisk:supplierrisk_B:Risque fournisseur securite B" "training:training_B:Formation securite B" \
  "managementreview:mreview_B:Revue securite B" "objective:objective_B:Objectif registre securite B" \
  "complianceobligation:obligation_B:Obligation securite B" "waiver:waiver_B:Derogation securite B" \
  "networkflow:flow_B:Flux securite B" "ebiosstudy:ebios_B:Etude securite B"; do
  page=${spec%%:*}; rest=${spec#*:}; key=${rest%%:*}; label=${rest#*:}
  expect "$page de l'entite B" $DENY "$(code "$P/$page.form.php?id=$(id "$key")")"
  contains "liste $page : element de B" "$label" $SEE_B "$(curl -s -c "$JAR" -b "$JAR" "$P/$page.php")"
done
if [ "$ROLE" != admin ]; then
  expect "modifier un risque fournisseur de B" 403 \
    "$(code -d "update=1&id=$(id supplierrisk_B)&title=Risque%20fournisseur%20securite%20B&_glpi_csrf_token=$(csrf)" "$P/supplierrisk.form.php")"
  expect "creer une formation dans l'entite B" 403 \
    "$(code -d "add=1&title=Formation%20creee%20securite&entities_id=$(id b)&_glpi_csrf_token=$(csrf)" "$P/training.form.php")"
fi

# Fragments : une fonctionnalite ajoute ses controles dans un NOUVEAU fichier
# tests/security/access-matrix.d/<issue>-<sujet>.sh (voir docs/ARCHITECTURE.md, « Ajouter une fonctionnalite
# sans conflit ») au lieu d'allonger ce script. Charges ici dans l'ordre des noms, avec les memes
# fonctions et variables (ROLE, P, id, code, body, csrf, expect, contains, tab, readable, SEE_B, DENY).
for fragment in "$(dirname "${BASH_SOURCE[0]}")"/access-matrix.d/*.sh; do
  [ -e "$fragment" ] || continue
  # shellcheck source=/dev/null
  . "$fragment"
done

rm -f "$JAR"
exit $FAIL
