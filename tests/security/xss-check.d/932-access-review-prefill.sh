# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #932 : raison de conformite piegee, reprise dans l'attribut data-reasons du report des suggestions.
check "onglet comptes a revoir (report des suggestions)" \
  "$(tab GlpiPlugin%5CGrcmanager%5CAccessReview 'GlpiPlugin%5CGrcmanager%5CAccessReview$1' "$(id campaign_A)")"
check "page Mes revues d'acces (report des suggestions)" "$P/accessreview.mine.php"
