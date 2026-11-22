# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #872 : titre, description et reponse pieges d'un signalement de risque.
check "front/reportedrisk.php (file de tri)"     "$P/reportedrisk.php?status=all"
check "front/reportedrisk.mine.php"              "$P/reportedrisk.mine.php"
check "onglet Risques signales du risque"        "$(tab GlpiPlugin%5CGrcmanager%5CRisk 'GlpiPlugin%5CGrcmanager%5CReportedRisk$1' "$(id risk)")"
check "onglet Mes signalements des preferences"  "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CReportedRisk$1' 2)"
