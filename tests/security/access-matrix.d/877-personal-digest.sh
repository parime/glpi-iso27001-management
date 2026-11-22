# shellcheck shell=bash
# Issue #877 : recapitulatif personnel par e-mail. L'encadre de choix est sur "Mon espace GRC",
# ouvert a toute session ; chacun ne regle que son propre recapitulatif, avec jeton CSRF.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
contains "Mon espace GRC : encadre du recapitulatif par e-mail" 'id="grc-personal-digest"' oui \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/myspace.php")"
expect "Recapitulatif : choix sans jeton CSRF" 403 \
  "$(code -d "save_personal_digest=1&personal_digest_frequency=daily" "$P/myspace.php")"
expect "Recapitulatif : envoi immediat sans jeton CSRF" 403 \
  "$(code -d "send_personal_digest=1" "$P/myspace.php")"
expect "Recapitulatif : choix de sa propre frequence" 302 \
  "$(code -d "save_personal_digest=1&personal_digest_frequency=weekly&_glpi_csrf_token=$(csrf)" "$P/myspace.php")"
expect "Recapitulatif : frequence inconnue refusee avec un message" 302 \
  "$(code -d "save_personal_digest=1&personal_digest_frequency=hourly&_glpi_csrf_token=$(csrf)" "$P/myspace.php")"
