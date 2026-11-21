#!/usr/bin/env bash
# Test XSS de bout en bout : apres `setup.php xss`, chaque champ texte libre (objets crees pour
# l'occasion et objets du jeu de donnees) contient <svg/onload=alert('xss-canary')>. Aucune page ni
# onglet ne doit restituer cette charge telle quelle (non echappee).
#
# Usage : xss-check.sh <url GLPI> <json de setup.php> <json de setup.php xss>
set -u
BASE=$1 IDS=$2 XSS=$3
CANARY="<svg/onload=alert('xss-canary')>"
JAR=$(mktemp)
FAIL=0
RENDERED=0
id() { jq -r ".$1" <<< "$IDS"; }
xid() { jq -r ".$1" <<< "$XSS"; }
check() { # libelle, url
  local body status
  body=$(curl -s -w '\n%{http_code}' -c "$JAR" -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' "$2")
  status=${body##*$'\n'}
  body=${body%$'\n'*}
  if [ "$status" != 200 ]; then
    echo "::error::$1 : code HTTP $status (page non verifiee)"; FAIL=1; return
  fi
  if grep -qF "$CANARY" <<< "$body"; then
    echo "::error::$1 : charge XSS restituee sans echappement"; FAIL=1; return
  fi
  grep -q "xss-canary" <<< "$body" && RENDERED=$((RENDERED + 1))
  echo "OK  $1"
}
tab() { # itemtype, onglet (classe$numero), id
  echo "$BASE/ajax/common.tabs.php?_target=%2Ffront%2Fx.php&_itemtype=$1&_glpi_tab=$(sed 's/\$/%24/' <<< "$2")&id=$3"
}

for try in 1 2 3 4 5; do
  rm -f "$JAR"
  token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "login_name=glpi" --data-urlencode "login_password=glpi" \
    --data-urlencode "_glpi_csrf_token=$token" -d noAUTO=1 "$BASE/front/login.php"
  [ "$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE/front/central.php")" = 200 ] && break
  [ "$try" = 5 ] && { echo "::error::connexion impossible"; exit 1; }
  sleep 3
done

P=$BASE/plugins/grcmanager/front
for url in \
  "risk.form.php?id=$(id risk)" "control.form.php?id=$(id control)" "supplierrisk.form.php?id=$(id supplierrisk)" \
  "securityincident.form.php?id=$(id incident_A)" "accessreview.form.php?id=$(id campaign_A)" \
  "audit.form.php?id=$(xid audit)" "complianceobligation.form.php?id=$(xid obligation)" \
  "managementreview.form.php?id=$(xid review)" "nonconformity.form.php?id=$(xid nonconformity)" \
  "objective.form.php?id=$(xid objective)" "policy.form.php?id=$(xid policy)" "training.form.php?id=$(xid training)" \
  "canonicalproduct.form.php?id=$(xid product)" "processingactivity.form.php?id=$(xid processing)" processingactivity.php \
  "riskindicator.form.php?id=$(xid kri)" riskindicator.php riskappetite.php \
  risk.php control.php supplierrisk.php securityincident.php accessreview.php audit.php complianceobligation.php \
  managementreview.php nonconformity.php objective.php policy.php training.php canonicalproduct.php \
  ismsrequirement.php referentiels.php riskheatmap.php soaapproval.php policyapproval.php config.php evidence.php controltest.php \
  myspace.php personnelcompliance.php customframework.php riskimport.php "customframework.form.php?id=$(xid customframework)" \
  frameworkassessment.php "frameworkassessment.php?framework=custom_$(xid customframework)"; do
  check "front/$url" "$P/$url"
done
# Issue #774 : compétences piégées (fonction tenue par glpi, compétence, déclaration).
for url in ismsfunction.php competence.php competencedeclaration.php \
  "ismsfunction.form.php?id=$(id ismsfunction_A)" "competence.form.php?id=$(id competence_A)" \
  "competencedeclaration.form.php?id=$(xid competencedeclaration)"; do
  check "front/$url" "$P/$url"
done
check "onglet Mes competences"                 "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CCompetenceMatrix$1' 2)"
# Issue #778 : evaluation de la securite physique de A piegee (page de comparaison, liste, fiche,
# grille, zones, onglet du lieu, preuve sur A.7.1, action proposee depuis la ligne non conforme).
for url in physicalsecurity.php physicalsecurityassessment.php \
  "physicalsecurityassessment.form.php?id=$(id physical_A)" "control.form.php?id=$(id control_a71)" \
  "nonconformity.form.php?physicalsecuritycheck=$(id physical_line_A)"; do
  check "front/$url" "$P/$url"
done
check "onglet grille de securite physique"     "$(tab GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment 'GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment$1' "$(id physical_A)")"
check "onglet zones du site"                   "$(tab GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment 'GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment$2' "$(id physical_A)")"
check "onglet securite physique d'un lieu"     "$(tab Location 'GlpiPlugin%5CGrcmanager%5CPhysicalSecurityAssessment$1' "$(id location_A)")"
# Issue #546 : fiche de conformité de glpi, dans le public de la politique piégée.
check "onglet Ma conformite securite"          "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CPersonnelCompliance$1' 2)"
check "onglet risques d'un ordinateur"        "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CRisk$1' "$(id computer_A)")"
check "onglet classification d'un ordinateur" "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CAssetClassification$1' "$(id computer_A)")"
check "onglet questionnaire fournisseur"      "$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$1' "$(id supplierrisk)")"
check "onglet contrats fournisseur"           "$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$2' "$(id supplierrisk)")"
# Issue #866 : ligne de revue piégée, suggestion et raison comprises.
check "onglet comptes a revoir d'une campagne" "$(tab GlpiPlugin%5CGrcmanager%5CAccessReview 'GlpiPlugin%5CGrcmanager%5CAccessReview$1' "$(id campaign_A)")"
check "onglet revues d'acces d'un controle"   "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CAccessReview$2' "$(id control)")"
check "onglet incidents d'un ordinateur"     "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CSecurityIncidentLinkedItem$1' "$(id computer_A)")"
check "onglet couts d'un incident"            "$(tab PluginGrcmanagerSecurityIncident 'PluginGrcmanagerSecurityIncidentCost$1' "$(id incident_A)")"
check "onglet mesures d'un objectif"          "$(tab GlpiPlugin%5CGrcmanager%5CObjective 'GlpiPlugin%5CGrcmanager%5CObjectiveMeasurement$1' "$(xid objective)")"
check "onglet references CPE d'un produit"    "$(tab GlpiPlugin%5CGrcmanager%5CCanonicalProduct 'GlpiPlugin%5CGrcmanager%5CCpeReference$1' "$(xid product)")"
check "onglet alias d'un produit"             "$(tab GlpiPlugin%5CGrcmanager%5CCanonicalProduct 'GlpiPlugin%5CGrcmanager%5CProductAlias$1' "$(xid product)")"
check "onglet registre des traitements d'un ordinateur" "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CProcessingActivity$1' "$(id computer_A)")"
check "fiche d'un traitement (onglet principal)" "$(tab GlpiPlugin%5CGrcmanager%5CProcessingActivity 'GlpiPlugin%5CGrcmanager%5CProcessingActivity$main' "$(xid processing)")"
# Issue #775 : procedure d'exploitation piegee (registre, fiche, preuve de la mesure A.5.37).
check "front/operatingprocedure.php"          "$P/operatingprocedure.php"
check "fiche d'une procedure d'exploitation"  "$P/policy.form.php?id=$(xid procedure)"
check "onglet principal d'une procedure"      "$(tab 'GlpiPlugin%5CGrcmanager%5CPolicy' 'GlpiPlugin%5CGrcmanager%5CPolicy$main' "$(xid procedure)")"
check "onglet base de connaissances d'une procedure" "$(tab 'GlpiPlugin%5CGrcmanager%5CPolicy' 'KnowbaseItem_Item$1' "$(xid procedure)")"
# Issue #777 : application, fiche, changement et liste de controle pieges (page, onglets, preuve A.8.31).
check "front/securedevelopment.php"           "$P/securedevelopment.php"
check "onglet developpement securise d'une application" "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CApplicationSecurity$1' "$(xid appliance)")"
check "onglet validation de mise en production" "$(tab Change 'GlpiPlugin%5CGrcmanager%5CReleaseValidation$1' "$(xid change)")"
check "preuve developpement securise (A.8.31)" "$P/control.form.php?id=$(xid control_a831)"
# Issue #779 : logiciel et ordinateur pieges (page des licences logicielles).
check "front/softwarelicensecompliance.php"   "$P/softwarelicensecompliance.php"
# Issue #847 : ordinateur, outil et algorithme de chiffrement pieges (chiffrement des disques).
check "front/diskencryption.php"              "$P/diskencryption.php"
# Issue #852 : compte et methode importes pieges (couverture MFA, preuve A.8.5).
check "front/mfacoverage.php"                 "$P/mfacoverage.php"
check "preuve couverture MFA (A.8.5)"         "$P/control.form.php?id=$(xid control_a85)"
# Issue #854 : ordinateur piege (correctifs de securite).
check "front/patchcompliance.php"             "$P/patchcompliance.php"
# Issue #846 : partage, chemin, compte, droit et commentaire de revue pieges (droits sur les partages).
check "front/shareaccess.php"                 "$P/shareaccess.php"
check "fiche d'un partage de fichiers"        "$P/shareaccess.php?share=$(id share_A)"
# Issue #781 : resultats OpenSCAP pieges (liste, non rapproche, detail, preuve A.8.9).
check "front/configcompliance.php"            "$P/configcompliance.php"
check "front/configcompliance.php?report"     "$P/configcompliance.php?report=$(xid configscan)"
check "front/configcompliance.php?report non rapproche" "$P/configcompliance.php?report=$(xid configscan_unmatched)"
check "preuve conformite de configuration (A.8.9)" "$P/control.form.php?id=$(xid control_a89)"
# Issue #853 : hote emetteur, serveur de temps et commentaire pieges (couverture de la journalisation).
check "front/logcoverage.php"                 "$P/logcoverage.php"
# Issue #782 : ordinateur piege (carte de fin de garantie du materiel, tous les materiels).
check "front/parkvulnerability.php?warranty=all" "$P/parkvulnerability.php?warranty=all"
# Issue #604 : campagne de simulation d'hameconnage piegee (liste et tendance, fiche, onglet Resultats,
# onglet des preferences de glpi, preuve sur A.6.3).
check "front/phishingcampaign.php"            "$P/phishingcampaign.php"
check "fiche d'une simulation d'hameconnage"  "$P/phishingcampaign.form.php?id=$(xid phishing)"
check "onglet Resultats d'une simulation"     "$(tab GlpiPlugin%5CGrcmanager%5CPhishingCampaign 'GlpiPlugin%5CGrcmanager%5CPhishingResult$1' "$(xid phishing)")"
check "onglet Mes simulations d'hameconnage"  "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CPhishingResult$1' 2)"
check "formation de rattrapage pre-remplie"   "$(tab GlpiPlugin%5CGrcmanager%5CTraining 'GlpiPlugin%5CGrcmanager%5CTraining$main' "0&phishing_of=$(xid phishing)")"
check "preuve des simulations sur A.6.3"      "$P/control.form.php?id=$(id control_a63)"
# Issue #771 : contact officiel piege (registre, fiche, onglets de l'incident et de la violation).
check "front/officialcontact.php"             "$P/officialcontact.php"
check "fiche d'un contact officiel"           "$P/contextitem.form.php?id=$(xid official_contact)"
check "onglet contacts officiels d'un incident" "$(tab PluginGrcmanagerSecurityIncident 'GlpiPlugin%5CGrcmanager%5COfficialContact$1' "$(id incident_A)")"
check "onglet contacts officiels d'une violation" "$(tab GlpiPlugin%5CGrcmanager%5CDataBreach 'GlpiPlugin%5CGrcmanager%5COfficialContact$1' "$(xid breach)")"
# Issue #771, lot 2 : prise de contact piegee (onglet de l'incident ci-dessus, fiche du contact).
check "onglet prises de contact d'un contact officiel" "$(tab GlpiPlugin%5CGrcmanager%5CContextItem 'GlpiPlugin%5CGrcmanager%5COfficialContactNotification$1' "$(xid official_contact)")"
# Issue #616 : modele de message de crise piege (liste, fiche, liste de diffusion), et onglet
# Communication de crise de l'incident de A (variables piegees, communication tracee piegee).
check "front/crisismessagetemplate.php"       "$P/crisismessagetemplate.php"
check "fiche d'un modele de message de crise" "$P/crisismessagetemplate.form.php?id=$(xid crisis_template)"
check "onglet liste de diffusion d'un modele" "$(tab 'GlpiPlugin%5CGrcmanager%5CCrisisMessageTemplate' 'GlpiPlugin%5CGrcmanager%5CCrisisMessageRecipient$1' "$(xid crisis_template)")"
check "onglet communication de crise d'un incident" "$(tab PluginGrcmanagerSecurityIncident 'GlpiPlugin%5CGrcmanager%5CCrisisCommunication$1' "$(id incident_A)")"
check "onglet communication de crise d'une violation" "$(tab GlpiPlugin%5CGrcmanager%5CDataBreach 'GlpiPlugin%5CGrcmanager%5CCrisisCommunication$1' "$(xid breach)")"
# Issue #620 : violation piegee (fiche, liste, onglet de l'incident, export CSV).
check "front/databreach.form.php"             "$P/databreach.form.php?id=$(xid breach)"
check "front/databreach.php"                  "$P/databreach.php"
check "onglet violation de donnees d'un incident" "$(tab PluginGrcmanagerSecurityIncident 'GlpiPlugin%5CGrcmanager%5CDataBreach$1' "$(id incident_A)")"
check "front/businessprocess.form.php"         "$P/businessprocess.form.php?id=$(xid process)"
check "front/businessprocess.php"              "$P/businessprocess.php"
# Issue #733 : programme des tests de reprise (nom du processus et type d'exercice pieges).
check "programme des tests de reprise"         "$P/recoveryprogramme.php"
check "onglet BIA d'un processus"              "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessImpact$1' "$(xid process)")"
check "onglet dependances d'un processus"      "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1' "$(xid process)")"
check "onglet processus metier d'un ordinateur" "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CBusinessProcessItem$1' "$(id computer_A)")"
# Issue #776 : scenario de crise piege (registre, fiche, onglet du processus lie).
check "front/crisisscenario.php"               "$P/crisisscenario.php"
check "fiche d'un scenario de crise"           "$(tab GlpiPlugin%5CGrcmanager%5CCrisisScenario 'GlpiPlugin%5CGrcmanager%5CCrisisScenario$main' "$(xid crisis_scenario)")"
check "onglet scenarios de crise d'un processus" "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CCrisisScenario$1' "$(xid process)")"
# Issue #776, lot 2 : onglet Preparation (processus, modele de message et contact officiel pieges).
check "onglet preparation d'un scenario de crise" "$(tab GlpiPlugin%5CGrcmanager%5CCrisisScenario 'GlpiPlugin%5CGrcmanager%5CCrisisScenarioReadiness$1' "$(xid crisis_scenario)")"
# Issue #377, deuxieme etape : processus lies, risques d'un processus, processus d'un risque.
check "onglet processus lies d'un processus"   "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$1' "$(xid process)")"
check "onglet risques d'un processus"          "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(xid process)")"
check "onglet processus metier d'un risque"    "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRisk$1' "$(id risk)")"
# Issue #377, troisieme etape : strategie de reprise et tests mesures ; classification d'une application.
check "onglet strategie de reprise d'un processus" "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessRecovery$1' "$(xid process)")"
check "onglet classification d'une application" "$(tab Appliance 'GlpiPlugin%5CGrcmanager%5CAssetClassification$1' "$(id appliance_A)")"
# Issue #377, quatrieme etape : carte des dependances (noms piege du processus, du processus amont et
# de l'ordinateur, passes au graphe par un attribut echappe).
check "onglet carte des dependances d'un processus" "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CBusinessProcessLink$2' "$(xid process)")"
check "onglet validites des preuves"          "$(tab 'GlpiPlugin%5CGrcmanager%5CPolicy' 'GlpiPlugin%5CGrcmanager%5CEvidence$1' "$(id policy_A)")"
# Issue #535 : politique piegee a lire (preferences) et onglet de prise de connaissance (groupe piege).
check "onglet Politiques a lire"              "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CPolicyAcknowledgement$1' 2)"
check "onglet prise de connaissance"          "$(tab 'GlpiPlugin%5CGrcmanager%5CPolicy' 'GlpiPlugin%5CGrcmanager%5CPolicyAcknowledgement$1' "$(xid policy)")"
# Issue #868 : dossier de preuves (nom piege d'une preuve expiree de la mesure de A).
check "front/evidencepack.php"                "$P/evidencepack.php?entities_id=$(id a)"
# Issue #614 : controles de coherence (noms pieges des risques, politiques, traitements...).
check "front/consistencycheck.php"            "$P/consistencycheck.php"
# Issue #867 : mesures bloquees et onglet de la mesure (constats, politiques et justification pieges).
check "front/controlblocker.php"             "$P/controlblocker.php"
check "onglet ce qui bloque la mesure"       "$(tab 'GlpiPlugin%5CGrcmanager%5CControl' 'GlpiPlugin%5CGrcmanager%5CControlBlocker$1' "$(id control)")"
# Issue #619 : preparation a la certification (titres des fiches pieges jamais repris tels quels).
check "front/certificationreadiness.php"     "$P/certificationreadiness.php"
# Issue #772 : audit de certification et constat pieges (vue des constats et onglet de l'audit).
check "front/certificationfinding.php"       "$P/certificationfinding.php"
check "onglet constats d'un audit de certification" "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CNonconformity$1' "$(xid audit)")"
# Issue #772, lot 2 : plan d'audit de l'organisme (processus et constat pieges, onglet et page).
check "onglet plan d'audit de l'organisme"   "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CCertificationSampling$1' "$(xid audit)")"
check "front/certificationsampling.php"      "$P/certificationsampling.php"
check "constats d'un processus"              "$P/certificationfinding.php?process=1"
# Issue #772, lot 3 : reponse a l'organisme (correction et retour de l'organisme pieges).
check "onglet reponse a l'organisme"         "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CCertificationResponse$1' "$(xid audit)")"
check "export CSV de prise de connaissance"   "$P/policyacknowledgement.export.php?id=$(xid ack_campaign)&format=csv"
# Issue #540 : registre DORA piege (page, onglets du contrat, du fournisseur et du processus, export).
check "registre DORA"                         "$P/doraregister.php"
check "onglet registre DORA d'un contrat"     "$(tab Contract 'GlpiPlugin%5CGrcmanager%5CDoraRegister$2' "$(id contract_A)")"
check "onglet registre DORA d'un fournisseur" "$(tab Supplier 'GlpiPlugin%5CGrcmanager%5CDoraRegister$1' "$(id supplier)")"
check "onglet fonction DORA d'un processus"   "$(tab 'GlpiPlugin%5CGrcmanager%5CBusinessProcess' 'GlpiPlugin%5CGrcmanager%5CDoraRegister$3' "$(xid process)")"
# Issue #538 : demande de preuve piegee (onglet de l'audit, page de la personne designee, espace
# auditeur et sa liste).
# Issue #606 : ligne de grille et modele pieges (onglet de l'audit et page de couverture).
check "onglet grille d'un audit"              "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CAuditChecklist$1' "$(xid audit)")"
check "couverture du programme d'audit"      "$P/auditcoverage.php"
# Issue #773 : processus, justification d'independance et remise du rapport pieges.
check "onglet programme et independance"      "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CAuditProgramme$1' "$(xid audit)")"
check "onglet equipe d'un audit"              "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CAuditTeam$1' "$(xid audit)")"
check "programme d'audit pluriannuel"         "$P/auditprogramme.php"
# Issue #773, lot 2 : objectifs et criteres pieges (fiche, onglet et points de controle).
check "onglet programme d'un audit interne"   "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CAuditProgramme$1' "$(xid audit_internal)")"
check "fiche d'un audit interne"              "$P/audit.form.php?id=$(xid audit_internal)"
check "onglet demandes de preuves d'un audit" "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CAuditEvidenceRequest$1' "$(xid audit)")"
check "mes demandes de preuves"               "$P/auditevidencerequest.mine.php"
check "espace auditeur d'un audit"            "$P/auditorspace.php?id=$(xid audit)"
check "liste de l'espace auditeur"            "$P/auditorspace.php"
# Issue #732 : bilan d'un exercice (objectif, constat, historique de validation).
check "salle de crise et bilan d'un exercice" "$P/crisisexercise.form.php?id=$(xid exercise)"
# Issue #380 : constat d'un test d'efficacite (onglet de la mesure).
check "onglet tests d'efficacite d'un controle" "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CControlTest$1' "$(id control)")"
# Issue #607 : echantillon piege (question, nom fige et commentaire d'un element).
check "page d'un echantillon de test"         "$P/controltestsample.form.php?id=$(xid sample)"

# Issue #909 : nouveau flux pre-rempli depuis l'adresse (bouton « Proposer le flux » de l'inventaire
# Docker) avec des parametres pieges : page du formulaire et onglet principal qui recoit les valeurs.
QC="%3Csvg%2Fonload%3Dalert(%27xss-canary%27)%3E"
check "nouveau flux pre-rempli (adresse piegee)"   "$P/networkflow.form.php?flow_type=internal&name=$QC&source_label=$QC&destination_label=$QC&protocol=tcp&ports=443"
check "onglet d'un nouveau flux pre-rempli"   "$(tab 'GlpiPlugin%5CGrcmanager%5CNetworkFlow' 'GlpiPlugin%5CGrcmanager%5CNetworkFlow$main' 0)&prefill%5Bname%5D=$QC&prefill%5Bsource_label%5D=$QC&prefill%5Bdestination_label%5D=$QC"

rm -f "$JAR"
if [ "$RENDERED" -lt 10 ]; then
  echo "::error::la charge n'apparait (echappee) que sur $RENDERED page(s) : les donnees piegees ne sont pas affichees, le test ne prouve rien"
  FAIL=1
else
  echo "OK  charge affichee echappee sur $RENDERED pages"
fi
exit $FAIL
