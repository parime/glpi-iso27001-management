# shellcheck shell=bash
# Issue #1055 : choix du fournisseur de la surveillance des fuites (XposedOrNot, gratuit, ou Have I
# Been Pwned) et cle de domaine XposedOrNot, dans l'onglet Connecteurs de la configuration : reglage
# reserve au droit de configurer le plugin et au droit des registres sensibles ; la cle n'est jamais
# renvoyee au navigateur. Fragment charge par tests/security/access-matrix.sh.
case $ROLE in
  none|general)
    expect "front/config.php : choisir XposedOrNot et sa cle de domaine" 403 \
      "$(code -d "update_breach_config=1&breach_provider=xposedornot&xon_api_key=x&hibp_enabled=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : essayer la connexion au fournisseur" 403 \
      "$(code -d "test_breach_config=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    ;;
  admin)
    connectors=$(body "$P/config.php?tab=connectors")
    contains "onglet Connecteurs : choix du fournisseur XposedOrNot" "grc-breach-provider-xon" oui "$connectors"
    contains "onglet Connecteurs : la cle XposedOrNot n'est jamais reaffichee" 'name="xon_api_key" value=""' oui "$connectors"
    expect "front/config.php : enregistrer sans jeton CSRF" 403 \
      "$(code -d "update_breach_config=1&breach_provider=xposedornot" "$P/config.php")"
    ;;
esac
