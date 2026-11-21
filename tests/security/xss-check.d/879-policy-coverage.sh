# shellcheck shell=bash
# Fragment charge par xss-check.sh (memes fonctions et variables).
# Issue #879 : section piegee d'un lien politique-mesure (onglets et rapport de couverture).
check "front/policycoverage.php"                "$P/policycoverage.php?filter=all"
check "onglet Mesures couvertes d'une politique" "$(tab GlpiPlugin%5CGrcmanager%5CPolicy 'GlpiPlugin%5CGrcmanager%5CPolicyCoverage$1' "$(xid policy)")"
check "onglet Textes de reference d'une mesure"  "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CPolicyCoverage$1' "$(id control)")"
