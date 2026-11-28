# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1182 : constat de revue, titre du risque et action du plan pieges (onglet Revues, fiche du
# risque avec l'efficacite du traitement et l'acceptation signee, configuration des profils).
check "onglet Revues du risque" \
  "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CRiskReview$1' "$(xid effectiveness_risk)")"
check "fiche du risque (risque cible, efficacite du traitement)" \
  "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CRisk$main' "$(xid effectiveness_risk)")"
check "fiche du risque (acceptation signee)" \
  "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CRisk$main' "$(xid accepted_risk)")"
check "configuration, onglet risks (profils prets a attribuer)" "$P/config.php?tab=risks"
