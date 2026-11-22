# shellcheck shell=bash
# Issue #1041 : Assistance > Incidents de securite et Crise et continuite. Page d'accueil de la
# rubrique ouverte au lecteur, refusee sans droit ; actions SentinelOne d'exploitation sorties de la
# configuration et toujours refusees sans le droit de modifier la configuration.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/crisiscontinuity.php
case $ROLE in
  reader)
    expect "front/securityincidentedrimport.php : lecture SentinelOne en lecture seule" 403 \
      "$(code -d "s1_fetch_now=1&_glpi_csrf_token=$(csrf)" "$P/securityincidentedrimport.php")"
    expect "front/config.php : ancienne lecture SentinelOne en lecture seule" 403 \
      "$(code -d "fetch_sentinelone_now=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    ;;
  admin)
    page=$(body "$P/config.php?tab=connectors")
    contains "configuration, Connecteurs : plus de bouton Recuperer maintenant" 'name="fetch_sentinelone_now"' non "$page"
    contains "configuration, Connecteurs : test de la connexion SentinelOne garde" 'id="grc-sentinelone"' oui "$page"
    hub=$(body "$P/crisiscontinuity.php")
    for p in businessprocess crisisscenario crisisexercise recoveryprogramme crisismessagetemplate; do
      contains "front/crisiscontinuity.php : carte vers $p" "front/$p.php" oui "$hub"
    done
    contains "front/securityincident.php : onglet Import des alertes EDR" 'securityincidentedrimport.php' oui \
      "$(body "$P/securityincident.php")"
    expect "front/securityincidentedrimport.php : lecture SentinelOne sans jeton CSRF" 403 \
      "$(code -d "s1_fetch_now=1" "$P/securityincidentedrimport.php")"
    ;;
esac
