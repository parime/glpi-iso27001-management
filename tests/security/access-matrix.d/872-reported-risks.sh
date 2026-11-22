# shellcheck shell=bash
# Issue #872 : risques signales. Le signalement est ouvert a toute session (le compte sans droit ne voit
# que ses propres signalements, jamais le registre ni le titre du risque retenu) ; la file de tri exige
# la lecture du registre des risques, dans les entites de la session ; trier exige de creer et
# modifier les risques, avec jeton CSRF.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/reportedrisk.php
expect "front/reportedrisk.mine.php : ouverte a toute session" 200 "$(code "$P/reportedrisk.mine.php")"
mine_reports=$(curl -s -c "$JAR" -b "$JAR" "$P/reportedrisk.mine.php")
own_report=non; [ "$ROLE" = none ] && own_report=oui
contains "Mes signalements : son propre signalement" "Signalement securite A" $own_report "$mine_reports"
contains "Mes signalements : son signalement rattache" "Signalement rattache securite" $own_report "$mine_reports"
contains "Mes signalements : signalement d'un autre" "Signalement securite B" $IS_ADMIN "$mine_reports"
contains "Mes signalements : jamais le titre du risque retenu" "Risque securite" non "$mine_reports"
contains "Mes signalements : onglet des preferences" "Signalement securite A" $own_report \
  "$(tab Preference 'GlpiPlugin%5CGrcmanager%5CReportedRisk$1' 2)"
expect "Mes signalements : envoi sans jeton CSRF" 403 \
  "$(code -d "report=1&name=x&description=y" "$P/reportedrisk.mine.php")"
# Titre vide : refuse avec un message, sans rien creer, mais la page accepte l'envoi de toute session.
expect "Mes signalements : envoi incomplet avec jeton" 302 \
  "$(code -d "report=1&name=&description=y&_glpi_csrf_token=$(csrf)" "$P/reportedrisk.mine.php")"

case $ROLE in
  general) expect "front/reportedrisk.php : sans le droit des risques" 403 "$(code "$P/reportedrisk.php")" ;;
  limited) expect "front/reportedrisk.php : gestionnaire des risques" 200 "$(code "$P/reportedrisk.php")" ;;
esac
see_queue=non; case $ROLE in reader|limited|admin) see_queue=oui ;; esac
queue=$(curl -s -c "$JAR" -b "$JAR" "$P/reportedrisk.php?status=all")
contains "file de tri : signalement de A" "Signalement securite A" $see_queue "$queue"
contains "file de tri : signalement de B" "Signalement securite B" $SEE_B "$queue"
contains "onglet Risques signales du risque de A" "Signalement rattache securite" $see_queue \
  "$(tab 'GlpiPlugin%5CGrcmanager%5CRisk' 'GlpiPlugin%5CGrcmanager%5CReportedRisk$1' "$(id risk)")"
expect "file de tri : decision sans jeton CSRF" 403 \
  "$(code -d "decide=rejected&id=$(id reportedrisk_A)&decision_comment=x" "$P/reportedrisk.php")"
case $ROLE in
  none|general|reader)
    expect "file de tri : decision sans le droit de trier" 403 \
      "$(code -d "decide=rejected&id=$(id reportedrisk_A)&decision_comment=x&_glpi_csrf_token=$(csrf)" "$P/reportedrisk.php")" ;;
  limited)
    expect "file de tri : decision sur un signalement de B" 403 \
      "$(code -d "decide=rejected&id=$(id reportedrisk_B)&decision_comment=x&_glpi_csrf_token=$(csrf)" "$P/reportedrisk.php")"
    # Decision deja prise : refusee avec un message, sans rien changer.
    expect "file de tri : signalement deja trie" 302 \
      "$(code -d "decide=rejected&id=$(id reportedrisk_merged)&decision_comment=x&_glpi_csrf_token=$(csrf)" "$P/reportedrisk.php")" ;;
esac
