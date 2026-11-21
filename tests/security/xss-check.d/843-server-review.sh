# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #843, lot 2 : compte de controleur piege dans la campagne de revue des acces de A.
check "onglet comptes des controleurs (revue des acces)" \
  "$(tab GlpiPlugin%5CGrcmanager%5CAccessReview 'GlpiPlugin%5CGrcmanager%5CAccessReviewBmcAccount$1' "$(id campaign_A)")"
