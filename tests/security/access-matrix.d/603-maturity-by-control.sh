# shellcheck shell=bash
# Issue #603 : maturite par mesure. Fragment charge par tests/security/access-matrix.sh (memes
# fonctions et variables). La liste des campagnes, la fiche, les onglets et le rapport suivent le droit
# du plugin et l'entite de la campagne ; enregistrer la grille exige de modifier la campagne ; le
# brouillon d'action d'un ecart de B n'est pre-rempli que pour qui peut modifier la campagne de B.
contains "front/maturitycampaign.php : campagne de A" "Campagne maturite securite A" oui   "$(curl -s -c "$JAR" -b "$JAR" "$P/maturitycampaign.php")"
expect "fiche de la campagne de maturite de B" "$DENY" "$(code "$P/maturitycampaign.form.php?id=$(id maturity_B)")"
expect "fiche de la campagne de maturite de A" 200 "$(code "$P/maturitycampaign.form.php?id=$(id maturity_A)")"
expect "front/maturitycampaign.pdf.php : rapport de B" "$DENY" "$(code "$P/maturitycampaign.pdf.php?id=$(id maturity_B)")"
expect "front/maturitycampaign.pdf.php : rapport de A" 200 "$(code "$P/maturitycampaign.pdf.php?id=$(id maturity_A)")"
contains "onglet niveaux de la campagne de B" "grc-maturity-grid" $SEE_B   "$(tab GlpiPlugin%5CGrcmanager%5CMaturityCampaign 'GlpiPlugin%5CGrcmanager%5CMaturityCampaign$1' "$(id maturity_B)")"
contains "onglet ecarts de la campagne de B" "Ecart maturite securite B" $SEE_B   "$(tab GlpiPlugin%5CGrcmanager%5CMaturityCampaign 'GlpiPlugin%5CGrcmanager%5CMaturityCampaign$2' "$(id maturity_B)")"
contains "brouillon d'action depuis l'ecart de B" "Ecart maturite securite B" $SEE_B   "$(tab 'GlpiPlugin%5CGrcmanager%5CNonconformity' 'GlpiPlugin%5CGrcmanager%5CNonconformity$main' "0&maturityassessment=$(id maturity_line_B)")"
if [ "$ROLE" != admin ]; then
  expect "campagne de B : enregistrer la grille" 403     "$(code -d "save_levels=1&plugin_grcmanager_maturitycampaigns_id=$(id maturity_B)&levels[$(id maturity_line_B)][current_level]=4&_glpi_csrf_token=$(csrf)" "$P/maturitycampaign.form.php")"
fi
EDIT_MATURITY=403; case $ROLE in limited|admin) EDIT_MATURITY=302 ;; general) EDIT_MATURITY= ;; esac
[ -n "$EDIT_MATURITY" ] && expect "campagne de A : enregistrer la grille" $EDIT_MATURITY   "$(code -d "save_levels=1&plugin_grcmanager_maturitycampaigns_id=$(id maturity_A)&levels[$(id maturity_line_A)][current_level]=1&levels[$(id maturity_line_A)][target_level]=3&_glpi_csrf_token=$(csrf)" "$P/maturitycampaign.form.php")"
expect "campagne de A : enregistrer la grille sans jeton CSRF" 403   "$(code -d "save_levels=1&plugin_grcmanager_maturitycampaigns_id=$(id maturity_A)&levels[$(id maturity_line_A)][current_level]=5" "$P/maturitycampaign.form.php")"
