# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1083 : plan de continuite piege (contenu, test, version refusee et reponse d'approbation) :
# liste des plans, fiche, onglets Versions et approbation et Tests du plan, onglet du processus,
# page d'accueil de la rubrique.
check "front/continuityplan.php" "$P/continuityplan.php"
check "front/continuityplan.form.php (plan piege)" "$P/continuityplan.form.php?id=$(xid continuityplan)"
check "onglet Versions et approbation (plan piege)" \
  "$(tab GlpiPlugin%5CGrcmanager%5CContinuityPlan 'GlpiPlugin%5CGrcmanager%5CContinuityPlanVersion$1' "$(xid continuityplan)")"
check "onglet Tests du plan (plan piege)" \
  "$(tab GlpiPlugin%5CGrcmanager%5CContinuityPlan 'GlpiPlugin%5CGrcmanager%5CContinuityPlanTesting$1' "$(xid continuityplan)")"
check "onglet Plans de continuite du processus piege" \
  "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CContinuityPlan$1' "$(xid process)")"
