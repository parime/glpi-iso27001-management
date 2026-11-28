# shellcheck shell=bash
# Issue #1185 : alertes Wazuh transformees en incidents de securite depuis la page de l'import des
# alertes EDR. Refuse sans le droit de creer un incident et sans jeton CSRF ; un ordinateur d'une
# autre entite n'ouvre aucun incident.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
wazuh_post() { # cle de groupe : code HTTP et adresse de renvoi
  curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" -H 'Sec-Fetch-Site: same-origin' \
    -d "wazuh_import=1&wazuh_groups[]=$1&_glpi_csrf_token=$(csrf)" "$P/securityincidentedrimport.php"
}
case $ROLE in
  none|reader|general)
    expect "front/securityincidentedrimport.php : alertes Wazuh sans le droit de creer un incident" 403 \
      "$(code -d "wazuh_import=1&wazuh_groups[]=$(id computer_A):5763&_glpi_csrf_token=$(csrf)" "$P/securityincidentedrimport.php")"
    ;;
  limited)
    out=$(wazuh_post "$(id computer_B):5763")
    expect "front/securityincidentedrimport.php : alertes Wazuh d'un ordinateur de B, renvoi" 302 "${out%% *}"
    contains "front/securityincidentedrimport.php : alertes Wazuh d'un ordinateur de B, aucun incident" \
      "securityincident.form.php" non "$out"
    out=$(wazuh_post "1:5763%27%20OR%201=1")
    contains "front/securityincidentedrimport.php : cle de groupe forgee, aucun incident" \
      "securityincident.form.php" non "$out"
    ;;
  admin)
    expect "front/securityincidentedrimport.php : alertes Wazuh sans jeton CSRF" 403 \
      "$(code -d "wazuh_import=1&wazuh_groups[]=$(id computer_A):5763" "$P/securityincidentedrimport.php")"
    ;;
esac
