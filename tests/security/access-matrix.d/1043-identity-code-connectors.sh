# shellcheck shell=bash
# Issue #1043 : application Microsoft Graph et connexion GitHub / GitLab dans l'onglet Connecteurs ;
# groupes d'acces distant dans l'onglet Personnes et politiques. Les pages d'exploitation gardent les
# resultats, la lecture sur clic et un lien vers la configuration ; leurs anciens envois de reglage
# restent refuses sans le droit.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
case $ROLE in
  reader)
    page=$(body "$P/config.php?tab=connectors")
    for id in grc-entra-graph-config grc-codesecurity-config; do
      contains "configuration, Connecteurs : bloc $id affiche en lecture" "id=\"$id\"" oui "$page"
    done
    contains "configuration, Connecteurs : aucun enregistrement Graph en lecture" 'name="save_mfa_graph"' non "$page"
    contains "configuration, Connecteurs : aucun enregistrement GitHub/GitLab en lecture" \
      'name="save_codesecurity"' non "$page"
    contains "configuration, Personnes : groupes d'acces distant affiches" 'id="grc-mfa-remote-config"' oui \
      "$(body "$P/config.php?tab=people")"
    expect "front/config.php : application Graph en lecture seule" 403 \
      "$(code -d "save_mfa_graph=1&mfa_graph_tenant=exemple.onmicrosoft.com&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : essai Graph en lecture seule" 403 \
      "$(code -d "test_mfa_graph=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : essai GitHub/GitLab en lecture seule" 403 \
      "$(code -d "test_codesecurity=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : groupes d'acces distant en lecture seule" 403 \
      "$(code -d "save_mfa_remote=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/mfacoverage.php : ancien envoi Graph en lecture seule" 403 \
      "$(code -d "save_mfa_graph=1&mfa_graph_tenant=exemple.onmicrosoft.com&_glpi_csrf_token=$(csrf)" \
        "$P/mfacoverage.php")"
    for p in mfacoverage codesecurity shareaccess; do
      contains "front/$p.php : lien vers la configuration" 'config.php?tab=connectors' oui "$(body "$P/$p.php")"
      contains "front/$p.php : plus de champ de secret" 'type="password"' non "$(body "$P/$p.php")"
    done
    contains "front/mfacoverage.php : lien vers les groupes d'acces distant" 'config.php?tab=people' oui \
      "$(body "$P/mfacoverage.php")" ;;
  limited|admin)
    page=$(body "$P/config.php?tab=connectors")
    contains "configuration, Connecteurs : application Graph modifiable" 'name="save_mfa_graph"' oui "$page"
    contains "configuration, Connecteurs : connexion GitHub/GitLab modifiable" 'name="save_codesecurity"' oui "$page"
    contains "configuration, Connecteurs : aucun jeton reaffiche" 'name="codesecurity_github_token" value=""' oui "$page"
    contains "configuration, Personnes : groupes d'acces distant modifiables" 'name="save_mfa_remote"' oui \
      "$(body "$P/config.php?tab=people")"
    expect "front/config.php : application Graph sans jeton CSRF" 403 \
      "$(code -d "save_mfa_graph=1&mfa_graph_tenant=" "$P/config.php")"
    expect "front/config.php : connexion GitHub/GitLab sans jeton CSRF" 403 \
      "$(code -d "save_codesecurity=1&codesecurity_gitlab_url=" "$P/config.php")" ;;
esac
