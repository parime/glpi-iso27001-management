# shellcheck shell=bash
# Issue #1056 : correspondances proposees (endoflife.date) sur Parc > Securite du parc > Logiciels a
# surveiller. Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
# Accepter ou refuser demande le droit de modifier la configuration du plugin, comme les autres
# correspondances ; jamais sans jeton CSRF.
case $ROLE in
  reader)
    contains "front/softwarewatch.php : pas de bouton d'acceptation en lecture" 'name="accept_endoflife_suggestions"' non \
      "$(body "$P/softwarewatch.php")"
    expect "front/softwarewatch.php : acceptation des propositions en lecture seule" 403 \
      "$(code -d "accept_endoflife_suggestions=1&endoflife_suggestion%5B%5D=0000000000000000&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
    expect "front/softwarewatch.php : refus des propositions en lecture seule" 403 \
      "$(code -d "reject_endoflife_suggestions=1&endoflife_suggestion%5B%5D=0000000000000000&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")" ;;
  admin)
    contains "front/softwarewatch.php : correspondances proposees" 'id="grc-endoflife-suggestions"' oui \
      "$(body "$P/softwarewatch.php")"
    # Aucune proposition cochee : rien n'est enregistre, retour a la page.
    expect "front/softwarewatch.php : acceptation sans proposition cochee" 302 \
      "$(code -d "accept_endoflife_suggestions=1&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
    expect "front/softwarewatch.php : acceptation sans jeton CSRF" 403 \
      "$(code -d "accept_endoflife_suggestions=1&endoflife_suggestion%5B%5D=0000000000000000" "$P/softwarewatch.php")" ;;
esac
