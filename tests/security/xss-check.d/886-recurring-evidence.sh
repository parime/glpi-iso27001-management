# shellcheck shell=bash
# Fragment charge par xss-check.sh (memes fonctions et variables).
# Issue #886 : preuve recurrente piegee non renouvelee a temps (onglet, coherence, dossier de preuves).
check "onglet validites des preuves d'une mesure" "$(tab GlpiPlugin%5CGrcmanager%5CControl 'GlpiPlugin%5CGrcmanager%5CEvidence$1' "$(id control)")"
check "front/consistencycheck.php (preuve recurrente)" "$P/consistencycheck.php"
