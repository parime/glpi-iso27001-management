# shellcheck shell=bash
# Issue #1039 : Parc > Securite du parc. Fragment charge par tests/security/access-matrix.sh (memes
# fonctions et variables). Le parcours des pages de la rubrique (200, fil d'Ariane, 403 sans droit) est
# fait par 1037-menu-sections.sh ; ici, les actions sorties de la configuration et l'onglet des modeles.
readable front/assetsecurity.php front/hardwaremodeleol.php
eol_tab() { tab ComputerModel 'GlpiPlugin%5CGrcmanager%5CHardwareModelEol$1' "$(id hw_model_A)"; }
case $ROLE in
  none)
    contains "onglet fin de vie constructeur sans droit" 'grc-hardware-model-eol' non "$(eol_tab)" ;;
  reader)
    # Le lecteur ne lit pas les intitules (modeles) de GLPI : l'onglet est verifie avec le super-admin.
    contains "front/hardwaremodeleol.php : pas de bouton en lecture" 'name="update_hardware_eol"' non "$(body "$P/hardwaremodeleol.php")"
    expect "front/hardwaremodeleol.php : saisie en lecture seule" 403 \
      "$(code -d "update_hardware_eol=1&hardware_eol%5BComputerModel%3A$(id hw_model_A)%5D=2030-01-01&_glpi_csrf_token=$(csrf)" "$P/hardwaremodeleol.php")"
    expect "front/softwarewatch.php : lecture endoflife.date en lecture seule" 403 \
      "$(code -d "refresh_endoflife=1&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
    expect "front/softwarewatch.php : correspondance endoflife.date en lecture seule" 403 \
      "$(code -d "add_endoflife_mapping=1&endoflife_kind=os&endoflife_name=Securite&endoflife_product=debian&_glpi_csrf_token=$(csrf)" "$P/softwarewatch.php")"
    expect "front/parkvulnerability.php : envoi du rapport en lecture seule" 403 \
      "$(code -d "send_vulnerability_digest=1&_glpi_csrf_token=$(csrf)" "$P/parkvulnerability.php")" ;;
  admin)
    contains "onglet fin de vie constructeur modifiable" 'name="update_hardware_eol"' oui "$(eol_tab)"
    contains "front/hardwaremodeleol.php : saisie groupee" 'id="grc-hardware-eol"' oui "$(body "$P/hardwaremodeleol.php")"
    home=$(body "$P/assetsecurity.php")
    contains "front/assetsecurity.php : cartes groupees" 'grc-asset-security-card' oui "$home"
    contains "front/assetsecurity.php : carte de la fin de vie des modeles" 'hardwaremodeleol.php' oui "$home"
    # La configuration ne garde que les reglages : plus de saisie des modeles, de lecture manuelle ni de
    # correspondances endoflife.date, plus d'envoi immediat du rapport.
    contains "configuration, onglet Parc : plus de saisie des modeles" 'id="grc-hardware-eol-config"' non \
      "$(body "$P/config.php?tab=assets")"
    connectors=$(body "$P/config.php?tab=connectors")
    contains "configuration, onglet Connecteurs : endoflife.date garde ses reglages" 'name="update_endoflife_config"' oui "$connectors"
    for button in refresh_endoflife add_endoflife_mapping delete_endoflife_mapping; do
      contains "configuration, onglet Connecteurs : plus de $button" "name=\"$button\"" non "$connectors"
    done
    contains "front/softwarewatch.php : fin de support endoflife.date" 'id="grc-endoflife-links"' oui "$(body "$P/softwarewatch.php")"
    contains "front/parkvulnerability.php : envoi du rapport" 'name="send_vulnerability_digest"' oui "$(body "$P/parkvulnerability.php")"
    # Envoi depuis l'onglet du modele : enregistre (date effacee, rien ne change), jamais sans jeton.
    expect "front/hardwaremodeleol.php : saisie" 302 \
      "$(code -d "update_hardware_eol=1&hardware_eol%5BComputerModel%3A$(id hw_model_A)%5D=&_glpi_csrf_token=$(csrf)" "$P/hardwaremodeleol.php")"
    expect "front/hardwaremodeleol.php : saisie sans jeton CSRF" 403 \
      "$(code -d "update_hardware_eol=1&hardware_eol%5BComputerModel%3A$(id hw_model_A)%5D=" "$P/hardwaremodeleol.php")"
    # Anciens envois de la configuration : acceptes pendant une version, menes au nouvel ecran.
    expect "front/config.php : ancienne saisie des modeles menee a la saisie groupee" hardwaremodeleol.php \
      "$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" -H "Sec-Fetch-Site: same-origin" \
        -d "update_hardware_eol=1&hardware_eol%5BComputerModel%3A$(id hw_model_A)%5D=&_glpi_csrf_token=$(csrf)" "$P/config.php" \
        | grep -o 'hardwaremodeleol.php')"
    expect "front/config.php : ancienne suppression d'une correspondance menee aux logiciels a surveiller" softwarewatch.php \
      "$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" -H "Sec-Fetch-Site: same-origin" \
        -d "delete_endoflife_mapping=0&_glpi_csrf_token=$(csrf)" "$P/config.php" | grep -o 'softwarewatch.php')" ;;
esac
