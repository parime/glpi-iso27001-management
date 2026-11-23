# shellcheck shell=bash
# Issue #1054 : roles du SMSI (RSSI et DPO : groupe GLPI et adresse generique). Reglage dans la
# configuration du plugin (onglet people), reserve au droit de la modifier ; roles affiches sur la
# matrice des responsabilites (Organisation du SMSI). Fragment charge par tests/security/access-matrix.sh.
# Aucun reglage n'est enregistre ici : seuls les envois refuses sont tentes.
case $ROLE in
  none|reader)
    expect "front/config.php : roles du SMSI sans droit de modifier" 403 \
      "$(code -d "update_isms_roles=1&dpo_email=dpo%40example.org&_glpi_csrf_token=$(csrf)" "$P/config.php")" ;;
esac
expect "front/config.php : roles du SMSI sans jeton CSRF" 403 \
  "$(code -d "update_isms_roles=1&dpo_email=dpo%40example.org" "$P/config.php")"
# Ancien envoi du champ texte du DPO : plus traite.
contains "front/config.php : ancien formulaire du DPO retire" 'name="update_dpo_contact"' non \
  "$(body "$P/config.php?tab=people")"
case $ROLE in
  reader|admin)
    page=$(body "$P/config.php?tab=people")
    contains "configuration, onglet people : bloc des roles du SMSI" 'id="grc-isms-roles-config"' oui "$page"
    EDIT=oui; [ "$ROLE" = reader ] && EDIT=non
    contains "configuration : roles du SMSI modifiables" 'name="update_isms_roles"' $EDIT "$page"
    contains "configuration, onglet controls : plus de choix du groupe RSSI dans les audits" \
      'name="update_audit_config"' non "$(body "$P/config.php?tab=controls")"
    matrix=$(curl -s -c "$JAR" -b "$JAR" "$P/responsibilityactivity.php")
    contains "matrice des responsabilites : roles du SMSI" 'id="grc-isms-roles"' oui "$matrix"
    contains "matrice des responsabilites : lien vers le reglage des roles" 'grc-isms-roles-config' $EDIT "$matrix" ;;
esac
