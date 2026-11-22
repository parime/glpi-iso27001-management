# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1038 : pages d'accueil des rubriques du menu GRC (noms des elements « a faire » de Mon espace).
for s in risk riskanalysis control policy audit improvement regulation privacy accessreview people ismsorganisation; do
  check "front/section.php?section=$s" "$P/section.php?section=$s"
done
