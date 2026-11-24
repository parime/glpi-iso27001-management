# shellcheck shell=bash
# Issue #1036 : page de configuration du plugin en onglets (front/config.php?tab=<cle>).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
# Sans droit : chaque onglet refuse. En lecture : chaque onglet affiche, sans bouton d'enregistrement
# ni d'action. Sans le droit des registres sensibles : leurs blocs sans bouton, les autres modifiables.
CONFIG_TABS="general frameworks risks controls people incidents connectors assets"
case $ROLE in
  none)
    for t in $CONFIG_TABS; do
      expect "front/config.php?tab=$t : sans droit" 403 "$(code "$P/config.php?tab=$t")"
    done ;;
  reader)
    for t in $CONFIG_TABS; do
      page=$(body "$P/config.php?tab=$t")
      contains "configuration, onglet $t : affiche en lecture" 'id="grc-config-tabs"' oui "$page"
      contains "configuration, onglet $t : aucun bouton d'enregistrement en lecture" 'name="update_' non "$page"
      contains "configuration, onglet $t : aucun bouton d'action en lecture" 'name="send_vulnerability_digest"' non "$page"
    done ;;
  general)
    page=$(body "$P/config.php?tab=general")
    contains "configuration sans registres sensibles : contenu des e-mails non modifiable" 'name="update_sensitive_config"' non "$page"
    contains "configuration sans registres sensibles : liens de calendrier modifiables" 'name="update_calendar_token"' oui "$page"
    page=$(body "$P/config.php?tab=connectors")
    contains "configuration sans registres sensibles : surveillance des fuites non modifiable" 'name="update_breach_config"' non "$page"
    contains "configuration sans registres sensibles : SentinelOne modifiable" 'name="update_sentinelone_config"' oui "$page" ;;
  limited|admin)
    for t in $CONFIG_TABS; do
      page=$(body "$P/config.php?tab=$t")
      contains "configuration, onglet $t : affiche" 'id="grc-config-tabs"' oui "$page"
      contains "configuration, onglet $t : modifiable" 'name="update_' oui "$page"
      expect "configuration, onglet $t : sept onglets dans la barre" 7 "$(grep -o 'role="tab"' <<< "$page" | wc -l | tr -d ' ')"
    done
    page=$(body "$P/config.php?tab=risks")
    contains "configuration, onglet Risques : matrice des risques" 'id="grc-risk-matrix-config"' oui "$page"
    contains "configuration, onglet Risques : rien de l'onglet General" 'id="grc-plugin-version"' non "$page"
    contains "configuration, onglet inconnu : premier onglet" 'id="grc-plugin-version"' oui \
      "$(body "$P/config.php?tab=inconnu")" ;;
esac
