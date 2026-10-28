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
  "canonicalproduct.form.php?id=$(xid product)" \
  risk.php control.php supplierrisk.php securityincident.php accessreview.php audit.php complianceobligation.php \
  managementreview.php nonconformity.php objective.php policy.php training.php canonicalproduct.php \
  ismsrequirement.php referentiels.php riskheatmap.php soaapproval.php config.php evidence.php; do
  check "front/$url" "$P/$url"
done
check "onglet risques d'un ordinateur"        "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CRisk$1' "$(id computer_A)")"
check "onglet classification d'un ordinateur" "$(tab Computer 'GlpiPlugin%5CGrcmanager%5CAssetClassification$1' "$(id computer_A)")"
check "onglet questionnaire fournisseur"      "$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$1' "$(id supplierrisk)")"
check "onglet contrats fournisseur"           "$(tab GlpiPlugin%5CGrcmanager%5CSupplierRisk 'GlpiPlugin%5CGrcmanager%5CSupplierAssessment$2' "$(id supplierrisk)")"
check "onglet revues d'acces d'un controle"   "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CAccessReview$2' "$(id control)")"
check "onglet couts d'un incident"            "$(tab PluginGrcmanagerSecurityIncident 'PluginGrcmanagerSecurityIncidentCost$1' "$(id incident_A)")"
check "onglet mesures d'un objectif"          "$(tab GlpiPlugin%5CGrcmanager%5CObjective 'GlpiPlugin%5CGrcmanager%5CObjectiveMeasurement$1' "$(xid objective)")"
check "onglet references CPE d'un produit"    "$(tab GlpiPlugin%5CGrcmanager%5CCanonicalProduct 'GlpiPlugin%5CGrcmanager%5CCpeReference$1' "$(xid product)")"
check "onglet alias d'un produit"             "$(tab GlpiPlugin%5CGrcmanager%5CCanonicalProduct 'GlpiPlugin%5CGrcmanager%5CProductAlias$1' "$(xid product)")"
check "onglet validites des preuves"          "$(tab 'GlpiPlugin%5CGrcmanager%5CPolicy' 'GlpiPlugin%5CGrcmanager%5CEvidence$1' "$(id policy_A)")"

rm -f "$JAR"
if [ "$RENDERED" -lt 10 ]; then
  echo "::error::la charge n'apparait (echappee) que sur $RENDERED page(s) : les donnees piegees ne sont pas affichees, le test ne prouve rien"
  FAIL=1
else
  echo "OK  charge affichee echappee sur $RENDERED pages"
fi
exit $FAIL
