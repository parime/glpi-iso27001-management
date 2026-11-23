# shellcheck shell=bash
# Fragment charge par tests/security/xss-check.sh (memes fonctions et variables).
# Issue #1056 : correspondances proposees (endoflife.date), produit piege du catalogue propose pour
# le logiciel piege, sur les logiciels a surveiller.
check "front/softwarewatch.php (correspondances proposees)" "$P/softwarewatch.php"
