# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #846, lot 2 : site SharePoint piege (adresse, compte, droit).
check "fiche d'un site SharePoint"            "$P/shareaccess.php?share=$(id m365_share_A)"
