# shellcheck shell=bash
# Issue #1047 : creation et import des referentiels personnalises dans la configuration du plugin
# (onglet frameworks, bloc grc-custom-framework-import). L'ancienne adresse front/customframework.php
# mene au bloc ; l'auto-evaluation des exigences reste rangee avec l'evaluation par referentiel.
# Fil d'Ariane de la configuration sans « Configuration > Plugins » en double.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
cf_location() { # adresse : destination de la redirection (vide sans redirection)
  curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" -H "Sec-Fetch-Site: same-origin" "$@"
}
case $ROLE in
  none)
    expect "front/customframework.php : sans droit" 403 "$(code "$P/customframework.php")"
    expect "front/config.php : analyse d'un referentiel personnalise sans droit" 403 \
      "$(code -d "analyse_custom_framework=1&name=securite&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : confirmation d'un import sans droit" 403 \
      "$(code -d "confirm_custom_framework=1&import_token=x&_glpi_csrf_token=$(csrf)" "$P/config.php")" ;;
  reader)
    contains "front/customframework.php : redirige vers le bloc de la configuration" \
      "config.php?tab=frameworks#grc-custom-framework-import" oui "$(cf_location "$P/customframework.php")"
    page=$(body "$P/config.php?tab=frameworks")
    contains "configuration, onglet frameworks : referentiels personnalises (lecture)" \
      'id="grc-custom-framework-import"' oui "$page"
    contains "configuration, onglet frameworks : pas d'import en lecture" 'name="analyse_custom_framework"' non "$page"
    expect "front/config.php : analyse d'un referentiel personnalise en lecture" 403 \
      "$(code -d "analyse_custom_framework=1&name=securite&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : confirmation d'un import en lecture" 403 \
      "$(code -d "confirm_custom_framework=1&import_token=x&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    contains "front/frameworkassessment.php : pas de lien de creation en lecture" \
      'id="grc-custom-framework-import"' non "$(body "$P/frameworkassessment.php")" ;;
  admin)
    contains "front/customframework.php : redirige vers le bloc de la configuration" \
      "config.php?tab=frameworks#grc-custom-framework-import" oui "$(cf_location "$P/customframework.php")"
    contains "front/customframework.php?target= : redirige vers la nouvelle version dans la configuration" \
      "cf_target=$(id custom_framework)#grc-custom-framework-import" oui \
      "$(cf_location "$P/customframework.php?target=$(id custom_framework)")"
    page=$(body "$P/config.php?tab=frameworks")
    contains "configuration, onglet frameworks : import d'un referentiel personnalise" \
      'name="analyse_custom_framework"' oui "$page"
    contains "configuration, onglet frameworks : modele de fichier" 'front/customframework.php?template=1' oui "$page"
    contains "configuration, onglet frameworks : nouvelle version" \
      "cf_target=$(id custom_framework)#grc-custom-framework-import" oui "$page"
    contains "configuration, onglet frameworks : nouvelle version d'un referentiel" 'name="target" value="'"$(id custom_framework)"'"' oui \
      "$(body "$P/config.php?tab=frameworks&cf_target=$(id custom_framework)")"
    # Analyse d'un fichier depuis la configuration : rien n'est enregistre, l'apercu attend la
    # confirmation dans le bloc ; puis annulation.
    cf_csv=$(mktemp)
    printf 'code;title;annex_a\nSEC-01;Exigence securite;A.5.1\n' > "$cf_csv"
    contains "front/config.php : analyse d'un fichier, retour au bloc" "#grc-custom-framework-import" oui \
      "$(cf_location -F analyse_custom_framework=1 -F name=securite -F "csv_file=@$cf_csv;filename=ref.csv" \
        -F "_glpi_csrf_token=$(csrf)" "$P/config.php")"
    rm -f "$cf_csv"
    page=$(body "$P/config.php?tab=frameworks")
    contains "configuration, onglet frameworks : apercu de l'analyse" 'name="confirm_custom_framework"' oui "$page"
    expect "front/config.php : annulation de l'analyse" 302 \
      "$(code -d "cancel_custom_framework=1&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    contains "configuration, onglet frameworks : analyse annulee" 'name="confirm_custom_framework"' non \
      "$(body "$P/config.php?tab=frameworks")"
    # Ancien envoi de front/customframework.php : meme traitement, puis le bloc de la configuration.
    contains "front/customframework.php : ancienne confirmation renvoyee sur la configuration" \
      "config.php?tab=frameworks#grc-custom-framework-import" oui \
      "$(cf_location -d "confirm_import=1&import_token=x&_glpi_csrf_token=$(csrf)" "$P/customframework.php")"
    # Reglementations : evaluation par referentiel, lien vers la configuration ; plus de page de
    # creation dans le menu. Auto-evaluation des exigences rangee avec l'evaluation.
    contains "front/frameworkassessment.php : lien vers la creation dans la configuration" \
      'config.php?tab=frameworks#grc-custom-framework-import' oui "$(body "$P/frameworkassessment.php")"
    contains "Reglementations, accueil : plus de page des referentiels personnalises" 'front/customframework.php"' non \
      "$(body "$P/section.php?section=regulation")"
    form=$(curl -s -c "$JAR" -b "$JAR" "$P/customframework.form.php?id=$(id custom_framework)")
    crumbs=$(tr '\n\r' '  ' <<< "$form" | grep -o '<ol class="breadcrumb[^"]*">.*' | sed 's#</ol>.*##')
    contains "front/customframework.form.php : fil d'Ariane de l'evaluation par referentiel" \
      'front/frameworkassessment.php"' oui "$crumbs"
    # Fil d'Ariane de la configuration : « Configuration > Plugins » une seule fois (en-tete de GLPI).
    conf=$(tr '\n\r' '  ' <<< "$(body "$P/config.php?tab=general")")
    grc_crumb=$(grep -o '<ol class="breadcrumb mb-2" id="grc-config-breadcrumb">.*' <<< "$conf" | sed 's#</ol>.*##')
    contains "configuration : fil d'Ariane du plugin present" 'GRC Manager' oui "$grc_crumb"
    contains "configuration : fil d'Ariane sans Plugins en double" 'front/plugin.php' non "$grc_crumb" ;;
esac
