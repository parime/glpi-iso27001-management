# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #603 : campagne de maturite de A piegee (liste, fiche, grille, ecarts, comparaison, action
# proposee depuis l'ecart, libelle d'echelle piege dans la configuration).
check "front/maturitycampaign.php"                "$P/maturitycampaign.php"
check "front/maturitycampaign.form.php"           "$P/maturitycampaign.form.php?id=$(id maturity_A)"
check "front/config.php (echelle de maturite)"    "$P/config.php?tab=frameworks"
check "onglet niveaux par mesure"                 "$(tab GlpiPlugin%5CGrcmanager%5CMaturityCampaign 'GlpiPlugin%5CGrcmanager%5CMaturityCampaign$1' "$(id maturity_A)")"
check "onglet ecarts de maturite"                 "$(tab GlpiPlugin%5CGrcmanager%5CMaturityCampaign 'GlpiPlugin%5CGrcmanager%5CMaturityCampaign$2' "$(id maturity_A)")"
check "onglet comparaison des campagnes"          "$(tab GlpiPlugin%5CGrcmanager%5CMaturityCampaign 'GlpiPlugin%5CGrcmanager%5CMaturityCampaign$3' "$(id maturity_A)")"
check "action proposee depuis un ecart"           "$(tab 'GlpiPlugin%5CGrcmanager%5CNonconformity' 'GlpiPlugin%5CGrcmanager%5CNonconformity$main' "0&maturityassessment=$(id maturity_line_A)")"
