# shellcheck shell=bash
# Issue #843, lot 2 : comptes des controleurs figes dans les campagnes de revue des acces ; compte de la
# campagne de A seulement, aucune decision sans droit de mise a jour sur la campagne.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
arbmc_tab() {
  tab 'GlpiPlugin%5CGrcmanager%5CAccessReview' 'GlpiPlugin%5CGrcmanager%5CAccessReviewBmcAccount$1' "$(id "campaign_$1")"
}
if [ "$ROLE" = none ]; then
  contains "onglet comptes des controleurs sans droit : rien" "Revue BMC securite A" non "$(arbmc_tab A)"
  expect "front/accessreviewbmcaccount.php sans droit" "$DENY" \
    "$(code -d "decide=keep&line_id=$(id bmc_review_A)&_glpi_csrf_token=$(csrf)" "$P/accessreviewbmcaccount.php")"
fi
if [ "$ROLE" = reader ]; then
  contains "onglet comptes des controleurs : compte de A" "Revue BMC securite A" oui "$(arbmc_tab A)"
  expect "front/accessreviewbmcaccount.php : decider en lecture seule" 403 \
    "$(code -d "decide=keep&line_id=$(id bmc_review_A)&_glpi_csrf_token=$(csrf)" "$P/accessreviewbmcaccount.php")"
  expect "front/accessreviewbmcaccount.php : figer en lecture seule" 403 \
    "$(code -d "snapshot=1&campaign_id=$(id campaign_A)&_glpi_csrf_token=$(csrf)" "$P/accessreviewbmcaccount.php")"
fi
if [ "$ROLE" = limited ]; then
  contains "onglet comptes des controleurs : rien de B" "Revue BMC securite B" non "$(arbmc_tab B)"
  expect "front/accessreviewbmcaccount.php : decider sur la campagne de B" 403 \
    "$(code -d "decide=keep&line_id=$(id bmc_review_B)&_glpi_csrf_token=$(csrf)" "$P/accessreviewbmcaccount.php")"
  expect "front/accessreviewbmcaccount.php : sans jeton CSRF" 403 \
    "$(code -d "decide=keep&line_id=$(id bmc_review_A)" "$P/accessreviewbmcaccount.php")"
fi
